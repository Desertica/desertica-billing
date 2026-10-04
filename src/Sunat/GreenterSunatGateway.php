<?php

declare(strict_types=1);

namespace Desertica\Billing\Sunat;

use Greenter\Model\Response\BillResult;
use Greenter\Model\Response\Error;
use Greenter\Model\Response\SummaryResult;
use Greenter\See;

/**
 * Implementación real sobre Greenter (`See`): SOAP a SUNAT beta o producción según el endpoint con que se construyó `See`.
 */
final class GreenterSunatGateway implements SunatGateway
{
    public function __construct(private readonly See $see)
    {
    }

    public function sendDocument(string $signedXml): CdrResult
    {
        $result = $this->call(fn () => $this->see->sendXmlFile($signedXml));
        if (!$result instanceof BillResult) {
            throw new SunatException(null, 'Respuesta inesperada de SUNAT.', true);
        }
        if (!$result->isSuccess()) {
            throw self::exceptionFor($result->getError());
        }

        $cdr = $result->getCdrResponse();
        $zip = $result->getCdrZip();
        if ($cdr === null || $zip === null || $zip === '') {
            throw new SunatException(null, 'SUNAT no devolvió el CDR.', true);
        }

        return new CdrResult(
            (int) $cdr->getCode() === 0,
            (string) $cdr->getCode(),
            (string) $cdr->getDescription(),
            $zip,
            array_values(array_map('strval', $cdr->getNotes() ?? [])),
        );
    }

    public function sendBatch(string $signedXml): string
    {
        $result = $this->call(fn () => $this->see->sendXmlFile($signedXml));
        if (!$result instanceof SummaryResult) {
            throw new SunatException(null, 'Respuesta inesperada de SUNAT.', true);
        }
        if (!$result->isSuccess()) {
            throw self::exceptionFor($result->getError());
        }
        $ticket = $result->getTicket();
        if ($ticket === null || $ticket === '') {
            throw new SunatException(null, 'SUNAT no devolvió el ticket.', true);
        }

        return $ticket;
    }

    public function ticketStatus(string $ticket): TicketStatus
    {
        $status = $this->call(fn () => $this->see->getStatus($ticket));
        if ($status->isSuccess()) {
            $cdr = $status->getCdrResponse();
            $zip = $status->getCdrZip();
            $code = (string) ($cdr?->getCode() ?? $status->getCode());

            return new TicketStatus(
                (int) $code === 0 ? TicketStatus::ACCEPTED : TicketStatus::REJECTED,
                $code,
                (string) $cdr?->getDescription(),
                $zip,
            );
        }

        $error = $status->getError();
        // Códigos de estado del ticket (FAQ de Greenter): 98 = en proceso; 99 = procesado con errores.
        if ($status->getCode() === '98') {
            return new TicketStatus(TicketStatus::PENDING, '98', $error?->getMessage());
        }
        if ($status->getCode() === '99') {
            return new TicketStatus(TicketStatus::REJECTED, '99', $error?->getMessage(), $status->getCdrZip());
        }

        throw self::exceptionFor($error);
    }

    /**
     * Clasifica un error de Greenter: con código numérico de 4 dígitos SUNAT respondió una excepción de negocio
     * (corregir y volver a enviar, FAQ de Greenter); sin él (HTTP, conexión, Bad Gateway) es un fallo reintentable.
     */
    public static function exceptionFor(?Error $error): SunatException
    {
        $code = $error?->getCode();
        $message = (string) ($error?->getMessage() ?? 'Error desconocido al conectar con SUNAT.');
        if ($code !== null && preg_match('/^\d{4}$/', $code) === 1) {
            return new SunatException($code, $message, false);
        }

        return new SunatException($code === '' ? null : $code, $message, true);
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function call(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\SoapFault $e) {
            throw new SunatException(null, 'No se pudo conectar con SUNAT: ' . $e->getMessage(), true);
        } catch (\Exception $e) {
            // `See` lanza excepciones genéricas al descomprimir o al fallar el transporte.
            throw new SunatException(null, 'Fallo al comunicarse con SUNAT: ' . $e->getMessage(), true);
        }
    }
}
