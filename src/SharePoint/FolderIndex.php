<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\SharePoint;

use Adx\MarketingMcp\Graph\GraphClient;
use Adx\MarketingMcp\Graph\GraphException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * A snapshot of every folder and file under the configured "Agent Knowledge"
 * folder. It serves two purposes:
 *
 *  1. Scope enforcement: an item ID is only served if it is in this index,
 *     i.e. it is the root folder or one of its descendants.
 *  2. Fast name/path search without a Graph round trip.
 *
 * The snapshot is built breadth-first, capped by item count and depth, and
 * cached on disk for SHAREPOINT_INDEX_TTL_SECONDS.
 */
final class FolderIndex
{
    private const SELECT = 'id,name,size,file,folder,webUrl,lastModifiedDateTime,lastModifiedBy,parentReference';

    /** How long a request waits for another process that is rebuilding the index. */
    private const LOCK_WAIT_SECONDS = 45;

    /** @var array<string, DriveItem>|null */
    private ?array $items = null;
    private int $builtAt = 0;
    private bool $truncated = false;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    public function __construct(
        private readonly GraphClient $graph,
        private readonly string $driveId,
        private readonly string $rootFolderId,
        private readonly ?string $cacheDirectory,
        private readonly int $ttlSeconds,
        private readonly int $maxItems,
        private readonly int $maxDepth,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?callable $clock = null,
    ) {
        $this->clock = null !== $clock ? $clock(...) : time(...);
    }

    public function rootFolderId(): string
    {
        return $this->rootFolderId;
    }

    /** @return array<string, DriveItem> */
    public function all(): array
    {
        $this->load();

        return $this->items ?? [];
    }

    public function get(string $id): ?DriveItem
    {
        $this->load();

        return $this->items[$id] ?? null;
    }

    public function contains(string $id): bool
    {
        return null !== $this->get($id);
    }

    public function isTruncated(): bool
    {
        $this->load();

        return $this->truncated;
    }

    public function ageSeconds(): int
    {
        $this->load();

        return max(0, ($this->clock)() - $this->builtAt);
    }

    /**
     * Rebuild from Graph.
     *
     * A cross-process lock makes concurrent requests share one crawl instead of each
     * walking the whole tree (which would trigger Graph throttling). A process that
     * waited for the lock adopts the index another process just saved, when it is
     * newer than the one held in memory.
     */
    public function refresh(): void
    {
        $lock = $this->acquireLock();

        try {
            if (null !== $lock && $this->loadFromDisk($this->builtAt)) {
                return;
            }

            $this->build();
            $this->save();
        } finally {
            if (null !== $lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * @return resource|null null when no cache directory is configured or the lock could not be taken in time
     */
    private function acquireLock()
    {
        $file = $this->cacheFile();
        if (null === $file) {
            return null;
        }

        $dir = \dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return null;
        }

        $handle = @fopen($file . '.lock', 'c');
        if (false === $handle) {
            return null;
        }

        $deadline = microtime(true) + self::LOCK_WAIT_SECONDS;
        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return $handle;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        // Never let a stuck peer block every request: rebuild anyway.
        fclose($handle);
        $this->logger->warning('Timed out waiting for the folder index lock; rebuilding without it');

        return null;
    }

    private function load(): void
    {
        if (null !== $this->items) {
            if ($this->ttlSeconds > 0 && ($this->clock)() - $this->builtAt < $this->ttlSeconds) {
                return;
            }
        } elseif ($this->loadFromDisk()) {
            return;
        }

        $this->refresh();
    }

    private function build(): void
    {
        $started = microtime(true);
        $root = $this->graph->getJson(
            sprintf('/drives/%s/items/%s', rawurlencode($this->driveId), rawurlencode($this->rootFolderId)),
            ['$select' => self::SELECT],
        );

        if (!isset($root['folder'])) {
            throw new GraphException('SHAREPOINT_ROOT_FOLDER_ID does not point at a folder.');
        }

        $items = [$this->rootFolderId => DriveItem::fromGraph($root, '', null)];
        $queue = [[$this->rootFolderId, '', 0]];
        $truncated = false;

        while ([] !== $queue) {
            [$folderId, $folderPath, $depth] = array_shift($queue);

            if ($depth >= $this->maxDepth) {
                $truncated = true;
                continue;
            }

            $remaining = $this->maxItems - \count($items);
            if ($remaining <= 0) {
                $truncated = true;
                break;
            }

            $children = $this->graph->getAllPages(
                sprintf('/drives/%s/items/%s/children', rawurlencode($this->driveId), rawurlencode($folderId)),
                ['$select' => self::SELECT, '$top' => 200],
                $remaining + 1,
            );

            foreach ($children as $child) {
                if (\count($items) >= $this->maxItems) {
                    $truncated = true;
                    break;
                }
                $id = (string) ($child['id'] ?? '');
                if ('' === $id || isset($items[$id])) {
                    continue;
                }
                $path = ('' === $folderPath ? '' : $folderPath . '/') . (string) ($child['name'] ?? '');
                $item = DriveItem::fromGraph($child, $path, $folderId);
                $items[$id] = $item;

                if ($item->isFolder) {
                    $queue[] = [$id, $path, $depth + 1];
                }
            }
        }

        $this->items = $items;
        $this->truncated = $truncated;
        $this->builtAt = ($this->clock)();

        $this->logger->info('Built SharePoint folder index', [
            'items' => \count($items),
            'truncated' => $truncated,
            'ms' => (int) ((microtime(true) - $started) * 1000),
        ]);
    }

    private function cacheFile(): ?string
    {
        if (null === $this->cacheDirectory) {
            return null;
        }

        return rtrim($this->cacheDirectory, '\\/') . DIRECTORY_SEPARATOR . 'index_' . hash('sha256', $this->driveId . '|' . $this->rootFolderId) . '.json';
    }

    /**
     * @param int $newerThan only accept a cache file built after this Unix time (0 = any fresh file)
     */
    private function loadFromDisk(int $newerThan = 0): bool
    {
        $file = $this->cacheFile();
        if (null === $file || !is_file($file) || $this->ttlSeconds <= 0) {
            return false;
        }

        $data = json_decode((string) @file_get_contents($file), true);
        if (!\is_array($data) || !isset($data['built_at'], $data['items']) || ($this->clock)() - (int) $data['built_at'] >= $this->ttlSeconds) {
            return false;
        }

        if ((int) $data['built_at'] <= $newerThan) {
            return false;
        }

        $items = [];
        foreach ($data['items'] as $row) {
            if (\is_array($row) && isset($row['id'], $row['name'])) {
                $items[(string) $row['id']] = DriveItem::fromArray($row);
            }
        }

        if (!isset($items[$this->rootFolderId])) {
            return false;
        }

        $this->items = $items;
        $this->builtAt = (int) $data['built_at'];
        $this->truncated = (bool) ($data['truncated'] ?? false);

        return true;
    }

    private function save(): void
    {
        $file = $this->cacheFile();
        if (null === $file || null === $this->items) {
            return;
        }

        $dir = \dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }

        $rows = [];
        foreach ($this->items as $item) {
            $rows[] = $item->toArray() + ['parentId' => $item->parentId];
        }

        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode(['built_at' => $this->builtAt, 'truncated' => $this->truncated, 'items' => $rows], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (false !== $json && false !== @file_put_contents($tmp, $json, LOCK_EX)) {
            if (!@rename($tmp, $file)) {
                @unlink($tmp);
            }
        }
    }
}
