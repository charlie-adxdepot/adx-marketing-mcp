<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Tests\Auth;

use Adx\MarketingMcp\Auth\AuthenticationException;
use Adx\MarketingMcp\Auth\EncryptedFileTokenCache;
use Adx\MarketingMcp\Auth\InMemoryTokenCache;
use Adx\MarketingMcp\Auth\MicrosoftGraphAuth;
use Adx\MarketingMcp\Tests\Support\FakeGraph;
use Adx\MarketingMcp\Tests\Support\TestKernel;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

final class MicrosoftGraphAuthTest extends TestCase
{
    public function testRequestsTokenWithClientCredentialsAndGraphDefaultScope(): void
    {
        $graph = new FakeGraph();
        $auth = new MicrosoftGraphAuth($graph->client(), 'tenant-id', 'client-id', FakeGraph::CLIENT_SECRET);

        self::assertSame(FakeGraph::ACCESS_TOKEN, $auth->getAccessToken());
        self::assertSame('https://login.microsoftonline.com/tenant-id/oauth2/v2.0/token', $graph->requests[0]['url']);
    }

    public function testCachesTokenUntilShortlyBeforeExpiry(): void
    {
        $graph = new FakeGraph();
        $now = 1_000_000;
        $auth = new MicrosoftGraphAuth($graph->client(), 'tenant', 'client', FakeGraph::CLIENT_SECRET, new InMemoryTokenCache(), clock: static function () use (&$now): int {
            return $now;
        });

        $auth->getAccessToken();
        $auth->getAccessToken();
        self::assertSame(1, $graph->tokenRequests);

        $now += 3599 - 299; // inside the 5-minute refresh margin
        $auth->getAccessToken();
        self::assertSame(2, $graph->tokenRequests);
    }

    public function testFailureMessageNeverContainsSecret(): void
    {
        $graph = new FakeGraph();
        $auth = new MicrosoftGraphAuth($graph->client(), 'tenant', 'client', 'wrong-secret-value-xyz');

        try {
            $auth->getAccessToken();
            self::fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            self::assertStringContainsString('AADSTS7000215', $e->getMessage());
            self::assertStringNotContainsString('wrong-secret-value-xyz', $e->getMessage());
        }
    }

    public function testTransportFailureNamesTheRealCauseWithoutLeakingTheUrl(): void
    {
        // What PHP on Windows produces when no CA bundle is configured.
        $failure = new RequestException(
            'cURL error 60: SSL certificate problem for https://login.microsoftonline.com/t/oauth2/v2.0/token?' . FakeGraph::TEMPAUTH,
            new Request('POST', 'https://login.microsoftonline.com/t/oauth2/v2.0/token'),
            null,
            null,
            ['errno' => 60, 'error' => 'SSL certificate problem: unable to get local issuer certificate'],
        );
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([$failure]))]);
        $auth = new MicrosoftGraphAuth($client, 'tenant', 'client', FakeGraph::CLIENT_SECRET);

        try {
            $auth->getAccessToken();
            self::fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            self::assertStringContainsString('cURL error 60', $e->getMessage());
            self::assertStringContainsString('unable to get local issuer certificate', $e->getMessage());
            self::assertStringNotContainsString('HTTP 0', $e->getMessage());
            self::assertStringNotContainsString('PREAUTH-DOWNLOAD-SECRET', $e->getMessage());
        }
    }

    public function testRejectsMalformedTenantId(): void
    {
        $this->expectException(AuthenticationException::class);
        new MicrosoftGraphAuth((new FakeGraph())->client(), 'evil/../tenant', 'c', 's');
    }

    public function testEncryptedFileCacheRoundTripsAndDoesNotStorePlaintext(): void
    {
        $dir = TestKernel::tempDir();
        try {
            $cache = new EncryptedFileTokenCache($dir, 'secret-material');
            $cache->set('k', FakeGraph::ACCESS_TOKEN, 2_000_000_000);

            self::assertSame(['token' => FakeGraph::ACCESS_TOKEN, 'expires_at' => 2_000_000_000], $cache->get('k'));

            $files = glob($dir . '/*.json') ?: [];
            self::assertCount(1, $files);
            self::assertStringNotContainsString(FakeGraph::ACCESS_TOKEN, (string) file_get_contents($files[0]));

            // A different secret cannot decrypt it.
            self::assertNull((new EncryptedFileTokenCache($dir, 'other-secret'))->get('k'));
        } finally {
            TestKernel::removeDir($dir);
        }
    }
}
