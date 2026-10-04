<?php

declare(strict_types=1);

namespace Desertica\Billing\Tests\Xml;

use Desertica\Billing\Config\Issuer;
use Desertica\Billing\Domain\RequestValidator;
use Desertica\Billing\Security\CertificateProvider;
use Desertica\Billing\Tests\Support\Payloads;
use Desertica\Billing\Xml\DocumentFactory;
use Desertica\Billing\Xml\XmlSigner;
use Greenter\Ubl\UblValidator;
use Greenter\Ws\Reader\XmlFilenameExtractor;
use Greenter\Ws\Reader\XmlReader;
use Greenter\Ws\Resolver\XmlTypeResolver;
use Greenter\XMLSecLibs\Sunat\SignedXml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Sin red: genera el XML UBL con Greenter, lo firma con un certificado de prueba creado en tiempo de ejecución y lo valida
 * contra el XSD de UBL 2.1 (`greenter/ubl-validator`).
 */
final class XmlSigningTest extends TestCase
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
     * @return array{xml: string, name: string, hash: string, taxable: int, igv: int, total: int}
     */
    private function signed(array $payload): array
    {
        $request = (new RequestValidator())->validate($payload, new \DateTimeImmutable('2026-10-04 12:00:00', new \DateTimeZone('America/Lima')));
        $built = (new DocumentFactory(new Issuer('20000000001', 'DESERTICA SAC', 'DESERTICA', 'AV PRUEBA 123', '150101', 'LIMA', 'LIMA', 'LIMA', '0000')))
            ->build($request);
        $signed = (new XmlSigner(self::$pem))->sign($built['document']);

        return [
            'xml' => $signed->xml,
            'name' => $signed->fileName,
            'hash' => $signed->hash,
            'taxable' => $built['taxableCents'],
            'igv' => $built['igvCents'],
            'total' => $built['totalCents'],
        ];
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string, string}>
     */
    public static function documents(): iterable
    {
        $dni = ['idDocType' => 'DNI', 'idDocNumber' => '46712369', 'name' => 'MARIA RAMOS ARTEAGA'];
        $ce = ['idDocType' => 'CE', 'idDocNumber' => '001234567', 'name' => 'JOHN SMITH'];
        $passport = ['idDocType' => 'PASSPORT', 'idDocNumber' => 'AB1234567', 'name' => 'JANE DOE'];
        $ruc = ['idDocType' => 'RUC', 'idDocNumber' => '20100070970', 'name' => 'EMPRESA CLIENTE SAC', 'address' => 'AV LIMA 123'];

        // [payload, tipo de documento (catálogo 01), tipo de documento de identidad (catálogo 06), moneda]
        yield 'boleta DNI PEN' => [Payloads::boleta(['customer' => $dni]), '03', '1', 'PEN'];
        yield 'boleta CE PEN' => [Payloads::boleta(['customer' => $ce]), '03', '4', 'PEN'];
        yield 'boleta pasaporte USD' => [Payloads::boleta(['customer' => $passport, 'currency' => 'USD', 'exchangeRate' => '3.7500']), '03', '7', 'USD'];
        yield 'boleta RUC PEN' => [Payloads::boleta(['customer' => $ruc]), '03', '6', 'PEN'];
        yield 'factura RUC PEN' => [Payloads::factura(), '01', '6', 'PEN'];
        yield 'factura RUC USD' => [Payloads::factura(['currency' => 'USD', 'exchangeRate' => '3.7500']), '01', '6', 'USD'];
        yield 'nota de crédito de factura' => [Payloads::creditNote(), '07', '6', 'PEN'];
        yield 'nota de crédito de boleta' => [Payloads::creditNote([
            'series' => 'BC01',
            'customer' => $dni,
            'affectedDocument' => ['docType' => 'BOLETA', 'series' => 'B001', 'number' => 7],
        ]), '07', '1', 'PEN'];
        yield 'nota de débito de factura' => [Payloads::creditNote([
            'docType' => 'NOTA_DEBITO',
            'series' => 'FD01',
            'reasonCode' => '02',
            'reason' => 'Aumento en el valor',
        ]), '08', '6', 'PEN'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('documents')]
    public function testSignedXmlIsValidAgainstXsdAndSignatureVerifies(array $payload, string $typeCode, string $idType, string $currency): void
    {
        $doc = $this->signed($payload);

        $validator = new UblValidator();
        self::assertTrue($validator->isValid($doc['xml']), 'XSD: ' . $validator->getError());
        self::assertTrue($this->verifies($doc['xml']), 'La firma no verifica.');
        self::assertStringStartsWith('20000000001-' . $typeCode . '-', $doc['name']);
        // `See::sendXmlFile` (lo que usa el gateway real) deduce tipo y nombre del propio XML: deben coincidir con los nuestros.
        $reader = new XmlReader();
        $dom = $reader->parseToDocument($doc['xml']);
        self::assertSame($doc['name'], (new XmlFilenameExtractor($reader))->getFilename($dom));
        self::assertNotNull((new XmlTypeResolver($reader))->getType($dom));
        self::assertNotSame('', $doc['hash']);

        $xpath = $this->xpath($doc['xml']);
        self::assertSame($idType, $xpath->evaluate('string(//cac:AccountingCustomerParty//cac:PartyIdentification/cbc:ID/@schemeID)'));
        self::assertSame($currency, $xpath->evaluate('string(//cbc:DocumentCurrencyCode)'));
        self::assertSame(
            number_format($doc['total'] / 100, 2, '.', ''),
            $xpath->evaluate('string(//*[local-name()="LegalMonetaryTotal" or local-name()="RequestedMonetaryTotal"]/cbc:PayableAmount)'),
        );
        self::assertSame(
            number_format($doc['igv'] / 100, 2, '.', ''),
            $xpath->evaluate('string(/*/cac:TaxTotal/cbc:TaxAmount)'),
        );
        // Un solo emisor y la leyenda de monto en letras.
        self::assertSame('20000000001', $xpath->evaluate('string(//cac:AccountingSupplierParty//cbc:ID)'));
        self::assertStringStartsWith('SON ', $xpath->evaluate('string(//cbc:Note[@languageLocaleID="1000"])'));
    }

    public function testNoteCarriesReferenceAndReasonCode(): void
    {
        $xml = $this->signed(Payloads::creditNote())['xml'];
        $xpath = $this->xpath($xml);

        self::assertSame('01', $xpath->evaluate('string(//cac:DiscrepancyResponse/cbc:ResponseCode)'));
        self::assertSame('F001-1', $xpath->evaluate('string(//cac:DiscrepancyResponse/cbc:ReferenceID)'));
        self::assertSame('01', $xpath->evaluate('string(//cac:BillingReference//cbc:DocumentTypeCode)'));
    }

    public function testLinesAreTaxedAndAddUp(): void
    {
        $doc = $this->signed(Payloads::factura());
        $xpath = $this->xpath($doc['xml']);

        self::assertSame('10', $xpath->evaluate('string(//cac:InvoiceLine[1]//cbc:TaxExemptionReasonCode)'));
        // 360.00/1.18 = 305.08 (IGV 54.92) y 50.00/1.18 = 42.37 (IGV 7.63): base 347.45, IGV 62.55, total 410.00
        self::assertSame(34745, $doc['taxable']);
        self::assertSame(6255, $doc['igv']);
        self::assertSame(41000, $doc['total']);
        self::assertSame('347.45', $xpath->evaluate('string(//cac:LegalMonetaryTotal/cbc:LineExtensionAmount)'));
    }

    public function testTamperedXmlFailsVerification(): void
    {
        $xml = $this->signed(Payloads::boleta())['xml'];
        $tampered = str_replace('MARIA RAMOS ARTEAGA', 'OTRA PERSONA', $xml);

        self::assertNotSame($xml, $tampered);
        self::assertFalse($this->verifies($tampered));
    }

    public function testDigestValueIsTheSignatureHash(): void
    {
        $doc = $this->signed(Payloads::boleta());

        self::assertSame(XmlSigner::digestValue($doc['xml']), $doc['hash']);
        self::assertMatchesRegularExpression('#^[A-Za-z0-9+/]+=*$#', $doc['hash']);
    }

    private function verifies(string $xml): bool
    {
        $verifier = new SignedXml();
        $verifier->setCertificate(self::$pem);

        return $verifier->verifyXml($xml);
    }

    private function xpath(string $xml): \DOMXPath
    {
        $dom = new \DOMDocument();
        $dom->loadXML($xml);
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('cbc', self::CBC);
        $xpath->registerNamespace('cac', self::CAC);

        return $xpath;
    }
}
