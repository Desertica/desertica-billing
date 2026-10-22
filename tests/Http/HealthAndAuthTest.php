<?php

declare(strict_types=1);

namespace Desertica\Billing\Tests\Http;

final class HealthAndAuthTest extends HttpTestCase
{
    public function testHealthIsPublicAndShowsEnvironment(): void
    {
        $response = $this->call('GET', '/health');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['status' => 'ok', 'environment' => 'BETA'], $this->json($response));
    }

    public function testProtectedRouteWithoutTokenIsUnauthorized(): void
    {
        $response = $this->call('GET', '/v1/tickets/123');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('UNAUTHORIZED', $this->json($response)['code']);
    }

    public function testWrongTokenIsUnauthorized(): void
    {
        $response = $this->call('GET', '/v1/tickets/123', null, ['Authorization' => 'Bearer nope']);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testUnknownRouteUsesErrorFormat(): void
    {
        $response = $this->call('GET', '/nope', null, ['Authorization' => 'Bearer ' . self::TOKEN]);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('NOT_FOUND', $this->json($response)['code']);
    }
}
