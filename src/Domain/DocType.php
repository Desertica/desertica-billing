<?php

declare(strict_types=1);

namespace Desertica\Billing\Domain;

enum DocType: string
{
    case Boleta = 'BOLETA';
    case Factura = 'FACTURA';
    case NotaCredito = 'NOTA_CREDITO';
    case NotaDebito = 'NOTA_DEBITO';

    /** Catálogo 01 de SUNAT (ver docs/DECISIONS.md). */
    public function sunatCode(): string
    {
        return match ($this) {
            self::Factura => '01',
            self::Boleta => '03',
            self::NotaCredito => '07',
            self::NotaDebito => '08',
        };
    }

    public function isNote(): bool
    {
        return $this === self::NotaCredito || $this === self::NotaDebito;
    }
}
