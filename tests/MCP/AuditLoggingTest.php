<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Tests\MCP;

use Adx\MarketingMcp\Kernel;
use Adx\MarketingMcp\Logging\RedactingProcessor;
use Adx\MarketingMcp\Logging\RequestId;
use Adx\MarketingMcp\Logging\RequestIdProcessor;
use Adx\MarketingMcp\Tests\Support\FakeGraph;
use Adx\MarketingMcp\Tests\Support\TestKernel;
use Mcp\Server\Exception\ToolCallException;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

/**
 * The audit trail: what was asked for, by which request, and what happened.
 */
final class AuditLoggingTest extends TestCase
{
    private string $storage;
    private TestHandler $handler;
    private Kernel $kernel;

    protected function setUp(): void
    {
        RequestId::reset();
        $this->storage = TestKernel::tempDir();
        $this->handler = new TestHandler();
        $logger = new Logger('audit-test', [$this->handler], [new RequestIdProcessor(), new RedactingProcessor([FakeGraph::CLIENT_SECRET])]);
        $this->kernel = new Kernel(TestKernel::config($this->storage), $logger, (new FakeGraph())->client());
    }

    protected function tearDown(): void
    {
        TestKernel::removeDir($this->storage);
    }

    /** @return list<LogRecord> */
    private function toolRecords(string $tool): array
    {
        return array_values(array_filter(
            $this->handler->getRecords(),
            static fn (LogRecord $r): bool => ($r->context['tool'] ?? null) === $tool,
        ));
    }

    public function testADocumentReadRecordsTheDocumentId(): void
    {
        $this->kernel->tools()->getDocument('D-GUIDE', 0);

        $records = $this->toolRecords('get_document');
        self::assertCount(1, $records);
        self::assertSame('D-GUIDE', $records[0]->context['document_id']);
        self::assertSame(0, $records[0]->context['offset']);
        self::assertSame(Level::Info, $records[0]->level);

        $extraction = array_values(array_filter($this->handler->getRecords(), static fn (LogRecord $r): bool => 'Extracted document text' === $r->message));
        self::assertCount(1, $extraction);
        self::assertSame('Brand/Brand Guidelines 2026.txt', $extraction[0]->context['path']);
    }

    public function testSearchRecordsLengthAndHashButNotTheQueryText(): void
    {
        $this->kernel->tools()->searchDocuments('Acme Builders pricing');

        $records = $this->toolRecords('search_documents');
        self::assertCount(1, $records);
        self::assertSame(mb_strlen('acme builders pricing'), $records[0]->context['query_len']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $records[0]->context['query_hash']);

        foreach ($this->handler->getRecords() as $r) {
            $flat = $r->message . json_encode($r->context);
            self::assertStringNotContainsStringIgnoringCase('Acme Builders', $flat, 'the query text must not be logged');
        }
    }

    public function testRejectedAccessIsLoggedAsAttemptedNotSilent(): void
    {
        try {
            $this->kernel->tools()->getDocument('SECRET-FILE');
            self::fail('Expected ToolCallException');
        } catch (ToolCallException) {
        }

        $records = $this->toolRecords('get_document');
        self::assertCount(1, $records);
        self::assertSame(Level::Notice, $records[0]->level);
        self::assertSame('Tool call failed', $records[0]->message);
        self::assertSame('SECRET-FILE', $records[0]->context['document_id']);
    }

    public function testFolderListingRecordsTheFolder(): void
    {
        $this->kernel->tools()->listFolder('F-BRAND');
        $this->kernel->tools()->listFolder();

        $records = $this->toolRecords('list_folder');
        self::assertSame(['F-BRAND', '(root)'], array_map(static fn (LogRecord $r): mixed => $r->context['folder_id'], $records));
    }

    public function testClientSuppliedIdsAreClippedBeforeLogging(): void
    {
        try {
            $this->kernel->tools()->getDocument(str_repeat('A', 5000));
        } catch (ToolCallException) {
        }

        self::assertSame(100, mb_strlen($this->toolRecords('get_document')[0]->context['document_id']));
    }

    public function testEveryLogLineCarriesTheSameRequestId(): void
    {
        $this->kernel->tools()->getDocument('D-GUIDE');

        $ids = array_unique(array_map(static fn (LogRecord $r): mixed => $r->extra['request_id'] ?? null, $this->handler->getRecords()));

        self::assertCount(1, $ids);
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', (string) reset($ids));
    }

    public function testANewRequestGetsANewId(): void
    {
        $first = RequestId::get();
        RequestId::reset();

        self::assertNotSame($first, RequestId::get());
    }
}
