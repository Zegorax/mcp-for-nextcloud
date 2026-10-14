<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Service;

use OCA\Mcp\Db\GroupGrantsMapper;
use OCA\Mcp\Migration\Version001100Date20261015000000;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\GroupGrants;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use OCA\Mcp\Tests\Unit\OAuth\InMemoryOAuthStore;
use OCA\Mcp\Tests\Unit\SqliteDatabase;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * A GrantPolicy with group rules on a real SQLite table and group membership held in $members, which a test may
 * change between "requests" (call forget() on the GroupGrants, as a new request would start empty).
 */
final class GroupFixture {
    public InMemoryConfig $config;
    public InMemoryOAuthStore $oauth;
    public SqliteDatabase $db;
    public GroupGrantsMapper $mapper;
    public GroupGrants $groups;
    public GrantPolicy $policy;
    /** @var array<string, list<string>> uids by group id */
    public array $members = [];
    /** Number of getUserGroupIds calls, to check a request reads each user's groups once. */
    public int $groupLookups = 0;

    public function __construct(TestCase $test) {
        $this->config = new InMemoryConfig();
        $this->oauth = new InMemoryOAuthStore();
        $this->db = new SqliteDatabase($test, [new Version001100Date20261015000000()]);
        $this->mapper = new GroupGrantsMapper($this->db->connection());
        $mock = static fn (string $class) => (new \ReflectionMethod($test, 'createMock'))->invoke($test, $class);
        $users = $mock(IUserManager::class);
        $users->method('get')->willReturnCallback(function (string $uid) use ($mock): ?IUser {
            if (str_starts_with($uid, 'ghost')) {
                return null;
            }
            $user = $mock(IUser::class);
            $user->method('getUID')->willReturn($uid);
            $user->method('getDisplayName')->willReturn(ucfirst($uid));
            $user->method('isEnabled')->willReturn(true);
            return $user;
        });
        $groupManager = $mock(IGroupManager::class);
        $groupManager->method('getUserGroupIds')->willReturnCallback(function (IUser $user): array {
            $this->groupLookups++;
            return array_values(array_keys(array_filter($this->members, static fn (array $uids): bool => in_array($user->getUID(), $uids, true))));
        });
        $groupManager->method('get')->willReturnCallback(function (string $gid) use ($mock, $users): ?IGroup {
            if (!array_key_exists($gid, $this->members)) {
                return null;
            }
            $group = $mock(IGroup::class);
            $group->method('getGID')->willReturn($gid);
            $group->method('getDisplayName')->willReturn(ucfirst($gid));
            $group->method('count')->willReturnCallback(fn (): int => count($this->members[$gid] ?? []));
            $group->method('searchUsers')->willReturnCallback(fn (): array => array_map(static fn (string $uid) => $users->get($uid), $this->members[$gid] ?? []));
            return $group;
        });
        $groupManager->method('getDisplayName')->willReturnCallback(fn (string $gid): ?string => array_key_exists($gid, $this->members) ? ucfirst($gid) : null);
        $groupManager->method('search')->willReturnCallback(fn (string $search): array => array_values(array_filter(array_map(
            fn (string $gid) => $groupManager->get($gid), array_keys($this->members)),
            static fn (IGroup $group): bool => $search === '' || stripos($group->getGID(), $search) !== false)));
        $this->groupManager = $groupManager;
        $this->userManager = $users;
        $this->groups = new GroupGrants($this->mapper, $groupManager, $users);
        $this->policy = InMemoryConfig::policy($this->config->mock($test), $this->oauth, $this->groups);
    }

    public IGroupManager $groupManager;
    public IUserManager $userManager;

    /**
     * Saves one group's rules on the current version.
     *
     * @param array<string, bool> $rules permission => allowed
     */
    public function rule(string $gid, array $rules): void {
        $this->groups->save($gid, $rules, $this->mapper->state()['version']);
    }

    /** Stores an individual value the way the matrix does ('1' allow, '0' deny). */
    public function individual(string $uid, string $key, bool $allowed): void {
        $this->config->user[$uid]['mcp'][$key] = $allowed ? '1' : '0';
    }
}
