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
    /**
     * @param int $connectionTimeout segundos para conectar
     * @param int $readTimeout segundos de espera de la respuesta, por llamada. Una emisión hace hasta dos (consulta de CDR + envío):
     *                         (conexión + lectura) × 2 debe quedar por debajo de `request_terminate_timeout` (docker/php-fpm.conf).
     */
    public function __construct(string $wsdl = '', ?string $caFile = null, int $connectionTimeout = 10, private readonly int $readTimeout = 30)
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

    /**
     * `SoapClient` no tiene opción de timeout de lectura: usa `default_socket_timeout`, que aquí se fija por llamada.
     */
    public function __doRequest(string $request, string $location, string $action, int $version, bool $oneWay = false): ?string
    {
        $previous = ini_set('default_socket_timeout', (string) $this->readTimeout);
        try {
            return parent::__doRequest($request, $location, $action, $version, $oneWay);
        } finally {
            ini_set('default_socket_timeout', $previous === false ? '60' : $previous);
        }
    }
}
