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
 * The optional built-in public client for self-hosted harnesses that take a manually configured client_id and
 * return to their own domain. It is off until an administrator enables {@see self::ENABLED_KEY} and lists the exact
 * redirect URIs in {@see self::REDIRECTS_KEY}. It has a fixed, non-secret client_id and uses PKCE; redirect URIs
 * match exactly as stored (loopback http ignores the port, see RedirectUriMatcher).
 */
final class CustomClient {
    public const CLIENT_ID = 'nextcloud-mcp-custom';
    public const ENABLED_KEY = 'oauth_custom_client_enabled';
    public const REDIRECTS_KEY = 'oauth_custom_redirect_uris';
    public const NAME = 'Self-hosted client (custom redirect URIs)';
    public const MAX_URIS = 20;
    /** Length of the redirect_uri column of the authorization codes. */
    public const MAX_LENGTH = 2000;

    /**
     * @param IConfig $config app configuration
     * @return bool true when the administrator enabled the custom client
     */
    public static function enabled(IConfig $config): bool {
        return $config->getAppValue('mcp', self::ENABLED_KEY, '0') === '1';
    }

    /**
     * @param IConfig $config app configuration
     * @return list<string> the stored redirect URIs; an unreadable value counts as none
     */
    public static function redirectUris(IConfig $config): array {
        $decoded = json_decode($config->getAppValue('mcp', self::REDIRECTS_KEY, '[]'), true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    /** @return ClientMetadata metadata of the custom client with the stored redirect URIs */
    public static function metadata(IConfig $config): ClientMetadata {
        return new ClientMetadata(self::CLIENT_ID, self::NAME, self::redirectUris($config));
    }

    /**
     * @param mixed $value request value, which must be a list of redirect URIs (empty allowed)
     * @return list<string> the URIs, trimmed and without duplicates, stored verbatim otherwise
     * @throws InvalidArgumentException for a non-list, too many entries or any URI that is not an absolute https URI
     *         (http only on loopback) without userinfo, fragment, wildcard, whitespace or control characters
     */
    public static function validate(mixed $value): array {
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

    /** @return bool true when $uri may be registered as a redirect URI of the custom client */
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
