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
}
