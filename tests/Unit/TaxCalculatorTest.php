<?php

declare(strict_types=1);

namespace Desertica\Billing\Tests\Unit;

use Desertica\Billing\Domain\TaxCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TaxCalculatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function rates(): iterable
    {
        yield '18 %' => ['0.1800', 1800];
        yield 'sin ceros' => ['0.18', 1800];
        yield '10 %' => ['0.1', 1000];
        yield 'con 4 decimales' => ['0.1825', 1825];
    }

    #[DataProvider('rates')]
    public function testParsesRate(string $rate, int $expected): void
    {
        self::assertSame($expected, TaxCalculator::parseRate($rate));
    }

    #[DataProvider('invalidRates')]
    public function testRejectsInvalidRate(string $rate): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TaxCalculator::parseRate($rate);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidRates(): iterable
    {
        yield 'vacía' => [''];
        yield 'con coma' => ['0,18'];
        yield 'cinco decimales' => ['0.18000'];
        yield 'cero' => ['0'];
        yield 'mayor a 50 %' => ['0.51'];
        yield 'negativa' => ['-0.18'];
        yield 'texto' => ['abc'];
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function taxable(): iterable
    {
        yield 'exacto: 118.00 → 100.00' => [11800, 1800, 10000];
        yield 'redondea hacia abajo: 100.00 / 1.18 = 84.745…' => [10000, 1800, 8475];
        yield 'mitad hacia arriba: 1 céntimo' => [1, 1800, 1];
        yield '59.00 → 50.00' => [5900, 1800, 5000];
        yield '0.59 / 1.18 = 0.5 → sube' => [59, 1800, 50];
        yield 'tasa 0' => [10000, 1, 9999];
        yield 'grande' => [123456789, 1800, 104624397];
    }

    #[DataProvider('taxable')]
    public function testTaxableCents(int $total, int $rate, int $expected): void
    {
        self::assertSame($expected, TaxCalculator::taxableCents($total, $rate));
    }

    public function testBaseAndIgvAlwaysAddUpToTotal(): void
    {
        foreach ([1, 7, 99, 100, 101, 5900, 11800, 33333, 99999, 1234567] as $total) {
            foreach ([1000, 1800, 1825] as $rate) {
                $base = TaxCalculator::taxableCents($total, $rate);
                $igv = $total - $base;
                self::assertGreaterThanOrEqual(0, $igv);
                // El IGV es lo más cercano posible a base × tasa (error de redondeo < 1 céntimo).
                self::assertLessThanOrEqual(1, abs($igv * 10000 - $base * $rate) / 10000);
            }
        }
    }

    public function testDocumentTotalsSumTheLines(): void
    {
        $totals = TaxCalculator::totals([
            ['quantity' => 1, 'totalCents' => 1],
            ['quantity' => 1, 'totalCents' => 1],
            ['quantity' => 1, 'totalCents' => 10000],
        ], 1800);

        // Cada línea se redondea por separado: 1→(1,0), 1→(1,0), 10000→(8475,1525).
        self::assertSame(8477, $totals['taxableCents']);
        self::assertSame(1525, $totals['igvCents']);
        self::assertSame(10002, $totals['totalCents']);
        self::assertSame($totals['totalCents'], $totals['taxableCents'] + $totals['igvCents']);
    }

    public function testIsDeterministic(): void
    {
        $lines = [['quantity' => 3, 'totalCents' => 12345], ['quantity' => 2, 'totalCents' => 777]];

        self::assertSame(TaxCalculator::totals($lines, 1800), TaxCalculator::totals($lines, 1800));
    }

    public function testUnitValue(): void
    {
        self::assertSame('50.0000000000', TaxCalculator::unitValue(10000, 2));
        self::assertSame('28.2500000000', TaxCalculator::unitValue(8475, 3));
        self::assertSame('0.0100000000', TaxCalculator::unitValue(1, 1));
        self::assertSame('0.0033333333', TaxCalculator::unitValue(1, 3));
    }

    public function testMoneyFormat(): void
    {
        self::assertSame('118.00', TaxCalculator::money(11800));
        self::assertSame('0.05', TaxCalculator::money(5));
        self::assertSame('1234567.89', TaxCalculator::money(123456789));
    }
}
