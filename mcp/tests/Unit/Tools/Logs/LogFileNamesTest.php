<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Logs;

use OCA\Mcp\Service\LogFileNames;
use OCA\Mcp\Service\VisibilityGuard;
use OCA\Mcp\Tests\Unit\InMemoryAppConfig;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tools\Logs\LogAnalyzer;
use OCA\Mcp\Tools\Logs\LogRedactor;
use OCA\Mcp\Tools\Logs\LogTime;
use OCA\Mcp\Tools\ToolRegistry;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;

/**
 * File names and paths in what the log tools show and in what the app itself writes to the log.
 */
final class LogFileNamesTest extends TestCase {
    /** Audit A4: a hidden path in the message and the URL of an entry. */
    public function testAnEntryHidesThePathInItsMessageAndUrl(): void {
        $entry = (object)['level' => 3, 'message' => 'Cannot open /alice/files/secret/salary.txt',
            'url' => '/remote.php/dav/files/alice/secret/salary.txt'];

        $out = LogRedactor::entry($entry, true);

        $this->assertStringNotContainsString('salary', $out['message']);
        $this->assertStringNotContainsString('secret', $out['message']);
        $this->assertSame('/remote.php/dav/files/alice/[hidden]', $out['url']);
    }

    public function testWithoutHidingAnEntryKeepsItsNames(): void {
        $entry = (object)['level' => 3, 'message' => 'Cannot open /alice/files/secret/salary.txt',
            'url' => '/remote.php/dav/files/alice/secret/salary.txt'];

        $out = LogRedactor::entry($entry);

        $this->assertStringContainsString('secret/salary.txt', $out['message']);
        $this->assertStringContainsString('secret/salary.txt', $out['url']);
    }

    /** The app's own log of an unexpected exception keeps its class and code only, in any mode. */
    public function testTheAppsOwnLogOfAnExceptionKeepsOnlyClassAndCode(): void {
        $this->assertSame('RuntimeException (code 0)', ToolRegistry::loggable(new \RuntimeException('Cannot access salary-secret.txt')));
        $this->assertSame('RuntimeException (code 7)', ToolRegistry::loggable(new \RuntimeException('File with name /Docs/Salary#Confidential could not be located', 7)));
        $this->assertSame('RuntimeException (code 0)', ToolRegistry::loggable(new \RuntimeException('File "README" is locked')));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function hidden(): iterable {
        // Review of A4: parts of names that the first heuristic left behind.
        yield 'name with # from the DAV message' => ['File with name /Docs/Salary#Confidential could not be located', 'File with name [hidden]'];
        yield 'folder with a space' => ['Could not move /Secret Folder/Payroll 2026', 'Could not move [hidden]'];
        yield 'not a route of an app' => ['Request to /apps/Secret failed', 'Request to [hidden]'];
        yield 'quoted name without extension' => ['File "README" is locked', 'File "[hidden]" is locked'];
        yield 'quoted name with unknown extension' => ['File "salary.secret" is locked', 'File "[hidden]" is locked'];
        yield 'quoted url with a path' => ['Fetch of "https://cloud.example.com/remote.php/dav/files/a/x.pdf" failed', 'Fetch of "https://cloud.example.com/[hidden]" failed'];
        // The first heuristic's cases.
        yield 'webdav path' => ['/remote.php/dav/files/alice/Docs/a.pdf', '/remote.php/dav/files/alice/[hidden]'];
        yield 'webdav path of a full url' => ['GET https://cloud.example.com/remote.php/webdav/Docs/a failed', 'GET https://cloud.example.com/remote.php/webdav/[hidden]'];
        yield 'public share dav keeps no token' => ['/public.php/dav/files/AbC123/Docs/a.pdf', '/public.php/dav/files/[hidden]'];
        yield 'public share link keeps no token' => ['/s/AbCdEf123/download', '/s/[hidden]'];
        yield 'route with more after it' => ['/index.php/apps/files/ajax/list.php', '/index.php/apps/files/[hidden]'];
        yield 'query of a route' => ['/index.php/apps/files/?dir=/secret&fileid=12', '/index.php/apps/files/[hidden]'];
        yield 'storage path' => ['Path /alice/files/Docs is locked', 'Path [hidden]'];
        yield 'data directory' => ['Path /var/www/data/alice/files/Docs/x is locked', 'Path [hidden]'];
        yield 'relative path' => ['Could not read secret/salary.txt here', 'Could not read [hidden]'];
        yield 'bare name' => ['Cannot access salary-secret.txt', 'Cannot access [hidden]'];
        yield 'quoted name with spaces' => ['File name must not end with a space: "Orçamento Exemplo .xlsx"', 'File name must not end with a space: "[hidden]"'];
        yield 'single quoted path' => ["Move of 'Docs/old plan' failed", "Move of '[hidden]' failed"];
    }

    /** @dataProvider hidden */
    public function testFileNamesAndPathsAreHidden(string $text, string $expected): void {
        $this->assertSame($expected, LogRedactor::fileNames($text));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function kept(): iterable {
        yield 'quoted url without a path and quoted version' => ['Failed connecting to "https://cloud.example.com" version "31.0.9"'];
        yield 'quoted class and method' => ['Error in "OC\\Files\\View::fopen" call'];
        yield 'route alone' => ['Hit /index.php/apps/files/ ok'];
        yield 'host and version' => ['Error connecting to apps.nextcloud.com version 31.0.9'];
        yield 'masked addresses' => ['Login failed from 203.0.x.x and 2001:db8::/48'];
        yield 'mime type' => ['Content type text/plain not allowed'];
        yield 'class and method' => ['OC\\Files\\View::file_put_contents failed'];
        yield 'plain text' => ['User alice not found'];
    }

    /** @dataProvider kept */
    public function testWhatIsNotAFileStays(string $text): void {
        $this->assertSame($text, LogRedactor::fileNames($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function urls(): iterable {
        yield 'webdav' => ['/remote.php/dav/files/alice/Docs/a.pdf', '/remote.php/dav/files/alice/[hidden]'];
        yield 'query' => ['/index.php/apps/files/?dir=/secret', '/index.php/apps/files/?[hidden]'];
        yield 'not a route' => ['https://cloud.example.com/apps/Secret', 'https://cloud.example.com/[hidden]'];
        yield 'ocs with query' => ['/ocs/v2.php/apps/notifications/api/v2/notifications?format=json', '/ocs/v2.php/apps/notifications/[hidden]?[hidden]'];
        yield 'public share' => ['/s/AbC/download', '/s/[hidden]'];
        yield 'route alone' => ['/status.php', '/status.php'];
    }

    /** @dataProvider urls */
    public function testAUrlKeepsHostAndRoutePrefixOnly(string $url, string $expected): void {
        $this->assertSame($expected, LogRedactor::url($url));
    }

    public function testTheSummaryHidesNamesInSignaturesExamplesAndUrls(): void {
        $entries = [(object)['level' => 3, 'time' => '2026-10-07T08:12:03-03:00', 'app' => 'webdav', 'user' => 'alice',
            'url' => '/remote.php/dav/files/alice/secret/salary.txt', 'message' => 'Cannot access salary-secret.txt', 'userAgent' => 'curl'],
            (object)['level' => 3, 'time' => '2026-10-07T08:12:03-03:00', 'app' => 'webdav', 'user' => 'alice',
            'url' => '/apps/Secret', 'message' => 'File with name /Docs/Salary#Confidential could not be located', 'userAgent' => 'curl']];

        $hidden = json_encode((new LogAnalyzer(new LogTime(\DateTimeInterface::ATOM, 'UTC'), true))->analyze($entries));
        $shown = json_encode((new LogAnalyzer(new LogTime(\DateTimeInterface::ATOM, 'UTC')))->analyze($entries));

        $this->assertStringNotContainsString('salary', $hidden);
        $this->assertStringNotContainsString('Confidential', $hidden);
        $this->assertStringNotContainsString('Secret', $hidden);
        $this->assertStringContainsString('salary-secret.txt', $shown);
    }

    /** On by default, and only active while files are hidden by tags or the tag setting is unreadable. */
    public function testTheSwitchIsOnByDefaultAndActiveOnlyWithHiddenTags(): void {
        $config = new InMemoryConfig();
        $appConfig = new InMemoryAppConfig();
        $service = fn (): LogFileNames => new LogFileNames($appConfig->worker($this),
            new VisibilityGuard($config->mock($this), $this->createMock(ISystemTagObjectMapper::class)));

        $this->assertTrue($service()->enabled());
        $this->assertFalse($service()->active(), 'sem etiqueta configurada nada muda');

        $config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $this->assertTrue($service()->active());

        $config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"';
        $this->assertTrue($service()->active(), 'configuração inválida esconde tudo');

        $service()->setEnabled(false);
        $this->assertFalse($service()->enabled());
        $this->assertFalse($service()->active());
    }
}
