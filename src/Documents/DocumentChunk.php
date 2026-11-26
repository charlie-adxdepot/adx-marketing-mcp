<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Documents;

use Adx\MarketingMcp\SharePoint\DriveItem;

final class DocumentChunk
{
    public function __construct(
        public readonly DriveItem $item,
        public readonly string $text,
        public readonly int $offset,
        public readonly int $totalChars,
        public readonly ?int $nextOffset,
    ) {
    }

    public function isPartial(): bool
    {
        return 0 !== $this->offset || null !== $this->nextOffset;
    }
}
