<?php

declare(strict_types=1);

namespace Desertica\Billing\Http\Error;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;

/**
 * Convierte cualquier excepción en el cuerpo de error del contrato. Nunca filtra trazas ni secretos.
 */
final class ErrorHandler
{
    public function __construct(
        private readonly ResponseFactoryInterface $responses,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, \Throwable $error): ResponseInterface
    {
        $apiError = match (true) {
            $error instanceof ApiError => $error,
            $error instanceof HttpNotFoundException => ApiError::notFound('Ruta no encontrada.'),
            $error instanceof HttpMethodNotAllowedException => new ApiError(405, 'METHOD_NOT_ALLOWED', 'Método no permitido.'),
            default => new ApiError(500, 'INTERNAL_ERROR', 'Error interno.', null, false),
        };

        if ($apiError->status >= 500 && $apiError->status !== 502) {
            // Solo clase y mensaje: las trazas pueden contener datos del comprobante o del cliente.
            $this->logger->error('unhandled_exception', [
                'class' => $error::class,
                'message' => $error->getMessage(),
                'path' => $request->getUri()->getPath(),
            ]);
        }

        $response = $this->responses->createResponse($apiError->status)
            ->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode($apiError->toBody(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $response;
    }
}
