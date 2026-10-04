<?php

declare(strict_types=1);

namespace Desertica\Billing\Sunat;

use Greenter\Ws\Services\SoapClient;

/**
 * Cliente SOAP de Greenter con verificación TLS activada.
 *
 * El `SoapClient` por defecto de Greenter se crea con `verify_peer` y `verify_peer_name` en false: por ese canal viaja la clave SOL,
 * así que aquí se exige el certificado del servidor (CA del sistema, o `$caFile` si SUNAT exige una cadena propia).
 */
final class VerifiedSoapClient extends SoapClient
{
    public function __construct(string $wsdl = '', ?string $caFile = null, int $connectionTimeout = 10)
    {
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false];
        if ($caFile !== null && $caFile !== '') {
            $ssl['cafile'] = $caFile;
        }

        parent::__construct($wsdl, [
            'stream_context' => stream_context_create(['ssl' => $ssl]),
            'connection_timeout' => $connectionTimeout,
            'exceptions' => true,
        ]);
    }
}
