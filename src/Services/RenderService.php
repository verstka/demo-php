<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Settings;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class RenderService
{
    private readonly Environment $twig;

    public function __construct(private readonly Settings $settings)
    {
        $this->twig = new Environment(
            new FilesystemLoader($settings->templatesDir),
            ['autoescape' => 'html', 'strict_variables' => false],
        );
    }

    public function isCurrentVerstkaArticleHtml(string $html): bool
    {
        return str_contains($html, 'data-vrstk-article') && str_contains($html, 'data-vrstk-article-payload');
    }

    public function fontsCssFileExists(): bool
    {
        return is_file($this->settings->storageDir . '/fonts/fonts.css');
    }

    /** @param array<string, mixed> $article */
    public function renderArticlePage(
        array $article,
        string $menuHtml,
        string $footerHtml,
        bool $fontsCssExists,
    ): string {
        $base = rtrim($this->settings->publicBaseUrl, '/');
        $ogImage = null;
        $rel = $article['og_image_relpath'] ?? null;
        if ($rel) {
            $p = rtrim((string) $article['path'], '/') ?: (string) $article['path'];
            $norm = ltrim($p, '/');
            $ogImage = str_starts_with((string) $rel, 'http') ? (string) $rel : "{$base}/{$norm}/{$rel}";
        }
        $bodyHtml = (string) ($article['html'] ?? '');
        $articleIsCurrent = $this->isCurrentVerstkaArticleHtml($bodyHtml);
        $viewerBootstrapEnabled = $articleIsCurrent
            || $this->isCurrentVerstkaArticleHtml($menuHtml)
            || $this->isCurrentVerstkaArticleHtml($footerHtml);

        return $this->twig->render('article.html.twig', [
            'title' => $article['title'] ?? $article['path'],
            'article_html' => $bodyHtml,
            'article_is_current_verstka_html' => $articleIsCurrent,
            'menu_html' => $menuHtml,
            'footer_html' => $footerHtml,
            'og_title' => $article['og_title'] ?? $article['title'] ?? '',
            'og_description' => $article['og_description'] ?? '',
            'og_image' => $ogImage,
            'fonts_css_exists' => $fontsCssExists,
            'canonical_url' => $base . $article['path'] . '/',
            'viewer_bootstrap_enabled' => $viewerBootstrapEnabled,
            'viewer_script_url' => $this->settings->verstkaViewerScriptUrl,
            'viewer_script_url_json' => json_encode($this->settings->verstkaViewerScriptUrl, JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** @param array<string, mixed> $context */
    public function render(string $template, array $context = []): string
    {
        return $this->twig->render($template, $context);
    }
}
