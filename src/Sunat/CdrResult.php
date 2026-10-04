<?php

declare(strict_types=1);

namespace Desertica\Billing\Sunat;

/**
 * Constancia de recepción leída. `accepted` es true con código 0 (las observaciones, códigos ≥ 4000, vienen en `notes`).
 */
final class CdrResult
{
    /**
     * @param list<string> $notes
     */
    public function __construct(
        public readonly bool $accepted,
        public readonly string $code,
        public readonly string $message,
        public readonly string $zip,
        public readonly array $notes = [],
    ) {
    }
}
