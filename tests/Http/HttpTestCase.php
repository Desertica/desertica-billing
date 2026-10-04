<?php

declare(strict_types=1);

namespace Desertica\Billing\Tests\Http;

use Desertica\Billing\App;
use Desertica\Billing\Config\Settings;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ServerRequestFactory;

abstract class HttpTestCase extends TestCase
{
    protected const TOKEN = 'test-token-0123456789-0123456789-abcdef';

    /**
     * @param array<string, string> $overrides
     */
    protected function settings(array $overrides = []): Settings
    {
        return Settings::fromEnv($overrides + [
            'BILLING_ENV' => 'BETA',
            'BILLING_SERVICE_TOKEN' => self::TOKEN,
            'BILLING_GATEWAY' => 'fake',
        ]);
    }

    /**
     * @param array<string, string> $headers
     */
    protected function call(string $method, string $path, ?string $body = null, array $headers = []): ResponseInterface
    {
        $app = App::create($this->settings(), new NullLogger());
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $request->getBody()->write($body);
            $request->getBody()->rewind();
        }

        return $app->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(ResponseInterface $response): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }
}
