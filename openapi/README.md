# Copia del contrato

`billing.yaml` es una **copia sin modificar** de `openapi/billing.yaml` de `Desertica/desertica-api`
(rama `claude/ola2-contract`, commit `22c3fb512f277f545d97bc55d9047d88595e884a`, «Reconcile the contract with the Ola 1 findings»).

- La fuente de verdad es el repo del API: un cambio de contrato se hace allí por PR y aquí se vuelve a copiar el archivo completo.
- Está aquí para que las pruebas (`tests/Contract`) validen las respuestas reales sin red. Para probar contra otra copia:
  `BILLING_CONTRACT_PATH=/ruta/a/billing.yaml composer test`.
- No se edita a mano. Si este archivo y el del API divergen, manda el del API.
