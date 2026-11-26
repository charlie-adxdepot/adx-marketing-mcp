<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\MCP;

use Adx\MarketingMcp\Logging\RequestId;
use Mcp\Server\Server;
use Mcp\Server\Transport\Http\Middleware\AuthorizationMiddleware;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtectedResourceMetadataMiddleware;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * HTTP front controller.
 *
 *   GET  /health                                   unauthenticated liveness probe (no details)
 *   GET  /.well-known/oauth-protected-resource...  RFC 9728 metadata (served by the SDK middleware)
 *   *    /mcp                                      MCP Streamable HTTP endpoint (bearer auth required)
 */
final class HttpApplication
{
    /**
     * @param \Closure(): Server $serverFactory built lazily so /health never touches Graph
     * @param list<string>       $allowedHosts
     */
    public function __construct(
        private readonly \Closure $serverFactory,
        private readonly AuthorizationTokenValidatorInterface $validator,
        private readonly ProtectedResourceMetadata $resourceMetadata,
        private readonly string $mcpPath,
        private readonly array $allowedHosts,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestId::reset();

        return $this->dispatch($request)->withHeader('X-Request-Id', RequestId::get());
    }

    private function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        $path = '/' . trim($request->getUri()->getPath(), '/');

        if ('/health' === $path && 'GET' === $request->getMethod()) {
            return $this->json(200, ['status' => 'ok']);
        }

        $isMetadata = str_starts_with($path, '/.well-known/oauth-protected-resource');
        if ($path !== $this->mcpPath && !$isMetadata) {
            return $this->json(404, ['error' => 'not_found']);
        }

        $middleware = [
            new CorsMiddleware(),
            new DnsRebindingProtectionMiddleware($this->allowedHosts),
            new ProtectedResourceMetadataMiddleware($this->resourceMetadata, $this->responseFactory, $this->streamFactory),
            new AuthorizationMiddleware($this->validator, $this->resourceMetadata, null, $this->responseFactory, $this->streamFactory, logger: $this->logger),
        ];

        $transport = new StreamableHttpTransport($request, $this->responseFactory, $this->streamFactory, $this->logger, $middleware);

        try {
            $response = ($this->serverFactory)()->run($transport);
        } catch (\Throwable $e) {
            $this->logger->error('Unhandled server error', ['exception_class' => $e::class, 'message' => $e->getMessage()]);

            return $this->json(500, ['error' => 'internal_error']);
        }

        if (!$response instanceof ResponseInterface) {
            return $this->json(500, ['error' => 'internal_error']);
        }

        return $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string, mixed> $data */
    private function json(int $status, array $data): ResponseInterface
    {
        return $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody($this->streamFactory->createStream((string) json_encode($data)));
    }
}
