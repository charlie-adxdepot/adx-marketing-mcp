<?php

declare(strict_types=1);

/*
 * ADX Marketing Knowledge - MCP Streamable HTTP endpoint.
 *
 * Local:   php -S 127.0.0.1:8080 -t public public/index.php
 * Hosted:  point the web server's document root at /public and route all requests here.
 */

use Adx\MarketingMcp\Config\ConfigurationException;
use Adx\MarketingMcp\Kernel;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ResponseInterface;

$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');

$emit = static function (ResponseInterface $response): void {
    if (!headers_sent()) {
        http_response_code($response->getStatusCode());
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $i => $value) {
                header(sprintf('%s: %s', $name, $value), 0 === $i);
            }
        }
    }

    $body = $response->getBody();
    if ($body->isSeekable()) {
        $body->rewind();
    }
    while (!$body->eof()) {
        echo $body->read(8192);
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }
};

$factory = new Psr17Factory();

$failure = static fn (string $code) => $factory->createResponse(500)
    ->withHeader('Content-Type', 'application/json')
    ->withHeader('Cache-Control', 'no-store')
    ->withBody($factory->createStream((string) json_encode(['error' => $code])));

try {
    $kernel = Kernel::boot($projectRoot);
} catch (ConfigurationException $e) {
    // Configuration messages name missing keys only, never values.
    error_log('[adx-mcp] Configuration error: ' . $e->getMessage());
    $emit($failure('server_misconfigured'));

    return;
} catch (\Throwable $e) {
    // Class name only: the message could in principle echo a configuration value.
    error_log('[adx-mcp] Startup failure: ' . $e::class);
    $emit($failure('internal_error'));

    return;
}

try {
    $request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();
    $response = $kernel->httpApplication()->handle($request);
} catch (\Throwable $e) {
    // Building the application can fail too (e.g. an invalid auth setting). Never show a blank page or a trace.
    $kernel->logger->error('Unhandled application error', ['exception_class' => $e::class, 'message' => $e->getMessage()]);
    $emit($failure('internal_error'));

    return;
}

$emit($response);
