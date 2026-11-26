<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Auth;

/**
 * Raised when an access token cannot be obtained from Microsoft Entra ID.
 * Messages never include the client secret or any token.
 */
final class AuthenticationException extends \RuntimeException
{
}
