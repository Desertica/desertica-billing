<?php

declare(strict_types=1);

namespace Desertica\Billing\Domain;

/**
 * Petición de emisión ya validada (contrato `EmitDocumentRequest`).
 */
final class EmitRequest
{
    /**
     * @param list<array{description: string, quantity: int, unitPriceCents: int, totalCents: int}> $items
     * @param array{idDocType: string, idDocNumber: string, name: string, address?: string, email?: string}|null $customer `null` solo en boletas por debajo del tope configurado
     * @param array{docType: string, series: string, number: int}|null $affectedDocument
     */
    public function __construct(
        public readonly string $externalId,
        public readonly DocType $docType,
        public readonly string $series,
        public readonly int $number,
        public readonly string $issueDate,
        public readonly string $currency,
        public readonly ?string $exchangeRate,
        public readonly string $igvRate,
        public readonly ?array $customer,
        public readonly array $items,
        public readonly int $totalCents,
        public readonly ?string $submission,
        public readonly ?array $affectedDocument,
        public readonly ?string $reasonCode,
        public readonly ?string $reason,
        public readonly ?string $notes,
    ) {
    }

    /**
     * Rehidrata una petición ya validada y guardada (`toArray()`), sin volver a validarla: las reglas de hoy no deben cambiar
     * lo que se aceptó ayer.
     *
     * @param array{
     *     externalId: string, docType: string, series: string, number: int, issueDate: string, currency: string, exchangeRate?: string,
     *     igvRate: string, customer?: array{idDocType: string, idDocNumber: string, name: string, address?: string, email?: string},
     *     items: list<array{description: string, quantity: int, unitPriceCents: int, totalCents: int}>, totalCents: int, submission?: string,
     *     affectedDocument?: array{docType: string, series: string, number: int}, reasonCode?: string, reason?: string, notes?: string
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['externalId'],
            DocType::from($data['docType']),
            $data['series'],
            $data['number'],
            $data['issueDate'],
            $data['currency'],
            $data['exchangeRate'] ?? null,
            $data['igvRate'],
            $data['customer'] ?? null,
            $data['items'],
            $data['totalCents'],
            $data['submission'] ?? null,
            $data['affectedDocument'] ?? null,
            $data['reasonCode'] ?? null,
            $data['reason'] ?? null,
            $data['notes'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'externalId' => $this->externalId,
            'docType' => $this->docType->value,
            'series' => $this->series,
            'number' => $this->number,
            'issueDate' => $this->issueDate,
            'currency' => $this->currency,
            'exchangeRate' => $this->exchangeRate,
            'igvRate' => $this->igvRate,
            'customer' => $this->customer,
            'items' => $this->items,
            'totalCents' => $this->totalCents,
            'submission' => $this->submission,
            'affectedDocument' => $this->affectedDocument,
            'reasonCode' => $this->reasonCode,
            'reason' => $this->reason,
            'notes' => $this->notes,
        ], static fn ($v) => $v !== null);
    }

    /**
     * Huella canónica del contenido: dos peticiones equivalentes dan el mismo hash sin importar el orden de las claves.
     */
    public function contentHash(): string
    {
        return hash('sha256', json_encode(self::canonical($this->toArray()), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function canonical(array $data): array
    {
        if (!array_is_list($data)) {
            ksort($data);
        }
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::canonical($value);
            }
        }

        return $data;
    }

    public function isFor(DocType ...$types): bool
    {
        return in_array($this->docType, $types, true);
    }

    /**
     * True si el comprobante (o la nota) pertenece al mundo de las boletas.
     */
    public function concernsBoleta(): bool
    {
        if ($this->docType === DocType::Boleta) {
            return true;
        }

        return $this->docType->isNote() && ($this->affectedDocument['docType'] ?? null) === DocType::Boleta->value;
    }
}
