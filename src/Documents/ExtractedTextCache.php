<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Documents;

/**
 * Short-lived, encrypted on-disk cache of extracted document text.
 *
 * Reading page 2 of a long document should not download and parse the whole file
 * again. Entries are keyed by drive item + last-modified time + size, so an edited
 * file is never served stale. Text is encrypted with AES-256-GCM (key derived from
 * the client secret, entry bound to its key as additional authenticated data), so a
 * copied cache file is unreadable without the secret. Callers must only consult the
 * cache AFTER the scope check has passed.
 */
final class ExtractedTextCache
{
    private const CIPHER = 'aes-256-gcm';

    private readonly string $key;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    public function __construct(
        private readonly string $directory,
        #[\SensitiveParameter] string $secretMaterial,
        private readonly int $ttlSeconds = 3600,
        private readonly int $maxEntries = 200,
        private readonly int $maxChars = 5_000_000,
        ?callable $clock = null,
    ) {
        $this->key = hash('sha256', 'adx-mcp-text-cache|' . $secretMaterial, true);
        $this->clock = null !== $clock ? $clock(...) : time(...);
    }

    public function enabled(): bool
    {
        return $this->ttlSeconds > 0;
    }

    public function get(string $key): ?string
    {
        if (!$this->enabled()) {
            return null;
        }

        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($path), true);
        if (!\is_array($data) || !isset($data['iv'], $data['tag'], $data['ct'], $data['expires_at'])) {
            return null;
        }

        if ((int) $data['expires_at'] <= ($this->clock)()) {
            @unlink($path);

            return null;
        }

        $plain = openssl_decrypt(
            (string) base64_decode((string) $data['ct'], true),
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            (string) base64_decode((string) $data['iv'], true),
            (string) base64_decode((string) $data['tag'], true),
            $this->aad($key),
        );

        return false === $plain ? null : $plain;
    }

    public function set(string $key, string $text): void
    {
        if (!$this->enabled() || mb_strlen($text) > $this->maxChars) {
            return; // Caching is an optimisation; never fail a request over it.
        }

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            return;
        }

        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($text, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag, $this->aad($key));
        if (false === $ct) {
            return;
        }

        $payload = json_encode([
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ct' => base64_encode($ct),
            'expires_at' => ($this->clock)() + $this->ttlSeconds,
        ]);
        if (false === $payload) {
            return;
        }

        $path = $this->path($key);
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (false !== @file_put_contents($tmp, $payload, LOCK_EX)) {
            @chmod($tmp, 0600);
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
            }
        }

        $this->prune();
    }

    /** Keep the directory bounded: beyond the entry cap, the oldest files are deleted. Expired entries are removed when read. */
    private function prune(): void
    {
        $files = glob(rtrim($this->directory, '\\/') . DIRECTORY_SEPARATOR . 'text_*.json') ?: [];
        if (\count($files) <= $this->maxEntries) {
            return;
        }

        usort($files, static fn (string $a, string $b): int => (int) @filemtime($a) <=> (int) @filemtime($b));
        foreach (\array_slice($files, 0, \count($files) - $this->maxEntries) as $old) {
            @unlink($old);
        }
    }

    private function aad(string $key): string
    {
        return hash('sha256', $key, true);
    }

    private function path(string $key): string
    {
        return rtrim($this->directory, '\\/') . DIRECTORY_SEPARATOR . 'text_' . hash('sha256', $key) . '.json';
    }
}
