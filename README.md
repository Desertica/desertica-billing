# desertica-billing

Servicio interno de facturación electrónica de Desertica (SUNAT, Perú). Emite boletas, facturas y notas de crédito y débito con
[Greenter](https://github.com/thegreenter), firma el XML, lo envía a SUNAT y guarda el XML, el CDR y el PDF en `BILLING_STORAGE_DIR` (almacenamiento de trabajo: la conservación con retención legal es del API, que los descarga). Solo lo llama `desertica-api`; no tiene
puerto público. El contrato es `openapi/billing.yaml` del repo `Desertica/desertica-api`.

Decisiones y sus fuentes: [`docs/DECISIONS.md`](docs/DECISIONS.md).

## Requisitos

PHP 8.3 con `soap`, `zip`, `openssl`, `dom`, `pdo_sqlite`; Composer 2; `wkhtmltopdf` (para el PDF); `openssl` en el PATH (para el certificado de prueba).

## Puesta en marcha

```bash
composer install
cp .env.example .env            # luego define BILLING_SERVICE_TOKEN; BILLING_ENV ya viene en BETA
set -a; . ./.env; set +a
php -S 127.0.0.1:8080 -t public public/index.php     # solo desarrollo local
curl http://127.0.0.1:8080/health
```

Con `BILLING_GATEWAY=fake` (por defecto) no hay red ni credenciales: SUNAT se simula y **acepta todo** (también en `BILLING_ENV=BETA`); `PRODUCTION` solo arranca con gateway `greenter`.

### Contenedor (producción)

`docker compose up --build` levanta nginx + php-fpm como usuario no-root (uid 10001), con sistema de archivos de solo lectura, healthcheck sobre `/health` y
logs JSON a stderr. La imagen escucha en `8080` y no se publica fuera de la red interna. Detalles y justificación: `docs/DECISIONS.md`, «Servidor de producción».
Los secretos (certificado, claves SOL, token) se montan como archivos (`BILLING_SERVICE_TOKEN_FILE`, `BILLING_CERT_PASSWORD_FILE`, `BILLING_SOL_PASSWORD_FILE`) o variables de entorno; nunca van en la imagen.
Al arrancar, `bin/startup.php` valida la configuración y el certificado (vigencia, clave, RUC) y prepara la base: si algo falla, el contenedor no arranca. En `PRODUCTION` hay que declarar todas las `BILLING_ISSUER_*` con los datos reales.

## Endpoints

Contrato: `openapi/billing.yaml` del repo del API. Todos salvo `/health` exigen `Authorization: Bearer <BILLING_SERVICE_TOKEN>`.

| Método y ruta | Qué hace |
|---|---|
| `GET /health` | Vivo y entorno (`BETA`/`PRODUCTION`) |
| `POST /v1/documents` | Emite boleta, factura, nota de crédito o de débito (idempotente por `externalId`) |
| `GET /v1/documents/{externalId}` | Estado del comprobante |
| `GET /v1/documents/{externalId}/files/{xml\|cdr\|pdf}` | Descarga de archivos |
| `POST /v1/documents/{externalId}/void` | Baja (comunicación de baja, o resumen con estado anulado para boletas) |
| `POST /v1/summaries/daily` | Resumen diario de boletas por fecha (una moneda por llamada; `currency` opcional) |
| `GET /v1/tickets/{ticket}` | Estado de un ticket de SUNAT |

## Pruebas y calidad

```bash
composer lint        # phpcs, PSR-12
composer stan        # phpstan nivel 8
composer test        # phpunit (sin red)
composer check       # todo lo anterior
```

Las pruebas con red contra SUNAT beta están en `tests/SunatBeta` y se saltan solas si el host no es alcanzable (se pide el WSDL; no basta abrir el socket).
Necesitan `SUNAT_BETA_SOL_USER` y `SUNAT_BETA_SOL_PASSWORD` (los valores públicos de pruebas están en la guía de Greenter) y, opcional, `SUNAT_BETA_CDR_CONSULT_URL`.

Cada respuesta de las pruebas HTTP se valida contra `openapi/billing.yaml` (copia del contrato del API, ver `openapi/README.md`): si el servicio y el contrato divergen, falla la suite.

## Seguridad

El certificado (.pfx/.pem), su clave y las credenciales SOL **nunca** se commitean ni se loguean: se leen de variables de entorno o de
archivos montados. En desarrollo se genera un certificado de prueba en tiempo de ejecución con `openssl` y solo se usa SUNAT beta.
Ver `.env.example` para la lista de variables.
