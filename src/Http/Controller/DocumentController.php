<?php

declare(strict_types=1);

namespace Desertica\Billing\Http\Controller;

use Desertica\Billing\Http\Error\ApiError;
use Desertica\Billing\Service\EmissionService;
use Desertica\Billing\Storage\FileStorage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class DocumentController
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const MIME = [
        FileStorage::XML => 'application/xml',
        FileStorage::CDR => 'application/zip',
        FileStorage::PDF => 'application/pdf',
    ];

    public function __construct(private readonly EmissionService $emission)
    {
    }

    public function emit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Json::respond($response, $this->emission->emit(self::jsonBody($request))->toResult());
    }

    /**
     * @param array<string, string> $args
     */
    public function get(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return Json::respond($response, $this->emission->get(self::uuid($args['externalId']))->toResult());
    }

    /**
     * @param array<string, string> $args
     */
    public function file(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $kind = $args['kind'];
        if (!isset(self::MIME[$kind])) {
            throw ApiError::notFound('Tipo de archivo desconocido (xml, cdr o pdf).');
        }
        $content = $this->emission->file(self::uuid($args['externalId']), $kind);
        $response->getBody()->write($content);

        return $response->withHeader('Content-Type', self::MIME[$kind]);
    }

    /**
     * @return array<mixed>
     */
    public static function jsonBody(ServerRequestInterface $request): array
    {
        $raw = (string) $request->getBody();
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ApiError::badRequest('El cuerpo no es JSON válido.', 'INVALID_JSON');
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw ApiError::badRequest('El cuerpo debe ser un objeto JSON.', 'INVALID_JSON');
        }

        return $data;
    }

    public static function uuid(string $value): string
    {
        if (!preg_match(self::UUID, $value)) {
            throw ApiError::notFound('Identificador inválido.');
        }

        return strtolower($value);
    }
}
