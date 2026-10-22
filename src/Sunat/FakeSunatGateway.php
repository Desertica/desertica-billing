<?php

declare(strict_types=1);

namespace Desertica\Billing\Sunat;

/**
 * SUNAT simulado para pruebas y desarrollo sin red. Por defecto acepta todo.
 * Los resultados se pueden programar con `enqueue*` (en proceso; en el servidor de desarrollo siempre acepta).
 */
final class FakeSunatGateway implements SunatGateway
{
    /** @var list<string> */
    public array $sentDocuments = [];
    /** @var list<string> */
    public array $sentBatches = [];
    /** @var list<CdrResult|SunatException> */
    private array $documentQueue = [];
    /** @var list<TicketStatus|SunatException> */
    private array $ticketQueue = [];
    /** @var list<SunatException> */
    private array $batchQueue = [];
    /** @var list<CdrResult|SunatException|null> */
    private array $consultQueue = [];
    /** @var list<array{ruc: string, type: string, series: string, number: int}> */
    public array $consultedCdrs = [];

    public function enqueueDocumentResult(CdrResult|SunatException $result): void
    {
        $this->documentQueue[] = $result;
    }

    public function enqueueBatchFailure(SunatException $error): void
    {
        $this->batchQueue[] = $error;
    }

    public function enqueueTicketStatus(TicketStatus|SunatException $result): void
    {
        $this->ticketQueue[] = $result;
    }

    /**
     * Programa lo que responde la siguiente consulta de CDR (`null` = SUNAT no tiene constancia).
     */
    public function enqueueCdrConsult(CdrResult|SunatException|null $result): void
    {
        $this->consultQueue[] = $result;
    }

    public function consultCdr(string $ruc, string $typeCode, string $series, int $number): ?CdrResult
    {
        $this->consultedCdrs[] = ['ruc' => $ruc, 'type' => $typeCode, 'series' => $series, 'number' => $number];
        $next = array_shift($this->consultQueue);
        if ($next instanceof SunatException) {
            throw $next;
        }

        return $next;
    }

    public function sendDocument(string $signedXml): CdrResult
    {
        $this->sentDocuments[] = $signedXml;
        $next = array_shift($this->documentQueue);
        if ($next instanceof SunatException) {
            throw $next;
        }

        return $next ?? $this->acceptedCdr($signedXml);
    }

    public function sendBatch(string $signedXml): string
    {
        $this->sentBatches[] = $signedXml;
        $next = array_shift($this->batchQueue);
        if ($next !== null) {
            throw $next;
        }

        return 'FAKE' . substr(hash('sha256', $signedXml), 0, 12);
    }

    public function ticketStatus(string $ticket): TicketStatus
    {
        $next = array_shift($this->ticketQueue);
        if ($next instanceof SunatException) {
            throw $next;
        }

        return $next ?? new TicketStatus(TicketStatus::ACCEPTED, '0', 'Procesado correctamente (simulado)', self::buildCdrZip($ticket, '0', 'Procesado correctamente (simulado)'));
    }

    public static function rejectedCdr(string $code = '2335', string $message = 'Rechazado (simulado)'): CdrResult
    {
        return new CdrResult(false, $code, $message, self::buildCdrZip('SIMULADO', $code, $message));
    }

    private function acceptedCdr(string $signedXml): CdrResult
    {
        $name = $this->documentName($signedXml);
        $message = sprintf('El comprobante %s ha sido aceptado (simulado)', $name);

        return new CdrResult(true, '0', $message, self::buildCdrZip($name, '0', $message));
    }

    private function documentName(string $signedXml): string
    {
        $doc = new \DOMDocument();
        if (@$doc->loadXML($signedXml) && $doc->documentElement !== null) {
            $id = $doc->getElementsByTagNameNS('urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2', 'ID')->item(0);
            if ($id !== null) {
                return trim($id->textContent);
            }
        }

        return 'SIN-ID';
    }

    /**
     * ZIP con un `ApplicationResponse` mínimo, con la forma del CDR de SUNAT.
     */
    public static function buildCdrZip(string $reference, string $code, string $description): string
    {
        $xml = sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<ar:ApplicationResponse xmlns:ar="urn:oasis:names:specification:ubl:schema:xsd:ApplicationResponse-2" '
            . 'xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" '
            . 'xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
            . '<cac:DocumentResponse><cac:Response><cbc:ReferenceID>%s</cbc:ReferenceID><cbc:ResponseCode>%s</cbc:ResponseCode>'
            . '<cbc:Description>%s</cbc:Description></cac:Response></cac:DocumentResponse></ar:ApplicationResponse>',
            htmlspecialchars($reference, ENT_XML1),
            htmlspecialchars($code, ENT_XML1),
            htmlspecialchars($description, ENT_XML1),
        );
        $tmp = tempnam(sys_get_temp_dir(), 'cdr');
        if ($tmp === false) {
            throw new \RuntimeException('No se pudo crear un archivo temporal.');
        }
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('R-' . $reference . '.xml', $xml);
        $zip->close();
        $content = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $content;
    }
}
