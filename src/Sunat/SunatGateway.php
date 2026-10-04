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

    /**
     * Pregunta a SUNAT por el CDR de un comprobante ya enviado (`getStatusCdr`), para recuperar el resultado cuando se perdió
     * la respuesta del envío. Devuelve `null` si SUNAT no tiene constancia (o si la consulta no está configurada).
     *
     * @param string $typeCode código del catálogo 01 (`01` factura, `03` boleta, `07`, `08`)
     * @throws SunatException si la consulta falla
     */
    public function consultCdr(string $ruc, string $typeCode, string $series, int $number): ?CdrResult;
}
