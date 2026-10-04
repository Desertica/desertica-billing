<?php

declare(strict_types=1);

namespace Desertica\Billing\Domain;

use Desertica\Billing\Http\Error\ApiError;

/**
 * Valida el cuerpo de `POST /v1/documents` y lo convierte en un `EmitRequest`.
 * Reúne todos los problemas en un solo 422 para que el API los corrija de una vez.
 */
final class RequestValidator
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const MAX_ITEMS = 500;

    /** @var list<string> */
    private array $errors = [];

    /**
     * @param int $anonymousBoletaMaxCents boletas en PEN hasta este monto pueden omitir `customer`; 0 = el receptor siempre es obligatorio
     */
    public function __construct(private readonly int $anonymousBoletaMaxCents = 0)
    {
    }

    /**
     * @param array<mixed> $body
     */
    public function validate(array $body, \DateTimeImmutable $today): EmitRequest
    {
        $this->errors = [];

        $externalId = $this->string($body, 'externalId', 36, 36);
        if ($externalId !== null && !preg_match(self::UUID, $externalId)) {
            $this->errors[] = 'externalId debe ser un UUID.';
        }

        $docTypeRaw = $this->string($body, 'docType', 1, 20);
        $docType = $docTypeRaw === null ? null : DocType::tryFrom($docTypeRaw);
        if ($docTypeRaw !== null && $docType === null) {
            $this->errors[] = 'docType debe ser BOLETA, FACTURA, NOTA_CREDITO o NOTA_DEBITO.';
        }

        $series = $this->string($body, 'series', 4, 4);
        $number = $this->int($body, 'number', 1, 99999999);
        $issueDate = $this->date($body, 'issueDate', $today);

        $currency = $this->string($body, 'currency', 3, 3);
        if ($currency !== null && !in_array($currency, ['PEN', 'USD'], true)) {
            $this->errors[] = 'currency debe ser PEN o USD.';
        }

        $exchangeRate = isset($body['exchangeRate']) ? $this->string($body, 'exchangeRate', 1, 12) : null;
        if ($exchangeRate !== null && (!preg_match('/^\d{1,4}(\.\d{1,4})?$/', $exchangeRate) || (float) $exchangeRate <= 0)) {
            $this->errors[] = 'exchangeRate debe ser un decimal positivo con hasta 4 decimales.';
        }
        if ($currency === 'USD' && $exchangeRate === null) {
            $this->errors[] = 'exchangeRate (tipo de cambio SUNAT) es obligatorio cuando currency es USD.';
        }

        $igvRate = $this->string($body, 'igvRate', 1, 8);
        if ($igvRate !== null) {
            try {
                TaxCalculator::parseRate($igvRate);
            } catch (\InvalidArgumentException $e) {
                $this->errors[] = $e->getMessage();
            }
        }

        $items = $this->items($body['items'] ?? null);
        $totalCents = $this->int($body, 'totalCents', 1, PHP_INT_MAX);
        $customer = $this->customerOrAnonymous($body, $docType, $currency, $totalCents);
        if ($totalCents !== null && $items !== []) {
            $sum = array_sum(array_column($items, 'totalCents'));
            if ($sum !== $totalCents) {
                $this->errors[] = sprintf('totalCents (%d) no coincide con la suma de los ítems (%d).', $totalCents, $sum);
            }
        }

        $submission = isset($body['submission']) ? $this->string($body, 'submission', 1, 20) : null;
        if ($submission !== null && !in_array($submission, ['IMMEDIATE', 'DAILY_SUMMARY'], true)) {
            $this->errors[] = 'submission debe ser IMMEDIATE o DAILY_SUMMARY.';
        }

        $affected = $this->affectedDocument($body['affectedDocument'] ?? null);
        $reasonCode = isset($body['reasonCode']) ? $this->string($body, 'reasonCode', 1, 2) : null;
        $reason = isset($body['reason']) ? $this->string($body, 'reason', 1, 250) : null;
        $notes = isset($body['notes']) ? $this->string($body, 'notes', 1, 1000) : null;

        $this->crossChecks($docType, $series, $affected, $reasonCode, $submission, $customer);

        if ($this->errors !== []) {
            throw ApiError::unprocessable('VALIDATION_ERROR', implode(' ', $this->errors));
        }

        /** @var DocType $docType */
        /** @var array{idDocType: string, idDocNumber: string, name: string}|null $customer */
        return new EmitRequest(
            strtolower((string) $externalId),
            $docType,
            (string) $series,
            (int) $number,
            (string) $issueDate,
            (string) $currency,
            $exchangeRate,
            (string) $igvRate,
            $customer,
            $items,
            (int) $totalCents,
            $submission,
            $affected,
            $reasonCode,
            $reason,
            $notes,
        );
    }

    /**
     * @param array{docType: string, series: string, number: int}|null $affected
     * @param array<string, string>|null $customer
     */
    private function crossChecks(
        ?DocType $docType,
        ?string $series,
        ?array $affected,
        ?string $reasonCode,
        ?string $submission,
        ?array $customer,
    ): void {
        if ($docType === null) {
            return;
        }
        $isNote = $docType->isNote();

        if ($isNote && $affected === null) {
            $this->errors[] = 'affectedDocument es obligatorio en notas de crédito y de débito.';
        }
        if (!$isNote && ($affected !== null || $reasonCode !== null)) {
            $this->errors[] = 'affectedDocument y reasonCode solo aplican a notas de crédito y de débito.';
        }
        if ($isNote) {
            $reasons = Catalogs::reasonsFor($docType);
            if ($reasonCode === null) {
                $this->errors[] = 'reasonCode es obligatorio en notas de crédito y de débito.';
            } elseif (!isset($reasons[$reasonCode])) {
                $this->errors[] = sprintf('reasonCode %s no es un código válido del catálogo (%s).', $reasonCode, implode(', ', array_keys($reasons)));
            }
        }

        // Prefijo de serie: B para boletas (y notas de boletas), F para facturas (y notas de facturas).
        $family = $isNote ? ($affected['docType'] ?? null) : $docType->value;
        $expectedPrefix = match ($family) {
            DocType::Boleta->value => 'B',
            DocType::Factura->value => 'F',
            default => null,
        };
        if ($series !== null && $expectedPrefix !== null && !preg_match('/^' . $expectedPrefix . '[A-Z0-9]{3}$/', $series)) {
            $this->errors[] = sprintf('series debe tener 4 caracteres y empezar con %s para este tipo de comprobante.', $expectedPrefix);
        }

        if ($docType === DocType::Factura && $customer !== null && ($customer['idDocType'] ?? null) !== 'RUC') {
            $this->errors[] = 'Una factura exige un cliente con RUC.';
        }

        if ($submission === 'DAILY_SUMMARY') {
            $boletaWorld = $docType === DocType::Boleta || ($isNote && ($affected['docType'] ?? null) === DocType::Boleta->value);
            if (!$boletaWorld) {
                $this->errors[] = 'DAILY_SUMMARY solo aplica a boletas y a notas que modifican boletas.';
            }
        }
    }

    /**
     * El receptor puede omitirse solo en una boleta en PEN cuyo total no supere el tope configurado (0 = nunca).
     * Si se omite, queda `null` y el XML lleva el receptor genérico.
     *
     * @param array<mixed> $body
     * @return array<string, string>|null
     */
    private function customerOrAnonymous(array $body, ?DocType $docType, ?string $currency, ?int $totalCents): ?array
    {
        $anonymousAllowed = $this->anonymousBoletaMaxCents > 0
            && $docType === DocType::Boleta
            && $currency === 'PEN'
            && $totalCents !== null
            && $totalCents <= $this->anonymousBoletaMaxCents;
        if (!isset($body['customer']) && $anonymousAllowed) {
            return null;
        }

        return $this->customer($body['customer'] ?? null, $docType);
    }

    /**
     * @return array<string, string>|null
     */
    private function customer(mixed $raw, ?DocType $docType): ?array
    {
        if (!is_array($raw)) {
            $this->errors[] = 'customer es obligatorio.';

            return null;
        }
        $type = $this->string($raw, 'customer.idDocType', 1, 10, 'idDocType');
        $number = $this->string($raw, 'customer.idDocNumber', 1, 20, 'idDocNumber');
        $name = $this->string($raw, 'customer.name', 1, 200, 'name');
        $out = [];
        if ($type !== null && !isset(Catalogs::ID_TYPES[$type])) {
            $this->errors[] = 'customer.idDocType debe ser DNI, CE, PASSPORT o RUC.';
        } elseif ($type !== null && $number !== null) {
            $pattern = match ($type) {
                'DNI' => '/^\d{8}$/',
                'RUC' => '/^\d{11}$/',
                default => '/^[A-Za-z0-9]{1,12}$/',
            };
            if (!preg_match($pattern, $number)) {
                $this->errors[] = sprintf('customer.idDocNumber no tiene el formato de un %s.', $type);
            }
        }
        foreach (['address' => 200, 'email' => 100] as $key => $max) {
            if (isset($raw[$key])) {
                $value = $this->string($raw, 'customer.' . $key, 1, $max, $key);
                if ($value !== null) {
                    $out[$key] = $value;
                }
            }
        }
        if (isset($out['email']) && filter_var($out['email'], FILTER_VALIDATE_EMAIL) === false) {
            $this->errors[] = 'customer.email no es un correo válido.';
        }
        if ($type === null || $number === null || $name === null) {
            return null;
        }

        return ['idDocType' => $type, 'idDocNumber' => $number, 'name' => $name] + $out;
    }

    /**
     * @return list<array{description: string, quantity: int, unitPriceCents: int, totalCents: int}>
     */
    private function items(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw) || $raw === []) {
            $this->errors[] = 'items debe ser una lista con al menos un ítem.';

            return [];
        }
        if (count($raw) > self::MAX_ITEMS) {
            $this->errors[] = sprintf('items admite como máximo %d ítems.', self::MAX_ITEMS);

            return [];
        }
        $items = [];
        foreach ($raw as $i => $item) {
            if (!is_array($item)) {
                $this->errors[] = sprintf('items[%d] debe ser un objeto.', $i);
                continue;
            }
            $description = $this->string($item, "items[$i].description", 1, 250, 'description');
            $quantity = $this->int($item, "items[$i].quantity", 1, 1000000, 'quantity');
            $unit = $this->int($item, "items[$i].unitPriceCents", 1, PHP_INT_MAX, 'unitPriceCents');
            $total = $this->int($item, "items[$i].totalCents", 1, PHP_INT_MAX, 'totalCents');
            if ($quantity !== null && $unit !== null && $total !== null && $quantity * $unit !== $total) {
                $this->errors[] = sprintf('items[%d].totalCents debe ser quantity × unitPriceCents (%d).', $i, $quantity * $unit);
            }
            if ($description !== null && $quantity !== null && $unit !== null && $total !== null) {
                $items[] = [
                    'description' => $description,
                    'quantity' => $quantity,
                    'unitPriceCents' => $unit,
                    'totalCents' => $total,
                ];
            }
        }

        return $items;
    }

    /**
     * @return array{docType: string, series: string, number: int}|null
     */
    private function affectedDocument(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (!is_array($raw)) {
            $this->errors[] = 'affectedDocument debe ser un objeto.';

            return null;
        }
        $type = $this->string($raw, 'affectedDocument.docType', 1, 20, 'docType');
        $series = $this->string($raw, 'affectedDocument.series', 4, 4, 'series');
        $number = $this->int($raw, 'affectedDocument.number', 1, 99999999, 'number');
        if ($type !== null && !in_array($type, [DocType::Boleta->value, DocType::Factura->value], true)) {
            $this->errors[] = 'affectedDocument.docType debe ser BOLETA o FACTURA.';
            $type = null;
        }
        if ($series !== null && $type !== null && !preg_match($type === DocType::Boleta->value ? '/^B[A-Z0-9]{3}$/' : '/^F[A-Z0-9]{3}$/', $series)) {
            $this->errors[] = 'affectedDocument.series no corresponde al tipo de comprobante afectado.';
        }
        if ($type === null || $series === null || $number === null) {
            return null;
        }

        return ['docType' => $type, 'series' => $series, 'number' => $number];
    }

    /**
     * @param array<mixed> $data
     */
    private function string(array $data, string $label, int $min, int $max, ?string $key = null): ?string
    {
        $key ??= $label;
        $value = $data[$key] ?? null;
        if (!is_string($value)) {
            $this->errors[] = sprintf('%s es obligatorio y debe ser texto.', $label);

            return null;
        }
        $value = trim($value);
        $length = mb_strlen($value);
        if ($length < $min || $length > $max) {
            $this->errors[] = $min === $max
                ? sprintf('%s debe tener %d caracteres.', $label, $min)
                : sprintf('%s debe tener entre %d y %d caracteres.', $label, $min, $max);

            return null;
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
            $this->errors[] = sprintf('%s contiene caracteres de control.', $label);

            return null;
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private function int(array $data, string $label, int $min, int $max, ?string $key = null): ?int
    {
        $key ??= $label;
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            $this->errors[] = sprintf('%s es obligatorio y debe ser un entero.', $label);

            return null;
        }
        if ($value < $min || $value > $max) {
            $this->errors[] = sprintf('%s fuera de rango.', $label);

            return null;
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private function date(array $data, string $key, \DateTimeImmutable $today): ?string
    {
        $value = $this->string($data, $key, 10, 10);
        if ($value === null) {
            return null;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            $this->errors[] = sprintf('%s debe ser una fecha YYYY-MM-DD.', $key);

            return null;
        }
        if ($value > $today->format('Y-m-d')) {
            $this->errors[] = sprintf('%s no puede ser futura (hoy en America/Lima es %s).', $key, $today->format('Y-m-d'));

            return null;
        }

        return $value;
    }
}
