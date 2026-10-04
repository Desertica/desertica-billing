<?php

declare(strict_types=1);

namespace Desertica\Billing\Xml;

/**
 * XML firmado más los datos que se extraen de él.
 */
final class SignedXml
{
    public function __construct(
        public readonly string $xml,
        public readonly string $fileName,
        public readonly string $hash,
    ) {
    }
}
