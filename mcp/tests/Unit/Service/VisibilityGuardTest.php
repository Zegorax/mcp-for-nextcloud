<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Service;

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

    /** Same file id, physical chain 30→20→10, recipient chain 30→20→90. */
    public function testSharedFileKeepsHiddenOwnerAncestor(): void {
        [$guard, $alias] = $this->sharedFixture();
        $this->tagAssignments['10'] = ['42'];
        $this->assertFalse($guard->isVisible($alias));
        $this->expectException(ToolFailure::class);
        $this->expectExceptionMessage(CommonMessages::notFound());
        $guard->assertVisible($alias);
    }

    /** A subfolder mount exposes 20 and its child 30, but omits hidden grandparent 10. */
    public function testSharedSubfolderChildKeepsHiddenOwnerGrandparent(): void {
        [$guard, $alias, $subfolder] = $this->sharedFixture();
        $this->tagAssignments['10'] = ['42'];
        $this->assertFalse($guard->isVisible($subfolder));
        $this->assertFalse($guard->isVisible($alias));
    }

    public function testSharedVisibleFileRemainsVisibleAndOwnerLookupIsCached(): void {
        [$guard, $alias] = $this->sharedFixture();
        $this->assertTrue($guard->isVisible($alias));
        $this->assertTrue($guard->isVisible($alias));
    }

    public function testSharedFileOwnerResolutionFailureIsHiddenWithoutDetails(): void {
        [$guard, $alias] = $this->sharedFixture(true);
        $this->assertFalse($guard->isVisible($alias));
        try {
            $guard->assertVisible($alias);
            $this->fail('Owner resolution must refuse access');
        } catch (ToolFailure $e) {
            $this->assertSame(CommonMessages::notFound(), $e->getMessage());
        }
    }

    /** @return array{VisibilityGuard, Node, Node} */
    private function sharedFixture(bool $failure = false): array {
        $this->config->app['mcp'][VisibilityGuard::CONFIG_KEY] = '["42"]';
        $owner = $this->createMock(\OCP\IUser::class);
        $owner->method('getUID')->willReturn('alice');
        $root = $this->createMock(\OCP\Files\IRootFolder::class);
        $root->method('getId')->willReturn(-1);
        $root->expects($this->never())->method('getParent');
        $ownerFolder = $this->ancestryNode(1, $root, $owner);
        $secret = $this->ancestryNode(10, $ownerFolder, $owner);
        $canonicalSub = $this->ancestryNode(20, $secret, $owner);
        $canonicalFile = $this->ancestryNode(30, $canonicalSub, $owner);
        $recipient = $this->ancestryNode(90, $root, $owner);
        $aliasSub = $this->ancestryNode(20, $recipient, $owner);
        $alias = $this->ancestryNode(30, $aliasSub, $owner);
        $root->method('getUserFolder')->with('alice')->willReturn($ownerFolder);
        if ($failure) {
            $ownerFolder->method('getById')->willThrowException(new \RuntimeException('/secret/sub/file'));
        } else {
            $ownerFolder->expects($this->atMost(2))->method('getById')->willReturnCallback(
                static fn (int $id): array => match ($id) {
                    30 => [$canonicalFile],
                    20 => [$canonicalSub],
                    default => [],
                }
            );
        }
        return [new VisibilityGuard($this->config->mock($this), $this->tagMapper, $root), $alias, $aliasSub];
    }

    private function ancestryNode(int $id, Node $parent, \OCP\IUser $owner): Folder {
        $node = $this->createMock(Folder::class);
        $node->method('getId')->willReturn($id);
        $node->method('getOwner')->willReturn($owner);
        $node->method('getParent')->willReturn($parent);
        return $node;
    }

}
