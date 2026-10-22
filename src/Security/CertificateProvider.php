<?php

declare(strict_types=1);

namespace Desertica\Billing\Security;

use Desertica\Billing\Config\Secret;
use Greenter\XMLSecLibs\Certificate\X509Certificate;
use Greenter\XMLSecLibs\Certificate\X509ContentType;

/**
 * Entrega el certificado en PEM (clave privada + certificado), que es lo que Greenter necesita para firmar.
 * El certificado y su clave nunca se loguean ni se copian a otro sitio.
 */
final class CertificateProvider
{
    /**
     * @param string $path .pem, .pfx o .p12 montado como secreto
     */
    public static function fromFile(string $path, Secret $password): string
    {
        $content = is_file($path) ? file_get_contents($path) : false;
        if ($content === false) {
            throw new \RuntimeException('No se pudo leer el certificado digital configurado.');
        }
        if (str_contains($content, '-----BEGIN')) {
            return $content;
        }

        // PKCS#12 (.pfx / .p12)
        $pem = (new X509Certificate($content, $password->reveal()))->export(X509ContentType::PEM);
        if ($pem === null || $pem === '') {
            throw new \RuntimeException('El certificado digital no se pudo convertir a PEM (¿clave incorrecta?).');
        }

        return $pem;
    }

    /**
     * Certificado autofirmado de prueba, generado en tiempo de ejecución con OpenSSL. SUNAT beta no verifica la cadena
     * del certificado, pero este nunca debe usarse con producción.
     */
    public static function generateTestCertificate(string $commonName = 'DESERTICA PRUEBA'): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new \RuntimeException('OpenSSL no pudo generar la clave de prueba.');
        }
        $csr = openssl_csr_new(
            ['commonName' => $commonName, 'organizationName' => $commonName, 'countryName' => 'PE'],
            $key,
            ['digest_alg' => 'sha256'],
        );
        if ($csr === false || $csr === true) {
            throw new \RuntimeException('OpenSSL no pudo generar la solicitud del certificado de prueba.');
        }
        $x509 = openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256']);
        if ($x509 === false) {
            throw new \RuntimeException('OpenSSL no pudo firmar el certificado de prueba.');
        }
        $privateKey = '';
        $certificate = '';
        openssl_pkey_export($key, $privateKey);
        openssl_x509_export($x509, $certificate);

        return $privateKey . $certificate;
    }
}
