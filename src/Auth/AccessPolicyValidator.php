<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Auth;

use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Application authorization policy layered on top of the SDK's JwtTokenValidator.
 *
 * The SDK validator proves a token is authentic: signature (JWKS), issuer,
 * audience, expiry and algorithm. It does NOT decide whether this application
 * should accept it. This decorator adds, in order:
 *
 *  1. Tenant pinning   - `tid` must equal the tenant in the configured issuer
 *                        (defence in depth; the issuer check already pins it).
 *  2. Client allow-list - optional: `azp` (the OAuth client the token was issued
 *                        to, e.g. Claude's registration) must be approved.
 *  3. Required scopes  - every MCP_OAUTH_REQUIRED_SCOPES value must be in the
 *                        token's scope claim (`scp` for Entra). App-only tokens
 *                        (client credentials) have no `scp` and are refused.
 *  4. Required roles   - optional: every MCP_OAUTH_REQUIRED_ROLES value must be
 *                        in the `roles` claim (Entra app roles, assigned to the
 *                        staff security group).
 *
 * Failure responses are built so Claude can recover correctly: a missing scope
 * returns 403 insufficient_scope advertising the scopes in their
 * *authorization-request* form (e.g. api://<id>/access_as_user), not the bare
 * claim value Entra puts in `scp` and would not accept in a request.
 *
 * Tokens are never logged. Users are identified in logs only by a short hash.
 */
final class AccessPolicyValidator implements AuthorizationTokenValidatorInterface
{
    /**
     * @param list<string> $requiredScopes   values as they appear in the token's scope claim
     * @param list<string> $advertisedScopes values a client must request (sent in WWW-Authenticate)
     * @param list<string> $requiredRoles    values that must appear in the `roles` claim
     * @param list<string> $allowedClientIds approved `azp`/`appid` values; empty = any client
     */
    public function __construct(
        private readonly AuthorizationTokenValidatorInterface $inner,
        private readonly array $requiredScopes,
        private readonly array $advertisedScopes,
        private readonly array $requiredRoles = [],
        private readonly ?string $expectedTenantId = null,
        private readonly array $allowedClientIds = [],
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        if ([] === $requiredScopes) {
            // Fail closed: an empty policy would accept any authentic token for this audience.
            throw new \InvalidArgumentException('At least one required scope must be configured for OAuth mode.');
        }
    }

    public function validate(#[\SensitiveParameter] string $accessToken): AuthorizationResult
    {
        $result = $this->inner->validate($accessToken);
        $token = $result->getAccessToken();

        if (!$result->isAllowed() || null === $token) {
            if (503 !== $result->getStatusCode()) {
                $this->logger->notice('OAuth token rejected', ['reason' => $result->getErrorDescription() ?? $result->getError() ?? 'no_token', 'status' => $result->getStatusCode()]);
            } else {
                $this->logger->warning('OAuth token validation unavailable (issuer discovery or JWKS fetch failed)');
            }

            return $result;
        }

        $claims = $token->getClaims();
        $user = self::userRef($claims);

        if (null !== $this->expectedTenantId && strtolower((string) ($claims['tid'] ?? '')) !== strtolower($this->expectedTenantId)) {
            return $this->deny($user, 'tenant_mismatch', AuthorizationResult::unauthorized('invalid_token', 'Token was issued by an unauthorised tenant.'));
        }

        if ([] !== $this->allowedClientIds) {
            // v2.0 tokens carry `azp`; v1.0 tokens carry `appid`.
            $client = strtolower((string) ($claims['azp'] ?? $claims['appid'] ?? ''));
            if (!\in_array($client, array_map('strtolower', $this->allowedClientIds), true)) {
                return $this->deny($user, 'client_not_allowed', AuthorizationResult::unauthorized('invalid_token', 'Token was issued to an unapproved client application.'));
            }
        }

        $missingScopes = array_values(array_diff($this->requiredScopes, $token->getScopes()));
        if ([] !== $missingScopes) {
            return $this->deny($user, 'missing_scope', AuthorizationResult::forbidden(
                'insufficient_scope',
                'The access token lacks a required scope.',
                [] !== $this->advertisedScopes ? $this->advertisedScopes : $this->requiredScopes,
            ), ['missing' => $missingScopes]);
        }

        if ([] !== $this->requiredRoles) {
            $roles = \is_array($claims['roles'] ?? null) ? array_filter($claims['roles'], 'is_string') : [];
            $missingRoles = array_values(array_diff($this->requiredRoles, $roles));
            if ([] !== $missingRoles) {
                // Not insufficient_scope: requesting more scopes cannot fix this; an administrator must assign the user.
                return $this->deny($user, 'missing_role', AuthorizationResult::forbidden(
                    'access_denied',
                    'The signed-in user is not authorised to use this connector.',
                ), ['missing' => $missingRoles]);
            }
        }

        $this->logger->info('OAuth token accepted', ['user' => $user]);

        return AuthorizationResult::allow(new AccessToken($token->getScopes(), $claims));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function deny(string $user, string $reason, AuthorizationResult $result, array $context = []): AuthorizationResult
    {
        $this->logger->notice('OAuth token refused by access policy', ['reason' => $reason, 'user' => $user] + $context);

        return $result;
    }

    /**
     * Stable, non-reversible reference to the user for correlating log lines
     * without writing names, emails or object IDs to the log.
     *
     * @param array<string, mixed> $claims
     */
    private static function userRef(array $claims): string
    {
        $id = (string) ($claims['oid'] ?? $claims['sub'] ?? '');

        return '' === $id ? 'unknown' : 'u_' . substr(hash('sha256', $id), 0, 12);
    }
}
