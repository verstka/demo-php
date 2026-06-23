<?php

declare(strict_types=1);

namespace App\Config;

final class Settings
{
    public function __construct(
        public readonly string $verstkaApiKey = '',
        public readonly string $verstkaApiSecret = '',
        public readonly string $verstkaCallbackUrl = '',
        public readonly string $verstkaApiUrl = 'https://api.r2.verstka.org/integration',
        public readonly string $verstkaViewerScriptUrl = 'https://go.r2.verstka.org/viewer-latest.js',
        public readonly string $publicBaseUrl = 'http://127.0.0.1:8000',
        public readonly string $sessionSecret = 'dev-secret-change-me',
        public readonly string $databaseUrl = 'sqlite:./data.db',
        public readonly bool $debug = false,
        public readonly int $cmsLoginMaxFailures = 10,
        public readonly int $cmsLoginWindowSeconds = 1800,
        public readonly string $storageDir = 'storage',
        public readonly string $templatesDir = 'templates',
        public readonly string $staticDir = 'static',
        public readonly string $projectRoot = '',
    ) {
    }

    public static function fromEnv(string $projectRoot): self
    {
        $get = static fn (string $key, ?string $default = null): string => (string) ($_ENV[$key] ?? $_SERVER[$key] ?? $default ?? '');

        return new self(
            verstkaApiKey: trim($get('VERSTKA_API_KEY')),
            verstkaApiSecret: trim($get('VERSTKA_API_SECRET')),
            verstkaCallbackUrl: trim($get('VERSTKA_CALLBACK_URL')),
            verstkaApiUrl: trim($get('VERSTKA_API_URL', 'https://api.r2.verstka.org/integration')),
            verstkaViewerScriptUrl: trim($get('VERSTKA_VIEWER_SCRIPT_URL', 'https://go.r2.verstka.org/viewer-latest.js')),
            publicBaseUrl: trim($get('PUBLIC_BASE_URL', 'http://127.0.0.1:8000')),
            sessionSecret: $get('SESSION_SECRET', 'dev-secret-change-me'),
            databaseUrl: $get('DATABASE_URL', 'sqlite:./data.db'),
            debug: self::coerceBool($get('DEBUG', '')),
            cmsLoginMaxFailures: (int) $get('CMS_LOGIN_MAX_FAILURES', '10'),
            cmsLoginWindowSeconds: (int) $get('CMS_LOGIN_WINDOW_SECONDS', '1800'),
            storageDir: $projectRoot . '/storage',
            templatesDir: $projectRoot . '/templates',
            staticDir: $projectRoot . '/static',
            projectRoot: $projectRoot,
        );
    }

    private static function coerceBool(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}
