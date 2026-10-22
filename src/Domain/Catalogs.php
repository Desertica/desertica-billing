<?php

declare(strict_types=1);

namespace Desertica\Billing\Domain;

/**
 * Códigos de catálogo SUNAT. Fuentes y límites de verificación en docs/DECISIONS.md.
 */
final class Catalogs
{
    /** Catálogo 06: tipo de documento de identidad. */
    public const ID_TYPES = ['DNI' => '1', 'CE' => '4', 'RUC' => '6', 'PASSPORT' => '7'];

    /** Catálogo 09: tipo de nota de crédito electrónica. */
    public const CREDIT_NOTE_REASONS = [
        '01' => 'Anulación de la operación',
        '02' => 'Anulación por error en el RUC',
        '03' => 'Corrección por error en la descripción',
        '04' => 'Descuento global',
        '05' => 'Descuento por ítem',
        '06' => 'Devolución total',
        '07' => 'Devolución por ítem',
        '08' => 'Bonificación',
        '09' => 'Disminución en el valor',
        '10' => 'Otros conceptos',
    ];

    /** Catálogo 10: tipo de nota de débito electrónica. */
    public const DEBIT_NOTE_REASONS = [
        '01' => 'Intereses por mora',
        '02' => 'Aumento en el valor',
        '03' => 'Penalidades / otros conceptos',
    ];

    /** Catálogo 07: gravado, operación onerosa. */
    public const IGV_AFFECTATION_TAXED = '10';

    /** Catálogo 51: venta interna. */
    public const OPERATION_DOMESTIC_SALE = '0101';

    /** Catálogo 52: leyenda «Agencia de Viaje – Paquete turístico» (fuente de terceros; confirmar el texto con el contador). */
    public const LEGEND_TRAVEL_AGENCY = '2004';
    public const LEGEND_TRAVEL_AGENCY_TEXT = 'Agencia de Viaje – Paquete turístico';

    /** Catálogo 06: sin documento de identidad (boleta sin receptor); número «-» y razón social genérica. */
    public const ID_TYPE_NONE = '0';
    public const ANONYMOUS_NUMBER = '-';
    public const ANONYMOUS_NAME = 'CLIENTES VARIOS';

    /** Catálogo 52: leyenda de monto en letras. */
    public const LEGEND_AMOUNT_IN_WORDS = '1000';

    /** Catálogo 19: estado del ítem en el resumen diario. */
    public const SUMMARY_ADD = '1';
    public const SUMMARY_VOID = '3';

    /**
     * @return array<int|string, string> los códigos son cadenas numéricas; PHP convierte '10' en la clave entera 10
     */
    public static function reasonsFor(DocType $type): array
    {
        return $type === DocType::NotaDebito ? self::DEBIT_NOTE_REASONS : self::CREDIT_NOTE_REASONS;
    }
}
