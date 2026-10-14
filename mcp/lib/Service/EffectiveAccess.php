<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service;

use InvalidArgumentException;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Explains one user's MCP access: their groups, and for eligibility and every permission what the groups say, the
 * individual value, the result and where it came from, plus whatever blocks the user regardless (service off,
 * account disabled, not allowed to connect, connection not turned on, app unavailable). It only reads.
 */
class EffectiveAccess {
    public function __construct(
        private GrantMatrix $matrix,
        private GrantPolicy $policy,
        private GroupGrants $groups,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
    ) {}

    /**
     * @param string $uid Nextcloud user id
     * @return array{uid:string, displayName:string, enabled:bool, serviceEnabled:bool, connected:bool, canConnect:bool,
     *     groups:list<array{id:string, displayName:string}>, blockers:list<string>,
     *     eligible:array{result:bool, source:string, override:bool|null, allow:list<string>, deny:list<string>},
     *     permissions:list<array{module:string, operation:string, result:bool, effective:bool, available:bool,
     *     source:string, override:bool|null, allow:list<string>, deny:list<string>}>}
     * @throws InvalidArgumentException for an unknown user
     */
    public function forUser(string $uid): array {
        $user = $uid === '' ? null : $this->userManager->get($uid);
        if ($user === null) {
            throw new InvalidArgumentException('Invalid request');
        }
        $row = $this->matrix->rows([$user])[0];
        $service = $this->policy->globalEnabled();
        $eligible = $this->policy->explain($uid, GroupGrants::ELIGIBLE);
        $canConnect = $service && $user->isEnabled() && $eligible['result'] && $row['connected'];
        $blockers = array_keys(array_filter([
            'service_off' => !$service,
            'account_disabled' => !$user->isEnabled(),
            'not_eligible' => !$eligible['result'],
            'not_connected' => !$row['connected'],
        ]));
        $permissions = [];
        foreach (GrantPolicy::CATALOG as $module => $operations) {
            $available = (bool)($row['appsEnabled'][$module] ?? false);
            foreach ($operations as $operation) {
                $decision = $this->policy->explain($uid, GroupGrants::permission($module, $operation));
                $permissions[] = ['module' => $module, 'operation' => $operation, 'result' => $decision['result'],
                    'effective' => $canConnect && $available && $decision['result'], 'available' => $available,
                    'source' => $decision['source'], 'override' => $decision['override'],
                    'allow' => $decision['allow'], 'deny' => $decision['deny']];
            }
        }
        $groups = array_map(fn (string $gid): array => ['id' => $gid, 'displayName' => $this->groupManager->getDisplayName($gid) ?? $gid],
            $this->groups->groupIdsOf($uid));
        usort($groups, static fn (array $a, array $b): int => strcasecmp($a['displayName'], $b['displayName']));
        return [
            'uid' => $uid,
            'displayName' => $user->getDisplayName(),
            'enabled' => $user->isEnabled(),
            'serviceEnabled' => $service,
            'connected' => $row['connected'],
            'canConnect' => $canConnect,
            'groups' => $groups,
            'blockers' => $blockers,
            'eligible' => $eligible,
            'permissions' => $permissions,
        ];
    }
}
