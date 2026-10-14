<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service;

use OCA\Mcp\OAuth\OAuthStore;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\BeforeGroupDeletedEvent;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;

/**
 * Keeps group permissions in step with Nextcloud groups. A user who joins a denying group or leaves the group that
 * let them connect is signed out of every MCP client at once; a deleted group's rules are removed, and its former
 * members who can no longer connect are signed out. Every request decides again anyway (TokenOwnerGate); this
 * only makes the revocation immediate.
 *
 * @template-implements IEventListener<Event>
 */
class GroupChangeListener implements IEventListener {
    /** @var array<string, list<string>> members of groups being deleted, between the two events of one request */
    private static array $deleting = [];

    public function __construct(
        private GrantPolicy $policy,
        private GroupGrants $groups,
        private OAuthStore $store,
    ) {}

    public function handle(Event $event): void {
        if ($event instanceof UserAddedEvent || $event instanceof UserRemovedEvent) {
            $this->groups->forget();
            $this->revokeIfBlocked([$event->getUser()->getUID()]);
        } elseif ($event instanceof BeforeGroupDeletedEvent) {
            $gid = $event->getGroup()->getGID();
            self::$deleting[$gid] = $this->groups->membersOf($gid);
        } elseif ($event instanceof GroupDeletedEvent) {
            $gid = $event->getGroup()->getGID();
            $this->groups->removeGroup($gid);
            $this->revokeIfBlocked(self::$deleting[$gid] ?? []);
            unset(self::$deleting[$gid]);
        }
    }

    /** @param list<string> $uids users whose groups changed */
    private function revokeIfBlocked(array $uids): void {
        foreach ($uids as $uid) {
            if (!$this->policy->eligible($uid)) {
                $this->store->deleteForUser($uid);
            }
        }
    }
}
