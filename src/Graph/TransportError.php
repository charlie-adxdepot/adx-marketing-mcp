<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Graph;

/**
 * Turns a low-level HTTP client failure (no response received) into a short, safe,
 * diagnosable string such as "cURL error 60: SSL certificate problem: unable to get
 * local issuer certificate".
 *
 * Only the transport's own error text is used. Guzzle's exception message is not,
 * because it embeds the full request URL, which for a document download is a
 * pre-authenticated link.
 */
final class TransportError
{
    public static function describe(\Throwable $e): string
    {
        if (method_exists($e, 'getHandlerContext')) {
            /** @var array<string, mixed> $context */
            $context = $e->getHandlerContext();

            if (isset($context['error']) && \is_string($context['error']) && '' !== $context['error']) {
                $errno = isset($context['errno']) && is_numeric($context['errno']) ? (int) $context['errno'] : null;

                return null !== $errno
                    ? sprintf('cURL error %d: %s', $errno, $context['error'])
                    : $context['error'];
            }
        }

        return $e::class;
    }
}
