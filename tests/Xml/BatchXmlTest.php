<?php

declare(strict_types=1);

namespace Desertica\Billing\Tests\Xml;

use Desertica\Billing\Config\Issuer;
use Desertica\Billing\Persistence\DocumentRecord;
use Desertica\Billing\Security\CertificateProvider;
use Desertica\Billing\Xml\BatchFactory;
use Desertica\Billing\Xml\DocumentFactory;
use Desertica\Billing\Xml\XmlSigner;
use Greenter\Ubl\UblValidator;
use Greenter\XMLSecLibs\Sunat\SignedXml;
use PHPUnit\Framework\TestCase;

/**
 * El resumen diario y la comunicación de baja, firmados y validados contra el XSD (UBL 2.0 de SUNAT), sin red.
 */
final class BatchXmlTest extends TestCase
{
    private static string $pem;

    public static function setUpBeforeClass(): void
    {
        self::$pem = CertificateProvider::generateTestCertificate();
    }

    private function factory(): BatchFactory
    {
        return new BatchFactory(new DocumentFactory(new Issuer('20000000001', 'DESERTICA SAC', 'DESERTICA', 'AV 1', '150101', 'LIMA', 'LIMA', 'LIMA', '0000')));
    }

    private function record(string $type, string $series, int $number, string $customerType = 'DNI', string $customerNumber = '46712369'): DocumentRecord
    {
        $request = ['customer' => ['idDocType' => $customerType, 'idDocNumber' => $customerNumber, 'name' => 'X'], 'igvRate' => '0.1800'];
        if ($type === 'NOTA_CREDITO') {
            $request['affectedDocument'] = ['docType' => 'BOLETA', 'series' => 'B001', 'number' => 1];
        }

        return new DocumentRecord(
            'e',
            'h',
            json_encode($request, JSON_THROW_ON_ERROR),
            $type,
            $series,
            $number,
            '2026-10-04',
            'PEN',
            'DAILY_SUMMARY',
            DocumentRecord::ISSUED,
            8475,
            1525,
            10000,
            'n',
            null,
            null,
            null,
            null,
            null,
            null,
            true,
            false,
            false,
        );
    }

    public function testDailySummaryIsValidAndSigned(): void
    {
        $summary = $this->factory()->summary([
            $this->record('BOLETA', 'B001', 1),
            $this->record('BOLETA', 'B001', 2, 'CE', '001234567'),
            $this->record('BOLETA', 'B001', 3, 'PASSPORT', 'AB1234567'),
            $this->record('NOTA_CREDITO', 'BC01', 1),
        ], 'PEN', '2026-10-04', '2026-10-05', 1);

        $xml = (new XmlSigner(self::$pem))->sign($summary)->xml;
        $validator = new UblValidator();

        self::assertTrue($validator->isValid($xml), 'XSD: ' . $validator->getError());
        self::assertSame('20000000001-RC-20261005-001', $summary->getName());
        self::assertTrue($this->verifies($xml));
        self::assertStringContainsString('<cbc:AdditionalAccountID>4</cbc:AdditionalAccountID>', $xml);
        self::assertStringContainsString('<cbc:AdditionalAccountID>7</cbc:AdditionalAccountID>', $xml);
    }

    public function testVoidedCommunicationIsValidAndSigned(): void
    {
        $voided = $this->factory()->voided($this->record('FACTURA', 'F001', 7, 'RUC', '20100070970'), '2026-10-05', 'ERROR EN CÁLCULOS', 3);

        $xml = (new XmlSigner(self::$pem))->sign($voided)->xml;
        $validator = new UblValidator();

        self::assertTrue($validator->isValid($xml), 'XSD: ' . $validator->getError());
        self::assertSame('20000000001-RA-20261005-00003', $voided->getName());
        self::assertTrue($this->verifies($xml));
        self::assertStringContainsString('ERROR EN CÁLCULOS', $xml);
    }

    private function verifies(string $xml): bool
    {
        $verifier = new SignedXml();
        $verifier->setCertificate(self::$pem);

        return $verifier->verifyXml($xml);
    }
}
