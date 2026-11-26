<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Auth;

interface TokenCacheInterface
{
    /** @return array{token: string, expires_at: int}|null */
    public function get(string $key): ?array;

    public function set(string $key, string $token, int $expiresAt): void;

    public function delete(string $key): void;
}
