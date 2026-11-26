<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Tests\SharePoint;

use Adx\MarketingMcp\Auth\MicrosoftGraphAuth;
use Adx\MarketingMcp\Graph\GraphClient;
use Adx\MarketingMcp\SharePoint\FolderIndex;
use Adx\MarketingMcp\Tests\Support\FakeGraph;
use Adx\MarketingMcp\Tests\Support\TestKernel;
use PHPUnit\Framework\TestCase;

final class FolderIndexTest extends TestCase
{
    private string $storage;
    private FakeGraph $fake;
    private int $now = 1_000_000;

    protected function setUp(): void
    {
        $this->storage = TestKernel::tempDir();
        $this->fake = new FakeGraph();
    }

    protected function tearDown(): void
    {
        TestKernel::removeDir($this->storage);
    }

    private function newIndex(int $ttl = 600): FolderIndex
    {
        $client = $this->fake->client();
        $graph = new GraphClient($client, new MicrosoftGraphAuth($client, 'tenant', 'client', FakeGraph::CLIENT_SECRET));

        return new FolderIndex($graph, FakeGraph::DRIVE, FakeGraph::ROOT, $this->storage, $ttl, 5000, 15, clock: fn (): int => $this->now);
    }

    private function graphCalls(): int
    {
        return \count(array_filter($this->fake->requests, static fn (array $r): bool => str_contains($r['url'], 'graph.microsoft.com')));
    }

    public function testAProcessThatWaitedForTheLockAdoptsTheIndexAnotherProcessJustBuilt(): void
    {
        $first = $this->newIndex();
        self::assertNotNull($first->get('D-GUIDE'));
        $callsAfterFirstBuild = $this->graphCalls();
        self::assertGreaterThan(0, $callsAfterFirstBuild);

        // A second "request" (new object, empty memory) asks for a rebuild while a fresh index is on disk.
        $second = $this->newIndex();
        $second->refresh();

        self::assertSame($callsAfterFirstBuild, $this->graphCalls(), 'must not crawl Graph again');
        self::assertNotNull($second->get('D-OLD'));
    }

    public function testAnExpiredIndexIsRebuiltFromGraph(): void
    {
        $index = $this->newIndex(ttl: 600);
        $index->all();
        $before = $this->graphCalls();

        $this->now += 601;
        $index->refresh();

        self::assertGreaterThan($before, $this->graphCalls());
    }

    public function testRefreshReleasesTheLock(): void
    {
        $this->newIndex()->all();

        $lockFiles = glob($this->storage . DIRECTORY_SEPARATOR . 'index_*.json.lock') ?: [];
        self::assertCount(1, $lockFiles);

        $handle = fopen($lockFiles[0], 'c');
        self::assertNotFalse($handle);
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB), 'lock should be free once the build is done');
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public function testWithoutACacheDirectoryItStillBuilds(): void
    {
        $client = $this->fake->client();
        $graph = new GraphClient($client, new MicrosoftGraphAuth($client, 'tenant', 'client', FakeGraph::CLIENT_SECRET));
        $index = new FolderIndex($graph, FakeGraph::DRIVE, FakeGraph::ROOT, null, 600, 5000, 15);

        $index->refresh();

        self::assertNotNull($index->get('D-GUIDE'));
    }
}
