<?php

declare(strict_types=1);

namespace Desertica\Billing\Tests\Http;

use Desertica\Billing\Tests\Support\Payloads;

/**
 * Decisiones pendientes del contador, como configuración: con los valores por defecto el servicio es el más estricto.
 */
final class AccountantDecisionsTest extends HttpTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function boletaWithoutCustomer(int $totalCents = 11800, string $currency = 'PEN'): array
    {
        $payload = Payloads::boleta([
            'currency' => $currency,
            'items' => [['description' => 'Tour', 'quantity' => 1, 'unitPriceCents' => $totalCents, 'totalCents' => $totalCents]],
            'totalCents' => $totalCents,
        ] + ($currency === 'USD' ? ['exchangeRate' => '3.7500'] : []));
        unset($payload['customer']);

        return $payload;
    }

    private function xpath(string $xml, string $query): string
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);

        return (string) (new \DOMXPath($dom))->evaluate($query);
    }

    public function testByDefaultTheReceiverIsAlwaysRequired(): void
    {
        $response = $this->api('POST', '/v1/documents', $this->boletaWithoutCustomer(100));

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('VALIDATION_ERROR', $this->json($response)['code']);
        self::assertStringContainsString('customer', (string) $this->json($response)['message']);
        self::assertCount(0, $this->gateway->sentDocuments);
    }

    public function testAnonymousBoletaIsAcceptedUpToTheConfiguredCap(): void
    {
        $this->rebuild(['BILLING_ANONYMOUS_BOLETA_MAX_CENTS' => '11800']);

        $response = $this->api('POST', '/v1/documents', $this->boletaWithoutCustomer(11800));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ACCEPTED', $this->json($response)['status']);
        $xml = $this->gateway->sentDocuments[0];
        self::assertSame('0', $this->xpath($xml, 'string(//*[local-name()="AccountingCustomerParty"]//*[local-name()="PartyIdentification"]/*[local-name()="ID"]/@schemeID)'));
        self::assertSame('CLIENTES VARIOS', $this->xpath($xml, 'string(//*[local-name()="AccountingCustomerParty"]//*[local-name()="RegistrationName"])'));
    }

    public function testAnonymousBoletaAboveTheCapStillNeedsAReceiver(): void
    {
        $this->rebuild(['BILLING_ANONYMOUS_BOLETA_MAX_CENTS' => '11800']);

        $response = $this->api('POST', '/v1/documents', $this->boletaWithoutCustomer(11801));

        self::assertSame(422, $response->getStatusCode());
        self::assertCount(0, $this->gateway->sentDocuments);
    }

    public function testTheCapDoesNotApplyToUsdFacturasOrNotes(): void
    {
        $this->rebuild(['BILLING_ANONYMOUS_BOLETA_MAX_CENTS' => '1000000']);

        self::assertSame(422, $this->api('POST', '/v1/documents', $this->boletaWithoutCustomer(5000, 'USD'))->getStatusCode());

        $factura = Payloads::factura();
        unset($factura['customer']);
        self::assertSame(422, $this->api('POST', '/v1/documents', $factura)->getStatusCode());

        $note = Payloads::creditNote(['series' => 'BC01', 'affectedDocument' => ['docType' => 'BOLETA', 'series' => 'B001', 'number' => 1]]);
        unset($note['customer']);
        self::assertSame(422, $this->api('POST', '/v1/documents', $note)->getStatusCode());
    }

    public function testAnonymousBoletaIdempotencyAndConflict(): void
    {
        $this->rebuild(['BILLING_ANONYMOUS_BOLETA_MAX_CENTS' => '50000']);
        $payload = $this->boletaWithoutCustomer();

        $first = $this->json($this->api('POST', '/v1/documents', $payload));
        $second = $this->json($this->api('POST', '/v1/documents', $payload));
        self::assertSame($first, $second);
        self::assertCount(1, $this->gateway->sentDocuments);

        // Añadir un receptor después es otro contenido para la misma externalId.
        $withCustomer = Payloads::boleta(['items' => $payload['items'], 'totalCents' => $payload['totalCents']]);
        self::assertSame(409, $this->api('POST', '/v1/documents', $withCustomer)->getStatusCode());
    }

    public function testAnonymousBoletaGoesIntoTheDailySummaryWithoutReceiver(): void
    {
        $this->rebuild(['BILLING_ANONYMOUS_BOLETA_MAX_CENTS' => '50000']);
        $this->api('POST', '/v1/documents', $this->boletaWithoutCustomer() + ['submission' => 'DAILY_SUMMARY']);

        $response = $this->api('POST', '/v1/summaries/daily', ['date' => '2026-10-04']);

        self::assertSame(200, $response->getStatusCode());
        $xml = $this->gateway->sentBatches[0];
        $validator = new \Greenter\Ubl\UblValidator();
        self::assertTrue($validator->isValid($xml), 'XSD: ' . $validator->getError());
        self::assertSame('0', $this->xpath($xml, 'string(//*[local-name()="AccountingCustomerParty"]/*[local-name()="AdditionalAccountID"])'));
        self::assertSame('-', $this->xpath($xml, 'string(//*[local-name()="AccountingCustomerParty"]/*[local-name()="CustomerAssignedAccountID"])'));
    }

    public function testUnitCodeAndLegendFlowThroughTheEmittedXml(): void
    {
        $this->rebuild(['BILLING_UNIT_CODE' => 'ZZ', 'BILLING_LEGEND_2004' => 'true']);

        $this->api('POST', '/v1/documents', Payloads::boleta());

        $xml = $this->gateway->sentDocuments[0];
        self::assertSame('ZZ', $this->xpath($xml, 'string(//*[local-name()="InvoiceLine"][1]/*[local-name()="InvoicedQuantity"]/@unitCode)'));
        self::assertSame('Agencia de Viaje – Paquete turístico', $this->xpath($xml, 'string(//*[local-name()="Note"][@languageLocaleID="2004"])'));
    }

    public function testDefaultsEmitNiuWithoutTheLegend(): void
    {
        $this->api('POST', '/v1/documents', Payloads::boleta());

        $xml = $this->gateway->sentDocuments[0];
        self::assertSame('NIU', $this->xpath($xml, 'string(//*[local-name()="InvoiceLine"][1]/*[local-name()="InvoicedQuantity"]/@unitCode)'));
        self::assertSame('0', $this->xpath($xml, 'string(count(//*[local-name()="Note"][@languageLocaleID="2004"]))'));
    }
}
