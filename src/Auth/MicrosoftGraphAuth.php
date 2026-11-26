<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Auth;

use Adx\MarketingMcp\Graph\TransportError;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Obtains app-only Microsoft Graph access tokens using the OAuth 2.0 client
 * credentials grant against the existing Entra application
 * "Claude Marketing SharePoint Connector".
 *
 * - Scope is always https://graph.microsoft.com/.default (the permissions the
 *   app was granted, i.e. Files.SelectedOperations.Selected).
 * - Tokens are cached and refreshed shortly before they expire.
 * - Neither the client secret nor any token is ever logged or put in an
 *   exception message.
 */
final class MicrosoftGraphAuth
{
    public const GRAPH_SCOPE = 'https://graph.microsoft.com/.default';

    /** Refresh this many seconds before the real expiry to absorb clock skew. */
    private const EXPIRY_MARGIN_SECONDS = 300;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param (callable(): int)|null $clock returns the current Unix time; injectable for tests
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $tenantId,
        private readonly string $clientId,
        #[\SensitiveParameter] private readonly string $clientSecret,
        private readonly TokenCacheInterface $cache = new InMemoryTokenCache(),
        private readonly LoggerInterface $logger = new NullLogger(),
        ?callable $clock = null,
        private readonly string $authorityHost = 'https://login.microsoftonline.com',
    ) {
        if (!preg_match('/^[A-Za-z0-9.\-]+$/', $tenantId)) {
            throw new AuthenticationException('MICROSOFT_TENANT_ID has an invalid format.');
        }
        $this->clock = null !== $clock ? $clock(...) : time(...);
    }

    /**
     * Returns a valid Graph access token, fetching a new one if needed.
     *
     * @throws AuthenticationException
     */
    public function getAccessToken(bool $forceRefresh = false): string
    {
        $cacheKey = $this->cacheKey();

        if (!$forceRefresh) {
            $cached = $this->cache->get($cacheKey);
            if (null !== $cached && $cached['expires_at'] - self::EXPIRY_MARGIN_SECONDS > ($this->clock)()) {
                return $cached['token'];
            }
        }

        [$token, $expiresAt] = $this->requestToken();
        $this->cache->set($cacheKey, $token, $expiresAt);

        return $token;
    }

    /**
     * Drops the cached token, e.g. after Graph answered 401.
     */
    public function invalidate(): void
    {
        $this->cache->delete($this->cacheKey());
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function requestToken(): array
    {
        $url = sprintf('%s/%s/oauth2/v2.0/token', rtrim($this->authorityHost, '/'), rawurlencode($this->tenantId));

        try {
            $response = $this->http->request('POST', $url, [
                'form_params' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => self::GRAPH_SCOPE,
                    'grant_type' => 'client_credentials',
                ],
                'headers' => ['Accept' => 'application/json'],
                'http_errors' => true,
                'timeout' => 20,
                'connect_timeout' => 10,
            ]);
        } catch (RequestException $e) {
            if (null === $e->getResponse()) {
                // No HTTP response at all: DNS, TLS (e.g. missing CA bundle), proxy or timeout problem.
                $detail = TransportError::describe($e);
                $this->logger->error('Microsoft Entra token request failed (transport)', ['detail' => $detail]);

                throw new AuthenticationException('Could not reach Microsoft Entra ID (' . $detail . ').');
            }

            $status = $e->getResponse()?->getStatusCode() ?? 0;
            $body = $e->getResponse() ? json_decode((string) $e->getResponse()->getBody(), true) : null;
            $error = \is_array($body) ? (string) ($body['error'] ?? 'unknown_error') : 'unknown_error';
            // AADSTS codes are safe to log and very useful for diagnosis (e.g. AADSTS7000215 = bad secret).
            $aadsts = \is_array($body) && isset($body['error_codes'][0]) ? 'AADSTS' . (int) $body['error_codes'][0] : null;

            $this->logger->error('Microsoft Entra token request failed', [
                'status' => $status,
                'error' => $error,
                'aadsts' => $aadsts,
                'correlation_id' => \is_array($body) ? ($body['correlation_id'] ?? null) : null,
            ]);

            throw new AuthenticationException(sprintf(
                'Could not authenticate with Microsoft Entra ID (HTTP %d, %s%s).',
                $status,
                $error,
                null !== $aadsts ? ', ' . $aadsts : '',
            ));
        } catch (GuzzleException $e) {
            $detail = $e instanceof \Throwable ? TransportError::describe($e) : 'unknown transport error';
            $this->logger->error('Microsoft Entra token request failed (network)', ['detail' => $detail]);

            throw new AuthenticationException('Could not reach Microsoft Entra ID (' . $detail . ').');
        }

        $data = json_decode((string) $response->getBody(), true);
        if (!\is_array($data) || !isset($data['access_token']) || !\is_string($data['access_token']) || '' === $data['access_token']) {
            throw new AuthenticationException('Microsoft Entra ID returned an unexpected token response.');
        }

        $expiresIn = isset($data['expires_in']) ? (int) $data['expires_in'] : 3599;
        $this->logger->info('Obtained Microsoft Graph access token', ['expires_in' => $expiresIn]);

        return [$data['access_token'], ($this->clock)() + max(60, $expiresIn)];
    }

    private function cacheKey(): string
    {
        return 'graph|' . $this->tenantId . '|' . $this->clientId;
    }
}
