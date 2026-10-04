<?php

declare(strict_types=1);

namespace Desertica\Billing\Http\Controller;

use Desertica\Billing\Http\Error\ApiError;
use Desertica\Billing\Service\BatchService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class BatchController
{
    public function __construct(private readonly BatchService $batches)
    {
    }

    /**
     * @param array<string, string> $args
     */
    public function void(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $externalId = DocumentController::uuid($args['externalId']);
        $body = DocumentController::jsonBody($request);
        $reason = $body['reason'] ?? null;
        $voidDate = $body['voidDate'] ?? null;
        $errors = [];
        if (!is_string($reason) || mb_strlen(trim($reason)) < 1 || mb_strlen(trim($reason)) > 100) {
            $errors[] = 'reason es obligatorio (1 a 100 caracteres).';
        }
        if (!is_string($voidDate) || !self::isDate($voidDate)) {
            $errors[] = 'voidDate es obligatorio y debe ser una fecha YYYY-MM-DD.';
        }
        if ($errors !== [] || !is_string($reason) || !is_string($voidDate)) {
            throw ApiError::unprocessable('VALIDATION_ERROR', implode(' ', $errors));
        }

        return Json::respond($response, $this->batches->void($externalId, trim($reason), $voidDate)->toResult());
    }

    public function dailySummary(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $date = DocumentController::jsonBody($request)['date'] ?? null;
        if (!is_string($date) || !self::isDate($date)) {
            throw ApiError::unprocessable('VALIDATION_ERROR', 'date es obligatorio y debe ser una fecha YYYY-MM-DD.');
        }

        return Json::respond($response, $this->batches->dailySummary($date)->toResult());
    }

    /**
     * @param array<string, string> $args
     */
    public function ticket(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $ticket = $args['ticket'];
        if (!preg_match('/^[A-Za-z0-9._-]{1,64}$/', $ticket)) {
            throw ApiError::notFound('Ticket desconocido.');
        }

        return Json::respond($response, $this->batches->ticket($ticket)->toResult());
    }

    private static function isDate(string $value): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
