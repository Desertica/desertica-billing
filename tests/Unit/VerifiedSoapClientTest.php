<?php

declare(strict_types=1);

namespace Desertica\Billing\Tests\Unit;

use Desertica\Billing\Security\CertificateProvider;
use Desertica\Billing\Sunat\VerifiedSoapClient;
use Greenter\Ws\Services\SoapClient;
use Greenter\Ws\Services\WsdlProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contra un servidor TLS local con certificado autofirmado: el cliente por defecto de Greenter se conecta (no verifica);
 * el nuestro rechaza el certificado antes de enviar nada (la clave SOL viaja en la cabecera WS-Security).
 */
final class VerifiedSoapClientTest extends TestCase
{
    /** @var resource|null */
    private $server = null;
    private string $pem = '';

    protected function setUp(): void
    {
        $openssl = trim((string) shell_exec('command -v openssl'));
        if ($openssl === '') {
            self::markTestSkipped('openssl no está disponible.');
        }
        ini_set('default_socket_timeout', '3');
        $this->pem = tempnam(sys_get_temp_dir(), 'tls') ?: '';
        file_put_contents($this->pem, CertificateProvider::generateTestCertificate('127.0.0.1'));
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        if ($this->pem !== '') {
            @unlink($this->pem);
        }
    }

    private function startServer(): int
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($probe);
        $port = (int) parse_url('tcp://' . stream_socket_get_name($probe, false), PHP_URL_PORT);
        fclose($probe);

        $this->server = proc_open(
            ['openssl', 's_server', '-accept', (string) $port, '-cert', $this->pem, '-key', $this->pem, '-www', '-quiet'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return $port;
            }
            usleep(100000);
        }
        self::markTestSkipped('No arrancó el servidor TLS de prueba.');
    }

    private function faultOf(SoapClient $client, int $port): string
    {
        $client->setService('https://127.0.0.1:' . $port . '/billConsultService');
        // PHP avisa del fallo de conexión con un warning además de la SoapFault: aquí es lo esperado.
        set_error_handler(static fn (): bool => true);
        try {
            $client->call('getStatus', ['parameters' => ['rucComprobante' => '20000000001', 'tipoComprobante' => '01', 'serieComprobante' => 'F001', 'numeroComprobante' => 1]]);
        } catch (\SoapFault $e) {
            return $e->getMessage();
        } finally {
            restore_error_handler();
        }

        return '';
    }

    public function testRejectsAnUntrustedCertificateWhileGreenterDefaultAcceptsIt(): void
    {
        $port = $this->startServer();

        $default = $this->faultOf(new SoapClient(WsdlProvider::getConsultPath()), $port);
        $verified = $this->faultOf(new VerifiedSoapClient(WsdlProvider::getConsultPath()), $port);

        self::assertStringNotContainsString('Could not connect to host', $default, 'El cliente de Greenter sí llegó a conectar.');
        self::assertStringContainsString('Could not connect to host', $verified);
    }

    public function testTrustsTheServerWhenItsCertificateIsInTheConfiguredCaFile(): void
    {
        $port = $this->startServer();
        // Mismo certificado como CA: la conexión TLS se establece (el fallo, si lo hay, ya no es de conexión).
        $client = new VerifiedSoapClient(WsdlProvider::getConsultPath(), $this->pem);

        self::assertStringNotContainsString('Could not connect to host', $this->faultOf($client, $port));
    }
}
