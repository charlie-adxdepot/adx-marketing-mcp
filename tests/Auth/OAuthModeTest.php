<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Tests\Auth;

use Adx\MarketingMcp\Config\ConfigurationException;
use Adx\MarketingMcp\Kernel;
use Adx\MarketingMcp\Logging\RedactingProcessor;
use Adx\MarketingMcp\Tests\Support\FakeEntra;
use Adx\MarketingMcp\Tests\Support\FakeGraph;
use Adx\MarketingMcp\Tests\Support\TestKernel;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * OAuth mode end to end, entirely local:
 *   HTTP request -> SDK AuthorizationMiddleware -> AccessPolicyValidator -> SDK JwtTokenValidator
 *   -> OIDC discovery + JWKS (FakeEntra) -> MCP tools -> SharePoint (FakeGraph)
 *
 * Tokens are signed with test-only fixture keys. No real Entra tenant or Claude is involved.
 */
final class OAuthModeTest extends TestCase
{
    private string $storage;
    private FakeGraph $graph;
    private FakeEntra $entra;
    private TestHandler $logs;

    protected function setUp(): void
    {
        $this->storage = TestKernel::tempDir();
        $this->graph = new FakeGraph();
        $this->entra = new FakeEntra();
        $this->graph->oidc = $this->entra->handle(...);
        $this->logs = new TestHandler();
    }

    protected function tearDown(): void
    {
        TestKernel::removeDir($this->storage);
    }

    /** @param array<string, string> $env */
    private function kernel(array $env = []): Kernel
    {
        $logger = new Logger('oauth-test', [$this->logs], [new RedactingProcessor([FakeGraph::CLIENT_SECRET])]);

        return new Kernel(TestKernel::config($this->storage, FakeEntra::env($env)), $logger, $this->graph->client());
    }

    /** @param array<string, string> $env */
    private function call(?string $token, string $method = 'tools/list', array $params = [], array $env = []): ResponseInterface
    {
        $headers = [
            'Host' => '127.0.0.1:8080',
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
            'MCP-Protocol-Version' => '2025-06-18',
        ];
        if (null !== $token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $body = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);

        $response = $this->kernel($env)->httpApplication()->handle(new ServerRequest('POST', 'http://127.0.0.1:8080/mcp', $headers, $body));
        $this->assertNothingSensitiveLeaked($response, $token);

        return $response;
    }

    private function assertNothingSensitiveLeaked(ResponseInterface $response, ?string $token): void
    {
        $everything = (string) $response->getBody() . json_encode($response->getHeaders());
        foreach ($this->logs->getRecords() as $record) {
            $everything .= $record->message . json_encode($record->context);
        }

        if (null !== $token && '' !== $token) {
            self::assertStringNotContainsString($token, $everything, 'the bearer token must never be echoed or logged');
        }
        self::assertStringNotContainsString(FakeGraph::CLIENT_SECRET, $everything);
        self::assertStringNotContainsString(TestKernel::MCP_TOKEN, $everything);
        self::assertStringNotContainsString('marketer@example.test', $everything, 'user emails are not logged');
    }

    private static function challenge(ResponseInterface $response): string
    {
        return $response->getHeaderLine('WWW-Authenticate');
    }

    // ---------------------------------------------------------------- happy path

    public function testValidEntraTokenIsAcceptedAndToolsWork(): void
    {
        $token = $this->entra->token();

        $list = $this->call($token);
        self::assertSame(200, $list->getStatusCode(), (string) $list->getBody());
        self::assertStringContainsString('search_documents', (string) $list->getBody());

        $call = $this->call($token, 'tools/call', ['name' => 'get_document', 'arguments' => ['document_id' => 'D-GUIDE']]);
        self::assertStringContainsString('ADX Orange', (string) $call->getBody());

        self::assertSame(1, $this->entra->discoveryRequests, 'issuer discovered via {issuer}/.well-known/openid-configuration');
    }

    public function testJwksAndDiscoveryAreCachedAcrossRequests(): void
    {
        $token = $this->entra->token();
        $this->call($token);
        $this->call($token);
        $this->call($token);

        self::assertSame(1, $this->entra->discoveryRequests);
        self::assertSame(1, $this->entra->jwksRequests);
    }

    public function testSuccessfulAuthIsLoggedWithAHashedUserReferenceOnly(): void
    {
        $this->call($this->entra->token());

        $accepted = array_values(array_filter($this->logs->getRecords(), static fn ($r) => 'OAuth token accepted' === $r->message));
        self::assertCount(1, $accepted);
        self::assertMatchesRegularExpression('/^u_[0-9a-f]{12}$/', $accepted[0]->context['user']);
    }

    // ---------------------------------------------------------------- authentication failures (401)

    public function testMissingTokenGets401WithResourceMetadataAndAdvertisedScope(): void
    {
        $response = $this->call(null);

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('resource_metadata="http://127.0.0.1:8080/.well-known/oauth-protected-resource/mcp"', self::challenge($response));
        // The request-form scope, which a client can send to Entra.
        self::assertStringContainsString('scope="' . FakeEntra::ADVERTISED_SCOPE . '"', self::challenge($response));
    }

    /** @return iterable<string, array{\Closure(FakeEntra): string, string}> */
    public static function rejectedTokens(): iterable
    {
        yield 'invalid signature (signed by another key)' => [static fn (FakeEntra $e) => $e->token([], 'attacker-signing-key'), 'Token validation failed'];
        yield 'tampered payload' => [static function (FakeEntra $e): string {
            [$h, , $s] = explode('.', $e->token());
            $evil = rtrim(strtr(base64_encode((string) json_encode(['aud' => FakeEntra::API_CLIENT_ID, 'iss' => FakeEntra::ISSUER, 'exp' => time() + 3600, 'scp' => 'access_as_user', 'tid' => FakeEntra::TENANT])), '+/', '-_'), '=');

            return "{$h}.{$evil}.{$s}";
        }, 'Token validation failed'];
        yield 'expired' => [static fn (FakeEntra $e) => $e->token(['exp' => time() - 3600, 'iat' => time() - 7200, 'nbf' => time() - 7200]), 'expired'];
        yield 'not yet valid' => [static fn (FakeEntra $e) => $e->token(['nbf' => time() + 3600]), 'not yet valid'];
        yield 'no expiry' => [static fn (FakeEntra $e) => $e->token(['exp' => null]), 'no expiration'];
        yield 'wrong issuer (another tenant)' => [static fn (FakeEntra $e) => $e->token(['iss' => 'https://login.microsoftonline.com/' . FakeEntra::OTHER_TENANT . '/v2.0', 'tid' => FakeEntra::OTHER_TENANT]), 'issuer mismatch'];
        yield 'v1.0 issuer' => [static fn (FakeEntra $e) => $e->token(['iss' => 'https://sts.windows.net/' . FakeEntra::TENANT . '/', 'ver' => '1.0']), 'issuer mismatch'];
        yield 'token for another API (audience)' => [static fn (FakeEntra $e) => $e->token(['aud' => FakeEntra::OTHER_API_CLIENT_ID]), 'audience mismatch'];
        yield 'audience as api:// URI' => [static fn (FakeEntra $e) => $e->token(['aud' => 'api://' . FakeEntra::API_CLIENT_ID]), 'audience mismatch'];
        yield 'Microsoft Graph token' => [static fn (FakeEntra $e) => $e->token(['aud' => '00000003-0000-0000-c000-000000000000']), 'audience mismatch'];
        yield 'right issuer, foreign tid claim' => [static fn (FakeEntra $e) => $e->token(['tid' => FakeEntra::OTHER_TENANT]), 'unauthorised tenant'];
        yield 'HS256 algorithm confusion' => [static fn (FakeEntra $e) => \Firebase\JWT\JWT::encode(['aud' => FakeEntra::API_CLIENT_ID, 'iss' => FakeEntra::ISSUER, 'exp' => time() + 60], 'shared-secret-guess-0123456789abcdef', 'HS256', FakeEntra::KID), 'algorithm is not accepted'];
        yield 'unknown key id' => [static fn (FakeEntra $e) => $e->token([], 'test-signing-key', 'unknown-kid'), 'signing key could not be resolved'];
        yield 'static MCP token in OAuth mode' => [static fn () => TestKernel::MCP_TOKEN, 'malformed'];
        yield 'garbage' => [static fn () => 'not.a.jwt', 'malformed'];
    }

    /** @param \Closure(FakeEntra): string $makeToken */
    #[DataProvider('rejectedTokens')]
    public function testInvalidTokensAreRejectedWith401(\Closure $makeToken, string $reason): void
    {
        $response = $this->call($makeToken($this->entra));

        self::assertSame(401, $response->getStatusCode(), (string) $response->getBody());
        self::assertStringContainsString('error="invalid_token"', self::challenge($response));
        self::assertStringContainsStringIgnoringCase($reason, self::challenge($response));
        self::assertStringNotContainsString('ADX Orange', (string) $response->getBody());
    }

    public function testRejectedRequestsNeverReachSharePoint(): void
    {
        $this->call($this->entra->token(['aud' => FakeEntra::OTHER_API_CLIENT_ID]), 'tools/call', ['name' => 'list_folder', 'arguments' => []]);

        foreach ($this->graph->requests as $r) {
            self::assertStringNotContainsString('graph.microsoft.com', $r['url']);
        }
    }

    // ---------------------------------------------------------------- authorization failures (403)

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function insufficientScopeTokens(): iterable
    {
        yield 'no scp claim (app-only / client-credentials token)' => [['scp' => null, 'roles' => ['Files.Read.All']]];
        yield 'different scope' => [['scp' => 'User.Read']];
        yield 'scope only in the wrong claim' => [['scp' => null, 'scope' => 'access_as_user']];
        yield 'prefix lookalike' => [['scp' => 'access_as_user_admin']];
    }

    /** @param array<string, mixed> $claims */
    #[DataProvider('insufficientScopeTokens')]
    public function testMissingRequiredScopeIs403WithRequestFormScope(array $claims): void
    {
        $response = $this->call($this->entra->token($claims));

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('error="insufficient_scope"', self::challenge($response));
        // Must be the api://... form; the bare name would make Claude ask Entra for a Graph scope.
        self::assertStringContainsString('scope="' . FakeEntra::ADVERTISED_SCOPE . '"', self::challenge($response));
        self::assertStringNotContainsString('scope="access_as_user"', self::challenge($response));
    }

    public function testMultipleRequiredScopesMustAllBePresent(): void
    {
        $env = ['MCP_OAUTH_REQUIRED_SCOPES' => 'access_as_user,Knowledge.Search', 'MCP_OAUTH_ADVERTISED_SCOPES' => FakeEntra::ADVERTISED_SCOPE . ' api://' . FakeEntra::API_CLIENT_ID . '/Knowledge.Search'];

        self::assertSame(403, $this->call($this->entra->token(['scp' => 'access_as_user']), env: $env)->getStatusCode());
        self::assertSame(200, $this->call($this->entra->token(['scp' => 'openid access_as_user Knowledge.Search']), env: $env)->getStatusCode());
    }

    public function testRequiredAppRoleBlocksUnassignedUsers(): void
    {
        $env = ['MCP_OAUTH_REQUIRED_ROLES' => 'MCP.Access'];

        $unassigned = $this->call($this->entra->token(), env: $env);
        self::assertSame(403, $unassigned->getStatusCode());
        self::assertStringContainsString('error="access_denied"', self::challenge($unassigned));

        self::assertSame(403, $this->call($this->entra->token(['roles' => ['Some.Other.Role']]), env: $env)->getStatusCode());
        self::assertSame(200, $this->call($this->entra->token(['roles' => ['MCP.Access']]), env: $env)->getStatusCode());
    }

    public function testClientAllowListRejectsTokensIssuedToOtherApps(): void
    {
        $env = ['MCP_OAUTH_ALLOWED_CLIENT_IDS' => FakeEntra::CLAUDE_CLIENT_ID];

        self::assertSame(200, $this->call($this->entra->token(), env: $env)->getStatusCode());

        $other = $this->call($this->entra->token(['azp' => FakeEntra::OTHER_API_CLIENT_ID]), env: $env);
        self::assertSame(401, $other->getStatusCode());
        self::assertStringContainsString('unapproved client', self::challenge($other));
    }

    public function testPolicyRefusalsAreLoggedWithReason(): void
    {
        $this->call($this->entra->token(['scp' => 'User.Read']));

        $refusals = array_values(array_filter($this->logs->getRecords(), static fn ($r) => 'OAuth token refused by access policy' === $r->message));
        self::assertSame('missing_scope', $refusals[0]->context['reason'] ?? null);
    }

    // ---------------------------------------------------------------- discovery / metadata

    public function testProtectedResourceMetadataAdvertisesEntraAndRequestFormScopes(): void
    {
        $response = $this->kernel()->httpApplication()->handle(new ServerRequest('GET', 'http://127.0.0.1:8080/.well-known/oauth-protected-resource/mcp', ['Host' => '127.0.0.1:8080']));
        $data = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('http://127.0.0.1:8080/mcp', $data['resource']);
        self::assertSame([FakeEntra::ISSUER], $data['authorization_servers']);
        self::assertSame([FakeEntra::ADVERTISED_SCOPE], $data['scopes_supported']);
        self::assertSame(['header'], $data['bearer_methods_supported']);
    }

    public function testAuthorizationServerCanPointAtAnIntermediaryWhileTokensStayEntraValidated(): void
    {
        $broker = 'https://auth.example.com';
        $kernel = $this->kernel(['MCP_OAUTH_AUTHORIZATION_SERVER' => $broker]);
        $metadata = json_decode((string) $kernel->httpApplication()->handle(new ServerRequest('GET', 'http://127.0.0.1:8080/.well-known/oauth-protected-resource/mcp', ['Host' => '127.0.0.1:8080']))->getBody(), true);

        self::assertSame([$broker], $metadata['authorization_servers']);
        self::assertSame(200, $this->call($this->entra->token(), env: ['MCP_OAUTH_AUTHORIZATION_SERVER' => $broker])->getStatusCode());
    }

    public function testDiscoveryOutageFailsClosedWith503(): void
    {
        $this->graph->oidc = static fn () => new \GuzzleHttp\Psr7\Response(500);

        self::assertSame(503, $this->call($this->entra->token())->getStatusCode());
    }

    // ---------------------------------------------------------------- configuration

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function incompleteOrWrongConfig(): iterable
    {
        yield 'no issuer' => [['MCP_OAUTH_ISSUER' => ''], 'MCP_OAUTH_ISSUER'];
        yield 'no audience' => [['MCP_OAUTH_AUDIENCE' => ''], 'MCP_OAUTH_AUDIENCE'];
        yield 'no required scopes' => [['MCP_OAUTH_REQUIRED_SCOPES' => ''], 'MCP_OAUTH_REQUIRED_SCOPES'];
        yield 'no advertised scopes for Entra' => [['MCP_OAUTH_ADVERTISED_SCOPES' => ''], 'MCP_OAUTH_ADVERTISED_SCOPES'];
        yield 'multi-tenant "common" issuer' => [['MCP_OAUTH_ISSUER' => 'https://login.microsoftonline.com/common/v2.0'], 'tenant-specific'];
        yield 'organizations issuer' => [['MCP_OAUTH_ISSUER' => 'https://login.microsoftonline.com/organizations/v2.0'], 'tenant-specific'];
        yield 'v1 issuer' => [['MCP_OAUTH_ISSUER' => 'https://sts.windows.net/' . FakeEntra::TENANT . '/'], 'v1.0'];
        yield 'issuer without /v2.0' => [['MCP_OAUTH_ISSUER' => 'https://login.microsoftonline.com/' . FakeEntra::TENANT], 'tenant-specific'];
        yield 'http issuer' => [['MCP_OAUTH_ISSUER' => 'http://idp.example.com'], 'https://'];
        yield 'audience as api:// URI' => [['MCP_OAUTH_AUDIENCE' => 'api://' . FakeEntra::API_CLIENT_ID], 'Application (client) ID GUID'];
        yield 'scope claim "scope" for Entra' => [['MCP_OAUTH_SCOPE_CLAIM' => 'scope'], '"scp"'];
        yield 'full scope as required scope' => [['MCP_OAUTH_REQUIRED_SCOPES' => FakeEntra::ADVERTISED_SCOPE], 'bare scope names'];
        yield 'bare scope as advertised scope' => [['MCP_OAUTH_ADVERTISED_SCOPES' => 'access_as_user'], 'full scope'];
        yield 'non-GUID client allow-list' => [['MCP_OAUTH_ALLOWED_CLIENT_IDS' => 'claude'], 'GUID'];
        yield 'placeholder left in' => [['MCP_OAUTH_AUDIENCE' => 'REPLACE_WITH_API_CLIENT_ID'], 'REPLACE_'];
    }

    /** @param array<string, string> $env */
    #[DataProvider('incompleteOrWrongConfig')]
    public function testIncompleteOrWrongOAuthConfigurationRefusesToStart(array $env, string $expected): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($expected);

        TestKernel::config($this->storage, FakeEntra::env($env));
    }

    public function testNonEntraIssuersKeepGenericBehaviour(): void
    {
        $config = TestKernel::config($this->storage, [
            'MCP_AUTH_MODE' => 'oauth',
            'MCP_OAUTH_ISSUER' => 'https://auth.example.com',
            'MCP_OAUTH_AUDIENCE' => 'https://mcp.example.com/mcp',
            'MCP_OAUTH_REQUIRED_SCOPES' => 'mcp:read',
        ]);

        self::assertNull($config->oauthTenantId());
        self::assertSame(['mcp:read'], $config->oauthScopesToAdvertise());
    }

    // ---------------------------------------------------------------- static mode unchanged

    public function testStaticModeStillWorksAndIgnoresJwts(): void
    {
        $kernel = new Kernel(TestKernel::config($this->storage), new Logger('t'), $this->graph->client());
        $request = static fn (string $token) => new ServerRequest('POST', 'http://127.0.0.1:8080/mcp', [
            'Host' => '127.0.0.1:8080', 'Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json',
            'MCP-Protocol-Version' => '2025-06-18', 'Authorization' => 'Bearer ' . $token,
        ], '{"jsonrpc":"2.0","id":1,"method":"tools/list"}');

        self::assertSame(200, $kernel->httpApplication()->handle($request(TestKernel::MCP_TOKEN))->getStatusCode());
        self::assertSame(401, $kernel->httpApplication()->handle($request($this->entra->token()))->getStatusCode(), 'a valid Entra JWT is not a static token');
    }
}
