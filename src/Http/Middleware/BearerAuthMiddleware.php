<?php

declare(strict_types=1);

namespace Desertica\Billing\Http\Middleware;

use Desertica\Billing\Config\Secret;
use Desertica\Billing\Http\Error\ApiError;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Autenticación con token de servicio (`Authorization: Bearer`). `/health` queda abierto (security: []).
 */
final class BearerAuthMiddleware implements MiddlewareInterface
{
    private const PUBLIC_PATHS = ['/health'];

    public function __construct(private readonly Secret $token)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array($request->getUri()->getPath(), self::PUBLIC_PATHS, true)) {
            return $handler->handle($request);
        }

        $header = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer (\S+)$/', $header, $m) || !hash_equals($this->token->reveal(), $m[1])) {
            throw ApiError::unauthorized();
        }

        return $handler->handle($request);
    }
}
