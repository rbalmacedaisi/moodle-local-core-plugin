# X-Api-Key compartida LXP <-> Odoo

Este secret se usa para autenticar las llamadas entre Moodle (status-change wizard)
y Odoo (subscription_oca) via el router `odoo_students.js` de Express.

## Configuración actual

### Moodle (server `lms.isi.edu.pa`)
- Plugin `local_grupomakro_core`
- Setting `local_grupomakro_core | odoo_proxy_api_key`
- Valor (12-ago-2026):
  ```
  QGdeM05IpbZgclKYwvok9AUBfuXFVryDS1CnqjH2shxT863L
  ```
- Guardado en `mdl_config_plugins` (verificado con `php /tmp/set_key2.php`).

### Odoo (server `34.224.173.39`)
- `ir.config_parameter` con key `subscription_oca.api_key`
- Mismo valor que arriba.
- Si no está seteado, el controller `subscription_oca.controllers.aplazo_api`
  sigue en modo abierto (matches el comportamiento legacy de `/api/odoo/status/bulk`).

### Express (server donde corre `rest_express` aún no deployado)
- Variable de entorno `ODOO_PROXY_API_KEY` con el mismo valor.
- Cuando está seteado, el middleware `odoo_students.js` exige header
  `X-Api-Key` en las llamadas a `/api/odoo/students/*`.
- Si NO está seteado, los endpoints quedan abiertos (compatible con legacy).

## Endpoints protegidos

- `GET  /api/odoo/students/:vat/pending-invoices` (consumido por el wizard)
- `POST /api/odoo/students/aplazar` (consumido por el wizard)
- `POST /api/odoo/students/retirar` (consumido por el wizard)

## Endpoints NO protegidos (legacy)

- `POST /api/odoo/status/bulk` (cron de Moodle)
- `POST /api/odoo/cache/invalidate` (webhook)
- `POST /api/odoo/modules/webhook/payment` (webhook)
- etc.

## Rotación

Si rotás el secret, hay que actualizar los 3 lados en orden:
1. Odoo: `ir.config_parameter` (cambia el comportamiento del controller)
2. Express: variable de entorno (cambia la respuesta del middleware)
3. Moodle: `config_plugins` (cambia el header que envía el wizard)

Orden inverso (Moodle primero) corre riesgo de auth fails en ventana corta.
