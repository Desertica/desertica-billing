<?php

declare(strict_types=1);

namespace Desertica\Billing\Config;

/**
 * Datos del emisor (la empresa). El contrato no los envía: pertenecen a este servicio,
 * igual que el certificado y las credenciales SOL.
 */
final class Issuer
{
    public function __construct(
        public readonly string $ruc,
        public readonly string $legalName,
        public readonly string $tradeName,
        public readonly string $address,
        public readonly string $ubigeo,
        public readonly string $department,
        public readonly string $province,
        public readonly string $district,
        public readonly string $localCode,
    ) {
    }
}
