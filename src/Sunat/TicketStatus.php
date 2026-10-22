<?php

declare(strict_types=1);

namespace Desertica\Billing\Sunat;

final class TicketStatus
{
    public const PENDING = 'PENDING';
    public const ACCEPTED = 'ACCEPTED';
    public const REJECTED = 'REJECTED';

    public function __construct(
        public readonly string $state,
        public readonly ?string $code = null,
        public readonly ?string $message = null,
        public readonly ?string $cdrZip = null,
    ) {
    }
}
