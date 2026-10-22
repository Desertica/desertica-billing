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
     * Falla con un mensaje claro si el certificado no sirve para firmar: vencido, sin clave, clave que no corresponde
     * o, cuando se puede extraer, RUC distinto del emisor.
     *
     * @param string $path .pem, .pfx o .p12 montado como secreto
     * @param string|null $expectedRuc RUC del emisor; si el certificado declara uno, debe coincidir
     */
    public static function fromFile(string $path, Secret $password, ?string $expectedRuc = null, ?int $now = null): string
    {
        $pem = self::readPem($path, $password);
        self::assertUsable($pem, $expectedRuc, $now ?? time());

        return $pem;
    }

    private static function readPem(string $path, Secret $password): string
    {
        $content = is_file($path) ? file_get_contents($path) : false;
        if ($content === false) {
            throw new \RuntimeException('No se pudo leer el certificado digital configurado.');
        }
        if (str_contains($content, '-----BEGIN')) {
            return $content;
        }

        // PKCS#12 (.pfx / .p12)
        try {
            $pem = (new X509Certificate($content, $password->reveal()))->export(X509ContentType::PEM);
        } catch (\Exception) {
            throw new \RuntimeException('No se pudo abrir el certificado digital PKCS#12 (¿clave incorrecta?).');
        }
        if ($pem === null || $pem === '') {
            throw new \RuntimeException('El certificado digital no se pudo convertir a PEM (¿clave incorrecta?).');
        }

        return $pem;
    }

    private static function assertUsable(string $pem, ?string $expectedRuc, int $now): void
    {
        $cert = openssl_x509_read($pem);
        if ($cert === false) {
            throw new \RuntimeException('El archivo configurado no contiene un certificado X.509.');
        }
        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new \RuntimeException('El archivo configurado no contiene una clave privada legible (¿protegida con otra clave?).');
        }
        if (!openssl_x509_check_private_key($cert, $key)) {
            throw new \RuntimeException('La clave privada no corresponde al certificado digital.');
        }
        $info = openssl_x509_parse($cert);
        if ($info === false) {
            throw new \RuntimeException('No se pudo leer el certificado digital.');
        }
        if ($now < $info['validFrom_time_t']) {
            throw new \RuntimeException('El certificado digital aún no es válido.');
        }
        if ($now > $info['validTo_time_t']) {
            throw new \RuntimeException('El certificado digital está vencido (' . date('Y-m-d', $info['validTo_time_t']) . ').');
        }
        if ($expectedRuc !== null) {
            $ruc = self::rucOf($info['subject']);
            if ($ruc !== null && $ruc !== $expectedRuc) {
                throw new \RuntimeException('El RUC del certificado digital no coincide con BILLING_ISSUER_RUC.');
            }
        }
    }

    /**
     * RUC declarado en el sujeto del certificado («RUC 20…» o un serialNumber de 11 dígitos); `null` si no se declara.
     *
     * @param array<array-key, mixed> $subject
     */
    private static function rucOf(array $subject): ?string
    {
        $values = [];
        array_walk_recursive($subject, static function (mixed $v) use (&$values): void {
            $values[] = (string) $v;
        });
        foreach ($values as $value) {
            if (preg_match('/RUC\D{0,3}(\d{11})\b/i', $value, $m) === 1 || preg_match('/^(\d{11})$/', $value, $m) === 1) {
                return $m[1];
            }
        }

        return null;
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
