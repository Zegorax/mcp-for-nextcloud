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

    /** Audit A4: the app's own log of an unexpected exception carries no file name, in any mode. */
    public function testTheAppsOwnLogOfAnExceptionCarriesNoFileName(): void {
        $this->assertSame('Cannot access [hidden]', ToolRegistry::loggable(new \RuntimeException('Cannot access salary-secret.txt')));
        $this->assertStringNotContainsString('Relat', ToolRegistry::loggable(new \RuntimeException('Could not move "Relatório final.pdf"')));
        $this->assertSame('Lock wait timeout', ToolRegistry::loggable(new \RuntimeException('Lock wait timeout')));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function hidden(): iterable {
        yield 'webdav path' => ['/remote.php/dav/files/alice/Docs/a.pdf', '/remote.php/dav/files/alice/[hidden]'];
        yield 'webdav path of a full url' => ['https://cloud.example.com/remote.php/webdav/Docs/a', 'https://cloud.example.com/remote.php/webdav/[hidden]'];
        yield 'public share dav' => ['/public.php/dav/files/AbC123/Docs/a.pdf', '/public.php/dav/files/AbC123/[hidden]'];
        yield 'storage path' => ['Path /alice/files/Docs is locked', 'Path [hidden] is locked'];
        yield 'data directory' => ['Path /var/www/data/alice/files/Docs/x is locked', 'Path [hidden] is locked'];
        yield 'user path without extension' => ['Cannot move /Projetos/Secreto', 'Cannot move [hidden]'];
        yield 'relative path with extension' => ['Could not read secret/salary.txt here', 'Could not read [hidden] here'];
        yield 'bare name' => ['Cannot access salary-secret.txt', 'Cannot access [hidden]'];
        yield 'quoted name with spaces' => ['File name must not end with a space: "Orçamento Exemplo .xlsx"', 'File name must not end with a space: "[hidden]"'];
        yield 'single quoted path' => ["Move of 'Docs/old plan' failed", "Move of '[hidden]' failed"];
        yield 'query parameter' => ['/index.php/apps/files/?dir=/secret&fileid=12', '/index.php/apps/files/?dir=[hidden]&fileid=12'];
        yield 'encoded query parameter' => ['/index.php/apps/files/ajax/download.php?files=%2Fa.txt', '/index.php/apps/files/ajax/download.php?files=[hidden]'];
    }

    /** @dataProvider hidden */
    public function testFileNamesAndPathsAreHidden(string $text, string $expected): void {
        $this->assertSame($expected, LogRedactor::fileNames($text));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function kept(): iterable {
        yield 'route' => ['/index.php/apps/files/ajax/list.php'];
        yield 'ocs route' => ['/ocs/v2.php/apps/notifications/api/v2/notifications'];
        yield 'public share link' => ['/s/AbCdEf123/download'];
        yield 'host and version' => ['Error connecting to apps.nextcloud.com version 31.0.9'];
        yield 'masked address' => ['Login failed from 203.0.x.x'];
        yield 'class and method' => ['OC\\Files\\View::file_put_contents failed'];
        yield 'plain text' => ['User alice not found'];
    }

    /** @dataProvider kept */
    public function testWhatIsNotAFileStays(string $text): void {
        $this->assertSame($text, LogRedactor::fileNames($text));
    }

    public function testTheSummaryHidesNamesInSignaturesExamplesAndUrls(): void {
        $entries = [(object)['level' => 3, 'time' => '2026-10-07T08:12:03-03:00', 'app' => 'webdav', 'user' => 'alice',
            'url' => '/remote.php/dav/files/alice/secret/salary.txt', 'message' => 'Cannot access salary-secret.txt', 'userAgent' => 'curl']];

        $hidden = json_encode((new LogAnalyzer(new LogTime(\DateTimeInterface::ATOM, 'UTC'), true))->analyze($entries));
        $shown = json_encode((new LogAnalyzer(new LogTime(\DateTimeInterface::ATOM, 'UTC')))->analyze($entries));

        $this->assertStringNotContainsString('salary', $hidden);
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
