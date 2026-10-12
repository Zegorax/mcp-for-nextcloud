<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Service;

use OCA\Mcp\Tools\Common\CommonMessages;
use OCA\Mcp\Tools\Files\FileBackup;
use OCA\Mcp\Tools\ToolFailure;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\Storage\ISharedStorage;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;

/**
 * Enforces privacy of sensitive files and folders tagged with admin-configured system tags.
 *
 * Any node tagged with a hidden tag, any node inside a tagged folder (ancestral inheritance),
 * or any backup of a hidden file is completely invisible to the MCP tools: access is refused
 * as "not found" (CommonMessages::notFound()) to avoid leaking whether the item exists.
 *
 * When no hidden tags are configured in oc_appconfig, all operations have zero overhead.
 */
class VisibilityGuard {
    public const CONFIG_KEY = 'hidden_system_tags';

    /** @var list<string>|null cached list of hidden system tag IDs */
    private ?array $hiddenTagIds = null;

    /** @var array<int, bool> per-request node visibility cache: [nodeId => isVisible] */
    private array $cache = [];

    /** whether the stored configuration could not be read as a list of tag IDs */
    private bool $configInvalid = false;

    /** whether the invalid configuration was already logged by this instance */
    private bool $invalidLogged = false;

    /** @var array<int, bool> per-request visibility in the owner's tree, independent of mounted paths */
    private array $ownerCache = [];

    /** @var array<string, array{node: Node, mount: string}|null> share root by ancestor id and mounted path */
    private array $shareRootCache = [];

    public function __construct(
        private IConfig $config,
        private ISystemTagObjectMapper $tagMapper,
        private ?IRootFolder $rootFolder = null,
        private ?ISystemTagManager $tagManager = null,
        private ?LoggerInterface $logger = null,
        ?\OCP\EventDispatcher\IEventDispatcher $events = null,
    ) {
        // Invalidate this very instance after OCP mutations outside the MCP tools,
        // too. Run after ordinary privacy hooks; tools also clear in finally.
        $invalidate = fn () => $this->clearCache();
        foreach ([
            \OCP\Files\Events\Node\NodeRenamedEvent::class,
            \OCP\Files\Events\Node\NodeCreatedEvent::class,
            \OCP\Files\Events\Node\NodeCopiedEvent::class,
            \OCP\Files\Events\Node\NodeDeletedEvent::class,
            \OCP\Files\Events\Node\NodeWrittenEvent::class,
            \OCP\SystemTag\MapperEvent::EVENT_ASSIGN,
            \OCP\SystemTag\MapperEvent::EVENT_UNASSIGN,
        ] as $event) {
            $events?->addListener($event, $invalidate, PHP_INT_MIN);
        }
    }

    /**
     * Checks whether a filesystem node is visible to the MCP caller.
     *
     * @param Node $node file or folder
     * @return bool false when the node or any ancestor has a hidden tag, or is a backup of a hidden file
     */
    public function isVisible(Node $node): bool {
        if (!$this->isConfigurationValid()) {
            $this->warnInvalidConfiguration();
            return false;
        }
        $hiddenTags = $this->getHiddenTagIds();
        if ($hiddenTags === []) {
            return true;
        }

        // Only local share mounts need the owner's tree: ordinary mounts may
        // have no local owner. Detect shares before using the mounted-path cache.
        if (!$this->isVisibleThroughLocalShare($node, $hiddenTags)) {
            return false;
        }

        $nodeId = (int)$node->getId();
        if ($nodeId > 0 && isset($this->cache[$nodeId])) {
            return $this->cache[$nodeId];
        }

        // Collect ancestors up to root
        $chain = [];
        $current = $node;
        while ($current instanceof Node) {
            $cid = (int)$current->getId();
            if ($cid <= 0 || isset($chain[$cid])) {
                break;
            }

            // If an ancestor is already cached, leverage it
            if (isset($this->cache[$cid])) {
                if (!$this->cache[$cid]) {
                    if ($nodeId > 0) {
                        $this->cache[$nodeId] = false;
                    }
                    return false;
                }
                break;
            }

            $chain[$cid] = $current;
            try {
                $parent = $current->getParent();
                if (!$parent instanceof Node || (int)$parent->getId() === $cid) {
                    break;
                }
                $current = $parent;
            } catch (\Throwable) {
                $this->warnVisibilityFailure();
                return false;
            }
        }

        // Check tags in batch for unverified chain nodes
        if ($chain !== []) {
            $idsToCheck = array_map(static fn (int $id): string => (string)$id, array_keys($chain));
            try {
                $tagsByObject = $this->tagMapper->getTagIdsForObjects($idsToCheck, 'files');
            } catch (\Throwable) {
                $this->warnVisibilityFailure();
                return false;
            }

            // Check from root down to node
            $reversedChain = array_reverse($chain, true);
            $foundHidden = false;
            foreach ($reversedChain as $cid => $chainNode) {
                if ($foundHidden) {
                    $this->cache[$cid] = false;
                    continue;
                }
                $nodeTags = array_map(strval(...), $tagsByObject[(string)$cid] ?? []);
                foreach ($nodeTags as $tagId) {
                    if (in_array((string)$tagId, $hiddenTags, true)) {
                        $foundHidden = true;
                        $this->cache[$cid] = false;
                        break;
                    }
                }
                if (!$foundHidden) {
                    $this->cache[$cid] = true;
                }
            }

            if ($foundHidden) {
                if ($nodeId > 0) {
                    $this->cache[$nodeId] = false;
                }
                return false;
            }
        }

        // Check /MCP backups mirror rule
        if ($this->isHiddenBackup($node, $chain)) {
            if ($nodeId > 0) {
                $this->cache[$nodeId] = false;
            }
            return false;
        }

        if ($nodeId > 0) {
            $this->cache[$nodeId] = true;
        }
        return true;
    }

    /**
     * Wrappers report local shares via the public ISharedStorage interface.
     * Also inspect ancestors: a child can be on another storage under a share
     * mount. Core federated storages implement only the legacy OCA interface;
     * their locally configured tags are checked through the mounted chain.
     *
     * @param list<string> $hiddenTags
     */
    private function isVisibleThroughLocalShare(Node $node, array $hiddenTags): bool {
        try {
            $current = $node;
            $seen = [];
            $keys = [];
            $shareRoot = null;
            while (!$current instanceof IRootFolder) {
                if (isset($seen[spl_object_id($current)])) {
                    throw new \RuntimeException();
                }
                $seen[spl_object_id($current)] = $current;
                $id = (int)$current->getId();
                if ($id > 0) {
                    // A file id can occur through both personal and shared paths.
                    // Keep classification local to the mounted path, unlike tags.
                    $key = $id . ':' . $current->getPath();
                    if (array_key_exists($key, $this->shareRootCache)) {
                        $known = $this->shareRootCache[$key];
                        if ($shareRoot === null || ($known !== null && $known['mount'] === $shareRoot['mount'])) {
                            $shareRoot = $known;
                        }
                        break;
                    }
                    if ($current->getStorage()->instanceOfStorage(ISharedStorage::class)) {
                        $mount = $current->getMountPoint()->getMountPoint();
                        if ($mount === '') {
                            throw new \RuntimeException();
                        }
                        if ($shareRoot !== null && $mount !== $shareRoot['mount']) {
                            break; // a different mount is not part of this share
                        }
                        $shareRoot = ['node' => $current, 'mount' => $mount];
                    } elseif ($shareRoot !== null) {
                        break; // reached the parent outside the shared mount
                    }
                    $keys[] = $key;
                }
                // Nodes without an id cannot be memoized or resolved by id;
                // continue to their parent so an enclosing share is still checked.
                $parent = $current->getParent();
                if (!$parent instanceof Node || $parent === $current
                    || ($id > 0 && (int)$parent->getId() === $id)) {
                    break;
                }
                $current = $parent;
            }
            foreach ($keys as $key) {
                $this->shareRootCache[$key] = $shareRoot;
            }
            // Descendants below this root have the same mounted and owner chain;
            // only the omitted parents above the mount need canonical resolution.
            return $shareRoot === null || $this->isVisibleInOwnerTree($shareRoot['node'], $hiddenTags);
        } catch (\Throwable) {
            $this->warnVisibilityFailure();
            return false;
        }
    }

    /**
     * Resolves metadata only; tools still access content through the caller's node
     * and its native permissions. getById covers every owner alias, unlike the
     * arbitrary single result of getFirstNodeById. No directory scan is needed.
     *
     * @param list<string> $hiddenTags
     */
    private function isVisibleInOwnerTree(Node $node, array $hiddenTags): bool {
        try {
            $id = (int)$node->getId();
            if ($id <= 0 || $this->rootFolder === null) {
                throw new \RuntimeException();
            }
            if (isset($this->ownerCache[$id])) {
                return $this->ownerCache[$id];
            }
            $owner = $node->getOwner();
            if ($owner === null || $owner->getUID() === '') {
                throw new \RuntimeException();
            }
            $folder = $this->rootFolder->getUserFolder($owner->getUID());
            $folderId = (int)$folder->getId();
            $origins = $id === $folderId ? [$folder] : $folder->getById($id);
            if ($origins === []) {
                throw new \RuntimeException();
            }
            $ids = [];
            foreach ($origins as $origin) {
                if (!$origin instanceof Node || (int)$origin->getId() !== $id) {
                    throw new \RuntimeException();
                }
                $current = $origin;
                $seen = [];
                while (true) {
                    $cid = (int)$current->getId();
                    if ($cid <= 0 || isset($seen[$cid])) {
                        throw new \RuntimeException();
                    }
                    $seen[$cid] = true;
                    $ids[$cid] = (string)$cid;
                    if ($cid === $folderId) {
                        break; // do not call Root::getParent()
                    }
                    $current = $current->getParent();
                }
            }
            $tagsByObject = $this->tagMapper->getTagIdsForObjects(array_values($ids), 'files');
            foreach ($ids as $cid) {
                foreach ($tagsByObject[$cid] ?? [] as $tagId) {
                    if (in_array((string)$tagId, $hiddenTags, true)) {
                        return $this->ownerCache[$id] = false;
                    }
                }
            }
            return $this->ownerCache[$id] = true;
        } catch (\Throwable) {
            $this->warnVisibilityFailure();
            return false; // resolution failures are retried, never cached as visible
        }
    }

    /** Logs a privacy refusal without node names, paths or exception details. */
    private function warnVisibilityFailure(): void {
        $this->logger?->warning('MCP visibility check failed; access refused', ['app' => 'mcp']);
    }

    /** Logs, once per instance, that the stored hidden tags cannot be read, without the stored value. */
    private function warnInvalidConfiguration(): void {
        if ($this->invalidLogged) {
            return;
        }
        $this->invalidLogged = true;
        $this->logger?->warning('MCP hidden tags configuration is invalid; access refused', ['app' => 'mcp']);
    }

    /**
     * Asserts that a node is visible, throwing ToolFailure with CommonMessages::notFound() if hidden.
     *
     * @param Node $node
     * @throws ToolFailure
     */
    public function assertVisible(Node $node): void {
        if (!$this->isVisible($node)) {
            throw new ToolFailure(CommonMessages::notFound());
        }
    }

    /**
     * Filters a collection of nodes, keeping only visible ones while preserving order.
     *
     * @param iterable<Node> $nodes
     * @return list<Node>
     */
    public function filter(iterable $nodes): array {
        $out = [];
        foreach ($nodes as $node) {
            if ($node instanceof Node && $this->isVisible($node)) {
                $out[] = $node;
            }
        }
        return $out;
    }

    /**
     * Checks if a path is allowed for write operations (mkdir, move destination, copy destination).
     * Returns false if an existing target or any existing ancestor is hidden.
     */
    public function isPathAllowedForWrite(Folder $root, string $path): bool {
        if (!$this->isConfigurationValid()) {
            $this->warnInvalidConfiguration();
            return false;
        }
        $hiddenTags = $this->getHiddenTagIds();
        if ($hiddenTags === []) {
            return true;
        }

        $relative = trim($path, '/');
        if ($relative !== '' && $root->nodeExists($relative)) {
            try {
                $existing = $root->get($relative);
                if (!$this->isVisible($existing)) {
                    return false;
                }
            } catch (\Throwable) {
                return false;
            }
        }

        // Check existing ancestors
        $segments = array_values(array_filter(explode('/', $relative), static fn (string $segment): bool => $segment !== ''));
        while ($segments !== []) {
            array_pop($segments);
            $walk = implode('/', $segments);
            if ($walk === '') {
                break;
            }
            if ($root->nodeExists($walk)) {
                try {
                    $ancestor = $root->get($walk);
                    if (!$this->isVisible($ancestor)) {
                        return false;
                    }
                } catch (\Throwable) {
                    return false;
                }
                break;
            }
        }

        return true;
    }

    /**
     * Copies hidden tags from the source or its ancestors before the empty backup receives content.
     *
     * @throws ToolFailure when tags or ancestors cannot be resolved, or tags cannot be assigned
     */
    public function copyHiddenTags(Node $source, Node $destination): void {
        if (!$this->isConfigurationValid()) {
            $this->warnInvalidConfiguration();
            throw new ToolFailure(\OCA\Mcp\Tools\Files\FilesMessages::backupFailed());
        }
        $hiddenTags = $this->getHiddenTagIds();
        if ($hiddenTags === []) {
            return;
        }

        $tagsToAssign = [];
        $current = $source;
        $seen = [];
        while ($current instanceof Node) {
            $cid = (int)$current->getId();
            if ($cid <= 0 || isset($seen[$cid])) {
                break;
            }
            $seen[$cid] = true;
            try {
                $tags = $this->tagMapper->getTagIdsForObjects([(string)$cid], 'files');
                foreach ($tags[(string)$cid] ?? [] as $tagId) {
                    if (in_array((string)$tagId, $hiddenTags, true)) {
                        $tagsToAssign[(string)$tagId] = true;
                    }
                }
                $parent = $current->getParent();
                if (!$parent instanceof Node || (int)$parent->getId() === $cid) {
                    break;
                }
                $current = $parent;
            } catch (\Throwable $e) {
                $this->warnVisibilityFailure();
                throw new ToolFailure(\OCA\Mcp\Tools\Files\FilesMessages::backupFailed(), 0, $e);
            }
        }

        if ($tagsToAssign !== []) {
            try {
                try {
                    $this->tagMapper->assignTags((string)$destination->getId(), 'files', array_map(strval(...), array_keys($tagsToAssign)));
                } finally {
                    $this->clearCache();
                }
            } catch (\Throwable $e) {
                $this->warnVisibilityFailure();
                throw new ToolFailure(\OCA\Mcp\Tools\Files\FilesMessages::backupFailed(), 0, $e);
            }
        }
    }

    /**
     * @return list<string> configured hidden system tag IDs as strings; empty when none are configured
     *                      or when the stored value is invalid (see isConfigurationValid())
     */
    public function getHiddenTagIds(): array {
        if ($this->hiddenTagIds !== null) {
            return $this->hiddenTagIds;
        }
        $raw = $this->config->getAppValue('mcp', self::CONFIG_KEY, '[]');
        // Objects stay objects: decoded as arrays, {} and {"0":"42"} would pass for lists.
        $decoded = json_decode($raw);
        $ids = [];
        $valid = is_array($decoded);
        foreach ($valid ? $decoded : [] as $id) {
            $str = is_int($id) || is_string($id) ? (string)$id : '';
            if (!ctype_digit($str) || (int)$str <= 0) {
                $valid = false;
                break;
            }
            $ids[] = $str;
        }
        // An unreadable value is not "no tags": the guard refuses every node until an admin saves again,
        // and the stored value is left untouched.
        $this->configInvalid = !$valid;
        $this->hiddenTagIds = $valid ? array_values(array_unique($ids)) : [];
        return $this->hiddenTagIds;
    }

    /** Whether the stored hidden tags can be read; when not, every node is refused. */
    public function isConfigurationValid(): bool {
        $this->getHiddenTagIds();
        return !$this->configInvalid;
    }

    /**
     * Saves the configured hidden system tag IDs.
     * Validates that each ID is a positive integer and corresponds to an existing system tag if tagManager is set.
     *
     * Removing every tag needs no lookup. Otherwise, when the tag manager cannot answer, nothing is saved
     * and the previous configuration still applies.
     *
     * @param list<string|int> $tagIds
     * @throws \InvalidArgumentException when an ID is not a positive integer
     * @throws HiddenTagsUnavailableException when the system tags cannot be checked
     */
    public function setHiddenTagIds(array $tagIds): void {
        $sanitized = [];
        foreach ($tagIds as $id) {
            $str = (string)$id;
            if (!ctype_digit($str) || (int)$str <= 0) {
                throw new \InvalidArgumentException('Tag ID must be a positive integer: ' . $str);
            }
            $sanitized[] = $str;
        }
        $sanitized = array_values(array_unique($sanitized));

        if ($this->tagManager !== null && $sanitized !== []) {
            try {
                $tags = $this->tagManager->getTagsByIds($sanitized);
                $existing = [];
                foreach ($tags as $tag) {
                    if ($tag instanceof ISystemTag) {
                        $existing[] = (string)$tag->getId();
                    }
                }
                $sanitized = array_values(array_intersect($sanitized, $existing));
            } catch (\Throwable) {
                try {
                    $all = $this->tagManager->getAllTags(null, false);
                    $valid = [];
                    foreach ($all as $t) {
                        if ($t instanceof ISystemTag) {
                            $valid[(string)$t->getId()] = true;
                        }
                    }
                    $sanitized = array_values(array_filter($sanitized, static fn (string $id): bool => isset($valid[$id])));
                } catch (\Throwable $e) {
                    throw new HiddenTagsUnavailableException('System tags could not be checked', 0, $e);
                }
            }
        }

        $this->config->setAppValue('mcp', self::CONFIG_KEY, json_encode($sanitized));
        $this->hiddenTagIds = $sanitized;
        $this->configInvalid = false;
        $this->cache = [];
        $this->ownerCache = [];
        $this->shareRootCache = [];
    }

    /** Clears the in-memory caches. */
    public function clearCache(): void {
        $this->hiddenTagIds = null;
        $this->configInvalid = false;
        $this->cache = [];
        $this->ownerCache = [];
        $this->shareRootCache = [];
    }

    /**
     * Checks if $node is inside the backup folder, and if the original file still exists and is hidden.
     *
     * @param Node $node node being checked
     * @param array<int, Node> $chain the node and its not-yet-cached ancestors, keyed by file id, as collected by the caller
     * @return bool true when the node is a backup mirror of a hidden original
     */
    private function isHiddenBackup(Node $node, array $chain): bool {
        // Find ancestor named FileBackup::FOLDER
        $backupAncestor = null;
        $segmentsBelowBackup = [];
        $current = $node;

        while ($current instanceof Node) {
            if ($current instanceof IRootFolder) {
                break; // Root has no parent: Root::getParent() throws NotFoundException
            }
            if ($current->getName() === FileBackup::FOLDER) {
                $backupAncestor = $current;
                break;
            }
            array_unshift($segmentsBelowBackup, $current->getName());
            try {
                $parent = $current->getParent();
                if (!$parent instanceof Node || (int)$parent->getId() === (int)$current->getId()) {
                    break;
                }
                $current = $parent;
            } catch (\Throwable) {
                $this->warnVisibilityFailure();
                return true;
            }
        }

        if ($backupAncestor === null || $segmentsBelowBackup === []) {
            return false;
        }

        try {
            $userFolder = $backupAncestor->getParent();
        } catch (\Throwable) {
            $this->warnVisibilityFailure();
            return true;
        }

        if (!$userFolder instanceof Folder) {
            return false;
        }

        // Reconstruct original relative path
        $leaf = array_pop($segmentsBelowBackup);
        if ($leaf !== null && preg_match('/^(.+)\.\d{8}-\d{6}(?:-\d+)?\.bak$/', $leaf, $matches)) {
            $leaf = $matches[1];
        }

        $relDir = implode('/', $segmentsBelowBackup);
        $originalRelPath = $relDir === '' ? (string)$leaf : $relDir . '/' . $leaf;

        if ($originalRelPath === '' || !$userFolder->nodeExists($originalRelPath)) {
            return false;
        }

        try {
            $originalNode = $userFolder->get($originalRelPath);
            return !$this->isVisible($originalNode);
        } catch (\Throwable) {
            $this->warnVisibilityFailure();
            return true;
        }
    }
}
