<?php

declare(strict_types=1);

namespace Desertica\Billing\Sunat;

use Greenter\Model\Response\BillResult;
use Greenter\Model\Response\Error;
use Greenter\Model\Response\SummaryResult;
use Greenter\See;
use Greenter\Ws\Services\ConsultCdrService;
use Greenter\Ws\Services\WsdlProvider;

/**
 * Implementación real sobre Greenter (`See`): SOAP a SUNAT beta o producción según el endpoint con que se construyó `See`.
 */
final class GreenterSunatGateway implements SunatGateway
{
    /**
     * @param ConsultCdrService|null $consult servicio `billConsultService` (consulta de CDR); sin él `consultCdr` no consulta
     */
    public function __construct(private readonly See $see, private readonly ?ConsultCdrService $consult = null)
    {
    }

    /**
     * Construye `See` y el servicio de consulta con clientes SOAP que verifican TLS (ver `VerifiedSoapClient`).
     *
     * @param string $billUrl endpoint de envío (`SunatEndpoints::FE_BETA` o `FE_PRODUCCION`)
     * @param string $consultUrl endpoint de consulta de CDR; vacío = sin consulta
     */
    public static function connect(
        string $certificate,
        string $ruc,
        string $solUser,
        string $solPassword,
        string $billUrl,
        string $consultUrl,
        ?string $caFile = null,
    ): self {
        $credentials = $ruc . $solUser;

        $client = new VerifiedSoapClient('', $caFile);
        $client->setService($billUrl);
        $client->setCredentials($credentials, $solPassword);

        $see = new See();
        $see->setCertificate($certificate);
        // `See` guarda su cliente en una propiedad privada y siempre crea uno con TLS sin verificar: se sustituye.
        (function () use ($client): void {
            $this->wsClient = $client;
        })->call($see);

        $consult = null;
        if ($consultUrl !== '') {
            $consultClient = new VerifiedSoapClient(WsdlProvider::getConsultPath(), $caFile);
            $consultClient->setService($consultUrl);
            $consultClient->setCredentials($credentials, $solPassword);
            $consult = new ConsultCdrService();
            $consult->setClient($consultClient);
        }

        return new self($see, $consult);
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

    public function consultCdr(string $ruc, string $typeCode, string $series, int $number): ?CdrResult
    {
        if ($this->consult === null) {
            return null;
        }

        $result = $this->call(fn () => $this->consult->getStatusCdr($ruc, $typeCode, $series, $number));
        if (!$result->isSuccess()) {
            throw self::exceptionFor($result->getError());
        }

        $cdr = $result->getCdrResponse();
        $zip = $result->getCdrZip();
        // Sin constancia en la respuesta (p. ej. «el comprobante no existe») no hay nada que recuperar.
        if ($cdr === null || $zip === null || $zip === '') {
            return null;
        }

        return new CdrResult(
            (int) $cdr->getCode() === 0,
            (string) $cdr->getCode(),
            (string) $cdr->getDescription(),
            $zip,
            array_values(array_map('strval', $cdr->getNotes() ?? [])),
        );
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
