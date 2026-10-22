<?php

declare(strict_types=1);

namespace Desertica\Billing\Tests\Unit;

use Desertica\Billing\Sunat\FakeSunatGateway;
use Desertica\Billing\Sunat\LazySunatGateway;
use PHPUnit\Framework\TestCase;

final class LazySunatGatewayTest extends TestCase
{
    public function testBuildsTheGatewayOnFirstUseOnly(): void
    {
        $built = 0;
        $fake = new FakeSunatGateway();
        $lazy = new LazySunatGateway(static function () use (&$built, $fake): FakeSunatGateway {
            $built++;

            return $fake;
        });

        self::assertSame(0, $built);
        $lazy->sendBatch('<a/>');
        $lazy->sendBatch('<b/>');
        self::assertSame(1, $built);
        self::assertSame(['<a/>', '<b/>'], $fake->sentBatches);
    }
}
