<?php

declare(strict_types=1);

namespace Tests;

use App\Database\Database;
use App\Services\LoginGuard;
use PHPUnit\Framework\TestCase;

final class CmsLoginThrottleTest extends TestCase
{
    private string $tmpRoot;
    private \App\Config\Settings $settings;
    private \PDO $pdo;

    protected function setUp(): void
    {
        LoginGuard::resetLoginFailures();
        $this->tmpRoot = sys_get_temp_dir() . '/demo_php_' . uniqid('', true);
        mkdir($this->tmpRoot, 0775, true);
        $this->settings = TestSupport::makeTestSettings($this->tmpRoot, [
            'CMS_LOGIN_MAX_FAILURES' => '3',
            'CMS_LOGIN_WINDOW_SECONDS' => '60',
        ]);
        $this->pdo = Database::init($this->settings);
        TestSupport::seedAdminAndArticle($this->settings, $this->pdo);
    }

    protected function tearDown(): void
    {
        LoginGuard::resetLoginFailures();
        $this->removeDir($this->tmpRoot);
    }

    public function testBlocksUserAcrossIps(): void
    {
        $app = TestSupport::buildCmsTestApp($this->settings, pdo: $this->pdo);
        for ($i = 0; $i < 3; $i++) {
            $response = $this->login($app, password: 'wrong');
            self::assertSame(401, $response->getStatusCode());
        }
        $blocked = $this->login($app, password: 'wrong');
        self::assertSame(429, $blocked->getStatusCode());
        self::assertStringContainsString('Too many failed attempts', TestSupport::responseBody($blocked));
    }

    public function testSuccessfulLoginClearsFailures(): void
    {
        $app = TestSupport::buildCmsTestApp($this->settings, pdo: $this->pdo);
        for ($i = 0; $i < 2; $i++) {
            self::assertSame(401, $this->login($app, password: 'wrong')->getStatusCode());
        }
        $ok = $this->login($app, password: 'password123');
        self::assertSame(303, $ok->getStatusCode());
        self::assertSame('/cms/articles', $ok->getHeaderLine('Location'));
        self::assertSame(401, $this->login($app, password: 'wrong')->getStatusCode());
    }

    private function login(\Slim\App $app, string $password = 'wrong'): \Psr\Http\Message\ResponseInterface
    {
        return TestSupport::request($app, 'POST', '/cms/login', [
            'user_email' => 'admin@example.test',
            'password' => $password,
        ]);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
