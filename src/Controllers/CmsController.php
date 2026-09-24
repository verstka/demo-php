<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Settings;
use App\Exception\CmsLoginRequired;
use App\Repo\ArticleRepo;
use App\Services\LoginGuard;
use App\Services\PublishService;
use App\Services\RenderService;
use App\Support\Paths;
use App\Support\Validation;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use App\Verstka\EditorClient;
use Verstka\Sdk\Exception\VerstkaApiError;
use Verstka\Sdk\Exception\VerstkaError;

final class CmsController
{
    private const ALLOWED_OG_EXT = ['.jpg', '.jpeg', '.png', '.webp', '.gif'];
    private const MIN_BOOTSTRAP_PASSWORD_LEN = 8;

    public function __construct(
        private readonly Settings $settings,
        private readonly ArticleRepo $repo,
        private readonly PublishService $publish,
        private readonly RenderService $render,
        private readonly EditorClient $verstkaClient,
    ) {
    }

    public function loginForm(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        if (!empty($_SESSION['user_email'])) {
            return $this->redirect($response, '/cms/articles');
        }

        return $this->html($response, 'cms/login.html.twig', [
            'bootstrap' => $this->isBootstrapRequired(),
            'error' => null,
        ]);
    }

    public function loginPost(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $data = (array) $request->getParsedBody();
        $email = trim((string) ($data['user_email'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $bootstrap = $this->isBootstrapRequired();

        if ($bootstrap) {
            if (!Validation::isValidEmail($email)) {
                return $this->loginPage($response, bootstrap: true, error: 'Invalid email', status: 400);
            }
            if (strlen($password) < self::MIN_BOOTSTRAP_PASSWORD_LEN) {
                return $this->loginPage(
                    $response,
                    bootstrap: true,
                    error: 'Password must be at least ' . self::MIN_BOOTSTRAP_PASSWORD_LEN . ' characters',
                    status: 400,
                );
            }
            $this->repo->insertCmsUser($email, password_hash($password, PASSWORD_ARGON2ID));
            $_SESSION['user_email'] = $email;

            return $this->redirect($response, '/cms/articles');
        }

        if (!Validation::isValidEmail($email)) {
            return $this->loginPage($response, bootstrap: false, error: 'Invalid email', status: 400);
        }
        if (LoginGuard::isUserLoginBlocked($this->settings, $email)) {
            return $this->loginPage(
                $response,
                bootstrap: false,
                error: 'Too many failed attempts. Try again later.',
                status: 429,
            );
        }
        if ($this->verifyLogin($email, $password)) {
            LoginGuard::clearUserLoginFailures($email);
            $_SESSION['user_email'] = $email;

            return $this->redirect($response, '/cms/articles');
        }
        LoginGuard::recordUserLoginFailure($this->settings, $email);

        return $this->loginPage($response, bootstrap: false, error: 'Invalid email or password', status: 401);
    }

    public function logout(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $_SESSION = [];

        return $this->redirect($response, '/cms/login');
    }

    public function root(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $this->requireUser();

        return $this->redirect($response, '/cms/articles');
    }

    public function articlesList(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $this->requireUser();
        $articles = [];
        foreach ($this->repo->listArticles() as $row) {
            $article = $row;
            $article['path_q'] = rawurlencode((string) $row['path']);
            $articles[] = $article;
        }

        return $this->html($response, 'cms/articles.html.twig', ['articles' => $articles]);
    }

    public function articlesCreate(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $this->requireUser();
        $data = (array) $request->getParsedBody();
        $p = Paths::normalizeArticlePath((string) ($data['path'] ?? ''));
        if (!Paths::isValidArticlePath($p)) {
            return $this->text($response, 'Недопустимый или зарезервированный путь', 400);
        }
        try {
            $row = $this->repo->insertArticle(
                $p,
                trim((string) ($data['title'] ?? '')) ?: $p,
                trim((string) ($data['og_title'] ?? '')) ?: null,
                trim((string) ($data['og_description'] ?? '')) ?: null,
                null,
                true,
            );
            $this->publish->publishArticleChange($row);
        } catch (\Throwable $e) {
            return $this->text($response, $e->getMessage(), 400);
        }

        return $this->redirect($response, '/cms/articles');
    }

    public function articlesDelete(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $this->requireUser();
        $data = (array) $request->getParsedBody();
        $p = Paths::normalizeArticlePath((string) ($data['path'] ?? ''));
        $this->repo->deleteArticle($p);
        $this->publish->publishArticleRemoved($p);

        return $this->redirect($response, '/cms/articles');
    }

    public function articlesVisibility(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $this->requireUser();
        $data = (array) $request->getParsedBody();
        $p = Paths::normalizeArticlePath((string) ($data['path'] ?? ''));
        $vis = in_array((string) ($data['is_visible'] ?? ''), ['1', 'true', 'on', 'yes'], true);
        $this->repo->updateArticleMeta($p, isVisible: $vis);
        $this->publish->syncVisibilityToDisk($p, $vis);

        return $this->redirect($response, '/cms/articles');
    }

    public function articlesOg(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $this->requireUser();
        $data = (array) $request->getParsedBody();
        $p = Paths::normalizeArticlePath((string) ($data['path'] ?? ''));
        $row = $this->repo->articleByPath($p);
        if ($row === null) {
            return $this->text($response, 'Not found', 404);
        }
        $relImg = $row['og_image_relpath'] ?? null;
        $files = $request->getUploadedFiles();
        $ogImage = $files['og_image'] ?? null;
        if ($ogImage !== null && $ogImage->getError() === UPLOAD_ERR_OK && $ogImage->getClientFilename()) {
            $suf = strtolower(pathinfo($ogImage->getClientFilename(), PATHINFO_EXTENSION));
            $suf = $suf !== '' ? '.' . $suf : '';
            if (!in_array($suf, self::ALLOWED_OG_EXT, true)) {
                return $this->text($response, 'Недопустимый тип файла', 400);
            }
            $body = (string) $ogImage->getStream();
            if (strlen($body) > 5 * 1024 * 1024) {
                return $this->text($response, 'Файл слишком большой', 400);
            }
            $dir = Paths::storageArticleDir($this->settings->storageDir, $p);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $name = 'og_' . bin2hex(random_bytes(4)) . $suf;
            file_put_contents($dir . '/' . $name, $body);
            $relImg = $name;
        }
        $row = $this->repo->updateArticleMeta(
            $p,
            ogTitle: trim((string) ($data['og_title'] ?? '')) ?: null,
            ogDescription: trim((string) ($data['og_description'] ?? '')) ?: null,
            ogImageRelpath: $relImg,
        );
        $this->publish->publishArticleChange($row);

        return $this->redirect($response, '/cms/articles');
    }

    public function articlesOpenEditor(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $userEmail = $this->requireUser();
        $configError = $this->editorConfigError();
        if ($configError !== null) {
            return $this->editorError($response, 500, 'Verstka editor is not configured', $configError);
        }
        $params = $request->getQueryParams();
        $p = Paths::normalizeArticlePath((string) ($params['path'] ?? ''));
        $row = $this->repo->articleByPath($p);
        if ($row === null) {
            return $this->text($response, 'Not found', 404);
        }
        $vms = $this->repo->parseVmsJson($row['vms_json'] ?? null);
        try {
            $url = $this->verstkaClient->getEditorUrl(
                (string) $row['material_id'],
                $vms,
                ['user_email' => $userEmail],
            );
        } catch (VerstkaApiError $e) {
            $message = $e->getMessage() ?? 'Verstka API error';
            if ($e->statusCode === 403 && stripos($message, 'not allowed') !== false) {
                $message = 'Verstka rejected this callback host for the current API key. '
                    . 'Current VERSTKA_CALLBACK_URL is ' . var_export($this->settings->verstkaCallbackUrl, true) . '. '
                    . 'Use an HTTPS public callback URL that is allowed for this key, then restart the app.';
            }

            return $this->editorError(
                $response,
                $e->statusCode ?? 502,
                'Could not open Verstka editor',
                $message,
            );
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            return $this->editorError(
                $response,
                502,
                'Could not reach Verstka API',
                'Request to ' . var_export($this->settings->verstkaApiUrl, true) . ' failed: ' . $e->getMessage(),
            );
        } catch (VerstkaError $e) {
            return $this->editorError($response, 500, 'Could not open Verstka editor', $e->getMessage());
        } catch (\ValueError $e) {
            return $this->editorError($response, 500, 'Could not open Verstka editor', $e->getMessage());
        }

        return $this->redirect($response, $url);
    }

    public function usersList(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $this->requireUser();

        return $this->html($response, 'cms/users.html.twig', [
            'users' => $this->repo->listCmsUsers(),
        ]);
    }

    public function usersCreate(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $this->requireUser();
        $data = (array) $request->getParsedBody();
        $email = trim((string) ($data['user_email'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        if (!Validation::isValidEmail($email) || $password === '') {
            return $this->text($response, 'Bad request', 400);
        }
        if ($this->repo->getCmsUser($email) !== null) {
            return $this->text($response, 'Пользователь уже существует', 400);
        }
        $this->repo->insertCmsUser($email, password_hash($password, PASSWORD_ARGON2ID));

        return $this->redirect($response, '/cms/users');
    }

    public function usersDelete(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $sessionUser = $this->requireUser();
        $data = (array) $request->getParsedBody();
        $email = (string) ($data['user_email'] ?? '');
        if ($email === $sessionUser) {
            return $this->text($response, 'Нельзя удалить самого себя', 400);
        }
        $this->repo->deleteCmsUser($email);

        return $this->redirect($response, '/cms/users');
    }

    public function usersPassword(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $this->requireUser();
        $data = (array) $request->getParsedBody();
        $email = (string) ($data['user_email'] ?? '');
        $password = (string) ($data['password'] ?? '');
        if ($this->repo->getCmsUser($email) === null) {
            return $this->text($response, 'Not found', 404);
        }
        $this->repo->updateCmsUserPassword($email, password_hash($password, PASSWORD_ARGON2ID));

        return $this->redirect($response, '/cms/users');
    }

    private function requireUser(): string
    {
        $email = $_SESSION['user_email'] ?? null;
        if (!$email) {
            throw new CmsLoginRequired();
        }

        return (string) $email;
    }

    private function isBootstrapRequired(): bool
    {
        return $this->repo->countCmsUsers() === 0;
    }

    private function verifyLogin(string $userEmail, string $password): bool
    {
        $row = $this->repo->getCmsUser($userEmail);
        if ($row === null) {
            return false;
        }

        return password_verify($password, (string) $row['password_hash']);
    }

    private function editorConfigError(): ?string
    {
        $missing = [];
        if ($this->settings->verstkaApiKey === '') {
            $missing[] = 'VERSTKA_API_KEY';
        }
        if ($this->settings->verstkaApiSecret === '') {
            $missing[] = 'VERSTKA_API_SECRET';
        }
        if ($this->settings->verstkaCallbackUrl === '') {
            $missing[] = 'VERSTKA_CALLBACK_URL';
        }
        if ($missing === []) {
            return null;
        }

        return 'Verstka editor is not configured. Set ' . implode(', ', $missing) . ' and restart the app.';
    }

    private function loginPage(
        Response $response,
        bool $bootstrap,
        ?string $error,
        int $status,
    ): ResponseInterface {
        return $this->html($response, 'cms/login.html.twig', [
            'bootstrap' => $bootstrap,
            'error' => $error,
        ], $status);
    }

    private function editorError(Response $response, int $status, string $title, string $message): ResponseInterface
    {
        return $this->html($response, 'cms/editor_error.html.twig', [
            'title' => $title,
            'message' => $message,
            'api_url' => $this->settings->verstkaApiUrl,
            'callback_url' => $this->settings->verstkaCallbackUrl,
        ], $status);
    }

    /** @param array<string, mixed> $context */
    private function html(Response $response, string $template, array $context, int $status = 200): ResponseInterface
    {
        if (!array_key_exists('current_email', $context)) {
            $context['current_email'] = (string) ($_SESSION['user_email'] ?? '');
        }
        $response->getBody()->write($this->render->render($template, $context));

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8')->withStatus($status);
    }

    private function text(Response $response, string $message, int $status): ResponseInterface
    {
        $response->getBody()->write($message);

        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8')->withStatus($status);
    }

    private function redirect(Response $response, string $location): ResponseInterface
    {
        return $response->withHeader('Location', $location)->withStatus(303);
    }
}
