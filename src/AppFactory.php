<?php

declare(strict_types=1);

namespace App;

use App\Actions\VerstkaCallbackAction;
use App\Config\Settings;
use App\Controllers\CmsController;
use App\Database\Database;
use App\Exception\CmsLoginRequired;
use App\Middleware\SessionMiddleware;
use App\Repo\ArticleRepo;
use App\Services\PublishService;
use App\Services\RenderService;
use App\Storage\CmsVerstkaStorage;
use App\Verstka\VerstkaHooks;
use PDO;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory as SlimAppFactory;
use Slim\Psr7\Response;
use App\Verstka\EditorClient;
use App\Verstka\VerstkaClientAdapter;
use Verstka\Sdk\Client\VerstkaClient;
use Verstka\Sdk\Config\VerstkaConfig;
use Verstka\Sdk\Exception\VerstkaError;
use Verstka\Sdk\Integration\CallbackDispatcher;

final class AppFactory
{
    public static function create(
        Settings $settings,
        ?EditorClient $editorClientOverride = null,
        ?VerstkaClient $verstkaClientOverride = null,
        ?PDO $pdoOverride = null,
    ): App {
        $app = SlimAppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->add(new SessionMiddleware($settings));

        $pdo = $pdoOverride ?? Database::init($settings);
        $repo = new ArticleRepo($pdo);
        $render = new RenderService($settings);
        $publish = new PublishService($settings, $repo, $render);
        $storage = new CmsVerstkaStorage($settings, $repo);
        $hooks = new VerstkaHooks($settings, $repo, $publish);

        if ($pdoOverride === null) {
            $publish->ensureDefaultFavicon();
            $publish->writeSitemap();
        }

        $verstkaConfig = new VerstkaConfig(
            apiKey: $settings->verstkaApiKey,
            apiSecret: $settings->verstkaApiSecret,
            callbackUrl: $settings->verstkaCallbackUrl,
            apiUrl: $settings->verstkaApiUrl,
            debug: $settings->debug,
        );
        $verstkaClient = $verstkaClientOverride ?? new VerstkaClient($verstkaConfig);
        $editorClient = $editorClientOverride ?? new VerstkaClientAdapter($verstkaClient);

        $cms = new CmsController($settings, $repo, $publish, $render, $editorClient);
        $callback = new VerstkaCallbackAction($verstkaClient, $storage, $hooks);

        $app->get('/cms/static/{file}', self::cmsStaticHandler($settings));
        $app->get('/cms/login', [$cms, 'loginForm']);
        $app->post('/cms/login', [$cms, 'loginPost']);
        $app->get('/cms/logout', [$cms, 'logout']);
        $app->get('/cms', [$cms, 'root']);
        $app->get('/cms/', [$cms, 'root']);
        $app->get('/cms/articles', [$cms, 'articlesList']);
        $app->post('/cms/articles/create', [$cms, 'articlesCreate']);
        $app->post('/cms/articles/delete', [$cms, 'articlesDelete']);
        $app->post('/cms/articles/visibility', [$cms, 'articlesVisibility']);
        $app->post('/cms/articles/og', [$cms, 'articlesOg']);
        $app->get('/cms/articles/open', [$cms, 'articlesOpenEditor']);
        $app->get('/cms/users', [$cms, 'usersList']);
        $app->post('/cms/users/create', [$cms, 'usersCreate']);
        $app->post('/cms/users/delete', [$cms, 'usersDelete']);
        $app->post('/cms/users/password', [$cms, 'usersPassword']);
        $app->post('/verstka/callback', $callback);

        $errorMiddleware = $app->addErrorMiddleware($settings->debug, true, true);
        $errorMiddleware->setDefaultErrorHandler(
            static function (
                \Psr\Http\Message\ServerRequestInterface $request,
                \Throwable $exception,
                bool $displayErrorDetails,
                bool $logErrors,
                bool $logErrorDetails,
            ) use ($app): Response {
                if ($exception instanceof CmsLoginRequired) {
                    $response = $app->getResponseFactory()->createResponse(303);
                    return $response->withHeader('Location', '/cms/login');
                }
                if ($exception instanceof VerstkaError) {
                    $err = CallbackDispatcher::mapException($exception);
                    $response = $app->getResponseFactory()->createResponse($err->status);
                    $response->getBody()->write((string) json_encode($err->toArray(), JSON_THROW_ON_ERROR));
                    return $response->withHeader('Content-Type', 'application/json');
                }
                if ($exception instanceof HttpNotFoundException) {
                    $response = $app->getResponseFactory()->createResponse(404);
                    $response->getBody()->write('Not found');
                    return $response;
                }
                $response = $app->getResponseFactory()->createResponse(500);
                $response->getBody()->write($displayErrorDetails ? $exception->getMessage() : 'Internal server error');
                return $response;
            },
        );

        return $app;
    }

    /** @return callable(\Psr\Http\Message\ServerRequestInterface, Response, array): Response */
    private static function cmsStaticHandler(Settings $settings): callable
    {
        $types = [
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'ico' => 'image/x-icon',
        ];

        return static function (
            \Psr\Http\Message\ServerRequestInterface $request,
            Response $response,
            array $args,
        ) use ($settings, $types): Response {
            $file = basename((string) ($args['file'] ?? ''));
            if ($file === '' || $file === '.' || $file === '..') {
                return $response->withStatus(404);
            }
            $path = $settings->staticDir . '/' . $file;
            if (!is_file($path)) {
                $response->getBody()->write('Not found');

                return $response->withStatus(404);
            }
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $contentType = $types[$ext] ?? 'application/octet-stream';
            $response->getBody()->write((string) file_get_contents($path));

            return $response->withHeader('Content-Type', $contentType);
        };
    }
}
