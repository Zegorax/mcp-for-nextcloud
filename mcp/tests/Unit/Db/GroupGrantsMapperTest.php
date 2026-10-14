<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tests\Unit\Db;

use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Migration\Version001100Date20261015000000;
use OCA\Mcp\Service\GroupGrantsConflict;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use PHPUnit\Framework\TestCase;

final class GroupGrantsMapperTest extends TestCase {
    public function testFirstReadCreatesOneEmptyRowAndWritesAreCanonical(): void {
        $db = new SqliteDatabase($this, [new Version001100Date20261015000000()]);
        $mapper = new GroupGrantsMapper($db->connection());
        $this->assertSame(['rules' => [], 'version' => 0], $mapper->state());
        $this->assertSame(['rules' => [], 'version' => 0], (new GroupGrantsMapper($db->connection()))->state());
        $this->assertCount(1, $db->rows('mcp_group_grants'));
        $mapper->compareAndSet(['staff' => ['files.edit' => true, 'eligible' => false], 'empty' => [], '0' => ['notes.read' => false]], 0);
        $this->assertSame(['rules' => ['0' => ['notes.read' => false], 'staff' => ['eligible' => false, 'files.edit' => true]], 'version' => 1], $mapper->state());
        $this->assertSame('{"0":{"notes.read":false},"staff":{"eligible":false,"files.edit":true}}', $db->rows('mcp_group_grants')[0]['rules']);
    }

    public function testAWriteOnAnOldVersionIsRefusedEvenWithTheSameRules(): void {
        $db = new SqliteDatabase($this, [new Version001100Date20261015000000()]);
        $a = new GroupGrantsMapper($db->connection());
        $b = new GroupGrantsMapper($db->connection());
        $snapshot = $b->state();
        $a->compareAndSet(['staff' => ['eligible' => true]], $snapshot['version']);
        try {
            $b->compareAndSet([], $snapshot['version']);
            $this->fail('B must not undo A');
        } catch (GroupGrantsConflict) {
        }
        $a->compareAndSet([], 1);
        $this->expectException(GroupGrantsConflict::class);
        $b->compareAndSet([], 0); // ABA: same rules as at version 0, older version
    }

    public function testAGarbledValueReadsAsNoRules(): void {
        $db = new SqliteDatabase($this, [new Version001100Date20261015000000()]);
        $db->dbal->executeStatement("INSERT INTO mcp_group_grants (id, rules, version) VALUES (1, 'not json', 7)");
        $this->assertSame(['rules' => [], 'version' => 7], (new GroupGrantsMapper($db->connection()))->state());
    }
}
