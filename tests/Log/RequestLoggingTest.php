<?php

declare(strict_types=1);

namespace Desertica\Billing\Tests\Log;

use Desertica\Billing\App;
use Desertica\Billing\Config\Settings;
use Desertica\Billing\Log\PhpErrorLogging;
use Desertica\Billing\Sunat\FakeSunatGateway;
use Desertica\Billing\Tests\Support\FixedClock;
use Desertica\Billing\Tests\Support\Payloads;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Slim\Psr7\Factory\ServerRequestFactory;

final class RequestLoggingTest extends TestCase
{
    private const TOKEN = 'test-token-0123456789-0123456789-abcdef';

    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    private array $lines = [];

    private function logger(): AbstractLogger
    {
        return new class ($this->lines) extends AbstractLogger {
            /** @param list<array{level: string, message: string, context: array<mixed>}> $lines */
            public function __construct(private array &$lines)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->lines[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }

    private function send(string $method, string $uri, ?string $body, ?string $token): int
    {
        $dir = sys_get_temp_dir() . '/billing-log-' . bin2hex(random_bytes(4));
        $app = App::create(
            Settings::fromEnv(['BILLING_ENV' => 'BETA', 'BILLING_SERVICE_TOKEN' => self::TOKEN, 'BILLING_STORAGE_DIR' => $dir, 'BILLING_WKHTMLTOPDF' => 'false']),
            $this->logger(),
            new FakeSunatGateway(),
            new FixedClock(),
            new \PDO('sqlite::memory:'),
        );
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri);
        if ($token !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $token);
        }
        if ($body !== null) {
            $request->getBody()->write($body);
            $request->getBody()->rewind();
        }

        return $app->handle($request)->getStatusCode();
    }

    public function testEachRequestLogsMethodPathStatusAndDurationOnly(): void
    {
        $status = $this->send('POST', '/v1/documents?apiKey=abc123', json_encode(Payloads::boleta(), JSON_THROW_ON_ERROR), self::TOKEN);

        self::assertSame(200, $status);
        $requests = array_values(array_filter($this->lines, static fn (array $l) => $l['message'] === 'request'));
        self::assertCount(1, $requests);
        self::assertSame(['method', 'path', 'status', 'durationMs'], array_keys($requests[0]['context']));
        self::assertSame('/v1/documents', $requests[0]['context']['path']);
        self::assertSame(200, $requests[0]['context']['status']);

        $dump = json_encode($this->lines, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::TOKEN, $dump);
        self::assertStringNotContainsString('abc123', $dump);
        self::assertStringNotContainsString('MARIA RAMOS', $dump, 'El cuerpo (datos del cliente) no se registra.');
    }

    public function testRejectedRequestsAreLoggedWithTheirFinalStatus(): void
    {
        self::assertSame(401, $this->send('GET', '/v1/documents/11111111-1111-4111-8111-111111111111', null, 'wrong-token-wrong-token-wrong-token'));
        self::assertSame(404, $this->send('GET', '/v1/documents/11111111-1111-4111-8111-111111111111', null, self::TOKEN));

        self::assertSame([401, 404], array_column(array_column($this->lines, 'context'), 'status'));
        self::assertStringNotContainsString('wrong-token', json_encode($this->lines, JSON_THROW_ON_ERROR));
    }

    public function testPhpErrorSeveritiesMapToLogLevels(): void
    {
        self::assertSame('warning', PhpErrorLogging::level(E_WARNING));
        self::assertSame('notice', PhpErrorLogging::level(E_DEPRECATED));
        self::assertSame('error', PhpErrorLogging::level(E_ERROR));
    }
}
