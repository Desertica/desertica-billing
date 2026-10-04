<?php

declare(strict_types=1);

namespace Desertica\Billing\Config;

/**
 * Configuración del servicio. Todo viene de variables de entorno; nada se infiere.
 */
final class Settings
{
    public function __construct(
        public readonly Environment $environment,
        public readonly Secret $serviceToken,
        public readonly string $gateway,
        public readonly string $dbDsn,
        public readonly string $storageDir,
        public readonly string $boletaSubmission,
        public readonly string $certPath,
        public readonly Secret $certPassword,
        public readonly string $solUser,
        public readonly Secret $solPassword,
        public readonly Issuer $issuer,
        public readonly string $wkhtmltopdfBinary,
        public readonly string $logoPath,
    ) {
    }

    /**
     * @param array<string, mixed> $env
     */
    public static function fromEnv(array $env): self
    {
        $get = static function (string $key, ?string $default = null) use ($env): string {
            $value = $env[$key] ?? null;
            if (!is_string($value) || $value === '') {
                if ($default === null) {
                    throw new ConfigException(sprintf('Falta la variable de entorno %s.', $key));
                }

                return $default;
            }

            return $value;
        };

        $envName = $get('BILLING_ENV');
        $environment = Environment::tryFrom($envName)
            ?? throw new ConfigException('BILLING_ENV debe ser BETA o PRODUCTION.');

        $token = $get('BILLING_SERVICE_TOKEN');
        if (strlen($token) < 32) {
            throw new ConfigException('BILLING_SERVICE_TOKEN debe tener al menos 32 caracteres.');
        }

        $gateway = $get('BILLING_GATEWAY', 'fake');
        if (!in_array($gateway, ['fake', 'greenter'], true)) {
            throw new ConfigException('BILLING_GATEWAY debe ser fake o greenter.');
        }
        if ($environment === Environment::Production && $gateway !== 'greenter') {
            throw new ConfigException('En PRODUCTION BILLING_GATEWAY debe ser greenter.');
        }

        $boletaSubmission = $get('BILLING_BOLETA_SUBMISSION', 'IMMEDIATE');
        if (!in_array($boletaSubmission, ['IMMEDIATE', 'DAILY_SUMMARY'], true)) {
            throw new ConfigException('BILLING_BOLETA_SUBMISSION debe ser IMMEDIATE o DAILY_SUMMARY.');
        }

        $certPath = $get('BILLING_CERT_PATH', '');
        $solUser = $get('BILLING_SOL_USER', '');
        $solPassword = $get('BILLING_SOL_PASSWORD', '');
        if ($gateway === 'greenter' && ($certPath === '' || $solUser === '' || $solPassword === '')) {
            throw new ConfigException(
                'Con BILLING_GATEWAY=greenter hacen falta BILLING_CERT_PATH, BILLING_SOL_USER y BILLING_SOL_PASSWORD.'
            );
        }

        return new self(
            $environment,
            new Secret($token),
            $gateway,
            $get('BILLING_DB_DSN', 'sqlite:' . $get('BILLING_STORAGE_DIR', 'var/storage') . '/billing.sqlite'),
            $get('BILLING_STORAGE_DIR', 'var/storage'),
            $boletaSubmission,
            $certPath,
            new Secret($get('BILLING_CERT_PASSWORD', '')),
            $solUser,
            new Secret($solPassword),
            new Issuer(
                $get('BILLING_ISSUER_RUC', '20000000001'),
                $get('BILLING_ISSUER_LEGAL_NAME', 'DESERTICA SAC'),
                $get('BILLING_ISSUER_TRADE_NAME', 'DESERTICA'),
                $get('BILLING_ISSUER_ADDRESS', '-'),
                $get('BILLING_ISSUER_UBIGEO', '150101'),
                $get('BILLING_ISSUER_DEPARTMENT', 'LIMA'),
                $get('BILLING_ISSUER_PROVINCE', 'LIMA'),
                $get('BILLING_ISSUER_DISTRICT', 'LIMA'),
                $get('BILLING_ISSUER_LOCAL_CODE', '0000'),
            ),
            $get('BILLING_WKHTMLTOPDF', 'wkhtmltopdf'),
            $get('BILLING_LOGO_PATH', ''),
        );
    }
}
