<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use InvalidArgumentException;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\Service\EffectiveAccess;
use OCA\Mcp\Service\GrantMatrix;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\GroupGrantImpact;
use OCA\Mcp\Service\GroupGrants;
use OCA\Mcp\Service\GroupGrantsConflict;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Group permissions and the effective-access view. Every endpoint is admin-only and CSRF-protected by Nextcloud's
 * defaults, except myAccess, which shows a signed-in user their own access. Invalid input answers 400 without
 * saying which part was wrong; a save made on stale data answers 409 with the current state.
 */
class GroupGrantsController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private GroupGrants $groups,
        private GroupGrantImpact $impact,
        private EffectiveAccess $effective,
        private IGroupManager $groupManager,
        private IUserSession $userSession,
        private OAuthStore $oauth,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @param string $search part of a group id or name
     * @return JSONResponse {groups, configured, version, permissions}: groups matching the search (at most
     *     GrantMatrix::MAX_GROUPS) and every group that has rules, each with its member count and rules
     */
    public function index(string $search = ''): JSONResponse {
        if (mb_strlen($search) > GrantMatrix::MAX_SEARCH) {
            return self::invalid();
        }
        return new JSONResponse($this->state($search));
    }

    /**
     * Body {group: string, rules: {permission: "allow" | "deny"}}; a permission left out is inherited.
     *
     * @return JSONResponse what saving would change (see GroupGrantImpact::preview), or 400
     */
    public function preview(): JSONResponse {
        try {
            [$gid, $rules] = $this->body();
            $preview = $this->impact->preview($gid, $rules);
            unset($preview['losingEligibility']);
            return new JSONResponse($preview);
        } catch (InvalidArgumentException) {
            return self::invalid();
        }
    }

    /**
     * Body {group, rules, version}. Members who can no longer connect are signed out of every MCP client.
     *
     * @return JSONResponse the same shape as index(), 400 for an invalid body, or 409 {error, state} when another
     *     save came first (nothing is written)
     */
    public function update(): JSONResponse {
        try {
            [$gid, $rules] = $this->body();
            $version = $this->request->getParam('version');
            if (!is_int($version) || $version < 0) {
                throw new InvalidArgumentException('Invalid request');
            }
            $losing = $this->impact->preview($gid, $rules)['losingEligibility'];
            $this->groups->save($gid, $rules, $version);
        } catch (InvalidArgumentException) {
            return self::invalid();
        } catch (GroupGrantsConflict) {
            return new JSONResponse(['error' => 'Conflict', 'state' => $this->state('')], Http::STATUS_CONFLICT);
        }
        foreach ($losing as $uid) {
            $this->oauth->deleteForUser($uid);
        }
        return new JSONResponse($this->state(''));
    }

    /**
     * @param string $uid Nextcloud user id
     * @return JSONResponse the user's effective access (see EffectiveAccess::forUser), or 400 for an unknown user
     */
    public function effective(string $uid): JSONResponse {
        try {
            return new JSONResponse($this->effective->forUser($uid));
        } catch (InvalidArgumentException) {
            return self::invalid();
        }
    }

    /** @return JSONResponse the signed-in user's own effective access */
    #[NoAdminRequired]
    public function myAccess(): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return self::invalid();
        }
        return new JSONResponse($this->effective->forUser($user->getUID()));
    }

    /**
     * @return array{0: string, 1: array<string, bool>} the group id and its rules; a group that no longer exists is
     *     accepted only to clear the rules it left behind (a backend that removed it without an event)
     * @throws InvalidArgumentException for an unknown group or a malformed rule
     */
    private function body(): array {
        $gid = $this->request->getParam('group');
        $raw = $this->request->getParam('rules');
        if (!is_string($gid) || $gid === '' || !is_array($raw)) {
            throw new InvalidArgumentException('Invalid request');
        }
        $rules = [];
        foreach ($raw as $permission => $value) {
            if (!is_string($permission) || !GroupGrants::valid($permission) || !in_array($value, ['allow', 'deny'], true)) {
                throw new InvalidArgumentException('Invalid request');
            }
            $rules[$permission] = $value === 'allow';
        }
        if ($this->groupManager->get($gid) === null && ($rules !== [] || !isset($this->groups->state()['rules'][$gid]))) {
            throw new InvalidArgumentException('Invalid request');
        }
        return [$gid, $rules];
    }

    /** @return array{groups: list<array<string, mixed>>, configured: list<array<string, mixed>>, version: int, permissions: list<string>, catalog: array<string, list<string>>} */
    private function state(string $search): array {
        $this->groups->forget();
        $state = $this->groups->state();
        $describe = function (string $gid, ?string $name) use ($state): array {
            $group = $this->groupManager->get($gid);
            $count = $group?->count();
            return [
                'id' => $gid,
                'displayName' => $name ?? $group?->getDisplayName() ?? $gid,
                'members' => is_int($count) ? $count : null,
                'missing' => $group === null,
                'rules' => array_map(static fn (bool $allowed): string => $allowed ? 'allow' : 'deny', $state['rules'][$gid] ?? []),
            ];
        };
        $groups = array_map(fn ($group): array => $describe($group->getGID(), $group->getDisplayName()),
            array_values($this->groupManager->search($search, GrantMatrix::MAX_GROUPS)));
        $configured = array_map(fn ($gid): array => $describe((string)$gid, null), array_keys($state['rules']));
        return [
            'groups' => $groups,
            'configured' => $configured,
            'version' => $state['version'],
            'permissions' => GroupGrants::permissions(),
            'catalog' => GrantPolicy::CATALOG,
        ];
    }

    private static function invalid(): JSONResponse {
        return new JSONResponse(['error' => 'Invalid request'], Http::STATUS_BAD_REQUEST);
    }
}
