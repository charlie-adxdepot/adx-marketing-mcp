<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Tests;

use Adx\MarketingMcp\Config\ConfigurationException;
use Adx\MarketingMcp\Logging\RedactingProcessor;
use Adx\MarketingMcp\Tests\Support\FakeEntra;
use Adx\MarketingMcp\Tests\Support\FakeGraph;
use Adx\MarketingMcp\Tests\Support\TestKernel;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

final class ConfigAndLoggingTest extends TestCase
{
    public function testMissingSettingsAreNamedButValuesAreNot(): void
    {
        try {
            TestKernel::config(sys_get_temp_dir(), ['SHAREPOINT_DRIVE_ID' => '', 'MCP_AUTH_TOKEN' => '']);
            self::fail('Expected ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('SHAREPOINT_DRIVE_ID', $e->getMessage());
            self::assertStringContainsString('MCP_AUTH_TOKEN', $e->getMessage());
            self::assertStringNotContainsString(FakeGraph::CLIENT_SECRET, $e->getMessage());
        }
    }

    public function testShortStaticTokenIsRejected(): void
    {
        $this->expectException(ConfigurationException::class);
        TestKernel::config(sys_get_temp_dir(), ['MCP_AUTH_TOKEN' => 'short']);
    }

    public function testOauthModeRequiresIssuerAndAudience(): void
    {
        $this->expectExceptionMessage('MCP_OAUTH_ISSUER');
        TestKernel::config(sys_get_temp_dir(), ['MCP_AUTH_MODE' => 'oauth']);
    }

    /** @return array<string, string> a configuration that is valid for APP_ENV=production with OAuth */
    private static function productionOverrides(): array
    {
        return [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'MCP_AUTH_MODE' => 'oauth',
            'MCP_OAUTH_ISSUER' => FakeEntra::ISSUER,
            'MCP_OAUTH_AUDIENCE' => FakeEntra::API_CLIENT_ID,
            'MCP_OAUTH_SCOPE_CLAIM' => 'scp',
            'MCP_OAUTH_REQUIRED_SCOPES' => 'access_as_user',
            'MCP_OAUTH_ADVERTISED_SCOPES' => 'https://mcp.example.com/mcp/access_as_user offline_access',
            'MCP_PUBLIC_URL' => 'https://mcp.example.com/mcp',
            'MCP_ALLOWED_HOSTS' => 'mcp.example.com',
        ];
    }

    public function testAValidProductionConfigurationIsAccepted(): void
    {
        $config = TestKernel::config(sys_get_temp_dir(), self::productionOverrides());

        self::assertTrue($config->isProduction());
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function unsafeProductionSettings(): iterable
    {
        yield 'debug left on' => [['APP_DEBUG' => 'true'], 'APP_DEBUG'];
        yield 'debug left on with static auth' => [['APP_DEBUG' => 'true', 'MCP_AUTH_MODE' => 'static'], 'APP_DEBUG'];
        yield 'static auth without opting in' => [['MCP_AUTH_MODE' => 'static'], 'MCP_ALLOW_STATIC_AUTH_IN_PRODUCTION'];
        yield 'wildcard host' => [['MCP_ALLOWED_HOSTS' => '*'], 'MCP_ALLOWED_HOSTS'];
        yield 'allowed hosts missing the public host' => [['MCP_ALLOWED_HOSTS' => 'other.example.com'], 'MCP_ALLOWED_HOSTS'];
        yield 'public url on localhost' => [['MCP_PUBLIC_URL' => 'https://localhost/mcp', 'MCP_ALLOWED_HOSTS' => 'localhost'], 'MCP_PUBLIC_URL'];
    }

    /** @param array<string, string> $change */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeProductionSettings')]
    public function testProductionRefusesUnsafeSettings(array $change, string $expectedKey): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage($expectedKey);

        TestKernel::config(sys_get_temp_dir(), $change + self::productionOverrides());
    }

    public function testStaticAuthInProductionIsPossibleOnlyByDeliberateOptIn(): void
    {
        $config = TestKernel::config(sys_get_temp_dir(), [
            'MCP_AUTH_MODE' => 'static',
            'MCP_ALLOW_STATIC_AUTH_IN_PRODUCTION' => 'true',
        ] + self::productionOverrides());

        self::assertSame('static', $config->authMode);
    }

    public function testUnreplacedTemplatePlaceholdersAreNamedNotSilentlyAccepted(): void
    {
        try {
            TestKernel::config(sys_get_temp_dir(), [
                'MICROSOFT_CLIENT_SECRET' => 'REPLACE_WITH_CLIENT_SECRET',
                'MCP_PUBLIC_URL' => 'https://REPLACE_WITH_DOMAIN/mcp',
            ] + self::productionOverrides());
            self::fail('Expected ConfigurationException');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('MICROSOFT_CLIENT_SECRET', $e->getMessage());
            self::assertStringContainsString('MCP_PUBLIC_URL', $e->getMessage());
            self::assertStringNotContainsString('REPLACE_WITH_CLIENT_SECRET', $e->getMessage(), 'names the setting, not its value');
        }
    }

    public function testPublicUrlMustBeHttpsUnlessLocal(): void
    {
        $this->expectExceptionMessage('https://');
        TestKernel::config(sys_get_temp_dir(), ['MCP_PUBLIC_URL' => 'http://mcp.example.com/mcp']);
    }

    public function testPublicUrlOnLoopbackMayUseHttp(): void
    {
        $config = TestKernel::config(sys_get_temp_dir(), ['MCP_PUBLIC_URL' => 'http://localhost:8080/mcp']);

        self::assertSame('http://localhost:8080/mcp', $config->publicUrl);
    }

    public function testDebugInfoHidesSecrets(): void
    {
        $dump = print_r(TestKernel::config(sys_get_temp_dir()), true);

        self::assertStringNotContainsString(FakeGraph::CLIENT_SECRET, $dump);
        self::assertStringNotContainsString(TestKernel::MCP_TOKEN, $dump);
    }

    public function testRedactingProcessorScrubsSecrets(): void
    {
        $processor = new RedactingProcessor([FakeGraph::CLIENT_SECRET]);
        $record = new LogRecord(new \DateTimeImmutable(), 'test', Level::Info, 'secret ' . FakeGraph::CLIENT_SECRET . ' header Bearer abc.def.ghi', [
            'client_secret' => 'x',
            'url' => 'https://x.sharepoint.com/download?' . FakeGraph::TEMPAUTH,
            'nested' => ['jwt' => FakeGraph::ACCESS_TOKEN],
        ]);

        $out = $processor($record);
        $flat = $out->message . json_encode($out->context);

        self::assertStringNotContainsString(FakeGraph::CLIENT_SECRET, $flat);
        self::assertStringNotContainsString('abc.def.ghi', $flat);
        self::assertStringNotContainsString('PREAUTH-DOWNLOAD-SECRET', $flat);
        self::assertStringNotContainsString(FakeGraph::ACCESS_TOKEN, $flat);
        self::assertSame('[REDACTED]', $out->context['client_secret']);
    }
}
