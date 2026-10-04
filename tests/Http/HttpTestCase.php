<?php

declare(strict_types=1);

namespace Desertica\Billing\Tests\Http;

use Desertica\Billing\App;
use Desertica\Billing\Config\Settings;
use Desertica\Billing\Sunat\FakeSunatGateway;
use Desertica\Billing\Tests\Support\FixedClock;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Slim\App as SlimApp;
use Slim\Psr7\Factory\ServerRequestFactory;

abstract class HttpTestCase extends TestCase
{
    protected const TOKEN = 'test-token-0123456789-0123456789-abcdef';

    protected FakeSunatGateway $gateway;
    protected FixedClock $clock;
    protected string $storageDir;
    /** @var SlimApp<\Psr\Container\ContainerInterface|null> */
    protected SlimApp $app;

    protected function setUp(): void
    {
        $this->storageDir = sys_get_temp_dir() . '/billing-test-' . bin2hex(random_bytes(6));
        $this->gateway = new FakeSunatGateway();
        $this->clock = new FixedClock();
        $this->app = App::create(
            $this->settings(),
            new NullLogger(),
            $this->gateway,
            $this->clock,
            new \PDO('sqlite::memory:'),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->storageDir);
    }

    /**
     * @param array<string, string> $overrides
     */
    protected function settings(array $overrides = []): Settings
    {
        return Settings::fromEnv($overrides + [
            'BILLING_ENV' => 'BETA',
            'BILLING_SERVICE_TOKEN' => self::TOKEN,
            'BILLING_GATEWAY' => 'fake',
            'BILLING_STORAGE_DIR' => $this->storageDir,
            'BILLING_ISSUER_RUC' => '20000000001',
        ]);
    }

    /**
     * @param array<string, string> $headers
     */
    protected function call(string $method, string $path, ?string $body = null, array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== null) {
            $request->getBody()->write($body);
            $request->getBody()->rewind();
        }

        return $this->app->handle($request);
    }

    /**
     * @param array<mixed>|null $body
     */
    protected function api(string $method, string $path, ?array $body = null): ResponseInterface
    {
        return $this->call(
            $method,
            $path,
            $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
            ['Authorization' => 'Bearer ' . self::TOKEN, 'Content-Type' => 'application/json'],
        );
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

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
