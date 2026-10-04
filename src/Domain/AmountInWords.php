<?php

declare(strict_types=1);

namespace Desertica\Billing\Domain;

/**
 * Monto en letras para la leyenda 1000: «SON CIENTO DIECIOCHO CON 00/100 SOLES».
 */
final class AmountInWords
{
    private const UNITS = [
        'CERO', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE', 'DIEZ', 'ONCE', 'DOCE',
        'TRECE', 'CATORCE', 'QUINCE', 'DIECISÉIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE', 'VEINTE', 'VEINTIUNO',
        'VEINTIDÓS', 'VEINTITRÉS', 'VEINTICUATRO', 'VEINTICINCO', 'VEINTISÉIS', 'VEINTISIETE', 'VEINTIOCHO', 'VEINTINUEVE',
    ];
    private const TENS = [3 => 'TREINTA', 4 => 'CUARENTA', 5 => 'CINCUENTA', 6 => 'SESENTA', 7 => 'SETENTA', 8 => 'OCHENTA', 9 => 'NOVENTA'];
    private const HUNDREDS = [
        1 => 'CIENTO', 2 => 'DOSCIENTOS', 3 => 'TRESCIENTOS', 4 => 'CUATROCIENTOS', 5 => 'QUINIENTOS',
        6 => 'SEISCIENTOS', 7 => 'SETECIENTOS', 8 => 'OCHOCIENTOS', 9 => 'NOVECIENTOS',
    ];

    public static function format(int $cents, string $currency): string
    {
        $name = $currency === 'USD' ? 'DÓLARES AMERICANOS' : 'SOLES';

        return sprintf('SON %s CON %02d/100 %s', self::integer(intdiv($cents, 100)), $cents % 100, $name);
    }

    public static function integer(int $n, bool $apocope = false): string
    {
        if ($n < 0) {
            throw new \InvalidArgumentException('Monto negativo.');
        }
        if ($n < 1000) {
            return self::below1000($n, $apocope);
        }
        if ($n < 1000000) {
            $thousands = intdiv($n, 1000);
            $rest = $n % 1000;
            $head = $thousands === 1 ? 'MIL' : self::below1000($thousands, true) . ' MIL';

            return $rest === 0 ? $head : $head . ' ' . self::below1000($rest, $apocope);
        }
        $millions = intdiv($n, 1000000);
        $rest = $n % 1000000;
        $head = $millions === 1 ? 'UN MILLÓN' : self::integer($millions, true) . ' MILLONES';

        return $rest === 0 ? $head : $head . ' ' . self::integer($rest, $apocope);
    }

    /**
     * @param bool $apocope «veintiún mil», «un mil»: el uno se apocopa delante de «mil».
     */
    private static function below1000(int $n, bool $apocope = false): string
    {
        if ($n === 100) {
            return 'CIEN';
        }
        $words = [];
        if ($n >= 100) {
            $words[] = self::HUNDREDS[intdiv($n, 100)];
            $n %= 100;
        }
        if ($n > 0 || $words === []) {
            if ($n < 30) {
                $word = self::UNITS[$n];
            } else {
                $word = self::TENS[intdiv($n, 10)];
                if ($n % 10 !== 0) {
                    $word .= ' Y ' . self::UNITS[$n % 10];
                }
            }
            if ($apocope) {
                $word = $word === 'VEINTIUNO' ? 'VEINTIÚN' : (preg_replace('/UNO$/', 'UN', $word) ?? $word);
            }
            $words[] = $word;
        }

        return implode(' ', $words);
    }
}
