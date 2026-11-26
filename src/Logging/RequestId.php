<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Logging;

/**
 * One short random identifier per HTTP request. It is added to every log line and
 * returned to the caller in the X-Request-Id response header, so a client-side
 * problem report can be matched to the server log.
 */
final class RequestId
{
    private static ?string $id = null;

    public static function get(): string
    {
        return self::$id ??= bin2hex(random_bytes(6));
    }

    /** Start a new request scope (the web server already does this per request; tests and CLI loops need it). */
    public static function reset(): void
    {
        self::$id = null;
    }
}
