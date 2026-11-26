<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Tests\Documents;

use Adx\MarketingMcp\Documents\ExtractedTextCache;
use Adx\MarketingMcp\Tests\Support\FakeGraph;
use Adx\MarketingMcp\Tests\Support\TestKernel;
use PHPUnit\Framework\TestCase;

final class ExtractedTextCacheTest extends TestCase
{
    private string $dir;
    private int $now = 1_000_000;

    protected function setUp(): void
    {
        $this->dir = TestKernel::tempDir();
    }

    protected function tearDown(): void
    {
        TestKernel::removeDir($this->dir);
    }

    private function cache(string $secret = 'secret-material', int $ttl = 3600, int $maxEntries = 200, int $maxChars = 5_000_000, ?string $dir = null): ExtractedTextCache
    {
        return new ExtractedTextCache($dir ?? $this->dir, $secret, $ttl, $maxEntries, $maxChars, fn (): int => $this->now);
    }

    public function testRoundTripsTextWithoutStoringItInPlaintext(): void
    {
        $cache = $this->cache();
        $cache->set('doc-1', "Confidential pricing: DECK-01 costs 49.95\nSecond line é ü 日本");

        self::assertSame("Confidential pricing: DECK-01 costs 49.95\nSecond line é ü 日本", $cache->get('doc-1'));

        $files = glob($this->dir . '/text_*.json') ?: [];
        self::assertCount(1, $files);
        self::assertStringNotContainsString('Confidential', (string) file_get_contents($files[0]));
        self::assertStringNotContainsString('49.95', (string) file_get_contents($files[0]));
    }

    public function testAnotherSecretCannotReadIt(): void
    {
        $this->cache('secret-one')->set('doc-1', 'private text');

        self::assertNull($this->cache('secret-two')->get('doc-1'));
    }

    public function testAnEntryCopiedUnderAnotherKeyIsRejected(): void
    {
        $cache = $this->cache();
        $cache->set('doc-1', 'text of document one');
        $cache->set('doc-2', 'text of document two');

        $files = glob($this->dir . '/text_*.json') ?: [];
        self::assertCount(2, $files);
        // Swap the two files' contents: the entry is bound to its key, so neither may decrypt.
        [$a, $b] = $files;
        $contentA = (string) file_get_contents($a);
        file_put_contents($a, (string) file_get_contents($b));
        file_put_contents($b, $contentA);

        self::assertNull($cache->get('doc-1'));
        self::assertNull($cache->get('doc-2'));
    }

    public function testEntriesExpire(): void
    {
        $cache = $this->cache(ttl: 60);
        $cache->set('doc-1', 'text');
        self::assertSame('text', $cache->get('doc-1'));

        $this->now += 61;

        self::assertNull($cache->get('doc-1'));
        self::assertSame([], glob($this->dir . '/text_*.json') ?: [], 'expired file is removed');
    }

    public function testZeroTtlDisablesTheCache(): void
    {
        $cache = $this->cache(ttl: 0);
        $cache->set('doc-1', 'text');

        self::assertFalse($cache->enabled());
        self::assertNull($cache->get('doc-1'));
        self::assertSame([], glob($this->dir . '/text_*.json') ?: []);
    }

    public function testOversizedTextIsNotCached(): void
    {
        $cache = $this->cache(maxChars: 10);
        $cache->set('doc-1', str_repeat('x', 11));

        self::assertNull($cache->get('doc-1'));
    }

    public function testTheCacheStaysBounded(): void
    {
        $cache = $this->cache(maxEntries: 3);
        foreach (range(1, 6) as $i) {
            $cache->set("doc-{$i}", "text {$i}");
            touch($this->dir . '/text_' . hash('sha256', "doc-{$i}") . '.json', $this->now + $i);
        }

        self::assertLessThanOrEqual(3, \count(glob($this->dir . '/text_*.json') ?: []));
        self::assertSame('text 6', $cache->get('doc-6'), 'the newest entry survives');
    }

    public function testCorruptFileIsAMissNotAnError(): void
    {
        $cache = $this->cache();
        $cache->set('doc-1', 'text');
        $file = (glob($this->dir . '/text_*.json') ?: [])[0];
        file_put_contents($file, '{not json');

        self::assertNull($cache->get('doc-1'));
    }

    // --- End to end: the second page of a document must not download the file again ---

    /** @return int how many times the pre-authenticated download host was contacted */
    private static function downloads(FakeGraph $fake): int
    {
        return \count(array_filter($fake->requests, static fn (array $r): bool => str_contains($r['url'], 'adxdepot.sharepoint.com')));
    }

    public function testReadingAnotherPageDoesNotDownloadTheFileAgain(): void
    {
        $storage = TestKernel::tempDir();
        try {
            $fake = new FakeGraph();
            $fake->content['D-GUIDE'] = implode("\n", array_map(static fn ($i) => "Paragraph {$i} " . str_repeat('word ', 30), range(1, 200)));
            $overrides = ['MCP_MAX_RESPONSE_CHARS' => '5000'];

            $first = TestKernel::create($fake, $storage, $overrides)->tools()->getDocument('D-GUIDE');
            self::assertSame(1, self::downloads($fake));
            preg_match('/offset=(\d+)/', $first->content[0]->text, $m);

            // A brand-new Kernel stands in for the next HTTP request (nothing is kept in memory).
            $second = TestKernel::create($fake, $storage, $overrides)->tools()->getDocument('D-GUIDE', (int) $m[1]);

            self::assertSame(1, self::downloads($fake), 'page 2 must come from the cache');
            self::assertStringContainsString('Showing characters ' . $m[1], $second->content[0]->text);
        } finally {
            TestKernel::removeDir($storage);
        }
    }

    public function testAnEditedDocumentIsNeverServedStale(): void
    {
        $storage = TestKernel::tempDir();
        try {
            $fake = new FakeGraph();
            TestKernel::create($fake, $storage)->tools()->getDocument('D-GUIDE');
            self::assertSame(1, self::downloads($fake));

            $fake->content['D-GUIDE'] .= "\nNew rule: always use the orange logo.";
            $text = TestKernel::create($fake, $storage)->tools()->getDocument('D-GUIDE')->content[0]->text;

            self::assertSame(2, self::downloads($fake), 'changed size => different cache key => fresh download');
            self::assertStringContainsString('always use the orange logo', $text);
        } finally {
            TestKernel::removeDir($storage);
        }
    }

    public function testTheCacheNeverBypassesTheScopeCheck(): void
    {
        $storage = TestKernel::tempDir();
        try {
            $fake = new FakeGraph();
            $kernel = TestKernel::create($fake, $storage);
            $kernel->tools()->getDocument('D-GUIDE'); // warm the cache

            $this->expectException(\Mcp\Server\Exception\ToolCallException::class);
            try {
                $kernel->tools()->getDocument('SECRET-FILE');
            } finally {
                foreach ($fake->requests as $r) {
                    self::assertStringNotContainsString('SECRET-FILE', $r['url']);
                }
            }
        } finally {
            TestKernel::removeDir($storage);
        }
    }

    public function testCachedTextIsEncryptedOnDisk(): void
    {
        $storage = TestKernel::tempDir();
        try {
            $fake = new FakeGraph();
            TestKernel::create($fake, $storage)->tools()->getDocument('D-GUIDE');

            $files = glob($storage . '/cache/text/text_*.json') ?: [];
            self::assertCount(1, $files);
            self::assertStringNotContainsString('ADX Orange', (string) file_get_contents($files[0]));
        } finally {
            TestKernel::removeDir($storage);
        }
    }
}
