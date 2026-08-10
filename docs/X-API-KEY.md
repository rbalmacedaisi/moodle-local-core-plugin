# X-Api-Key compartida LXP <-> Odoo

Este secret se usa para autenticar las llamadas entre Moodle (status-change wizard)
y Odoo (subscription_oca) via el router `odoo_students.js` de Express.

## IMPORTANTE: el valor NO se commitea

Este archivo **NO** debe contener el valor del secret. El secret vive
solo en:

- Moodle: `mdl_config_plugins.odoo_proxy_api_key` (admin UI en
  Plugins -> grupomakro_core -> Financial settings -> API Key Odoo Proxy).
- Express: variable de entorno `ODOO_PROXY_API_KEY` en
  `/home/ubuntu/odoo-proxy/ecosystem.config.js` (no commitear, ver .gitignore).
- Odoo: `ir.config_parameter` con key `subscription_oca.api_key`.

Si el valor aparece en este archivo historicamente, **removerlo** y
pedir a quien lo haya pegado que lo rote (el secret queda expuesto en
el historial de git).

## Configuración

### Moodle (server `lms.isi.edu.pa`)
- Plugin `local_grupomakro_core`
- Setting `local_grupomakro_core | odoo_proxy_api_key`
- Definido en `settings.php` como `admin_setting_configpasswordunmask`
  (admin UI). Default: vacío (modo abierto compatible con legacy).
- Si está seteado, `dispatch_odoo_action()` y `dispatch_odoo_reactivation()`
  en `status_change_manager.php` lo envían como header `X-Api-Key`.

### Odoo (server `34.224.173.39`)
- `ir.config_parameter` con key `subscription_oca.api_key`.
- Si no está seteado, el controller `subscription_oca.controllers.aplazo_api`
  sigue en modo abierto (matches el comportamiento legacy de
  `/api/odoo/status/bulk`).
- Configurable desde Settings -> Technical -> System Parameters
  (UI) o via XML-RPC:
  ```
  model: ir.config.parameter
  key: subscription_oca.api_key
  value: <the-shared-secret>
  ```

### Express (server `lms.isi.edu.pa` -> `/home/ubuntu/odoo-proxy/`)
- Variable de entorno `ODOO_PROXY_API_KEY` definida en `ecosystem.config.js`
  (no commitear al repo).
- El middleware `odoo_students.js` exige header `X-Api-Key` en todas las
  llamadas a `/api/odoo/students/*` cuando la variable está seteada.
- Si NO está seteado, los endpoints `/students/*` quedan abiertos
  (compatible con legacy).

## Endpoints protegidos (requieren X-Api-Key)

- `GET  /api/odoo/students/:vat/pending-invoices` (wizard preview)
- `POST /api/odoo/students/aplazar` (wizard / Odoo wizard)
- `POST /api/odoo/students/retirar` (wizard / Odoo wizard)
- `POST /api/odoo/students/reactivar` (wizard / Odoo wizard)

## Endpoints NO protegidos (legacy, siguen abiertos)

- `POST /api/odoo/status/bulk` (cron de Moodle)
- `POST /api/odoo/cache/invalidate` (webhook)
- `POST /api/odoo/modules/webhook/payment` (webhook)
- `POST /api/odoo/letters/*`, `/api/odoo/revalidations/*` (webhooks con HMAC propio)
- etc.

## Rotación (orden recomendado para evitar ventana de auth fails)

1. Generar nuevo secret (`openssl rand -hex 32` o equivalente; 64 chars hex).
2. Set en Odoo: `ir.config_parameter.subscription_oca.api_key` (XML-RPC).
3. Set en Express: `ODOO_PROXY_API_KEY` en `ecosystem.config.js`,
   `pm2 delete all && pm2 start ecosystem.config.js && pm2 save`.
4. Set en Moodle: `UPDATE isi_config_plugins SET value='...' WHERE name='odoo_proxy_api_key'`,
   purge `core_config/` cache.
5. Smoke test: `curl -sk https://lms.isi.edu.pa:4000/api/admin/bypass -H 'x-admin-secret: ...'`
   + un test `build_preview()` desde un estudiante real.

Si rotás en orden inverso (Moodle primero) corres riesgo de auth fails
en una ventana corta entre pasos.

## Auditoría

Cualquier `git log -p docs/X-API-KEY.md` que muestre un valor concreto
es un leak: rotar inmediatamente y considerar el secret previo
comprometido.