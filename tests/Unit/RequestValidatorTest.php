<?php

declare(strict_types=1);

namespace Desertica\Billing\Tests\Unit;

use Desertica\Billing\Domain\DocType;
use Desertica\Billing\Domain\RequestValidator;
use Desertica\Billing\Http\Error\ApiError;
use Desertica\Billing\Tests\Support\Payloads;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestValidatorTest extends TestCase
{
    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-04 12:00:00', new \DateTimeZone('America/Lima'));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function failure(array $body): string
    {
        try {
            (new RequestValidator())->validate($body, $this->today());
        } catch (ApiError $e) {
            self::assertSame(422, $e->status);
            self::assertSame('VALIDATION_ERROR', $e->errorCode);

            return $e->getMessage();
        }
        self::fail('Se esperaba un 422.');
    }

    public function testAcceptsValidBoleta(): void
    {
        $request = (new RequestValidator())->validate(Payloads::boleta(), $this->today());

        self::assertSame(DocType::Boleta, $request->docType);
        self::assertSame(11800, $request->totalCents);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function validCustomers(): iterable
    {
        yield 'DNI' => [['idDocType' => 'DNI', 'idDocNumber' => '46712369', 'name' => 'MARIA']];
        yield 'CE' => [['idDocType' => 'CE', 'idDocNumber' => '001234567', 'name' => 'JOHN']];
        yield 'pasaporte' => [['idDocType' => 'PASSPORT', 'idDocNumber' => 'AB1234567', 'name' => 'JANE']];
        yield 'RUC' => [['idDocType' => 'RUC', 'idDocNumber' => '20100070970', 'name' => 'EMPRESA SAC']];
    }

    /**
     * @param array<string, string> $customer
     */
    #[DataProvider('validCustomers')]
    public function testAcceptsCustomerTypes(array $customer): void
    {
        $request = (new RequestValidator())->validate(Payloads::boleta(['customer' => $customer]), $this->today());

        self::assertSame($customer['idDocType'], $request->customer['idDocType']);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidCustomers(): iterable
    {
        yield 'DNI corto' => [['idDocType' => 'DNI', 'idDocNumber' => '1234', 'name' => 'X'], 'DNI'];
        yield 'RUC corto' => [['idDocType' => 'RUC', 'idDocNumber' => '2010007', 'name' => 'X'], 'RUC'];
        yield 'tipo desconocido' => [['idDocType' => 'LE', 'idDocNumber' => '12345678', 'name' => 'X'], 'idDocType'];
        yield 'pasaporte con símbolos' => [['idDocType' => 'PASSPORT', 'idDocNumber' => 'AB-12/34', 'name' => 'X'], 'PASSPORT'];
    }

    /**
     * @param array<string, string> $customer
     */
    #[DataProvider('invalidCustomers')]
    public function testRejectsInvalidCustomers(array $customer, string $needle): void
    {
        self::assertStringContainsString($needle, $this->failure(Payloads::boleta(['customer' => $customer])));
    }

    public function testUsdRequiresExchangeRate(): void
    {
        self::assertStringContainsString('exchangeRate', $this->failure(Payloads::boleta(['currency' => 'USD'])));
        $request = (new RequestValidator())->validate(Payloads::boleta(['currency' => 'USD', 'exchangeRate' => '3.7500']), $this->today());

        self::assertSame('3.7500', $request->exchangeRate);
    }

    public function testRejectsBadExchangeRate(): void
    {
        self::assertStringContainsString('exchangeRate', $this->failure(Payloads::boleta(['currency' => 'USD', 'exchangeRate' => '0'])));
        self::assertStringContainsString('exchangeRate', $this->failure(Payloads::boleta(['currency' => 'USD', 'exchangeRate' => '3,75'])));
    }

    public function testTotalMustMatchItems(): void
    {
        self::assertStringContainsString('no coincide', $this->failure(Payloads::boleta(['totalCents' => 11801])));
    }

    public function testItemTotalMustBeQuantityTimesPrice(): void
    {
        $body = Payloads::boleta();
        $body['items'][0]['totalCents'] = 11000;
        $body['totalCents'] = 11000;

        self::assertStringContainsString('quantity × unitPriceCents', $this->failure($body));
    }

    public function testFacturaRequiresRuc(): void
    {
        self::assertStringContainsString('RUC', $this->failure(Payloads::factura([
            'customer' => ['idDocType' => 'DNI', 'idDocNumber' => '46712369', 'name' => 'MARIA'],
        ])));
    }

    public function testSeriesPrefixMustMatchType(): void
    {
        self::assertStringContainsString('empezar con B', $this->failure(Payloads::boleta(['series' => 'F001'])));
        self::assertStringContainsString('empezar con F', $this->failure(Payloads::factura(['series' => 'B001'])));
    }

    public function testNotesRequireAffectedDocumentAndValidReason(): void
    {
        $note = Payloads::creditNote();
        unset($note['affectedDocument']);
        self::assertStringContainsString('affectedDocument es obligatorio', $this->failure($note));

        self::assertStringContainsString('reasonCode 99', $this->failure(Payloads::creditNote(['reasonCode' => '99'])));
        // 03 es válido para nota de débito (penalidades); 04 no.
        self::assertStringContainsString('reasonCode 04', $this->failure(Payloads::creditNote(['docType' => 'NOTA_DEBITO', 'reasonCode' => '04'])));
    }

    public function testNonNotesRejectAffectedDocument(): void
    {
        self::assertStringContainsString('solo aplican', $this->failure(Payloads::boleta([
            'affectedDocument' => ['docType' => 'BOLETA', 'series' => 'B001', 'number' => 1],
        ])));
    }

    public function testNoteSeriesFollowsAffectedDocument(): void
    {
        // Nota que modifica una boleta: serie B; que modifica una factura: serie F.
        $request = (new RequestValidator())->validate(Payloads::creditNote([
            'series' => 'BC01',
            'affectedDocument' => ['docType' => 'BOLETA', 'series' => 'B001', 'number' => 3],
            'customer' => ['idDocType' => 'DNI', 'idDocNumber' => '46712369', 'name' => 'MARIA'],
        ]), $this->today());
        self::assertTrue($request->concernsBoleta());

        self::assertStringContainsString('empezar con F', $this->failure(Payloads::creditNote(['series' => 'BC01'])));
    }

    public function testDailySummaryOnlyForBoletas(): void
    {
        self::assertStringContainsString('DAILY_SUMMARY', $this->failure(Payloads::factura(['submission' => 'DAILY_SUMMARY'])));
        $request = (new RequestValidator())->validate(Payloads::boleta(['submission' => 'DAILY_SUMMARY']), $this->today());

        self::assertSame('DAILY_SUMMARY', $request->submission);
    }

    public function testRejectsFutureAndInvalidDates(): void
    {
        self::assertStringContainsString('futura', $this->failure(Payloads::boleta(['issueDate' => '2026-10-05'])));
        self::assertStringContainsString('YYYY-MM-DD', $this->failure(Payloads::boleta(['issueDate' => '2026-02-30'])));
    }

    public function testRejectsFloatsAndStringsWhereIntegersAreRequired(): void
    {
        self::assertStringContainsString('totalCents', $this->failure(Payloads::boleta(['totalCents' => 118.0])));
        self::assertStringContainsString('number', $this->failure(Payloads::boleta(['number' => '1'])));
    }

    public function testCollectsAllProblemsAtOnce(): void
    {
        $message = $this->failure(['externalId' => 'nope']);

        self::assertStringContainsString('externalId', $message);
        self::assertStringContainsString('docType', $message);
        self::assertStringContainsString('items', $message);
    }

    public function testContentHashIgnoresKeyOrderButNotContent(): void
    {
        $a = (new RequestValidator())->validate(Payloads::boleta(), $this->today());
        $reordered = array_reverse(Payloads::boleta(), true);
        $b = (new RequestValidator())->validate($reordered, $this->today());
        $c = (new RequestValidator())->validate(Payloads::boleta(['notes' => 'otra cosa']), $this->today());

        self::assertSame($a->contentHash(), $b->contentHash());
        self::assertNotSame($a->contentHash(), $c->contentHash());
    }

    public function testExternalIdIsNormalizedToLowercase(): void
    {
        $request = (new RequestValidator())->validate(
            Payloads::boleta(['externalId' => strtoupper(Payloads::BOLETA_ID)]),
            $this->today(),
        );

        self::assertSame(Payloads::BOLETA_ID, $request->externalId);
    }
}
