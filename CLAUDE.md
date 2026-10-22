# Desertica billing

Servicio interno de facturación electrónica de Desertica (SUNAT, Perú): emite boletas, facturas y notas de crédito con **Greenter**, firma el XML, lo envía a SUNAT y devuelve el CDR y el PDF. Solo lo llama `Desertica/desertica-api`; no tiene puerto público.

El contrato que implementa es `openapi/billing.yaml` en `Desertica/desertica-api`; `openapi/billing.yaml` de este repo es una copia contra la que se validan las pruebas (ver `openapi/README.md`). Decisiones y fuentes: `docs/DECISIONS.md`; los pendientes están en `docs/PENDIENTES.md` del repo del API.

## Stack

PHP 8.3, Composer, `greenter/*`, Slim 4, PDO (SQLite por defecto). Contenedor propio (nginx + php-fpm, no-root), red interna, autenticación con token de servicio (`Authorization: Bearer`).

## Rules

- **El contrato manda.** Implementa `openapi/billing.yaml`; un cambio de contrato se hace primero por PR en el repo del API.
- **Idempotencia por `externalId`**: repetir una emisión devuelve el resultado guardado; la misma `externalId` con otro contenido es `409`.
- **El API es dueño de series y correlativos.** Este servicio recibe `series` y `number` y no lleva contadores.
- **Montos**: enteros en la unidad menor con IGV incluido. Calcula base imponible e IGV con `igvRate` y redondea a céntimos de forma determinista; hay pruebas para eso. En USD exige el tipo de cambio SUNAT.
- **Secretos**: el certificado digital (.pfx), su clave y las credenciales SOL **nunca se commitean ni se loguean**. Se leen de variables de entorno o de secretos montados. En desarrollo y en sesiones cloud solo existen un certificado de prueba y el entorno **SUNAT beta**; el certificado y la clave SOL reales no salen del servidor de producción.
- El entorno (`BETA` o `PRODUCTION`) es configuración explícita y visible en `/health`; nunca se infiere.
- Los archivos (XML firmado, CDR, PDF) se guardan en `BILLING_STORAGE_DIR/<externalId>/` y el API los descarga con `GET /v1/documents/{externalId}/files/{xml|cdr|pdf}` y los conserva con retención legal.
- Verifica los códigos de catálogo SUNAT (motivo de nota de crédito, afectación del IGV) y si las boletas van una a una o por resumen diario con la documentación de Greenter y de SUNAT antes de implementarlos; no los escribas de memoria.
- Nunca commitees `.env`.

## Commands

```bash
composer install && cp .env.example .env   # una vez; define BILLING_SERVICE_TOKEN
composer lint                              # phpcs (PSR-12)
composer stan                              # phpstan nivel 8
composer test                              # phpunit, sin red
composer check                             # lint + stan + test
php -S 127.0.0.1:8080 -t public public/index.php   # con las variables de .env exportadas
```

Requiere PHP 8.3 con `soap`, `zip`, `pdo_sqlite` y el binario `wkhtmltopdf` para el PDF. Las pruebas sin red generan y firman el XML con un
certificado de prueba (creado en tiempo de ejecución con `openssl`) y las validan contra el XSD. Pruebas con red: `tests/SunatBeta`, requieren
permitir `e-beta.sunat.gob.pe` en el entorno y se saltan solas si no es alcanzable. Decisiones y fuentes: `docs/DECISIONS.md`.

## Pitfalls

- En sesiones cloud, Composer no puede bajar `phpstan/phpstan` (solo publica zip en api.github.com, bloqueado): usa el phar de
  `raw.githubusercontent.com/phpstan/phpstan/<commit>/phpstan.phar` en `tools/` (ignorado por git) y enlázalo en `vendor/bin/phpstan`.
- En sesiones cloud Composer no baja **ningún** zip de `api.github.com` (no solo phpstan): usa `composer install --prefer-source` (clona por git) y, para phpstan, el phar de arriba.
  Si falta `ext-soap`: `apt-get update && apt-get install -y php8.3-soap`.
- Los hosts de SUNAT (`e-beta`, `e-factura`, `www`) siguen bloqueados en sesiones cloud: el egress responde 403 *después* de completar el TLS, así que `fsockopen` engaña; las pruebas
  `tests/SunatBeta` piden el WSDL y se saltan solas.

## Ramas

`main` es solo la base; el trabajo entra por PR a `develop`, `develop` -> `staging` (pruebas) y `staging` -> `prod` (producción). Rama de trabajo: `feat/*`, `fix/*` o `chore/*`, se borra al mezclar. Los checks y la protección están en `docs/PROTECCION-RAMAS.md`; los workflows son `.github/workflows/{ci,security,policy}.yml` y los editas con un PR normal.
