<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\SharePoint;

/**
 * The item does not exist, or exists but is outside the "Agent Knowledge"
 * folder. Both cases deliberately produce the same message so the connector
 * does not reveal whether out-of-scope items exist.
 */
final class ItemNotFoundException extends \RuntimeException
{
    public static function forId(string $id): self
    {
        return new self(sprintf('No document or folder with ID "%s" was found in Agent Knowledge.', mb_substr($id, 0, 80)));
    }
}
