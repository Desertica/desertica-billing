<?php

declare(strict_types=1);

namespace Desertica\Billing\Persistence;

final class DocumentRecord
{
    public const PENDING = 'PENDING';
    public const ISSUED = 'ISSUED';
    public const ACCEPTED = 'ACCEPTED';
    public const REJECTED = 'REJECTED';
    public const VOIDED = 'VOIDED';
    public const ERROR = 'ERROR';

    public function __construct(
        public readonly string $externalId,
        public readonly string $requestHash,
        public readonly string $requestJson,
        public readonly string $docType,
        public readonly string $series,
        public readonly int $number,
        public readonly string $issueDate,
        public readonly string $currency,
        public readonly string $submission,
        public readonly string $status,
        public readonly int $taxableCents,
        public readonly int $igvCents,
        public readonly int $totalCents,
        public readonly string $fileName,
        public readonly ?string $hash,
        public readonly ?string $sunatCode,
        public readonly ?string $sunatMessage,
        public readonly ?string $ticket,
        public readonly ?string $voidTicket,
        public readonly ?string $voidReason,
        public readonly bool $hasXml,
        public readonly bool $hasCdr,
        public readonly bool $hasPdf,
        /** El XML llegó a enviarse alguna vez a SUNAT (aunque la respuesta se perdiera). */
        public readonly bool $sendAttempted = false,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $str = static fn (string $k): ?string => isset($row[$k]) ? (string) $row[$k] : null;

        return new self(
            (string) $row['external_id'],
            (string) $row['request_hash'],
            (string) $row['request_json'],
            (string) $row['doc_type'],
            (string) $row['series'],
            (int) $row['number'],
            (string) $row['issue_date'],
            (string) $row['currency'],
            (string) $row['submission'],
            (string) $row['status'],
            (int) $row['taxable_cents'],
            (int) $row['igv_cents'],
            (int) $row['total_cents'],
            (string) $row['file_name'],
            $str('hash'),
            $str('sunat_code'),
            $str('sunat_message'),
            $str('ticket'),
            $str('void_ticket'),
            $str('void_reason'),
            (bool) $row['has_xml'],
            (bool) $row['has_cdr'],
            (bool) $row['has_pdf'],
            (bool) ($row['send_attempted'] ?? false),
        );
    }

    /**
     * Cuerpo `EmitDocumentResult` del contrato.
     *
     * @return array<string, mixed>
     */
    public function toResult(): array
    {
        $result = [
            'externalId' => $this->externalId,
            'status' => $this->status,
            'taxableCents' => $this->taxableCents,
            'igvCents' => $this->igvCents,
            'totalCents' => $this->totalCents,
            'sunatCode' => $this->sunatCode,
            'sunatMessage' => $this->sunatMessage,
            'ticket' => $this->ticket,
            'files' => ['xml' => $this->hasXml, 'cdr' => $this->hasCdr, 'pdf' => $this->hasPdf],
        ];
        // El contrato define `hash` como string (no nulo): sin firma todavía se omite.
        if ($this->hash !== null) {
            $result['hash'] = $this->hash;
        }

        return $result;
    }
}
