<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Db;

use OCA\Mcp\Service\GroupGrantsConflict;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Entity> The group permissions: a single row with every group's rules as JSON
 * ({gid: {permission: true|false}}) and a version that each write must name, so a stale save never wins.
 * Always read from the database, without caches.
 */
class GroupGrantsMapper extends QBMapper {
    public const TABLE = 'mcp_group_grants';

    public function __construct(IDBConnection $db) {
        parent::__construct($db, self::TABLE, Entity::class);
    }

    /** @return array{rules: array<string, array<string, bool>>, version: int} the stored rules, canonical */
    public function state(): array {
        $row = $this->read();
        if ($row === false) {
            $qb = $this->db->getQueryBuilder();
            try {
                $qb->insert(self::TABLE)
                    ->setValue('id', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
                    ->setValue('rules', $qb->createNamedParameter('{}'))
                    ->setValue('version', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
                    ->executeStatement();
            } catch (Exception $e) {
                // Another worker created the row first; read theirs.
                if ($e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                    throw $e;
                }
            }
            $row = $this->read();
            if ($row === false) {
                return ['rules' => [], 'version' => 0];
            }
        }
        return ['rules' => self::decode((string)$row['rules']), 'version' => (int)$row['version']];
    }

    /**
     * Replaces every rule when the stored version is still $version; the database decides, not a compared list.
     *
     * @param array<string, array<string, bool>> $rules every group's rules
     * @param int $version the version the caller read
     * @throws GroupGrantsConflict when another write came first
     */
    public function compareAndSet(array $rules, int $version): void {
        $this->state();
        $qb = $this->db->getQueryBuilder();
        $changed = $qb->update(self::TABLE)
            ->set('rules', $qb->createNamedParameter(self::encode($rules)))
            ->set('version', $qb->createFunction('version + 1'))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('version', $qb->createNamedParameter($version, IQueryBuilder::PARAM_INT)))
            ->executeStatement();
        if ($changed !== 1) {
            throw new GroupGrantsConflict('The group permissions changed since they were read');
        }
    }

    /**
     * @param array<string, array<string, bool>> $rules rules to normalise
     * @return array<string, array<string, bool>> groups and permissions sorted, groups without a rule dropped
     */
    public static function canonical(array $rules): array {
        $out = [];
        foreach ($rules as $gid => $permissions) {
            if (!is_string($gid) && !is_int($gid) || (string)$gid === '' || !is_array($permissions)) {
                continue;
            }
            $clean = array_filter($permissions, static fn ($value, $key): bool => is_string($key) && $key !== '' && is_bool($value), ARRAY_FILTER_USE_BOTH);
            if ($clean !== []) {
                ksort($clean, SORT_STRING);
                $out[(string)$gid] = $clean;
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @return array<string,mixed>|false */
    private function read(): array|false {
        $qb = $this->db->getQueryBuilder();
        $result = $qb->select('rules', 'version')->from(self::TABLE)
            ->where($qb->expr()->eq('id', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->executeQuery();
        try {
            return $result->fetch();
        } finally {
            $result->closeCursor();
        }
    }

    /** @return array<string, array<string, bool>> the stored rules; a garbled value reads as none */
    private static function decode(string $json): array {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? self::canonical($decoded) : [];
    }

    /** @param array<string, array<string, bool>> $rules */
    private static function encode(array $rules): string {
        $canonical = self::canonical($rules);
        return json_encode($canonical === [] ? new \stdClass() : $canonical, JSON_THROW_ON_ERROR);
    }
}
