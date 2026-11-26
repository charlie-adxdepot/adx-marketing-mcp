<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Auth;

final class InMemoryTokenCache implements TokenCacheInterface
{
    /** @var array<string, array{token: string, expires_at: int}> */
    private array $items = [];

    public function get(string $key): ?array
    {
        return $this->items[$key] ?? null;
    }

    public function set(string $key, #[\SensitiveParameter] string $token, int $expiresAt): void
    {
        $this->items[$key] = ['token' => $token, 'expires_at' => $expiresAt];
    }

    public function delete(string $key): void
    {
        unset($this->items[$key]);
    }
}
