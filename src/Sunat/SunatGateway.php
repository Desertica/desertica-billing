<?php

declare(strict_types=1);

namespace Desertica\Billing\Sunat;

/**
 * Frontera con SUNAT. Recibe XML ya firmado y devuelve resultados neutrales; no sabe nada de Greenter
 * ni de nuestras tablas. Implementaciones: `GreenterSunatGateway` (real) y `FakeSunatGateway` (sin red).
 */
interface SunatGateway
{
    /**
     * Envía una factura, boleta o nota (`sendBill`) y devuelve el CDR.
     *
     * @throws SunatException si SUNAT no está disponible o rechaza la solicitud antes de emitir CDR
     */
    public function sendDocument(string $signedXml): CdrResult;

    /**
     * Envía un resumen diario o una comunicación de baja (`sendSummary`) y devuelve el ticket.
     *
     * @throws SunatException
     */
    public function sendBatch(string $signedXml): string;

    /**
     * Consulta el estado de un ticket.
     *
     * @throws SunatException
     */
    public function ticketStatus(string $ticket): TicketStatus;
}
