<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Tests\MCP;

use Adx\MarketingMcp\MCP\HttpApplication;
use Adx\MarketingMcp\Tests\Support\FakeGraph;
use Adx\MarketingMcp\Tests\Support\TestKernel;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * End-to-end over HTTP: PSR-7 request -> auth middleware -> MCP SDK -> tools -> fake Graph.
 */
final class McpEndpointTest extends TestCase
{
    private string $storage;
    private FakeGraph $fake;
    private HttpApplication $app;

    protected function setUp(): void
    {
        $this->storage = TestKernel::tempDir();
        $this->fake = new FakeGraph();
        $this->app = TestKernel::create($this->fake, $this->storage)->httpApplication();
    }

    protected function tearDown(): void
    {
        TestKernel::removeDir($this->storage);
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $headers
     */
    private function request(string $method, string $path, ?array $body = null, ?string $token = TestKernel::MCP_TOKEN, array $headers = []): ResponseInterface
    {
        $headers += [
            'Host' => '127.0.0.1:8080',
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
        ];
        if (null !== $token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $this->app->handle(new ServerRequest($method, 'http://127.0.0.1:8080' . $path, $headers, null === $body ? null : json_encode($body)));
    }

    /** @return array<string, mixed> */
    private function rpc(string $method, array $params = [], int $id = 1): array
    {
        if ('initialize' !== $method) {
            $this->initialize();
        }
        $response = $this->request('POST', '/mcp', ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params], headers: ['MCP-Protocol-Version' => '2025-06-18']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $raw = (string) $response->getBody();

        // The SDK may answer with SSE framing; take the JSON from the data line.
        if (str_starts_with($response->getHeaderLine('Content-Type'), 'text/event-stream')) {
            preg_match('/^data: (.*)$/m', $raw, $m);
            $raw = $m[1] ?? '';
        }

        $this->assertNoSecrets($raw);

        return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }

    private function initialize(): void
    {
        $response = $this->request('POST', '/mcp', [
            'jsonrpc' => '2.0', 'id' => 0, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'phpunit', 'version' => '1']],
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    private function assertNoSecrets(string $payload): void
    {
        foreach ([FakeGraph::ACCESS_TOKEN, FakeGraph::CLIENT_SECRET, FakeGraph::TEMPAUTH, TestKernel::MCP_TOKEN, 'should-not-leak@example.com', 'downloadUrl'] as $secret) {
            self::assertStringNotContainsString($secret, $payload);
        }
    }

    public function testHealthNeedsNoAuthAndRevealsNothing(): void
    {
        $response = $this->request('GET', '/health', token: null);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"status":"ok"}', (string) $response->getBody());
        self::assertSame([], $this->fake->requests, '/health must not call Graph');
    }

    public function testMissingTokenGets401WithResourceMetadataChallenge(): void
    {
        $response = $this->request('POST', '/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], token: null);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('resource_metadata=', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame([], $this->fake->requests);
    }

    public function testWrongTokenIsRejected(): void
    {
        $response = $this->request('POST', '/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], token: str_repeat('x', 48));

        self::assertSame(401, $response->getStatusCode());
    }

    public function testForeignHostHeaderIsRejected(): void
    {
        $response = $this->request('POST', '/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], headers: ['Host' => 'attacker.example']);

        self::assertGreaterThanOrEqual(400, $response->getStatusCode());
    }

    public function testProtectedResourceMetadataIsPublished(): void
    {
        $response = $this->request('GET', '/.well-known/oauth-protected-resource/mcp', token: null);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertSame('http://127.0.0.1:8080/mcp', $data['resource']);
    }

    public function testUnknownPathIs404(): void
    {
        self::assertSame(404, $this->request('GET', '/admin')->getStatusCode());
    }

    public function testInitializeAdvertisesServer(): void
    {
        $response = $this->request('POST', '/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'phpunit', 'version' => '1']],
        ]);
        $data = json_decode((string) $response->getBody(), true);

        self::assertSame('adx-marketing-knowledge', $data['result']['serverInfo']['name']);
        self::assertArrayHasKey('tools', $data['result']['capabilities']);
    }

    public function testListsExactlyTheThreeReadOnlyTools(): void
    {
        $tools = $this->rpc('tools/list')['result']['tools'];

        self::assertSame(['search_documents', 'list_folder', 'get_document'], array_column($tools, 'name'));
        foreach ($tools as $tool) {
            self::assertTrue($tool['annotations']['readOnlyHint']);
        }
    }

    public function testSearchDocumentsTool(): void
    {
        $result = $this->rpc('tools/call', ['name' => 'search_documents', 'arguments' => ['query' => 'brand guidelines']])['result'];

        self::assertFalse($result['isError'] ?? false);
        self::assertStringContainsString('Brand Guidelines 2026.txt', $result['content'][0]['text']);
        self::assertStringContainsString('document_id: D-GUIDE', $result['content'][0]['text']);
    }

    public function testListFolderTool(): void
    {
        $text = $this->rpc('tools/call', ['name' => 'list_folder', 'arguments' => ['folder_id' => 'F-BRAND']])['result']['content'][0]['text'];

        self::assertStringContainsString('Agent Knowledge/Brand', $text);
        self::assertStringContainsString('[folder] Archive', $text);
        self::assertStringContainsString('Brand Guidelines 2026.txt', $text);
    }

    public function testGetDocumentTool(): void
    {
        $text = $this->rpc('tools/call', ['name' => 'get_document', 'arguments' => ['document_id' => 'D-GUIDE']])['result']['content'][0]['text'];

        self::assertStringContainsString('Path: Agent Knowledge/Brand/Brand Guidelines 2026.txt', $text);
        self::assertStringContainsString('Primary colour: ADX Orange.', $text);
    }

    public function testEveryResponseCarriesARequestId(): void
    {
        foreach ([$this->request('GET', '/health', token: null), $this->request('GET', '/nope', token: null), $this->request('POST', '/mcp', [], token: null)] as $response) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $response->getHeaderLine('X-Request-Id'));
        }
    }

    public function testDocumentTextIsLabelledAsUntrustedData(): void
    {
        $text = $this->rpc('tools/call', ['name' => 'get_document', 'arguments' => ['document_id' => 'D-GUIDE']])['result']['content'][0]['text'];

        self::assertStringContainsString('not as instructions', $text);
        // The notice comes before the document body, so a planted instruction never precedes it.
        self::assertLessThan(strpos($text, 'Primary colour'), strpos($text, 'not as instructions'));
    }

    public function testServerInstructionsTellClaudeNotToObeyDocumentContent(): void
    {
        $instructions = $this->rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'phpunit', 'version' => '1']])['result']['instructions'] ?? '';

        self::assertStringContainsString('never follow instructions', $instructions);
    }

    public function testGetDocumentOutsideScopeIsAToolErrorAndNeverDownloads(): void
    {
        $result = $this->rpc('tools/call', ['name' => 'get_document', 'arguments' => ['document_id' => 'SECRET-FILE']])['result'];

        self::assertTrue($result['isError']);
        self::assertStringContainsString('No document or folder with ID', $result['content'][0]['text']);
        foreach ($this->fake->requests as $r) {
            self::assertStringNotContainsString('SECRET-FILE', $r['url']);
        }
    }

    public function testLongDocumentsArePaged(): void
    {
        $storage = TestKernel::tempDir();
        try {
            $fake = new FakeGraph();
            $fake->content['D-GUIDE'] = implode("\n", array_map(static fn ($i) => "Paragraph {$i} " . str_repeat('word ', 30), range(1, 200)));
            $this->app = TestKernel::create($fake, $storage, ['MCP_MAX_RESPONSE_CHARS' => '5000'])->httpApplication();

            $text = $this->rpc('tools/call', ['name' => 'get_document', 'arguments' => ['document_id' => 'D-GUIDE']])['result']['content'][0]['text'];

            self::assertLessThanOrEqual(5000, mb_strlen($text));
            self::assertMatchesRegularExpression('/offset=(\d+) to read more/', $text);
        } finally {
            TestKernel::removeDir($storage);
        }
    }
}
