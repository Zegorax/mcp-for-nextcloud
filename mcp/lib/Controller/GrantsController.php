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
use OCA\Mcp\OAuth\CustomClient;
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
     * Body {eligible: bool} or {module, operation, granted: bool}, never both; or the same with
     * state: "allow" | "deny" | "inherit" instead of the boolean, where "inherit" removes the individual value so
     * the user's groups (or the default) decide.
     *
     * @param string $uid existing Nextcloud user id
     * @return JSONResponse the user's updated row, or 400
     */
    public function update(string $uid): JSONResponse {
        return $this->guard(function () use ($uid): array {
            $user = $this->user($uid);
            $eligible = $this->request->getParam('eligible');
            $state = $this->request->getParam('state');
            $granted = $this->request->getParam('granted');
            $hasGrant = $this->request->getParam('module') !== null || $this->request->getParam('operation') !== null;
            if ($state !== null) {
                if ($eligible !== null || $granted !== null) {
                    throw new InvalidArgumentException('Invalid request');
                }
                $value = self::state($state);
                if ($hasGrant) {
                    [$module, $operation] = self::grant($this->request->getParam('module'), $this->request->getParam('operation'));
                    $this->policy->setGrantOverride($uid, $module, $operation, $value);
                } else {
                    $this->policy->setEligibleOverride($uid, $value);
                }
            } elseif ($eligible !== null && !$hasGrant && $granted === null) {
                $this->policy->setEligible($uid, self::bool($eligible));
            } elseif ($eligible === null && ($hasGrant || $granted !== null)) {
                [$module, $operation] = self::grant($this->request->getParam('module'), $this->request->getParam('operation'));
                $this->policy->setGrant($uid, $module, $operation, self::bool($granted));
            } else {
                throw new InvalidArgumentException('Invalid request');
            }
            return $this->matrix->rows([$user])[0];
        });
    }

    /**
     * Body {uids: list<string> (1..PAGE_SIZE), module: string|null, operation: string, granted: bool}, or state
     * "allow" | "deny" | "inherit" instead of granted; operation "eligible" with module null changes eligibility.
     * Every uid is validated before anything is written.
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
            $state = $this->request->getParam('state');
            if ($state !== null && $this->request->getParam('granted') !== null) {
                throw new InvalidArgumentException('Invalid request');
            }
            $granted = $state !== null ? self::state($state) : self::bool($this->request->getParam('granted'));
            $eligibility = $operation === self::ELIGIBLE && $module === null;
            if (!$eligibility) {
                [$module, $operation] = self::grant($module, $operation);
            }
            $uids = array_values(array_unique(array_map(fn ($uid) => $this->user(is_string($uid) ? $uid : '')->getUID(), $uids)));
            foreach ($uids as $uid) {
                $eligibility ? $this->policy->setEligibleOverride($uid, $granted) : $this->policy->setGrantOverride($uid, $module, $operation, $granted);
            }
            return ['updated' => count($uids)];
        });
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
     * @return JSONResponse {hosts, hostsDefault, nativeClientEnabled, nativeClientId, nativeRedirectUris,
     *                      customClientEnabled, customClientId, customRedirectUris}
     */
    public function oauthClients(): JSONResponse {
        return $this->guard(fn (): array => $this->oauthState());
    }

    /**
     * Body {hosts?: list<string>, nativeClientEnabled?: bool, customClientEnabled?: bool,
     * customRedirectUris?: list<string>}. Everything is validated before anything is written; an empty host list is
     * rejected because at least one host must stay trusted. Turning a built-in client off deletes its grants, and so
     * does removing a redirect URI of the custom client, so nothing issued for a withdrawn URI outlives the decision.
     *
     * @return JSONResponse the same shape as oauthClients(), or 400
     */
    public function updateOauthClients(): JSONResponse {
        return $this->guard(function (): array {
            $hosts = $this->request->getParam('hosts');
            $native = $this->request->getParam('nativeClientEnabled');
            $custom = $this->request->getParam('customClientEnabled');
            $uris = $this->request->getParam('customRedirectUris');
            if ($hosts === null && $native === null && $custom === null && $uris === null) {
                throw new InvalidArgumentException('Invalid request');
            }
            if ($hosts !== null) {
                $hosts = self::hosts($hosts);
            }
            if ($native !== null) {
                $native = self::bool($native);
            }
            if ($custom !== null) {
                $custom = self::bool($custom);
            }
            if ($uris !== null) {
                $uris = CustomClient::validate($uris);
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
            $revokeCustom = false;
            if ($uris !== null) {
                $revokeCustom = array_diff(CustomClient::redirectUris($this->config), $uris) !== [];
                if ($uris === []) {
                    $this->config->deleteAppValue($this->appName, CustomClient::REDIRECTS_KEY);
                } else {
                    $this->config->setAppValue($this->appName, CustomClient::REDIRECTS_KEY, (string)json_encode($uris, JSON_UNESCAPED_SLASHES));
                }
            }
            if ($custom !== null) {
                $this->config->setAppValue($this->appName, CustomClient::ENABLED_KEY, $custom ? '1' : '0');
                $revokeCustom = $revokeCustom || !$custom;
            }
            if ($revokeCustom) {
                $this->oauth->deleteForClient(CustomClient::CLIENT_ID);
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

    /**
     * @return array{hosts:list<string>, hostsDefault:bool, nativeClientEnabled:bool, nativeClientId:string,
     *     nativeRedirectUris:list<string>, customClientEnabled:bool, customClientId:string, customRedirectUris:list<string>}
     */
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
            'customClientEnabled' => CustomClient::enabled($this->config),
            'customClientId' => CustomClient::CLIENT_ID,
            'customRedirectUris' => CustomClient::redirectUris($this->config),
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
     * @param mixed $value request value: "allow", "deny" or "inherit"
     * @return bool|null true allow, false deny, null inherit
     * @throws InvalidArgumentException for anything else
     */
    private static function state(mixed $value): ?bool {
        return match ($value) {
            'allow' => true,
            'deny' => false,
            'inherit' => null,
            default => throw new InvalidArgumentException('Invalid request'),
        };
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
