# Sincronización de Estado Financiero (Odoo ↔ Moodle)

> Versión: 20260818000 (Moodle fork `grupomakro_core`).

Este documento describe el flujo de sincronización del estado financiero
(`al_dia`, `mora`, `becado`, `convenio`, `sin_contrato_o_usuario`) entre
**Odoo** (fuente de verdad contable) y **Moodle** (snapshots que usan
academicpanel, reportes y filtros).

## Arquitectura

```
                 Odoo
                  │
                  │ (cr.postcommit) hooks en account.move, account.partial.reconcile,
                  │ account.payment y res.partner (modules
                  │ moodle_invoice_payment_webhook,
                  │ moodle_module_invoice_webhook, moodle_letter_webhook,
                  │ moodle_revalida_webhook)
                  ▼
        Express /api/odoo/cache/invalidate
                  │
                  ├─► invalida caché Express (POS 24h / NEG 5 min)
                  │   → próximo login del LXP recalcula contra Odoo
                  │
                  └─► push fire-and-forget a Moodle (dedupe 30s por VAT,
                      rate limit 5/seg)
                          │
                          ▼
              Moodle pages/financial_webhook.php
                          │
                          ├─► HMAC verify → sync OK
                          │   actualiza gmk_financial_status para ese userid
                          │
                          └─► HMAC ok + sync falla → DLQ
                              (gmk_financial_webhook_dlq,
                              reintento manual desde
                              pages/financial_webhook_dlq.php)

   Respaldo / red de seguridad:

   • Hook \core\event\user_loggedin (Moodle):
     classes/event/user_login_handler.php → maybe_refresh_financial_status()
     Throttle 6h por userid. Solo para usuarios con usertype='Estudiante'.
     Si falla el proxy, NO bloquea el login (try/catch).

   • Cron residual \local_grupomakro_core\task\update_financial_status:
     cada 6h, 1 lote de 15. Solo procesa filas con
     gmk_financial_status.lastupdated > 24h.

   • Cron manual vía CLI:
     sudo -u www-data php local/grupomakro_core/cli/sync_financial_all.php
     para bootstrap inicial o resincronizaciones masivas.
```

## Indicador en academicpanel

`pages/academicpanel.php` → tab Estudiantes (`studenttable.js`) muestra
4 cards en la parte superior:

1. **Estudiantes Activos** (universo, ya existía)
2. **Al día (financiero)** = `al_dia + becado + convenio`
3. **En mora** = `mora`
4. **Pendientes / sin contrato** = `sin_contrato_o_usuario + sin fila en gmk_financial_status`

Las 3 cards financieras se auto-actualizan cada 60s vía el WebService
`local_grupomakro_get_financial_counts` (definido en
`classes/external/student/get_financial_counts.php`).

- Polling se pausa si el usuario está editando (focus en input).
- Si el WS falla: backoff 30s → 60s → 120s, indicador muestra
  "offline — reintentando en Ns" con icono rojo.
- Click en el icono `mdi-information-outline` de cada card muestra el
  desglose completo (al_dia/becado/convenio, sin_contrato/pendiente).

## Bootstrap CLI

El CLI `cli/sync_financial_all.php` reusa
`local_grupomakro_sync_financial_status([$userids])` y barre TODOS los
estudiantes con `documentnumber` en chunks.

### Comandos típicos

```bash
# Dry-run (no toca BD, solo cuenta y mide):
sudo -u www-data php local/grupomakro_core/cli/sync_financial_all.php --dry-run

# Bootstrap normal (15 por lote, 2s entre lotes):
sudo -u www-data php local/grupomakro_core/cli/sync_financial_all.php

# Bootstrap agresivo (10 por lote, 3s throttle):
sudo -u www-data php local/grupomakro_core/cli/sync_financial_all.php --batch=10 --throttle=3

# Limitar a N estudiantes (útil para smoke test):
sudo -u www-data php local/grupomakro_core/cli/sync_financial_all.php --max=50

# Con log a archivo:
sudo -u www-data php local/grupomakro_core/cli/sync_financial_all.php \
    --logfile=/var/log/moodle-financial-bootstrap.log

# Ver opciones:
sudo -u www-data php local/grupomakro_core/cli/sync_financial_all.php --help
```

- `Ctrl+C` / `SIGTERM` → termina limpio después del batch actual
  (exit code 130).
- Cada batch loguea: `[chunk N/M] OK: X actualizados de Y solicitados (Zs)`.
- Loguea progreso y ETA cada batch.

## Diagnóstico

### Dead-letter queue

URL: `/local/grupomakro_core/pages/financial_webhook_dlq.php`
(capability `moodle/site:config`)

- Lista de webhooks que fallaron al actualizar `gmk_financial_status`.
- Tabs: pendientes / resueltos / abandonados.
- Acciones por fila: **Reintentar** (llama al endpoint en modo retry)
  o **Marcar OK** (manual).
- Acción bulk: **Reintentar todas** (max 200 por click, con 200ms entre
  cada uno para no saturar).

Estados posibles:

- `pending`   → recibió el push, sync a Odoo falló, intentos < 10.
- `resolved`  → reintento OK o admin marcó manualmente.
- `abandoned` → 10+ intentos fallidos; requiere acción manual.

### Health check

URL: `/local/grupomakro_core/pages/financial_health.php?token=<health_token>`

Token opcional: configurado como
`local_grupomakro_core | financial_health_token`. Si está vacío, el
endpoint es público (no recomendado en producción).

Devuelve JSON con:

```json
{
  "success": true,
  "health": "ok",
  "message": "flujo financiero saludable",
  "server_time": 1723456789,
  "gmk_financial_status": {
    "total": 487,
    "fresh_lt_1h": 412,
    "fresh_lt_6h": 487,
    "stale_gt_24h": 0,
    "stale_gt_7d": 0,
    "stale_pct": 0
  },
  "cron": {
    "last_run_unix": 1723440000,
    "last_run_age_seconds": 16789
  },
  "dlq": {
    "pending": 0,
    "resolved": 12,
    "abandoned": 0
  }
}
```

Health score:

- `ok`    → DLQ pending ≤ 5 y stale ≤ 30%.
- `warn`  → DLQ pending > 5 o stale > 30%.
- `error` → DLQ pending > 50.

## Configuración

| Setting | Default | Override | Notas |
|---|---|---|---|
| `local_grupomakro_core/financial_webhook_secret` | `gmk_payment_invalidate_2026` | env `ODOO_PAYMENT_WEBHOOK_SECRET` en Express | Debe coincidir con el secret del módulo Odoo `moodle_invoice_payment_webhook`. |
| `local_grupomakro_core/financial_health_token` | (vacío) | definir antes de exponer | Si vacío, health check es público. |
| `local_grupomakro_core/odoo_proxy_url` | `https://lms.isi.edu.pa:4000` | `MOODLE_URL` en Express | URL del proxy Express que reusa `local_grupomakro_sync_financial_status`. |

Variables de entorno en Express (definidas en `rest_express/server.js`):

| Variable | Default | Notas |
|---|---|---|
| `MOODLE_FINANCIAL_WEBHOOK_URL` | `${MOODLE_URL}/local/grupomakro_core/pages/financial_webhook.php` | Endpoint receptor en Moodle. |
| `MOODLE_FINANCIAL_WEBHOOK_SECRET` | `ODOO_PAYMENT_WEBHOOK_SECRET` | HMAC compartido. |

## Deploy / verificación post-deploy

Tras hacer push local + pull SSH en Moodle:

1. Visitar `/local/grupomakro_core/pages/financial_health.php?token=...`
   y confirmar que `health: "ok"` y `gmk_financial_status.fresh_lt_6h` cubre
   la mayoría de los usuarios.
2. Visitar `/local/grupomakro_core/pages/financial_webhook_dlq.php` y
   confirmar que no hay pendientes.
3. Esperar un pago real en Odoo (o forzar uno desde la UI Odoo) y
   verificar que:
   - Express log muestra `[MOODLE_PUSH] ok vat=... reason=invoice_paid`.
   - Moodle `moodledata/local_grupomakro_core/financial_webhook.log` tiene
     línea `OK userid=... vat=... updated=1`.
4. Esperar 60s y refrescar el academicpanel; las 3 cards deben mostrar
   el cambio.

Si algo falla, los logs clave son:

- Moodle: `moodledata/local_grupomakro_core/financial_webhook.log`
- Express: stdout del proceso (`pm2 logs rest_express` o
  `sudo docker logs odoo-odoo-1` si corre dentro del contenedor
  de Odoo, según tu deploy actual).
- Odoo: módulo `moodle_invoice_payment_webhook`,
  `moodle.invoice.payment.webhook.event` con `state='error'`.

## Rollback

Cada componente es reversible sin tocar BD (más allá del upgrade que
añade la tabla `gmk_financial_webhook_dlq`, que es inerte hasta que
empiecen a llegar webhooks):

| Componente | Rollback |
|---|---|
| Hook `user_loggedin` | Comentar la línea `self::maybe_refresh_financial_status($userid);` en `classes/event/user_login_handler.php`. |
| Endpoint `financial_webhook.php` | Bloquear el path en nginx o cambiar el secret. |
| WS `get_financial_counts` | Comentar el registro en `db/services.php`. |
| Modificaciones Express | Revertir `git checkout HEAD~1 -- server.js` y reiniciar el proceso. |
| Tabla `gmk_financial_webhook_dlq` | DROP TABLE (no afecta otras tablas). |
| Cron 6h | Cambiar schedule en `db/tasks.php` de vuelta a `minute='30', hour='2'`. |