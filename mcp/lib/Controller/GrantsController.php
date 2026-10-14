<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use InvalidArgumentException;
use OCA\Mcp\OAuth\ClientMetadataFetcher;
use OCA\Mcp\OAuth\ClientResolver;
use OCA\Mcp\OAuth\NativeClient;
use OCA\Mcp\OAuth\OAuthStore;
use OCA\Mcp\Service\GrantMatrix;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\LogsAccess;
use OCA\Mcp\Service\LogsGroupsConflict;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;

/**
 * JSON API of the admin permission matrix. Every endpoint is admin-only and CSRF-protected by
 * Nextcloud's defaults (no NoAdminRequired/NoCSRFRequired); the page sends the requesttoken header.
 * Invalid input answers 400 {"error":"Invalid request"} without saying which part was wrong.
 */
class GrantsController extends Controller {
    /** Pseudo-operation of the bulk endpoint that toggles eligibility instead of a grant. */
    public const ELIGIBLE = 'eligible';
    /** Most connections counted for one user in the detail panel; the panel says "100 or more" beyond it. */
    public const MAX_LISTED_CONNECTIONS = 100;
    public function __construct(
        string $appName,
        IRequest $request,
        private GrantMatrix $matrix,
        private GrantPolicy $policy,
        private IUserManager $userManager,
        private IConfig $config,
        private OAuthStore $oauth,
        private LogsAccess $logs,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @param string $search term matched against uid, display name and e-mail
     * @param string $group group id, '' for every user
     * @param int $page 1-based page
     * @param string $filter '' for every user, 'eligible' or 'connected' (see GrantMatrix::FILTERS)
     * @return JSONResponse one matrix page (see GrantMatrix::page), or 400
     */
    public function index(string $search = '', string $group = '', int $page = 1, string $filter = ''): JSONResponse {
        return $this->guard(fn () => $this->matrix->page($search, $group, $page, $filter));
    }

    /**
     * Body {eligible: bool} or {module, operation, granted: bool}, never both.
     *
     * @param string $uid existing Nextcloud user id
     * @return JSONResponse the user's updated row, or 400
     */
    public function update(string $uid): JSONResponse {
        return $this->guard(function () use ($uid): array {
            $user = $this->user($uid);
            $eligible = $this->request->getParam('eligible');
            $hasGrant = $this->request->getParam('module') !== null || $this->request->getParam('operation') !== null || $this->request->getParam('granted') !== null;
            if ($eligible !== null && !$hasGrant) {
                $this->policy->setEligible($uid, self::bool($eligible));
            } elseif ($eligible === null && $hasGrant) {
                [$module, $operation] = self::grant($this->request->getParam('module'), $this->request->getParam('operation'));
                $this->policy->setGrant($uid, $module, $operation, self::bool($this->request->getParam('granted')));
            } else {
                throw new InvalidArgumentException('Invalid request');
            }
            return $this->matrix->rows([$user])[0];
        });
    }

    /**
     * Body {uids: list<string> (1..PAGE_SIZE), module: string|null, operation: string, granted: bool}; operation
     * "eligible" with module null toggles eligibility. Every uid is validated before anything is written.
     *
     * @return JSONResponse {updated: int}, or 400
     */
    public function bulk(): JSONResponse {
        return $this->guard(function (): array {
            $uids = $this->request->getParam('uids');
            if (!is_array($uids) || $uids === [] || count($uids) > GrantMatrix::PAGE_SIZE || !array_is_list($uids)) {
                throw new InvalidArgumentException('Invalid request');
            }
            $module = $this->request->getParam('module');
            $operation = $this->request->getParam('operation');
            $granted = self::bool($this->request->getParam('granted'));
            $eligibility = $operation === self::ELIGIBLE && $module === null;
            if (!$eligibility) {
                [$module, $operation] = self::grant($module, $operation);
            }
            $uids = array_values(array_unique(array_map(fn ($uid) => $this->user(is_string($uid) ? $uid : '')->getUID(), $uids)));
            foreach ($uids as $uid) {
                $eligibility ? $this->policy->setEligible($uid, $granted) : $this->policy->setGrant($uid, $module, $operation, $granted);
            }
            return ['updated' => count($uids)];
        });
    }

    /**
     * One user for the detail panel of the admin page: the matrix row, the user's groups and how many MCP
     * clients they have signed in with OAuth.
     *
     * @param string $uid existing Nextcloud user id
     * @return JSONResponse the row of GrantMatrix::detail plus connections: int, or 400
     */
    public function show(string $uid): JSONResponse {
        return $this->guard(function () use ($uid): array {
            $user = $this->user($uid);
            $connections = count($this->oauth->listGrants($user->getUID(), '', self::MAX_LISTED_CONNECTIONS, 0, time()));
            return $this->matrix->detail($user) + ['connections' => $connections];
        });
    }

    /**
     * Applies one change to every user the matrix filters select, across all pages, never to more than
     * GrantMatrix::MAX_BULK users.
     *
     * Body {search: string, group: string, filter: string, module: string|null, operations: list<string>, granted: bool,
     * dryRun: bool, expected?: int}; module null with operations ["eligible"] changes "Can connect". Users for whom the
     * permission is unavailable (a disabled account, "Can connect" off, the app unavailable to them) are left out, like
     * the "All" menu of one page. A dry run only counts. The real run must send `expected`, the `change` count of its
     * dry run: when the selection changed in between it answers 409 with the new counts and writes nothing.
     *
     * @return JSONResponse a dry run {matched, change, unavailable, unchanged, tooMany, limit}; a real run
     *     {updated, previous: list<{uid, operation, granted}>} with the values it replaced, so the page can undo it;
     *     400 for an invalid body or a selection over the limit; 409 when `expected` no longer matches
     */
    public function bulkFiltered(): JSONResponse {
        try {
            $search = $this->request->getParam('search', '');
            $group = $this->request->getParam('group', '');
            $filter = $this->request->getParam('filter', '');
            $module = $this->request->getParam('module');
            $operations = $this->request->getParam('operations');
            $granted = self::bool($this->request->getParam('granted'));
            $dryRun = self::bool($this->request->getParam('dryRun', false));
            if (!is_string($search) || !is_string($group) || !is_string($filter) || !is_array($operations) || $operations === [] || !array_is_list($operations)) {
                throw new InvalidArgumentException('Invalid request');
            }
            $eligibility = $module === null && $operations === [self::ELIGIBLE];
            if (!$eligibility) {
                foreach ($operations as $operation) {
                    self::grant($module, $operation);
                }
                if (count(array_unique($operations)) !== count($operations)) {
                    throw new InvalidArgumentException('Invalid request');
                }
            }
            $users = $this->matrix->matching($search, $group, $filter, GrantMatrix::MAX_BULK);
        } catch (InvalidArgumentException) {
            return new JSONResponse(['error' => 'Invalid request'], Http::STATUS_BAD_REQUEST);
        }
        $tooMany = count($users) > GrantMatrix::MAX_BULK;
        if ($tooMany && !$dryRun) {
            return new JSONResponse(['error' => 'Too many users', 'limit' => GrantMatrix::MAX_BULK], Http::STATUS_BAD_REQUEST);
        }
        $plan = $this->bulkPlan($tooMany ? [] : $users, $module, $operations, $granted);
        $counts = ['matched' => min(count($users), GrantMatrix::MAX_BULK), 'change' => count($plan['changes']),
            'unavailable' => $plan['unavailable'], 'unchanged' => $plan['unchanged'], 'tooMany' => $tooMany, 'limit' => GrantMatrix::MAX_BULK];
        if ($dryRun) {
            return new JSONResponse($counts);
        }
        $expected = $this->request->getParam('expected');
        if (!is_int($expected)) {
            return new JSONResponse(['error' => 'Invalid request'], Http::STATUS_BAD_REQUEST);
        }
        if ($expected !== $counts['change']) {
            return new JSONResponse(['error' => 'Conflict'] + $counts, Http::STATUS_CONFLICT);
        }
        $previous = [];
        foreach ($plan['changes'] as $uid => $values) {
            // A numeric uid became an integer array key; the policy and the answer take it as the string it is.
            $uid = (string)$uid;
            foreach ($values as $operation => $value) {
                $eligibility ? $this->policy->setEligible($uid, $granted) : $this->policy->setGrant($uid, (string)$module, $operation, $granted);
                $previous[] = ['uid' => $uid, 'operation' => $operation, 'granted' => $value];
            }
        }
        return new JSONResponse(['updated' => count($plan['changes']), 'previous' => $previous]);
    }

    /**
     * What a change would do to each user, with the rule of the page's "All" menu: a user for whom the permission is
     * unavailable is left out, a user who already has the value is unchanged.
     *
     * @param list<IUser> $users users to change
     * @param string|null $module module id, null for eligibility
     * @param list<string> $operations operation ids, or ["eligible"]
     * @param bool $granted new value
     * @return array{changes: array<array-key, array<string, bool>>, unavailable: int, unchanged: int} per uid, the
     *     operations that change and their current value
     */
    private function bulkPlan(array $users, ?string $module, array $operations, bool $granted): array {
        $changes = [];
        $unavailable = 0;
        $unchanged = 0;
        foreach (array_chunk($users, GrantMatrix::PAGE_SIZE) as $chunk) {
            foreach ($this->matrix->rows($chunk) as $row) {
                if (!$row['enabled'] || ($module !== null && (!$row['eligible'] || !($row['appsEnabled'][$module] ?? false)))) {
                    $unavailable++;
                    continue;
                }
                $values = [];
                foreach ($operations as $operation) {
                    $current = $module === null ? $row['eligible'] : $row['grants'][$module][$operation];
                    if ($current !== $granted) {
                        $values[$operation] = $current;
                    }
                }
                if ($values === []) {
                    $unchanged++;
                } else {
                    $changes[$row['uid']] = $values;
                }
            }
        }
        return ['changes' => $changes, 'unavailable' => $unavailable, 'unchanged' => $unchanged];
    }

    /**
     * Body {enabled: bool}.
     *
     * @return JSONResponse {enabled: bool}, or 400
     */
    public function service(): JSONResponse {
        return $this->guard(function (): array {
            $this->policy->setGlobalEnabled(self::bool($this->request->getParam('enabled')));
            return ['enabled' => $this->policy->globalEnabled()];
        });
    }

    /**
     * Current OAuth client settings for the admin page.
     *
     * @return JSONResponse {hosts, hostsDefault, nativeClientEnabled, nativeClientId, nativeRedirectUris}
     */
    public function oauthClients(): JSONResponse {
        return $this->guard(fn (): array => $this->oauthState());
    }

    /**
     * Body {hosts?: list<string>, nativeClientEnabled?: bool}. Everything is validated before anything is written;
     * an empty host list is rejected because at least one host must stay trusted.
     *
     * @return JSONResponse the same shape as oauthClients(), or 400
     */
    public function updateOauthClients(): JSONResponse {
        return $this->guard(function (): array {
            $hosts = $this->request->getParam('hosts');
            $native = $this->request->getParam('nativeClientEnabled');
            if ($hosts === null && $native === null) {
                throw new InvalidArgumentException('Invalid request');
            }
            if ($hosts !== null) {
                $hosts = self::hosts($hosts);
            }
            if ($native !== null) {
                $native = self::bool($native);
            }
            if ($hosts !== null) {
                $default = explode(',', ClientMetadataFetcher::DEFAULT_HOSTS);
                if (count($hosts) === count($default) && array_diff($hosts, $default) === []) {
                    // The default is not frozen into the config, so a later release can change it.
                    $this->config->deleteAppValue($this->appName, ClientMetadataFetcher::HOSTS_KEY);
                } else {
                    $this->config->setAppValue($this->appName, ClientMetadataFetcher::HOSTS_KEY, implode(',', $hosts));
                }
            }
            if ($native !== null) {
                $this->config->setAppValue($this->appName, NativeClient::CONFIG_KEY, $native ? '1' : '0');
                if (!$native) {
                    $this->oauth->deleteForClient(NativeClient::CLIENT_ID);
                }
            }
            return $this->oauthState();
        });
    }

    /**
     * Who may read the server log besides the administrators, for the "Server log" block.
     *
     * @return JSONResponse {groups, version, missing, allGroups, available, logType}
     */
    public function logsAccess(): JSONResponse {
        return $this->guard(fn (): array => $this->logsState());
    }

    /**
     * Body {groups: list<string>, previous: list<string>, version: int}: the groups whose members pass the role gate of the logs
     * module, and the list/version the page showed when the change was made. When the stored version differs,
     * another tab or administrator saved first: the answer is 409 {error: "Conflict", state} and nothing is written,
     * so a stale save never puts a removed group back. An empty list leaves the administrators only.
     *
     * @return JSONResponse the same shape as logsAccess(), 400 for an invalid body, or 409
     */
    public function updateLogsAccess(): JSONResponse {
        $groups = $this->request->getParam('groups');
        $previous = $this->request->getParam('previous');
        $version = $this->request->getParam('version');
        $valid = static fn (mixed $list): bool => is_array($list) && array_is_list($list) && count($list) <= GrantMatrix::MAX_GROUPS;
        if (!$valid($groups) || !$valid($previous) || !is_int($version) || $version < 0) {
            return new JSONResponse(['error' => 'Invalid request'], Http::STATUS_BAD_REQUEST);
        }
        try {
            return $this->guard(function () use ($groups, $previous, $version): array {
                $this->logs->setGroups($groups, $previous, $version);
                return $this->logsState();
            });
        } catch (LogsGroupsConflict) {
            return new JSONResponse(['error' => 'Conflict', 'state' => $this->logsState()], Http::STATUS_CONFLICT);
        }
    }

    /** @return array{groups:list<string>, version:int, missing:list<string>, allGroups:list<array{id:string, displayName:string}>, available:bool, logType:string} */
    private function logsState(): array {
        $state = $this->logs->state();
        return [
            'groups' => $state['groups'],
            'version' => $state['version'],
            'missing' => $this->logs->missingGroups($state['groups']),
            'allGroups' => $this->matrix->groups(),
            'available' => $this->logs->available(),
            'logType' => $this->logs->logType(),
        ];
    }

    /** @return array{hosts:list<string>, hostsDefault:bool, nativeClientEnabled:bool, nativeClientId:string, nativeRedirectUris:list<string>} */
    private function oauthState(): array {
        $raw = $this->config->getAppValue($this->appName, ClientMetadataFetcher::HOSTS_KEY, '');
        $hosts = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $h) => $h !== ''));
        $default = $hosts === [];
        return [
            'hosts' => $default ? explode(',', ClientMetadataFetcher::DEFAULT_HOSTS) : $hosts,
            'hostsDefault' => $default,
            'nativeClientEnabled' => ClientResolver::nativeEnabled($this->config),
            'nativeClientId' => NativeClient::CLIENT_ID,
            'nativeRedirectUris' => NativeClient::REDIRECT_URIS,
        ];
    }

    /**
     * @param mixed $value request value, which must be a non-empty list of bare host names
     * @return list<string> validated lowercase hosts without surrounding whitespace or duplicates
     * @throws InvalidArgumentException for an empty list, a non-string or a host with scheme, port, path, wildcard or space
     */
    private static function hosts(mixed $value): array {
        if (!is_array($value) || $value === [] || !array_is_list($value)) {
            throw new InvalidArgumentException('Invalid request');
        }
        foreach ($value as $index => $host) {
            if (!is_string($host)) {
                throw new InvalidArgumentException('Invalid request');
            }
            $host = strtolower(trim($host));
            if (preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*$/D', $host) !== 1 || strlen($host) > 253) {
                throw new InvalidArgumentException('Invalid request');
            }
            $value[$index] = $host;
        }
        return array_values(array_unique($value));
    }

    /** @param callable():array<string, mixed> $action */
    private function guard(callable $action): JSONResponse {
        try {
            return new JSONResponse($action());
        } catch (InvalidArgumentException) {
            return new JSONResponse(['error' => 'Invalid request'], Http::STATUS_BAD_REQUEST);
        }
    }

    /** @throws InvalidArgumentException for an unknown user */
    private function user(string $uid): IUser {
        $user = $uid === '' ? null : $this->userManager->get($uid);
        if ($user === null) {
            throw new InvalidArgumentException('Invalid request');
        }
        return $user;
    }

    /**
     * @param mixed $module module id from the request body, validated against GrantPolicy::CATALOG
     * @param mixed $operation operation id from the request body, validated against GrantPolicy::CATALOG
     * @return array{0:string, 1:string}
     * @throws InvalidArgumentException when the pair is not in GrantPolicy::CATALOG
     */
    private static function grant(mixed $module, mixed $operation): array {
        if (!is_string($module) || !is_string($operation) || !GrantPolicy::inCatalog($module, $operation)) {
            throw new InvalidArgumentException('Invalid request');
        }
        return [$module, $operation];
    }

    /**
     * @param mixed $value request value, which must be a JSON boolean
     * @return bool validated boolean value
     * @throws InvalidArgumentException unless the value is a JSON boolean
     */
    private static function bool(mixed $value): bool {
        if (!is_bool($value)) {
            throw new InvalidArgumentException('Invalid request');
        }
        return $value;
    }
}
