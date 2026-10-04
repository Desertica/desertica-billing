<?php

declare(strict_types=1);

namespace Desertica\Billing\Tests\Support;

use Desertica\Billing\Service\Clock;

final class FixedClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct(string $at = '2026-10-04 15:30:00')
    {
        $this->now = new \DateTimeImmutable($at, new \DateTimeZone('America/Lima'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function set(string $at): void
    {
        $this->now = new \DateTimeImmutable($at, new \DateTimeZone('America/Lima'));
    }
}
