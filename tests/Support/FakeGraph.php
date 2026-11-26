<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * In-memory stand-in for Entra ID + Microsoft Graph, served through Guzzle's
 * handler stack so redirects, paging and error handling run for real.
 *
 * Tree:
 *   DRIVE-ROOT (outside scope)
 *   ├── SECRET-FILE   "Payroll.xlsx"               <- must never be reachable
 *   └── ROOT          "Agent Knowledge"
 *       ├── F-BRAND   "Brand"
 *       │   ├── D-GUIDE  "Brand Guidelines 2026.txt"
 *       │   └── F-DEEP   "Archive"
 *       │       └── D-OLD "Old Campaign Notes.md"
 *       └── D-PRICE  "Product Pricing.csv"
 */
final class FakeGraph
{
    public const DRIVE = 'b!drive123';
    public const ROOT = 'ROOT';
    public const ACCESS_TOKEN = 'eyJhbGciOiJSUzI1NiJ9.eyJmYWtlIjoiZ3JhcGgtdG9rZW4ifQ.c2lnbmF0dXJl';
    public const CLIENT_SECRET = 'super-secret-client-value-123';
    public const TEMPAUTH = 'tempauth=PREAUTH-DOWNLOAD-SECRET';

    /** @var list<array{method: string, url: string, auth: string}> */
    public array $requests = [];

    public int $tokenRequests = 0;

    /** @var list<int> statuses to return (once each) for the next Graph calls before normal handling */
    public array $failNext = [];

    public bool $searchForbidden = false;

    /** @var (\Closure(RequestInterface): \Psr\Http\Message\ResponseInterface)|null */
    public ?\Closure $oidc = null;

    /** @var array<string, array<string, mixed>> */
    public array $items = [];

    /** @var array<string, string> */
    public array $content = [];

    public function __construct()
    {
        $this->add('DRIVE-ROOT', 'root', null, true);
        $this->add('SECRET-FILE', 'Payroll.xlsx', 'DRIVE-ROOT', false, 'confidential salaries');
        $this->add(self::ROOT, 'Agent Knowledge', 'DRIVE-ROOT', true);
        $this->add('F-BRAND', 'Brand', self::ROOT, true);
        $this->add('D-GUIDE', 'Brand Guidelines 2026.txt', 'F-BRAND', false, "ADX Depot brand voice is confident and practical.\nPrimary colour: ADX Orange.");
        $this->add('F-DEEP', 'Archive', 'F-BRAND', true);
        $this->add('D-OLD', 'Old Campaign Notes.md', 'F-DEEP', false, "# Spring sale\nThe spring campaign focused on decking.");
        $this->add('D-PRICE', 'Product Pricing.csv', self::ROOT, false, "sku,price\nDECK-01,49.95");
    }

    public function add(string $id, string $name, ?string $parent, bool $folder, string $content = ''): void
    {
        $this->items[$id] = [
            'id' => $id,
            'name' => $name,
            'parent' => $parent,
            'folder' => $folder,
        ];
        if (!$folder) {
            $this->content[$id] = $content;
        }
    }

    public function client(): Client
    {
        return new Client(['handler' => HandlerStack::create($this->handler(...))]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function handler(RequestInterface $request, array $options): PromiseInterface
    {
        $uri = $request->getUri();
        $url = (string) $uri;
        $this->requests[] = ['method' => $request->getMethod(), 'url' => $url, 'auth' => $request->getHeaderLine('Authorization')];

        // OIDC discovery and JWKS (GET) are served by FakeEntra when a test plugs it in.
        if ('login.microsoftonline.com' === $uri->getHost() && 'GET' === $request->getMethod() && null !== $this->oidc) {
            return Create::promiseFor(($this->oidc)($request));
        }

        if ('login.microsoftonline.com' === $uri->getHost()) {
            ++$this->tokenRequests;
            parse_str((string) $request->getBody(), $form);
            if (($form['client_secret'] ?? '') !== self::CLIENT_SECRET) {
                return $this->json(401, ['error' => 'invalid_client', 'error_codes' => [7000215], 'correlation_id' => 'corr-1']);
            }

            return $this->json(200, ['access_token' => self::ACCESS_TOKEN, 'expires_in' => 3599, 'token_type' => 'Bearer']);
        }

        if ('adxdepot.sharepoint.com' === $uri->getHost()) {
            // Pre-authenticated download host. Graph's Authorization header must NOT arrive here.
            if ('' !== $request->getHeaderLine('Authorization')) {
                return $this->json(400, ['error' => 'authorization header leaked to download host']);
            }
            parse_str($uri->getQuery(), $q);

            return Create::promiseFor(new Response(200, ['Content-Type' => 'application/octet-stream'], $this->content[$q['id'] ?? ''] ?? ''));
        }

        if ('Bearer ' . self::ACCESS_TOKEN !== $request->getHeaderLine('Authorization')) {
            return $this->json(401, ['error' => ['code' => 'InvalidAuthenticationToken']]);
        }

        if ([] !== $this->failNext) {
            $status = array_shift($this->failNext);

            return Create::promiseFor(new Response($status, ['Retry-After' => '1'], json_encode(['error' => ['code' => 'throttled']])));
        }

        $path = rawurldecode($uri->getPath());
        $prefix = '/v1.0/drives/' . self::DRIVE . '/items/';
        if (!str_starts_with($path, $prefix)) {
            return $this->json(404, ['error' => ['code' => 'itemNotFound']]);
        }
        $rest = substr($path, \strlen($prefix));

        if (preg_match("#^([^/]+)/search\\(q='(.*)'\\)$#", $rest, $m)) {
            if ($this->searchForbidden) {
                return $this->json(403, ['error' => ['code' => 'accessDenied']]);
            }
            $q = mb_strtolower(str_replace("''", "'", $m[2]));
            $hits = [];
            foreach ($this->content as $id => $text) {
                if (str_contains(mb_strtolower($text), $q)) {
                    $hits[] = $this->graphItem($id);
                }
            }

            return $this->json(200, ['value' => $hits]);
        }

        if (preg_match('#^([^/]+)/children$#', $rest, $m)) {
            $children = array_values(array_filter($this->items, static fn (array $i): bool => $i['parent'] === $m[1]));
            parse_str($uri->getQuery(), $query);
            $page = (int) ($query['page'] ?? 0);
            // Two items per page to exercise @odata.nextLink.
            $slice = \array_slice($children, $page * 2, 2);
            $body = ['value' => array_map(fn (array $i): array => $this->graphItem($i['id']), $slice)];
            if (\count($children) > ($page + 1) * 2) {
                $body['@odata.nextLink'] = 'https://graph.microsoft.com' . $prefix . $m[1] . '/children?page=' . ($page + 1);
            }

            return $this->json(200, $body);
        }

        if (preg_match('#^([^/]+)/content$#', $rest, $m)) {
            if (!isset($this->content[$m[1]])) {
                return $this->json(404, ['error' => ['code' => 'itemNotFound']]);
            }

            return Create::promiseFor(new Response(302, ['Location' => 'https://adxdepot.sharepoint.com/_layouts/15/download.aspx?id=' . $m[1] . '&' . self::TEMPAUTH]));
        }

        if (isset($this->items[$rest])) {
            return $this->json(200, $this->graphItem($rest));
        }

        return $this->json(404, ['error' => ['code' => 'itemNotFound']]);
    }

    /** @return array<string, mixed> */
    private function graphItem(string $id): array
    {
        $i = $this->items[$id];
        $children = \count(array_filter($this->items, static fn (array $c): bool => $c['parent'] === $id));
        $data = [
            'id' => $id,
            'name' => $i['name'],
            'size' => \strlen($this->content[$id] ?? ''),
            'webUrl' => 'https://adxdepot.sharepoint.com/sites/marketing/' . rawurlencode($i['name']),
            'lastModifiedDateTime' => '2026-10-01T09:00:00Z',
            'lastModifiedBy' => ['user' => ['displayName' => 'Marketing Team', 'email' => 'should-not-leak@example.com']],
            'parentReference' => ['driveId' => self::DRIVE, 'id' => $i['parent']],
            '@microsoft.graph.downloadUrl' => 'https://adxdepot.sharepoint.com/download?' . self::TEMPAUTH,
        ];
        if ($i['folder']) {
            $data['folder'] = ['childCount' => $children];
        } else {
            $data['file'] = ['mimeType' => 'text/plain'];
        }

        return $data;
    }

    /** @param array<string, mixed> $body */
    private function json(int $status, array $body): PromiseInterface
    {
        return Create::promiseFor(new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body)));
    }
}
