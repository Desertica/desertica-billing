<?php

declare(strict_types=1);

namespace Desertica\Billing\Config;

use Greenter\Ws\Services\SunatEndpoints;

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
        public readonly BillingOptions $billing = new BillingOptions(),
        public readonly string $cdrConsultUrl = '',
        public readonly string $sunatCaFile = '',
        /** @var list<string> */
        public readonly array $alreadyRegisteredCodes = ['1033'],
    ) {
    }

    /**
     * Valores que no deben aparecer nunca en un log ni en un mensaje de error.
     *
     * @return list<string>
     */
    public function secretValues(): array
    {
        return array_values(array_filter([
            $this->serviceToken->reveal(),
            $this->certPassword->reveal(),
            $this->solPassword->reveal(),
            $this->solUser,
        ], static fn (string $v): bool => $v !== ''));
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

        $unitCode = $get('BILLING_UNIT_CODE', 'NIU');
        if (!preg_match('/^[A-Z0-9]{1,3}$/', $unitCode)) {
            throw new ConfigException('BILLING_UNIT_CODE debe ser un código de 1 a 3 caracteres A-Z o 0-9 (catálogo 03 de SUNAT).');
        }
        $legend = $get('BILLING_LEGEND_2004', 'false');
        if (!in_array($legend, ['true', 'false'], true)) {
            throw new ConfigException('BILLING_LEGEND_2004 debe ser true o false.');
        }
        $anonymousMax = $get('BILLING_ANONYMOUS_BOLETA_MAX_CENTS', '0');
        if (!preg_match('/^\d{1,9}$/', $anonymousMax)) {
            throw new ConfigException('BILLING_ANONYMOUS_BOLETA_MAX_CENTS debe ser un entero en céntimos (0 = receptor siempre obligatorio).');
        }

        // La consulta de CDR (getStatusCdr) vive en otro servicio de SUNAT; solo se activa en PRODUCTION por defecto.
        $cdrConsultUrl = $get('BILLING_CDR_CONSULT_URL', $environment === Environment::Production ? SunatEndpoints::FE_CONSULTA_CDR : '-');
        $cdrConsultUrl = $cdrConsultUrl === '-' ? '' : $cdrConsultUrl;
        if ($cdrConsultUrl !== '' && !str_starts_with($cdrConsultUrl, 'https://')) {
            throw new ConfigException('BILLING_CDR_CONSULT_URL debe empezar con https://.');
        }

        $registered = array_values(array_filter(array_map('trim', explode(',', $get('BILLING_ALREADY_REGISTERED_CODES', '1033')))));
        foreach ($registered as $code) {
            if (!preg_match('/^\d{4}$/', $code)) {
                throw new ConfigException('BILLING_ALREADY_REGISTERED_CODES debe ser una lista de códigos SUNAT de 4 dígitos separados por comas.');
            }
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
            new BillingOptions($unitCode, $legend === 'true', (int) $anonymousMax),
            $cdrConsultUrl,
            $get('BILLING_SUNAT_CA_FILE', ''),
            $registered,
        );
    }
}
