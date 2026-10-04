<?php

declare(strict_types=1);

namespace Desertica\Billing\Tests\Http;

use Desertica\Billing\Sunat\SunatException;
use Desertica\Billing\Sunat\TicketStatus;
use Desertica\Billing\Tests\Support\Payloads;

final class BatchTest extends HttpTestCase
{
    private const BOLETA_2 = '55555555-5555-4555-8555-555555555555';

    /**
     * @return array<string, mixed>
     */
    private function void(string $id, string $reason = 'ERROR EN CÁLCULOS', string $date = '2026-10-04'): array
    {
        $response = $this->api('POST', '/v1/documents/' . $id . '/void', ['reason' => $reason, 'voidDate' => $date]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $this->json($response);
    }

    public function testVoidsFacturaWithCommunicationOfVoidedDocuments(): void
    {
        $this->api('POST', '/v1/documents', Payloads::factura());

        $ticket = $this->void(Payloads::FACTURA_ID);

        self::assertSame('PENDING', $ticket['status']);
        self::assertNotEmpty($ticket['ticket']);
        self::assertCount(1, $this->gateway->sentBatches);
        self::assertStringContainsString('VoidedDocuments', $this->gateway->sentBatches[0]);
        self::assertStringContainsString('RA-20261004-00001', $this->xmlId($this->gateway->sentBatches[0]));
        // Hasta que SUNAT confirme el ticket el comprobante sigue aceptado.
        self::assertSame('ACCEPTED', $this->json($this->api('GET', '/v1/documents/' . Payloads::FACTURA_ID))['status']);
    }

    public function testTicketAcceptedMarksDocumentVoided(): void
    {
        $this->api('POST', '/v1/documents', Payloads::factura());
        $ticket = $this->void(Payloads::FACTURA_ID)['ticket'];

        $result = $this->json($this->api('GET', '/v1/tickets/' . $ticket));

        self::assertSame('ACCEPTED', $result['status']);
        self::assertSame($ticket, $result['ticket']);
        self::assertSame('VOIDED', $this->json($this->api('GET', '/v1/documents/' . Payloads::FACTURA_ID))['status']);
    }

    public function testPendingTicketStaysPendingThenAccepts(): void
    {
        $this->api('POST', '/v1/documents', Payloads::factura());
        $ticket = $this->void(Payloads::FACTURA_ID)['ticket'];

        $this->gateway->enqueueTicketStatus(new TicketStatus(TicketStatus::PENDING, '98', 'En proceso'));
        self::assertSame('PENDING', $this->json($this->api('GET', '/v1/tickets/' . $ticket))['status']);
        self::assertSame('ACCEPTED', $this->json($this->api('GET', '/v1/documents/' . Payloads::FACTURA_ID))['status']);

        self::assertSame('ACCEPTED', $this->json($this->api('GET', '/v1/tickets/' . $ticket))['status']);
        self::assertSame('VOIDED', $this->json($this->api('GET', '/v1/documents/' . Payloads::FACTURA_ID))['status']);
    }

    public function testRejectedVoidReleasesTheDocumentForRetry(): void
    {
        $this->api('POST', '/v1/documents', Payloads::factura());
        $ticket = $this->void(Payloads::FACTURA_ID)['ticket'];
        $this->gateway->enqueueTicketStatus(new TicketStatus(TicketStatus::REJECTED, '2000', 'Baja rechazada'));

        $result = $this->json($this->api('GET', '/v1/tickets/' . $ticket));
        self::assertSame('REJECTED', $result['status']);
        self::assertSame('2000', $result['sunatCode']);
        self::assertSame('ACCEPTED', $this->json($this->api('GET', '/v1/documents/' . Payloads::FACTURA_ID))['status']);

        // Se puede pedir una baja nueva (otro correlativo RA).
        $second = $this->void(Payloads::FACTURA_ID);
        self::assertNotSame($ticket, $second['ticket']);
        self::assertStringContainsString('RA-20261004-00002', $this->xmlId($this->gateway->sentBatches[1]));
    }

    public function testRepeatedVoidReturnsSameTicketAndOtherReasonConflicts(): void
    {
        $this->api('POST', '/v1/documents', Payloads::factura());
        $first = $this->void(Payloads::FACTURA_ID);
        $again = $this->void(Payloads::FACTURA_ID);

        self::assertSame($first['ticket'], $again['ticket']);
        self::assertCount(1, $this->gateway->sentBatches);

        $conflict = $this->api('POST', '/v1/documents/' . Payloads::FACTURA_ID . '/void', ['reason' => 'OTRO', 'voidDate' => '2026-10-04']);
        self::assertSame(409, $conflict->getStatusCode());
        self::assertSame('VOID_CONFLICT', $this->json($conflict)['code']);
    }

    public function testBoletaIsVoidedThroughDailySummaryWithStateThree(): void
    {
        $this->api('POST', '/v1/documents', Payloads::boleta());

        $ticket = $this->void(Payloads::BOLETA_ID);

        self::assertSame('PENDING', $ticket['status']);
        $xml = $this->gateway->sentBatches[0];
        self::assertStringContainsString('SummaryDocuments', $xml);
        self::assertStringContainsString('RC-20261004-001', $this->xmlId($xml));
        self::assertSame('3', $this->xpathValue($xml, 'string(//*[local-name()="ConditionCode"])'));
        self::assertSame('B001-1', $this->xpathValue($xml, '//*[local-name()="SummaryDocumentsLine"]/*[local-name()="ID"]'));
    }

    public function testCannotVoidUnknownUnsentOrAlreadyVoided(): void
    {
        $missing = $this->api('POST', '/v1/documents/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa/void', ['reason' => 'x', 'voidDate' => '2026-10-04']);
        self::assertSame(404, $missing->getStatusCode());

        $this->api('POST', '/v1/documents', Payloads::boleta(['submission' => 'DAILY_SUMMARY']));
        $unsent = $this->api('POST', '/v1/documents/' . Payloads::BOLETA_ID . '/void', ['reason' => 'x', 'voidDate' => '2026-10-04']);
        self::assertSame(409, $unsent->getStatusCode());
        self::assertSame('NOT_VOIDABLE', $this->json($unsent)['code']);

        $this->api('POST', '/v1/documents', Payloads::factura());
        $ticket = $this->void(Payloads::FACTURA_ID)['ticket'];
        $this->api('GET', '/v1/tickets/' . $ticket);
        $voided = $this->api('POST', '/v1/documents/' . Payloads::FACTURA_ID . '/void', ['reason' => 'ERROR EN CÁLCULOS', 'voidDate' => '2026-10-04']);
        // Repetir la misma baja ya confirmada devuelve su ticket.
        self::assertSame(200, $voided->getStatusCode());
        $other = $this->api('POST', '/v1/documents/' . Payloads::FACTURA_ID . '/void', ['reason' => 'OTRA', 'voidDate' => '2026-10-04']);
        self::assertSame(409, $other->getStatusCode());
    }

    public function testRejectedDocumentCannotBeVoided(): void
    {
        $this->gateway->enqueueDocumentResult(\Desertica\Billing\Sunat\FakeSunatGateway::rejectedCdr());
        $this->api('POST', '/v1/documents', Payloads::factura());

        $response = $this->api('POST', '/v1/documents/' . Payloads::FACTURA_ID . '/void', ['reason' => 'x', 'voidDate' => '2026-10-04']);

        self::assertSame(409, $response->getStatusCode());
    }

    public function testVoidValidation(): void
    {
        $this->api('POST', '/v1/documents', Payloads::factura());
        $url = '/v1/documents/' . Payloads::FACTURA_ID . '/void';

        self::assertSame(422, $this->api('POST', $url, ['voidDate' => '2026-10-04'])->getStatusCode());
        self::assertSame(422, $this->api('POST', $url, ['reason' => str_repeat('x', 101), 'voidDate' => '2026-10-04'])->getStatusCode());
        self::assertSame(422, $this->api('POST', $url, ['reason' => 'x', 'voidDate' => '2026-13-01'])->getStatusCode());
        self::assertSame(422, $this->api('POST', $url, ['reason' => 'x', 'voidDate' => '2026-10-05'])->getStatusCode(), 'fecha futura');
        self::assertSame(422, $this->api('POST', $url, ['reason' => 'x', 'voidDate' => '2026-10-03'])->getStatusCode(), 'anterior a la emisión');
        self::assertCount(0, $this->gateway->sentBatches);
    }

    public function testVoidWhenSunatIsDownIs502AndNothingIsRecorded(): void
    {
        $this->api('POST', '/v1/documents', Payloads::factura());
        $this->gateway->enqueueBatchFailure(new SunatException(null, 'Bad Gateway', true));

        $down = $this->api('POST', '/v1/documents/' . Payloads::FACTURA_ID . '/void', ['reason' => 'x', 'voidDate' => '2026-10-04']);
        self::assertSame(502, $down->getStatusCode());
        self::assertTrue($this->json($down)['retryable']);

        // El reintento funciona.
        self::assertSame('PENDING', $this->void(Payloads::FACTURA_ID, 'x')['status']);
    }

    public function testDailySummarySendsPendingBoletasAndAcceptedTicketAcceptsThem(): void
    {
        $this->api('POST', '/v1/documents', Payloads::boleta(['submission' => 'DAILY_SUMMARY']));
        $this->api('POST', '/v1/documents', Payloads::boleta([
            'externalId' => self::BOLETA_2,
            'number' => 2,
            'customer' => ['idDocType' => 'CE', 'idDocNumber' => '001234567', 'name' => 'JOHN SMITH'],
            'submission' => 'DAILY_SUMMARY',
        ]));

        $response = $this->api('POST', '/v1/summaries/daily', ['date' => '2026-10-04']);
        $ticket = $this->json($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('PENDING', $ticket['status']);
        self::assertCount(1, $this->gateway->sentBatches);
        $xml = $this->gateway->sentBatches[0];
        self::assertStringContainsString('RC-20261004-001', $this->xmlId($xml));
        self::assertSame('2', $this->xpathValue($xml, 'count(//*[local-name()="SummaryDocumentsLine"])'));
        self::assertSame('1', $this->xpathValue($xml, '//*[local-name()="SummaryDocumentsLine"][1]/*[local-name()="Status"]/*[local-name()="ConditionCode"]'));
        // Mientras SUNAT procesa, las boletas no se vuelven a incluir.
        self::assertSame('ISSUED', $this->json($this->api('GET', '/v1/documents/' . Payloads::BOLETA_ID))['status']);

        $accepted = $this->json($this->api('GET', '/v1/tickets/' . $ticket['ticket']));
        self::assertSame('ACCEPTED', $accepted['status']);
        foreach ([Payloads::BOLETA_ID, self::BOLETA_2] as $id) {
            self::assertSame('ACCEPTED', $this->json($this->api('GET', '/v1/documents/' . $id))['status']);
        }
    }

    public function testDailySummaryRepeatedReturnsTheSameTicketAndNothingToSummarizeIs422(): void
    {
        $none = $this->api('POST', '/v1/summaries/daily', ['date' => '2026-10-04']);
        self::assertSame(422, $none->getStatusCode());
        self::assertSame('NOTHING_TO_SUMMARIZE', $this->json($none)['code']);

        $this->api('POST', '/v1/documents', Payloads::boleta(['submission' => 'DAILY_SUMMARY']));
        $first = $this->json($this->api('POST', '/v1/summaries/daily', ['date' => '2026-10-04']));
        $second = $this->json($this->api('POST', '/v1/summaries/daily', ['date' => '2026-10-04']));

        self::assertSame($first['ticket'], $second['ticket']);
        self::assertCount(1, $this->gateway->sentBatches);
    }

    public function testDailySummaryHandlesOneCurrencyPerCall(): void
    {
        $this->api('POST', '/v1/documents', Payloads::boleta(['submission' => 'DAILY_SUMMARY']));
        $this->api('POST', '/v1/documents', Payloads::boleta([
            'externalId' => self::BOLETA_2,
            'number' => 2,
            'currency' => 'USD',
            'exchangeRate' => '3.7500',
            'submission' => 'DAILY_SUMMARY',
        ]));

        $pen = $this->json($this->api('POST', '/v1/summaries/daily', ['date' => '2026-10-04']));
        $usd = $this->json($this->api('POST', '/v1/summaries/daily', ['date' => '2026-10-04']));

        self::assertNotSame($pen['ticket'], $usd['ticket']);
        self::assertCount(2, $this->gateway->sentBatches);
        self::assertSame('PEN', $this->xpathValue($this->gateway->sentBatches[0], 'string(//*[local-name()="TotalAmount"]/@currencyID)'));
        self::assertSame('USD', $this->xpathValue($this->gateway->sentBatches[1], 'string(//*[local-name()="TotalAmount"]/@currencyID)'));
    }

    public function testDailySummaryWithCurrencyAttendsThatCurrencyFirst(): void
    {
        $this->api('POST', '/v1/documents', Payloads::boleta(['submission' => 'DAILY_SUMMARY']));
        $this->api('POST', '/v1/documents', Payloads::boleta([
            'externalId' => self::BOLETA_2,
            'number' => 2,
            'currency' => 'USD',
            'exchangeRate' => '3.7500',
            'submission' => 'DAILY_SUMMARY',
        ]));

        $usd = $this->json($this->api('POST', '/v1/summaries/daily', ['date' => '2026-10-04', 'currency' => 'USD']));
        self::assertSame('USD', $this->xpathValue($this->gateway->sentBatches[0], 'string(//*[local-name()="TotalAmount"]/@currencyID)'));
        self::assertSame('1', $this->xpathValue($this->gateway->sentBatches[0], 'count(//*[local-name()="SummaryDocumentsLine"])'));

        // Repetir la misma moneda devuelve su ticket sin reenviar; la otra moneda sigue pendiente.
        $again = $this->json($this->api('POST', '/v1/summaries/daily', ['date' => '2026-10-04', 'currency' => 'USD']));
        self::assertSame($usd['ticket'], $again['ticket']);
        self::assertCount(1, $this->gateway->sentBatches);

        $pen = $this->json($this->api('POST', '/v1/summaries/daily', ['date' => '2026-10-04', 'currency' => 'PEN']));
        self::assertNotSame($usd['ticket'], $pen['ticket']);
        self::assertSame('PEN', $this->xpathValue($this->gateway->sentBatches[1], 'string(//*[local-name()="TotalAmount"]/@currencyID)'));
    }

    public function testDailySummaryWithCurrencyWithoutPendingBoletasIsNothingToSummarize(): void
    {
        $this->api('POST', '/v1/documents', Payloads::boleta(['submission' => 'DAILY_SUMMARY']));

        $response = $this->api('POST', '/v1/summaries/daily', ['date' => '2026-10-04', 'currency' => 'USD']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('NOTHING_TO_SUMMARIZE', $this->json($response)['code']);
        self::assertCount(0, $this->gateway->sentBatches);
    }

    public function testDailySummaryRejectsAnUnknownCurrency(): void
    {
        $response = $this->api('POST', '/v1/summaries/daily', ['date' => '2026-10-04', 'currency' => 'EUR']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('VALIDATION_ERROR', $this->json($response)['code']);
    }

    public function testAVoidSummaryIsNotReturnedAsTheDailySummary(): void
    {
        $this->api('POST', '/v1/documents', Payloads::boleta());
        $this->api('POST', '/v1/documents/' . Payloads::BOLETA_ID . '/void', ['reason' => 'x', 'voidDate' => '2026-10-04']);

        $response = $this->api('POST', '/v1/summaries/daily', ['date' => '2026-10-04']);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('NOTHING_TO_SUMMARIZE', $this->json($response)['code']);
    }

    public function testRejectedSummaryReleasesBoletasForANewSummary(): void
    {
        $this->api('POST', '/v1/documents', Payloads::boleta(['submission' => 'DAILY_SUMMARY']));
        $ticket = $this->json($this->api('POST', '/v1/summaries/daily', ['date' => '2026-10-04']))['ticket'];
        $this->gateway->enqueueTicketStatus(new TicketStatus(TicketStatus::REJECTED, '99', 'Procesado con errores'));

        self::assertSame('REJECTED', $this->json($this->api('GET', '/v1/tickets/' . $ticket))['status']);
        self::assertSame('ISSUED', $this->json($this->api('GET', '/v1/documents/' . Payloads::BOLETA_ID))['status']);

        $retry = $this->json($this->api('POST', '/v1/summaries/daily', ['date' => '2026-10-04']));
        self::assertNotSame($ticket, $retry['ticket']);
        self::assertCount(2, $this->gateway->sentBatches);
    }

    public function testNoteOfBoletaGoesInTheSummaryWithItsReference(): void
    {
        $this->api('POST', '/v1/documents', Payloads::boleta(['submission' => 'DAILY_SUMMARY']));
        $this->api('POST', '/v1/documents', Payloads::creditNote([
            'series' => 'BC01',
            'customer' => ['idDocType' => 'DNI', 'idDocNumber' => '46712369', 'name' => 'MARIA RAMOS ARTEAGA'],
            'affectedDocument' => ['docType' => 'BOLETA', 'series' => 'B001', 'number' => 1],
            'submission' => 'DAILY_SUMMARY',
        ]));

        $this->api('POST', '/v1/summaries/daily', ['date' => '2026-10-04']);
        $xml = $this->gateway->sentBatches[0];

        self::assertSame('2', $this->xpathValue($xml, 'count(//*[local-name()="SummaryDocumentsLine"])'));
        self::assertSame('07', $this->xpathValue($xml, '//*[local-name()="SummaryDocumentsLine"][2]/*[local-name()="DocumentTypeCode"]'));
        self::assertSame('B001-1', $this->xpathValue($xml, '//*[local-name()="BillingReference"]//*[local-name()="ID"]'));
    }

    public function testSummaryValidationAndUnknownTicket(): void
    {
        self::assertSame(422, $this->api('POST', '/v1/summaries/daily', [])->getStatusCode());
        self::assertSame(422, $this->api('POST', '/v1/summaries/daily', ['date' => 'ayer'])->getStatusCode());
        self::assertSame(404, $this->api('GET', '/v1/tickets/NOPE')->getStatusCode());
    }

    public function testTicketWhenSunatIsDownIs502(): void
    {
        $this->api('POST', '/v1/documents', Payloads::factura());
        $ticket = $this->void(Payloads::FACTURA_ID)['ticket'];
        $this->gateway->enqueueTicketStatus(new SunatException(null, 'Bad Gateway', true));

        $response = $this->api('GET', '/v1/tickets/' . $ticket);

        self::assertSame(502, $response->getStatusCode());
        // El fallo no cambia nada: el comprobante sigue aceptado y la siguiente consulta sí confirma la baja.
        self::assertSame('ACCEPTED', $this->json($this->api('GET', '/v1/documents/' . Payloads::FACTURA_ID))['status']);
        self::assertSame('ACCEPTED', $this->json($this->api('GET', '/v1/tickets/' . $ticket))['status']);
        self::assertSame('VOIDED', $this->json($this->api('GET', '/v1/documents/' . Payloads::FACTURA_ID))['status']);
    }

    private function xmlId(string $xml): string
    {
        return $this->xpathValue($xml, 'string(/*/*[local-name()="ID"])');
    }

    private function xpathValue(string $xml, string $expression): string
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $result = (new \DOMXPath($dom))->evaluate($expression);
        if ($result instanceof \DOMNodeList) {
            return trim((string) $result->item(0)?->textContent);
        }

        return (string) $result;
    }
}
