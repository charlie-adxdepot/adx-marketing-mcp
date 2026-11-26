<?php

declare(strict_types=1);

namespace Adx\MarketingMcp;

use Adx\MarketingMcp\Auth\AccessPolicyValidator;
use Adx\MarketingMcp\Auth\EncryptedFileTokenCache;
use Adx\MarketingMcp\Auth\MicrosoftGraphAuth;
use Adx\MarketingMcp\Auth\StaticTokenValidator;
use Adx\MarketingMcp\Config\Config;
use Adx\MarketingMcp\Documents\DocumentService;
use Adx\MarketingMcp\Documents\ExtractedTextCache;
use Adx\MarketingMcp\Documents\TextExtractor;
use Adx\MarketingMcp\Graph\GraphClient;
use Adx\MarketingMcp\Logging\LoggerFactory;
use Adx\MarketingMcp\MCP\HttpApplication;
use Adx\MarketingMcp\MCP\KnowledgeTools;
use Adx\MarketingMcp\MCP\ServerFactory;
use Adx\MarketingMcp\SharePoint\FolderIndex;
use Adx\MarketingMcp\SharePoint\SharePointRepository;
use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use Mcp\Server\Transport\Http\OAuth\JwtTokenValidator;
use Mcp\Server\Transport\Http\OAuth\ProtectedResourceMetadata;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

/**
 * Composition root: wires configuration, logging, Graph, SharePoint and MCP.
 */
final class Kernel
{
    private ?GraphClient $graph = null;
    private ?SharePointRepository $repository = null;

    public function __construct(
        public readonly Config $config,
        public readonly LoggerInterface $logger,
        private readonly ClientInterface $http,
    ) {
    }

    public static function boot(string $projectRoot): self
    {
        if (is_file($projectRoot . '/.env')) {
            Dotenv::createImmutable($projectRoot)->safeLoad();
        }

        // Precedence: real process environment (set by the host / shell) > .env file.
        // Dotenv writes to $_ENV/$_SERVER, but cannot always see process variables
        // (e.g. under `php -S` with variables_order=GPCS), so getenv() is applied last.
        /** @var array<string, string> $env */
        $env = array_merge($_SERVER, $_ENV, getenv() ?: []);
        $config = Config::fromEnvironment(array_filter($env, 'is_string'), $projectRoot);
        $logger = LoggerFactory::create($config);

        $http = new Client([
            'timeout' => 30,
            'connect_timeout' => 10,
            'headers' => ['User-Agent' => 'adx-marketing-mcp/' . ServerFactory::SERVER_VERSION],
        ]);

        return new self($config, $logger, $http);
    }

    public function graph(): GraphClient
    {
        return $this->graph ??= new GraphClient(
            $this->http,
            new MicrosoftGraphAuth(
                $this->http,
                $this->config->tenantId,
                $this->config->clientId,
                $this->config->clientSecret(),
                new EncryptedFileTokenCache($this->cacheDir(), $this->config->clientSecret() . '|' . $this->config->clientId),
                $this->logger,
            ),
            $this->logger,
        );
    }

    public function repository(): SharePointRepository
    {
        return $this->repository ??= new SharePointRepository(
            $this->graph(),
            new FolderIndex(
                $this->graph(),
                $this->config->driveId,
                $this->config->rootFolderId,
                $this->cacheDir(),
                $this->config->indexTtlSeconds,
                $this->config->maxIndexItems,
                $this->config->maxFolderDepth,
                $this->logger,
            ),
            $this->config->driveId,
            $this->logger,
        );
    }

    public function tools(): KnowledgeTools
    {
        $documents = new DocumentService(
            $this->repository(),
            $this->graph(),
            new TextExtractor(),
            $this->config->maxDocumentBytes,
            // Leave room for the metadata header and continuation footer.
            max(500, $this->config->maxResponseChars - 1500),
            $this->logger,
            new ExtractedTextCache(
                $this->cacheDir() . DIRECTORY_SEPARATOR . 'text',
                $this->config->clientSecret() . '|' . $this->config->clientId,
                $this->config->textCacheTtlSeconds,
            ),
        );

        return new KnowledgeTools($this->repository(), $documents, $this->config->maxResponseChars, $this->logger);
    }

    public function httpApplication(): HttpApplication
    {
        $factory = new Psr17Factory();
        $mcpPath = parse_url($this->config->publicUrl, PHP_URL_PATH) ?: '/mcp';

        return new HttpApplication(
            fn () => ServerFactory::create($this->tools(), $this->logger),
            $this->tokenValidator(),
            new ProtectedResourceMetadata(
                $this->config->publicUrl,
                Config::AUTH_MODE_OAUTH === $this->config->authMode ? [$this->config->oauthAuthorizationServerUrl()] : [$this->localIssuerPlaceholder()],
                // Advertise scopes in the form a client must REQUEST them (Entra: api://<id>/access_as_user),
                // not the bare `scp` values the server checks. These also feed the WWW-Authenticate challenge.
                Config::AUTH_MODE_OAUTH === $this->config->authMode ? $this->config->oauthScopesToAdvertise() : null,
                'ADX Marketing Knowledge',
            ),
            '/' . trim($mcpPath, '/'),
            $this->config->allowedHosts,
            $factory,
            $factory,
            $this->logger,
        );
    }

    private function tokenValidator(): AuthorizationTokenValidatorInterface
    {
        if (Config::AUTH_MODE_OAUTH === $this->config->authMode) {
            $factory = new Psr17Factory();

            /** @var \Psr\Http\Client\ClientInterface $psr18 */
            $psr18 = $this->http;

            // Authenticity (signature/JWKS, iss, aud, exp, alg) is the SDK's job; acceptance policy
            // (tenant, client, scopes, roles) is ours. MCP_AUTH_TOKEN is never consulted in this mode.
            return new AccessPolicyValidator(
                JwtTokenValidator::fromIssuer(
                    $this->config->oauthIssuer,
                    $this->config->oauthAudiences(),
                    new FilesystemAdapter('jwks', 3600, $this->cacheDir()),
                    $psr18,
                    $factory,
                    ['RS256'],
                    $this->config->oauthScopeClaim,
                    null,
                    60,
                ),
                $this->config->oauthRequiredScopes,
                $this->config->oauthScopesToAdvertise(),
                $this->config->oauthRequiredRoles,
                $this->config->oauthTenantId(),
                $this->config->oauthAllowedClientIds,
                $this->logger,
            );
        }

        return new StaticTokenValidator($this->config->authToken());
    }

    /**
     * RFC 9728 metadata requires an authorization server entry. In static-token
     * mode there is none; we advertise the server's own origin so the document
     * is well formed, and clients are expected to send the static header.
     */
    private function localIssuerPlaceholder(): string
    {
        $parts = parse_url($this->config->publicUrl);

        return sprintf('%s://%s%s', $parts['scheme'] ?? 'http', $parts['host'] ?? '127.0.0.1', isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private function cacheDir(): string
    {
        return rtrim($this->config->storagePath, '\\/') . DIRECTORY_SEPARATOR . 'cache';
    }
}
