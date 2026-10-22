<?php

declare(strict_types=1);

namespace Desertica\Billing\Tests\Unit;

use Desertica\Billing\Config\ConfigException;
use Desertica\Billing\Config\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    private const TOKEN = 'test-token-0123456789-0123456789-abcdef';

    public function testEnvironmentIsNeverInferred(): void
    {
        $this->expectException(ConfigException::class);
        Settings::fromEnv(['BILLING_SERVICE_TOKEN' => self::TOKEN]);
    }

    public function testRejectsUnknownEnvironment(): void
    {
        $this->expectException(ConfigException::class);
        Settings::fromEnv(['BILLING_ENV' => 'staging', 'BILLING_SERVICE_TOKEN' => self::TOKEN]);
    }

    public function testRejectsShortToken(): void
    {
        $this->expectException(ConfigException::class);
        Settings::fromEnv(['BILLING_ENV' => 'BETA', 'BILLING_SERVICE_TOKEN' => 'short']);
    }

    public function testProductionRequiresRealGateway(): void
    {
        $this->expectException(ConfigException::class);
        Settings::fromEnv(['BILLING_ENV' => 'PRODUCTION', 'BILLING_SERVICE_TOKEN' => self::TOKEN, 'BILLING_GATEWAY' => 'fake']);
    }

    public function testGreenterGatewayRequiresCertificateAndSol(): void
    {
        $this->expectException(ConfigException::class);
        Settings::fromEnv(['BILLING_ENV' => 'BETA', 'BILLING_SERVICE_TOKEN' => self::TOKEN, 'BILLING_GATEWAY' => 'greenter']);
    }

    public function testSecretsAreRedacted(): void
    {
        $settings = Settings::fromEnv([
            'BILLING_ENV' => 'BETA',
            'BILLING_SERVICE_TOKEN' => self::TOKEN,
            'BILLING_GATEWAY' => 'greenter',
            'BILLING_CERT_PATH' => '/run/secrets/cert.pem',
            'BILLING_CERT_PASSWORD' => 'cert-password-xyz',
            'BILLING_SOL_USER' => 'MODDATOS',
            'BILLING_SOL_PASSWORD' => 'sol-password-xyz',
        ]);

        $dump = print_r($settings, true) . var_export($settings, true) . serialize($settings);

        self::assertStringNotContainsString(self::TOKEN, $dump);
        self::assertStringNotContainsString('cert-password-xyz', $dump);
        self::assertStringNotContainsString('sol-password-xyz', $dump);
    }

    /**
     * @param array<string, string> $extra
     */
    private function env(array $extra = []): Settings
    {
        return Settings::fromEnv($extra + ['BILLING_ENV' => 'BETA', 'BILLING_SERVICE_TOKEN' => self::TOKEN]);
    }

    public function testAccountantDecisionsDefaultToTheSafestValues(): void
    {
        $billing = $this->env()->billing;

        self::assertSame('NIU', $billing->unitCode);
        self::assertFalse($billing->travelAgencyLegend);
        self::assertSame(0, $billing->anonymousBoletaMaxCents);
    }

    public function testAccountantDecisionsAreReadFromTheEnvironment(): void
    {
        $billing = $this->env(['BILLING_UNIT_CODE' => 'ZZ', 'BILLING_LEGEND_2004' => 'true', 'BILLING_ANONYMOUS_BOLETA_MAX_CENTS' => '70000'])->billing;

        self::assertSame('ZZ', $billing->unitCode);
        self::assertTrue($billing->travelAgencyLegend);
        self::assertSame(70000, $billing->anonymousBoletaMaxCents);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function invalidDecisions(): iterable
    {
        yield 'unidad en minúsculas' => [['BILLING_UNIT_CODE' => 'niu']];
        yield 'unidad demasiado larga' => [['BILLING_UNIT_CODE' => 'NIUU']];
        yield 'interruptor ambiguo' => [['BILLING_LEGEND_2004' => 'yes']];
        yield 'tope negativo' => [['BILLING_ANONYMOUS_BOLETA_MAX_CENTS' => '-1']];
        yield 'tope decimal' => [['BILLING_ANONYMOUS_BOLETA_MAX_CENTS' => '700.00']];
        yield 'consulta de CDR sin TLS' => [['BILLING_CDR_CONSULT_URL' => 'http://e-factura.sunat.gob.pe/x']];
        yield 'código de «ya registrado» inválido' => [['BILLING_ALREADY_REGISTERED_CODES' => '10x3']];
    }

    /**
     * @param array<string, string> $env
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidDecisions')]
    public function testRejectsInvalidDecisionValues(array $env): void
    {
        $this->expectException(ConfigException::class);
        $this->env($env);
    }

    public function testCdrConsultIsOnlyEnabledByDefaultInProduction(): void
    {
        $production = Settings::fromEnv([
            'BILLING_ENV' => 'PRODUCTION', 'BILLING_SERVICE_TOKEN' => self::TOKEN, 'BILLING_GATEWAY' => 'greenter',
            'BILLING_CERT_PATH' => '/run/secrets/cert.pem', 'BILLING_SOL_USER' => 'U', 'BILLING_SOL_PASSWORD' => 'p',
        ]);

        self::assertSame('', $this->env()->cdrConsultUrl);
        self::assertSame('https://e-factura.sunat.gob.pe/ol-it-wsconscpegem/billConsultService', $production->cdrConsultUrl);
        self::assertSame('https://x.example/consult', $this->env(['BILLING_CDR_CONSULT_URL' => 'https://x.example/consult'])->cdrConsultUrl);
        self::assertSame(['1033'], $this->env()->alreadyRegisteredCodes);
    }
}
