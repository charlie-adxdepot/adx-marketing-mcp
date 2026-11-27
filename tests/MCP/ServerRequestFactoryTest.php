<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Tests\MCP;

use Adx\MarketingMcp\MCP\ServerRequestFactory;
use Adx\MarketingMcp\Tests\Support\FakeEntra;
use Adx\MarketingMcp\Tests\Support\FakeGraph;
use Adx\MarketingMcp\Tests\Support\TestKernel;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression: in production every request failed with "Invalid Host header"
 * because nyholm/psr7-server produced "Host: mcp.example.com, mcp.example.com".
 */
final class ServerRequestFactoryTest extends TestCase
{
    /** @return array<string, string> what PHP-FPM puts in $_SERVER behind nginx */
    private static function server(string $host, string $method = 'GET', string $uri = '/.well-known/oauth-protected-resource/mcp'): array
    {
        return [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'HTTPS' => 'on',
            'SERVER_PORT' => '443',
            'HTTP_HOST' => $host,
        ];
    }

    public function testTheUnderlyingLibraryReallyDuplicatesTheHostHeader(): void
    {
        // Documents why the workaround exists; if this starts failing, the library was fixed.
        $f = new Psr17Factory();
        $raw = (new ServerRequestCreator($f, $f, $f, $f))->fromArrays(self::server('mcp.example.test'), ['Host' => 'mcp.example.test']);

        self::assertSame(['mcp.example.test', 'mcp.example.test'], $raw->getHeader('Host'));
    }

    /** @return iterable<string, array{string}> */
    public static function hosts(): iterable
    {
        yield 'production domain, no port' => ['mcp.example.test'];
        yield 'with port' => ['127.0.0.1:8080'];
        yield 'IPv6 with port' => ['[::1]:8080'];
    }

    #[DataProvider('hosts')]
    public function testHostHeaderHasExactlyOneValue(string $host): void
    {
        $request = ServerRequestFactory::fromArrays(self::server($host), ['Host' => $host]);

        self::assertSame([$host], $request->getHeader('Host'));
    }

    public function testOtherHeadersIncludingAuthorizationAreKept(): void
    {
        $request = ServerRequestFactory::fromArrays(self::server('mcp.example.test', 'POST', '/mcp'), [
            'Host' => 'mcp.example.test',
            'Authorization' => 'Bearer abc',
            'Content-Type' => 'application/json',
        ]);

        self::assertSame('Bearer abc', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('/mcp', $request->getUri()->getPath());
    }

    /**
     * The production scenario end to end: production OAuth config on a real-looking
     * domain, request built the way public/index.php builds it.
     */
    public function testProductionDomainPassesTheDnsRebindingCheck(): void
    {
        $storage = TestKernel::tempDir();
        try {
            $graph = new FakeGraph();
            $kernel = TestKernel::create($graph, $storage, FakeEntra::env([
                'APP_ENV' => 'production',
                'APP_DEBUG' => 'false',
                'MCP_PUBLIC_URL' => 'https://mcp.example.test/mcp',
                'MCP_ALLOWED_HOSTS' => 'mcp.example.test',
                'MCP_OAUTH_ADVERTISED_SCOPES' => 'https://mcp.example.test/mcp/access_as_user offline_access',
            ]));
            $app = $kernel->httpApplication();

            $metadata = $app->handle(ServerRequestFactory::fromArrays(self::server('mcp.example.test'), ['Host' => 'mcp.example.test']));
            self::assertSame(200, $metadata->getStatusCode(), (string) $metadata->getBody());
            self::assertSame('https://mcp.example.test/mcp', json_decode((string) $metadata->getBody(), true)['resource']);

            $noToken = $app->handle(ServerRequestFactory::fromArrays(self::server('mcp.example.test', 'POST', '/mcp'), ['Host' => 'mcp.example.test', 'Content-Type' => 'application/json'], [], [], null, [], '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'));
            self::assertSame(401, $noToken->getStatusCode());
            self::assertStringContainsString('resource_metadata=', $noToken->getHeaderLine('WWW-Authenticate'));

            // The protection still works: a foreign Host is still refused.
            $foreign = $app->handle(ServerRequestFactory::fromArrays(self::server('attacker.example'), ['Host' => 'attacker.example']));
            self::assertSame(403, $foreign->getStatusCode());
        } finally {
            TestKernel::removeDir($storage);
        }
    }
}
