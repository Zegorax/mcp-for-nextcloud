<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

use InvalidArgumentException;
use OCP\IConfig;

/**
 * Self-hosted clients: public clients the administrator adds one by one for harnesses that take a manually
 * configured client_id and return to their own domain. Each has a name chosen by the administrator (shown on the
 * consent screen and in the connection lists), its own Enabled setting, the exact redirect URIs it may return to,
 * and a client_id "nextcloud-mcp-custom-" followed by 12 random hex characters. They use PKCE like every client;
 * redirect URIs match exactly as stored (loopback http ignores the port, see RedirectUriMatcher). With none added,
 * none is accepted.
 */
final class CustomClient {
    /** Every client_id starting with this is a self-hosted client, valid only while it is stored and enabled. */
    public const ID_PREFIX = 'nextcloud-mcp-custom';
    public const CLIENTS_KEY = 'oauth_custom_clients';
    public const MAX_CLIENTS = 20;
    public const MAX_NAME = 64;
    public const MAX_URIS = 20;
    /** Length of the redirect_uri column of the authorization codes. */
    public const MAX_LENGTH = 2000;

    /**
     * @param string $clientId client_id from a request or a stored grant
     * @return bool true when it belongs to the self-hosted namespace, whether or not such a client exists
     */
    public static function owns(string $clientId): bool {
        return str_starts_with($clientId, self::ID_PREFIX);
    }

    /** @return string the client_id of the stored client with this id */
    public static function clientId(string $id): string {
        return self::ID_PREFIX . '-' . $id;
    }

    /**
     * @param IConfig $config app configuration
     * @return list<array{id:string, name:string, enabled:bool, redirectUris:list<string>}> the stored clients; an
     *     unreadable value counts as none and a malformed entry is skipped
     */
    public static function clients(IConfig $config): array {
        $decoded = json_decode($config->getAppValue('mcp', self::CLIENTS_KEY, '[]'), true);
        if (!is_array($decoded)) {
            return [];
        }
        $clients = [];
        foreach ($decoded as $entry) {
            if (is_array($entry) && is_string($entry['id'] ?? null) && preg_match('/^[0-9a-f]{12}$/', $entry['id']) === 1
                && is_string($entry['name'] ?? null) && $entry['name'] !== '' && is_bool($entry['enabled'] ?? null)
                && is_array($entry['redirectUris'] ?? null)) {
                $clients[] = [
                    'id' => $entry['id'],
                    'name' => $entry['name'],
                    'enabled' => $entry['enabled'],
                    'redirectUris' => array_values(array_filter($entry['redirectUris'], 'is_string')),
                ];
            }
        }
        return $clients;
    }

    /**
     * @param IConfig $config app configuration
     * @param string $clientId client_id from a request or a stored grant
     * @return array{id:string, name:string, enabled:bool, redirectUris:list<string>}|null the stored client, or null
     */
    public static function find(IConfig $config, string $clientId): ?array {
        foreach (self::clients($config) as $client) {
            if (self::clientId($client['id']) === $clientId) {
                return $client;
            }
        }
        return null;
    }

    /**
     * @param IConfig $config app configuration
     * @param string $clientId client_id of a stored grant
     * @return bool true when it is a self-hosted client that is stored and enabled
     */
    public static function active(IConfig $config, string $clientId): bool {
        return self::find($config, $clientId)['enabled'] ?? false;
    }

    /**
     * @param IConfig $config app configuration
     * @return array<string, string> the name of each stored client by client_id
     */
    public static function names(IConfig $config): array {
        $names = [];
        foreach (self::clients($config) as $client) {
            $names[self::clientId($client['id'])] = $client['name'];
        }
        return $names;
    }

    /** @param array{id:string, name:string, redirectUris:list<string>} $client a stored client */
    public static function metadata(array $client): ClientMetadata {
        return new ClientMetadata(self::clientId($client['id']), $client['name'], $client['redirectUris']);
    }

    /**
     * @param mixed $value request value: the full list of clients, each {id?, name, enabled, redirectUris}; an entry
     *     without id is a new client
     * @param list<array{id:string}> $current the stored clients
     * @return list<array{id:string, name:string, enabled:bool, redirectUris:list<string>}> the clients to store, with a
     *     new random id for each new one; names and URIs trimmed, URIs without duplicates and otherwise verbatim
     * @throws InvalidArgumentException for a non-list, more than MAX_CLIENTS, an id that is not stored or repeated, a
     *     name that is empty, longer than MAX_NAME, has control characters or repeats another ignoring case, a
     *     non-boolean enabled, or redirect URIs that are not 1 to MAX_URIS acceptable ones (see validateUris)
     */
    public static function validate(mixed $value, array $current): array {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_CLIENTS) {
            throw new InvalidArgumentException('Invalid request');
        }
        $known = array_column($current, 'id');
        $ids = [];
        $names = [];
        $clients = [];
        foreach ($value as $entry) {
            if (!is_array($entry)) {
                throw new InvalidArgumentException('Invalid request');
            }
            $id = $entry['id'] ?? null;
            if ($id === null) {
                $id = bin2hex(random_bytes(6));
            } elseif (!is_string($id) || !in_array($id, $known, true) || isset($ids[$id])) {
                throw new InvalidArgumentException('Invalid request');
            }
            $ids[$id] = true;
            $name = is_string($entry['name'] ?? null) ? trim($entry['name']) : '';
            $key = mb_strtolower($name);
            if ($name === '' || mb_strlen($name) > self::MAX_NAME || preg_match('/[\x00-\x1f\x7f]/u', $name) !== 0 || isset($names[$key])) {
                throw new InvalidArgumentException('Invalid request');
            }
            $names[$key] = true;
            if (!is_bool($entry['enabled'] ?? null)) {
                throw new InvalidArgumentException('Invalid request');
            }
            $uris = self::validateUris($entry['redirectUris'] ?? null);
            if ($uris === []) {
                throw new InvalidArgumentException('Invalid request');
            }
            $clients[] = ['id' => $id, 'name' => $name, 'enabled' => $entry['enabled'], 'redirectUris' => $uris];
        }
        return $clients;
    }

    /**
     * @param list<array{id:string, enabled:bool, redirectUris:list<string>}> $before the stored clients
     * @param list<array{id:string, enabled:bool, redirectUris:list<string>}> $after the clients about to be stored
     * @return list<string> client_ids whose grants must go: deleted, turned off, or missing a redirect URI they had
     */
    public static function withdrawn(array $before, array $after): array {
        $next = array_column($after, null, 'id');
        $withdrawn = [];
        foreach ($before as $client) {
            $now = $next[$client['id']] ?? null;
            if ($now === null || !$now['enabled'] || array_diff($client['redirectUris'], $now['redirectUris']) !== []) {
                $withdrawn[] = self::clientId($client['id']);
            }
        }
        return $withdrawn;
    }

    /**
     * @param mixed $value request value, which must be a list of redirect URIs (empty allowed)
     * @return list<string> the URIs, trimmed and without duplicates, stored verbatim otherwise
     * @throws InvalidArgumentException for a non-list, too many entries or any URI that is not an absolute https URI
     *         (http only on loopback) without userinfo, fragment, wildcard, whitespace or control characters
     */
    public static function validateUris(mixed $value): array {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_URIS) {
            throw new InvalidArgumentException('Invalid request');
        }
        $uris = [];
        foreach ($value as $uri) {
            if (!is_string($uri)) {
                throw new InvalidArgumentException('Invalid request');
            }
            $uri = trim($uri);
            if (!self::acceptable($uri)) {
                throw new InvalidArgumentException('Invalid request');
            }
            $uris[] = $uri;
        }
        return array_values(array_unique($uris));
    }

    /** @return bool true when $uri may be registered as a redirect URI of a self-hosted client */
    private static function acceptable(string $uri): bool {
        if ($uri === '' || strlen($uri) > self::MAX_LENGTH || preg_match('/[\s\x00-\x1f\x7f*#\\\\]/', $uri) === 1) {
            return false;
        }
        $parts = parse_url($uri);
        if (!is_array($parts) || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $scheme = $parts['scheme'] ?? '';
        return $scheme === 'https' || $scheme === 'http' && RedirectUriMatcher::isLoopback($uri);
    }
}
