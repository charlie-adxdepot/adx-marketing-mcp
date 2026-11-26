<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Graph;

use Adx\MarketingMcp\Auth\MicrosoftGraphAuth;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\TransferException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Thin, read-only Microsoft Graph v1.0 REST client.
 *
 * - Adds the app-only bearer token to every request.
 * - Retries throttling / transient errors (429, 503, 504) honouring Retry-After.
 * - On 401 refreshes the token once and retries.
 * - Only follows @odata.nextLink URLs that point back at graph.microsoft.com.
 * - Streams downloads and aborts when they exceed the configured size cap.
 */
final class GraphClient
{
    public const BASE_URL = 'https://graph.microsoft.com/v1.0';

    private const MAX_RETRIES = 3;
    private const MAX_RETRY_AFTER_SECONDS = 10;

    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    /**
     * @param (callable(float): void)|null $sleep injectable for tests
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly MicrosoftGraphAuth $auth,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?callable $sleep = null,
    ) {
        $this->sleep = null !== $sleep ? $sleep(...) : static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };
    }

    /**
     * GET a Graph resource and decode the JSON body.
     *
     * @param string               $pathOrUrl path relative to /v1.0 (e.g. "/drives/x/items/y") or a Graph nextLink URL
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     *
     * @throws GraphException
     */
    public function getJson(string $pathOrUrl, array $query = []): array
    {
        // Note: Guzzle's 'query' option REPLACES the URL's query string, even when empty,
        // which would strip $skiptoken from @odata.nextLink URLs. Only pass it when needed.
        $response = $this->send('GET', $this->resolveUrl($pathOrUrl), [] === $query ? [] : ['query' => $query]);
        $data = json_decode((string) $response->getBody(), true);

        if (!\is_array($data)) {
            throw new GraphException('Microsoft Graph returned a response that was not valid JSON.', $response->getStatusCode());
        }

        return $data;
    }

    /**
     * Follow @odata.nextLink pages until exhausted or $maxItems reached.
     *
     * @param array<string, mixed> $query
     *
     * @return list<array<string, mixed>>
     */
    public function getAllPages(string $path, array $query = [], int $maxItems = 1000): array
    {
        $items = [];
        $next = $path;
        $first = true;

        while (null !== $next && \count($items) < $maxItems) {
            $page = $this->getJson($next, $first ? $query : []);
            $first = false;

            foreach (($page['value'] ?? []) as $item) {
                if (\is_array($item)) {
                    $items[] = $item;
                }
            }

            $next = isset($page['@odata.nextLink']) && \is_string($page['@odata.nextLink']) ? $page['@odata.nextLink'] : null;
        }

        return \array_slice($items, 0, $maxItems);
    }

    /**
     * Download a drive item's binary content, refusing anything larger than $maxBytes.
     *
     * Graph answers /content with a 302 to a short-lived pre-authenticated URL.
     * Guzzle follows it and strips the Authorization header on the cross-host hop.
     * That URL is never logged or returned.
     *
     * @throws GraphException
     */
    public function downloadContent(string $driveId, string $itemId, int $maxBytes): string
    {
        $url = sprintf('%s/drives/%s/items/%s/content', self::BASE_URL, rawurlencode($driveId), rawurlencode($itemId));
        $response = $this->send('GET', $url, ['stream' => true, 'timeout' => 120]);

        $declared = $response->getHeaderLine('Content-Length');
        if ('' !== $declared && (int) $declared > $maxBytes) {
            throw new GraphException(sprintf('Document is larger than the %d MB limit.', intdiv($maxBytes, 1024 * 1024)), 413);
        }

        $body = $response->getBody();
        $buffer = '';
        while (!$body->eof()) {
            $buffer .= $body->read(65536);
            if (\strlen($buffer) > $maxBytes) {
                $body->close();
                throw new GraphException(sprintf('Document is larger than the %d MB limit.', intdiv($maxBytes, 1024 * 1024)), 413);
            }
        }

        return $buffer;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function send(string $method, string $url, array $options): ResponseInterface
    {
        $authRetried = false;
        $attempt = 0;

        while (true) {
            ++$attempt;
            $requestOptions = $options + [
                'timeout' => 30,
                'connect_timeout' => 10,
                'http_errors' => false,
                'allow_redirects' => ['max' => 3, 'strict' => true, 'protocols' => ['https']],
            ];
            $requestOptions['headers'] = [
                'Authorization' => 'Bearer ' . $this->auth->getAccessToken(),
                'Accept' => 'application/json',
            ];

            try {
                $response = $this->http->request($method, $url, $requestOptions);
            } catch (ConnectException $e) {
                $detail = TransportError::describe($e);
                if ($attempt <= self::MAX_RETRIES) {
                    $this->logger->warning('Graph connection failed, retrying', ['attempt' => $attempt, 'detail' => $detail]);
                    ($this->sleep)(min(2 ** $attempt, self::MAX_RETRY_AFTER_SECONDS));
                    continue;
                }

                $this->logger->error('Graph connection failed', ['detail' => $detail]);

                throw new GraphException('Could not connect to Microsoft Graph.', 0, null, null, $e);
            } catch (GuzzleException|TransferException $e) {
                $this->logger->error('Graph request failed (transport)', ['detail' => $e instanceof \Throwable ? TransportError::describe($e) : 'unknown transport error']);

                throw new GraphException('Microsoft Graph request failed.', 0, null, null, $e instanceof \Throwable ? $e : null);
            }

            $status = $response->getStatusCode();

            if ($status >= 200 && $status < 300) {
                return $response;
            }

            if (401 === $status && !$authRetried) {
                $authRetried = true;
                $this->auth->invalidate();
                $this->auth->getAccessToken(true);
                continue;
            }

            if (\in_array($status, [429, 503, 504], true) && $attempt <= self::MAX_RETRIES) {
                $retryAfter = (int) $response->getHeaderLine('Retry-After');
                $delay = $retryAfter > 0 ? min($retryAfter, self::MAX_RETRY_AFTER_SECONDS) : min(2 ** $attempt, self::MAX_RETRY_AFTER_SECONDS);
                $this->logger->warning('Graph throttled or unavailable, retrying', ['status' => $status, 'delay' => $delay, 'attempt' => $attempt]);
                ($this->sleep)((float) $delay);
                continue;
            }

            throw $this->toException($response);
        }
    }

    private function toException(ResponseInterface $response): GraphException
    {
        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getBody(), true);
        $code = \is_array($body) && isset($body['error']['code']) ? (string) $body['error']['code'] : null;
        $requestId = $response->getHeaderLine('request-id') ?: null;

        $this->logger->warning('Graph request failed', ['status' => $status, 'code' => $code, 'request_id' => $requestId]);

        $message = match (true) {
            401 === $status => 'Microsoft Graph rejected the connector credentials.',
            403 === $status => 'Access denied: the connector is not permitted to read this item.',
            404 === $status => 'The requested SharePoint item was not found.',
            429 === $status => 'Microsoft Graph is throttling requests. Please try again shortly.',
            $status >= 500 => 'Microsoft Graph is temporarily unavailable. Please try again shortly.',
            default => sprintf('Microsoft Graph request failed (HTTP %d).', $status),
        };

        return new GraphException($message, $status, $code, $requestId);
    }

    private function resolveUrl(string $pathOrUrl): string
    {
        if (str_starts_with($pathOrUrl, 'https://')) {
            // Only follow links that stay on Graph v1.0 (paging links). Prevents SSRF via crafted nextLinks.
            if (!str_starts_with($pathOrUrl, self::BASE_URL . '/')) {
                throw new GraphException('Refusing to follow a non-Graph URL.');
            }

            return $pathOrUrl;
        }

        return self::BASE_URL . '/' . ltrim($pathOrUrl, '/');
    }
}
