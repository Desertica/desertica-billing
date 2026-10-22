<?php

declare(strict_types=1);

namespace Desertica\Billing\Tests\Http;

use Desertica\Billing\App;
use Desertica\Billing\Persistence\LockRepository;
use Desertica\Billing\Service\EmissionService;
use Desertica\Billing\Sunat\SunatException;
use Desertica\Billing\Tests\Support\Payloads;
use Psr\Log\NullLogger;

/**
 * Dos peticiones sobre la misma externalId no pueden firmar ni enviar a la vez; un cerrojo caducado no bloquea para siempre.
 */
final class ConcurrencyTest extends HttpTestCase
{
    private \PDO $pdo;
    private LockRepository $locks;

    protected function setUp(): void
    {
        parent::setUp();
        // Misma base que la aplicación: el cerrojo que toma la prueba es el que ve el servicio.
        $this->pdo = new \PDO('sqlite::memory:');
        $this->app = App::create($this->settings(), new NullLogger(), $this->gateway, $this->clock, $this->pdo);
        $this->locks = new LockRepository($this->pdo);
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    public function testEmissionInProgressIsNotRaced(): void
    {
        // Otra petición tiene el cerrojo del comprobante: la fila existe pero este proceso no firma ni envía.
        $owner = $this->locks->acquire(EmissionService::lockName(Payloads::FACTURA_ID), $this->now(), 130);
        self::assertNotNull($owner);

        $busy = $this->api('POST', '/v1/documents', Payloads::factura());

        self::assertSame(200, $busy->getStatusCode());
        self::assertSame('PENDING', $this->json($busy)['status']);
        self::assertCount(0, $this->gateway->sentDocuments);
        self::assertFalse($this->json($busy)['files']['xml']);

        // Al soltarse, la siguiente llamada completa la emisión una sola vez.
        $this->locks->release(EmissionService::lockName(Payloads::FACTURA_ID), $owner);
        $done = $this->json($this->api('POST', '/v1/documents', Payloads::factura()));
        self::assertSame('ACCEPTED', $done['status']);
        self::assertCount(1, $this->gateway->sentDocuments);
    }

    public function testStateChangedWhileWaitingForTheLockIsRespected(): void
    {
        $this->api('POST', '/v1/documents', Payloads::factura());

        // Una repetición posterior no vuelve a enviar: el estado leído con el cerrojo ya es ACCEPTED.
        $again = $this->json($this->api('POST', '/v1/documents', Payloads::factura()));

        self::assertSame('ACCEPTED', $again['status']);
        self::assertCount(1, $this->gateway->sentDocuments);
    }

    public function testAnExpiredLockDoesNotBlockForever(): void
    {
        $this->locks->acquire(EmissionService::lockName(Payloads::FACTURA_ID), $this->now() - 1000, 130);

        $response = $this->json($this->api('POST', '/v1/documents', Payloads::factura()));

        self::assertSame('ACCEPTED', $response['status']);
    }

    public function testTheLockIsReleasedAfterAFailure(): void
    {
        $this->gateway->enqueueDocumentResult(new SunatException(null, 'timeout', true));
        self::assertSame(502, $this->api('POST', '/v1/documents', Payloads::factura())->getStatusCode());

        self::assertNotNull($this->locks->acquire(EmissionService::lockName(Payloads::FACTURA_ID), $this->now(), 130));
    }

    public function testVoidWhileTheDocumentIsBusyIsAConflict(): void
    {
        $this->api('POST', '/v1/documents', Payloads::factura());
        $this->locks->acquire(EmissionService::lockName(Payloads::FACTURA_ID), $this->now(), 130);

        $response = $this->api('POST', '/v1/documents/' . Payloads::FACTURA_ID . '/void', ['reason' => 'x', 'voidDate' => '2026-10-04']);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('VOID_CONFLICT', $this->json($response)['code']);
        self::assertCount(0, $this->gateway->sentBatches);
    }

    public function testSummaryWhileAnotherIsRunningIsRetryable(): void
    {
        $this->api('POST', '/v1/documents', Payloads::boleta(['submission' => 'DAILY_SUMMARY']));
        $this->locks->acquire('summary:2026-10-04', $this->now(), 130);

        $response = $this->api('POST', '/v1/summaries/daily', ['date' => '2026-10-04']);

        self::assertSame(502, $response->getStatusCode());
        self::assertTrue($this->json($response)['retryable']);
        self::assertCount(0, $this->gateway->sentBatches);
    }

    public function testLockRepositoryAcquireReleaseAndExpiry(): void
    {
        $first = $this->locks->acquire('x', 100, 10);
        self::assertNotNull($first);
        self::assertNull($this->locks->acquire('x', 105, 10));
        $second = $this->locks->acquire('x', 110, 10);
        self::assertNotNull($second, 'Caducó en el segundo 110.');
        $this->locks->release('x', $second);
        self::assertNotNull($this->locks->acquire('x', 111, 10));
    }

    public function testAnExpiredHolderCannotReleaseTheNewOwnersLock(): void
    {
        $slow = $this->locks->acquire('x', 100, 10);
        $new = $this->locks->acquire('x', 110, 10);
        self::assertNotNull($slow);
        self::assertNotNull($new);

        $this->locks->release('x', $slow);

        self::assertNull($this->locks->acquire('x', 111, 10), 'El cerrojo del nuevo propietario sigue vigente.');
    }
}
