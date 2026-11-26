<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Stamps every log record with the current request id.
 */
final class RequestIdProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(extra: $record->extra + ['request_id' => RequestId::get()]);
    }
}
