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
        // Un cortafuegos de salida puede completar el TLS y luego responder 403: abrir el socket no basta, se pide el WSDL.
        $probe = @file_get_contents(
            SunatEndpoints::FE_BETA . '?wsdl',
            false,
            stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]),
        );
        $status = isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m) === 1 ? (int) $m[1] : 0;
        if ($probe === false || $status !== 200) {
            self::markTestSkipped(sprintf('e-beta.sunat.gob.pe no es alcanzable desde este entorno (HTTP %d).', $status));
        }

        $pem = CertificateProvider::generateTestCertificate();
        // Opcional: URL del servicio de consulta de CDR en beta (`billConsultService`); si no se define no se prueba la consulta.
        $consultUrl = (string) getenv('SUNAT_BETA_CDR_CONSULT_URL');
        $gateway = GreenterSunatGateway::connect($pem, '20000000001', $user, $password, SunatEndpoints::FE_BETA, $consultUrl);

        $request = (new RequestValidator())->validate(
            Payloads::boleta(['number' => random_int(1, 99999999)]),
            new \DateTimeImmutable('now', new \DateTimeZone('America/Lima')),
        );
        $built = (new DocumentFactory(new Issuer('20000000001', 'EMPRESA SAC', 'EMPRESA', '-', '150101', 'LIMA', 'LIMA', 'LIMA', '0000')))
            ->build($request);
        $signed = (new XmlSigner($pem))->sign($built['document']);

        $cdr = $gateway->sendDocument($signed->xml);

        self::assertNotSame('', $cdr->code);
        self::assertNotSame('', $cdr->zip);

        if ($consultUrl !== '') {
            // No se afirma qué responde SUNAT beta (no está verificado): solo que la consulta se completa y, si hay constancia, la lee.
            $found = $gateway->consultCdr('20000000001', '03', $request->series, $request->number);
            if ($found !== null) {
                self::assertNotSame('', $found->code);
            }
        }
    }
}
