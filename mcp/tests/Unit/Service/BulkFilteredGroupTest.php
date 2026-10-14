<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Service;

use OCA\Mcp\Controller\GrantsController;
use OCA\Mcp\Service\GrantMatrix;
use OCA\Mcp\Service\LogsAccess;
use OCP\App\IAppManager;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The "every filtered user" change judged with group rules: inherit hands users back to their groups, and the dry run
 * counts who actually gains or loses access once the groups decide.
 */
final class BulkFilteredGroupTest extends TestCase {
    private GroupFixture $fx;

    protected function setUp(): void {
        $this->fx = new GroupFixture($this);
        $this->fx->members = ['staff' => ['ana', 'bob', 'cid'], 'interns' => ['cid']];
        $this->fx->rule('staff', ['eligible' => true, 'files.edit' => true]);
        $this->fx->rule('interns', ['files.edit' => false]);
    }

    /** @param array<string, mixed> $body */
    private function filtered(array $body): array {
        $request = $this->createMock(IRequest::class);
        $body += ['search' => '', 'group' => '', 'filter' => 'eligible'];
        $request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => array_key_exists($key, $body) ? $body[$key] : $default);
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturn(true);
        $apps->method('isEnabledForAnyone')->willReturn(true);
        $logs = $this->createMock(LogsAccess::class);
        $matrix = new GrantMatrix($this->fx->policy, $this->fx->userManager, $this->fx->groupManager, $apps, $logs);
        $controller = new GrantsController('mcp', $request, $matrix, $this->fx->policy, $this->fx->userManager, $this->fx->config->mock($this), $this->fx->oauth, $logs);
        $response = $controller->bulkFiltered();
        return [$response->getStatus(), $response->getData()];
    }

    public function testInheritHandsUsersBackToTheirGroupsAndCountsTheRealOutcome(): void {
        // ana denies herself, bob allows himself, cid inherits: staff allows, interns deny, and a deny wins.
        $this->fx->individual('ana', 'grant_files_edit', false);
        $this->fx->individual('bob', 'grant_files_edit', true);
        [$status, $dry] = $this->filtered(['module' => 'files', 'operations' => ['edit'], 'state' => 'inherit', 'dryRun' => true]);
        $this->assertSame(200, $status);
        // ana gains through staff; bob keeps it through staff; cid has nothing to remove.
        $this->assertSame(['matched' => 3, 'change' => 2, 'unchanged' => 1, 'gain' => 1, 'lose' => 0],
            array_intersect_key($dry, array_flip(['matched', 'change', 'unchanged', 'gain', 'lose'])));

        [, $done] = $this->filtered(['module' => 'files', 'operations' => ['edit'], 'state' => 'inherit', 'expected' => 2]);
        $this->assertEqualsCanonicalizing([
            ['uid' => 'ana', 'operation' => 'edit', 'state' => 'deny'],
            ['uid' => 'bob', 'operation' => 'edit', 'state' => 'allow'],
        ], $done['previous']);
        $this->assertSame('group', $this->fx->policy->explain('ana', 'files.edit')['source']);
        $this->assertTrue($this->fx->policy->explain('ana', 'files.edit')['result']);
        $this->assertFalse($this->fx->policy->explain('cid', 'files.edit')['result'], 'the interns deny still wins');
    }

    public function testDenyingCanConnectToGroupMembersCountsThemAsLosingAndSignsThemOut(): void {
        foreach (['ana', 'bob', 'cid'] as $uid) {
            $this->fx->oauth->insertToken(['user_id' => $uid], 0);
        }
        [, $dry] = $this->filtered(['module' => null, 'operations' => ['eligible'], 'state' => 'deny', 'dryRun' => true]);
        $this->assertSame([3, 0, 3], [$dry['change'], $dry['gain'], $dry['lose']]);
        [$status, $done] = $this->filtered(['module' => null, 'operations' => ['eligible'], 'state' => 'deny', 'expected' => 3]);
        $this->assertSame(200, $status);
        $this->assertSame(['inherit'], array_values(array_unique(array_column($done['previous'], 'state'))));
        $this->assertSame([], $this->fx->oauth->tokens, 'users who can no longer connect are signed out');
        $this->assertFalse($this->fx->policy->eligible('ana'), 'an individual deny wins over the staff allow');
    }
}
