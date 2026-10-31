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

    private const PRODUCTION_ISSUER = [
        'BILLING_ISSUER_RUC' => '20123456789',
        'BILLING_ISSUER_LEGAL_NAME' => 'EMPRESA REAL SAC',
        'BILLING_ISSUER_TRADE_NAME' => 'EMPRESA',
        'BILLING_ISSUER_ADDRESS' => 'AV. REAL 123',
        'BILLING_ISSUER_UBIGEO' => '150101',
        'BILLING_ISSUER_DEPARTMENT' => 'LIMA',
        'BILLING_ISSUER_PROVINCE' => 'LIMA',
        'BILLING_ISSUER_DISTRICT' => 'LIMA',
        'BILLING_ISSUER_LOCAL_CODE' => '0000',
    ];

    /**
     * @param array<string, string> $overrides
     */
    private function production(array $overrides = [], string ...$without): Settings
    {
        $env = $overrides + self::PRODUCTION_ISSUER + [
            'BILLING_ENV' => 'PRODUCTION', 'BILLING_SERVICE_TOKEN' => self::TOKEN, 'BILLING_GATEWAY' => 'greenter',
            'BILLING_CERT_PATH' => '/run/secrets/cert.pem', 'BILLING_SOL_USER' => 'U', 'BILLING_SOL_PASSWORD' => 'p',
        ];
        foreach ($without as $key) {
            unset($env[$key]);
        }

        return Settings::fromEnv($env);
    }

    public function testProductionAcceptsAFullyDeclaredIssuer(): void
    {
        self::assertSame('20123456789', $this->production()->issuer->ruc);
    }

    /**
     * @return iterable<string, array{array<string, string>, list<string>}>
     */
    public static function invalidProductionIssuers(): iterable
    {
        yield 'RUC de prueba' => [['BILLING_ISSUER_RUC' => '20000000001'], []];
        yield 'RUC mal formado' => [['BILLING_ISSUER_RUC' => '123'], []];
        yield 'razón social de ejemplo' => [['BILLING_ISSUER_LEGAL_NAME' => 'DESERTICA SAC'], []];
        yield 'dirección de ejemplo' => [['BILLING_ISSUER_ADDRESS' => '-'], []];
        foreach (array_keys(self::PRODUCTION_ISSUER) as $key) {
            yield 'falta ' . $key => [[], [$key]];
        }
    }

    /**
     * @param array<string, string> $overrides
     * @param list<string> $without
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidProductionIssuers')]
    public function testProductionRejectsMissingOrPlaceholderIssuer(array $overrides, array $without): void
    {
        $this->expectException(ConfigException::class);
        $this->production($overrides, ...$without);
    }

    public function testSecretsCanComeFromFiles(): void
    {
        $dir = sys_get_temp_dir() . '/billing-secrets-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/token', self::TOKEN . "\n");
        file_put_contents($dir . '/sol', 'sol-from-file');
        file_put_contents($dir . '/cert', 'cert-from-file');

        try {
            $settings = Settings::fromEnv([
                'BILLING_ENV' => 'BETA', 'BILLING_SERVICE_TOKEN_FILE' => $dir . '/token', 'BILLING_GATEWAY' => 'greenter',
                'BILLING_CERT_PATH' => '/run/secrets/cert.pem', 'BILLING_CERT_PASSWORD_FILE' => $dir . '/cert',
                'BILLING_SOL_USER' => 'U', 'BILLING_SOL_PASSWORD_FILE' => $dir . '/sol',
            ]);
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }

        self::assertSame(self::TOKEN, $settings->serviceToken->reveal());
        self::assertSame('sol-from-file', $settings->solPassword->reveal());
        self::assertSame('cert-from-file', $settings->certPassword->reveal());
    }

    public function testRejectsAnUnreadableSecretFile(): void
    {
        $this->expectException(ConfigException::class);
        Settings::fromEnv(['BILLING_ENV' => 'BETA', 'BILLING_SERVICE_TOKEN_FILE' => '/nonexistent/token']);
    }

    public function testRejectsASecretGivenBothWays(): void
    {
        $this->expectException(ConfigException::class);
        Settings::fromEnv(['BILLING_ENV' => 'BETA', 'BILLING_SERVICE_TOKEN' => self::TOKEN, 'BILLING_SERVICE_TOKEN_FILE' => __FILE__]);
    }

    public function testBetaKeepsTheTestIssuerDefaults(): void
    {
        self::assertSame('20000000001', $this->env()->issuer->ruc);
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
        ] + self::PRODUCTION_ISSUER);

        self::assertSame('', $this->env()->cdrConsultUrl);
        self::assertSame('https://e-factura.sunat.gob.pe/ol-it-wsconscpegem/billConsultService', $production->cdrConsultUrl);
        self::assertSame('https://x.example/consult', $this->env(['BILLING_CDR_CONSULT_URL' => 'https://x.example/consult'])->cdrConsultUrl);
        self::assertSame(['1033'], $this->env()->alreadyRegisteredCodes);
    }
}
