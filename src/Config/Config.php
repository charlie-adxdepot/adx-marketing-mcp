<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\Config;

/**
 * Immutable, validated application configuration built from environment variables.
 *
 * Secrets are held in private properties and are never included in __debugInfo()
 * or exception messages.
 */
final class Config
{
    public const AUTH_MODE_STATIC = 'static';
    public const AUTH_MODE_OAUTH = 'oauth';

    /**
     * @param list<string> $allowedHosts
     * @param list<string> $oauthRequiredScopes
     */
    public function __construct(
        public readonly string $appEnv,
        public readonly bool $debug,
        public readonly string $tenantId,
        public readonly string $clientId,
        #[\SensitiveParameter] private readonly string $clientSecret,
        public readonly string $driveId,
        public readonly string $rootFolderId,
        public readonly string $serverHost,
        public readonly int $serverPort,
        public readonly string $authMode,
        #[\SensitiveParameter] private readonly string $authToken,
        public readonly int $maxDocumentBytes,
        public readonly int $maxResponseChars,
        public readonly string $logLevel,
        public readonly string $publicUrl,
        public readonly array $allowedHosts,
        public readonly string $oauthIssuer,
        public readonly string $oauthAudience,
        public readonly array $oauthRequiredScopes,
        public readonly string $oauthScopeClaim,
        public readonly int $indexTtlSeconds,
        public readonly int $maxIndexItems,
        public readonly int $maxFolderDepth,
        public readonly string $storagePath,
        public readonly int $textCacheTtlSeconds = 3600,
        public readonly bool $allowStaticAuthInProduction = false,
        /** @var list<string> scopes a client must REQUEST (authorization-request form, e.g. api://<id>/access_as_user) */
        public readonly array $oauthAdvertisedScopes = [],
        /** @var list<string> app roles every token must carry in its `roles` claim */
        public readonly array $oauthRequiredRoles = [],
        /** @var list<string> approved OAuth client IDs (`azp`); empty = any client */
        public readonly array $oauthAllowedClientIds = [],
        /** URL advertised as the authorization server in RFC 9728 metadata; defaults to the issuer */
        public readonly string $oauthAuthorizationServer = '',
    ) {
    }

    private const GUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const ENTRA_HOSTS = ['login.microsoftonline.com', 'login.microsoftonline.us'];
    private const OIDC_RESERVED_SCOPES = ['openid', 'profile', 'email', 'offline_access'];

    /** @return list<string> accepted `aud` values */
    public function oauthAudiences(): array
    {
        return self::csv($this->oauthAudience);
    }

    /** Scopes advertised to clients; falls back to the required scopes for non-Entra issuers. */
    public function oauthScopesToAdvertise(): array
    {
        return [] !== $this->oauthAdvertisedScopes ? $this->oauthAdvertisedScopes : $this->oauthRequiredScopes;
    }

    public function oauthAuthorizationServerUrl(): string
    {
        return '' !== $this->oauthAuthorizationServer ? $this->oauthAuthorizationServer : $this->oauthIssuer;
    }

    public function isEntraIssuer(): bool
    {
        return \in_array(strtolower((string) parse_url($this->oauthIssuer, PHP_URL_HOST)), self::ENTRA_HOSTS, true);
    }

    /** Tenant GUID taken from an Entra issuer (https://login.microsoftonline.com/<tenant>/v2.0), else null. */
    public function oauthTenantId(): ?string
    {
        if (!$this->isEntraIssuer()) {
            return null;
        }

        return preg_match('#^/([0-9a-f-]{36})/v2\.0$#i', (string) parse_url($this->oauthIssuer, PHP_URL_PATH), $m) ? strtolower($m[1]) : null;
    }

    /**
     * @param array<string, string|null> $env
     */
    public static function fromEnvironment(array $env, string $projectRoot): self
    {
        $get = static fn (string $key, string $default = ''): string => trim((string) ($env[$key] ?? '')) !== ''
            ? trim((string) $env[$key])
            : $default;

        $storage = $get('STORAGE_PATH', 'var');
        if (!preg_match('#^([a-zA-Z]:[\\\\/]|/)#', $storage)) {
            $storage = rtrim($projectRoot, '\\/') . DIRECTORY_SEPARATOR . $storage;
        }

        $host = $get('MCP_SERVER_HOST', '127.0.0.1');
        $port = self::int($get('MCP_SERVER_PORT', '8080'), 'MCP_SERVER_PORT', 1, 65535);

        $config = new self(
            appEnv: $get('APP_ENV', 'local'),
            debug: filter_var($get('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN),
            tenantId: $get('MICROSOFT_TENANT_ID'),
            clientId: $get('MICROSOFT_CLIENT_ID'),
            clientSecret: $get('MICROSOFT_CLIENT_SECRET'),
            driveId: $get('SHAREPOINT_DRIVE_ID'),
            rootFolderId: $get('SHAREPOINT_ROOT_FOLDER_ID'),
            serverHost: $host,
            serverPort: $port,
            authMode: strtolower($get('MCP_AUTH_MODE', self::AUTH_MODE_STATIC)),
            authToken: $get('MCP_AUTH_TOKEN'),
            maxDocumentBytes: self::int($get('MCP_MAX_DOCUMENT_SIZE_MB', '20'), 'MCP_MAX_DOCUMENT_SIZE_MB', 1, 500) * 1024 * 1024,
            maxResponseChars: self::int($get('MCP_MAX_RESPONSE_CHARS', '30000'), 'MCP_MAX_RESPONSE_CHARS', 1000, 1_000_000),
            logLevel: strtolower($get('LOG_LEVEL', 'info')),
            publicUrl: $get('MCP_PUBLIC_URL', sprintf('http://%s:%d/mcp', $host, $port)),
            allowedHosts: self::csv($get('MCP_ALLOWED_HOSTS', 'localhost,127.0.0.1,[::1]')),
            oauthIssuer: $get('MCP_OAUTH_ISSUER'),
            oauthAudience: $get('MCP_OAUTH_AUDIENCE'),
            oauthRequiredScopes: self::csv($get('MCP_OAUTH_REQUIRED_SCOPES')),
            oauthScopeClaim: $get('MCP_OAUTH_SCOPE_CLAIM', 'scope'),
            indexTtlSeconds: self::int($get('SHAREPOINT_INDEX_TTL_SECONDS', '600'), 'SHAREPOINT_INDEX_TTL_SECONDS', 0, 86400),
            maxIndexItems: self::int($get('SHAREPOINT_MAX_INDEX_ITEMS', '5000'), 'SHAREPOINT_MAX_INDEX_ITEMS', 10, 100_000),
            maxFolderDepth: self::int($get('SHAREPOINT_MAX_FOLDER_DEPTH', '15'), 'SHAREPOINT_MAX_FOLDER_DEPTH', 1, 50),
            storagePath: $storage,
            textCacheTtlSeconds: self::int($get('SHAREPOINT_TEXT_CACHE_TTL_SECONDS', '3600'), 'SHAREPOINT_TEXT_CACHE_TTL_SECONDS', 0, 86400),
            allowStaticAuthInProduction: filter_var($get('MCP_ALLOW_STATIC_AUTH_IN_PRODUCTION', 'false'), FILTER_VALIDATE_BOOLEAN),
            // Space- or comma-separated, matching how OAuth writes scope lists.
            oauthAdvertisedScopes: self::csv(str_replace(' ', ',', $get('MCP_OAUTH_ADVERTISED_SCOPES'))),
            oauthRequiredRoles: self::csv($get('MCP_OAUTH_REQUIRED_ROLES')),
            oauthAllowedClientIds: self::csv($get('MCP_OAUTH_ALLOWED_CLIENT_IDS')),
            oauthAuthorizationServer: $get('MCP_OAUTH_AUTHORIZATION_SERVER'),
        );

        $config->validate();

        return $config;
    }

    public function clientSecret(): string
    {
        return $this->clientSecret;
    }

    public function authToken(): string
    {
        return $this->authToken;
    }

    public function isProduction(): bool
    {
        return 'production' === $this->appEnv;
    }

    /**
     * @throws ConfigurationException
     */
    public function validate(): void
    {
        $missing = [];
        foreach ([
            'MICROSOFT_TENANT_ID' => $this->tenantId,
            'MICROSOFT_CLIENT_ID' => $this->clientId,
            'MICROSOFT_CLIENT_SECRET' => $this->clientSecret,
            'SHAREPOINT_DRIVE_ID' => $this->driveId,
            'SHAREPOINT_ROOT_FOLDER_ID' => $this->rootFolderId,
        ] as $key => $value) {
            if ('' === $value) {
                $missing[] = $key;
            }
        }

        if (!\in_array($this->authMode, [self::AUTH_MODE_STATIC, self::AUTH_MODE_OAUTH], true)) {
            throw new ConfigurationException('MCP_AUTH_MODE must be "static" or "oauth".');
        }

        if (self::AUTH_MODE_STATIC === $this->authMode) {
            if ('' === $this->authToken) {
                $missing[] = 'MCP_AUTH_TOKEN';
            } elseif (\strlen($this->authToken) < 32) {
                throw new ConfigurationException('MCP_AUTH_TOKEN must be at least 32 characters. Generate one with: php -r "echo bin2hex(random_bytes(32));"');
            }
        }

        if (self::AUTH_MODE_OAUTH === $this->authMode) {
            foreach (['MCP_OAUTH_ISSUER' => $this->oauthIssuer, 'MCP_OAUTH_AUDIENCE' => $this->oauthAudience] as $key => $value) {
                if ('' === $value) {
                    $missing[] = $key;
                }
            }
            // Fail closed: without a required scope any authentic token for this audience would be accepted.
            if ([] === $this->oauthRequiredScopes) {
                $missing[] = 'MCP_OAUTH_REQUIRED_SCOPES';
            }
            if ($this->isEntraIssuer() && [] === $this->oauthAdvertisedScopes) {
                $missing[] = 'MCP_OAUTH_ADVERTISED_SCOPES';
            }
        }

        if ([] !== $missing) {
            throw new ConfigurationException('Missing required configuration: ' . implode(', ', $missing) . '. Copy .env.example to .env and fill these in.');
        }

        // A template value nobody replaced (e.g. REPLACE_WITH_DOMAIN) would otherwise start fine and fail confusingly later.
        $placeholders = [];
        foreach ([
            'MICROSOFT_TENANT_ID' => $this->tenantId,
            'MICROSOFT_CLIENT_ID' => $this->clientId,
            'MICROSOFT_CLIENT_SECRET' => $this->clientSecret,
            'SHAREPOINT_DRIVE_ID' => $this->driveId,
            'SHAREPOINT_ROOT_FOLDER_ID' => $this->rootFolderId,
            'MCP_PUBLIC_URL' => $this->publicUrl,
            'MCP_ALLOWED_HOSTS' => implode(',', $this->allowedHosts),
            'STORAGE_PATH' => $this->storagePath,
            'MCP_OAUTH_ISSUER' => $this->oauthIssuer,
            'MCP_OAUTH_AUDIENCE' => $this->oauthAudience,
            'MCP_OAUTH_ADVERTISED_SCOPES' => implode(',', $this->oauthAdvertisedScopes),
            'MCP_OAUTH_REQUIRED_SCOPES' => implode(',', $this->oauthRequiredScopes),
            'MCP_OAUTH_REQUIRED_ROLES' => implode(',', $this->oauthRequiredRoles),
            'MCP_OAUTH_ALLOWED_CLIENT_IDS' => implode(',', $this->oauthAllowedClientIds),
            'MCP_OAUTH_AUTHORIZATION_SERVER' => $this->oauthAuthorizationServer,
        ] as $key => $value) {
            if (str_contains($value, 'REPLACE_')) {
                $placeholders[] = $key;
            }
        }
        if ([] !== $placeholders) {
            throw new ConfigurationException('These settings still contain a REPLACE_... placeholder: ' . implode(', ', $placeholders) . '. Put the real value in.');
        }

        if (!\in_array($this->logLevel, ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'], true)) {
            throw new ConfigurationException('LOG_LEVEL is not a valid PSR-3 level.');
        }

        $this->validatePublicEndpoint();

        if (self::AUTH_MODE_OAUTH === $this->authMode) {
            $this->validateOAuth();
        }

        if ($this->isProduction()) {
            $this->validateProduction();
        }
    }

    /**
     * The advertised URL must be HTTPS unless it points at this machine.
     */
    private function validatePublicEndpoint(): void
    {
        $parts = parse_url($this->publicUrl);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (false === $parts || '' === $host || !\in_array($scheme, ['http', 'https'], true)) {
            throw new ConfigurationException('MCP_PUBLIC_URL must be a full http(s) URL, e.g. https://mcp.example.com/mcp.');
        }

        if ('http' === $scheme && !\in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true)) {
            throw new ConfigurationException('MCP_PUBLIC_URL must use https:// unless it points at localhost.');
        }
    }

    /**
     * OAuth settings that would otherwise start "fine" and then reject every
     * real token (or, worse, accept tokens they should not). Microsoft Entra
     * has specific, well-defined token formats, so they are checked precisely.
     */
    private function validateOAuth(): void
    {
        foreach (['MCP_OAUTH_ISSUER' => $this->oauthIssuer, 'MCP_OAUTH_AUTHORIZATION_SERVER' => $this->oauthAuthorizationServer] as $key => $url) {
            if ('' === $url) {
                continue;
            }
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ('https' !== $scheme && !('http' === $scheme && \in_array($host, ['localhost', '127.0.0.1'], true))) {
                throw new ConfigurationException($key . ' must be an https:// URL.');
            }
        }

        if ('sts.windows.net' === strtolower((string) parse_url($this->oauthIssuer, PHP_URL_HOST))) {
            throw new ConfigurationException(
                'MCP_OAUTH_ISSUER is an Entra v1.0 issuer. Set the API app\'s requestedAccessTokenVersion to 2 and use '
                . 'https://login.microsoftonline.com/<TENANT_ID_GUID>/v2.0.'
            );
        }

        if (!$this->isEntraIssuer()) {
            return;
        }

        if (null === $this->oauthTenantId()) {
            throw new ConfigurationException(
                'MCP_OAUTH_ISSUER must be the tenant-specific Entra v2.0 issuer: https://login.microsoftonline.com/<TENANT_ID_GUID>/v2.0 '
                . '(not "common", "organizations", a domain name, or the v1 https://sts.windows.net/... form).'
            );
        }

        foreach ($this->oauthAudiences() as $aud) {
            if (!preg_match(self::GUID, $aud)) {
                throw new ConfigurationException(
                    'MCP_OAUTH_AUDIENCE must be the OAuth API app\'s Application (client) ID GUID. Entra v2.0 access tokens put the '
                    . 'client ID in "aud", not the api:// Application ID URI.'
                );
            }
        }

        if ('scp' !== $this->oauthScopeClaim) {
            throw new ConfigurationException('MCP_OAUTH_SCOPE_CLAIM must be "scp" for Microsoft Entra delegated (user) access tokens.');
        }

        foreach ($this->oauthRequiredScopes as $scope) {
            if (str_contains($scope, '/') || str_contains($scope, ':')) {
                throw new ConfigurationException(
                    'MCP_OAUTH_REQUIRED_SCOPES must list bare scope names as they appear in the token\'s "scp" claim (e.g. access_as_user), '
                    . 'not the full api://... scope. Put the full scope in MCP_OAUTH_ADVERTISED_SCOPES.'
                );
            }
        }

        foreach ($this->oauthAdvertisedScopes as $scope) {
            if (!\in_array($scope, self::OIDC_RESERVED_SCOPES, true) && !str_contains($scope, '/')) {
                throw new ConfigurationException(
                    'MCP_OAUTH_ADVERTISED_SCOPES must use the full scope a client requests from Entra, e.g. '
                    . 'api://<OAUTH_API_CLIENT_ID>/access_as_user. Entra treats a bare name as a Microsoft Graph scope.'
                );
            }
        }

        foreach ($this->oauthAllowedClientIds as $client) {
            if (!preg_match(self::GUID, $client)) {
                throw new ConfigurationException('MCP_OAUTH_ALLOWED_CLIENT_IDS must contain Application (client) ID GUIDs.');
            }
        }
    }

    /**
     * Settings that are acceptable for local testing but must never reach a real deployment.
     */
    private function validateProduction(): void
    {
        if ($this->debug) {
            throw new ConfigurationException('APP_DEBUG must be false when APP_ENV=production.');
        }

        if (self::AUTH_MODE_STATIC === $this->authMode && !$this->allowStaticAuthInProduction) {
            throw new ConfigurationException(
                'MCP_AUTH_MODE=static is a shared-secret mode intended for local testing. Use MCP_AUTH_MODE=oauth, or set '
                . 'MCP_ALLOW_STATIC_AUTH_IN_PRODUCTION=true to accept the risk deliberately.'
            );
        }

        if (\in_array('*', $this->allowedHosts, true)) {
            throw new ConfigurationException('MCP_ALLOWED_HOSTS must not contain "*" when APP_ENV=production.');
        }

        $publicHost = strtolower((string) parse_url($this->publicUrl, PHP_URL_HOST));
        $allowed = array_map('strtolower', $this->allowedHosts);
        if (!\in_array($publicHost, $allowed, true) && !\in_array('[' . trim($publicHost, '[]') . ']', $allowed, true)) {
            throw new ConfigurationException('MCP_ALLOWED_HOSTS must include the host name from MCP_PUBLIC_URL when APP_ENV=production.');
        }

        if (\in_array($publicHost, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
            throw new ConfigurationException('MCP_PUBLIC_URL must be the public https:// address when APP_ENV=production, not localhost.');
        }
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [
            'appEnv' => $this->appEnv,
            'driveId' => $this->driveId,
            'rootFolderId' => $this->rootFolderId,
            'authMode' => $this->authMode,
            'clientSecret' => '***',
            'authToken' => '***',
        ];
    }

    private static function int(string $value, string $key, int $min, int $max): int
    {
        if (!preg_match('/^\d+$/', $value) || (int) $value < $min || (int) $value > $max) {
            throw new ConfigurationException(sprintf('%s must be an integer between %d and %d.', $key, $min, $max));
        }

        return (int) $value;
    }

    /** @return list<string> */
    private static function csv(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => '' !== $v));
    }
}
