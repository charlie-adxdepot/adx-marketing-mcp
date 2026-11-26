<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Auth;

/**
 * Persists the Graph access token between requests (PHP's built-in server and
 * PHP-FPM start each request with empty memory).
 *
 * The token is encrypted with AES-256-GCM using a key derived from the client
 * secret, so the cache file is useless to anyone who does not already hold the
 * secret. Corrupt or undecryptable files are treated as a cache miss.
 */
final class EncryptedFileTokenCache implements TokenCacheInterface
{
    private const CIPHER = 'aes-256-gcm';

    private readonly string $key;

    public function __construct(
        private readonly string $directory,
        #[\SensitiveParameter] string $secretMaterial,
    ) {
        $this->key = hash('sha256', 'adx-mcp-token-cache|' . $secretMaterial, true);
    }

    public function get(string $key): ?array
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        $data = false === $raw ? null : json_decode($raw, true);
        if (!\is_array($data) || !isset($data['iv'], $data['tag'], $data['ct'], $data['expires_at'])) {
            return null;
        }

        $plain = openssl_decrypt(
            (string) base64_decode((string) $data['ct'], true),
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            (string) base64_decode((string) $data['iv'], true),
            (string) base64_decode((string) $data['tag'], true),
        );

        if (false === $plain || '' === $plain) {
            return null;
        }

        return ['token' => $plain, 'expires_at' => (int) $data['expires_at']];
    }

    public function set(string $key, #[\SensitiveParameter] string $token, int $expiresAt): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            return; // Caching is an optimisation; never fail the request over it.
        }

        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($token, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if (false === $ct) {
            return;
        }

        $payload = json_encode([
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ct' => base64_encode($ct),
            'expires_at' => $expiresAt,
        ], JSON_THROW_ON_ERROR);

        $path = $this->path($key);
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (false !== @file_put_contents($tmp, $payload, LOCK_EX)) {
            @chmod($tmp, 0600);
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
            }
        }
    }

    public function delete(string $key): void
    {
        @unlink($this->path($key));
    }

    private function path(string $key): string
    {
        return rtrim($this->directory, '\\/') . DIRECTORY_SEPARATOR . 'token_' . hash('sha256', $key) . '.json';
    }
}
