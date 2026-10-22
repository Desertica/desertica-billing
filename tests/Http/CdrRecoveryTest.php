<?php

declare(strict_types=1);

namespace Desertica\Billing\Tests\Http;

use Desertica\Billing\Sunat\CdrResult;
use Desertica\Billing\Sunat\FakeSunatGateway;
use Desertica\Billing\Sunat\SunatException;
use Desertica\Billing\Tests\Support\Payloads;

/**
 * Recuperación cuando el envío llegó a SUNAT pero se perdió la respuesta: se consulta el CDR (`getStatusCdr`) en lugar de
 * reenviar o emitir de nuevo.
 */
final class CdrRecoveryTest extends HttpTestCase
{
    private function accepted(): CdrResult
    {
        return new CdrResult(true, '0', 'La Factura numero F001-1, ha sido aceptada', FakeSunatGateway::buildCdrZip('F001-1', '0', 'ok'));
    }

    private function lost(): SunatException
    {
        return new SunatException(null, 'Fallo al comunicarse con SUNAT: timeout', true);
    }

    public function testRetryAfterALostResponseRecoversTheCdrWithoutResending(): void
    {
        $this->gateway->enqueueDocumentResult($this->lost());
        $first = $this->api('POST', '/v1/documents', Payloads::factura());
        self::assertSame(502, $first->getStatusCode());

        $this->gateway->enqueueCdrConsult($this->accepted());
        $second = $this->api('POST', '/v1/documents', Payloads::factura());

        self::assertSame(200, $second->getStatusCode());
        self::assertSame('ACCEPTED', $this->json($second)['status']);
        self::assertTrue($this->json($second)['files']['cdr']);
        self::assertCount(1, $this->gateway->sentDocuments, 'No se reenvía: el CDR se recuperó por consulta.');
        self::assertSame([['ruc' => '20000000001', 'type' => '01', 'series' => 'F001', 'number' => 1]], $this->gateway->consultedCdrs);
        self::assertSame(200, $this->api('GET', '/v1/documents/' . Payloads::FACTURA_ID . '/files/cdr')->getStatusCode());

        // Una tercera llamada devuelve lo guardado sin consultar ni enviar.
        $third = $this->api('POST', '/v1/documents', Payloads::factura());
        self::assertSame('ACCEPTED', $this->json($third)['status']);
        self::assertCount(1, $this->gateway->consultedCdrs);
        self::assertCount(1, $this->gateway->sentDocuments);
    }

    public function testRejectedCdrRecoveredByConsultationLeavesTheDocumentRejected(): void
    {
        $this->gateway->enqueueDocumentResult($this->lost());
        $this->api('POST', '/v1/documents', Payloads::factura());
        $this->gateway->enqueueCdrConsult(FakeSunatGateway::rejectedCdr('2335', 'Rechazada'));

        $second = $this->json($this->api('POST', '/v1/documents', Payloads::factura()));

        self::assertSame('REJECTED', $second['status']);
        self::assertSame('2335', $second['sunatCode']);
        self::assertCount(1, $this->gateway->sentDocuments);
    }

    public function testWithoutAConstanciaTheSameSignedXmlIsResent(): void
    {
        $this->gateway->enqueueDocumentResult($this->lost());
        $this->api('POST', '/v1/documents', Payloads::factura());
        $this->gateway->enqueueCdrConsult(null);

        $second = $this->json($this->api('POST', '/v1/documents', Payloads::factura()));

        self::assertSame('ACCEPTED', $second['status']);
        self::assertCount(2, $this->gateway->sentDocuments);
        self::assertSame($this->gateway->sentDocuments[0], $this->gateway->sentDocuments[1], 'Se reenvía el mismo XML firmado.');
    }

    public function testAFailedConsultationBeforeResendingDoesNotBlockTheRetry(): void
    {
        $this->gateway->enqueueDocumentResult($this->lost());
        $this->api('POST', '/v1/documents', Payloads::factura());
        $this->gateway->enqueueCdrConsult($this->lost());

        $second = $this->json($this->api('POST', '/v1/documents', Payloads::factura()));

        self::assertSame('ACCEPTED', $second['status']);
        self::assertCount(2, $this->gateway->sentDocuments);
    }

    public function testAlreadyRegisteredAnswerTriggersTheConsultation(): void
    {
        $this->gateway->enqueueDocumentResult($this->lost());
        $this->api('POST', '/v1/documents', Payloads::factura());
        // La consulta previa al reenvío no encuentra nada; SUNAT contesta «ya registrado»; la segunda consulta sí.
        $this->gateway->enqueueCdrConsult(null);
        $this->gateway->enqueueDocumentResult(new SunatException('1033', 'El comprobante fue registrado previamente con otros datos', false));
        $this->gateway->enqueueCdrConsult($this->accepted());

        $second = $this->api('POST', '/v1/documents', Payloads::factura());

        self::assertSame(200, $second->getStatusCode());
        self::assertSame('ACCEPTED', $this->json($second)['status']);
        self::assertCount(2, $this->gateway->consultedCdrs);
    }

    public function testAlreadyRegisteredWithoutConstanciaIsASunatException(): void
    {
        $this->gateway->enqueueDocumentResult(new SunatException('1033', 'El comprobante fue registrado previamente con otros datos', false));
        $this->gateway->enqueueCdrConsult(null);

        $response = $this->api('POST', '/v1/documents', Payloads::factura());

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('SUNAT_EXCEPTION', $this->json($response)['code']);
        self::assertSame('1033', $this->json($response)['sunatCode']);
    }

    public function testAlreadyRegisteredWhenTheConsultationFailsStaysRetryable(): void
    {
        $this->gateway->enqueueDocumentResult(new SunatException('1033', 'El comprobante fue registrado previamente con otros datos', false));
        $this->gateway->enqueueCdrConsult($this->lost());

        $first = $this->api('POST', '/v1/documents', Payloads::factura());
        self::assertSame(502, $first->getStatusCode());
        self::assertTrue($this->json($first)['retryable']);
        self::assertSame('PENDING', $this->json($this->api('GET', '/v1/documents/' . Payloads::FACTURA_ID))['status']);

        // El API reintenta con la misma externalId y esta vez la consulta funciona.
        $this->gateway->enqueueCdrConsult(null);
        $this->gateway->enqueueDocumentResult(new SunatException('1033', 'x', false));
        $this->gateway->enqueueCdrConsult($this->accepted());
        $second = $this->json($this->api('POST', '/v1/documents', Payloads::factura()));
        self::assertSame('ACCEPTED', $second['status']);
    }

    public function testOtherSunatExceptionsDoNotConsultTheCdr(): void
    {
        $this->gateway->enqueueDocumentResult(new SunatException('0151', 'Nombre de archivo inválido', false));

        $response = $this->api('POST', '/v1/documents', Payloads::factura());

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([], $this->gateway->consultedCdrs);
    }

    public function testNothingIsConsultedOnTheFirstSuccessfulSend(): void
    {
        $this->api('POST', '/v1/documents', Payloads::factura());

        self::assertSame([], $this->gateway->consultedCdrs);
    }
}
