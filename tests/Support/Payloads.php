<?php

declare(strict_types=1);

namespace Desertica\Billing\Tests\Support;

final class Payloads
{
    public const BOLETA_ID = '11111111-1111-4111-8111-111111111111';
    public const FACTURA_ID = '22222222-2222-4222-8222-222222222222';
    public const NOTE_ID = '33333333-3333-4333-8333-333333333333';

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function boleta(array $overrides = []): array
    {
        return $overrides + [
            'externalId' => self::BOLETA_ID,
            'docType' => 'BOLETA',
            'series' => 'B001',
            'number' => 1,
            'issueDate' => '2026-10-04',
            'currency' => 'PEN',
            'igvRate' => '0.1800',
            'customer' => ['idDocType' => 'DNI', 'idDocNumber' => '46712369', 'name' => 'MARIA RAMOS ARTEAGA'],
            'items' => [
                ['description' => 'Tour en dune buggy y sandboard', 'quantity' => 2, 'unitPriceCents' => 5900, 'totalCents' => 11800],
            ],
            'totalCents' => 11800,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function factura(array $overrides = []): array
    {
        return $overrides + [
            'externalId' => self::FACTURA_ID,
            'docType' => 'FACTURA',
            'series' => 'F001',
            'number' => 1,
            'issueDate' => '2026-10-04',
            'currency' => 'PEN',
            'igvRate' => '0.1800',
            'customer' => ['idDocType' => 'RUC', 'idDocNumber' => '20100070970', 'name' => 'EMPRESA CLIENTE SAC', 'address' => 'AV LIMA 123'],
            'items' => [
                ['description' => 'Tour Paracas', 'quantity' => 3, 'unitPriceCents' => 12000, 'totalCents' => 36000],
                ['description' => 'Traslado', 'quantity' => 1, 'unitPriceCents' => 5000, 'totalCents' => 5000],
            ],
            'totalCents' => 41000,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function creditNote(array $overrides = []): array
    {
        return $overrides + [
            'externalId' => self::NOTE_ID,
            'docType' => 'NOTA_CREDITO',
            'series' => 'FC01',
            'number' => 1,
            'issueDate' => '2026-10-04',
            'currency' => 'PEN',
            'igvRate' => '0.1800',
            'customer' => ['idDocType' => 'RUC', 'idDocNumber' => '20100070970', 'name' => 'EMPRESA CLIENTE SAC'],
            'items' => [['description' => 'Anulación de la operación', 'quantity' => 1, 'unitPriceCents' => 5000, 'totalCents' => 5000]],
            'totalCents' => 5000,
            'affectedDocument' => ['docType' => 'FACTURA', 'series' => 'F001', 'number' => 1],
            'reasonCode' => '01',
            'reason' => 'Anulación de la operación',
        ];
    }
}
