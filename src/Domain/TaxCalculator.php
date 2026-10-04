<?php

declare(strict_types=1);

namespace Desertica\Billing\Domain;

/**
 * Cálculo determinista de base imponible e IGV a partir de montos enteros con IGV incluido.
 *
 * Todo es aritmética entera: la tasa se lee como puntos base (0.1800 → 1800) y el redondeo es
 * «mitad hacia arriba» sobre céntimos. El cálculo se hace por línea y el documento suma las líneas,
 * de modo que el XML siempre cuadra con lo que SUNAT recalcula por ítem.
 */
final class TaxCalculator
{
    private const SCALE = 10000;

    /**
     * @return int<1, 5000> puntos base
     */
    public static function parseRate(string $rate): int
    {
        if (!preg_match('/^(\d{1,2})(?:\.(\d{1,4}))?$/', $rate, $m)) {
            throw new \InvalidArgumentException('igvRate debe ser un decimal con hasta 4 decimales, p. ej. 0.1800.');
        }
        $basis = (int) $m[1] * self::SCALE + (int) str_pad($m[2] ?? '', 4, '0');
        if ($basis < 1 || $basis > 5000) {
            throw new \InvalidArgumentException('igvRate debe estar entre 0.0001 y 0.5000.');
        }

        return $basis;
    }

    /**
     * Base imponible de un monto con IGV incluido, redondeada a céntimos (mitad hacia arriba).
     */
    public static function taxableCents(int $totalCents, int $rateBasisPoints): int
    {
        $divisor = self::SCALE + $rateBasisPoints;

        return intdiv(2 * $totalCents * self::SCALE + $divisor, 2 * $divisor);
    }

    /**
     * @param list<array{quantity: int, totalCents: int}> $lines
     * @return array{taxableCents: int, igvCents: int, totalCents: int, lines: list<array{taxableCents: int, igvCents: int}>}
     */
    public static function totals(array $lines, int $rateBasisPoints): array
    {
        $taxable = 0;
        $igv = 0;
        $total = 0;
        $out = [];
        foreach ($lines as $line) {
            $base = self::taxableCents($line['totalCents'], $rateBasisPoints);
            $lineIgv = $line['totalCents'] - $base;
            $out[] = ['taxableCents' => $base, 'igvCents' => $lineIgv];
            $taxable += $base;
            $igv += $lineIgv;
            $total += $line['totalCents'];
        }

        return ['taxableCents' => $taxable, 'igvCents' => $igv, 'totalCents' => $total, 'lines' => $out];
    }

    /**
     * Valor unitario sin IGV con 10 decimales (SUNAT admite hasta 10), a partir de la base de la línea.
     */
    public static function unitValue(int $lineTaxableCents, int $quantity): string
    {
        // base/qty en euros: (cents * 10^10) / (100 * qty), redondeado mitad arriba
        $numerator = $lineTaxableCents * 10 ** 10;
        $denominator = 100 * $quantity;
        $scaled = intdiv(2 * $numerator + $denominator, 2 * $denominator);
        $integer = intdiv($scaled, 10 ** 10);
        $fraction = $scaled % (10 ** 10);

        return $integer . '.' . str_pad((string) $fraction, 10, '0', STR_PAD_LEFT);
    }

    /**
     * Céntimos como decimal con dos cifras: 11800 → '118.00'.
     */
    public static function money(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }
}
