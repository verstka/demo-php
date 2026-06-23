<?php

declare(strict_types=1);

namespace App\Actions;

use App\Storage\CmsVerstkaStorage;
use App\Verstka\VerstkaHooks;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use Verstka\Sdk\Client\VerstkaClient;
use Verstka\Sdk\Integration\CallbackDispatcher;

final class VerstkaCallbackAction
{
    public function __construct(
        private readonly VerstkaClient $client,
        private readonly CmsVerstkaStorage $storage,
        private readonly VerstkaHooks $hooks,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $body = (string) $request->getBody();
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $signature = trim($request->getHeaderLine('X-Verstka-Signature'));

        try {
            $result = CallbackDispatcher::dispatch(
                $this->client,
                $payload,
                $signature,
                $this->storage,
                $this->hooks->onContentFinalize,
                $this->hooks->onFontsFinalize,
                $this->hooks->onContentPreSave,
                $this->hooks->onFontsPreSave,
            );
        } catch (\Throwable $e) {
            $err = CallbackDispatcher::mapException($e);
            $response->getBody()->write((string) json_encode($err->toArray(), JSON_THROW_ON_ERROR));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus($err->status);
        }

        $response->getBody()->write((string) json_encode($result, JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
