<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Tests\SharePoint;

use Adx\MarketingMcp\SharePoint\ItemNotFoundException;
use Adx\MarketingMcp\SharePoint\SharePointRepository;
use Adx\MarketingMcp\Tests\Support\FakeGraph;
use Adx\MarketingMcp\Tests\Support\TestKernel;
use PHPUnit\Framework\TestCase;

final class SharePointRepositoryTest extends TestCase
{
    private string $storage;
    private FakeGraph $fake;
    private SharePointRepository $repo;

    protected function setUp(): void
    {
        $this->storage = TestKernel::tempDir();
        $this->fake = new FakeGraph();
        $this->repo = TestKernel::create($this->fake, $this->storage)->repository();
    }

    protected function tearDown(): void
    {
        TestKernel::removeDir($this->storage);
    }

    public function testIndexesNestedSubfoldersWithPaths(): void
    {
        $item = $this->repo->require('D-OLD');

        self::assertSame('Brand/Archive/Old Campaign Notes.md', $item->path);
    }

    public function testRejectsItemsOutsideAgentKnowledge(): void
    {
        $this->expectException(ItemNotFoundException::class);
        $this->repo->require('SECRET-FILE');
    }

    public function testRejectsTheDriveRootAndParentFolders(): void
    {
        $this->expectException(ItemNotFoundException::class);
        $this->repo->require('DRIVE-ROOT');
    }

    public function testRejectsMalformedIds(): void
    {
        $this->expectException(ItemNotFoundException::class);
        $this->repo->require("../../items/SECRET-FILE");
    }

    public function testListsRootFoldersFirst(): void
    {
        $names = array_map(static fn ($i) => $i->name, $this->repo->listFolder());

        self::assertSame(['Brand', 'Product Pricing.csv'], $names);
    }

    public function testSearchMatchesNamesAndContentButNeverOutOfScopeFiles(): void
    {
        $byName = $this->repo->search('guidelines');
        self::assertSame('D-GUIDE', $byName[0]['item']->id);

        $byContent = $this->repo->search('decking');
        self::assertSame(['D-OLD'], array_map(static fn ($h) => $h['item']->id, $byContent));
        self::assertSame(['content'], $byContent[0]['matchedOn']);

        // "salaries" only appears in the out-of-scope Payroll file.
        self::assertSame([], $this->repo->search('salaries'));
    }

    public function testSearchFallsBackToNamesWhenGraphSearchIsForbidden(): void
    {
        $this->fake->searchForbidden = true;

        self::assertSame('D-PRICE', $this->repo->search('pricing')[0]['item']->id);
        self::assertSame([], $this->repo->search('decking'));
    }

    public function testFileTypeFilter(): void
    {
        self::assertSame([], $this->repo->search('pricing', 10, ['pdf']));
        self::assertCount(1, $this->repo->search('pricing', 10, ['csv']));
    }

    public function testIndexIsCachedOnDisk(): void
    {
        $this->repo->root();
        $before = \count($this->fake->requests);

        $second = TestKernel::create($this->fake, $this->storage)->repository();
        $second->require('D-OLD');

        $graphCalls = array_filter(\array_slice($this->fake->requests, $before), static fn ($r) => str_contains($r['url'], 'graph.microsoft.com'));
        self::assertCount(0, $graphCalls);
    }
}
