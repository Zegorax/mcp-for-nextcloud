<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\OAuth;

use InvalidArgumentException;
use OCA\Mcp\OAuth\ClientMetadataFetcher;
use OCA\Mcp\OAuth\ClientResolver;
use OCA\Mcp\OAuth\CustomClient;
use OCA\Mcp\OAuth\NativeClient;
use OCA\Mcp\OAuth\OAuthException;
use OCA\Mcp\OAuth\RedirectUriMatcher;
use OCA\Mcp\Service\ConnectionList;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CustomClientTest extends TestCase {
    private const URI = 'https://harness.example.com/oauth/callback';

    private InMemoryConfig $config;

    private function resolver(): ClientResolver {
        $this->config = new InMemoryConfig();
        $fetcher = $this->createMock(ClientMetadataFetcher::class);
        $fetcher->expects($this->never())->method('fetch');
        return new ClientResolver($fetcher, $this->config->mock($this));
    }

    private function assertInvalidClient(ClientResolver $resolver): void {
        try {
            $resolver->resolve(CustomClient::CLIENT_ID);
            $this->fail('the custom client must be refused');
        } catch (OAuthException $e) {
            $this->assertSame('invalid_client', $e->error);
            $this->assertFalse($e->redirectable);
        }
    }

    public function testTheCustomClientIsOffByDefaultAndWhenZero(): void {
        $resolver = $this->resolver();
        $this->config->app['mcp'][CustomClient::REDIRECTS_KEY] = json_encode([self::URI]);
        $this->assertInvalidClient($resolver);
        $this->config->app['mcp'][CustomClient::ENABLED_KEY] = '0';
        $this->assertInvalidClient($resolver);
    }

    public function testAnEnabledCustomClientWithoutRedirectUrisIsRefused(): void {
        $resolver = $this->resolver();
        $this->config->app['mcp'][CustomClient::ENABLED_KEY] = '1';
        $this->assertInvalidClient($resolver);
        $this->config->app['mcp'][CustomClient::REDIRECTS_KEY] = 'not json';
        $this->assertInvalidClient($resolver);
        $this->config->app['mcp'][CustomClient::REDIRECTS_KEY] = '[]';
        $this->assertInvalidClient($resolver);
    }

    public function testAnEnabledCustomClientResolvesWithTheStoredUrisAndMatchesExactly(): void {
        $resolver = $this->resolver();
        $this->config->app['mcp'][CustomClient::ENABLED_KEY] = '1';
        $this->config->app['mcp'][CustomClient::REDIRECTS_KEY] = json_encode([self::URI, 'https://other.example.org/cb?tenant=a']);
        $client = $resolver->resolve(CustomClient::CLIENT_ID);
        $this->assertSame(CustomClient::CLIENT_ID, $client->clientId);
        $this->assertSame(CustomClient::NAME, $client->host());
        $this->assertTrue(RedirectUriMatcher::matches(self::URI, $client->redirectUris));
        $this->assertTrue(RedirectUriMatcher::matches('https://other.example.org/cb?tenant=a', $client->redirectUris));
        foreach ([self::URI . '/', self::URI . '?x=1', 'https://harness.example.com/oauth/other', 'https://harness.example.com:8443/oauth/callback',
            'http://harness.example.com/oauth/callback', 'https://evil.example/oauth/callback', 'https://other.example.org/cb?tenant=b',
            'https://harness.example.com.evil.example/oauth/callback'] as $bad) {
            $this->assertFalse(RedirectUriMatcher::matches($bad, $client->redirectUris), $bad);
        }
    }

    public function testTheCustomClientDoesNotOpenTheNativeOne(): void {
        $resolver = $this->resolver();
        $this->config->app['mcp'][CustomClient::ENABLED_KEY] = '1';
        $this->config->app['mcp'][CustomClient::REDIRECTS_KEY] = json_encode([self::URI]);
        try {
            $resolver->resolve(NativeClient::CLIENT_ID);
            $this->fail('the native client stays off');
        } catch (OAuthException $e) {
            $this->assertSame('invalid_client', $e->error);
        }
    }

    public function testBuiltInDisabledCoversBothBuiltInClientsOnly(): void {
        $this->resolver();
        $config = $this->config->mock($this);
        $this->assertTrue(ClientResolver::builtInDisabled($config, CustomClient::CLIENT_ID));
        $this->assertTrue(ClientResolver::builtInDisabled($config, NativeClient::CLIENT_ID));
        $this->assertFalse(ClientResolver::builtInDisabled($config, 'https://claude.ai/c'));
        $this->config->app['mcp'][CustomClient::ENABLED_KEY] = '1';
        $this->assertFalse(ClientResolver::builtInDisabled($config, CustomClient::CLIENT_ID));
        $this->assertTrue(ClientResolver::builtInDisabled($config, NativeClient::CLIENT_ID));
    }

    public function testValidationTrimsAndDeduplicatesAndKeepsTheRestVerbatim(): void {
        $this->assertSame([self::URI, 'https://Other.Example.org/CB?a=1&b=2', 'http://localhost/cb', 'http://127.0.0.1:8080/cb'],
            CustomClient::validate(['  ' . self::URI . "\t", self::URI, 'https://Other.Example.org/CB?a=1&b=2', 'http://localhost/cb', 'http://127.0.0.1:8080/cb']));
        $this->assertSame([], CustomClient::validate([]));
        $this->assertCount(CustomClient::MAX_URIS, CustomClient::validate(array_map(fn (int $i) => self::URI . $i, range(1, CustomClient::MAX_URIS))));
    }

    /** @return array<string, array{0:mixed}> */
    public static function invalid(): array {
        return [
            'not a list' => [['a' => self::URI]],
            'a string' => [self::URI],
            'null entry' => [[null]],
            'empty entry' => [['  ']],
            'too many' => [array_map(fn (int $i) => self::URI . $i, range(0, CustomClient::MAX_URIS))],
            'too long' => [['https://harness.example.com/' . str_repeat('a', CustomClient::MAX_LENGTH)]],
            'http on a public host' => [['http://harness.example.com/cb']],
            'relative' => [['/oauth/callback']],
            'no host' => [['https:///cb']],
            'other scheme' => [['javascript://harness.example.com/%0aalert(1)']],
            'custom scheme' => [['com.example.app:/cb']],
            'uppercase scheme' => [['HTTPS://harness.example.com/cb']],
            'userinfo' => [['https://user@harness.example.com/cb']],
            'userinfo with password' => [['https://user:pw@harness.example.com/cb']],
            'fragment' => [['https://harness.example.com/cb#x']],
            'wildcard host' => [['https://*.example.com/cb']],
            'wildcard path' => [['https://harness.example.com/*']],
            'inner space' => [['https://harness.example.com/a b']],
            'newline' => [["https://harness.example.com/cb\nhttps://evil.example/cb"]],
            'backslash' => [['https://harness.example.com\\@evil.example/cb']],
        ];
    }

    #[DataProvider('invalid')]
    public function testValidationRejects(mixed $value): void {
        $this->expectException(InvalidArgumentException::class);
        CustomClient::validate($value);
    }

    public function testConnectionListLabelsTheCustomClient(): void {
        $this->assertSame(['kind' => 'custom', 'host' => '', 'id' => CustomClient::CLIENT_ID], ConnectionList::client(CustomClient::CLIENT_ID));
    }
}
