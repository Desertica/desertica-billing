# Proteger ramas en GitHub (guia para principiantes)

Objetivo: nadie sube codigo directo a `main` ni a `develop`; todo entra por Pull Request (PR) con los checks en verde. Este repo maneja el certificado digital y la clave SOL, asi que esto es importante.

## Antes de empezar

1. Copia el workflow: `docs/pipelines/ci.yml` a `.github/workflows/ci.yml` (crea la carpeta si no existe), commit y push. Sin esto los checks no existen.
2. Crea el secreto `GITLEAKS_LICENSE` (el repo es de una organizacion, gitleaks lo exige): consigue una clave gratuita en https://gitleaks.io, luego Settings -> Secrets and variables -> Actions -> New repository secret, nombre `GITLEAKS_LICENSE`.
3. Abre un PR de prueba para que los checks corran al menos una vez; GitHub solo deja elegir checks que ya se han ejecutado.

Los checks se llaman exactamente: `secretos` y `php`.

## Ramas que se protegen

Ramas reales del repo hoy: `main` (por defecto), `develop`. Las demas (`claude/*`, `feat/*`) son ramas de trabajo temporales y no se protegen. Si mas adelante se crean ramas permanentes (por ejemplo `release/*`), se agregan igual.

## Pasos (repetir para `main` y para `develop`)

1. En GitHub abre el repo `Desertica/desertica-billing` -> **Settings** -> **Branches**.
2. En "Branch protection rules" pulsa **Add branch ruleset** (o **Add classic branch protection rule** si ves esa opcion; abajo se usan los nombres de la regla clasica).
3. En "Branch name pattern" escribe `main` (luego repite con `develop`).
4. Marca **Require a pull request before merging**.
   - **Required approvals**: 1 (con 2 personas en el equipo, la otra persona revisa).
   - Marca **Dismiss stale pull request approvals when new commits are pushed**.
5. Marca **Require status checks to pass before merging** y **Require branches to be up to date before merging**.
6. En el buscador de checks agrega: `secretos` y `php` (nombres exactos).
7. Marca **Require conversation resolution before merging**.
8. Marca **Do not allow bypassing the above settings** (tambien aplica a admins) y deja **Allow force pushes** y **Allow deletions** SIN marcar.
9. Pulsa **Create** / **Save changes**.

## Comprobar que funciona

Intenta `git push origin main` directo: debe rechazarlo. Abre un PR: el boton Merge debe quedar bloqueado hasta que `secretos` y `php` esten en verde.

## Que hacer si `secretos` falla

- "certificado versionado" / ".env versionado" / "clave privada versionada": quita el archivo del PR. Si ya se subio a algun commit, el secreto esta comprometido: avisa de inmediato para revocar el certificado o cambiar la clave SOL; borrar el archivo despues no basta.
- Falla de gitleaks: lee el reporte del job; si es un falso positivo, se agrega a `.gitleaksignore` (nunca se desactiva el check).

## Requisitos del esqueleto PHP

Para que `php` proteja de verdad, el esqueleto debe incluir `phpstan/phpstan` con nivel 6 o mayor (`phpstan.neon.dist`) y un estandar de estilo PSR-12 (`squizlabs/php_codesniffer` con `phpcs.xml.dist`, o `php-cs-fixer`), con los scripts de composer `lint` y `analyse`. El CI invoca las herramientas directamente; si faltan, esos pasos se omiten en silencio con un mensaje.

El workflow corre en cada push y en cada PR (cualquier rama); un push nuevo cancela la ejecucion anterior de la misma rama.

## Nota

`php` termina en verde con un mensaje cuando todavia no hay `composer.json`; en cuanto aparece, ejecuta `composer validate --strict`, `install`, `audit`, phpstan (si esta en composer.json) y phpunit (si hay `phpunit.xml(.dist)`).
