<?php

declare(strict_types=1);

namespace Desertica\Billing\Service;

use Desertica\Billing\Domain\DocType;
use Desertica\Billing\Domain\EmitRequest;
use Desertica\Billing\Domain\RequestValidator;
use Desertica\Billing\Http\Error\ApiError;
use Desertica\Billing\Persistence\DocumentRecord;
use Desertica\Billing\Persistence\DocumentRepository;
use Desertica\Billing\Persistence\LockRepository;
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
    /** Vigencia del cerrojo: más que el máximo de una petición (110 s en php-fpm). */
    private const LOCK_SECONDS = 130;

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
        private readonly LockRepository $locks,
        private readonly int $anonymousBoletaMaxCents = 0,
        /** @var list<string> Códigos de excepción de SUNAT que significan «ese comprobante ya está registrado» (recuperar su CDR en vez de fallar). */
        private readonly array $alreadyRegisteredCodes = [],
    ) {
    }

    /**
     * @param array<mixed> $body
     */
    public function emit(array $body): DocumentRecord
    {
        $request = (new RequestValidator($this->anonymousBoletaMaxCents))->validate($body, $this->clock->now());
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
        $lock = self::lockName($record->externalId);
        if (!$this->locks->acquire($lock, $this->clock->now()->getTimestamp(), self::LOCK_SECONDS)) {
            // Otra petición con la misma externalId está firmando o enviando: no se compite con ella (firmaría un XML distinto).
            // Se devuelve el estado actual (PENDING); el API consulta `GET /v1/documents/{externalId}` o reintenta.
            $this->logger->info('emission_in_progress', ['externalId' => $record->externalId]);

            return $this->reload($record->externalId);
        }

        try {
            // Con el cerrojo tomado se vuelve a leer: la petición que lo tenía pudo haber terminado entre medias.
            $current = $this->reload($record->externalId);
            if ($current->status === DocumentRecord::ERROR) {
                throw ApiError::unprocessable('SUNAT_EXCEPTION', $current->sunatMessage ?? 'SUNAT no aceptó el comprobante.', $current->sunatCode);
            }
            if ($current->status !== DocumentRecord::PENDING) {
                return $current;
            }

            return $this->process($current, $request);
        } finally {
            $this->locks->release($lock);
        }
    }

    public static function lockName(string $externalId): string
    {
        return 'doc:' . $externalId;
    }

    private function process(DocumentRecord $record, EmitRequest $request): DocumentRecord
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

        if ($record->sendAttempted) {
            // Un envío anterior pudo llegar a SUNAT sin que llegara la respuesta: se pregunta por el CDR antes de reenviar.
            $known = $this->tryConsultCdr($record, $request);
            if ($known !== null) {
                return $this->settle($record, $request, $known);
            }
        }
        // Se anota antes de la red: si el proceso muere o se pierde la respuesta, el reintento sabrá que pudo llegar.
        $this->documents->update($record->externalId, ['send_attempted' => true], $this->now());

        try {
            $cdr = $this->gateway->sendDocument($xml);
        } catch (SunatException $e) {
            if ($this->isAlreadyRegistered($e)) {
                try {
                    $cdr = $this->consultCdr($record, $request);
                } catch (SunatException $consultError) {
                    // No se pudo confirmar en SUNAT: el comprobante sigue PENDING y el API reintenta; no se marca ERROR.
                    $this->logger->warning('cdr_consult_failed', ['externalId' => $record->externalId, 'sunatCode' => $consultError->sunatCode]);
                    throw ApiError::upstream('SUNAT ya tiene el comprobante registrado y no se pudo consultar su CDR; reintenta.', $e->sunatCode);
                }
                if ($cdr !== null) {
                    return $this->settle($record, $request, $cdr);
                }
            }
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

        return $this->settle($record, $request, $cdr);
    }

    /**
     * Guarda el CDR (recibido al enviar o recuperado por consulta) y deja el comprobante `ACCEPTED` o `REJECTED`.
     */
    private function settle(DocumentRecord $record, EmitRequest $request, CdrResult $cdr): DocumentRecord
    {
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

    private function isAlreadyRegistered(SunatException $error): bool
    {
        return !$error->retryable && $error->sunatCode !== null && in_array($error->sunatCode, $this->alreadyRegisteredCodes, true);
    }

    /**
     * Consulta el CDR del comprobante con `getStatusCdr`. Devuelve `null` si SUNAT no tiene constancia.
     *
     * @throws SunatException si la consulta falla
     */
    private function consultCdr(DocumentRecord $record, EmitRequest $request): ?CdrResult
    {
        $cdr = $this->gateway->consultCdr(
            $this->factory->issuerRuc(),
            $request->docType->sunatCode(),
            $request->series,
            $request->number,
        );
        $this->logger->info('cdr_consulted', ['externalId' => $record->externalId, 'found' => $cdr !== null]);

        return $cdr;
    }

    /**
     * Igual que `consultCdr`, pero una consulta fallida se registra y se trata como «sin constancia»: antes de reenviar
     * no hay nada que perder (SUNAT contestará con el CDR o con «ya registrado» y entonces sí se exige la consulta).
     */
    private function tryConsultCdr(DocumentRecord $record, EmitRequest $request): ?CdrResult
    {
        try {
            return $this->consultCdr($record, $request);
        } catch (SunatException $e) {
            $this->logger->warning('cdr_consult_failed', ['externalId' => $record->externalId, 'sunatCode' => $e->sunatCode, 'retryable' => $e->retryable]);

            return null;
        }
    }

    /**
     * Devuelve el archivo; el PDF se genera bajo demanda si no se pudo al emitir.
     */
    public function file(string $externalId, string $kind): string
    {
        $record = $this->get($externalId);
        $content = $this->storage->read($externalId, $kind);
        if ($content === null && $kind === FileStorage::PDF && $record->hasXml) {
            // Se rehidrata una petición ya aceptada: el tope de boleta sin receptor no debe volver a rechazarla.
            $request = (new RequestValidator(PHP_INT_MAX))->validate(
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
