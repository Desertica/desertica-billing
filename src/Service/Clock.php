<?php

declare(strict_types=1);

namespace Desertica\Billing\Service;

interface Clock
{
    /** Instante actual en America/Lima. */
    public function now(): \DateTimeImmutable;
}
