<?php

declare(strict_types=1);

namespace Desertica\Billing\Service;

use Desertica\Billing\Domain\DocType;
use Desertica\Billing\Http\Error\ApiError;
use Desertica\Billing\Persistence\DocumentRecord;
use Desertica\Billing\Persistence\DocumentRepository;
use Desertica\Billing\Persistence\TicketRecord;
use Desertica\Billing\Persistence\TicketRepository;
use Desertica\Billing\Storage\FileStorage;
use Desertica\Billing\Sunat\SunatException;
use Desertica\Billing\Sunat\SunatGateway;
use Desertica\Billing\Sunat\TicketStatus;
use Desertica\Billing\Xml\BatchFactory;
use Desertica\Billing\Xml\XmlSigner;
use Greenter\Model\DocumentInterface;
use Psr\Log\LoggerInterface;

/**
 * Operaciones asíncronas con ticket: comunicación de baja, resumen diario de boletas y consulta de tickets.
 *
 * - Las facturas y sus notas se anulan con comunicación de baja (RA).
 * - Las boletas y las notas de boletas se anulan con un resumen diario (RC) con estado «anulado» (Greenter docs, usage.md).
 */
final class BatchService
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly TicketRepository $tickets,
        private readonly FileStorage $storage,
        private readonly SunatGateway $gateway,
        private readonly BatchFactory $factory,
        private readonly XmlSigner $signer,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function void(string $externalId, string $reason, string $voidDate): TicketRecord
    {
        $record = $this->documents->find($externalId) ?? throw ApiError::notFound('Comprobante desconocido.');

        if ($record->voidTicket !== null) {
            return $this->existingVoid($record, $reason);
        }
        if ($record->status !== DocumentRecord::ACCEPTED) {
            throw ApiError::conflict('NOT_VOIDABLE', match ($record->status) {
                DocumentRecord::VOIDED => 'El comprobante ya fue anulado.',
                DocumentRecord::ISSUED => 'La boleta aún no fue enviada a SUNAT en un resumen diario; no hay nada que anular allí.',
                default => sprintf('Solo se puede anular un comprobante aceptado por SUNAT (estado actual: %s).', $record->status),
            });
        }
        if ($voidDate < $record->issueDate) {
            throw ApiError::unprocessable('VALIDATION_ERROR', 'voidDate no puede ser anterior a la fecha de emisión.');
        }
        if ($voidDate > $this->clock->now()->format('Y-m-d')) {
            throw ApiError::unprocessable('VALIDATION_ERROR', 'voidDate no puede ser futura.');
        }

        $day = str_replace('-', '', $voidDate);
        if ($this->concernsBoleta($record)) {
            $sequence = $this->tickets->nextSequence('RC', $day);
            $document = $this->factory->summary([$record], $record->currency, $record->issueDate, $voidDate, $sequence, '3');
            $kind = TicketRecord::SUMMARY;
        } else {
            $sequence = $this->tickets->nextSequence('RA', $day);
            $document = $this->factory->voided($record, $voidDate, $reason, $sequence);
            $kind = TicketRecord::VOIDED;
        }

        $ticket = $this->submit($document, $kind, $voidDate, $record->currency, [['externalId' => $externalId, 'action' => 'VOID']]);
        $this->documents->update($externalId, ['void_ticket' => $ticket->ticket, 'void_reason' => $reason], $this->now());

        return $ticket;
    }

    /**
     * Envía el resumen diario de las boletas (y sus notas) de una fecha que esperan envío. Una llamada atiende
     * una moneda: la indicada en `$currency` o, sin ella, la siguiente pendiente (PEN primero). Greenter genera el
     * resumen con una sola moneda; repetir la llamada atiende la otra.
     */
    public function dailySummary(string $date, ?string $currency = null): TicketRecord
    {
        $pending = $this->documents->pendingForSummary($date);
        if ($currency !== null) {
            $pending = array_values(array_filter($pending, static fn (DocumentRecord $r) => $r->currency === $currency));
        }
        if ($pending === []) {
            $latest = $this->tickets->latestSummary($date, $currency);
            if ($latest !== null) {
                return $latest;
            }
            throw ApiError::unprocessable('NOTHING_TO_SUMMARIZE', sprintf(
                'No hay boletas con envío por resumen diario del %s%s.',
                $date,
                $currency === null ? '' : ' en ' . $currency,
            ));
        }

        $currency = $pending[0]->currency;
        $group = array_values(array_filter($pending, static fn (DocumentRecord $r) => $r->currency === $currency));
        $sendDate = $this->clock->now()->format('Y-m-d');
        $sequence = $this->tickets->nextSequence('RC', str_replace('-', '', $sendDate));
        $summary = $this->factory->summary($group, $currency, $date, $sendDate, $sequence);

        $links = array_map(static fn (DocumentRecord $r) => ['externalId' => $r->externalId, 'action' => 'ADD'], $group);
        $ticket = $this->submit($summary, TicketRecord::SUMMARY, $date, $currency, $links);
        foreach ($group as $record) {
            $this->documents->update($record->externalId, ['ticket' => $ticket->ticket], $this->now());
        }

        return $ticket;
    }

    public function ticket(string $ticket): TicketRecord
    {
        $record = $this->tickets->find($ticket) ?? throw ApiError::notFound('Ticket desconocido.');
        if ($record->status !== TicketStatus::PENDING) {
            return $record;
        }

        try {
            $status = $this->gateway->ticketStatus($ticket);
        } catch (SunatException $e) {
            if ($e->retryable) {
                throw ApiError::upstream($e->getMessage(), $e->sunatCode);
            }
            $this->settle($record, 'ERROR', $e->sunatCode, $e->getMessage(), null);

            return $this->tickets->find($ticket) ?? $record;
        }

        if ($status->state !== TicketStatus::PENDING) {
            $this->settle($record, $status->state, $status->code, $status->message, $status->cdrZip);
        }

        return $this->tickets->find($ticket) ?? $record;
    }

    /**
     * Firma, envía y registra el ticket.
     *
     * @param list<array{externalId: string, action: string}> $links
     */
    private function submit(DocumentInterface $document, string $kind, string $refDate, ?string $currency, array $links): TicketRecord
    {
        $signed = $this->signer->sign($document);
        try {
            $ticketId = $this->gateway->sendBatch($signed->xml);
        } catch (SunatException $e) {
            if ($e->retryable) {
                $this->logger->warning('sunat_unavailable', ['sunatCode' => $e->sunatCode, 'kind' => $kind]);
                throw ApiError::upstream($e->getMessage(), $e->sunatCode);
            }
            throw ApiError::unprocessable('SUNAT_EXCEPTION', $e->getMessage(), $e->sunatCode);
        }

        $this->storage->writeTicketXml($ticketId, $signed->xml);
        $record = new TicketRecord($ticketId, $kind, $signed->fileName, $refDate, $currency, TicketStatus::PENDING, null, null);
        $this->tickets->create($record, $links, $this->now());

        return $record;
    }

    private function settle(TicketRecord $ticket, string $state, ?string $code, ?string $message, ?string $cdrZip): void
    {
        $now = $this->now();
        if ($cdrZip !== null && $cdrZip !== '') {
            $this->storage->write('tickets/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $ticket->ticket), FileStorage::CDR, $cdrZip);
        }
        $this->tickets->updateStatus($ticket->ticket, $state, $code, $message, $now);

        foreach ($this->tickets->documents($ticket->ticket) as $link) {
            if ($state === TicketStatus::ACCEPTED) {
                $this->documents->update(
                    $link['externalId'],
                    $link['action'] === 'VOID'
                        ? ['status' => DocumentRecord::VOIDED]
                        : ['status' => DocumentRecord::ACCEPTED, 'sunat_code' => $code, 'sunat_message' => $message],
                    $now,
                );
                continue;
            }
            // Rechazado o con error: se libera el comprobante para poder reintentar con otra llamada.
            $this->documents->update(
                $link['externalId'],
                $link['action'] === 'VOID'
                    ? ['void_ticket' => null, 'void_reason' => null, 'sunat_code' => $code, 'sunat_message' => $message]
                    : ['ticket' => null, 'sunat_code' => $code, 'sunat_message' => $message],
                $now,
            );
        }
    }

    private function existingVoid(DocumentRecord $record, string $reason): TicketRecord
    {
        $ticket = $this->tickets->find((string) $record->voidTicket);
        if ($ticket === null) {
            throw new \LogicException('Ticket de baja desaparecido.');
        }
        if ($record->voidReason !== $reason) {
            throw ApiError::conflict('VOID_CONFLICT', 'El comprobante ya tiene una baja en curso con otro motivo.');
        }

        return $ticket;
    }

    private function concernsBoleta(DocumentRecord $record): bool
    {
        if ($record->docType === DocType::Boleta->value) {
            return true;
        }
        $request = json_decode($record->requestJson, true, 512, JSON_THROW_ON_ERROR);

        return is_array($request) && ($request['affectedDocument']['docType'] ?? null) === DocType::Boleta->value;
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
