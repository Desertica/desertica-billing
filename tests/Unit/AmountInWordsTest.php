<?php

declare(strict_types=1);

namespace Desertica\Billing\Tests\Unit;

use Desertica\Billing\Domain\AmountInWords;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AmountInWordsTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function cases(): iterable
    {
        yield 'ciento dieciocho' => [11800, 'PEN', 'SON CIENTO DIECIOCHO CON 00/100 SOLES'];
        yield 'cien exacto' => [10000, 'PEN', 'SON CIEN CON 00/100 SOLES'];
        yield 'céntimos' => [123456, 'PEN', 'SON MIL DOSCIENTOS TREINTA Y CUATRO CON 56/100 SOLES'];
        yield 'veintiún mil' => [2100000, 'PEN', 'SON VEINTIÚN MIL CON 00/100 SOLES'];
        yield 'dólares' => [4500, 'USD', 'SON CUARENTA Y CINCO CON 00/100 DÓLARES AMERICANOS'];
        yield 'mil' => [100000, 'PEN', 'SON MIL CON 00/100 SOLES'];
        yield 'un millón' => [100000000, 'PEN', 'SON UN MILLÓN CON 00/100 SOLES'];
    }

    #[DataProvider('cases')]
    public function testFormat(int $cents, string $currency, string $expected): void
    {
        self::assertSame($expected, AmountInWords::format($cents, $currency));
    }
}
