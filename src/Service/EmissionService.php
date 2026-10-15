<?php

declare(strict_types=1);

namespace Desertica\Billing\Service;

use Desertica\Billing\Domain\DocType;
use Desertica\Billing\Domain\EmitRequest;
use Desertica\Billing\Domain\RequestValidator;
use Desertica\Billing\Http\Error\ApiError;
use Desertica\Billing\Persistence\DocumentRecord;
use Desertica\Billing\Persistence\DocumentRepository;
use Desertica\Billing\Storage\FileStorage;
use Desertica\Billing\Sunat\CdrResult;
use Desertica\Billing\Sunat\SunatException;
use Desertica\Billing\Sunat\SunatGateway;
use Desertica\Billing\Xml\DocumentFactory;
use Desertica\Billing\Xml\XmlSigner;
use Psr\Log\LoggerInterface;

/**
 * Emisión idempotente por `externalId`.
 *
 * - Mismo `externalId` y mismo contenido: devuelve lo guardado (y, si quedó a medias por un fallo de SUNAT, reintenta
 *   enviando el MISMO XML firmado: nunca se vuelve a firmar algo que SUNAT pudo haber recibido).
 * - Mismo `externalId` con otro contenido, o serie y número ya usados por otro comprobante: 409.
 */
final class EmissionService
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly FileStorage $storage,
        private readonly SunatGateway $gateway,
        private readonly DocumentFactory $factory,
        private readonly XmlSigner $signer,
        private readonly PdfRenderer $pdf,
        private readonly Clock $clock,
        private readonly string $defaultBoletaSubmission,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<mixed> $body
     */
    public function emit(array $body): DocumentRecord
    {
        $request = (new RequestValidator())->validate($body, $this->clock->now());
        $hash = $request->contentHash();

        $existing = $this->documents->find($request->externalId);
        if ($existing !== null) {
            return $this->resume($existing, $request, $hash);
        }

        $other = $this->documents->findBySeriesNumber($request->series, $request->number);
        if ($other !== null) {
            throw $this->seriesInUse($request);
        }

        $built = $this->factory->build($request);
        $submission = $this->resolveSubmission($request);
        $now = $this->now();
        $record = new DocumentRecord(
            $request->externalId,
            $hash,
            json_encode($request->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $request->docType->value,
            $request->series,
            $request->number,
            $request->issueDate,
            $request->currency,
            $submission,
            DocumentRecord::PENDING,
            $built['taxableCents'],
            $built['igvCents'],
            $built['totalCents'],
            sprintf('%s-%s-%s-%d', $this->factory->company()->getRuc(), $request->docType->sunatCode(), $request->series, $request->number),
            null,
            null,
            null,
            null,
            null,
            null,
            false,
            false,
            false,
        );

        try {
            // La inserción es la puerta de la idempotencia: solo una petición concurrente la gana.
            $this->documents->insert($record, $now);
        } catch (\PDOException $e) {
            $winner = $this->documents->find($request->externalId);
            if ($winner !== null) {
                return $this->resume($winner, $request, $hash);
            }
            if ($this->documents->findBySeriesNumber($request->series, $request->number) !== null) {
                throw $this->seriesInUse($request);
            }
            throw $e;
        }

        return $this->signAndSubmit($record, $request);
    }

    public function get(string $externalId): DocumentRecord
    {
        return $this->documents->find($externalId) ?? throw ApiError::notFound('Comprobante desconocido.');
    }

    private function resume(DocumentRecord $existing, EmitRequest $request, string $hash): DocumentRecord
    {
        if ($existing->requestHash !== $hash) {
            throw ApiError::conflict(
                'IDEMPOTENCY_CONFLICT',
                'La externalId ya se usó con otro contenido; los comprobantes emitidos no se modifican.',
            );
        }

        return match ($existing->status) {
            DocumentRecord::PENDING => $this->signAndSubmit($existing, $request),
            DocumentRecord::ERROR => throw ApiError::unprocessable(
                'SUNAT_EXCEPTION',
                $existing->sunatMessage ?? 'SUNAT no aceptó el comprobante.',
                $existing->sunatCode,
            ),
            default => $existing,
        };
    }

    /**
     * Firma (si aún no hay XML) y envía. Se llama también para reintentar un comprobante `PENDING`.
     */
    private function signAndSubmit(DocumentRecord $record, EmitRequest $request): DocumentRecord
    {
        $xml = $record->hasXml ? $this->storage->read($record->externalId, FileStorage::XML) : null;
        if ($xml === null) {
            $built = $this->factory->build($request);
            $signed = $this->signer->sign($built['document']);
            $this->storage->write($record->externalId, FileStorage::XML, $signed->xml);
            $this->documents->update($record->externalId, ['hash' => $signed->hash, 'has_xml' => true], $this->now());
            $xml = $signed->xml;
            $record = $this->reload($record->externalId);
        }

        if ($record->submission === 'DAILY_SUMMARY') {
            $this->documents->update($record->externalId, ['status' => DocumentRecord::ISSUED], $this->now());
            $this->renderPdf($this->reload($record->externalId), $request);

            return $this->reload($record->externalId);
        }

        try {
            $cdr = $this->gateway->sendDocument($xml);
        } catch (SunatException $e) {
            if ($e->retryable) {
                // El comprobante queda PENDING con su XML firmado; el API reintenta con la misma externalId.
                $this->logger->warning('sunat_unavailable', ['externalId' => $record->externalId, 'sunatCode' => $e->sunatCode]);
                throw ApiError::upstream($e->getMessage(), $e->sunatCode);
            }
            $this->documents->update($record->externalId, [
                'status' => DocumentRecord::ERROR,
                'sunat_code' => $e->sunatCode,
                'sunat_message' => $e->getMessage(),
            ], $this->now());
            throw ApiError::unprocessable('SUNAT_EXCEPTION', $e->getMessage(), $e->sunatCode);
        }

        $this->storage->write($record->externalId, FileStorage::CDR, $cdr->zip);
        $this->documents->update($record->externalId, [
            'status' => $cdr->accepted ? DocumentRecord::ACCEPTED : DocumentRecord::REJECTED,
            'sunat_code' => $cdr->code,
            'sunat_message' => $this->cdrMessage($cdr),
            'has_cdr' => true,
        ], $this->now());
        $this->renderPdf($this->reload($record->externalId), $request);

        return $this->reload($record->externalId);
    }

    /**
     * Devuelve el archivo; el PDF se genera bajo demanda si no se pudo al emitir.
     */
    public function file(string $externalId, string $kind): string
    {
        $record = $this->get($externalId);
        $content = $this->storage->read($externalId, $kind);
        if ($content === null && $kind === FileStorage::PDF && $record->hasXml) {
            $request = (new RequestValidator())->validate(
                json_decode($record->requestJson, true, 512, JSON_THROW_ON_ERROR),
                new \DateTimeImmutable($record->issueDate . ' 23:59:59', new \DateTimeZone('America/Lima')),
            );
            $this->renderPdf($record, $request);
            $content = $this->storage->read($externalId, FileStorage::PDF);
        }

        return $content ?? throw ApiError::notFound('El archivo todavía no existe.');
    }

    private function renderPdf(DocumentRecord $record, EmitRequest $request): void
    {
        if ($record->hash === null) {
            return;
        }
        $built = $this->factory->build($request);
        $pdf = $this->pdf->render($built['document'], $record->hash, $request->notes);
        if ($pdf === null) {
            return;
        }
        $this->storage->write($record->externalId, FileStorage::PDF, $pdf);
        $this->documents->update($record->externalId, ['has_pdf' => true], $this->now());
    }

    private function cdrMessage(CdrResult $cdr): string
    {
        return $cdr->notes === [] ? $cdr->message : $cdr->message . ' | Observaciones: ' . implode('; ', $cdr->notes);
    }

    private function resolveSubmission(EmitRequest $request): string
    {
        if ($request->submission !== null) {
            return $request->submission;
        }

        // Las facturas (y sus notas) nunca van por resumen diario.
        return $request->concernsBoleta() ? $this->defaultBoletaSubmission : 'IMMEDIATE';
    }

    private function seriesInUse(EmitRequest $request): ApiError
    {
        return ApiError::conflict('SERIES_NUMBER_IN_USE', sprintf('La serie %s número %d ya pertenece a otro comprobante.', $request->series, $request->number));
    }

    private function reload(string $externalId): DocumentRecord
    {
        return $this->documents->find($externalId) ?? throw new \LogicException('Comprobante desaparecido.');
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
