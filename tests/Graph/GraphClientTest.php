<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Tests\Graph;

use Adx\MarketingMcp\Auth\MicrosoftGraphAuth;
use Adx\MarketingMcp\Graph\GraphClient;
use Adx\MarketingMcp\Graph\GraphException;
use Adx\MarketingMcp\Tests\Support\FakeGraph;
use PHPUnit\Framework\TestCase;

final class GraphClientTest extends TestCase
{
    private FakeGraph $fake;
    /** @var list<float> */
    private array $sleeps = [];

    private function client(): GraphClient
    {
        $this->fake ??= new FakeGraph();
        $http = $this->fake->client();

        return new GraphClient($http, new MicrosoftGraphAuth($http, 'tenant', 'client', FakeGraph::CLIENT_SECRET), sleep: function (float $s): void {
            $this->sleeps[] = $s;
        });
    }

    public function testRetriesThrottledRequestsHonouringRetryAfter(): void
    {
        $client = $this->client();
        $this->fake->failNext = [429, 503];

        $item = $client->getJson('/drives/' . FakeGraph::DRIVE . '/items/' . FakeGraph::ROOT);

        self::assertSame('Agent Knowledge', $item['name']);
        self::assertSame([1.0, 1.0], $this->sleeps);
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        $client = $this->client();
        $this->fake->failNext = [429, 429, 429, 429];

        $this->expectException(GraphException::class);
        $this->expectExceptionMessage('throttling');
        $client->getJson('/drives/' . FakeGraph::DRIVE . '/items/' . FakeGraph::ROOT);
    }

    public function testFollowsPagination(): void
    {
        $client = $this->client();
        $this->fake->add('X1', 'a.txt', FakeGraph::ROOT, false);
        $this->fake->add('X2', 'b.txt', FakeGraph::ROOT, false);

        $children = $client->getAllPages('/drives/' . FakeGraph::DRIVE . '/items/' . FakeGraph::ROOT . '/children');

        self::assertCount(4, $children); // F-BRAND, D-PRICE, X1, X2 across two pages
    }

    public function testRefusesToFollowNonGraphUrls(): void
    {
        $this->expectException(GraphException::class);
        $this->client()->getJson('https://evil.example.com/v1.0/steal');
    }

    public function testDownloadFollowsRedirectWithoutLeakingAuthorizationHeader(): void
    {
        $client = $this->client();
        $bytes = $client->downloadContent(FakeGraph::DRIVE, 'D-GUIDE', 1024 * 1024);

        self::assertStringContainsString('brand voice', $bytes);
        // FakeGraph returns 400 if the Graph bearer token reaches the download host.
    }

    public function testDownloadEnforcesSizeLimit(): void
    {
        $client = $this->client();
        $this->fake->content['D-GUIDE'] = str_repeat('x', 5000);

        $this->expectException(GraphException::class);
        $this->expectExceptionMessage('larger than');
        $client->downloadContent(FakeGraph::DRIVE, 'D-GUIDE', 1000);
    }

    public function testErrorMessagesAreSafe(): void
    {
        try {
            $this->client()->getJson('/drives/' . FakeGraph::DRIVE . '/items/NOPE');
            self::fail('Expected GraphException');
        } catch (GraphException $e) {
            self::assertSame(404, $e->httpStatus);
            self::assertStringNotContainsString(FakeGraph::ACCESS_TOKEN, $e->getMessage());
        }
    }
}
