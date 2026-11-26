<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Graph;

/**
 * A Microsoft Graph failure. The message is safe to show to MCP clients:
 * it never contains tokens, secrets or pre-authenticated download URLs.
 */
class GraphException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly ?string $graphErrorCode = null,
        public readonly ?string $requestId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }
}
