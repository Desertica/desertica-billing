# Decisiones

Cada decisión indica su fuente. **Limitación de la sesión cloud donde se escribió esto:** los hosts de SUNAT
(`www.sunat.gob.pe`, `cpe.sunat.gob.pe`, `e-beta.sunat.gob.pe`) y `greenter.dev` están bloqueados por la política de red del entorno.
La documentación de Greenter se leyó de su repositorio de docs (`thegreenter/docs`, vía raw.githubusercontent.com) y el código instalado en
`vendor/greenter`. Los catálogos de SUNAT **no** se pudieron leer del documento oficial: se contrastaron con una extracción de terceros
del Anexo N.° 8 (ver cada punto). **Antes de producción, el contador o quien tramite la afiliación debe confirmar los códigos contra el
Anexo N.° 8 vigente** (`docs/PENDIENTES.md` del repo del API ya lo tiene como pendiente del contador).

## (a) Boletas: una a una o resumen diario

**Decisión:** ambas modalidades están soportadas; el valor por defecto es **envío inmediato (`IMMEDIATE`)**, configurable con
`BILLING_BOLETA_SUBMISSION` y por petición con `submission`.

- Fuente: FAQ de Greenter («Enviar Boleta o Resumen diario»): *«actualmente ambas opciones son permitidas, puedes seguir enviando los
  resumen diario o enviar boletas individualmente como se hace con las facturas»*, citando la Resolución N.° 114-2019/SUNAT, art. 12 (plazo
  máximo de cinco días calendario para enviar el ejemplar de la boleta y de sus notas). Greenter docs, `docs/faq.md`.
- Motivo del valor por defecto: el CDR llega en la misma llamada y el API puede mostrar el estado de inmediato.
- La **anulación** de una boleta siempre va por resumen diario (estado `3`), nunca por comunicación de baja: Greenter docs, `usage.md`,
  aviso «Anulación de Boletas». Las facturas y sus notas se anulan con comunicación de baja.
- Con `DAILY_SUMMARY` la boleta queda `ISSUED` (XML firmado, no enviado) hasta que `POST /v1/summaries/daily` la envíe.
- Solo `BOLETA` y las notas que modifican una boleta pueden ir por resumen. Una factura y sus notas siempre van inmediatas.
- No verificado contra SUNAT beta (host bloqueado): confirmar que el beta acepta boletas por `sendBill`.

## (b) Motivos de nota de crédito y de débito

- **Nota de crédito (Catálogo 09)**: se aceptan `01` a `10` (01 Anulación de la operación, 02 Anulación por error en el RUC, 03 Corrección
  por error en la descripción, 04 Descuento global, 05 Descuento por ítem, 06 Devolución total, 07 Devolución por ítem, 08 Bonificación,
  09 Disminución en el valor, 10 Otros conceptos).
- **Nota de débito (Catálogo 10)**: `01` Intereses por mora, `02` Aumento en el valor, `03` Penalidades / otros conceptos.
- Fuente: extracción del Anexo N.° 8 (R.S. 117-2017/SUNAT) publicada en el paquete `carloschininin/facturax-sunat` (`resources/catalogs.json`).
  **Fuente de terceros, versión de 2017.** Resoluciones posteriores pueden haber añadido códigos; el servicio rechaza (422) cualquier
  código fuera de esta lista hasta que se verifique contra el Anexo vigente.
- Greenter transporta el código tal cual (`Note::setCodMotivo`, plantilla `notacr2.1.xml.twig` → `cbc:ResponseCode`); no lo valida.

## (c) Tipo de afectación del IGV

**Decisión:** todos los ítems son `10` (Gravado – Operación Onerosa), tipo de operación `0101` (Venta interna).

- Fuente: Catálogo 07 (extracción de terceros, mismo origen que arriba) y los ejemplos de Greenter (`starter.md`, `examples/boleta.md`:
  `setTipAfeIgv('10')`, `setTipoOperacion('0101')`, `setPorcentajeIgv(18.00)`).
- Regla del negocio (`CLAUDE.md` del API): precio único para todos los clientes, sin exoneración por nacionalidad. Si el contador decide que
  alguna venta es exonerada o de exportación, habrá que ampliar el contrato (hoy no existe el campo).

## Otros catálogos usados

| Catálogo | Uso | Códigos | Fuente |
|---|---|---|---|
| 01 Tipo de documento | factura `01`, boleta `03`, nota de crédito `07`, nota de débito `08` | Greenter `DocumentFilter` y ejemplos |
| 06 Documento de identidad | DNI `1`, CE `4`, RUC `6`, pasaporte `7` | `1` y `6` en Greenter; `4` y `7` en la extracción de terceros |
| 02 Moneda | `PEN`, `USD` (ISO 4217) | Greenter |
| 19 Estado en resumen | agregar `1`, modificar `2`, anular `3` | Greenter `DocumentFilter` / `usage.md` |

## (d) Paquetes de Greenter

`greenter/lite` (modelos, XML UBL 2.1, firma y cliente SOAP), `greenter/report` y `greenter/htmltopdf` (representación impresa en PDF vía
`wkhtmltopdf`), y `greenter/ubl-validator` solo en desarrollo (valida el XML contra el XSD de UBL sin red). No se usa el metapaquete
`greenter/greenter` porque arrastra módulos que no necesitamos (guía de remisión por API, parser, validador de CDP, etc.).
Versión instalada: 5.3.0. Requiere `ext-soap` (cliente SUNAT) y el binario `wkhtmltopdf` (PDF).

## Arquitectura

- **Stack:** PHP 8.3, Slim 4, PDO (SQLite por defecto, DSN configurable), PHPUnit 11, PHPStan nivel 8, PSR-12 (`phpcs`).
- **Servidor en contenedor:** `php -S` con `PHP_CLI_SERVER_WORKERS=4`. Es sencillo y suficiente para un servicio interno de bajo volumen
  detrás de la red interna; si el volumen crece, cambiar a php-fpm + nginx. Sin DI container: el cableado es manual en `App::create`.
- **Datos del emisor** (RUC, razón social, dirección, ubigeo) y las credenciales viven en este servicio (variables de entorno). El contrato
  no los envía.
- **Entorno explícito:** `BILLING_ENV` es obligatorio (`BETA` o `PRODUCTION`), aparece en `/health`, y `PRODUCTION` exige gateway `greenter`.
