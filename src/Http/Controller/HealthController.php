<?php

declare(strict_types=1);

namespace Desertica\Billing\Http\Controller;

use Desertica\Billing\Config\Environment;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class HealthController
{
    public function __construct(private readonly Environment $environment)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Json::respond($response, ['status' => 'ok', 'environment' => $this->environment->value]);
    }
}
