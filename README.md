# desertica-billing

Servicio interno de facturación electrónica de Desertica (SUNAT, Perú). Emite boletas, facturas y notas de crédito y débito con
[Greenter](https://github.com/thegreenter), firma el XML, lo envía a SUNAT y guarda el CDR y el PDF. Solo lo llama `desertica-api`; no tiene
puerto público. El contrato es `openapi/billing.yaml` del repo `Desertica/desertica-api`.

Decisiones y sus fuentes: [`docs/DECISIONS.md`](docs/DECISIONS.md).

## Requisitos

PHP 8.3 con `soap`, `zip`, `openssl`, `dom`, `pdo_sqlite`; Composer 2; `wkhtmltopdf` (para el PDF); `openssl` en el PATH (para el certificado de prueba).

## Puesta en marcha

```bash
composer install
cp .env.example .env            # luego define BILLING_SERVICE_TOKEN
set -a; . ./.env; set +a
php -S 127.0.0.1:8080 -t public public/index.php
curl http://127.0.0.1:8080/health
```

Con `BILLING_GATEWAY=fake` (por defecto) no hay red ni credenciales: SUNAT se simula. Con Docker: `docker compose up --build`.

## Pruebas y calidad

```bash
composer lint        # phpcs, PSR-12
composer stan        # phpstan nivel 8
composer test        # phpunit (sin red)
composer check       # todo lo anterior
```

Las pruebas con red contra SUNAT beta están en `tests/SunatBeta` y se saltan solas si el host no es alcanzable.

## Seguridad

El certificado (.pfx/.pem), su clave y las credenciales SOL **nunca** se commitean ni se loguean: se leen de variables de entorno o de
archivos montados. En desarrollo se genera un certificado de prueba en tiempo de ejecución con `openssl` y solo se usa SUNAT beta.
Ver `.env.example` para la lista de variables.
