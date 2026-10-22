<?php

declare(strict_types=1);

namespace Desertica\Billing\Persistence;

final class TicketRecord
{
    public const SUMMARY = 'SUMMARY';
    public const VOIDED = 'VOIDED';

    public function __construct(
        public readonly string $ticket,
        public readonly string $kind,
        public readonly string $fileName,
        public readonly string $refDate,
        public readonly ?string $currency,
        public readonly string $status,
        public readonly ?string $sunatCode,
        public readonly ?string $sunatMessage,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) $row['ticket'],
            (string) $row['kind'],
            (string) $row['file_name'],
            (string) $row['ref_date'],
            isset($row['currency']) ? (string) $row['currency'] : null,
            (string) $row['status'],
            isset($row['sunat_code']) ? (string) $row['sunat_code'] : null,
            isset($row['sunat_message']) ? (string) $row['sunat_message'] : null,
        );
    }

    /**
     * Cuerpo `TicketResult` del contrato.
     *
     * @return array<string, mixed>
     */
    public function toResult(): array
    {
        return [
            'ticket' => $this->ticket,
            'status' => $this->status,
            'sunatCode' => $this->sunatCode,
            'sunatMessage' => $this->sunatMessage,
        ];
    }
}
