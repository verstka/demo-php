<?php

declare(strict_types=1);

namespace Tests;

use App\Database\Database;
use PHPUnit\Framework\TestCase;

final class CmsLayoutTest extends TestCase
{
    private string $tmpRoot;
    private \App\Config\Settings $settings;
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/demo_php_' . uniqid('', true);
        mkdir($this->tmpRoot, 0775, true);
        $this->settings = TestSupport::makeTestSettings($this->tmpRoot);
        $this->pdo = Database::init($this->settings);
        TestSupport::seedAdminAndArticle($this->settings, $this->pdo);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpRoot);
    }

    public function testLoginPageUsesNewLayoutAndPreservesAuthForm(): void
    {
        $app = TestSupport::buildCmsTestApp($this->settings, pdo: $this->pdo);
        $response = TestSupport::request($app, 'GET', '/cms/login');
        $body = TestSupport::responseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('data-testid="login-layout"', $body);
        self::assertStringContainsString('href="/cms/static/cms-login.css?v=login-stats-cards-20260923"', $body);
        self::assertStringContainsString('src="/cms/static/cms.js?v=login-stats-duration-2000-20260923"', $body);
        self::assertStringContainsString('action="/cms/login"', $body);
        self::assertStringContainsString('name="user_email"', $body);
        self::assertStringContainsString('name="password"', $body);
        self::assertStringContainsString('Welcome back.', $body);
        self::assertStringContainsString('data-testid="login-stats"', $body);
        self::assertStringContainsString('Get started with us', $body);
        self::assertStringNotContainsString(
            'Join teams publishing articles, managing access, and connecting editorial brands in one quiet workspace.',
            $body,
        );
        self::assertStringContainsString('data-count-target="231"', $body);
        self::assertStringContainsString('Users signed in', $body);
        self::assertStringContainsString('data-count-target="2147"', $body);
        self::assertStringContainsString('data-count-format="comma"', $body);
        self::assertStringContainsString('Articles created', $body);
        self::assertStringContainsString('data-count-target="37"', $body);
        self::assertStringContainsString('Journals connected', $body);
        self::assertStringNotContainsString('18K+', $body);
        self::assertStringNotContainsString('72K+', $body);
        self::assertStringNotContainsString('340+', $body);
        self::assertStringNotContainsString('stat-card--featured', $body);
        self::assertStringNotContainsString('Get started with Verstka', $body);
        self::assertStringNotContainsString('step', $body);
        self::assertStringNotContainsString('slider-dots', $body);
        self::assertStringNotContainsString('slider-arrows', $body);
        self::assertStringNotContainsString('Verstka editorial team', $body);
    }

    public function testArticlesPageUsesDashboardAndPreservesArticleActions(): void
    {
        $app = $this->loggedInApp();
        $response = TestSupport::request($app, 'GET', '/cms/articles', sessionEmail: 'admin@example.test');
        $body = TestSupport::responseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('data-testid="cms-shell"', $body);
        self::assertStringContainsString('href="/cms/static/cms.css"', $body);
        self::assertStringContainsString('aria-current="page"', $body);
        self::assertStringContainsString('admin@example.test', $body);
        self::assertStringContainsString('Hello', $body);
        self::assertStringContainsString('/hi', $body);
        self::assertStringContainsString('data-stat="total">1<', $body);
        self::assertStringContainsString('data-stat="published">1<', $body);
        self::assertStringContainsString('data-stat="hidden">0<', $body);
        self::assertStringContainsString('action="/cms/articles/create"', $body);
        self::assertStringContainsString('action="/cms/articles/visibility"', $body);
        self::assertStringContainsString('action="/cms/articles/og"', $body);
        self::assertStringContainsString('action="/cms/articles/delete"', $body);
        self::assertStringContainsString('/cms/articles/open?path=', $body);
        self::assertStringNotContainsString('>Statistics<', $body);
        self::assertStringContainsString('data-testid="logout-button"', $body);
    }

    public function testUsersPageUsesDashboardAndPreservesUserActions(): void
    {
        $app = $this->loggedInApp();
        $response = TestSupport::request($app, 'GET', '/cms/users', sessionEmail: 'admin@example.test');
        $body = TestSupport::responseBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('data-testid="cms-shell"', $body);
        self::assertStringContainsString('Administrators', $body);
        self::assertStringContainsString('admin@example.test', $body);
        self::assertStringContainsString('action="/cms/users/create"', $body);
        self::assertStringContainsString('action="/cms/users/password"', $body);
        self::assertStringContainsString('action="/cms/users/delete"', $body);
    }

    public function testCmsStaticAssetsAreServedByTheApplication(): void
    {
        $app = TestSupport::buildCmsTestApp($this->settings, pdo: $this->pdo);

        $css = TestSupport::request($app, 'GET', '/cms/static/cms.css');
        $loginCss = TestSupport::request($app, 'GET', '/cms/static/cms-login.css');
        $js = TestSupport::request($app, 'GET', '/cms/static/cms.js');
        $image = TestSupport::request($app, 'GET', '/cms/static/login-editorial.png');
        $favicon = TestSupport::request($app, 'GET', '/cms/static/favicon.svg');

        self::assertSame(200, $css->getStatusCode());
        self::assertStringContainsString('text/css', $css->getHeaderLine('Content-Type'));
        self::assertSame(200, $loginCss->getStatusCode());
        self::assertStringContainsString('text/css', $loginCss->getHeaderLine('Content-Type'));
        $loginCssBody = TestSupport::responseBody($loginCss);
        self::assertStringContainsString('.stat-card::before', $loginCssBody);
        self::assertStringContainsString('.stat-card::after', $loginCssBody);
        self::assertStringContainsString('.stat-card__label::before', $loginCssBody);
        self::assertStringContainsString('font-variant-numeric: tabular-nums', $loginCssBody);
        self::assertStringNotContainsString('stat-card--featured', $loginCssBody);
        self::assertStringNotContainsString('color: #111', $loginCssBody);

        self::assertSame(200, $js->getStatusCode());
        self::assertStringContainsString('javascript', $js->getHeaderLine('Content-Type'));
        $jsBody = TestSupport::responseBody($js);
        self::assertStringContainsString('data-count-target', $jsBody);
        self::assertStringContainsString('countStartDelay = 0', $jsBody);
        self::assertStringContainsString('duration = 2000', $jsBody);
        self::assertStringContainsString('window.setTimeout(startCounter, countStartDelay)', $jsBody);

        self::assertSame(200, $image->getStatusCode());
        self::assertSame('image/png', $image->getHeaderLine('Content-Type'));
        self::assertSame(200, $favicon->getStatusCode());
        self::assertStringContainsString('image/svg', $favicon->getHeaderLine('Content-Type'));
        self::assertStringContainsString('fill="#2d2d2d"', TestSupport::responseBody($favicon));
    }

    private function loggedInApp(): \Slim\App
    {
        $app = TestSupport::buildCmsTestApp($this->settings, pdo: $this->pdo);
        $response = TestSupport::request($app, 'POST', '/cms/login', [
            'user_email' => 'admin@example.test',
            'password' => 'password123',
        ]);
        self::assertSame(303, $response->getStatusCode());

        return $app;
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
