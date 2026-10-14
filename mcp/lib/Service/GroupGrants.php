<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service;

use InvalidArgumentException;
use OCA\Mcp\Db\GroupGrantsMapper;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * What a user's groups say about a permission. A permission is "eligible" (may connect) or "<module>.<operation>"
 * of GrantPolicy::CATALOG; a group allows it, denies it, or says nothing. Between groups a deny wins over an allow.
 * The rules are read once per request and the user's groups once per user, so a membership change or a saved rule
 * applies to the next request.
 */
class GroupGrants {
    public const ELIGIBLE = 'eligible';
    /** Members read for one preview or eligibility listing; beyond it the answer says it is partial. */
    public const MAX_MEMBERS = 10000;

    /** @var array{rules: array<string, array<string, bool>>, version: int}|null */
    private ?array $state = null;
    /** @var array<string, list<string>> group ids by uid, for this request */
    private array $groupIds = [];

    public function __construct(
        private GroupGrantsMapper $mapper,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
    ) {}

    /**
     * @param string $module key of GrantPolicy::CATALOG
     * @param string $operation operation of the module
     * @return string the permission name used in group rules
     * @throws InvalidArgumentException outside the catalog
     */
    public static function permission(string $module, string $operation): string {
        if (!GrantPolicy::inCatalog($module, $operation)) {
            throw new InvalidArgumentException('Unknown module or operation');
        }
        return $module . '.' . $operation;
    }

    /** @return bool whether $permission is "eligible" or a catalog "<module>.<operation>" */
    public static function valid(string $permission): bool {
        if ($permission === self::ELIGIBLE) {
            return true;
        }
        $parts = explode('.', $permission);
        return count($parts) === 2 && GrantPolicy::inCatalog($parts[0], $parts[1]);
    }

    /** @return list<string> every permission a group rule may name, eligibility first */
    public static function permissions(): array {
        $all = [self::ELIGIBLE];
        foreach (GrantPolicy::CATALOG as $module => $operations) {
            foreach ($operations as $operation) {
                $all[] = $module . '.' . $operation;
            }
        }
        return $all;
    }

    /** @return array{rules: array<string, array<string, bool>>, version: int} the stored rules, read once per request */
    public function state(): array {
        return $this->state ??= $this->mapper->state();
    }

    /** Drops what this request read, so the next call sees the database again (after a save). */
    public function forget(): void {
        $this->state = null;
        $this->groupIds = [];
    }

    /** @return list<string> the user's group ids, empty for an unknown user */
    public function groupIdsOf(string $uid): array {
        if (!array_key_exists($uid, $this->groupIds)) {
            $user = $this->userManager->get($uid);
            $this->groupIds[$uid] = $user === null ? [] : array_values(array_map('strval', $this->groupManager->getUserGroupIds($user)));
        }
        return $this->groupIds[$uid];
    }

    /**
     * @param string $uid Nextcloud user id
     * @param string $permission "eligible" or "<module>.<operation>"
     * @param array<string, array<string, bool>>|null $rules rules to judge with instead of the stored ones (preview)
     * @return array{allow: list<string>, deny: list<string>} the user's groups that allow and that deny it
     */
    public function sources(string $uid, string $permission, ?array $rules = null): array {
        $rules ??= $this->state()['rules'];
        $out = ['allow' => [], 'deny' => []];
        foreach ($this->groupIdsOf($uid) as $gid) {
            if (isset($rules[$gid][$permission])) {
                $out[$rules[$gid][$permission] ? 'allow' : 'deny'][] = $gid;
            }
        }
        return $out;
    }

    /**
     * @param string $uid Nextcloud user id
     * @param string $permission "eligible" or "<module>.<operation>"
     * @param array<string, array<string, bool>>|null $rules rules to judge with instead of the stored ones (preview)
     * @return bool|null false when one of the user's groups denies, true when one allows, null when none says
     */
    public function decide(string $uid, string $permission, ?array $rules = null): ?bool {
        $sources = $this->sources($uid, $permission, $rules);
        if ($sources['deny'] !== []) {
            return false;
        }
        return $sources['allow'] !== [] ? true : null;
    }

    /**
     * @return list<string> uids of the group's members, at most MAX_MEMBERS (an unknown group has none)
     */
    public function membersOf(string $gid): array {
        $group = $this->groupManager->get($gid);
        if ($group === null) {
            return [];
        }
        return array_values(array_map(static fn ($user): string => $user->getUID(), $group->searchUsers('', self::MAX_MEMBERS)));
    }

    /** @return list<string> groups whose rule allows connecting */
    public function groupsAllowing(string $permission): array {
        return array_values(array_map('strval', array_keys(array_filter($this->state()['rules'],
            static fn (array $permissions): bool => ($permissions[$permission] ?? null) === true))));
    }

    /**
     * Replaces one group's rules when the stored version is still $version.
     *
     * @param string $gid group id
     * @param array<string, bool> $rules the group's complete rules; a permission left out is inherited
     * @param int $version version the caller read
     * @throws GroupGrantsConflict when another save came first
     * @throws InvalidArgumentException for a permission outside the catalog
     */
    public function save(string $gid, array $rules, int $version): void {
        foreach ($rules as $permission => $allowed) {
            if (!is_string($permission) || !self::valid($permission) || !is_bool($allowed)) {
                throw new InvalidArgumentException('Invalid request');
            }
        }
        $all = $this->mapper->state()['rules'];
        $all[$gid] = $rules;
        $this->mapper->compareAndSet($all, $version);
        $this->forget();
    }

    /** Removes a deleted group's rules, retrying when another save interleaves. */
    public function removeGroup(string $gid): void {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $state = $this->mapper->state();
            if (!isset($state['rules'][$gid])) {
                break;
            }
            unset($state['rules'][$gid]);
            try {
                $this->mapper->compareAndSet($state['rules'], $state['version']);
                break;
            } catch (GroupGrantsConflict) {
                // Read again and retry.
            }
        }
        $this->forget();
    }
}
