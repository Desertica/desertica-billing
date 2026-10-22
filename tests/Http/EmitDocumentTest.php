<?php

declare(strict_types=1);

namespace Desertica\Billing\Tests\Http;

use Desertica\Billing\Sunat\FakeSunatGateway;
use Desertica\Billing\Sunat\SunatException;
use Desertica\Billing\Tests\Support\Payloads;

final class EmitDocumentTest extends HttpTestCase
{
    protected string $pdfBinary = 'wkhtmltopdf';

    private function hasWkhtmltopdf(): bool
    {
        return trim((string) shell_exec('command -v wkhtmltopdf')) !== '';
    }

    public function testEmitsBoletaAndStoresFiles(): void
    {
        $response = $this->api('POST', '/v1/documents', Payloads::boleta());
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode(), (string) json_encode($body));
        self::assertSame(Payloads::BOLETA_ID, $body['externalId']);
        self::assertSame('ACCEPTED', $body['status']);
        self::assertSame(10000, $body['taxableCents']);
        self::assertSame(1800, $body['igvCents']);
        self::assertSame(11800, $body['totalCents']);
        self::assertSame('0', $body['sunatCode']);
        self::assertNotEmpty($body['hash']);
        self::assertNull($body['ticket']);
        self::assertSame(['xml' => true, 'cdr' => true, 'pdf' => $this->hasWkhtmltopdf()], $body['files']);
        self::assertCount(1, $this->gateway->sentDocuments);
    }

    public function testEmitsFacturaWithRoundedTotals(): void
    {
        $body = $this->json($this->api('POST', '/v1/documents', Payloads::factura()));

        self::assertSame('ACCEPTED', $body['status']);
        self::assertSame(34745, $body['taxableCents']);
        self::assertSame(6255, $body['igvCents']);
        self::assertSame(41000, $body['totalCents']);
    }

    public function testEmitsNotesAndUsdDocuments(): void
    {
        $factura = Payloads::factura(['currency' => 'USD', 'exchangeRate' => '3.7500']);
        self::assertSame('ACCEPTED', $this->json($this->api('POST', '/v1/documents', $factura))['status']);

        $credit = $this->json($this->api('POST', '/v1/documents', Payloads::creditNote()));
        self::assertSame('ACCEPTED', $credit['status'], (string) json_encode($credit));

        $debit = $this->json($this->api('POST', '/v1/documents', Payloads::creditNote([
            'externalId' => '44444444-4444-4444-8444-444444444444',
            'docType' => 'NOTA_DEBITO',
            'series' => 'FD01',
            'reasonCode' => '02',
        ])));
        self::assertSame('ACCEPTED', $debit['status']);
    }

    public function testSameRequestReturnsStoredResultWithoutResending(): void
    {
        $first = $this->json($this->api('POST', '/v1/documents', Payloads::boleta()));
        $second = $this->api('POST', '/v1/documents', Payloads::boleta());

        self::assertSame(200, $second->getStatusCode());
        self::assertSame($first, $this->json($second));
        self::assertCount(1, $this->gateway->sentDocuments, 'SUNAT no debe recibir el comprobante dos veces.');
    }

    public function testSameRequestWithReorderedKeysIsStillTheSameRequest(): void
    {
        $this->api('POST', '/v1/documents', Payloads::boleta());
        $reordered = array_reverse(Payloads::boleta(), true);
        $response = $this->api('POST', '/v1/documents', $reordered);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $this->gateway->sentDocuments);
    }

    public function testSameExternalIdWithOtherContentIsConflict(): void
    {
        $this->api('POST', '/v1/documents', Payloads::boleta());
        $other = Payloads::boleta(['customer' => ['idDocType' => 'DNI', 'idDocNumber' => '12345678', 'name' => 'OTRA PERSONA']]);
        $response = $this->api('POST', '/v1/documents', $other);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('IDEMPOTENCY_CONFLICT', $this->json($response)['code']);
        self::assertCount(1, $this->gateway->sentDocuments);
    }

    public function testSeriesAndNumberAlreadyUsedByAnotherExternalIdIsConflict(): void
    {
        $this->api('POST', '/v1/documents', Payloads::boleta());
        $response = $this->api('POST', '/v1/documents', Payloads::boleta(['externalId' => '99999999-9999-4999-8999-999999999999']));

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('SERIES_NUMBER_IN_USE', $this->json($response)['code']);
    }

    public function testInvalidBodyIs422WithContractErrorFormat(): void
    {
        $response = $this->api('POST', '/v1/documents', Payloads::boleta(['totalCents' => 5]));
        $body = $this->json($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('VALIDATION_ERROR', $body['code']);
        self::assertFalse($body['retryable']);
        self::assertArrayHasKey('message', $body);
        self::assertCount(0, $this->gateway->sentDocuments);
    }

    public function testMalformedJsonIs400(): void
    {
        $response = $this->call('POST', '/v1/documents', '{nope', ['Authorization' => 'Bearer ' . self::TOKEN]);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('INVALID_JSON', $this->json($response)['code']);
    }

    public function testSunatDownIs502RetryableAndRetryResendsTheSameSignedXml(): void
    {
        $this->gateway->enqueueDocumentResult(new SunatException(null, 'Bad Gateway', true));

        $down = $this->api('POST', '/v1/documents', Payloads::boleta());
        $error = $this->json($down);
        self::assertSame(502, $down->getStatusCode());
        self::assertTrue($error['retryable']);
        self::assertSame('SUNAT_UNAVAILABLE', $error['code']);

        $status = $this->json($this->api('GET', '/v1/documents/' . Payloads::BOLETA_ID));
        self::assertSame('PENDING', $status['status']);
        self::assertTrue($status['files']['xml']);

        $retry = $this->api('POST', '/v1/documents', Payloads::boleta());
        self::assertSame(200, $retry->getStatusCode());
        self::assertSame('ACCEPTED', $this->json($retry)['status']);
        self::assertCount(2, $this->gateway->sentDocuments);
        self::assertSame($this->gateway->sentDocuments[0], $this->gateway->sentDocuments[1], 'Se debe reenviar el mismo XML firmado.');
    }

    public function testSunatCdrRejectionIsReturnedAsRejected(): void
    {
        $this->gateway->enqueueDocumentResult(FakeSunatGateway::rejectedCdr('2335', 'El comprobante rechazado'));
        $response = $this->api('POST', '/v1/documents', Payloads::boleta());
        $body = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('REJECTED', $body['status']);
        self::assertSame('2335', $body['sunatCode']);
        self::assertTrue($body['files']['cdr']);
    }

    public function testSunatExceptionIs422AndRepeatsTheSameError(): void
    {
        $this->gateway->enqueueDocumentResult(new SunatException('0151', 'El nombre del archivo XML no es válido', false));

        $first = $this->api('POST', '/v1/documents', Payloads::boleta());
        $body = $this->json($first);
        self::assertSame(422, $first->getStatusCode());
        self::assertSame('SUNAT_EXCEPTION', $body['code']);
        self::assertSame('0151', $body['sunatCode']);
        self::assertFalse($body['retryable']);

        $again = $this->api('POST', '/v1/documents', Payloads::boleta());
        self::assertSame(422, $again->getStatusCode());
        self::assertSame($body, $this->json($again));
        self::assertCount(1, $this->gateway->sentDocuments);
        self::assertSame('ERROR', $this->json($this->api('GET', '/v1/documents/' . Payloads::BOLETA_ID))['status']);
    }

    public function testCdrObservationsAreKeptInTheMessage(): void
    {
        $zip = FakeSunatGateway::buildCdrZip('B001-1', '0', 'Aceptada');
        $this->gateway->enqueueDocumentResult(new \Desertica\Billing\Sunat\CdrResult(true, '0', 'Aceptada', $zip, ['4287 - Observación de prueba']));
        $body = $this->json($this->api('POST', '/v1/documents', Payloads::boleta()));

        self::assertSame('ACCEPTED', $body['status']);
        self::assertStringContainsString('4287', (string) $body['sunatMessage']);
    }

    public function testDailySummaryBoletaIsIssuedButNotSent(): void
    {
        $body = $this->json($this->api('POST', '/v1/documents', Payloads::boleta(['submission' => 'DAILY_SUMMARY'])));

        self::assertSame('ISSUED', $body['status']);
        self::assertTrue($body['files']['xml']);
        self::assertFalse($body['files']['cdr']);
        self::assertCount(0, $this->gateway->sentDocuments);
    }

    public function testGetDocumentAndUnknownDocument(): void
    {
        $this->api('POST', '/v1/documents', Payloads::boleta());

        $found = $this->api('GET', '/v1/documents/' . Payloads::BOLETA_ID);
        self::assertSame(200, $found->getStatusCode());
        self::assertSame('ACCEPTED', $this->json($found)['status']);

        $missing = $this->api('GET', '/v1/documents/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
        self::assertSame(404, $missing->getStatusCode());
        self::assertSame('NOT_FOUND', $this->json($missing)['code']);
    }

    public function testDownloadsFiles(): void
    {
        $this->api('POST', '/v1/documents', Payloads::boleta());
        $base = '/v1/documents/' . Payloads::BOLETA_ID . '/files/';

        $xml = $this->api('GET', $base . 'xml');
        self::assertSame(200, $xml->getStatusCode());
        self::assertSame('application/xml', $xml->getHeaderLine('Content-Type'));
        self::assertStringContainsString('<Invoice', (string) $xml->getBody());

        $cdr = $this->api('GET', $base . 'cdr');
        self::assertSame('application/zip', $cdr->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('PK', (string) $cdr->getBody());

        if (!$this->hasWkhtmltopdf()) {
            return;
        }
        $pdf = $this->api('GET', $base . 'pdf');
        self::assertSame('application/pdf', $pdf->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('%PDF', (string) $pdf->getBody());
    }

    public function testPdfFailureDoesNotBreakEmission(): void
    {
        // Binario inexistente: el comprobante se emite igual y el PDF queda pendiente.
        $this->pdfBinary = '/nonexistent/wkhtmltopdf';
        $this->setUp();

        $body = $this->json($this->api('POST', '/v1/documents', Payloads::boleta()));

        self::assertSame('ACCEPTED', $body['status']);
        self::assertFalse($body['files']['pdf']);
        self::assertSame(404, $this->api('GET', '/v1/documents/' . Payloads::BOLETA_ID . '/files/pdf')->getStatusCode());
    }

    public function testRegeneratedPdfDoesNotRevalidateTheStoredRequestWithTodaysRules(): void
    {
        if (!$this->hasWkhtmltopdf()) {
            self::markTestSkipped('wkhtmltopdf no está disponible.');
        }
        $pdo = new \PDO('sqlite::memory:');
        $emit = fn (string $binary) => \Desertica\Billing\App::create(
            $this->settings(['BILLING_WKHTMLTOPDF' => $binary]),
            new \Psr\Log\NullLogger(),
            $this->gateway,
            $this->clock,
            $pdo,
        );
        $this->app = $emit('/nonexistent/wkhtmltopdf');
        $this->api('POST', '/v1/documents', Payloads::boleta());
        // Una regla posterior (aquí, notes ≤ 1000 caracteres) habría rechazado lo que se aceptó antes: el PDF no debe depender de ella.
        $stored = json_decode((string) $pdo->query('SELECT request_json FROM documents')->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        $stored['notes'] = str_repeat('n', 1001);
        $pdo->prepare('UPDATE documents SET request_json = ?')->execute([json_encode($stored, JSON_THROW_ON_ERROR)]);

        $this->app = $emit('wkhtmltopdf');
        $pdf = $this->api('GET', '/v1/documents/' . Payloads::BOLETA_ID . '/files/pdf');

        self::assertSame(200, $pdf->getStatusCode());
        self::assertStringStartsWith('%PDF', (string) $pdf->getBody());
    }

    public function testMissingFileAndUnknownKindAre404(): void
    {
        $this->api('POST', '/v1/documents', Payloads::boleta(['submission' => 'DAILY_SUMMARY']));
        $base = '/v1/documents/' . Payloads::BOLETA_ID . '/files/';

        self::assertSame(404, $this->api('GET', $base . 'cdr')->getStatusCode());
        self::assertSame(404, $this->api('GET', $base . 'exe')->getStatusCode());
        self::assertSame(404, $this->api('GET', '/v1/documents/not-a-uuid/files/xml')->getStatusCode());
    }

    public function testBoletaDefaultSubmissionFollowsConfiguration(): void
    {
        $this->app = \Desertica\Billing\App::create(
            $this->settings(['BILLING_BOLETA_SUBMISSION' => 'DAILY_SUMMARY']),
            new \Psr\Log\NullLogger(),
            $this->gateway,
            $this->clock,
            new \PDO('sqlite::memory:'),
        );

        // Boleta: resumen diario por defecto; factura: siempre inmediata.
        self::assertSame('ISSUED', $this->json($this->api('POST', '/v1/documents', Payloads::boleta()))['status']);
        self::assertSame('ACCEPTED', $this->json($this->api('POST', '/v1/documents', Payloads::factura()))['status']);
    }

    public function testEmissionRequiresToken(): void
    {
        $response = $this->call('POST', '/v1/documents', json_encode(Payloads::boleta(), JSON_THROW_ON_ERROR));

        self::assertSame(401, $response->getStatusCode());
    }
}
