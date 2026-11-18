# Pruebas: mesa de ayuda, fase 2 ("que nadie se quede sin atención")

Rama del plugin: `feat/tickets-soporte-fase2` (versión `20261001122`, sale de master `713b17e1`). La 20261001121 ya está en staging (autotest 71/71); la 20261001122 agrega el horario por agente y la reasignación (§9).
Rama del portal: `feat/tickets-soporte-fase2` sobre `isi-prod` `23bbfd0` (repo `isi_moodle_lxp`).

Staging (`/opt/moodle_staging`, base `isidb_staging`) se trata como producción: **cada paso marcado ✋ escribe y necesita el OK explícito de Ramiro**. Staging es solo CLI: la lógica se prueba ahí con el autotest; las pantallas (indicador flotante, bloqueo, portal) necesitan un Moodle con navegador (producción con usuarios de prueba, o local).

## Qué entra en la fase 2

| Pieza | Dónde |
|---|---|
| Semáforo en minutos hábiles por área (horario de `gmk_tkt_area.hours` + feriados de `gmk_holidays`) | `business_hours::elapsed()` / `add_minutes()`, `policy` |
| Pestaña **Vencidos** = rojo en minutos hábiles (antes: reloj) | `manager::desk_list()` / `overdue_ids()` |
| Escalera cada 5 min: 20 min agente + mensaje al estudiante · 1 h supervisores (+ asignar si está en cola) · 3 h supervisores + prioridad alta + vuelve a la cola si el agente está ausente · 5 h gerencia, repite cada 2 h | tarea `support_escalate`, `escalation::process_ticket()` |
| Sin acuse en 5 min hábiles → respaldo (o supervisores si no hay) + administradores | `escalation::check_ack()`, proveedor `support_noack` |
| Resumen diario a gerencia y supervisores (7:00) | tarea `support_digest` (cada hora, envía una vez al día) |
| Recordatorio de 12 h al solicitante (verificado: **no existía** en la fase 1; ahora lo manda `support_autoclose` antes del cierre de 24 h) | `manager::remind_stale()` |
| Tope de 1 correo cada 10 min por ticket y persona (la campanita sigue inmediata) | `manager::mail_gate()`, tabla `gmk_tkt_mailgate`, proveedor `support_live` sin correo |
| Indicador flotante en todas las páginas de Moodle (solo personal de soporte) | `local_grupomakro_core_before_footer()`, `js/support_badge.js`, WS `support_get_badge` (caché 20 s) |
| Modo incisivo capas 1–3 con acuse obligatorio y perfiles | `badge.php`, `policy::incisive_profile()`, tabla `gmk_tkt_ack`, WS `support_snooze` |
| Botón **Ayuda TI** para docentes | `pages/support_my.php`, `js/components/SupportRequester.js`, `audience=teacher` |
| Portal: aviso persistente de respuesta, distintivo en el encabezado, título parpadeante, botón Ayuda en cursos, calificación | `SupportReplyAlert.vue`, `SupportRatingDialog.vue`, `SupportFab compact`, `store/support.js` |

Parámetros nuevos (pestaña **Parámetros** de `support_settings.php`): `tkt_escalation_enabled`, `tkt_ack_minutes` (5), `tkt_ack_admins` (1), `tkt_mail_gap` (10), `tkt_badge_poll` (30), `tkt_snooze_minutes` (5), `tkt_snooze_max` (2), `tkt_rating_days` (7), `tkt_digest_enabled` (1). La escalera y los perfiles incisivos se editan en la pestaña nueva **Avisos y escalamiento**. Nada se guarda en `config_plugins` al instalar: mientras nadie los cambie, rigen los valores por defecto del código.

## 0. Antes de empezar (solo lectura)

1. Versión instalada: `SELECT value FROM mdl_config_plugins WHERE plugin='local_grupomakro_core' AND name='version';` → `20261001120` (producción) o `20261001121` (staging).
2. Ninguna rama pendiente con número entre `20261001121` y el de esta rama.
3. Feriados que afectan las pruebas: `SELECT FROM_UNIXTIME(date), name FROM mdl_gmk_holidays ORDER BY date DESC LIMIT 20;`
4. Quién recibe qué:
   - Gerencia: `SELECT * FROM mdl_gmk_tkt_person;`
   - Supervisores por capacidad: roles con `local/grupomakro_core:support_supervise` en `mdl_role_capabilities`.
   - Administradores del sitio (`$CFG->siteadmins`): reciben el aviso por falta de acuse si `tkt_ack_admins = 1`.
5. Docentes: confirmar que Soporte Tecnológico tiene "disponible para docentes" (`SELECT code, teacher_visible FROM mdl_gmk_tkt_area;`).

## 1. Despliegue en staging ✋

1. Respaldo: `mysqldump isidb_staging` (o al menos `mdl_gmk_tkt_ticket`, `mdl_config_plugins`, `mdl_message_providers`, `mdl_task_scheduled`).
2. Copiar el plugin de la rama a `/opt/moodle_staging/local/grupomakro_core`.
3. `sudo -u www-data php admin/cli/upgrade.php --non-interactive`.
4. Revisar:
   - `DESCRIBE mdl_gmk_tkt_ticket;` → columnas nuevas `escalated_at`, `assigned_at`, `rating_solved`, `rated_at`, `rating_dismissed`, `rating_dismissed_at` (todas 0 en los tickets existentes).
   - Tablas nuevas `mdl_gmk_tkt_ack` y `mdl_gmk_tkt_mailgate` (vacías).
   - `SELECT name FROM mdl_message_providers WHERE component='local_grupomakro_core' AND name LIKE 'support_%';` → 12 (5 de la fase 1 + 7 nuevos, incluido `support_live`).
   - `SELECT classname, minute, hour FROM mdl_task_scheduled WHERE classname LIKE '%support_%';` → `support_autoclose` (*/15), `support_escalate` (*/5), `support_digest` (5 de cada hora).
   - Los tickets abiertos de la fase 1 tienen `escalation_level = 0`: en la primera pasada de `support_escalate` los que ya llevan mucho tiempo saltan directo a su nivel **con un solo aviso combinado** (no uno por nivel). Si hay tickets viejos abiertos, conviene revisarlos antes (ver §7).

## 2. Autotest con rollback ✋ (corre código en staging, no deja datos)

```bash
cd /opt/moodle_staging/local/grupomakro_core/cli
sudo -u www-data php support_selftest.php --student=<id de un estudiante de prueba>
```

Todo corre en una transacción que siempre se revierte (incluidos el feriado de prueba del 8-ene-2030 y el horario 24/7 temporal del área académica). Además de las ~30 pruebas de la fase 1, cubre:

| # | Prueba |
|---|---|
| 11 | Minutos hábiles: dentro del tramo, antes de abrir, cruce de medianoche, sábado corto + domingo cerrado, dos tramos con almuerzo, cambio de horario entre áreas, área sin horario = reloj, `add_minutes` como inversa, feriado de `gmk_holidays` |
| 12 | Niveles del semáforo según los umbrales configurados |
| 13 | La bandeja exporta minutos hábiles y semáforo `green_warn` a los 25 min |
| 14 | Escalera: 20 min (nivel 1 + mensaje `sla_warning`), idempotencia, 1 h (asigna si estaba en cola), 3 h (prioridad alta + vuelve a la cola con agente ausente), 5 h (gerencia), sin repetición antes de `tkt_red_repeat` y repetición después |
| 15 | **Vencidos** en minutos hábiles (aparece con el área abierta, desaparece si esas horas eran fuera de horario); indicador: contadores, alerta por acusar, **sin texto de mensajes**, acuse al abrir, nada para un estudiante |
| 16 | La respuesta del personal reinicia la escalera; recordatorio de 12 h una sola vez |
| 17 | Sin acuse en 5 min: avisa una vez por alerta; con acuse no avisa; posponer máximo 2 veces |
| 18 | Tope de correo: uno cada `tkt_mail_gap` min por ticket y persona |
| 19 | Calificación: pendiente al cerrar, "Ahora no" (no reaparece el mismo día, sí al siguiente), calificación baja con "no se resolvió", no se califica dos veces |
| 20 | Docentes: catálogo solo con áreas para docentes; un estudiante no abre como docente; un docente abre con `requesterrole=teacher` y enlace a Moodle |

Si hoy es feriado en `gmk_holidays`, el autotest omite 13–18 (el reloj hábil no corre) y lo dice.

## 3. Escalera completa en vivo ✋ (checklist de §12 del plan)

Con un estudiante de prueba y el área de prueba en horario hábil:

1. En **Parámetros** bajar temporalmente los umbrales a `tkt_sla_green=1`, `tkt_sla_yellow=2`, `tkt_sla_orange=3`, `tkt_sla_red=4`, `tkt_red_repeat=2`, `tkt_ack_minutes=1` (anotar los valores originales: 20/60/180/300/120/5).
2. Crear un ticket académico y no responderlo.
3. Correr cada minuto `sudo -u www-data php admin/cli/scheduled_task.php --execute='\local_grupomakro_core\task\support_escalate'` y verificar en el historial del ticket (bandeja → Historial):
   - min 1: "Escalamiento a verde con aviso" + mensaje automático "sigue en cola" en el chat; aviso `support_sla_warning` al agente.
   - min 1–2: "Sin acuse del responsable" → aviso `support_noack` al respaldo (o supervisores) y administradores.
   - min 2: amarillo → `support_sla_breach` a supervisores.
   - min 3: naranja → segunda alerta, prioridad **Alta**.
   - min 4: rojo → `support_escalation` a Joyce; el ticket aparece en **Vencidos**.
   - min 6: "Escalamiento (repetición) a rojo" → otro aviso a gerencia.
4. Responder como agente: la espera vuelve a 0; el siguiente mensaje del estudiante arranca la escalera de nuevo.
5. Restaurar los umbrales originales.

Correos: dentro de un mismo ticket, cada persona recibe como máximo un correo cada 10 min; los demás avisos le llegan solo por campanita (proveedor "avisos en pantalla").

## 4. Indicador flotante y modo incisivo (navegador) ✋

| # | Quién | Esperado |
|---|---|---|
| 1 | Estudiante o docente sin rol de soporte | No ve la píldora (el docente ve solo **Ayuda TI**). |
| 2 | Agente académico (Jean/Lizbeth) | Píldora abajo a la derecha: Disponible, Sin asignar, Míos (nuevos), Vencidos; cada contador abre la bandeja en esa pestaña. |
| 3 | Ticket nuevo asignado | Aviso que no se cierra solo, con **Abrir** y **Tomar**; "Ocultar" lo esconde solo en esa página. Sonido, título "🔴 (1) …" y punto rojo en el favicon. **Sin** bloqueo (perfil académico). |
| 4 | Esteban (TI), ticket nuevo | **Bloqueo a pantalla completa**: no se cierra con Esc ni con clic afuera; "Posponer 5 min" dos veces y luego ya no. Vuelve tras posponer. |
| 5 | Notificación del sistema | La píldora ofrece "Activar avisos"; con permiso, llega una notificación que no desaparece sola (Windows/macOS) con número, área, quién y espera, **sin texto**. Psicología: "Ticket confidencial". |
| 6 | Acuse | El aviso se apaga solo al **abrir** el ticket en la bandeja, **tomarlo** o responder. Mirar la píldora no cuenta. |
| 7 | Ausente o fuera de horario | Sin bloqueo, sin sonido ni notificación: solo la píldora y la campanita. |
| 8 | Supervisor | Botón ▴ con totales por área y el agente con más pendientes; avisos de tickets naranja/rojo. Administrador del sitio: bloqueo solo con naranja o rojo. |
| 9 | Pestaña en segundo plano | Con perfil con sonido o notificaciones, sigue consultando cada 60 s (ver nota); sin ellos, deja de consultar hasta volver a la pestaña. |
| 10 | Configuración | En **Avisos y escalamiento** cambiar el perfil de una persona (por ejemplo, Jean a "Agente de TI") → en ≤ 30 s ve el bloqueo. |

Nota: el plan pide sondeo solo con la pestaña visible (§8.2) pero también sonido y notificación con la pestaña en segundo plano (§8.5 capas 2–3). Para cumplir ambas, en segundo plano se consulta cada 60 s **solo** si el perfil tiene sonido o notificación; el navegador ya limita los temporizadores de pestañas ocultas a ese ritmo. El sonido necesita un primer clic o tecla en la página (política de los navegadores).

## 5. Docentes: Ayuda TI ✋

1. Entrar como docente (rol de profesor en algún curso) → botón **Ayuda TI** en todas las páginas, también en el panel docente.
2. Nueva solicitud: solo aparece Soporte Tecnológico. Enviar.
3. Esteban la ve en su bandeja con "(docente)" en el aviso y la píldora.
4. Esteban responde → el docente ve el aviso persistente "Nueva respuesta" en cualquier página de Moodle, sin botón de cerrar; "Ver respuesta" abre el chat.
5. Resolver → "¿Se resolvió tu caso?" → Sí → diálogo de calificación.

## 6. Portal del estudiante ✋ (con el portal de la rama desplegado)

| # | Paso | Esperado |
|---|---|---|
| 1 | El personal responde | Diálogo persistente sin botón de cerrar "Gestión Académica respondió tu solicitud TKT-…" con **Ver respuesta**; varios tickets → en cola. Distintivo rojo en el botón Ayuda **y** en el encabezado; título "(1) Nueva respuesta" parpadeando. |
| 2 | Ver respuesta | Abre `/soporte?ticket=…`, marca leído y el aviso desaparece sin esperar el siguiente sondeo. |
| 3 | El personal pide información | Diálogo "Te pedimos información" una vez por sesión del navegador hasta que responda o se cierre. |
| 4 | Reproductor de cursos | Botón Ayuda compacto (solo el ícono) que no tapa el contenido; el aviso de respuesta también aparece ahí. |
| 5 | Recordatorio a las 12 h y cierre a las 24 h | Con `tkt_remind_hours`/`tkt_close_hours` bajados a 1 y 2: mensaje "¿Sigues necesitando ayuda?" y luego "cerrada". |
| 6 | Calificación al cerrar | 1–5 estrellas, "¿Te resolvimos?" Sí/No, comentario. "Ahora no" → reaparece una vez al día siguiente y nunca más. 1–2 estrellas → aviso `support_low_rating` a supervisores. |

## 7. Tickets abiertos desde la fase 1

Al desplegar, los tickets abiertos que ya llevan horas esperando entran directo a su nivel en la primera pasada (un aviso combinado por ticket). Si se prefiere no avisar por lo viejo, antes de habilitar la tarea:

```sql
-- solo lectura: cuántos y cuánto llevan
SELECT id, number, status, FROM_UNIXTIME(waiting_since) FROM mdl_gmk_tkt_ticket
 WHERE status NOT IN ('cerrado','cancelado') AND waiting_since > 0 ORDER BY waiting_since;
```

y decidir con Ramiro (✋) si se apaga `tkt_escalation_enabled` un día o se responde primero.

## 8. Limpieza ✋

Restaurar parámetros cambiados (están en la pestaña **Auditoría** con antes y después) y borrar los tickets de prueba o restaurar el respaldo, según prefiera Ramiro.

## 9. Ampliación 20261001122: horario por agente y reasignación ✋

Pedido de Ramiro (18-nov-2026): "las horas ten en cuenta que sean horas hábiles para el agente" y "necesito que el supervisor, gerencia o tecnología puedan reasignar tickets o conversaciones".

### Qué cambia

| Pieza | Comportamiento |
|---|---|
| Horario propio del agente | `gmk_tkt_presence.hours` (JSON igual al del área). Vacío = trabaja el horario del área del ticket; esa es la carga inicial: no se copia nada, así que un cambio de horario del área sigue aplicando a quien no tiene horario propio. Se edita en **Temas y agentes → Horario hábil por agente** y queda en **Auditoría** (`config_agenthours`). |
| Reloj del ticket | Asignado: horas hábiles del agente asignado (semáforo, Vencidos, escalamiento, repetición en rojo y plazo de acuse de 5 min). Sin asignar: horario del área. |
| Modo incisivo | Solo dentro del horario propio del agente (si no tiene, el de sus áreas). Fuera de él: solo píldora y campanita. |
| Reparto automático | Prefiere a los agentes que están en horario; si nadie lo está, el de menos carga como antes. |
| Agente fuera de horario | Si el asignado tiene horario propio, está fuera de él, el área está abierta y el estudiante ya esperó `tkt_ack_minutes` minutos hábiles del área, la tarea de escalamiento pasa el ticket a otro agente del tema en horario o, si no hay, a un respaldo del agente en horario (evento `offhours_handoff`). Si no hay nadie, avisa una vez por espera a los supervisores (`offhours_nohandoff`). Se apaga con `tkt_offhours_handoff = 0`. |
| Recordatorio | El aviso de 20 min al agente usa su reloj (es parte de la escalera). El recordatorio de 12 h al estudiante y el cierre de 24 h siguen en horas de reloj (decisión de la fase 0, "1 día sin respuesta"). |
| Capacidad `support_reassign` | Dada a `gmk_secretaria_academica`, `gmk_director_academico`, `gmk_bienestar`, `gmk_psicologo`, `gmk_gerencia`, `gmk_soporte_ti` y al arquetipo `manager`. Quien la tiene ve la pestaña **Todos** y reasigna cualquier ticket no confidencial. TI no se vuelve supervisor: no recibe las alertas de 1 h y 3 h ni el resumen. |
| Reasignar a otra área | En **Reasignar** se elige el tema destino (cualquier área); la lista de agentes cambia con el tema. Quedan los eventos `category` y `assigned` (how `transfer`) y el mensaje automático nuevo `transferred` ("Pasamos tu solicitud … a {área} ({categoría}). Te atenderá {agente}."). |
| Confidenciales | Solo los reasignan los agentes de Psicología y gerencia; un ticket confidencial nunca se vuelve público al moverlo. |

### Despliegue ✋

1. Respaldo de `mdl_gmk_tkt_presence`, `mdl_gmk_tkt_template`, `mdl_role_capabilities`.
2. Upgrade a `20261001122`. Revisar: `DESCRIBE mdl_gmk_tkt_presence;` (columna `hours`, todo NULL); `SELECT r.shortname FROM mdl_role_capabilities rc JOIN mdl_role r ON r.id = rc.roleid WHERE rc.capability = 'local/grupomakro_core:support_reassign';` → los 6 roles gmk_* más manager; plantilla `transferred` en `mdl_gmk_tkt_template`.
3. Autotest: mismas instrucciones del §2. Pruebas nuevas:

| # | Prueba |
|---|---|
| 21 | Agente con horario propio fuera de horario: el reloj no corre; sin asignar cuenta el área; modo incisivo apagado; sin horario propio hereda el del área; el reparto prefiere a otro agente en horario; con el área abierta y la espera cumplida, el ticket pasa a quien está en horario (o queda el aviso a supervisores) |
| 22 | TI (Esteban 2756, o quien tenga `gmk_soporte_ti`; si no tiene la capacidad se le asigna el rol dentro de la transacción) tiene `support_reassign` y la pestaña Todos sin ser supervisor; reasigna un ticket académico a Jorge (2737); lo pasa a Soporte Tecnológico con mensaje al estudiante; no ve ni reasigna el de Psicología; un agente académico sin la capacidad no reasigna un ticket ajeno (se omite si todos los agentes académicos la tienen, como Secretaría) |

### En pantalla

1. Configuración → Temas y agentes → Horario hábil por agente: poner a Esteban L–V 14:00–20:00, guardar, recargar (se mantiene; Auditoría muestra antes y después). "Usar el del área" lo devuelve a heredar.
2. Con Esteban fuera de su horario: sin bloqueo ni sonido; su ticket no avanza el semáforo; si el área está abierta, a los 5 min hábiles pasa a otro agente de TI en horario o se avisa a supervisores.
3. Como Esteban (TI): pestaña **Todos**, abrir un ticket académico, **Reasignar** a Jorge; luego Reasignar eligiendo otro tema de otra área: el estudiante ve el mensaje automático y el historial muestra el cambio.
4. Como Esteban: un ticket de Psicología no aparece en Todos; como Secretaría tampoco.

## 10. Mobile first en Moodle (bandeja, configuración, indicador y Ayuda TI) ✋

Pedido de Ramiro (18-nov-2026): "Necesito que toda la ux se adapte a mobile first". Estilos base para 360–400 px en `js/components/SupportApi.js` (compartidos por las tres páginas) y el escritorio agregado con `min-width` (600 y 960 px). Revisado en el navegador con datos simulados a 375 px y a 1280 px: sin scroll horizontal.

| Pantalla | Celular (≤ 959 px) | Escritorio |
|---|---|---|
| Bandeja | Lista a ancho completo; pestañas desplazables con el dedo. Al abrir un ticket, el chat ocupa toda la pantalla (respeta el área segura) con flecha de volver; el botón "atrás" del teléfono también vuelve a la lista. Acción principal a la vista (Tomar o Resolver) y el resto en el menú ⋮ (Reasignar, Devolver, Pedir información, Cerrar, Prioridad, Cambiar tema, Historial, Datos del solicitante). Modos del mensaje como chips. | Lista de 420 px y chat al lado, botones en línea, como antes. |
| Configuración | Tablas convertidas en tarjetas apiladas (cada dato con su etiqueta); formularios a una columna; diálogos a pantalla completa. | Tablas normales. |
| Ayuda TI (docentes) | Igual que la bandeja: lista y chat a pantalla completa con volver. | Lista y chat lado a lado. |
| Indicador | Píldora compacta (solo números, 44 px de alto) que respeta el área segura; reserva espacio al final de la página para no tapar contenido; un aviso a la vez con "+N avisos más". El bloqueo apila los botones a lo ancho (52 px). El aviso al docente sale como hoja inferior. | Píldora con etiquetas, hasta 4 avisos, bloqueo con botones en fila. |

Comunes: botones e íconos de al menos 44 px de alto en el celular; campos con letra de 16 px (iOS no hace zoom).

Prueba en un teléfono real (iPhone y Android) con un agente de prueba: abrir la bandeja, abrir un ticket, responder, usar el menú ⋮, volver con el botón del teléfono, abrir la configuración y recorrer las pestañas, y provocar el bloqueo con Esteban.
