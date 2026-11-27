<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\MCP;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Builds the PSR-7 request for the current HTTP request.
 *
 * Works around nyholm/psr7-server: ServerRequestCreator::fromArrays() first
 * creates the request with a Host header derived from the URI, then *adds* the
 * incoming Host header, so the request ends up with two values
 * ("mcp.example.com, mcp.example.com"). The SDK's DNS-rebinding check then
 * rejects every request with "Invalid Host header".
 *
 * Locally this was masked: with a port ("127.0.0.1:8080, 127.0.0.1:8080") the
 * check only reads the part before the first colon. On a real domain without a
 * port the whole duplicated value is compared and fails.
 *
 * The fix sets Host to the single value the client actually sent (HTTP_HOST),
 * so the DNS-rebinding check still judges the real header.
 */
final class ServerRequestFactory
{
    public static function fromGlobals(): ServerRequestInterface
    {
        return self::normaliseHost(self::creator()->fromGlobals(), $_SERVER);
    }

    /**
     * Same as fromGlobals(), from explicit arrays (used by tests).
     *
     * @param array<string, mixed>      $server
     * @param array<string, string>     $headers
     * @param array<string, mixed>      $cookie
     * @param array<string, mixed>      $get
     * @param array<string, mixed>|null $post
     * @param array<string, mixed>      $files
     * @param resource|string|null      $body
     */
    public static function fromArrays(array $server, array $headers = [], array $cookie = [], array $get = [], ?array $post = null, array $files = [], $body = null): ServerRequestInterface
    {
        return self::normaliseHost(self::creator()->fromArrays($server, $headers, $cookie, $get, $post, $files, $body), $server);
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function normaliseHost(ServerRequestInterface $request, array $server): ServerRequestInterface
    {
        $host = isset($server['HTTP_HOST']) && \is_string($server['HTTP_HOST']) && '' !== $server['HTTP_HOST']
            ? $server['HTTP_HOST']
            : ($request->getHeader('Host')[0] ?? null);

        return null === $host ? $request : $request->withHeader('Host', $host);
    }

    private static function creator(): ServerRequestCreator
    {
        $factory = new Psr17Factory();

        return new ServerRequestCreator($factory, $factory, $factory, $factory);
    }
}
