<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Logging;

use Adx\MarketingMcp\Config\Config;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

final class LoggerFactory
{
    public static function create(Config $config): LoggerInterface
    {
        $level = Level::fromName($config->logLevel);
        $logger = new Logger('adx-mcp');

        $logDir = rtrim($config->storagePath, '\\/') . DIRECTORY_SEPARATOR . 'log';
        if (is_dir($logDir) || @mkdir($logDir, 0700, true)) {
            $file = new StreamHandler($logDir . DIRECTORY_SEPARATOR . 'app-' . date('Y-m-d') . '.log', $level);
            $file->setFormatter(new JsonFormatter());
            $logger->pushHandler($file);
        }

        // Also to stderr so messages appear in the `php -S` console.
        $logger->pushHandler(new StreamHandler('php://stderr', $level));

        $logger->pushProcessor(new RequestIdProcessor());
        $logger->pushProcessor(new RedactingProcessor([$config->clientSecret(), $config->authToken()]));

        return $logger;
    }
}
