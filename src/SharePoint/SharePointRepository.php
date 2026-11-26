<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\SharePoint;

use Adx\MarketingMcp\Graph\GraphClient;
use Adx\MarketingMcp\Graph\GraphException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Read-only access to the "Agent Knowledge" folder tree.
 *
 * Every public method enforces scope: IDs supplied by the MCP client are
 * checked against the FolderIndex before any Graph call is made with them.
 */
final class SharePointRepository
{
    /** Do not rebuild the index more often than this when an unknown ID is requested. */
    private const MIN_REFRESH_AGE_SECONDS = 60;

    public function __construct(
        private readonly GraphClient $graph,
        private readonly FolderIndex $index,
        private readonly string $driveId,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function driveId(): string
    {
        return $this->driveId;
    }

    public function root(): DriveItem
    {
        return $this->require($this->index->rootFolderId());
    }

    /**
     * Resolve an ID to an in-scope item, refreshing a stale index once if the ID is unknown
     * (e.g. a document added a few minutes ago).
     *
     * @throws ItemNotFoundException
     */
    public function require(string $id): DriveItem
    {
        $id = trim($id);
        if ('' === $id || !preg_match('/^[A-Za-z0-9!_\-.]+$/', $id)) {
            throw ItemNotFoundException::forId($id);
        }

        $item = $this->index->get($id);
        if (null === $item && $this->index->ageSeconds() >= self::MIN_REFRESH_AGE_SECONDS) {
            $this->index->refresh();
            $item = $this->index->get($id);
        }

        if (null === $item) {
            $this->logger->notice('Rejected item outside Agent Knowledge scope or unknown', ['id_hash' => substr(hash('sha256', $id), 0, 12)]);

            throw ItemNotFoundException::forId($id);
        }

        return $item;
    }

    /**
     * Children of an in-scope folder, folders first then files, alphabetical.
     *
     * @return list<DriveItem>
     */
    public function listFolder(?string $folderId = null): array
    {
        $folder = null === $folderId || '' === trim($folderId) ? $this->root() : $this->require($folderId);
        if (!$folder->isFolder) {
            throw new ItemNotFoundException(sprintf('"%s" is a file, not a folder.', $folder->name));
        }

        $children = array_values(array_filter(
            $this->index->all(),
            static fn (DriveItem $i): bool => $i->parentId === $folder->id,
        ));

        usort($children, static fn (DriveItem $a, DriveItem $b): int => [$b->isFolder, strtolower($a->name)] <=> [$a->isFolder, strtolower($b->name)]);

        return $children;
    }

    /**
     * Search file names, folder paths and (via Graph search) document content.
     *
     * @param list<string> $extensions lower-case, without dots; empty = any
     *
     * @return list<array{item: DriveItem, score: int, matchedOn: list<string>}>
     */
    public function search(string $query, int $limit = 10, array $extensions = []): array
    {
        $query = trim($query);
        $terms = array_values(array_filter(
            preg_split('/[\s,;]+/u', mb_strtolower($query)) ?: [],
            static fn (string $t): bool => mb_strlen($t) >= 2,
        ));

        /** @var array<string, array{item: DriveItem, score: int, matchedOn: list<string>}> $hits */
        $hits = [];

        // 1. Name / path matches from the index.
        foreach ($this->index->all() as $item) {
            if ($item->isFolder || ($extensions !== [] && !\in_array($item->extension(), $extensions, true))) {
                continue;
            }

            $name = mb_strtolower($item->name);
            $path = mb_strtolower($item->path);
            $score = 0;
            $allMatched = [] !== $terms;

            foreach ($terms as $term) {
                if (str_contains($name, $term)) {
                    $score += 3;
                } elseif (str_contains($path, $term)) {
                    ++$score;
                } else {
                    $allMatched = false;
                }
            }

            if ($allMatched && $score > 0) {
                $hits[$item->id] = ['item' => $item, 'score' => $score, 'matchedOn' => ['name/path']];
            }
        }

        // 2. Full-text search through Graph, restricted to the root folder and filtered by the index.
        foreach ($this->graphSearch($query) as $id) {
            $item = $this->index->get($id);
            if (null === $item || $item->isFolder || ($extensions !== [] && !\in_array($item->extension(), $extensions, true))) {
                continue;
            }

            if (isset($hits[$id])) {
                $hits[$id]['score'] += 2;
                $hits[$id]['matchedOn'][] = 'content';
            } else {
                $hits[$id] = ['item' => $item, 'score' => 2, 'matchedOn' => ['content']];
            }
        }

        $results = array_values($hits);
        usort($results, static fn (array $a, array $b): int => [$b['score'], $b['item']->lastModified ?? ''] <=> [$a['score'], $a['item']->lastModified ?? '']);

        return \array_slice($results, 0, max(1, $limit));
    }

    /**
     * Live metadata for an in-scope item (fresh size / modified date).
     */
    public function fetchLive(DriveItem $item): DriveItem
    {
        $data = $this->graph->getJson(
            sprintf('/drives/%s/items/%s', rawurlencode($this->driveId), rawurlencode($item->id)),
            ['$select' => 'id,name,size,file,folder,webUrl,lastModifiedDateTime,lastModifiedBy,parentReference'],
        );

        if (($data['parentReference']['driveId'] ?? $this->driveId) !== $this->driveId) {
            throw ItemNotFoundException::forId($item->id);
        }

        return DriveItem::fromGraph($data, $item->path, $item->parentId);
    }

    public function indexIsTruncated(): bool
    {
        return $this->index->isTruncated();
    }

    /**
     * @return list<string> item IDs
     */
    private function graphSearch(string $query): array
    {
        if (mb_strlen($query) < 2) {
            return [];
        }

        // OData string literal: single quotes are escaped by doubling them.
        $literal = str_replace("'", "''", mb_substr($query, 0, 200));
        $path = sprintf(
            "/drives/%s/items/%s/search(q='%s')",
            rawurlencode($this->driveId),
            rawurlencode($this->index->rootFolderId()),
            rawurlencode($literal),
        );

        try {
            $results = $this->graph->getAllPages($path, ['$select' => 'id,name', '$top' => 50], 100);
        } catch (GraphException $e) {
            // Selected-permission apps may not be allowed to use search; name/path search still works.
            $this->logger->notice('Graph content search unavailable, using name/path search only', ['status' => $e->httpStatus, 'code' => $e->graphErrorCode]);

            return [];
        }

        return array_values(array_filter(array_map(static fn (array $r): string => (string) ($r['id'] ?? ''), $results)));
    }
}
