<?php

declare(strict_types=1);

namespace Tests;

use App\Database\Database;
use App\Repo\ArticleRepo;
use PHPUnit\Framework\TestCase;

final class CmsBootstrapTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/demo_php_' . uniqid('', true);
        mkdir($this->tmpRoot, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpRoot);
    }

    public function testFirstLoginCreatesAdminInSqliteAndSignsIn(): void
    {
        $settings = TestSupport::makeTestSettings($this->tmpRoot);
        $pdo = Database::init($settings);
        $app = TestSupport::buildCmsTestApp($settings, pdo: $pdo);

        $response = TestSupport::request($app, 'POST', '/cms/login', [
            'user_email' => 'admin@example.test',
            'password' => 'password123',
        ]);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/cms/articles', $response->getHeaderLine('Location'));

        $repo = new ArticleRepo($pdo);
        self::assertSame(1, $repo->countCmsUsers());
        $row = $repo->getCmsUser('admin@example.test');
        self::assertNotNull($row);
        self::assertTrue(password_verify('password123', (string) $row['password_hash']));

        $articlesResponse = TestSupport::request($app, 'GET', '/cms/articles', sessionEmail: 'admin@example.test');
        self::assertSame(200, $articlesResponse->getStatusCode());
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
