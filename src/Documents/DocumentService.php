<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Documents;

use Adx\MarketingMcp\Graph\GraphClient;
use Adx\MarketingMcp\SharePoint\ItemNotFoundException;
use Adx\MarketingMcp\SharePoint\SharePointRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Retrieves an in-scope document and returns its text in response-sized chunks.
 */
final class DocumentService
{
    public function __construct(
        private readonly SharePointRepository $repository,
        private readonly GraphClient $graph,
        private readonly TextExtractor $extractor,
        private readonly int $maxDocumentBytes,
        private readonly int $maxChunkChars,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?ExtractedTextCache $cache = null,
    ) {
    }

    /**
     * @throws ItemNotFoundException
     * @throws UnsupportedDocumentException
     * @throws \Adx\MarketingMcp\Graph\GraphException
     */
    public function read(string $documentId, int $offset = 0): DocumentChunk
    {
        $item = $this->repository->require($documentId);
        if ($item->isFolder) {
            throw new UnsupportedDocumentException(sprintf('"%s" is a folder. Use list_folder to see its contents.', $item->name));
        }

        $item = $this->repository->fetchLive($item);

        if (!$this->extractor->supports($item->extension())) {
            // Let the extractor produce the specific, helpful message.
            $this->extractor->extract('', $item->extension());
        }

        if ($item->size > $this->maxDocumentBytes) {
            throw new UnsupportedDocumentException(sprintf(
                '"%s" is %.1f MB, above the %d MB limit for text extraction. Open it in SharePoint instead: %s',
                $item->name,
                $item->size / 1048576,
                intdiv($this->maxDocumentBytes, 1048576),
                $item->webUrl ?? '(no link available)',
            ));
        }

        // The key includes last-modified time and size, so an edited file is never served from cache.
        // Version suffix: bump when extraction logic changes so old cached text is not reused.
        $cacheKey = sprintf('%s|%s|%s|%d|v1', $this->repository->driveId(), $item->id, $item->lastModified ?? '', $item->size);

        $started = microtime(true);
        $text = $this->cache?->get($cacheKey);
        $cached = null !== $text;

        if (null === $text) {
            $bytes = $this->graph->downloadContent($this->repository->driveId(), $item->id, $this->maxDocumentBytes);
            $text = $this->extractor->extract($bytes, $item->extension());
            unset($bytes);
            $this->cache?->set($cacheKey, $text);
        }

        $this->logger->info($cached ? 'Served document text from cache' : 'Extracted document text', [
            'item_id' => $item->id,
            'path' => $item->path,
            'extension' => $item->extension(),
            'chars' => mb_strlen($text),
            'offset' => $offset,
            'ms' => (int) ((microtime(true) - $started) * 1000),
        ]);

        return self::chunk($item, $text, $offset, $this->maxChunkChars);
    }

    public static function chunk(\Adx\MarketingMcp\SharePoint\DriveItem $item, string $text, int $offset, int $maxChars): DocumentChunk
    {
        $total = mb_strlen($text);
        $offset = max(0, min($offset, $total));
        $piece = mb_substr($text, $offset, $maxChars);
        $end = $offset + mb_strlen($piece);

        // Prefer to break on a paragraph or line boundary in the last 15% of the chunk.
        if ($end < $total) {
            $window = (int) ($maxChars * 0.15);
            foreach (["\n\n", "\n", '. '] as $sep) {
                $pos = mb_strrpos($piece, $sep);
                if (false !== $pos && $pos >= mb_strlen($piece) - $window) {
                    $piece = mb_substr($piece, 0, $pos + mb_strlen($sep));
                    $end = $offset + mb_strlen($piece);
                    break;
                }
            }
        }

        return new DocumentChunk($item, $piece, $offset, $total, $end < $total ? $end : null);
    }
}
