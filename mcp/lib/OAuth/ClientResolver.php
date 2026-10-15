<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\OAuth;

use OCP\IConfig;

/**
 * Resolves a client_id to its metadata: the optional native client, a self-hosted client, or a Client ID
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
     *         (a self-hosted client_id that is not stored, or whose client is disabled, counts as disabled)
     */
    public function resolve(string $clientId): ClientMetadata {
        if ($clientId === NativeClient::CLIENT_ID) {
            if (!self::nativeEnabled($this->config)) {
                throw new OAuthException('invalid_client', 'Unknown or not allowed client');
            }
            return NativeClient::metadata();
        }
        if (CustomClient::owns($clientId)) {
            $client = CustomClient::find($this->config, $clientId);
            if ($client === null || !$client['enabled'] || $client['redirectUris'] === []) {
                throw new OAuthException('invalid_client', 'Unknown or not allowed client');
            }
            return CustomClient::metadata($client);
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
     * @return bool true when the grant belongs to a built-in client the administrator has turned off, or to a
     *     self-hosted client that was turned off or deleted
     */
    public static function builtInDisabled(IConfig $config, string $clientId): bool {
        return $clientId === NativeClient::CLIENT_ID && !self::nativeEnabled($config)
            || CustomClient::owns($clientId) && !CustomClient::active($config, $clientId);
    }
}
