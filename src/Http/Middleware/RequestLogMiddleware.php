<?php

declare(strict_types=1);

namespace Desertica\Billing\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Una línea JSON por petición: método, ruta, estado y duración. Sin cabeceras (llevan el token de servicio), sin query string
 * y sin cuerpo (datos del cliente y del comprobante).
 */
final class RequestLogMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $start = hrtime(true);
        $response = $handler->handle($request);
        $status = $response->getStatusCode();

        $this->logger->log($status >= 500 ? 'warning' : 'info', 'request', [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'status' => $status,
            'durationMs' => (int) round((hrtime(true) - $start) / 1_000_000),
        ]);

        return $response;
    }
}
