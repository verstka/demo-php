<?php

declare(strict_types=1);

namespace Tests;

use App\Database\Database;
use PHPUnit\Framework\TestCase;

final class CmsAuthTest extends TestCase
{
    private string $tmpRoot;
    private \App\Config\Settings $settings;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/demo_php_' . uniqid('', true);
        mkdir($this->tmpRoot, 0775, true);
        $settings = TestSupport::makeTestSettings($this->tmpRoot);
        $pdo = Database::init($settings);
        TestSupport::seedAdminAndArticle($settings, $pdo);
        $this->settings = $settings;
        $this->pdo = $pdo;
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpRoot);
    }

    public function testUnauthenticatedArticlesRedirectsToLogin(): void
    {
        $app = TestSupport::buildCmsTestApp($this->settings, pdo: $this->pdo);
        $response = TestSupport::request($app, 'GET', '/cms/articles');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/cms/login', $response->getHeaderLine('Location'));
    }

    public function testUnauthenticatedUsersRedirectsToLogin(): void
    {
        $app = TestSupport::buildCmsTestApp($this->settings, pdo: $this->pdo);
        $response = TestSupport::request($app, 'GET', '/cms/users');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/cms/login', $response->getHeaderLine('Location'));
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
