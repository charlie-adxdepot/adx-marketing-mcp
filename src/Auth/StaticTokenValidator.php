<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Auth;

use Mcp\Server\Authorization\AccessToken;
use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;

/**
 * Validates a single shared bearer token (MCP_AUTH_TOKEN) in constant time.
 *
 * Intended for local testing, and for Claude's "Request headers" connector
 * option where an organisation has access to it. Not a substitute for OAuth
 * in a multi-user production deployment (no per-user identity or revocation).
 */
final class StaticTokenValidator implements AuthorizationTokenValidatorInterface
{
    public function __construct(
        #[\SensitiveParameter] private readonly string $expectedToken,
    ) {
        if (\strlen($expectedToken) < 32) {
            throw new \InvalidArgumentException('The static MCP token must be at least 32 characters.');
        }
    }

    public function validate(#[\SensitiveParameter] string $accessToken): AuthorizationResult
    {
        if (!hash_equals($this->expectedToken, $accessToken)) {
            return AuthorizationResult::unauthorized('invalid_token', 'The access token is invalid.');
        }

        return AuthorizationResult::allow(new AccessToken(['knowledge.read'], ['sub' => 'static-token-client']));
    }
}
