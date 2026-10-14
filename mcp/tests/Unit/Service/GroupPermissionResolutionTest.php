<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Service;

use OCA\Mcp\Service\GrantPolicy;
use OCA\Mcp\Service\GroupGrants;
use OCA\Mcp\Tests\Unit\InMemoryConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The documented order: an individual deny, an individual allow, the user's groups (a deny in any group wins over an
 * allow), then the default. Each case below is one row of that table, checked for eligibility, a write and a read.
 */
final class GroupPermissionResolutionTest extends TestCase {
    private GroupFixture $fx;

    protected function setUp(): void {
        $this->fx = new GroupFixture($this);
        $this->fx->members = ['staff' => ['alice', 'bob'], 'interns' => ['bob'], 'ops' => ['carol']];
    }

    /** @return array<string, array{0: bool|null, 1: list<array{0: string, 1: bool}>, 2: bool, 3: string}> */
    public static function order(): array {
        // individual value, group rules for alice's groups, expected result, expected source
        return [
            'nothing: default denies the write' => [null, [], false, GrantPolicy::SOURCE_DEFAULT],
            'one group allows' => [null, [['staff', true]], true, GrantPolicy::SOURCE_GROUP],
            'one group denies' => [null, [['staff', false]], false, GrantPolicy::SOURCE_GROUP],
            'allow and deny in two groups: deny wins' => [null, [['staff', true], ['admins2', false]], false, GrantPolicy::SOURCE_GROUP],
            'individual allow beats a group deny' => [true, [['staff', false]], true, GrantPolicy::SOURCE_OVERRIDE],
            'individual deny beats a group allow' => [false, [['staff', true]], false, GrantPolicy::SOURCE_OVERRIDE],
            'individual allow with no group' => [true, [], true, GrantPolicy::SOURCE_OVERRIDE],
            'a rule of a group alice is not in has no effect' => [null, [['ops', true]], false, GrantPolicy::SOURCE_DEFAULT],
        ];
    }

    /**
     * @param list<array{0: string, 1: bool}> $rules
     */
    #[DataProvider('order')]
    public function testTheOrderAppliesToAWrite(?bool $individual, array $rules, bool $expected, string $source): void {
        $this->fx->members['admins2'] = ['alice'];
        foreach ($rules as [$gid, $allowed]) {
            $this->fx->rule($gid, ['files.edit' => $allowed]);
        }
        if ($individual !== null) {
            $this->fx->individual('alice', 'grant_files_edit', $individual);
        }
        $this->assertSame($expected, $this->fx->policy->granted('alice', 'files', 'edit'));
        $this->assertSame($source, $this->fx->policy->explain('alice', 'files.edit')['source']);
    }

    /**
     * @param list<array{0: string, 1: bool}> $rules
     */
    #[DataProvider('order')]
    public function testTheOrderAppliesToEligibility(?bool $individual, array $rules, bool $expected, string $source): void {
        $this->fx->members['admins2'] = ['alice'];
        foreach ($rules as [$gid, $allowed]) {
            $this->fx->rule($gid, [GroupGrants::ELIGIBLE => $allowed]);
        }
        if ($individual !== null) {
            $this->fx->individual('alice', GrantPolicy::ELIGIBLE_KEY, $individual);
        }
        $this->assertSame($expected, $this->fx->policy->eligible('alice'));
        $this->assertSame($source, $this->fx->policy->explain('alice', GroupGrants::ELIGIBLE)['source']);
    }

    public function testAGroupCanRestrictAReadThatStartsAllowedAndOpenTheLogThatStartsDenied(): void {
        $this->assertTrue($this->fx->policy->granted('bob', 'notes', 'read'));
        $this->assertFalse($this->fx->policy->granted('bob', 'logs', 'read'));
        $this->fx->rule('interns', ['notes.read' => false]);
        $this->fx->rule('staff', ['logs.read' => true]);
        $this->assertFalse($this->fx->policy->granted('bob', 'notes', 'read'));
        $this->assertTrue($this->fx->policy->granted('alice', 'notes', 'read'), 'alice is not an intern');
        $this->assertTrue($this->fx->policy->granted('bob', 'logs', 'read'));
    }

    public function testExplainNamesTheGroupsThatAllowAndDeny(): void {
        $this->fx->rule('staff', ['files.edit' => true]);
        $this->fx->rule('interns', ['files.edit' => false]);
        $this->assertSame(['result' => false, 'source' => GrantPolicy::SOURCE_GROUP, 'override' => null, 'allow' => ['staff'], 'deny' => ['interns']],
            $this->fx->policy->explain('bob', 'files.edit'));
    }

    public function testAMembershipChangeAppliesToTheNextRequest(): void {
        $this->fx->rule('ops', [GroupGrants::ELIGIBLE => true, 'files.edit' => true]);
        $this->assertFalse($this->fx->policy->eligible('alice'));
        $this->fx->members['ops'][] = 'alice';
        $this->fx->groups->forget(); // a new request
        $this->assertTrue($this->fx->policy->eligible('alice'));
        $this->assertTrue($this->fx->policy->granted('alice', 'files', 'edit'));
        $this->fx->members['ops'] = ['carol'];
        $this->fx->groups->forget();
        $this->assertFalse($this->fx->policy->eligible('alice'));
    }

    public function testASavedRuleAppliesAtOnceAndAStaleSaveIsRefused(): void {
        $version = $this->fx->mapper->state()['version'];
        $this->fx->groups->save('staff', [GroupGrants::ELIGIBLE => true], $version);
        $this->assertTrue($this->fx->policy->eligible('alice'));
        $this->expectException(\OCA\Mcp\Service\GroupGrantsConflict::class);
        $this->fx->groups->save('staff', [], $version);
    }

    public function testInvalidPermissionsAreRefusedBeforeAnythingIsWritten(): void {
        foreach (['files.delete', 'files', 'nope.read', 'eligible.x', ''] as $bad) {
            try {
                $this->fx->rule('staff', [$bad => true]);
                $this->fail($bad);
            } catch (\InvalidArgumentException) {
            }
        }
        $this->assertSame(['rules' => [], 'version' => 0], $this->fx->mapper->state());
    }

    public function testInheritRemovesTheIndividualValueSoTheGroupDecides(): void {
        $this->fx->rule('staff', ['files.edit' => true, GroupGrants::ELIGIBLE => true]);
        $this->fx->policy->setGrantOverride('alice', 'files', 'edit', false);
        $this->fx->policy->setEligibleOverride('alice', false);
        $this->assertFalse($this->fx->policy->granted('alice', 'files', 'edit'));
        $this->assertFalse($this->fx->policy->eligible('alice'));
        $this->fx->policy->setGrantOverride('alice', 'files', 'edit', null);
        $this->fx->policy->setEligibleOverride('alice', null);
        $this->assertArrayNotHasKey('grant_files_edit', $this->fx->config->user['alice']['mcp']);
        $this->assertArrayNotHasKey(GrantPolicy::ELIGIBLE_KEY, $this->fx->config->user['alice']['mcp']);
        $this->assertTrue($this->fx->policy->granted('alice', 'files', 'edit'));
        $this->assertTrue($this->fx->policy->eligible('alice'));
    }

    public function testSettingAnIndividualValueThatEndsEligibilitySignsTheUserOut(): void {
        $this->fx->rule('staff', [GroupGrants::ELIGIBLE => true]);
        $this->fx->oauth->insertToken(['user_id' => 'alice'], 0);
        $this->fx->policy->setEligibleOverride('alice', null);
        $this->assertCount(1, $this->fx->oauth->tokens, 'still eligible through staff');
        $this->fx->policy->setEligibleOverride('alice', false);
        $this->assertSame([], $this->fx->oauth->tokens);
    }

    public function testEligibleUsersIncludeGroupMembersAndExcludeDenies(): void {
        $this->fx->members['ops'] = ['carol', 'dave'];
        $this->fx->rule('ops', [GroupGrants::ELIGIBLE => true]);
        $this->fx->individual('erin', GrantPolicy::ELIGIBLE_KEY, true);
        $this->fx->individual('dave', GrantPolicy::ELIGIBLE_KEY, false);
        $this->fx->rule('interns', [GroupGrants::ELIGIBLE => false]);
        $this->fx->rule('staff', [GroupGrants::ELIGIBLE => true]);
        // alice: staff; bob: staff + interns (deny wins); carol: ops; dave: ops but individual deny; erin: individual.
        $this->assertSame(['alice', 'carol', 'erin'], $this->fx->policy->eligibleUsers());
        $this->fx->config->user['carol']['mcp'][GrantPolicy::CONNECTED_KEY] = '1';
        $this->fx->config->user['bob']['mcp'][GrantPolicy::CONNECTED_KEY] = '1';
        $this->assertSame(['carol'], $this->fx->policy->connectedUsers());
    }

    public function testForUsersReportsResultsOverridesSourcesAndDecidingGroupsWithOneReadPerKeyAndUser(): void {
        $this->fx->rule('staff', [GroupGrants::ELIGIBLE => true, 'files.edit' => true]);
        $this->fx->rule('interns', ['files.edit' => false]);
        $this->fx->individual('alice', 'grant_notes_read', false);
        $before = $this->fx->config->batchCalls;
        $state = $this->fx->policy->forUsers(['alice', 'bob', 'carol']);
        $keys = 2 + array_sum(array_map('count', GrantPolicy::CATALOG)); // eligible, connected, every grant
        $this->assertSame($keys, $this->fx->config->batchCalls - $before, 'one batched read per stored key');
        $this->assertSame(3, $this->fx->groupLookups, "one read of each user's groups");
        $this->assertTrue($state['alice']['eligible']);
        $this->assertSame(GrantPolicy::SOURCE_GROUP, $state['alice']['sources']['eligible']);
        $this->assertTrue($state['alice']['grants']['files']['edit']);
        $this->assertFalse($state['alice']['grants']['notes']['read']);
        $this->assertFalse($state['alice']['overrides']['grants']['notes']['read']);
        $this->assertSame(GrantPolicy::SOURCE_OVERRIDE, $state['alice']['sources']['grants']['notes']['read']);
        $this->assertFalse($state['bob']['grants']['files']['edit']);
        $this->assertSame(['allow' => ['staff'], 'deny' => ['interns']], $state['bob']['groupRules']['files.edit']);
        $this->assertFalse($state['carol']['eligible']);
        $this->assertSame([], $state['carol']['groupRules']);
        $this->assertNull($state['carol']['overrides']['eligible']);
    }

    public function testRemovingAGroupDropsItsRules(): void {
        $this->fx->rule('staff', [GroupGrants::ELIGIBLE => true]);
        $this->fx->rule('ops', ['files.edit' => true]);
        $this->fx->groups->removeGroup('staff');
        $this->assertSame(['ops' => ['files.edit' => true]], $this->fx->mapper->state()['rules']);
        $this->assertFalse($this->fx->policy->eligible('alice'));
    }

    /**
     * The upgrade guarantee: with no group rules yet, every user's decisions are exactly the ones 1.0.x made from the
     * same stored values (an individual value where one is stored, the default otherwise), so nobody gains access.
     */
    public function testAfterTheUpgradeEveryDecisionIsTheSameAsBefore(): void {
        $stored = [
            'never-touched' => [],
            'enabled-writer' => ['eligible' => '1', 'connected' => '1', 'grant_files_edit' => '1', 'grant_notes_read' => '0'],
            'toggled-off' => ['eligible' => '0', 'grant_files_read' => '0', 'grant_logs_read' => '1'],
            'bulk-default' => ['eligible' => '1', 'grant_files_edit' => '0', 'grant_calendar_read' => '1'],
        ];
        foreach ($stored as $uid => $values) {
            $this->fx->config->user[$uid]['mcp'] = $values;
        }
        $this->fx->members = ['everyone' => array_keys($stored)];
        $legacy = static function (array $values, string $key, string $default): bool {
            return ($values[$key] ?? $default) === '1';
        };
        foreach ($stored as $uid => $values) {
            $this->assertSame($legacy($values, 'eligible', '0'), $this->fx->policy->eligible($uid), "$uid eligible");
            foreach (GrantPolicy::CATALOG as $module => $operations) {
                foreach ($operations as $operation) {
                    $default = GrantPolicy::defaultGranted($module, $operation) ? '1' : '0';
                    $this->assertSame($legacy($values, "grant_{$module}_{$operation}", $default),
                        $this->fx->policy->granted($uid, $module, $operation), "$uid $module.$operation");
                }
            }
        }
        // And the policy without any group source decides the same, so the group source alone changes nothing.
        $plain = InMemoryConfig::policy($this->fx->config->mock($this), $this->fx->oauth);
        foreach (array_keys($stored) as $uid) {
            $this->assertSame($plain->forUsers([$uid])[$uid]['grants'], $this->fx->policy->forUsers([$uid])[$uid]['grants']);
        }
    }
}
