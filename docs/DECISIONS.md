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
- **Servidor en contenedor:** nginx + php-fpm, como usuario no-root (ver «Servidor de producción» más abajo). `php -S` queda solo para
  desarrollo local (README). Sin DI container: el cableado es manual en `App::create`.
- **Datos del emisor** (RUC, razón social, dirección, ubigeo) y las credenciales viven en este servicio (variables de entorno). El contrato
  no los envía.
- **Entorno explícito:** `BILLING_ENV` es obligatorio (`BETA` o `PRODUCTION`), aparece en `/health`, y `PRODUCTION` exige gateway `greenter` y todas las `BILLING_ISSUER_*` declaradas,
  distintas del RUC de prueba `20000000001`, de la razón social `DESERTICA SAC` (ejemplo) y de la dirección `-`; en `BETA` conservan esos valores por defecto. Con gateway `fake`, SUNAT se simula y se acepta todo, también en BETA.

## Emisión

- **Montos.** Todo en enteros (céntimos) con IGV incluido; la tasa se lee como puntos base (`0.1800` → 1800) y no se usan flotantes para calcular.
  Base imponible de una línea = `round_half_up(total × 10000 / (10000 + tasa))` y el IGV es el resto. El documento **suma las líneas**
  (no recalcula sobre el total) para que el XML cuadre con lo que SUNAT recomputa por ítem; por eso la base puede diferir en un céntimo de
  `total / 1.18` cuando hay varias líneas. Pruebas en `tests/Unit/TaxCalculatorTest.php`.
- **USD.** `exchangeRate` es obligatorio (422 si falta); se valida y se guarda, pero Greenter no lo escribe en el XML de factura o boleta.
  El PDF y el XML salen en USD.
- **Idempotencia.** `externalId` es la clave primaria; la fila se inserta antes de firmar o enviar (el INSERT es la puerta contra
  peticiones concurrentes). Misma petición (comparada por hash canónico, sin importar el orden de las claves) devuelve lo guardado y no
  reenvía; otro contenido → 409 `IDEMPOTENCY_CONFLICT`; serie+número ya usados por otra `externalId` → 409 `SERIES_NUMBER_IN_USE`.
- **Reintentos.** Si SUNAT no responde (sin código numérico, p. ej. Bad Gateway) → 502 `retryable: true`, el comprobante queda `PENDING` con su XML firmado
  y el reintento con la misma `externalId` **reenvía el mismo XML** (nunca se vuelve a firmar algo que SUNAT pudo recibir). Un código numérico de
  4 dígitos (cualquiera: `GreenterSunatGateway::exceptionFor` solo comprueba el formato `^\d{4}$`, no un rango; la FAQ de Greenter habla de 0100–1999) → 422 `SUNAT_EXCEPTION`, estado `ERROR`, y repetir la petición devuelve el mismo 422. Un CDR con
  código ≠ 0 → 200 con `REJECTED`; código 0 → `ACCEPTED` (las observaciones del CDR se anexan a `sunatMessage`).
- **Archivos.** XML firmado, CDR y PDF en `BILLING_STORAGE_DIR/<externalId>/`; el PDF (Greenter report + `wkhtmltopdf`) nunca tumba una emisión y se
  regenera bajo demanda en `GET .../files/pdf`. El «hash» del contrato es el `ds:DigestValue` de la firma.
- **Datos fijos de línea:** unidad `NIU` y código de producto `T001…` (el contrato no trae unidad ni código). Los ejemplos de Greenter usan `NIU`;
  para servicios SUNAT admite otros códigos del catálogo 03 (p. ej. `ZZ`), que **no** pude verificar. Preguntar al contador.
- **Firma y certificado.** `CertificateProvider` lee `.pem` o `.pfx/.p12` desde una ruta montada y lo valida (`openssl_x509_parse`: vigencia; `openssl_x509_check_private_key`: la clave
  corresponde; si el sujeto declara un RUC, debe ser el del emisor). Sin ruta (solo con gateway `fake`) genera un certificado autofirmado efímero, uno por proceso.
  `PRODUCTION` exige gateway `greenter`, certificado y SOL; `bin/startup.php` falla al arrancar si algo no sirve. No verificado: el formato real del sujeto de los certificados de
  los proveedores peruanos; si no declara el RUC como «RUC 20…» o como `serialNumber` de 11 dígitos, esa comprobación simplemente no se aplica.
- **Pruebas sin red del XML.** Greenter sí permite validar contra el XSD sin conexión con `greenter/ubl-validator` (UBL 2.1 para facturas, boletas y
  notas; UBL 2.0 para resumen y baja). Se valida además la firma con `SignedXml::verifyXml` y que un XML alterado deje de verificar.

## Baja, resumen y tickets

- **Baja.** Factura y sus notas → comunicación de baja (`RA-YYYYMMDD-NNNNN`). Boleta y sus notas → resumen diario con estado `3` (`RC-YYYYMMDD-NNN`),
  como indican los docs de Greenter. Solo se anula un comprobante `ACCEPTED`. El estado pasa a `VOIDED` cuando el ticket queda aceptado; si el ticket
  se rechaza, el comprobante vuelve a poder anularse. Repetir la baja devuelve el mismo ticket; otro motivo → 409 `VOID_CONFLICT`.
- **Correlativos de RC y RA.** Los lleva este servicio por día (tabla `batch_sequences`): son documentos propios del servicio, no series de comprobantes
  (esas son del API y aquí no hay contadores).
- **Resumen diario.** Incluye las boletas (y notas de boletas) de la fecha con `submission = DAILY_SUMMARY` y estado `ISSUED`. Greenter genera el
  resumen con **una sola moneda**, así que cada llamada atiende una moneda (PEN primero); llamar de nuevo atiende la siguiente. Si no queda nada
  pendiente devuelve el último ticket de esa fecha; si nunca hubo, 422 `NOTHING_TO_SUMMARIZE`. Con ticket rechazado las boletas se liberan para
  un nuevo resumen.
- **Tickets.** `GET /v1/tickets/{ticket}` consulta a SUNAT mientras esté `PENDING` (código 98 = en proceso; 0 y 99 = procesado, FAQ de Greenter) y
  actualiza los comprobantes. Los tickets terminados se sirven desde la base sin volver a SUNAT.

## Límites conocidos / no verificado contra SUNAT

- **No se probó contra SUNAT beta**: `e-beta.sunat.gob.pe` está bloqueado en esta sesión. `GreenterSunatGateway` se prueba contra un `See` simulado
  y `tests/SunatBeta` se activa solo cuando hay credenciales y el host es alcanzable.
- Clasificación de errores: «código de 4 dígitos = excepción de SUNAT (422); sin código = fallo de conexión (502, reintentable)». Los códigos
  0100–0199 incluyen errores de autenticación del usuario SOL, que aquí también salen como 422; confirmar con la lista de códigos de SUNAT.
- Recuperación por `getStatusCdr`, TLS verificado hacia SUNAT, `BILLING_ALREADY_REGISTERED_CODES` y la URL de consulta en BETA: ver la sección
  «Recuperación cuando se pierde la respuesta» (qué está verificado y qué no).
- Tratamiento de anticipos (depósito y saldo): sigue pendiente de consultar al contador. Las otras decisiones del contador (unidad, leyenda 2004,
  boleta sin receptor) ya son configuración: ver «Decisiones del contador».

## Contrato reconciliado

- Copia sin modificar del contrato en `openapi/billing.yaml` (origen y commit en `openapi/README.md`). Cada respuesta de cada prueba HTTP se valida contra él
  (`tests/Contract/ContractValidator`, con `opis/json-schema`): una respuesta con un estado que la operación no documenta, un código fuera del `enum`
  o un campo con otro tipo hace fallar la suite. `tests/Contract/ContractValidatorTest` comprueba que el validador de verdad detecta divergencias.
- `POST /v1/summaries/daily` acepta `currency` (`PEN` o `USD`): atiende esa moneda; sin ella, la siguiente pendiente (PEN primero). Repetir la misma
  moneda devuelve su ticket sin reenviar. Los resúmenes que solo anulan boletas (baja de boleta) ya no se devuelven como «resumen del día».
- El contrato define `hash` como `string` sin `null`: cuando todavía no hay XML firmado el campo se omite en vez de enviarse como `null`
  (lo encontró el validador).
- Fuera del contrato, nginx responde `413` (JSON `VALIDATION_ERROR`) a cuerpos de más de 1 MB.

## Decisiones del contador (configuración, valores por defecto seguros)

No las decide el servicio: son variables de entorno cuyo valor por defecto es el más restrictivo. **Hasta que el contador confirme, los valores por
defecto son los vigentes.**

| Variable | Defecto | Efecto | Qué debe confirmar el contador |
|---|---|---|---|
| `BILLING_UNIT_CODE` | `NIU` | Unidad de medida (catálogo 03) de todas las líneas. Solo se valida el formato (1 a 3 caracteres A-Z/0-9). | Si para paquetes turísticos corresponde `NIU`, `ZZ` (servicio) u otra. No se verificó la lista del catálogo 03. |
| `BILLING_LEGEND_2004` | `false` | Añade la leyenda 2004 «Agencia de Viaje – Paquete turístico» a **boletas y facturas** (no a notas). | Si Desertica debe emitirla, a qué documentos, y el texto exacto. Código y texto salen de la extracción de terceros del catálogo 52, no del Anexo oficial. |
| `BILLING_ANONYMOUS_BOLETA_MAX_CENTS` | `0` | `0`: el receptor es siempre obligatorio (422 sin `customer`). `> 0`: una **boleta en PEN** con total ≤ ese monto (céntimos, IGV incluido) puede omitir `customer`; el XML lleva tipo de documento `0`, número `-` y «CLIENTES VARIOS». | **El tope legal vigente** (el servicio no lo asume; no hay valor por defecto distinto de 0). También si el receptor `0`/`-`/«CLIENTES VARIOS» es lo que SUNAT espera. No aplica a USD, facturas ni notas. |

Notas:
- Con `customer` omitido, el contrato actual (que lo declara obligatorio) queda corto: ver «Cambios de contrato propuestos».
- La boleta sin receptor entra al resumen diario con tipo `0` y número `-`; se valida contra el XSD. Un cambio posterior de `customer` es otro contenido: 409.
- Cada interruptor tiene su prueba (`SettingsTest`, `AccountantOptionsTest`, `AccountantDecisionsTest`).

## Recuperación cuando se pierde la respuesta de SUNAT

**Problema.** El envío llega a SUNAT pero la respuesta se pierde (timeout, corte). El API reintenta con la misma `externalId`; reenviar el mismo XML puede
dar «comprobante ya registrado», que antes terminaba en un 422 definitivo.

**Mecanismo.**
1. El servicio anota `send_attempted` **antes** de la llamada de red. En un reintento con ese marcador pregunta primero por el CDR (`consultCdr`) y,
   si SUNAT lo tiene, lo adopta (ACCEPTED o REJECTED según el código del CDR) **sin reenviar**. Si no hay constancia, reenvía el mismo XML firmado.
2. Si el envío contesta con un código de `BILLING_ALREADY_REGISTERED_CODES` (por defecto `1033`), consulta el CDR. Si la consulta falla, responde `502`
   reintentable y el comprobante sigue `PENDING` (no se marca ERROR por un fallo transitorio de la consulta). Si SUNAT no tiene constancia, `422 SUNAT_EXCEPTION`.
3. La consulta es `ConsultCdrService::getStatusCdr(ruc, tipo, serie, número)` de Greenter (servicio `billConsultService`), con **las mismas credenciales SOL**
   (usuario `RUC+usuario` y clave, WS-Security UsernameToken). Lee el CDR del propio contenido de la respuesta (`content` + `ApplicationResponse`), no el
   `statusCode` de la consulta: así no depende de cómo SUNAT numere sus estados.

**Qué está verificado y qué no (sin acceso a SUNAT en esta sesión).**
- Verificado en el código de Greenter instalado: clase, firma de `getStatusCdr`, endpoint `SunatEndpoints::FE_CONSULTA_CDR`
  (`https://e-factura.sunat.gob.pe/ol-it-wsconscpegem/billConsultService`, **producción**) y que las pruebas de Greenter usan credenciales `RUC+usuario`.
  Los docs de Greenter (`usage.md`) solo describen `getStatus(ticket)`; no documentan `getStatusCdr`.
- **No verificado:** el código exacto de «ya registrado». `1033` («El comprobante fue registrado previamente con otros datos») sale de una búsqueda web
  de terceros; por eso es configurable. Confirmar con la lista de códigos de error de SUNAT.
- **No verificado:** si la consulta existe en beta. Por eso `BILLING_CDR_CONSULT_URL` está vacía en BETA y solo se activa por defecto en PRODUCTION
  (en BETA hay que fijarla explícitamente). Tampoco se verificó si el servicio admite boletas (si no devuelve constancia, la recuperación simplemente no ocurre).
- **Riesgo asumido:** el CDR de la consulta no incluye el hash del XML. Si SUNAT tuviera registrado el número con *otro* XML, se adoptaría su CDR. Esto solo puede
  pasar si se perdió la base de este servicio (nunca se firma dos veces el mismo comprobante: ver cerrojos).

**Concurrencia (revisión adversarial).** Dos peticiones simultáneas con la misma `externalId` podían firmar dos veces (firmas distintas) y mandar a SUNAT dos
versiones del mismo número, que es justo el caso «registrado con otros datos». Ahora cada emisión, baja y resumen toma un cerrojo con caducidad en la base
(`locks`, 130 s > 110 s del límite de php-fpm). Si está ocupado: la emisión responde el estado actual (`PENDING`, 200); la baja, `409 VOID_CONFLICT`; el resumen, `502`
reintentable. Se relee el estado con el cerrojo tomado.

## TLS hacia SUNAT (hallazgo de la revisión)

`Greenter\See` crea su `SoapClient` con `verify_peer` y `verify_peer_name` en `false`, y por ese canal viaja la clave SOL. `GreenterSunatGateway::connect` sustituye
el cliente por `VerifiedSoapClient` (verificación activa, `connection_timeout` 10 s, `BILLING_SUNAT_CA_FILE` opcional). `tests/Unit/VerifiedSoapClientTest` lo comprueba
contra un servidor TLS local con certificado autofirmado: el cliente de Greenter conecta; el nuestro lo rechaza.
- `See` guarda su cliente en una propiedad privada: se reemplaza con una closure; `GreenterSunatGatewayTest::testConnectReplacesGreenterClientsWithTlsVerifyingOnes`
  falla si Greenter cambia esa propiedad (versión fijada `^5.3`).
- **Riesgo no probado:** la FAQ de Greenter menciona problemas al validar el certificado SSL de SUNAT. Con el host bloqueado no se pudo comprobar que la cadena
  de `e-beta`/`e-factura` valide con el almacén del sistema de la imagen. Si falla, usar `BILLING_SUNAT_CA_FILE`; no se añadió un interruptor para apagar la verificación.

## Servidor de producción

**Elegido: nginx + php-fpm** en un solo contenedor, como usuario `billing` (uid 10001).

Por qué no FrankenPHP: (1) cada petición en php-fpm termina con su proceso limpio; en el modo worker de FrankenPHP el estado persiste entre peticiones y aquí pasan por el
proceso el certificado, la clave SOL y los XML; (2) php-fpm/nginx son lo que cualquier equipo PHP sabe operar y depurar; (3) la imagen oficial `php:8.3-fpm` permite
instalar `soap`, `zip` y `pdo_sqlite` igual que antes y `wkhtmltopdf` desde apt; (4) FrankenPHP añade un runtime (Caddy/Go) y su propia política de actualizaciones por un
servicio de bajo volumen. Coste asumido: dos procesos en el contenedor (un script de arranque los vigila y sale si cualquiera muere).

- **Non-root:** nginx y php-fpm sin directivas `user`; socket Unix en `/run/billing`, temporales en `/tmp`; la imagen funciona con `read_only: true` + tmpfs
  (el compose lo usa) y `cap_drop: [ALL]`.
- **Healthcheck:** `GET /health` a través de nginx y php-fpm (comprueba la cadena entera).
- **Logs a stderr en JSON sin secretos:** la aplicación escribe una línea por petición (método, ruta, estado, duración; **sin** cabeceras, query ni cuerpo) y sus eventos con
  `StderrLogger`; los avisos/excepciones de PHP pasan por el mismo logger (`PhpErrorLogging`, sin trazas) y `zend.exception_ignore_args=On`. nginx no registra accesos
  (`access_log off`); su log de errores y el de php-fpm van a stderr **en texto**, no JSON (nginx y php-fpm no lo soportan sin más).
- **Parada ordenada:** SIGTERM → SIGQUIT a ambos (terminan las peticiones en curso, p. ej. un envío a SUNAT); `stop_grace_period: 130s` en el compose.
- **Variables y secretos:** `clear_env = no` para que `BILLING_*` lleguen a PHP; los secretos también pueden venir de archivos (`*_FILE`). `wkhtmltopdf` no hereda el entorno
  (solo `PATH`) y corre con `disable-local-file-access`: el logo va en línea (data URI) y el HTML de entrada es el único archivo que necesita. Permisos: base y archivos solo del usuario del servicio
  (`umask(0077)`; directorios 0750, archivos 0600).
- **Estado de la validación (histórico, sesión cloud sin Docker):** se validó entonces: `hadolint Dockerfile` limpio; `bash -n` del arranque;
  `nginx -t`; `docker compose config`; y la **pila real fuera de Docker** (nginx 1.24 + php-fpm 8.3 de Ubuntu con esos mismos archivos de configuración, como usuario no-root con
  uid 10001): `/health`, 401, emisión con gateway simulado, descarga del XML, 400, 413, parada con SIGTERM y salida del script cuando muere php-fpm.
  Después se comprobó con Docker real: `docker build`, `docker run` con `/health` (healthy), emisión con gateway fake y descarga del PDF dentro de la imagen, y que el arranque falla con
  un mensaje claro en `PRODUCTION` sin los datos del emisor.
- **Timeouts.** Cada llamada SOAP a SUNAT tiene límites explícitos en `VerifiedSoapClient`: conexión 10 s y lectura 30 s (se fija `default_socket_timeout` por llamada, porque
  `SoapClient` no tiene otra opción). Una emisión hace como máximo dos llamadas (consulta de CDR + envío): (10 + 30) × 2 = 80 s < `request_terminate_timeout` 110 s,
  y el cerrojo (130 s) caduca después de ambos y de `fastcgi_read_timeout` de nginx (120 s). Si se cambia un valor, revisar la cadena entera.
- **Arranque.** El esquema se migra una sola vez (`schema_version`; las peticiones solo leen esa tabla), el certificado y el gateway se cargan al primer uso y el certificado de
  prueba del gateway `fake` se genera una vez por proceso. `/health` pasó de ~280 ms a ~2 ms en local. `bin/startup.php` (lo ejecuta el entrypoint antes de nginx y php-fpm) valida la
  configuración, prepara la base y valida el certificado (vigencia, clave que corresponde, RUC del certificado si lo declara): si algo falla, el contenedor no arranca.
- SQLite con varios procesos de php-fpm: WAL y `busy_timeout` 5 s; con 8 procesos máximo y poco volumen es suficiente. Si crece, usar `BILLING_DB_DSN` con otro motor.

## Revisión adversarial del diff

Encontrado y corregido (con prueba):
1. **TLS sin verificar hacia SUNAT** (clave SOL por ese canal): ver arriba.
2. **Texto que rompe el XML firmado:** `]]>` en una descripción o nombre cerraba el CDATA de Greenter, y U+FFFE/U+FFFF no son caracteres válidos de XML; ambos acababan en
   `500 INTERNAL_ERROR` con la fila en `PENDING` y el API reintentando sin fin. Ahora son `422 VALIDATION_ERROR` antes de insertar. También probados y aceptados: `& < > " '`,
   emoji, CJK, árabe, `{{ 7*7 }}` (Twig no evalúa datos), saltos de línea y tabuladores.
3. **Desbordamiento de enteros:** montos cercanos a `PHP_INT_MAX` desbordaban `quantity × unitPriceCents`, `2 × total × 10 000` y `base × 10^10` (TypeError → 500). Topes: ítem ≤ 100 000 000
   céntimos (S/ 1 000 000), documento ≤ 50 000 000 000; con el máximo, el cálculo es exacto.
4. **Concurrencia sobre la misma `externalId`:** ver cerrojos.
5. **Secretos en mensajes:** los mensajes de SoapFault y de error de SUNAT se devuelven al API y se registran; ahora el gateway y el logger tachan el usuario/clave SOL,
   la clave del certificado y el token de servicio si aparecen en el texto (`SecretScrubbingTest`). Los logs de petición no llevan cabeceras, query ni cuerpo.
6. **Inyección en el PDF:** los textos del cliente van al HTML de wkhtmltopdf. Se comprobó que la plantilla de Greenter los escapa (`<iframe src=file://…>` queda inerte);
   `PrintedRepresentationEscapingTest` lo fija. Solo `header` y `footer` usan `|raw` y los fija este servicio en vacío.
7. **Resumen diario devolvía una baja:** `latestSummary` podía devolver el ticket de una baja de boleta como «resumen del día».

Redondeos: sin cambios en `TaxCalculator` (mitad hacia arriba por línea, suma de líneas); se añadió la prueba del máximo permitido.

Sin resolver / a vigilar:
- Si el proceso muere entre firmar y escribir el XML hay una ventana mínima en la que el reintento firma de nuevo; no hay envío previo en ese caso (`send_attempted` aún en 0).
- El cerrojo es por base de datos: con varias réplicas sobre SQLite no compartido no sirve; usar un motor compartido (`BILLING_DB_DSN`) o una sola réplica.
- Anticipos (depósito y saldo) siguen sin modelarse.
- **Estados terminales (auditoría M2):** un comprobante `ERROR` o `REJECTED` es terminal y su serie+número quedan ocupados; ver «Pendientes de la auditoría».
- **Baja y resumen sin recuperación (auditoría S5):** ver «Pendientes de la auditoría».
- **Retención y respaldo de SQLite (auditoría S9):** ver «Pendientes de la auditoría».

## Pendientes de la auditoría (no implementados)

Hallazgos de la auditoría que no se resolvieron porque dependen de una decisión de negocio, de un cambio de contrato o de un riesgo de migración. Para cada uno: propuesta y pregunta.

| Id | Hoy | Propuesta | Pregunta |
|---|---|---|---|
| M2 estados terminales | `ERROR` (excepción de SUNAT) y `REJECTED` (CDR con código ≠ 0) son terminales: repetir la petición devuelve lo mismo, otro contenido es 409 y serie+número quedan ocupados. La clasificación es gruesa: 4 dígitos = excepción, sin código = reintentable. | Clasificar los códigos de SUNAT (excepción corregible, rechazo con CDR, observación) y permitir reemplazar el contenido de un comprobante que SUNAT nunca registró, con el mismo número. | Contador: tras una excepción o un rechazo, ¿se reutiliza el mismo correlativo corrigiendo el comprobante o se salta y se registra la baja? ¿Qué códigos tratar como corregibles? |
| S3 `igvRate` y afectación | `igvRate` lo manda el cliente (cualquier tasa válida de formato); la afectación es siempre `10` y la operación `0101`. | Lista permitida de tasas en configuración (por defecto solo `0.1800`) y rechazo del resto; afectación configurable solo si el contador lo pide. | Contador: ¿alguna venta es exonerada, inafecta o de exportación (p. ej. turistas no domiciliados)? ¿Qué tasas son válidas? |
| S4 tipo de cambio USD | `exchangeRate` se exige, se valida y se guarda, pero no sale en el XML ni en el PDF. | Imprimirlo en el PDF/observaciones y fijar de dónde sale (cambio SUNAT del día de emisión); si SUNAT lo exige en el XML, añadirlo. | Contador: ¿qué tipo de cambio se usa y quién lo calcula, el API o este servicio? ¿Se emite en USD o se convierte a PEN? |
| S5 baja y resumen sin recuperación | `sendBatch` devuelve el ticket y solo entonces se guarda; si se pierde la respuesta o el proceso muere, el ticket no se registra, el correlativo `RC`/`RA` queda consumido y los comprobantes siguen `ISSUED`/`ACCEPTED`. No hay equivalente a `send_attempted` + `getStatusCdr`. | Anotar el intento antes de enviar y reconciliar (reenviar el mismo XML o consultar el ticket); endpoint o tarea que liste lotes sin ticket. | Contador/operaciones: ¿qué hacer si SUNAT ya procesó un resumen que no registramos (duplicado de `RC`)? |
| S8 `/health` | Devuelve `ok` y el entorno sin tocar base, disco ni certificado. | Separar liveness (actual) de una comprobación de readiness: base, escritura en el almacenamiento y días de vigencia del certificado, sin exponer detalles. Cambia el contrato (repo del API). | Operaciones: ¿qué debe vigilar el monitor y con qué umbral de vencimiento del certificado? |
| S9 retención y respaldo | SQLite en un volumen, sin respaldo ni purga. Los archivos son de trabajo; la retención legal es del API. | Respaldo periódico de la base (`.backup` de SQLite) fuera del volumen y purga de archivos ya descargados por el API; o pasar a un motor gestionado con `BILLING_DB_DSN`. | Contador: ¿plazo legal de conservación de XML/CDR y quién responde, el API o este servicio? |
| S11 plazos SUNAT | No se valida la antigüedad de `issueDate` ni se avisa de comprobantes `PENDING`/`ISSUED` cerca del límite (boletas: 5 días calendario según la FAQ de Greenter). | Validar la ventana de `issueDate` por tipo y alertar los pendientes antiguos. | Contador: plazos vigentes de envío por tipo (factura, boleta, notas, bajas) y qué hacer al vencerlos. |
| N1 `UNIQUE` | `UNIQUE (series, number)` sin el tipo: una factura y una nota con la misma serie y número chocarían aunque SUNAT numera por tipo y serie. | `UNIQUE (doc_type, series, number)` con migración (SQLite exige reconstruir la tabla) y el mismo criterio en `seriesInUse`. | API: ¿la numeración es por tipo+serie, como en SUNAT? |

## Cambios de contrato propuestos (para el repo del API)

1. `customer` opcional en `EmitDocumentRequest` **solo si** el contador activa `BILLING_ANONYMOUS_BOLETA_MAX_CENTS`; hoy el contrato lo declara `required`. Alternativa sin
   cambio de esquema: que el API envíe `idDocType`/`idDocNumber`/`name` genéricos; el servicio no tiene un tipo de documento «sin documento» en el enum `idDocType`.
2. Documentar que `POST /v1/documents` puede responder `200` con `status: PENDING` cuando otra petición con la misma `externalId` está en curso (el API debe consultar
   `GET /v1/documents/{externalId}` o reintentar), y que `POST /v1/summaries/daily` responde `502` reintentable si ya hay un resumen de esa fecha en curso.
3. Añadir `maximum` a `totalCents` (50 000 000 000), `Item.unitPriceCents` y `Item.totalCents` (100 000 000), y `maxLength` a las cadenas (descripción 250, nombre 200); y
   documentar el `413` de cuerpos mayores de 1 MB y que el texto no puede contener `]]>` ni controles XML.
4. Registrar en `EmitDocumentResult.hash` que se omite mientras no hay XML firmado (hoy el esquema ya lo permite al no ser `required`).
