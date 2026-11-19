# Pruebas: dashboard administrativo "Mis pendientes"

Rama `feat/dashboard-administrativo` (versión 20261001129). Plan: `PLAN_DASHBOARD_ADMINISTRATIVO.md`.

## 1. Ya verificado (local, sin base real)

| Prueba | Resultado |
|---|---|
| `php -l` de todo lo nuevo y lo tocado (PHP 8.2, sin sintaxis exclusiva de 8.x: corre en 7.4) | limpio |
| `node --check js/components/AdminDashboard.js` | limpio |
| `db/install.xml` es XML válido | sí |
| Arnés PHP aislado del ANS (`business_hours` + `dashboard\config` + `dashboard\sla`, sin Moodle): horas hábiles 12/16/32, vencimiento exacto, sábado medio día, feriado, días hábiles 1/2/5, domingo no cuenta, fecha límite "antes" 7/5/2 y vencida, crítico −1, tipo desconocido, since futuro, umbrales incoherentes (normal y estricto), limpieza de supervisores, tipo apagado, horario inválido | 30/30 |
| Pantalla con datos simulados a 375 px: sin desborde horizontal (scrollWidth = 375), tarjetas ordenadas por urgencia, "Ver más urgentes", tarjeta con error, "1000+" truncado, bloque "Al día", cambio a "Mi equipo" (pide `scope=team` y muestra responsables), sin errores de consola | OK |
| Escritorio 1366 px: 3 columnas | OK |

## 2. Autotest contra una base real (necesita OK de Ramiro)

`cli/dashboard_selftest.php --staff=<administrativo> [--student=<estudiante de prueba>]`

- Corre todo dentro de **una transacción que siempre se revierte**. No envía mensajes: la escalera y el resumen se
  corren con `$send = false`.
- Al final vacía la caché de configuración para que no queden valores de prueba en memoria.
- Revisa:
  1. **ANS** con la base real (feriados de `gmk_holidays`).
  2. **Fuentes** con datos de prueba: retiro, webhook fallido, módulo pagado y cita de psicología. La cita no debe
     mostrar el nombre del estudiante ni el motivo.
  3. **Lo mío y mi equipo:** la cita de otro psicólogo no aparece en "lo mío"; el retiro sin responsable sí aparece en
     "lo mío".
  4. **Escalera:**
     - Con las alertas apagadas no hace nada.
     - La primera pasada no envía nada y registra los niveles.
     - La segunda pasada no envía nada.
     - Cuando un pendiente sale de la cola se borran sus niveles; cuando vuelve, se avisa de nuevo.
     - Un pendiente crítico se avisa a la cola del rol, a los supervisores y a gerencia.
     - Cada persona recibe un solo mensaje por pasada, sin filas duplicadas.
  5. **Resumen diario:** sale una sola vez por día y solo en día hábil.
  6. **Tiempo de armado** del dashboard del usuario `--staff` en las dos vistas, por debajo de 1500 ms, sin fuentes
     con error, con el conteo por fuente.

Comandos, con OK y en este orden:

```
# staging (solo CLI): desplegar la rama en /opt/moodle_staging/moodle, luego
sudo -u www-data php /opt/moodle_staging/moodle/admin/cli/upgrade.php --non-interactive
sudo -u www-data php /opt/moodle_staging/moodle/local/grupomakro_core/cli/dashboard_selftest.php --staff=<id> --student=<id>
```

### Resultado en staging (19-nov-2026, con OK de Ramiro)

**Despliegue:**
- Archivos de la rama (commit `a1884f1b`) copiados a `/opt/moodle_staging/moodle/local/grupomakro_core`.
- Antes de copiar se comprobó que los 12 archivos modificados eran idénticos a master en staging.
- Respaldo en `/opt/backups/dash_staging_20261119_1629`: archivos anteriores, tablas de capacidades, config,
  proveedores, tareas y funciones, y esquemas de las 4 tablas que reciben índices.

**Upgrade 127 → 129:** 4,5 s. Quedaron creadas la tabla, los 6 índices y las capacidades: gerencia (u2886) tiene vista
y equipo, y 9 usuarios tienen `view_admin_dashboard`. Los interruptores están apagados.

**Autotest:** 47/47 con `--staff=2886` (gerencia) y 47/47 con `--staff=2732` (secretaría académica). Todo revertido:
0 filas en `gmk_dash_alert`, `dash_alerts_primed` sin definir.
- Primera pasada: 877 pendientes, 874 niveles registrados en silencio. La segunda pasada no registró nada nuevo.
- Pendiente crítico que vuelve a la cola: 5 mensajes (cola de 4 personas + 1 supervisor + gerencia, sin duplicados).
- Armado del dashboard: 163–297 ms.

**Lo que muestran los datos de staging** (copia de producción del 12-nov):

| Fuente | Pendientes | Críticos |
|---|---|---|
| Pagos de Odoo sin aplicar | 439 | 438 (el más reciente del 21-sep) |
| Bloqueos por inasistencia | 387 | 387 |
| Cartas | 29 | 29 |
| Clases sin cerrar | 11 | 6 |
| Retiros | 5 | 5 |
| Clases sin aprobar | 3 | 3 |
| Citas de psicología | 2 | — |
| Módulos pagados sin matricular | 1 | — |

Planificación no muestra nada porque staging no tiene un periodo futuro. Con estos volúmenes conviene decidir antes de
encender el inicio: depurar la cola de Odoo y decidir qué hacer con los bloqueos por inasistencia, o apagar esas dos
fuentes.

## 3. Después de publicar en producción (todo llega apagado)

1. **Upgrade fuera de horario.** Agrega 3 índices a `gmk_class` y 3 a otras tablas. No hay que cancelarlo a mitad.
2. **Pantalla de cada rol:** abrir `/local/grupomakro_core/pages/admin_dashboard.php` con un usuario de cada uno
   (secretaría, registros, dirección académica, soporte TI, bienestar, psicología, gerencia).
   - Cada tarjeta lleva a su bandeja.
   - "Mi equipo" solo aparece para dirección académica, secretaría académica, dirección general y gerencia.
   - Desde el celular: sin desborde y con botones de 44 px.
3. **Encender el inicio** en "Mis pendientes → Configuración" (marcar "página de inicio").
   - Un administrativo que entra a `/` o `/my/` aterriza en Mis pendientes. Si llega con un enlace directo, se respeta
     el enlace.
   - El administrador del sitio sigue como antes.
   - Estudiante: sigue al LXP. Docente activo: `teacher_dashboard.php`. Docente inactivo:
     `inactive_teacher_dashboard.php`.
   - Si se apaga el interruptor, todo vuelve a como estaba.
4. **Encender las alertas.**
   - La primera pasada (15 min) no envía nada; en la configuración se ve "Primera pasada en silencio: ya hecha".
   - A partir de ahí llegan avisos solo de lo que cambia de nivel. Llegan como notificación web; el correo solo si
     cada persona lo enciende en sus preferencias.
5. **Encender el resumen diario.** Al día hábil siguiente, a la hora configurada, cada administrativo con pendientes
   recibe un aviso.

## 4. Cómo volver atrás

- **Inicio:** desmarcar "página de inicio".
- **Alertas y resumen:** desmarcar los interruptores. Las tareas no hacen nada con el interruptor apagado.
- **Una fuente:** desmarcarla en "Fuentes".
- **El código:** revertir el merge. La tabla `gmk_dash_alert`, los índices y las capacidades no estorban a nada si se
  quedan.

## 5. Riesgos conocidos

- **Número de versión:** acordado el 19-nov. El dashboard queda en 20261001129 (ya aplicado en staging) y entra
  **primero**. La rama de cartas se rebasa sobre esta y pasa a 20261001130.
- **Bloqueos por inasistencia:** puede haber muchos bloqueos legítimos que nadie va a "resolver". Si generan ruido, se
  apaga el tipo `absence.block` o la fuente.
- **Revalidas sin nota:** la nota la pone el docente. El aviso de nivel 1 va a la cola del rol (seguimiento), no al
  docente.
- **El rol `manager` (arquetipo) también recibe `view_admin_dashboard`.** Si alguien tiene ese rol y no es
  administrador del sitio, aterriza en el dashboard cuando se enciende el inicio.
