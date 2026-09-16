# AGENTS.md — Contexto del proyecto

## Convenciones críticas

- **Odoo:** la base de datos de producción se llama `odoo_staging` (no `odoo`). El nombre `staging` es histórico, no indica que sea un ambiente de pruebas. Tratar siempre `odoo_staging` como producción.
- **PostgreSQL:** `odoo_staging` vive en el EC2 de Odoo (`34.224.173.39`) en el contenedor `odoo-db-1`. Existe además una BD `odoo` separada (que NO es producción) en el mismo Postgres; ignorarla.
- **BD staging Odoo nueva:** nombre **`odoo_stg`** (a confirmar con @asistente-de-bases-de-datos). Vive en el EC2 staging Odoo cuando se aprovisione, **NO** comparte Postgres con prod. Toda automation debe verificar el target por **IP / tag `Environment` de la EC2 / nombre de la BD + host** — **nunca** por el lexema "staging" en el nombre de la BD (porque `odoo_staging` ES prod y `odoo_stg` ES staging — opuesto al sentido común).
- **Trucha léxica a no repetir:** `odoo_staging` (BD prod, nombre engañoso) vs `moodle_staging` (BD staging Moodle, ya creada en `moodle-staging-ec2` 3.224.158.23). Misma palabra, dos roles opuestos. Toda automation que las lea tiene que verificar la IP del contenedor (`inet_server_addr()`) y el tag de la EC2 (`Environment=production` vs `Environment=staging`).
- **Moodle BD:** `isidb` vive en `52.20.149.225` (mismo host que el EC2 de Odoo). Prefijo de tablas `isi_` (no `mdl_`).
- **Dominios staging:** patrón único `*.staging.infoisi.com` (zona Cloudflare `infoisi.com`, zone_id `221d051e9216e3587ea334bdc226ede0`). Subdominios: `lms.staging.infoisi.com` (Moodle staging, ya activo), `odoo.staging.infoisi.com` (Odoo staging, a crear). **`odoo.isi.edu.pa` es producción y NO se toca** — decisión del usuario 2026-09-16, sin excepciones.
- **Moodle fork:** `C:\Users\Ramiro\Documents\TI\Proyectos\Plug Ins Moodle\grupomakro_core` (branch `master`, sin remote). Deploy vía `git push` local + SSH + `git pull` en el server (100.28.104.64).
- **Odoo deploy:** push a `origin/main` en `https://gitlab.com/rbalmaceda2/isi-erp-odoo-local` → pipeline dispara `lint_python` y `build_image` automáticamente → darle play ▶️ a `deploy_production` (manual). El runner self-hosted (id 54562804) corre dentro del EC2 de Odoo.

## Hosts

| Componente | IP/Endpoint | User | Key | Notas |
|---|---|---|---|---|
| EC2 Odoo (prod) | 34.224.173.39 | ec2-user | `C:\cred\odoo-aws-key` | Contiene BD `odoo_staging` (prod). Deploy automatizado vía GitLab. |
| EC2 Moodle staging | 3.224.158.23 (`moodle-staging-ec2`) | ubuntu | (pendiente, vía SSM port-forward o nueva key) | Contiene BD `moodle_staging` (MariaDB local). Staging Moodle, NO Odoo. |
| EC2 Odoo staging | **PENDIENTE** (a levantar) | — | — | Cuando exista: contiene BD `odoo_stg` (Postgres separado). DNS `odoo.staging.infoisi.com`. |
| Server Moodle | 100.28.104.64 | ubuntu | `C:\cred\moodle.pem` | Moodle en `/var/www/html/moodle/`. BD `isidb` en `52.20.149.225`. |
| RDS / BD remota | 52.20.149.225 | isi / `zLK9UjZVtCyYJTvDg4rk8F*` | (vía SSH) | BD `isidb` Moodle. |
| Proxy Express prod | `https://lms.isi.edu.pa:4000` | — | — | API del servidor Express que habla con Odoo. |

## Módulos Odoo clave

- `moodle` — sincroniza contactos/estudiantes Odoo → Moodle
- `moodle_invoice_payment_webhook` — webhook al proxy Express cuando cambia `payment_state` de una factura
- `carreras` — gestión de carreras/módulos

## Flujo de deploy Odoo

1. Editar en `C:\Users\Ramiro\Documents\TI\Proyectos\OdooDev\isi-erp-odoo-local`
2. `git push origin main` → pipeline `lint → build` automático
3. En `https://gitlab.com/rbalmaceda2/isi-erp-odoo-local/-/pipelines`, play ▶️ a `deploy_production`
4. El runner dentro del EC2 ejecuta `update-odoo.sh <sha> <mods>` (login ECR, pg_dump, docker pull, upgrade, smoke test)

## Comando clave para shell Odoo prod

```bash
ssh -i "C:\cred\odoo-aws-key" ec2-user@34.224.173.39 \
  "docker exec odoo-odoo-1 odoo shell -d odoo_staging --no-http < /dev/null"
```

## Comando clave para shell Moodle

```bash
ssh -i "C:\cred\moodle.pem" ubuntu@100.28.104.64
mysql -h 52.20.149.225 -u isi -p"..." isidb  # usar --defaults-file=~/.my.cnf si hay escape issues
```
