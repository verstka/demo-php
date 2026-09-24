<?php

declare(strict_types=1);

namespace Tests;

use App\AppFactory;
use App\Config\Settings;
use App\Database\Database;
use App\Repo\ArticleRepo;
use App\Services\LoginGuard;
use App\Verstka\EditorClient;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

final class TestSupport
{
    public const DEFAULT_VIEWER_SCRIPT_URL = 'https://go.r2.verstka.org/viewer-latest.js';

    /** @param array<string, mixed> $overrides */
    public static function makeTestSettings(string $tmpRoot, array $overrides = []): Settings
    {
        $projectRoot = dirname(__DIR__);
        $defaults = [
            'VERSTKA_API_KEY' => 'key',
            'VERSTKA_API_SECRET' => 'secret',
            'VERSTKA_CALLBACK_URL' => 'https://cms.example.test/verstka/callback',
            'VERSTKA_API_URL' => 'https://api-stage.verstka.org/integration',
            'PUBLIC_BASE_URL' => 'https://cms.example.test',
            'SESSION_SECRET' => 'test-secret',
            'DATABASE_URL' => 'sqlite:' . $tmpRoot . '/data.db',
        ];
        $merged = array_merge($defaults, $overrides);
        foreach ($merged as $key => $value) {
            $_ENV[$key] = (string) $value;
            $_SERVER[$key] = (string) $value;
        }

        $settings = Settings::fromEnv($projectRoot);
        $viewerUrl = (string) ($merged['VERSTKA_VIEWER_SCRIPT_URL'] ?? $settings->verstkaViewerScriptUrl);

        return new Settings(
            verstkaApiKey: $settings->verstkaApiKey,
            verstkaApiSecret: $settings->verstkaApiSecret,
            verstkaCallbackUrl: $settings->verstkaCallbackUrl,
            verstkaApiUrl: $settings->verstkaApiUrl,
            verstkaViewerScriptUrl: $viewerUrl,
            publicBaseUrl: $settings->publicBaseUrl,
            sessionSecret: $settings->sessionSecret,
            databaseUrl: 'sqlite:' . $tmpRoot . '/data.db',
            debug: $settings->debug,
            cmsLoginMaxFailures: isset($overrides['CMS_LOGIN_MAX_FAILURES'])
                ? (int) $overrides['CMS_LOGIN_MAX_FAILURES']
                : $settings->cmsLoginMaxFailures,
            cmsLoginWindowSeconds: isset($overrides['CMS_LOGIN_WINDOW_SECONDS'])
                ? (int) $overrides['CMS_LOGIN_WINDOW_SECONDS']
                : $settings->cmsLoginWindowSeconds,
            storageDir: $tmpRoot . '/storage',
            templatesDir: $projectRoot . '/templates',
            staticDir: $projectRoot . '/static',
            projectRoot: $projectRoot,
        );
    }

    public static function buildCmsTestApp(
        Settings $settings,
        ?EditorClient $editorClient = null,
        ?\Verstka\Sdk\Client\VerstkaClient $verstkaClient = null,
        ?PDO $pdo = null,
    ): App {
        LoginGuard::resetLoginFailures();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
        $_SESSION = [];
        $pdo ??= Database::init($settings);

        return AppFactory::create($settings, $editorClient, $verstkaClient, $pdo);
    }

    public static function seedAdminAndArticle(
        Settings $settings,
        PDO $pdo,
        string $userEmail = 'admin@example.test',
        string $password = 'password123',
        string $articlePath = '/hi',
        string $articleTitle = 'Hello',
    ): void {
        Database::init($settings, $pdo);
        $repo = new ArticleRepo($pdo);
        $repo->insertCmsUser($userEmail, password_hash($password, PASSWORD_ARGON2ID));
        $repo->insertArticle(
            $articlePath,
            $articleTitle,
            null,
            null,
            null,
            true,
        );
    }

    /** @param array<string, string> $body */
    public static function request(
        App $app,
        string $method,
        string $path,
        array $body = [],
        ?string $sessionEmail = null,
    ): ResponseInterface {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if ($sessionEmail !== null) {
            $_SESSION['user_email'] = $sessionEmail;
        }

        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest($method, 'http://localhost' . $path);
        if ($body !== []) {
            $request = $request->withParsedBody($body);
            $request = $request->withHeader('Content-Type', 'application/x-www-form-urlencoded');
        }

        $response = $app->handle($request);
        session_write_close();

        return $response;
    }

    /** @param array<string, mixed> $json */
    public static function jsonRequest(
        App $app,
        string $path,
        array $json,
        array $headers = [],
    ): ResponseInterface {
        $factory = new ServerRequestFactory();
        $streamFactory = new StreamFactory();
        $body = $streamFactory->createStream((string) json_encode($json, JSON_THROW_ON_ERROR));
        $request = $factory->createServerRequest('POST', 'http://localhost' . $path)
            ->withBody($body)
            ->withHeader('Content-Type', 'application/json');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $app->handle($request);
    }

    public static function responseBody(ResponseInterface $response): string
    {
        $response->getBody()->rewind();

        return (string) $response->getBody();
    }
}
