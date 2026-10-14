<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Service;

use OCA\Mcp\Controller\GroupGrantsController;
use OCA\Mcp\Service\EffectiveAccess;
use OCA\Mcp\Service\GrantMatrix;
use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\GroupChangeListener;
use OCA\Mcp\Service\GroupGrantImpact;
use OCA\Mcp\Service\GroupGrants;
use OCA\Mcp\Service\LogsAccess;
use OCP\App\IAppManager;
use OCP\Group\Events\BeforeGroupDeletedEvent;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/** The admin API of group permissions, its preview, the effective-access view and the group event listener. */
final class GroupGrantsControllerTest extends TestCase {
    private GroupFixture $fx;

    protected function setUp(): void {
        $this->fx = new GroupFixture($this);
        $this->fx->members = ['staff' => ['alice', 'bob', 'carol'], 'interns' => ['bob'], 'ops' => ['dave']];
    }

    private function controller(array $params = [], string $sessionUid = 'alice'): GroupGrantsController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $params[$key] ?? $default);
        $session = $this->createMock(IUserSession::class);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($sessionUid);
        $session->method('getUser')->willReturn($user);
        return new GroupGrantsController('mcp', $request, $this->fx->groups, new GroupGrantImpact($this->fx->policy, $this->fx->groups),
            $this->effective(), $this->fx->groupManager, $session, $this->fx->oauth);
    }

    private function effective(): EffectiveAccess {
        $apps = $this->createMock(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturnCallback(static fn (string $app): bool => $app !== 'deck');
        $apps->method('isEnabledForAnyone')->willReturn(true);
        $apps->method('isInstalled')->willReturn(true);
        $logs = $this->createMock(LogsAccess::class);
        $logs->method('permits')->willReturn(false);
        $matrix = new GrantMatrix($this->fx->policy, $this->fx->userManager, $this->fx->groupManager, $apps, $logs);
        return new EffectiveAccess($matrix, $this->fx->policy, $this->fx->groups, $this->fx->userManager, $this->fx->groupManager);
    }

    public function testIndexListsGroupsWithMemberCountsAndConfiguredRules(): void {
        $this->fx->rule('interns', ['files.edit' => false]);
        $data = $this->controller()->index()->getData();
        $this->assertEqualsCanonicalizing(['interns', 'ops', 'staff'], array_column($data['groups'], 'id'));
        $staff = array_values(array_filter($data['groups'], static fn ($g) => $g['id'] === 'staff'))[0];
        $this->assertSame(3, $staff['members']);
        $this->assertSame([['id' => 'interns', 'displayName' => 'Interns', 'members' => 1, 'missing' => false, 'rules' => ['files.edit' => 'deny']]], $data['configured']);
        $this->assertSame(1, $data['version']);
        $this->assertSame(GroupGrants::permissions(), $data['permissions']);
        $this->assertSame(['interns'], array_column($this->controller()->index('intern')->getData()['groups'], 'id'));
        $this->assertSame(400, $this->controller()->index(str_repeat('x', 101))->getStatus());
    }

    public function testPreviewCountsGainsLossesOverridesAndOtherGroupsWithoutWriting(): void {
        $this->fx->rule('interns', ['files.edit' => false]);
        $this->fx->individual('carol', 'grant_files_edit', false);
        $data = $this->controller(['group' => 'staff', 'rules' => ['files.edit' => 'allow', 'eligible' => 'allow']])->preview()->getData();
        $this->assertSame(3, $data['members']);
        $this->assertFalse($data['truncated']);
        $byPermission = array_column($data['changes'], null, 'permission');
        // alice gains; bob stays denied by interns; carol keeps her own deny.
        $this->assertSame(['permission' => 'files.edit', 'from' => null, 'to' => true, 'gain' => 1, 'lose' => 0, 'overridden' => 1, 'unchanged' => 1], $byPermission['files.edit']);
        $this->assertSame(3, $byPermission['eligible']['gain']);
        $this->assertArrayNotHasKey('losingEligibility', $data);
        $this->assertSame(['rules' => ['interns' => ['files.edit' => false]], 'version' => 1], $this->fx->mapper->state(), 'a preview writes nothing');
    }

    public function testSaveStoresRulesSignsOutWhoLosesEligibilityAndRefusesAStaleVersion(): void {
        $this->fx->rule('staff', ['eligible' => true]);
        $this->fx->individual('carol', 'eligible', true);
        foreach (['alice', 'bob', 'carol', 'dave'] as $uid) {
            $this->fx->oauth->insertToken(['user_id' => $uid], 0);
        }
        $response = $this->controller(['group' => 'staff', 'rules' => ['files.read' => 'deny'], 'version' => 1])->update();
        $this->assertSame(200, $response->getStatus());
        $this->assertSame(['staff' => ['files.read' => false]], $this->fx->mapper->state()['rules']);
        // alice and bob depended on staff to connect; carol has her own allow; dave is not a member.
        $this->assertSame(['carol', 'dave'], array_values(array_column($this->fx->oauth->tokens, 'user_id')));
        $this->assertFalse($this->fx->policy->granted('alice', 'files', 'read'));

        $stale = $this->controller(['group' => 'staff', 'rules' => [], 'version' => 1])->update();
        $this->assertSame(409, $stale->getStatus());
        $this->assertSame('Conflict', $stale->getData()['error']);
        $this->assertSame(2, $stale->getData()['state']['version']);
        $this->assertSame(['staff' => ['files.read' => false]], $this->fx->mapper->state()['rules']);
    }

    public function testInvalidBodiesAreRefusedWithoutWriting(): void {
        foreach ([
            ['rules' => [], 'version' => 0],
            ['group' => 'nope', 'rules' => [], 'version' => 0],
            ['group' => 'staff', 'rules' => 'eligible', 'version' => 0],
            ['group' => 'staff', 'rules' => ['eligible' => true], 'version' => 0],
            ['group' => 'staff', 'rules' => ['eligible' => 'inherit'], 'version' => 0],
            ['group' => 'staff', 'rules' => ['files.delete' => 'allow'], 'version' => 0],
            ['group' => 'staff', 'rules' => ['eligible' => 'allow'], 'version' => '0'],
            ['group' => 'staff', 'rules' => ['eligible' => 'allow'], 'version' => -1],
            ['group' => 'staff', 'rules' => ['eligible' => 'allow']],
        ] as $body) {
            $this->assertSame(400, $this->controller($body)->update()->getStatus(), json_encode($body));
        }
        $this->assertSame(400, $this->controller(['group' => 'staff', 'rules' => ['x' => 'allow']])->preview()->getStatus());
        $this->assertSame(['rules' => [], 'version' => 0], $this->fx->mapper->state());
    }

    public function testRulesLeftByAGroupThatVanishedCanOnlyBeCleared(): void {
        $this->fx->rule('ops', ['eligible' => true]);
        unset($this->fx->members['ops']); // removed by its backend without an event
        $listed = $this->controller()->index()->getData()['configured'];
        $this->assertSame([['id' => 'ops', 'displayName' => 'ops', 'members' => null, 'missing' => true, 'rules' => ['eligible' => 'allow']]], $listed);
        $this->assertSame(400, $this->controller(['group' => 'ops', 'rules' => ['eligible' => 'deny'], 'version' => 1])->update()->getStatus());
        $this->assertSame(400, $this->controller(['group' => 'never', 'rules' => [], 'version' => 1])->update()->getStatus());
        $this->assertSame(200, $this->controller(['group' => 'ops', 'rules' => [], 'version' => 1])->update()->getStatus());
        $this->assertSame([], $this->fx->mapper->state()['rules']);
    }

    public function testEffectiveAccessExplainsEachPermissionAndWhatBlocks(): void {
        $this->fx->rule('staff', ['eligible' => true, 'files.edit' => true]);
        $this->fx->rule('interns', ['files.edit' => false, 'notes.read' => false]);
        $this->fx->individual('bob', 'grant_files_create', true);
        $this->fx->config->app['mcp'][GrantPolicy::SERVICE_KEY] = '1';
        $this->fx->config->user['bob']['mcp'][GrantPolicy::CONNECTED_KEY] = '1';
        $data = $this->controller()->effective('bob')->getData();
        $this->assertSame([['id' => 'interns', 'displayName' => 'Interns'], ['id' => 'staff', 'displayName' => 'Staff']], $data['groups']);
        $this->assertTrue($data['canConnect']);
        $this->assertSame([], $data['blockers']);
        $this->assertSame(['result' => true, 'source' => 'group', 'override' => null, 'allow' => ['staff'], 'deny' => []], $data['eligible']);
        $permissions = [];
        foreach ($data['permissions'] as $row) {
            $permissions[$row['module'] . '.' . $row['operation']] = $row;
        }
        $this->assertSame(['result' => false, 'source' => 'group', 'allow' => ['staff'], 'deny' => ['interns']],
            array_intersect_key($permissions['files.edit'], array_flip(['result', 'source', 'allow', 'deny'])));
        $this->assertSame(['result' => true, 'effective' => true, 'source' => 'override', 'override' => true],
            array_intersect_key($permissions['files.create'], array_flip(['result', 'effective', 'source', 'override'])));
        $this->assertFalse($permissions['notes.read']['result']);
        $this->assertSame('default', $permissions['calendar.read']['source']);
        $this->assertTrue($permissions['deck.read']['result']);
        $this->assertFalse($permissions['deck.read']['available'], 'Deck is not enabled for bob');
        $this->assertFalse($permissions['deck.read']['effective']);
        $this->assertSame(0, $data['connections']);
        $live = ['client_id' => 'https://claude.ai/x', 'scope' => 'mcp', 'created_at' => 1, 'access_expires' => 1, 'refresh_expires' => PHP_INT_MAX];
        $this->fx->oauth->insertToken(['user_id' => 'bob'] + $live, 0);
        $this->fx->oauth->insertToken(['user_id' => 'bob', 'refresh_expires' => 1] + $live, 0);
        $this->assertSame(1, $this->controller()->effective('bob')->getData()['connections'], 'live connections only');

        $carol = $this->controller()->effective('dave')->getData();
        $this->assertSame(['not_eligible', 'not_connected'], $carol['blockers']);
        $this->assertFalse($carol['canConnect']);
        $this->assertSame(400, $this->controller()->effective('ghost')->getStatus());
    }

    public function testMyAccessShowsOnlyTheSignedInUser(): void {
        $this->assertSame('carol', $this->controller([], 'carol')->myAccess()->getData()['uid']);
    }

    public function testGroupEventsSignOutWhoCanNoLongerConnectAndForgetDeletedGroups(): void {
        $this->fx->rule('staff', ['eligible' => true]);
        $this->fx->rule('interns', ['eligible' => false]);
        foreach (['alice', 'carol', 'dave'] as $uid) {
            $this->fx->oauth->insertToken(['user_id' => $uid], 0);
        }
        $listener = new GroupChangeListener($this->fx->policy, $this->fx->groups, $this->fx->oauth);
        $group = fn (string $gid) => $this->fx->groupManager->get($gid);
        $user = fn (string $uid) => $this->fx->userManager->get($uid);

        // carol joins the denying group: signed out at once.
        $this->fx->members['interns'][] = 'carol';
        $listener->handle(new UserAddedEvent($group('interns'), $user('carol')));
        $this->assertSame(['alice', 'dave'], array_values(array_column($this->fx->oauth->tokens, 'user_id')));

        // dave leaves ops; no rule ever let him connect, so his stray token goes too, alice keeps hers.
        $this->fx->members['ops'] = [];
        $listener->handle(new UserRemovedEvent($group('ops'), $user('dave')));
        $this->assertSame(['alice'], array_values(array_column($this->fx->oauth->tokens, 'user_id')));

        // staff is deleted: its rule goes and alice, who connected through it, is signed out.
        $staff = $group('staff');
        $listener->handle(new BeforeGroupDeletedEvent($staff));
        unset($this->fx->members['staff']);
        $listener->handle(new GroupDeletedEvent($staff));
        $this->assertSame(['interns' => ['eligible' => false]], $this->fx->mapper->state()['rules']);
        $this->assertNotContains('alice', array_column($this->fx->oauth->tokens, 'user_id'));
    }
}
