<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Service;

use OCA\Mcp\Service\HiddenTagsUnavailableException;
use OCA\Mcp\Service\VisibilityGuard;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\Tools\FakeTree;
use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\Files\FileBackup;
use OCA\Mcp\Tools\ToolFailure;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\TestCase;

final class VisibilityGuardTest extends TestCase {
    private InMemoryConfig $config;
    private $tagMapper;
    private FakeTree $tree;
    private array $tagAssignments = [];

    protected function setUp(): void {
        parent::setUp();
        $this->config = new InMemoryConfig();
        $this->tree = new FakeTree($this, '/alice/files');
        $this->tagAssignments = [];

        $this->tagMapper = $this->createMock(ISystemTagObjectMapper::class);
        $this->tagMapper->method('getTagIdsForObjects')->willReturnCallback(function (array $objIds, string $objectType): array {
            $result = [];
            foreach ($objIds as $id) {
                $result[(string)$id] = $this->tagAssignments[(string)$id] ?? [];
            }
            return $result;
        });
        $this->tagMapper->method('assignTags')->willReturnCallback(function (string $objId, string $objectType, array $tagIds): void {
            $existing = $this->tagAssignments[$objId] ?? [];
            $this->tagAssignments[$objId] = array_values(array_unique(array_merge($existing, array_map(strval(...), $tagIds))));
        });
    }

    private function guard(): VisibilityGuard {
        return new VisibilityGuard($this->config->mock($this), $this->tagMapper);
    }

    public function testZeroCostWhenNoTagsConfigured(): void {
        $guard = $this->guard();
        $this->assertSame([], $guard->getHiddenTagIds());

        $fileId = $this->tree->addFile('/alice/files/doc.txt', 'hello');
        $file = $this->tree->node('/alice/files/doc.txt');

        // tagMapper should not even be called if hidden tags is empty
        $this->assertTrue($guard->isVisible($file));
        $guard->assertVisible($file);
        $this->assertSame([$file], $guard->filter([$file]));
    }

    public function testTagLookupFailureIsHiddenAndRetriedWithoutLeakingNames(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $mapper = $this->createMock(ISystemTagObjectMapper::class);
        $calls = 0;
        $mapper->expects($this->exactly(2))->method('getTagIdsForObjects')->willReturnCallback(
            function () use (&$calls): array {
                if (++$calls === 1) {
                    throw new \RuntimeException('secret.txt');
                }
                return [];
            }
        );
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            'MCP visibility check failed; access refused', ['app' => 'mcp']
        );
        $node = $this->createMock(Node::class);
        $node->method('getId')->willReturn(123);
        $node->method('getParent')->willReturn($node);
        $guard = new VisibilityGuard($this->config->mock($this), $mapper, null, null, $logger);
        $this->assertFalse($guard->isVisible($node));
        $this->assertTrue($guard->isVisible($node));
    }

    public function testParentFailureIsHiddenAndNeverCachedAsVisible(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $node = $this->createMock(Node::class);
        $node->method('getId')->willReturn(123);
        $node->expects($this->exactly(2))->method('getParent')->willThrowException(new \RuntimeException());
        $mapper = $this->createMock(ISystemTagObjectMapper::class);
        $mapper->expects($this->never())->method('getTagIdsForObjects');
        $guard = new VisibilityGuard($this->config->mock($this), $mapper);
        $this->assertFalse($guard->isVisible($node));
        $this->assertFalse($guard->isVisible($node));
    }

    public function testCopyHiddenTagsAbortsOnAncestryFailure(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $source = $this->createMock(Node::class);
        $source->method('getId')->willReturn(123);
        $source->method('getParent')->willThrowException(new \RuntimeException());
        $destination = $this->createMock(Node::class);
        $mapper = $this->createMock(ISystemTagObjectMapper::class);
        $mapper->method('getTagIdsForObjects')->willReturn(['123' => ['42']]);
        $mapper->expects($this->never())->method('assignTags');
        $guard = new VisibilityGuard($this->config->mock($this), $mapper);
        $this->expectException(ToolFailure::class);
        $guard->copyHiddenTags($source, $destination);
    }

    public function testDirectlyTaggedFileIsHidden(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['42', '99']);
        $guard = $this->guard();
        $this->assertSame(['42', '99'], $guard->getHiddenTagIds());

        $fileId = $this->tree->addFile('/alice/files/secret.txt', 'secret content');
        $file = $this->tree->node('/alice/files/secret.txt');
        $this->tagAssignments[(string)$fileId] = ['42'];

        $this->assertFalse($guard->isVisible($file));

        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(CommonMessages::notFound());
        $guard->assertVisible($file);
    }

    public function testAncestralFolderTaggedHidesChildren(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['10']);
        $guard = $this->guard();

        $this->tree->addFolder('/alice/files/Confidential');
        $folder = $this->tree->node('/alice/files/Confidential');
        $folderId = $folder->getId();
        $this->tagAssignments[(string)$folderId] = ['10'];

        $fileId = $this->tree->addFile('/alice/files/Confidential/sub.txt', 'sub content');
        $file = $this->tree->node('/alice/files/Confidential/sub.txt');

        $this->assertFalse($guard->isVisible($folder));
        $this->assertFalse($guard->isVisible($file));

        $normalId = $this->tree->addFile('/alice/files/normal.txt', 'normal content');
        $normal = $this->tree->node('/alice/files/normal.txt');
        $this->assertTrue($guard->isVisible($normal));

        $filtered = $guard->filter([$file, $normal, $folder]);
        $this->assertSame([$normal], $filtered);
    }

    public function testBackupIsHiddenWhenOriginalExistsAndIsHidden(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['7']);
        $guard = $this->guard();

        $origId = $this->tree->addFile('/alice/files/docs/secret.txt', 'secret');
        $orig = $this->tree->node('/alice/files/docs/secret.txt');
        $this->tagAssignments[(string)$origId] = ['7'];

        // Backup mirror path
        $backupPath = '/alice/files/' . FileBackup::FOLDER . '/docs/secret.txt.20261001-120000.bak';
        $backupId = $this->tree->addFile($backupPath, 'secret');
        $backup = $this->tree->node($backupPath);

        $this->assertFalse($guard->isVisible($orig));
        $this->assertFalse($guard->isVisible($backup));
    }

    public function testBackupRemainsHiddenAfterCopyHiddenTagsEvenIfOriginalDeleted(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['7']);
        $guard = $this->guard();

        $origId = $this->tree->addFile('/alice/files/secret.txt', 'secret');
        $orig = $this->tree->node('/alice/files/secret.txt');
        $this->tagAssignments[(string)$origId] = ['7'];

        $backupPath = '/alice/files/' . FileBackup::FOLDER . '/secret.txt.20261001-120000.bak';
        $backupId = $this->tree->addFile($backupPath, 'secret');
        $backup = $this->tree->node($backupPath);

        // Copy hidden tags to backup
        $guard->copyHiddenTags($orig, $backup);
        $this->assertContains('7', $this->tagAssignments[(string)$backupId]);

        // Now simulate deleting the original
        $orig->delete();
        $guard->clearCache();

        $this->assertFalse($guard->isVisible($backup));
    }

    public function testIsPathAllowedForWriteRefusesHiddenTargetOrAncestor(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['5']);
        $guard = $this->guard();
        $root = $this->tree->rootFolder();

        $this->tree->addFolder('/alice/files/Private');
        $privateFolder = $this->tree->node('/alice/files/Private');
        $this->tagAssignments[(string)$privateFolder->getId()] = ['5'];

        $this->assertFalse($guard->isPathAllowedForWrite($root, '/Private'));
        $this->assertFalse($guard->isPathAllowedForWrite($root, '/Private/sub/newfile.txt'));
        $this->assertTrue($guard->isPathAllowedForWrite($root, '/Public/newfile.txt'));
    }

    public function testSetHiddenTagIdsRejectsNonPositiveInteger(): void {
        $guard = $this->guard();
        $this->expectException(\InvalidArgumentException::class);
        $guard->setHiddenTagIds(['not-an-int']);
    }

    public function testSetHiddenTagIdsRejectsZero(): void {
        $guard = $this->guard();
        $this->expectException(\InvalidArgumentException::class);
        $guard->setHiddenTagIds(['0']);
    }

    public function testSetHiddenTagIdsRejectsNegative(): void {
        $guard = $this->guard();
        $this->expectException(\InvalidArgumentException::class);
        $guard->setHiddenTagIds(['-4']);
    }

    public function testSetHiddenTagIdsFiltersOutNonExistentTagsWhenTagManagerPresent(): void {
        $tagManager = $this->createMock(\OCP\SystemTag\ISystemTagManager::class);
        $tag1 = $this->createMock(\OCP\SystemTag\ISystemTag::class);
        $tag1->method('getId')->willReturn('10');

        $tagManager->method('getTagsByIds')->willReturn(['10' => $tag1]);

        $guard = new VisibilityGuard(
            $this->config->mock($this),
            $this->tagMapper,
            null,
            $tagManager
        );

        $guard->setHiddenTagIds(['10', '99']);
        $this->assertSame(['10'], $guard->getHiddenTagIds());
    }

    /**
     * Regression (#5): walking up past the user folder reaches Nextcloud's Root, whose
     * getParent() throws by design; that must not be read as a lookup failure.
     */
    public function testOrdinaryFileStaysVisibleWhenWalkReachesFilesystemRoot(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['7']);
        $guard = $this->guard();

        $this->tree->addFile('/alice/files/docs/report.txt', 'report');
        $file = $this->tree->node('/alice/files/docs/report.txt');

        $this->assertTrue($guard->isVisible($file));
        $guard->assertVisible($file);
        $this->assertSame([$file], $guard->filter([$file]));
    }

    public function testBackupOfHiddenOriginalStaysHiddenWithFilesystemRoot(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['7']);
        $guard = $this->guard();

        $origId = $this->tree->addFile('/alice/files/docs/secret.txt', 'secret');
        $this->tagAssignments[(string)$origId] = ['7'];
        $backupPath = '/alice/files/' . FileBackup::FOLDER . '/docs/secret.txt.20261001-120000.bak';
        $this->tree->addFile($backupPath, 'secret');

        $this->assertFalse($guard->isVisible($this->tree->node($backupPath)));
    }

    public function testBackupOfVisibleOriginalIsVisibleWithFilesystemRoot(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = json_encode(['7']);
        $guard = $this->guard();

        $this->tree->addFile('/alice/files/docs/report.txt', 'report');
        $backupPath = '/alice/files/' . FileBackup::FOLDER . '/docs/report.txt.20261001-120000.bak';
        $this->tree->addFile($backupPath, 'report');

        $this->assertTrue($guard->isVisible($this->tree->node('/alice/files/docs/report.txt')));
        $this->assertTrue($guard->isVisible($this->tree->node($backupPath)));
    }

    /** A3: a tag manager that cannot answer refuses the save and keeps the previous protection. */
    public function testTagManagerFailureKeepsProtection(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $guard = new VisibilityGuard($this->config->mock($this), $this->tagMapper, null, $this->failingTagManager());

        $refused = false;
        try {
            $guard->setHiddenTagIds(['42', '7']);
        } catch (HiddenTagsUnavailableException) {
            $refused = true;
        }

        $this->assertTrue($refused, 'the save must be refused while the tags cannot be checked');

        $this->assertSame('["42"]', $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY]);
        $this->assertSame(['42'], $guard->getHiddenTagIds());
    }

    public function testExplicitRemovalOfAllTagsNeedsNoTagManager(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $guard = new VisibilityGuard($this->config->mock($this), $this->tagMapper, null, $this->failingTagManager());

        $guard->setHiddenTagIds([]);

        $this->assertSame('[]', $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY]);
        $this->assertSame([], $guard->getHiddenTagIds());
    }

    /** A9: a stored value that is not a list of tag ids refuses every node, and is left as it is. */
    public function testMalformedConfigurationRefusesAccess(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"';
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            'MCP hidden tags configuration is invalid; access refused', ['app' => 'mcp']
        );
        $guard = new VisibilityGuard($this->config->mock($this), $this->tagMapper, null, null, $logger);

        $secretId = $this->tree->addFile('/alice/files/secret.txt', 'secret');
        $this->tagAssignments[(string)$secretId] = ['42'];
        $this->tree->addFile('/alice/files/plain.txt', 'plain');

        $this->assertFalse($guard->isVisible($this->tree->node('/alice/files/secret.txt')));
        $this->assertFalse($guard->isVisible($this->tree->node('/alice/files/plain.txt')));
        $this->assertSame([], $guard->filter([$this->tree->node('/alice/files/plain.txt')]));
        $this->assertFalse($guard->isPathAllowedForWrite($this->tree->rootFolder(), '/new.txt'));
        $this->assertFalse($guard->isConfigurationValid());
        $this->assertSame('["42"', $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedConfigurations(): iterable {
        yield 'truncated json' => ['["42"'];
        yield 'not a list' => ['{"a":"42"}'];
        yield 'scalar' => ['42'];
        yield 'empty string' => [''];
        yield 'non numeric id' => ['["secret"]'];
        yield 'nested list' => ['[["42"]]'];
        yield 'zero id' => ['["0"]'];
    }

    /** @dataProvider malformedConfigurations */
    public function testEveryMalformedConfigurationIsInvalid(string $raw): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = $raw;
        $guard = $this->guard();
        $this->tree->addFile('/alice/files/plain.txt', 'plain');

        $this->assertFalse($guard->isConfigurationValid());
        $this->assertFalse($guard->isVisible($this->tree->node('/alice/files/plain.txt')));
    }

    public function testMalformedConfigurationRefusesBackupTagCopy(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"';
        $guard = $this->guard();
        $this->tree->addFile('/alice/files/a.txt', 'a');
        $this->tree->addFile('/alice/files/b.txt', 'b');

        $this->expectException(ToolFailure::class);
        $guard->copyHiddenTags($this->tree->node('/alice/files/a.txt'), $this->tree->node('/alice/files/b.txt'));
    }

    public function testValidEmptyConfigurationMeansNoProtection(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '[]';
        $guard = $this->guard();
        $this->tree->addFile('/alice/files/plain.txt', 'plain');

        $this->assertTrue($guard->isConfigurationValid());
        $this->assertTrue($guard->isVisible($this->tree->node('/alice/files/plain.txt')));
    }

    public function testSavingAgainRepairsMalformedConfiguration(): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"';
        $guard = $this->guard();
        $this->tree->addFile('/alice/files/plain.txt', 'plain');
        $this->assertFalse($guard->isVisible($this->tree->node('/alice/files/plain.txt')));

        $guard->setHiddenTagIds(['42']);

        $this->assertTrue($guard->isConfigurationValid());
        $this->assertSame('["42"]', $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY]);
        $this->assertTrue($guard->isVisible($this->tree->node('/alice/files/plain.txt')));
    }

    private function failingTagManager(): \OCP\SystemTag\ISystemTagManager {
        $manager = $this->createMock(\OCP\SystemTag\ISystemTagManager::class);
        $manager->method('getTagsByIds')->willThrowException(new \RuntimeException('down'));
        $manager->method('getAllTags')->willThrowException(new \RuntimeException('down'));
        return $manager;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function objectConfigurations(): iterable {
        yield 'empty object' => ['{}'];
        yield 'object with list-like keys' => ['{"0":"42"}'];
    }

    /**
     * A JSON object is not the list the admin page saves, even when decoding it as an array would look like one:
     * every path of the guard refuses, the log names no value and the value stays as it was.
     *
     * @dataProvider objectConfigurations
     */
    public function testJsonObjectConfigurationIsInvalidOnEveryPath(string $raw): void {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = $raw;
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            'MCP hidden tags configuration is invalid; access refused', ['app' => 'mcp']
        );
        $guard = new VisibilityGuard($this->config->mock($this), $this->tagMapper, null, null, $logger);
        $this->tree->addFile('/alice/files/plain.txt', 'plain');
        $this->tree->addFile('/alice/files/copy.txt', 'copy');
        $plain = $this->tree->node('/alice/files/plain.txt');

        $this->assertFalse($guard->isVisible($plain));
        $this->assertSame([], $guard->filter([$plain]));
        $this->assertFalse($guard->isPathAllowedForWrite($this->tree->rootFolder(), '/new.txt'));
        try {
            $guard->assertVisible($plain);
            $this->fail('assertVisible must refuse');
        } catch (ToolFailure $e) {
            $this->assertSame(CommonMessages::notFound(), $e->getMessage());
        }
        try {
            $guard->copyHiddenTags($plain, $this->tree->node('/alice/files/copy.txt'));
            $this->fail('copyHiddenTags must refuse');
        } catch (ToolFailure $e) {
            $this->assertStringNotContainsString('42', $e->getMessage());
        }
        $this->assertFalse($guard->isConfigurationValid());
        $this->assertSame($raw, $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY]);
    }
}
