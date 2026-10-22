<?php

declare(strict_types=1);

namespace Desertica\Billing\Tests\Xml;

use Desertica\Billing\Config\BillingOptions;
use Desertica\Billing\Config\Issuer;
use Desertica\Billing\Domain\RequestValidator;
use Desertica\Billing\Security\CertificateProvider;
use Desertica\Billing\Tests\Support\Payloads;
use Desertica\Billing\Xml\DocumentFactory;
use Desertica\Billing\Xml\XmlSigner;
use Greenter\Ubl\UblValidator;
use PHPUnit\Framework\TestCase;

/**
 * Los interruptores de las decisiones del contador cambian el XML (y solo cuando se activan); cada XML sigue siendo válido contra el XSD.
 */
final class AccountantOptionsTest extends TestCase
{
    private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
    private const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    private static string $pem;

    public static function setUpBeforeClass(): void
    {
        self::$pem = CertificateProvider::generateTestCertificate();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function xml(array $payload, BillingOptions $options, int $anonymousMax = 0): \DOMXPath
    {
        $request = (new RequestValidator($anonymousMax))->validate($payload, new \DateTimeImmutable('2026-10-04 12:00:00', new \DateTimeZone('America/Lima')));
        $built = (new DocumentFactory(new Issuer('20000000001', 'DESERTICA SAC', 'DESERTICA', 'AV PRUEBA 123', '150101', 'LIMA', 'LIMA', 'LIMA', '0000'), $options))
            ->build($request);
        $xml = (new XmlSigner(self::$pem))->sign($built['document'])->xml;

        $validator = new UblValidator();
        self::assertTrue($validator->isValid($xml), 'XSD: ' . $validator->getError());
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('cbc', self::CBC);
        $xpath->registerNamespace('cac', self::CAC);

        return $xpath;
    }

    public function testDefaultsKeepNiuAndNoTravelAgencyLegend(): void
    {
        $xpath = $this->xml(Payloads::boleta(), new BillingOptions());

        self::assertSame('NIU', $xpath->evaluate('string(//cac:InvoiceLine[1]/cbc:InvoicedQuantity/@unitCode)'));
        self::assertSame(0, (int) $xpath->evaluate('count(//cbc:Note[@languageLocaleID="2004"])'));
    }

    public function testUnitCodeIsConfigurable(): void
    {
        $xpath = $this->xml(Payloads::factura(), new BillingOptions(unitCode: 'ZZ'));

        self::assertSame('ZZ', $xpath->evaluate('string(//cac:InvoiceLine[1]/cbc:InvoicedQuantity/@unitCode)'));
        self::assertSame('ZZ', $xpath->evaluate('string(//cac:InvoiceLine[2]/cbc:InvoicedQuantity/@unitCode)'));
    }

    public function testTravelAgencyLegendIsAddedToBoletasAndFacturasWhenEnabled(): void
    {
        $options = new BillingOptions(travelAgencyLegend: true);

        foreach ([Payloads::boleta(), Payloads::factura()] as $payload) {
            $xpath = $this->xml($payload, $options);
            self::assertSame('Agencia de Viaje – Paquete turístico', $xpath->evaluate('string(//cbc:Note[@languageLocaleID="2004"])'));
            self::assertStringStartsWith('SON ', $xpath->evaluate('string(//cbc:Note[@languageLocaleID="1000"])'));
        }
    }

    public function testTravelAgencyLegendIsNotAddedToNotes(): void
    {
        $xpath = $this->xml(Payloads::creditNote(), new BillingOptions(travelAgencyLegend: true));

        self::assertSame(0, (int) $xpath->evaluate('count(//cbc:Note[@languageLocaleID="2004"])'));
    }

    public function testAnonymousBoletaUsesTheGenericReceiverWhenUnderTheCap(): void
    {
        $payload = Payloads::boleta();
        unset($payload['customer']);

        $xpath = $this->xml($payload, new BillingOptions(), anonymousMax: 70000);

        self::assertSame('0', $xpath->evaluate('string(//cac:AccountingCustomerParty//cac:PartyIdentification/cbc:ID/@schemeID)'));
        self::assertSame('-', $xpath->evaluate('string(//cac:AccountingCustomerParty//cac:PartyIdentification/cbc:ID)'));
        self::assertSame('CLIENTES VARIOS', $xpath->evaluate('string(//cac:AccountingCustomerParty//cac:PartyLegalEntity/cbc:RegistrationName)'));
    }
}
