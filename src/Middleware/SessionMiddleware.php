<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Config\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_name('cms_session');
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => false,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            // ponytail: PHP 8.5+ deprecates session.sid_length; default SID length is fine for this demo
            session_start();
        }

        $response = $handler->handle($request);
        session_write_close();

        return $response;
    }
}
