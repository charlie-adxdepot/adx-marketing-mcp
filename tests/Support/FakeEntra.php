<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Tests\Support;

use Firebase\JWT\JWT;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A controlled stand-in for Microsoft Entra ID's token-signing side:
 *  - mints RS256 v2.0-style access tokens with the test key
 *  - serves {tenant}/v2.0/.well-known/openid-configuration and the JWKS
 *    (keys published without "alg", exactly as Entra does)
 *
 * All identifiers are fake. The signing keys are test fixtures trusted by nothing.
 */
final class FakeEntra
{
    public const TENANT = 'aaaaaaaa-1111-2222-3333-444444444444';
    public const OTHER_TENANT = 'bbbbbbbb-5555-6666-7777-888888888888';
    public const API_CLIENT_ID = 'cccccccc-0000-0000-0000-000000000001';
    public const OTHER_API_CLIENT_ID = 'dddddddd-0000-0000-0000-000000000002';
    public const CLAUDE_CLIENT_ID = 'eeeeeeee-0000-0000-0000-000000000003';
    public const KID = 'test-key-1';
    public const ISSUER = 'https://login.microsoftonline.com/' . self::TENANT . '/v2.0';
    public const ADVERTISED_SCOPE = 'api://' . self::API_CLIENT_ID . '/access_as_user';

    public int $discoveryRequests = 0;
    public int $jwksRequests = 0;

    /** OAuth-mode environment overrides matching this fake tenant. */
    public static function env(array $overrides = []): array
    {
        return $overrides + [
            'MCP_AUTH_MODE' => 'oauth',
            'MCP_OAUTH_ISSUER' => self::ISSUER,
            'MCP_OAUTH_AUDIENCE' => self::API_CLIENT_ID,
            'MCP_OAUTH_SCOPE_CLAIM' => 'scp',
            'MCP_OAUTH_REQUIRED_SCOPES' => 'access_as_user',
            'MCP_OAUTH_ADVERTISED_SCOPES' => self::ADVERTISED_SCOPE,
        ];
    }

    public static function privateKey(string $name = 'test-signing-key'): string
    {
        return (string) file_get_contents(\dirname(__DIR__) . '/Fixtures/oauth/' . $name . '.pem');
    }

    /**
     * A token that passes every check, with claims overridable per test.
     *
     * @param array<string, mixed> $claims merged over the defaults; a null value removes the claim
     */
    public function token(array $claims = [], string $key = 'test-signing-key', string $kid = self::KID, string $alg = 'RS256'): string
    {
        $now = time();
        $payload = array_filter($claims + [
            'aud' => self::API_CLIENT_ID,
            'iss' => self::ISSUER,
            'iat' => $now - 60,
            'nbf' => $now - 60,
            'exp' => $now + 3600,
            'azp' => self::CLAUDE_CLIENT_ID,
            'azpacr' => '1',
            'name' => 'Test Marketer',
            'oid' => '99999999-aaaa-bbbb-cccc-000000000001',
            'preferred_username' => 'marketer@example.test',
            'scp' => 'access_as_user',
            'sub' => 'subject-1',
            'tid' => self::TENANT,
            'ver' => '2.0',
        ], static fn ($v): bool => null !== $v);

        return JWT::encode($payload, self::privateKey($key), $alg, $kid);
    }

    public function handle(RequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if ('/' . self::TENANT . '/v2.0/.well-known/openid-configuration' === $path) {
            ++$this->discoveryRequests;

            return $this->json([
                'issuer' => self::ISSUER,
                'authorization_endpoint' => 'https://login.microsoftonline.com/' . self::TENANT . '/oauth2/v2.0/authorize',
                'token_endpoint' => 'https://login.microsoftonline.com/' . self::TENANT . '/oauth2/v2.0/token',
                'jwks_uri' => 'https://login.microsoftonline.com/' . self::TENANT . '/discovery/v2.0/keys',
                'response_types_supported' => ['code', 'id_token', 'code id_token', 'id_token token'],
                'id_token_signing_alg_values_supported' => ['RS256'],
            ]);
        }

        if ('/' . self::TENANT . '/discovery/v2.0/keys' === $path) {
            ++$this->jwksRequests;
            $details = openssl_pkey_get_details(openssl_pkey_get_private(self::privateKey()));
            $b64 = static fn (string $bin): string => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

            return $this->json(['keys' => [[
                'kty' => 'RSA',
                'use' => 'sig',
                'kid' => self::KID,
                'n' => $b64($details['rsa']['n']),
                'e' => $b64($details['rsa']['e']),
            ]]]);
        }

        return new Response(404, ['Content-Type' => 'application/json'], '{"error":"not_found"}');
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($data, JSON_UNESCAPED_SLASHES));
    }
}
