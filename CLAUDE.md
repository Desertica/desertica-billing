# Desertica billing

Servicio interno de facturación electrónica de Desertica (SUNAT, Perú): emite boletas, facturas y notas de crédito con **Greenter**, firma el XML, lo envía a SUNAT y devuelve el CDR y el PDF. Solo lo llama `Desertica/desertica-api`; no tiene puerto público.

Repo recién creado: todavía no tiene código. El contrato que implementa es `openapi/billing.yaml` en `Desertica/desertica-api`; el plan y los pendientes están en `docs/PENDIENTES.md` de ese repo.

## Stack previsto

PHP 8.3, Composer, `greenter/*`, un framework mínimo (Slim). Contenedor propio, red interna, autenticación con token de servicio (`Authorization: Bearer`).

## Rules

- **El contrato manda.** Implementa `openapi/billing.yaml`; un cambio de contrato se hace primero por PR en el repo del API.
- **Idempotencia por `externalId`**: repetir una emisión devuelve el resultado guardado; la misma `externalId` con otro contenido es `409`.
- **El API es dueño de series y correlativos.** Este servicio recibe `series` y `number` y no lleva contadores.
- **Montos**: enteros en la unidad menor con IGV incluido. Calcula base imponible e IGV con `igvRate` y redondea a céntimos de forma determinista; hay pruebas para eso. En USD exige el tipo de cambio SUNAT.
- **Secretos**: el certificado digital (.pfx), su clave y las credenciales SOL **nunca se commitean ni se loguean**. Se leen de variables de entorno o de secretos montados. En desarrollo y en sesiones cloud solo existen un certificado de prueba y el entorno **SUNAT beta**; el certificado y la clave SOL reales no salen del servidor de producción.
- El entorno (`BETA` o `PRODUCTION`) es configuración explícita y visible en `/health`; nunca se infiere.
- Los archivos (XML firmado, CDR, PDF) no se guardan aquí: el API los descarga y los conserva con retención legal.
- Verifica los códigos de catálogo SUNAT (motivo de nota de crédito, afectación del IGV) y si las boletas van una a una o por resumen diario con la documentación de Greenter y de SUNAT antes de implementarlos; no los escribas de memoria.
- Nunca commitees `.env`.

## Commands

Se completan al crear el esqueleto del servicio (Ola 1). Pruebas sin red: generar y firmar el XML con un certificado de prueba y validarlo contra el XSD. Pruebas con red: envío a SUNAT beta (requiere permitir el host en el entorno).
