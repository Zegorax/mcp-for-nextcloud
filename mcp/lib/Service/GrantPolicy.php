<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service;

use InvalidArgumentException;
use OCP\Config\IUserConfig;
use OCP\IConfig;
use OCA\Mcp\OAuth\OAuthStore;

/**
 * Persisted MCP access policy: global switch, admin eligibility, per-user personal connection and grants for
 * (module, operation). Eligibility and each grant are decided in this order: an individual deny, an individual
 * allow, the user's groups (a deny in any of them wins over an allow), then the default. An individual value is
 * stored per user ('1' allow, '0' deny, absent = inherit); group rules live in GroupGrants. Every read goes back
 * to the stored values, so a change, including a group membership change, applies to the next request. Grants
 * never widen Nextcloud ACLs.
 */
class GrantPolicy {
    /** Operations an admin can grant per module; Files deliberately has no delete. */
    public const CATALOG = [
        // 'restore' rolls a file back to a stored version and 'create' adds a folder, a copy or a new file;
        // like every operation but read, both start denied. 'share' shares an own file with a person or a
        // group and 'link' creates a public link; they are separate so an admin can allow one without the
        // other, and removing a share asks for the operation of its type. Listing shares stays a 'read'.
        'files' => ['read', 'edit', 'create', 'move', 'restore', 'share', 'link'],
        'notes' => ['read', 'create', 'edit', 'move', 'delete'],
        'deck' => ['read', 'create', 'edit', 'move', 'delete'],
        'contacts' => ['read', 'create', 'edit', 'delete'],
        'tasks' => ['read', 'create', 'edit', 'delete'],
        'calendar' => ['read', 'create', 'edit', 'move', 'delete', 'transfer'],
        // 'create' is what talk_create_group asks for: opening a conversation invites people, so it is denied by
        // default like every other operation that is not a read.
        // Account search; read only, so the model can find who to invite or message.
        'people' => ['read'],
        'talk' => ['read', 'reply', 'attach', 'quote', 'create'],
        // Reading the server log; read only, and the one read that starts denied (see DENIED_READS).
        'logs' => ['read'],
    ];

    /**
     * Modules whose read starts denied like a write. The server log shows the file names, URLs and accounts of every
     * user, so nobody may read it by omission: an administrator turns it on user by user.
     */
    public const DENIED_READS = ['logs'];

    /** App id under which every value of this policy is stored. */
    public const APP = 'mcp';
    /** App config key of the global service switch; never core's reserved 'enabled'. */
    public const SERVICE_KEY = 'service_enabled';
    /** Where a decision came from, see explain(). */
    public const SOURCE_OVERRIDE = 'override';
    public const SOURCE_GROUP = 'group';
    public const SOURCE_DEFAULT = 'default';
    /** User config key of the admin eligibility. */
    public const ELIGIBLE_KEY = 'eligible';
    /** User config key of the personal connection. */
    public const CONNECTED_KEY = 'connected';
    /**
     * App config keys core stable33 owns for every app (AppManager, OC_App, AppConfig lexicon bypass)
     * plus legacy ones; this app must never read or write them.
     */
    public const RESERVED_APP_KEYS = ['enabled', 'installed_version', 'types', 'levels', 'ocsid'];

    /**
     * @param IConfig $config Nextcloud configuration, where every value of the policy lives
     * @param OAuthStore $oauth OAuth tokens, revoked when a switch closes
     * @param IUserConfig|null $userConfig the user config service of Nextcloud 32 and later; Nextcloud 31 has no such
     *     interface, so its container falls back to the null default and the policy searches through IConfig
     * @param GroupGrants|null $groups group rules; without them only individual values and defaults apply
     */
    public function __construct(
        private IConfig $config,
        private OAuthStore $oauth,
        private ?IUserConfig $userConfig = null,
        private ?GroupGrants $groups = null,
    ) {}

    /** @return bool whether the administrator enabled the MCP service (off by default) */
    public function globalEnabled(): bool {
        return $this->config->getAppValue(self::APP, self::SERVICE_KEY, '0') === '1';
    }

    /** @param bool $enabled new state of the global service switch */
    public function setGlobalEnabled(bool $enabled): void {
        $this->config->setAppValue(self::APP, self::SERVICE_KEY, $enabled ? '1' : '0');
        if (!$enabled) {
            $this->oauth->deleteAll();
        }
    }

    /**
     * @param string $uid Nextcloud user id
     * @return bool whether an administrator allowed this user to connect, individually or through a group (off by
     *     default, admins included)
     */
    public function eligible(string $uid): bool {
        return $this->explain($uid, GroupGrants::ELIGIBLE)['result'];
    }

    /**
     * @param string $uid Nextcloud user id
     * @param bool $enabled new eligibility, stored as an individual allow or deny
     */
    public function setEligible(string $uid, bool $enabled): void {
        $this->setEligibleOverride($uid, $enabled);
    }

    /**
     * Sets or clears the individual eligibility; a user who can no longer connect is signed out of every client.
     *
     * @param string $uid Nextcloud user id
     * @param bool|null $allowed true allow, false deny, null inherit from the groups
     */
    public function setEligibleOverride(string $uid, ?bool $allowed): void {
        $this->writeOverride($uid, self::ELIGIBLE_KEY, $allowed);
        if (!$this->eligible($uid)) {
            $this->oauth->deleteForUser($uid);
        }
    }

    /**
     * How a permission is decided for one user.
     *
     * @param string $uid Nextcloud user id
     * @param string $permission GroupGrants::ELIGIBLE or "<module>.<operation>"
     * @param array<string, array<string, bool>>|null $rules group rules to judge with instead of the stored ones
     * @return array{result: bool, source: string, override: bool|null, allow: list<string>, deny: list<string>}
     *     the decision, where it came from (SOURCE_*), the individual value and the user's groups that allow or deny
     * @throws InvalidArgumentException for an unknown permission
     */
    public function explain(string $uid, string $permission, ?array $rules = null): array {
        [$key] = self::keyAndDefault($permission);
        return $this->decide($uid, $permission, self::overrideValue($this->config->getUserValue($uid, self::APP, $key, '')), $rules);
    }

    /**
     * Users who may connect: individual allows plus members of groups that allow it, minus everyone a deny or a
     * missing allow leaves out. Only those candidates are read, never the whole user base.
     *
     * @return list<string> uids, sorted
     */
    public function eligibleUsers(): array {
        $candidates = $this->flaggedUsers(self::ELIGIBLE_KEY);
        foreach ($this->groups?->groupsAllowing(GroupGrants::ELIGIBLE) ?? [] as $gid) {
            array_push($candidates, ...$this->groups->membersOf($gid));
        }
        $uids = array_values(array_filter(array_unique($candidates), fn (string $uid): bool => $this->eligible($uid)));
        sort($uids);
        return $uids;
    }

    /**
     * @param string $uid Nextcloud user id
     * @return bool whether the user activated their own connection (off by default)
     */
    public function connected(string $uid): bool {
        return $this->config->getUserValue($uid, self::APP, self::CONNECTED_KEY, '0') === '1';
    }

    /**
     * @param string $uid Nextcloud user id
     * @param bool $enabled new personal connection state
     */
    public function setConnected(string $uid, bool $enabled): void {
        $this->config->setUserValue($uid, self::APP, self::CONNECTED_KEY, $enabled ? '1' : '0');
    }

    /**
     * Users whose own stored eligibility or connection flag is on ('1'). For who may connect, groups included, use
     * eligibleUsers().
     *
     * @param string $key ELIGIBLE_KEY or CONNECTED_KEY
     * @return list<string> uids with that flag set to '1', sorted
     * @throws InvalidArgumentException for any other key
     */
    public function flaggedUsers(string $key): array {
        if (!in_array($key, [self::ELIGIBLE_KEY, self::CONNECTED_KEY], true)) {
            throw new InvalidArgumentException('Invalid request');
        }
        // IConfig::getUsersForUserValue is the only search Nextcloud 31 has; 33 deprecates it in favour of IUserConfig.
        $found = $this->userConfig === null
            ? $this->config->getUsersForUserValue(self::APP, $key, '1')
            : iterator_to_array($this->userConfig->searchUsersByValueString(self::APP, $key, '1'), false);
        $uids = array_map('strval', $found);
        sort($uids);
        return array_values(array_unique($uids));
    }

    /**
     * Users an administrator allowed and who activated their connection: the one meaning of "connected" in the
     * status summary and in the matrix filter.
     *
     * @return list<string> uids with both flags on, sorted
     */
    public function connectedUsers(): array {
        return array_values(array_intersect($this->eligibleUsers(), $this->flaggedUsers(self::CONNECTED_KEY)));
    }

    /**
     * @param string $uid Nextcloud user id
     * @return bool true only when the service is on, the user is eligible and connected
     */
    public function canConnect(string $uid): bool {
        return $this->globalEnabled() && $this->eligible($uid) && $this->connected($uid);
    }

    /**
     * @param string $uid Nextcloud user id
     * @param string $module key of CATALOG
     * @param string $operation operation listed for the module in CATALOG
     * @return bool the individual value, else the groups' decision, else {@see self::defaultGranted()}
     * @throws InvalidArgumentException for a module or operation outside CATALOG
     */
    public function granted(string $uid, string $module, string $operation): bool {
        return $this->explain($uid, GroupGrants::permission($module, $operation))['result'];
    }

    /**
     * What a user who was never given nor denied the grant gets: read is allowed, except in DENIED_READS, and every
     * other operation is denied.
     *
     * @param string $module key of CATALOG
     * @param string $operation operation of the module
     * @return bool the default grant
     */
    public static function defaultGranted(string $module, string $operation): bool {
        return $operation === 'read' && !in_array($module, self::DENIED_READS, true);
    }

    /**
     * @param string $uid Nextcloud user id
     * @param string $module key of CATALOG
     * @param string $operation operation listed for the module in CATALOG
     * @param bool $enabled new grant, stored as an individual allow or deny
     * @throws InvalidArgumentException for a module or operation outside CATALOG
     */
    public function setGrant(string $uid, string $module, string $operation, bool $enabled): void {
        $this->setGrantOverride($uid, $module, $operation, $enabled);
    }

    /**
     * @param string $uid Nextcloud user id
     * @param string $module key of CATALOG
     * @param string $operation operation listed for the module in CATALOG
     * @param bool|null $allowed true allow, false deny, null inherit from the groups
     * @throws InvalidArgumentException for a module or operation outside CATALOG
     */
    public function setGrantOverride(string $uid, string $module, string $operation, ?bool $allowed): void {
        $this->writeOverride($uid, self::grantKey($module, $operation), $allowed);
    }

    /**
     * Reads eligibility, connection and every grant of many users with one query per key
     * (IConfig::getUserValueForUsers, backed by IUserConfig::getValuesByUsers in stable33), so the cost does not grow
     * with the number of users; group rules are read once and each user's groups once.
     *
     * @param list<string> $uids Nextcloud user ids
     * @return array<string, array{eligible:bool, connected:bool, grants:array<string, array<string, bool>>,
     *     overrides:array{eligible:bool|null, grants:array<string, array<string, bool|null>>},
     *     sources:array{eligible:string, grants:array<string, array<string, string>>},
     *     groupRules:array<string, array{allow:list<string>, deny:list<string>}>}> state by uid: the decisions, the
     *     individual values (null = inherit), where each decision came from, and the groups that decided it
     */
    public function forUsers(array $uids): array {
        $uids = array_values(array_unique($uids));
        if ($uids === []) {
            return [];
        }
        $load = fn (string $key): array => $this->config->getUserValueForUsers(self::APP, $key, $uids);
        $stored = [GroupGrants::ELIGIBLE => $load(self::ELIGIBLE_KEY)];
        $connected = $load(self::CONNECTED_KEY);
        foreach (self::CATALOG as $module => $operations) {
            foreach ($operations as $operation) {
                $stored[$module . '.' . $operation] = $load(self::grantKey($module, $operation));
            }
        }
        $out = [];
        foreach ($uids as $uid) {
            $state = ['eligible' => false, 'connected' => ($connected[$uid] ?? '0') === '1', 'grants' => [],
                'overrides' => ['eligible' => null, 'grants' => []], 'sources' => ['eligible' => self::SOURCE_DEFAULT, 'grants' => []],
                'groupRules' => []];
            foreach ($stored as $permission => $values) {
                $decision = $this->decide($uid, $permission, self::overrideValue((string)($values[$uid] ?? '')));
                if ($decision['allow'] !== [] || $decision['deny'] !== []) {
                    $state['groupRules'][$permission] = ['allow' => $decision['allow'], 'deny' => $decision['deny']];
                }
                if ($permission === GroupGrants::ELIGIBLE) {
                    $state['eligible'] = $decision['result'];
                    $state['overrides']['eligible'] = $decision['override'];
                    $state['sources']['eligible'] = $decision['source'];
                    continue;
                }
                [$module, $operation] = explode('.', $permission);
                $state['grants'][$module][$operation] = $decision['result'];
                $state['overrides']['grants'][$module][$operation] = $decision['override'];
                $state['sources']['grants'][$module][$operation] = $decision['source'];
            }
            $out[$uid] = $state;
        }
        return $out;
    }

    /**
     * @param string $module candidate module
     * @param string $operation candidate operation
     * @return bool whether the pair exists in CATALOG
     */
    public static function inCatalog(string $module, string $operation): bool {
        return isset(self::CATALOG[$module]) && in_array($operation, self::CATALOG[$module], true);
    }

    /**
     * The one place the order is applied: individual value, then groups (deny wins), then the default. Takes the
     * individual value already read, so a batch does not read it again.
     *
     * @param array<string, array<string, bool>>|null $rules group rules to judge with instead of the stored ones
     * @return array{result: bool, source: string, override: bool|null, allow: list<string>, deny: list<string>}
     */
    private function decide(string $uid, string $permission, ?bool $override, ?array $rules = null): array {
        [, $default] = self::keyAndDefault($permission);
        $sources = $this->groups?->sources($uid, $permission, $rules) ?? ['allow' => [], 'deny' => []];
        if ($override !== null) {
            return ['result' => $override, 'source' => self::SOURCE_OVERRIDE, 'override' => $override] + $sources;
        }
        if ($sources['deny'] !== [] || $sources['allow'] !== []) {
            return ['result' => $sources['deny'] === [], 'source' => self::SOURCE_GROUP, 'override' => null] + $sources;
        }
        return ['result' => $default, 'source' => self::SOURCE_DEFAULT, 'override' => null] + $sources;
    }

    /**
     * @return array{0: string, 1: bool} the user config key and the default of a permission
     * @throws InvalidArgumentException for an unknown permission
     */
    private static function keyAndDefault(string $permission): array {
        if ($permission === GroupGrants::ELIGIBLE) {
            return [self::ELIGIBLE_KEY, false];
        }
        $parts = explode('.', $permission);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException('Unknown module or operation');
        }
        return [self::grantKey($parts[0], $parts[1]), self::defaultGranted($parts[0], $parts[1])];
    }

    /** @return bool|null the individual value: '1' allow, '0' deny, anything else inherit */
    private static function overrideValue(string $stored): ?bool {
        return match ($stored) {
            '1' => true,
            '0' => false,
            default => null,
        };
    }

    /** Stores '1' or '0', or removes the value so the user inherits. */
    private function writeOverride(string $uid, string $key, ?bool $allowed): void {
        if ($allowed === null) {
            $this->config->deleteUserValue($uid, self::APP, $key);
        } else {
            $this->config->setUserValue($uid, self::APP, $key, $allowed ? '1' : '0');
        }
    }

    /** @throws InvalidArgumentException for a module or operation outside CATALOG */
    private static function grantKey(string $module, string $operation): string {
        if (!self::inCatalog($module, $operation)) {
            throw new InvalidArgumentException('Unknown module or operation');
        }
        return 'grant_' . $module . '_' . $operation;
    }
}
