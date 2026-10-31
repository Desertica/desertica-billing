<?php

declare(strict_types=1);

namespace Desertica\Billing\Sunat;

/**
 * Crea el gateway real en la primera llamada: las peticiones que no hablan con SUNAT (p. ej. /health) no cargan el certificado.
 */
final class LazySunatGateway implements SunatGateway
{
    private ?SunatGateway $gateway = null;

    /**
     * @param \Closure(): SunatGateway $factory
     */
    public function __construct(private readonly \Closure $factory)
    {
    }

    public function sendDocument(string $signedXml): CdrResult
    {
        return $this->gateway()->sendDocument($signedXml);
    }

    public function sendBatch(string $signedXml): string
    {
        return $this->gateway()->sendBatch($signedXml);
    }

    public function ticketStatus(string $ticket): TicketStatus
    {
        return $this->gateway()->ticketStatus($ticket);
    }

    public function consultCdr(string $ruc, string $typeCode, string $series, int $number): ?CdrResult
    {
        return $this->gateway()->consultCdr($ruc, $typeCode, $series, $number);
    }

    private function gateway(): SunatGateway
    {
        return $this->gateway ??= ($this->factory)();
    }
}
