# Dashboard administrativo: "Mis pendientes"

Estado: **aprobado por Ramiro el 19-nov-2026** ("OK al plan, usa tus recomendaciones en las 7 decisiones") e
**implementado** en la rama `feat/dashboard-administrativo` (versión 20261001129). Falta probarlo contra una base real
(autotest en staging, con OK) y publicarlo. Plan de pruebas: `PRUEBAS_DASHBOARD_ADMINISTRATIVO.md`.

Decisiones tomadas (§7):

1. Las 12 fuentes.
2. Horario de lunes a viernes de 08:00 a 17:00 y sábado de 08:00 a 12:00.
3. Los ANS de la tabla §2.2.
4. Los supervisores de esa misma tabla.
5. La primera pasada de alertas va en silencio.
6. Los administradores del sitio **no** se redirigen.
7. Las alertas van solo como notificación web; el correo queda a elección de cada persona.

Pedido de Ramiro: *"un dashboard donde caigan todos los administrativos indicándoles qué tienen pendiente, si cartas,
tickets, planificación de próximo periodo, gestión de eventos, todo en una sola página con los accesos directos a cada
cosa, que ese sea el home cuando accedan, que maneje los ANS de cada cosa y genere las respectivas alertas".*

---

## 1. Diagnóstico

### 1.1 Lo que se encontró

- **Solo la mesa de ayuda tiene responsable, ANS y alertas al personal.** Retiros, cartas, revalidas, bloqueos por
  inasistencia, clases sin cerrar, bienestar y psicología **no avisan a nadie del personal** ni tienen fecha límite.
  Hoy, si un retiro lleva dos semanas en `solicitada`, nadie se entera.
- **Casi ninguna bandeja tiene responsable con nombre.** La cola es del rol (por capacidad). Las excepciones son los
  tickets (`assignedto`) y las citas de psicología (`psychologist_userid`). Por eso "lo mío" será casi siempre
  "la cola de mi rol".
- **Faltan índices** en las columnas que el dashboard tiene que filtrar:
  - `gmk_class` solo tiene la clave primaria: no hay índice en `closed`, `approved`, `enddate` ni `periodid`.
  - `gmk_revalidations` no tiene índice por `status`.
  - `gmk_wellness_psy_appts` no tiene índice por `psychologist_userid`.
- **Las tablas de cartas no están en `install.xml`** (solo en `db/upgrade.php:1676`). Una instalación nueva no las
  crea. No es de este trabajo, pero queda anotado (§8).
- **Las alertas académicas (`gmk_academic_alerts`) no tienen flujo.** Ningún código las pasa a `acknowledged` o
  `resolved` y no tienen página propia. Contarlas hoy solo mostraría un número que crece sin parar.
- **El home de los administrativos ya pasa por un solo punto**: `local_grupomakro_core_get_staff_landing_url()`
  (`lib.php:128`). Lo usan el observer de login (`classes/event/user_login_handler.php:75-86`),
  `user_home_redirect` y `extend_navigation`. Estudiantes y docentes no tienen ninguna de esas capacidades, así que
  siguen su propio camino.

### 1.2 Inventario de fuentes de pendientes

"Dueño" es quien actúa. "Supervisor" es a quien se escala.

| # | Fuente | Qué cuenta como pendiente | Reloj del ANS (desde cuándo) | Dueño (capacidad → roles) | Página destino | Hoy |
|---|---|---|---|---|---|---|
| 1 | **Tickets** (`gmk_tkt_ticket`) | `nuevo`, `asignado`, `reabierto` | El propio de la mesa (`waiting_since`, `first_response_due`) | `assignedto`; cola del área (`support_*` → soporte TI, bienestar, gerencia) | `support_desk.php` | ANS, semáforo y escalamiento propios |
| 2 | **Cartas** (`gmk_letter_request`) | `pendiente_gestion` (por elaborar); `pendiente_recoleccion` (lista, sin entregar); `pagada` o `solicitada` atascadas (en un flujo normal pasan solas) | Último cambio de estado (`timemodified` / último `gmk_letter_request_event`) | `managerequests` → secretaría académica, registros académicos | `letterrequests.php` | Sin ANS; solo avisos al estudiante |
| 3 | **Retiros RET-01** (`gmk_wdr`) | `solicitada`, `pendiente_firma_presencial`, `firmada_digital` (falta sello), `recibida_direccion_academica` / `recibida_direccion_administrativa` (falta procesar) | `timecreated` y, en cada paso, su sello (`received_da_at`, `received_admin_at`) | `manage_wdr_requests` → dir. académico, secretaría, registros, dir. general | `withdrawal_requests.php` | Sin ANS ni avisos |
| 4 | **Planificación del próximo periodo** (`gmk_academic_periods.configsettings`, `gmk_class`) | Lista de control del primer periodo con `startdate > hoy`: sin propuesta, sin borrador, sin publicar, publicación a medias (`board_published.pending`), clases sin aprobar, sin docente, sin horario, escenarios con fecha efectiva sin aplicar, sin calendario académico | **Fecha límite fija**: inicio del periodo menos N días hábiles | `manage_academic_planning` → dir. académico, secretaría, registros | `academic_planning.php` | Sin ANS |
| 5 | **Clases sin aprobar** (`gmk_class`) | `approved=0 AND closed=0`, con inicio próximo | Fecha límite: `initdate` menos N días hábiles | `manage_schedules` | `scheduleapproval.php` | Sin ANS |
| 6 | **Clases sin cerrar** (`gmk_class`) | `closed=0`, `enddate` vencido hace más de 7 días: la tarea `close_expired_schedules` no pudo cerrarlas (pesos ≠ 100 % o asistencia sin marcar) | `enddate` | `manage_courses` → dir. académico, secretaría, registros | `manage_courses.php` | Solo aviso al docente por los pesos |
| 7 | **Revalidas** (`gmk_revalidations`) | `scheduled` con `sessionend` pasado y sin nota (la pone el docente; el personal le da seguimiento); `invoice_id` vacío (factura fallida, se reintenta) | `sessionend` / `timecreated` | `view_revalidations_dashboard` → dir. académico, secretaría, registros | `revalidations_director.php` | Avisos solo al estudiante |
| 8 | **Bloqueos por inasistencia** (`gmk_class_absence_state`) | `alert_level=3`, `blocked_at` lleno y `unblocked_at` vacío | `blocked_at` | `viewabsencedashboard` → dir. académico, secretaría, registros, bienestar | `absence_dashboard.php` | Avisos solo al estudiante |
| 9 | **Eventos de Bienestar** (`gmk_wellness_event`, `_registration`) | Evento terminado con inscritos aún en `confirmada` (falta pasar asistencia); evento próximo con lista de espera | `enddate` del evento / fecha del evento | `manage_wellness` → bienestar | `wellness_dashboard.php` | Sin ANS |
| 10 | **Citas de psicología** (`gmk_wellness_psy_appts`) | `pendiente` (falta confirmar); `confirmada`/`modificada` con fecha pasada (falta marcar atendida o no asistió) | `timecreated` / `appointment_at` | `psychologist_userid`; cola: `manage_psychology_appointments` → psicólogo, bienestar | `wellness_psychology_panel.php` | Aviso al crear; sin ANS |
| 11 | **Módulos pagados sin matricular** (`gmk_module_invoice_requests`) | `status='paid'` sin `enrolled_classid` | `paid` (momento del pago) | `manage_modules` | `module_management.php` | Sin ANS |
| 12 | **Webhooks financieros fallidos** (`gmk_financial_webhook_dlq`) | `state='pending'` | `timecreated` | `manage_financial_webhooks` → soporte TI | `financial_webhook_dlq.php` | Sin avisos |

**Se descartan para la v1** (sin estado de pendiente o sin flujo):
- Alertas académicas: no tienen flujo de acuse (§8).
- Diplomas por emitir: es un cálculo pesado (`list_eligible_graduands`); mejor en fase 2.
- Evaluación docente con promedio bajo: es un indicador, no un pendiente. Puede ir como aviso informativo en fase 2.
- Carnés y convenios por vencer: igual que la anterior.
- Bitácoras sin cola: suspensiones, movimientos, prórrogas, homologación, aplazamientos, disponibilidad.

---

## 2. Propuesta

### 2.1 Idea general

Un **registro de fuentes**: cada bandeja aporta una clase pequeña que sabe contar y listar sus pendientes. El dashboard,
la tarea de alertas y el resumen diario recorren ese registro. Agregar una bandeja nueva es escribir una clase.

```
classes/local/dashboard/
  source.php            interfaz: key(), label(), applies_to($userid), summary($userid,$scope), items($userid,$scope,$limit)
  registry.php          lista de fuentes activas
  sla.php               calcula vencimiento y semáforo (usa support\business_hours)
  config.php            ANS por tipo, horario, supervisores, interruptores (config_plugins, prefijo dash_)
  dashboard.php         arma la respuesta de la página, con caché
  escalation.php        alertas escalonadas sin duplicar
  sources/tickets.php, letters.php, withdrawals.php, planning.php, class_approval.php, class_closing.php,
          revalidas.php, absence_blocks.php, wellness_events.php, psychology.php, modules_paid.php, webhook_dlq.php
```

Cada pendiente se normaliza así:

```
{ source, type, ref, title, subtitle, since, due, level, owner_userid|null, url }
```

- `type` es el tipo de pendiente dentro de la fuente (por ejemplo `letters.gestion` o `letters.recoleccion`). El ANS
  se configura por tipo.
- `level` es el semáforo: 0 verde, 1 amarillo (próximo a vencer), 2 rojo (vencido), 3 crítico (vencido con margen,
  ya va a gerencia).
- **Privacidad:** las fuentes de psicología y de bienestar no ponen nombres de estudiantes en `title` para nadie que
  no sea su dueño. En la vista de equipo se ven solo conteos.

### 2.2 ANS en horas hábiles

- Se reutiliza `classes/local/support/business_hours` tal cual: `elapsed()` y `add_minutes()` reciben un objeto con
  `->hours`.
- El dashboard tiene su propio horario administrativo (`dash_hours`, mismo JSON que las áreas de la mesa) y los
  feriados de `gmk_holidays`. Propuesta: lunes a viernes de 08:00 a 17:00 y sábado de 08:00 a 12:00. **Lo confirma
  Ramiro.**
- Hay dos clases de ANS:
  - **Por antigüedad**: vence a `since` + X horas hábiles (cartas, retiros, bloqueos, citas, etc.).
  - **Por fecha límite**: vence N días hábiles antes de una fecha (planificación y aprobación de clases antes del
    inicio).
- Semáforo por tipo: amarillo al consumir el `dash_warn_pct` % del ANS (por defecto 75 %), rojo al vencer, crítico al
  pasar del `dash_crit_pct` % (por defecto 200 %, es decir, el doble del ANS).
- **Los tickets no se recalculan.** Se lee el nivel que ya pone la mesa (`policy::level_for`) para no tener dos
  semáforos distintos del mismo ticket.

**ANS propuestos por defecto** (horas hábiles salvo que diga días; todo editable en la pantalla de configuración):

| Tipo | Amarillo | Vence | Crítico (gerencia) | Supervisor propuesto |
|---|---|---|---|---|
| Cartas: elaborar (`pendiente_gestion`) | 12 h | 16 h (2 días) | 32 h | Secretaría académica |
| Cartas: atascada en `pagada`/`solicitada` | 3 h | 4 h | 8 h | Soporte TI (es una falla) |
| Cartas: sin recoger | 5 días | 10 días | — (sin gerencia) | Secretaría académica |
| Retiros: sin recibir | 12 h | 16 h | 32 h | Dir. académico |
| Retiros: recibido sin procesar | 18 h | 24 h | 48 h | Dir. académico |
| Planificación próximo periodo | 20 días hábiles antes | 15 días hábiles antes | 10 días hábiles antes | Dir. académico → dir. general |
| Clases sin aprobar | 7 días antes del inicio | 5 días antes | 2 días antes | Dir. académico |
| Clases sin cerrar | 2 días tras el plazo de 7 | 3 días | 10 días | Dir. académico |
| Revalidas sin nota | 1 día | 2 días | 5 días | Dir. académico |
| Revalida con factura fallida | 3 h | 4 h | 16 h | Soporte TI |
| Bloqueo por inasistencia sin resolver | 12 h | 16 h | 40 h | Secretaría académica |
| Evento sin asistencia | 1 día | 2 días | 5 días | Jefe de bienestar |
| Cita de psicología sin confirmar | 4 h | 8 h | 16 h | Jefe de bienestar (solo conteos) |
| Cita pasada sin cerrar | 8 h | 16 h | 40 h | Jefe de bienestar (solo conteos) |
| Módulo pagado sin matricular | 6 h | 8 h | 16 h | Secretaría académica |
| Webhook financiero fallido | 2 h | 4 h | 8 h | Soporte TI → gerencia |

Gerencia = quien tenga el rol `gmk_gerencia` (hoy Joyce Muñoz, u2886), más quien tenga la nueva capacidad
`dashboard_receive_escalations`.

### 2.3 Alertas escalonadas sin duplicar

Mismo patrón que `support\escalation`:

- Tarea nueva `dashboard_escalate`, cada 15 minutos, apagada por defecto (`dash_alerts_enabled=0`).
- Tabla nueva `gmk_dash_alert`: `source`, `ref`, `type`, `level`, `timesent`, `cleared`. Índice único
  `(source, ref, level)`.
  - Un nivel ya avisado no se vuelve a enviar.
  - Si en una pasada el pendiente salta varios niveles, se manda un solo aviso con el nivel más alto.
  - Cuando el pendiente sale de la cola, se borran sus filas. Si vuelve a entrar (por ejemplo, un retiro reabierto),
    se avisa de nuevo.
- Escalera:
  - Nivel 1 (amarillo): al dueño.
  - Nivel 2 (rojo): al dueño y al supervisor.
  - Nivel 3 (crítico): además a gerencia.
- **Un mensaje por persona y pasada**, con la lista agrupada ("3 retiros vencidos, 1 carta por vencer"). No se manda
  uno por pendiente. Así nadie recibe 40 correos.
- Todo se escribe en una transacción con *outbox*, igual que la mesa. Si algo falla, no queda nada a medias.
- **La tarea ignora los tickets**: ya los escala `support_escalate`.
- Nuevos proveedores en `db/messages.php`:
  - `dashboard_sla_warning`: próximo a vencer, al dueño.
  - `dashboard_sla_breach`: vencido, al dueño y al supervisor.
  - `dashboard_escalation`: crítico, a gerencia.
  - `dashboard_daily_digest`: resumen diario.

  Por defecto solo notificación web (`popup`). El correo queda a elección de cada persona en sus preferencias, para no
  cargar el límite de 100 correos al día de Bluehost.
- **Resumen diario opcional** (`dashboard_digest`):
  - Cada hora, envía una vez al día a la hora `dash_digest_hour` (por defecto 7:00) y solo en días hábiles.
  - Va a cada persona con al menos un pendiente.
  - Contenido: conteos por fuente, vencidos y enlace al dashboard.
  - Interruptor general `dash_digest_enabled`, y cada usuario lo puede apagar en sus preferencias de mensajes.

### 2.4 La página

`/local/grupomakro_core/pages/admin_dashboard.php`, con Vue 2 + Vuetify como `SupportDesk.js`. Está pensada primero
para el celular.

- **Encabezado:** "Buenos días, Nombre", con el total de pendientes, cuántos vencidos y la hora de la última
  actualización.
- **Interruptor "Lo mío / Mi equipo":** "Mi equipo" solo aparece a quien tiene `view_admin_dashboard_team`
  (dir. académico, dir. general, gerencia, secretaría académica).
  - Lo mío: lo asignado a mí (tickets, citas) más la cola de mis roles.
  - Mi equipo: la cola de todas las fuentes que superviso, con quién tiene cada cosa cuando hay responsable.
- **Tarjetas por fuente**, ordenadas por urgencia. Primero las que tienen vencidos y, si no hay vencidos, las de mayor
  conteo.
  - Cada tarjeta muestra el ícono y nombre de la fuente, el conteo total, los vencidos en rojo y los próximos a vencer
    en amarillo.
  - Muestra también los 3 pendientes más urgentes con su semáforo y "vence en 3 h" o "vencido hace 2 días".
  - Botón "Abrir bandeja" que lleva a la página de esa fuente, con el filtro aplicado si la página lo acepta.
- Las fuentes sin pendientes se pliegan en una línea: "Al día: cartas, retiros, módulos".
- En una columna en el celular, en dos o tres columnas en pantallas anchas.
- **Accesos rápidos** al pie: los enlaces del menú administrativo que el usuario puede abrir, con el mismo filtro por
  capacidad de `extend_navigation`.
- Botón actualizar. Se refresca solo cada 2 minutos mientras la pestaña está visible.

**Rendimiento:**
- Un solo servicio web, `local_grupomakro_dashboard_get(scope)`, que devuelve todas las tarjetas.
- Cada fuente hace como máximo 2 consultas (un `COUNT` agrupado por nivel y un `SELECT` con `LIMIT 3` ordenado por
  antigüedad), todas por columnas indexadas.
- Caché MUC de aplicación `admindash`, con clave usuario + vista, de 60 segundos. Planificación tiene su propia caché
  de 10 minutos, porque lee el JSON del periodo.
- **Índices nuevos** en el paso de upgrade:
  - `gmk_class (closed, enddate)`, `(approved, closed)` y `(periodid)`.
  - `gmk_revalidations (status, sessionend)`.
  - `gmk_wellness_psy_appts (psychologist_userid, status)`.
  - `gmk_wdr` y `gmk_letter_request` ya tienen índice por `status`.
- Meta: menos de 300 ms en el servidor sin caché, para un usuario con todas las fuentes. El autotest lo mide.

### 2.5 Que sea el home

- Capacidad nueva `local/grupomakro_core:view_admin_dashboard`. Se asigna a: dir. académico, secretaría académica,
  registros académicos, soporte TI, bienestar, psicólogo, gerencia, dir. general y el rol heredado `administrative`.
- En `get_staff_landing_url()` se agrega primero
  `'view_admin_dashboard' => 'admin_dashboard.php'`, **solo si `dash_home_enabled=1`** (apagado al desplegar).
  Así se publica primero, se prueba y luego se enciende con un clic.
- Como el observer de login, `user_home_redirect` y `extend_navigation` usan esa misma función, el cambio queda en un
  solo lugar.
- **Estudiantes y docentes no cambian**: no tienen la capacidad y siguen al LXP, `teacher_dashboard.php` o
  `inactive_teacher_dashboard.php`, como hoy.
- Entrada "🏠 Mis pendientes" al principio del menú administrativo.
- `academicpanel.php` sigue igual. El LXP lo tiene escrito a mano y su reenvío actual lo seguirá cubriendo.
- **Ojo, ya pasa hoy y no se cambia sin que Ramiro lo decida:**
  - El observer de login sí redirige a los administradores del sitio (en `lib.php` se excluyen). Con el cambio,
    Ramiro aterrizaría en el dashboard al entrar.
  - Un administrativo que también es docente aterriza como administrativo. Desde el dashboard tendrá un acceso a su
    panel docente.

### 2.6 Configuración

Página `pages/dashboard_settings.php` (capacidad `dashboard_manage`: gerencia y administradores del sitio):

- Interruptores: dashboard como home, alertas y resumen diario.
- Horario administrativo y hora del resumen.
- Tabla de ANS por tipo (amarillo, vence y crítico) con su supervisor (rol o usuario).
- Activar o desactivar cada fuente.

Se guarda en `config_plugins` con prefijo `dash_`, como la mesa con `tkt_`.

---

## 3. Coordinación con la sesión de cartas

La sesión "Cartas: vincular con tickets, optimizar y alertas" va a dejar una función de pendientes por usuario o rol
con su ANS. En su rama todavía no hay nada (`docs/cartas-optimizacion` está en `2bb26bac`, sin commits). Se le propone
este contrato, para que el dashboard la consuma sin duplicar reglas:

```php
\local_grupomakro_core\local\letters\manager::get_pending_for_staff(int $userid, string $scope = 'mine', int $limit = 3): array
// return ['counts' => ['total'=>n,'warning'=>n,'breached'=>n,'critical'=>n],
//         'items'  => [ {type, ref, title, subtitle, since, due, level, owner_userid, url}, ... ]]
```

**Acordado con la sesión de cartas (19-nov-2026)**, según su plan `PLAN_CARTAS_OPTIMIZACION.md` §4.3:

- La firma se acepta tal cual.
- **Alcance:**
  - `mine`: cartas con `assignedto = $userid`. Es una columna nueva de su rama.
  - `team`: todas las cartas abiertas, si el usuario tiene `managerequests`. Incluye las que no tienen responsable
    (`owner_userid = null`). Si no tiene esa capacidad, devuelve `[]`.
- **Nivel:** lo guarda la carta en `sla_level`, con los mismos cortes de este plan (75 %, 100 % y 200 %).
  - Lo calcula su tarea `letters_sla` cada 15 minutos.
  - **El dashboard lo lee; no lo recalcula.**
- **Tipos:**
  - `elaborar`: estado `pendiente_gestion`.
  - `sin_recoger`: estado `pendiente_recoleccion`.
  - `atascada`: estados `pagada` o `solicitada`.
  - `pendiente_pago` no entra, porque espera al estudiante.
- **Referencia y enlace:** `ref = 'CARTA-<id>'`; `url` apunta a la bandeja nueva `pages/letter_desk.php?request=<id>`.
  El orden es `level DESC, due ASC`.
- **Las alertas de cartas las envía su tarea `letters_sla`**: responsable, luego supervisores y luego gerencia. La
  tarea del dashboard **ignora las cartas**, igual que los tickets. Por eso los ANS de cartas de la tabla §2.2 se
  configuran en su pantalla, no en la del dashboard.
- **Respaldo:** si su rama no está en master cuando se implemente este dashboard, `sources/letters.php` cuenta por
  `status` + `timemodified`, sin alertas. Cuando su rama entre, pasa a envolver su función.

---

## 4. Fases

**Fase 1: página y home** (sin enviar nada):
- Registro de fuentes y las 12 fuentes.
- Cálculo de ANS y semáforo, caché e índices.
- Página para el celular y servicio web.
- Capacidades y entrada de menú.
- Home detrás de `dash_home_enabled`.
- Pantalla de configuración.

**Fase 2: alertas:**
- Tabla `gmk_dash_alert` y tarea `dashboard_escalate`.
- Proveedores de mensajes y resumen diario.
- Todo apagado al desplegar.

Las dos fases van en la misma rama, en commits separados. Se pueden publicar juntas o primero la 1.

**Fase 3 (opcional, con su propio OK):**
- Píldora "Pendientes: 5 (2 vencidos)" en el indicador flotante.
- Flujo de acuse para alertas académicas.
- Diplomas por emitir.
- Evaluación docente baja como aviso.

---

## 5. Pruebas (al implementar)

- **`cli/dashboard_selftest.php`**: crea datos de prueba en una transacción y al final hace rollback.
  - Datos: un retiro, una carta, una clase vencida, una cita y un evento. Los relojes se mueven para dejar cada uno en
    verde, amarillo, rojo y crítico.
  - Comprueba conteos y niveles por fuente, la vista "lo mío" frente a "equipo" y la privacidad de psicología (sin
    nombres en la vista de equipo).
  - Comprueba la escalera sin duplicados: dos pasadas seguidas no envían nada nuevo, y un pendiente que sale y vuelve
    se avisa de nuevo.
  - Usa un *message sink*: no se envía nada real.
  - Mide el tiempo por fuente.
- **Arnés PHP aislado** del cálculo de ANS: fecha límite frente a antigüedad, fines de semana, feriados y
  porcentajes.
- **Pantalla a 375 px** con datos simulados: sin desborde horizontal, sin errores de consola y con modo "equipo".
- **Home:** un usuario de cada rol aterriza en el dashboard con el interruptor encendido. Estudiante, docente activo y
  docente inactivo no cambian. Con el interruptor apagado, todo queda como hoy.
- Plan detallado en `docs/planning/PRUEBAS_DASHBOARD_ADMINISTRATIVO.md`.
- Staging es solo CLI: allí se corre el autotest, con OK de Ramiro.

---

## 6. Riesgos

- **Versión:** el dashboard usa 20261001129 (ya aplicado en staging) y entra primero a master. La rama de cartas (`feat/cartas-tickets-ans`) se rebasa sobre esta y pasa a 20261001130. El número se decide justo
  antes del commit final, mirando `origin/master`.
- **Índices en `gmk_class`:** es una tabla grande. Agregar 3 índices en el upgrade tarda unos segundos y bloquea la
  tabla. Hay que desplegar fuera de horario (memoria: no cancelar en pleno upgrade).
- **Ruido de alertas:** el primer arranque encontrará pendientes viejos ya vencidos. Igual que en la mesa, subirán
  directo a su nivel en un solo aviso combinado. Se puede hacer una primera pasada "en silencio" que marque los niveles
  sin enviar. **Lo decide Ramiro.**
- **Pendientes que son de otros:** las revalidas sin nota son del docente. El dashboard las muestra como seguimiento,
  y la alerta de nivel 1 va al docente solo si Ramiro lo quiere.

---

## 7. Decisiones que necesita Ramiro

1. **OK al plan** y a las 12 fuentes de la v1, o cuáles quitar o agregar.
2. **Horario administrativo** para los ANS (propuesta: L–V 08:00–17:00 y sábado 08:00–12:00).
3. **ANS por defecto** de la tabla §2.2 (se pueden ajustar después en la pantalla).
4. **Supervisores** de cada fuente (columna "Supervisor propuesto").
5. **Primera pasada de alertas en silencio** (recomendado) o avisando de todo lo viejo.
6. **Administradores del sitio:** ¿también aterrizan en el dashboard o se quedan como hoy?
7. **Correo:** solo notificación web por defecto (recomendado, por el límite de Bluehost) o también correo.

---

## 8. Fuera de alcance (anotado)

- Tablas de cartas ausentes en `install.xml` (`gmk_letter_request`, `_event`, `_document`, `_type`, ...) y
  `gmk_wellness_teacher_eval`.
- El observer de login escribe `redirect_debug.log` en el `dirroot` en cada login
  (`classes/event/user_login_handler.php:48`).
- Las acciones de retiro (`wdr_manager::admin_update`) no validan el orden de los estados.
- La lógica de home está repetida en tres sitios (observer, `user_home_redirect`, `extend_navigation`).
