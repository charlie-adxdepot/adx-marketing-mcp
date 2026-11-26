<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Tests\Support;

use Adx\MarketingMcp\Config\Config;
use Adx\MarketingMcp\Kernel;
use Psr\Log\NullLogger;

final class TestKernel
{
    public const MCP_TOKEN = 'local-test-token-0123456789abcdef0123456789abcdef';

    /**
     * @param array<string, string> $overrides
     */
    public static function config(string $storage, array $overrides = []): Config
    {
        return Config::fromEnvironment($overrides + [
            'APP_ENV' => 'testing',
            'MICROSOFT_TENANT_ID' => '11111111-2222-3333-4444-555555555555',
            'MICROSOFT_CLIENT_ID' => 'client-id',
            'MICROSOFT_CLIENT_SECRET' => FakeGraph::CLIENT_SECRET,
            'SHAREPOINT_DRIVE_ID' => FakeGraph::DRIVE,
            'SHAREPOINT_ROOT_FOLDER_ID' => FakeGraph::ROOT,
            'MCP_AUTH_TOKEN' => self::MCP_TOKEN,
            'MCP_MAX_RESPONSE_CHARS' => '30000',
            'STORAGE_PATH' => $storage,
            'LOG_LEVEL' => 'debug',
        ], $storage);
    }

    /**
     * @param array<string, string> $overrides
     */
    public static function create(FakeGraph $graph, string $storage, array $overrides = []): Kernel
    {
        return new Kernel(self::config($storage, $overrides), new NullLogger(), $graph->client());
    }

    public static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'adxmcp-test-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);

        return $dir;
    }

    public static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
