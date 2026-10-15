<?php

declare(strict_types=1);

namespace Desertica\Billing\Config;

/**
 * Decisiones tributarias que corresponden al contador, expuestas como configuración con valores por defecto seguros
 * (los más restrictivos). Ver `docs/DECISIONS.md`, sección «Decisiones del contador».
 */
final class BillingOptions
{
    /**
     * @param string $unitCode código de unidad de medida (catálogo 03) de todas las líneas
     * @param bool $travelAgencyLegend añade la leyenda 2004 «Agencia de Viaje – Paquete turístico» a boletas y facturas
     * @param int $anonymousBoletaMaxCents las boletas en PEN por este monto (céntimos, IGV incluido) o menos pueden ir sin receptor; 0 = el receptor siempre es obligatorio
     */
    public function __construct(
        public readonly string $unitCode = 'NIU',
        public readonly bool $travelAgencyLegend = false,
        public readonly int $anonymousBoletaMaxCents = 0,
    ) {
    }
}
