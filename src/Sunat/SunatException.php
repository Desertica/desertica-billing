<?php

declare(strict_types=1);

namespace Desertica\Billing\Sunat;

/**
 * Fallo al hablar con SUNAT. `retryable` distingue «SUNAT caído / sin conexión» (reintentar con la misma `externalId`)
 * de «SUNAT rechazó la solicitud con una excepción» (corregir y volver a enviar).
 */
final class SunatException extends \RuntimeException
{
    public function __construct(
        public readonly ?string $sunatCode,
        string $message,
        public readonly bool $retryable,
    ) {
        parent::__construct($message);
    }
}
