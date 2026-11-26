<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Last line of defence: scrubs anything that looks like a credential from log
 * messages and context before they are written, even if a caller (or a
 * library) accidentally logs one.
 */
final class RedactingProcessor implements ProcessorInterface
{
    private const SENSITIVE_KEYS = '/(secret|password|passwd|token|authorization|api[_-]?key|cookie|downloadurl|client_assertion|code_verifier)/i';

    /** @var list<string> */
    private array $literals;

    /**
     * @param list<string> $secrets exact values to mask (client secret, MCP token)
     */
    public function __construct(#[\SensitiveParameter] array $secrets = [])
    {
        $this->literals = array_values(array_filter($secrets, static fn (string $s): bool => \strlen($s) >= 8));
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->scrubString($record->message),
            context: $this->scrub($record->context),
            extra: $this->scrub($record->extra),
        );
    }

    public function scrubString(string $value): string
    {
        foreach ($this->literals as $secret) {
            $value = str_replace($secret, '[REDACTED]', $value);
        }

        // Bearer tokens and JWTs
        $value = preg_replace('/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i', 'Bearer [REDACTED]', $value) ?? $value;
        $value = preg_replace('/eyJ[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]*/', '[REDACTED_JWT]', $value) ?? $value;
        // OAuth form fields and SharePoint pre-authenticated URLs
        $value = preg_replace('/(client_secret|access_token|refresh_token|tempauth)=[^&\s"]+/i', '$1=[REDACTED]', $value) ?? $value;

        return $value;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private function scrub(array $data, int $depth = 0): array
    {
        foreach ($data as $key => $value) {
            if (\is_string($key) && preg_match(self::SENSITIVE_KEYS, $key)) {
                $data[$key] = '[REDACTED]';
            } elseif (\is_string($value)) {
                $data[$key] = $this->scrubString($value);
            } elseif (\is_array($value) && $depth < 5) {
                $data[$key] = $this->scrub($value, $depth + 1);
            } elseif ($value instanceof \Throwable) {
                $data[$key] = $value::class . ': ' . $this->scrubString($value->getMessage());
            }
        }

        return $data;
    }
}
