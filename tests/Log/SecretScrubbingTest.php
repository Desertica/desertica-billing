<?php

declare(strict_types=1);

namespace Desertica\Billing\Tests\Log;

use Desertica\Billing\Config\Settings;
use Desertica\Billing\Sunat\GreenterSunatGateway;
use Desertica\Billing\Sunat\SunatException;
use Greenter\Model\Response\BillResult;
use Greenter\Model\Response\Error;
use Greenter\See;
use PHPUnit\Framework\TestCase;

/**
 * Los secretos de la configuración no salen en logs ni en mensajes de error, ni siquiera si una librería los repite en su texto.
 */
final class SecretScrubbingTest extends TestCase
{
    private const SOL_PASSWORD = 'sol-clave-ultra-secreta';
    private const SOL_USER = 'USUARIOSOL';

    public function testLoggerScrubsConfiguredSecretsFromMessagesAndContext(): void
    {
        // StderrLogger escribe en php://stderr: se captura redirigiendo el descriptor con un proceso hijo.
        $script = sprintf(
            'require %s; (new Desertica\\Billing\\Log\\StderrLogger([%s, "abc"]))->error("fallo con %s", ["detail" => ["nested" => "usa %s ok abc"], "token" => "x"]);',
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            var_export(self::SOL_PASSWORD, true),
            self::SOL_PASSWORD,
            self::SOL_PASSWORD,
        );
        $output = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1');

        self::assertStringNotContainsString(self::SOL_PASSWORD, $output);
        self::assertStringContainsString('fallo con ***', $output);
        self::assertStringContainsString('usa *** ok abc', $output, 'Los valores de menos de 4 caracteres no se tachan.');
        self::assertStringContainsString('"token":"***"', $output);
    }

    public function testGatewayScrubsSecretsFromSunatAndTransportMessages(): void
    {
        $see = $this->createMock(See::class);
        $calls = 0;
        $see->method('sendXmlFile')->willReturnCallback(function () use (&$calls) {
            if (++$calls === 1) {
                return (new BillResult())->setSuccess(false)->setError(new Error('0111', 'Usuario ' . self::SOL_USER . ' clave ' . self::SOL_PASSWORD . ' inválida'));
            }

            return $this->throwsSoapFault();
        });
        $gateway = new GreenterSunatGateway($see, null, [self::SOL_USER, self::SOL_PASSWORD]);

        foreach ([0, 1] as $_) {
            try {
                $gateway->sendDocument('<xml/>');
                self::fail('Se esperaba SunatException.');
            } catch (SunatException $e) {
                self::assertStringNotContainsString(self::SOL_PASSWORD, $e->getMessage());
                self::assertStringNotContainsString(self::SOL_USER, $e->getMessage());
                self::assertStringContainsString('***', $e->getMessage());
            }
        }
    }

    private function throwsSoapFault(): never
    {
        throw new \SoapFault('HTTP', 'Fallo con credenciales ' . self::SOL_USER . ':' . self::SOL_PASSWORD);
    }

    public function testSettingsListsEverySecretValue(): void
    {
        $settings = Settings::fromEnv([
            'BILLING_ENV' => 'BETA',
            'BILLING_SERVICE_TOKEN' => 'test-token-0123456789-0123456789-abcdef',
            'BILLING_GATEWAY' => 'greenter',
            'BILLING_CERT_PATH' => '/run/secrets/cert.pem',
            'BILLING_CERT_PASSWORD' => 'cert-clave',
            'BILLING_SOL_USER' => self::SOL_USER,
            'BILLING_SOL_PASSWORD' => self::SOL_PASSWORD,
        ]);

        self::assertEqualsCanonicalizing(
            ['test-token-0123456789-0123456789-abcdef', 'cert-clave', self::SOL_PASSWORD, self::SOL_USER],
            $settings->secretValues(),
        );
    }
}
