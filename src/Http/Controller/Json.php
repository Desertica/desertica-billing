<?php

declare(strict_types=1);

namespace Desertica\Billing\Http\Controller;

use Psr\Http\Message\ResponseInterface;

final class Json
{
    /**
     * @param array<mixed> $data
     */
    public static function respond(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
