<?php

declare(strict_types=1);

namespace Desertica\Billing\Service;

final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('America/Lima'));
    }
}
