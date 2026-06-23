<?php

declare(strict_types=1);

namespace Tests;

use App\Services\RenderService;
use PHPUnit\Framework\TestCase;

final class ArticleRenderingTest extends TestCase
{
    private const CURRENT_ARTICLE_HTML = <<<'HTML'
<article class="vrstk-article" data-vrstk-article="">
<style data-vrstk-critical-css="">.vrstk-article{display:block}</style>
<div data-vrstk-article-app=""><div class="vrstk-frame">Hello from Verstka</div></div>
<script type="application/json" data-vrstk-article-payload="">{"containers":[]}</script>
</article>
HTML;

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

    public function testCurrentVerstkaHtmlIsPreservedAndBootstrapped(): void
    {
        $settings = TestSupport::makeTestSettings($this->tmpRoot, [
            'VERSTKA_VIEWER_SCRIPT_URL' => TestSupport::DEFAULT_VIEWER_SCRIPT_URL,
        ]);
        $render = new RenderService($settings);
        $html = $render->renderArticlePage(
            [
                'path' => '/hi',
                'title' => 'Hello',
                'html' => self::CURRENT_ARTICLE_HTML,
                'og_title' => null,
                'og_description' => null,
                'og_image_relpath' => null,
            ],
            '',
            '',
            false,
        );

        self::assertStringContainsString(self::CURRENT_ARTICLE_HTML, $html);
        self::assertStringContainsString(TestSupport::DEFAULT_VIEWER_SCRIPT_URL, $html);
        self::assertStringContainsString('Verstka.initArticles(document)', $html);
        self::assertStringNotContainsString('go.verstka.org/api.js', $html);
        self::assertStringNotContainsString('class="verstka-article"', $html);
    }

    public function testLegacyHtmlIsRenderedWithoutViewerBootstrap(): void
    {
        $settings = TestSupport::makeTestSettings($this->tmpRoot);
        $render = new RenderService($settings);
        $html = $render->renderArticlePage(
            [
                'path' => '/legacy',
                'title' => 'Legacy',
                'html' => '<p>Legacy body</p>',
                'og_title' => null,
                'og_description' => null,
                'og_image_relpath' => null,
            ],
            '',
            '',
            false,
        );

        self::assertStringContainsString('class="verstka-legacy-article"', $html);
        self::assertStringContainsString('<p>Legacy body</p>', $html);
        self::assertStringNotContainsString('Verstka.initArticles', $html);
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
