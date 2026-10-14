<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service;

/**
 * What saving a group's rules would change, before it is saved: for each permission whose rule changes, how many
 * members gain it, lose it, keep it because of their own individual value, or keep it because another group or
 * the default already decides. It reads only; the members who would lose eligibility are listed for revocation.
 */
class GroupGrantImpact {
    public function __construct(
        private GrantPolicy $policy,
        private GroupGrants $groups,
    ) {}

    /**
     * @param string $gid group being edited
     * @param array<string, bool> $rules the group's complete new rules (a permission left out is inherited)
     * @return array{group:string, members:int, truncated:bool, version:int,
     *     changes:list<array{permission:string, from:bool|null, to:bool|null, gain:int, lose:int, overridden:int, unchanged:int}>,
     *     losingEligibility:list<string>}
     */
    public function preview(string $gid, array $rules): array {
        $state = $this->groups->state();
        $before = $state['rules'][$gid] ?? [];
        $candidate = $state['rules'];
        $candidate[$gid] = $rules;
        $members = $this->groups->membersOf($gid);
        $changes = [];
        $losingEligibility = [];
        foreach (GroupGrants::permissions() as $permission) {
            $from = $before[$permission] ?? null;
            $to = $rules[$permission] ?? null;
            if ($from === $to) {
                continue;
            }
            $change = ['permission' => $permission, 'from' => $from, 'to' => $to, 'gain' => 0, 'lose' => 0, 'overridden' => 0, 'unchanged' => 0];
            foreach ($members as $uid) {
                $now = $this->policy->explain($uid, $permission);
                $next = $this->policy->explain($uid, $permission, $candidate);
                if ($now['override'] !== null) {
                    $change['overridden']++;
                } elseif ($now['result'] === $next['result']) {
                    $change['unchanged']++;
                } elseif ($next['result']) {
                    $change['gain']++;
                } else {
                    $change['lose']++;
                    if ($permission === GroupGrants::ELIGIBLE) {
                        $losingEligibility[] = $uid;
                    }
                }
            }
            $changes[] = $change;
        }
        return [
            'group' => $gid,
            'members' => count($members),
            'truncated' => count($members) >= GroupGrants::MAX_MEMBERS,
            'version' => $state['version'],
            'changes' => $changes,
            'losingEligibility' => $losingEligibility,
        ];
    }
}
