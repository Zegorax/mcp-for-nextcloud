<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

use OCP\IConfig;

/**
 * Resolves a client_id to its metadata: one of the optional built-in clients (native, custom), or a Client ID
 * Metadata Document.
 */
class ClientResolver {
    public function __construct(
        private ClientMetadataFetcher $fetcher,
        private IConfig $config,
    ) {}

    /**
     * @param string $clientId client_id from the authorization request
     * @return ClientMetadata metadata of the client
     * @throws OAuthException invalid_client (never redirectable) when the client is unknown, disallowed or disabled
     *         (the custom client also counts as disabled while it has no redirect URI)
     */
    public function resolve(string $clientId): ClientMetadata {
        if ($clientId === NativeClient::CLIENT_ID) {
            if (!self::nativeEnabled($this->config)) {
                throw new OAuthException('invalid_client', 'Unknown or not allowed client');
            }
            return NativeClient::metadata();
        }
        if ($clientId === CustomClient::CLIENT_ID) {
            $client = CustomClient::metadata($this->config);
            if (!CustomClient::enabled($this->config) || $client->redirectUris === []) {
                throw new OAuthException('invalid_client', 'Unknown or not allowed client');
            }
            return $client;
        }
        return $this->fetcher->fetch($clientId);
    }

    /**
     * @param IConfig $config app configuration
     * @return bool true when the administrator enabled the native client
     */
    public static function nativeEnabled(IConfig $config): bool {
        return $config->getAppValue('mcp', NativeClient::CONFIG_KEY, '0') === '1';
    }

    /**
     * @param IConfig $config app configuration
     * @param string $clientId client of a stored grant
     * @return bool true when the grant belongs to a built-in client the administrator has turned off
     */
    public static function builtInDisabled(IConfig $config, string $clientId): bool {
        return $clientId === NativeClient::CLIENT_ID && !self::nativeEnabled($config)
            || $clientId === CustomClient::CLIENT_ID && !CustomClient::enabled($config);
    }
}
