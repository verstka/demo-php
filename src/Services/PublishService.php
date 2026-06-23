<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Settings;
use App\Repo\ArticleRepo;
use App\Support\Paths;

final class PublishService
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ArticleRepo $repo,
        private readonly RenderService $render,
    ) {
    }

    public function ensureDefaultFavicon(): void
    {
        $src = $this->settings->staticDir . '/favicon.ico';
        $dst = $this->settings->storageDir . '/favicon.ico';
        if (!is_dir($this->settings->storageDir)) {
            mkdir($this->settings->storageDir, 0775, true);
        }
        if (!is_file($dst) && is_file($src)) {
            copy($src, $dst);
        }
    }

    /** @param array<string, mixed>|null $row */
    public function publishArticleChange(?array $row, bool $removeIfHidden = false): void
    {
        if ($row === null) {
            $this->writeSitemap();

            return;
        }

        $path = (string) $row['path'];
        $visible = (bool) ($row['is_visible'] ?? false);
        $isMenuFooter = in_array($path, ['/menu', '/footer'], true);

        if (!$visible) {
            if ($removeIfHidden) {
                $this->removeArticleIndex($path);
            }
            $this->writeSitemap();

            return;
        }

        if ($removeIfHidden && $isMenuFooter) {
            $this->regenerateAllVisibleIndexes();
        } else {
            $this->writeArticleIndex($row);
            if ($isMenuFooter) {
                $this->regenerateAllVisibleIndexes();
            }
        }

        $this->writeSitemap();
    }

    public function publishArticleRemoved(string $articlePath): void
    {
        $this->deleteArticleStorage($articlePath);
        if (in_array($articlePath, ['/menu', '/footer'], true)) {
            $this->regenerateAllVisibleIndexes();
        }
        $this->writeSitemap();
    }

    public function syncVisibilityToDisk(string $path, bool $isVisible): void
    {
        if (in_array($path, ['/menu', '/footer'], true)) {
            $this->regenerateAllVisibleIndexes();
            $this->writeSitemap();

            return;
        }
        $row = $this->repo->articleByPath($path);
        if ($row === null) {
            return;
        }
        $row['is_visible'] = $isVisible ? 1 : 0;
        $this->publishArticleChange($row, removeIfHidden: true);
    }

    /** @param array<string, mixed> $article */
    public function writeArticleIndex(array $article): void
    {
        [$menuHtml, $footerHtml] = $this->menuFooterBlocks();
        $html = $this->render->renderArticlePage(
            $article,
            $menuHtml,
            $footerHtml,
            $this->render->fontsCssFileExists(),
        );
        $dir = Paths::storageArticleDir($this->settings->storageDir, (string) $article['path']);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir . '/index.html', $html);
    }

    public function removeArticleIndex(string $articlePath): void
    {
        $index = Paths::storageArticleDir($this->settings->storageDir, $articlePath) . '/index.html';
        if (is_file($index)) {
            unlink($index);
        }
    }

    public function writeSitemap(): void
    {
        $paths = $this->repo->listVisibleArticles(pathsOnly: true);
        $base = rtrim($this->settings->publicBaseUrl, '/');
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];
        foreach ($paths as $path) {
            $loc = htmlspecialchars($base . $path . '/', ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $lines[] = '  <url>';
            $lines[] = "    <loc>{$loc}</loc>";
            $lines[] = '  </url>';
        }
        $lines[] = '</urlset>';
        if (!is_dir($this->settings->storageDir)) {
            mkdir($this->settings->storageDir, 0775, true);
        }
        file_put_contents($this->settings->storageDir . '/sitemap.xml', implode("\n", $lines) . "\n");
    }

    public function regenerateAllVisibleIndexes(): void
    {
        foreach ($this->repo->listVisibleArticles() as $article) {
            $this->writeArticleIndex($article);
        }
        foreach (['/menu', '/footer'] as $special) {
            $row = $this->repo->articleByPath($special);
            if ($row !== null && ($row['is_visible'] ?? 0)) {
                $this->writeArticleIndex($row);
            }
        }
    }

    public function deleteArticleStorage(string $articlePath): void
    {
        $dir = Paths::storageArticleDir($this->settings->storageDir, $articlePath);
        if (!is_dir($dir)) {
            return;
        }
        $this->removeDirectory($dir);
    }

    /** @return array{0: string, 1: string} */
    private function menuFooterBlocks(): array
    {
        $menuHtml = '';
        $footerHtml = '';
        $menuRow = $this->repo->articleByPath('/menu');
        $footerRow = $this->repo->articleByPath('/footer');
        if ($menuRow && !empty($menuRow['html']) && ($menuRow['is_visible'] ?? 0)) {
            $menuHtml = (string) $menuRow['html'];
        }
        if ($footerRow && !empty($footerRow['html']) && ($footerRow['is_visible'] ?? 0)) {
            $footerHtml = (string) $footerRow['html'];
        }

        return [$menuHtml, $footerHtml];
    }

    private function removeDirectory(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
