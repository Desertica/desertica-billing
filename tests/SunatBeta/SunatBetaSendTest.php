<?php

declare(strict_types=1);

namespace Desertica\Billing\Tests\SunatBeta;

use Desertica\Billing\Config\Issuer;
use Desertica\Billing\Domain\RequestValidator;
use Desertica\Billing\Security\CertificateProvider;
use Desertica\Billing\Sunat\GreenterSunatGateway;
use Desertica\Billing\Tests\Support\Payloads;
use Desertica\Billing\Xml\DocumentFactory;
use Desertica\Billing\Xml\XmlSigner;
use Greenter\See;
use Greenter\Ws\Services\SunatEndpoints;
use PHPUnit\Framework\TestCase;

/**
 * Envío real a SUNAT beta. Se salta solo si no hay credenciales en el entorno o si el host no es alcanzable
 * (en sesiones cloud hay que permitir `e-beta.sunat.gob.pe` en la política de red).
 *
 * Variables: SUNAT_BETA_SOL_USER y SUNAT_BETA_SOL_PASSWORD. Los valores públicos de pruebas de SUNAT beta para el RUC
 * 20000000001 están en la guía de inicio de Greenter; no se commitean aquí.
 */
final class SunatBetaSendTest extends TestCase
{
    public function testSendsABoletaToSunatBeta(): void
    {
        $user = getenv('SUNAT_BETA_SOL_USER');
        $password = getenv('SUNAT_BETA_SOL_PASSWORD');
        if ($user === false || $user === '' || $password === false || $password === '') {
            self::markTestSkipped('Define SUNAT_BETA_SOL_USER y SUNAT_BETA_SOL_PASSWORD para probar contra SUNAT beta.');
        }
        $socket = @fsockopen('ssl://e-beta.sunat.gob.pe', 443, $errno, $error, 5);
        if ($socket === false) {
            self::markTestSkipped('e-beta.sunat.gob.pe no es alcanzable desde este entorno: ' . $error);
        }
        fclose($socket);

        $pem = CertificateProvider::generateTestCertificate();
        $see = new See();
        $see->setCertificate($pem);
        $see->setService(SunatEndpoints::FE_BETA);
        $see->setClaveSOL('20000000001', $user, $password);

        $request = (new RequestValidator())->validate(
            Payloads::boleta(['number' => random_int(1, 99999999)]),
            new \DateTimeImmutable('now', new \DateTimeZone('America/Lima')),
        );
        $built = (new DocumentFactory(new Issuer('20000000001', 'EMPRESA SAC', 'EMPRESA', '-', '150101', 'LIMA', 'LIMA', 'LIMA', '0000')))
            ->build($request);
        $signed = (new XmlSigner($pem))->sign($built['document']);

        $cdr = (new GreenterSunatGateway($see))->sendDocument($signed->xml);

        self::assertNotSame('', $cdr->code);
        self::assertNotSame('', $cdr->zip);
    }
}
