<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Versioned storage for the group permissions: one row holding every group's rules. No data is moved: existing
 * per-user values stay where they are and become individual overrides, so nobody's access changes on upgrade.
 */
class Version001100Date20261015000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        if ($schema->hasTable('mcp_group_grants')) {
            return null;
        }
        $table = $schema->createTable('mcp_group_grants');
        $table->addColumn('id', Types::INTEGER, ['notnull' => true]);
        $table->addColumn('rules', Types::TEXT, ['notnull' => true]);
        $table->addColumn('version', Types::BIGINT, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
        $table->setPrimaryKey(['id']);
        return $schema;
    }
}
