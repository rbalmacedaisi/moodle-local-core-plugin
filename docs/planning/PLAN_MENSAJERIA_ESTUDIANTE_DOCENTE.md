# Plan — Mensajería directa Estudiante ↔ Docente

Fecha: 2026-10-15 · Estado: **F1 implementada** (pendiente de deploy y prueba en producción)

### Estado F1 (versión del plugin 20261001107)

Decisiones de la sección 13 aplicadas con los valores por defecto: el docente de apoyo ve y responde; `/mensajes` queda fuera del bloqueo por mora; email desactivado por defecto hasta el digest (F2); sin adjuntos (F2); sin supervisión (F3); el drawer core de Moodle no se toca.

| Pieza | Archivos |
|---|---|
| Tablas + upgrade | `db/install.xml`, `db/upgrade.php` (20261001107), `version.php` |
| Reglas y operaciones | `classes/local/messaging/manager.php` |
| 7 WS | `classes/external/messaging/*`, `db/services.php` (`local_grupomakro_msg_*`) |
| Notificación | `db/messages.php` (`class_message`), strings `msg_*` en `lang/es|en` |
| Datos del curso al LXP | `classes/external/student/get_data_by_courses.php` → `messaging {classid, threadid, unread}` y `teachers[].userid` |
| UI docente | `js/components/TeacherMessages.js`, `amd/src/teacher_experience.js` (página `messages`, `?gmk_page=messages&gmk_thread=`), `js/components/TeacherStudentTable.js` (ícono por estudiante), `pages/teacher_dashboard.php` |
| UI estudiante (LXP) | `store/messaging.js`, `pages/mensajes/index.vue`, `components/MessageList.vue` (badge), botón en `pages/courses/overview/_id.vue` y `components/headerCourseBar.vue`, `middleware/paymentRestriction.js`, `lang/es|en.js` |

## 1. Objetivo

- **Estudiante (LXP):** en cada curso, botón "Enviar mensaje al docente" que abre una sección **Mensajes** con su bandeja de conversaciones con todos sus docentes.
- **Docente (Moodle, teacher_dashboard):** sección **Mensajes** con la bandeja de conversaciones con sus estudiantes, y botón para escribirle a un estudiante desde la clase.
- Notificar mensajes nuevos (badge en ambas interfaces + notificación Moodle/email).

## 2. Lo que ya existe (hallazgos)

| Hallazgo | Implicación |
|---|---|
| El LXP habla **directo con Moodle** por `webservice/rest/server.php` con el token del usuario (stores `course.js`, `announcement.js`, etc.). Express/Odoo no intervienen. | Todo el backend vive en `grupomakro_core`. **Express y Odoo no se tocan.** |
| WS del plugin se registran con `'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE]`. | Los nuevos WS siguen el mismo patrón; el token del LXP ya los puede llamar. |
| Moodle **4.0.12**. Mensajería core activada (`messaging=1`) pero `messagingallusers=0` y casi sin uso: 7 conversaciones individuales, 24 mensajes, último en 2026-08. | La mensajería core no es un activo que haya que preservar. |
| El LXP ya tiene `components/MessageList.vue` en el header (layouts `default`, `courses`, `historicalacademic`), basado en `local_soluttolms_core_get_messages` + `core_message_*`. Prácticamente vacío. | Se **reemplaza** por el nuevo ícono de Mensajes con badge. |
| El curso core es **compartido entre clases/planes**; la clase real del estudiante se resuelve por `gmk_course_progre.classid → gmk_class.instructorid` (ver `get_data_by_courses::normalize_teachers_payload`). | La conversación debe colgar de la **clase (`gmk_class.id`)**, no del curso. |
| `gmk_class` tiene `instructorid` y `supportinstructorid`; `lib.php` trata a ambos como docente. | El docente de apoyo también debe ver/responder las conversaciones de su clase. |
| El payload `teachers` de `get_data_by_courses` **no trae userid** (se matchea por email). La vista `courses/overview/_id.vue` muestra docentes con `mailto:`. | Hay que exponer `classid` y `teacher.userid` en ese WS; el botón nuevo va junto al docente. |
| Teacher dashboard = Vue 2 + Vuetify por CDN (`js/components/TeacherDashboard.js`, `ManageClass.js`, `TeacherStudentTable.js`), llama a `ajax.php`. | La UI docente son componentes JS nuevos en el mismo estilo. |

## 3. Decisión de arquitectura

**Recomendación: tablas propias del plugin (hilo por clase + estudiante), no la mensajería core de Moodle.**

Por qué:
1. **Contexto por curso/clase**: la conversación core es una por par de usuarios; si el estudiante tiene al mismo docente en dos materias, se mezcla todo. Aquí cada hilo es *(clase, estudiante)*.
2. **Curso compartido** entre clases: la regla de "quién puede escribirle a quién" la dicta `gmk_course_progre`/`gmk_class`, no la matrícula core.
3. **Docente de apoyo** y **supervisión (Director)** sin depender de preferencias de privacidad del usuario ni de `messagingallusers` / `moodle/site:sendmessage`.
4. Control de reglas propias: solo lectura al cerrar la clase, límites de envío, auditoría, métricas de tiempo de respuesta.

Se reutiliza de Moodle: `message_send()` con un **message provider** nuevo para notificaciones (popup/email), File API para adjuntos (fase 2), y el patrón de external functions.

Alternativa descartada: `core_message_*` (menos código, trae drawer de Moodle y app móvil gratis), pero no resuelve el contexto por clase ni el docente de apoyo, y obliga a ajustar privacidad global.

## 4. Modelo de datos (install.xml + upgrade.php, prefijo `isi_`)

**`gmk_msg_thread`** — un hilo por (clase, estudiante)
| Campo | Tipo | Nota |
|---|---|---|
| id | int | |
| classid | int | FK `gmk_class.id` |
| courseid | int | redundante para filtrar/mostrar |
| studentid | int | FK `user.id` |
| createdby | int | quién inició (estudiante o docente) |
| status | int(1) | 0 abierto, 1 solo lectura (clase cerrada), 2 archivado |
| lastmessageid | int | |
| lastmessageat | int | para ordenar la bandeja |
| timecreated / timemodified | int | |
| **UNIQUE** (classid, studentid) · INDEX (studentid, lastmessageat) · INDEX (classid, lastmessageat) | | |

> No se guarda `teacherid`: los participantes docentes se derivan de `gmk_class.instructorid` + `supportinstructorid` en cada lectura. Si cambian al docente, el nuevo hereda el hilo.

**`gmk_msg_message`**
| Campo | Tipo | Nota |
|---|---|---|
| id | int | |
| threadid | int | FK |
| senderid | int | |
| senderrole | char(10) | `student` / `teacher` / `support` / `staff` (congelado al enviar) |
| body | text | texto plano saneado, máx. 4000 caracteres |
| hasattachments | int(1) | fase 2 |
| deleted | int(1) | borrado lógico (solo moderación) |
| timecreated | int | |
| INDEX (threadid, id) | | |

**`gmk_msg_read`** — puntero de lectura
| Campo | Tipo |
|---|---|
| threadid, userid, lastreadmessageid, timeread | UNIQUE (threadid, userid) |

No leídos = mensajes del hilo con `id > lastreadmessageid` y `senderid <> userid`.

## 5. Reglas de acceso (una sola clase: `classes/local/messaging/access.php`)

Cada WS pasa por aquí — **nunca** confiar en ids que manda el cliente (riesgo IDOR).

- **Estudiante** puede abrir/escribir en el hilo de la clase X si tiene fila en `gmk_course_progre (userid, classid = X)` en un estado de clase cursándose. No puede escribir a otro estudiante ni a docentes de clases donde no está.
- **Docente** si `gmk_class.instructorid = uid` o `supportinstructorid = uid`. Solo ve hilos de sus clases. Puede **iniciar** un hilo con cualquier estudiante de su clase.
- **Clase cerrada** (`gmk_class.closed = 1`): el hilo queda en **solo lectura**; visible N días (p. ej. 60) y luego solo para supervisión.
- **Supervisión** (fase 3): cap nueva `local/grupomakro_core:messaging_oversee` (Director / Secretaría académica), solo lectura, con log de acceso.
- Límite de envío: p. ej. 30 mensajes/hora por usuario.
- Sin `debugging()` en la ruta de los WS (trampa B: rompe el JSON del LXP).

## 6. Web services (`classes/external/messaging/*`, registrados en `db/services.php`, `ajax => true`, `MOODLE_OFFICIAL_MOBILE_SERVICE`)

El rol (estudiante/docente) lo detecta el servidor, el mismo WS sirve a las dos UIs.

| Función | Uso |
|---|---|
| `local_grupomakro_msg_get_inbox` | Lista de hilos del usuario: contraparte (nombre, foto), curso/clase, último mensaje, no leídos. Filtros: `classid`, `unreadonly`, `search`; paginado. |
| `local_grupomakro_msg_get_contacts` | Estudiante: docentes de sus clases activas (`classid`, `courseid`, curso, docente(s)). Docente: estudiantes de una clase. Para "Nuevo mensaje". |
| `local_grupomakro_msg_open_thread` | `classid` (+ `studentid` si es docente) → crea o devuelve el hilo. Lo usa el botón del curso. |
| `local_grupomakro_msg_get_messages` | `threadid`, `beforeid`, `limit` → mensajes paginados hacia atrás. |
| `local_grupomakro_msg_send` | `threadid`, `body` → valida, inserta, actualiza `lastmessage*`, marca leído al remitente, dispara notificación. |
| `local_grupomakro_msg_mark_read` | `threadid`, `uptomessageid`. |
| `local_grupomakro_msg_get_unread_count` | Total para el badge (polling liviano). |

Cambio en WS existente: `get_data_by_courses` debe devolver **`classid`** del estudiante y **`userid`** en cada `teacher`.

**Tiempo real:** polling (no hay websockets en Moodle 4.0): badge cada 60 s; hilo abierto cada 10–15 s solo mientras la pestaña está visible.

## 7. Notificaciones

- Nuevo provider en `db/messages.php`: `class_message` (popup + email).
- Al enviar: `message_send()` popup inmediato al/los destinatario(s), con `contexturl` al hilo (LXP para el estudiante, teacher dashboard para el docente).
- **Email en digest** (para no spamear): tarea programada cada 15 min (`db/tasks.php`) que envía un correo por hilo con mensajes no leídos de más de 15 min, una sola vez hasta que se lea.

## 8. UI del estudiante (LXP, Nuxt 2 + Vuetify)

- `store/messaging.js` — mismo patrón `buildWsParams` / `ensureWsResult` que `announcement.js`.
- `pages/mensajes/index.vue` — bandeja a dos columnas (lista de hilos | conversación); en móvil, lista → detalle. Agrupable/filtrable por curso. Soporta `?classid=` y `?thread=`.
- **Botón en cada curso**:
  - `pages/courses/overview/_id.vue`, sección *Docentes*: "Enviar mensaje" junto (o en lugar) del `mailto:`.
  - `components/headerCourseBar.vue` (vista de actividades `pages/courses/_id.vue`): ícono "Mensaje al docente".
  - Ambos → `msg_open_thread(classid)` → `/mensajes?thread=<id>`.
- **Header**: reemplazar `MessageList.vue` legado por ícono con badge (`msg_get_unread_count`) que lleva a `/mensajes`, en los tres layouts. Refresco tipo `plugins/absence-refresh.client.js`.
- Textos en `lang/` (es/en). Indicar expectativa de respuesta ("los docentes responden en un plazo de hasta 48 h hábiles").

## 9. UI del docente (Moodle, teacher_dashboard)

- `js/components/MessagingInbox.js` (bandeja) + `js/components/MessageThread.js` (conversación), mismo stack Vue 2/Vuetify CDN; cargar en `pages/teacher_dashboard.php`.
- **TeacherDashboard.js**: entrada "Mensajes" con badge de no leídos; filtro por clase.
- **ManageClass.js / TeacherStudentTable.js**: ícono "Enviar mensaje" por estudiante → abre el hilo (lo crea si no existe).
- Endpoints por `ajax.php` (patrón actual) o `core/ajax` con las mismas external functions.

## 10. Fases

| Fase | Alcance | Entregable |
|---|---|---|
| **F1 — MVP** | Tablas + upgrade, `access.php`, 7 WS, ajuste `get_data_by_courses`, provider + popup, LXP (store, `/mensajes`, botones en curso, badge en header), dashboard docente (bandeja + botón por estudiante). | Estudiante y docente conversan por clase con notificación. |
| **F2** | Email digest (tarea programada), adjuntos (imagen/PDF ≤ 5 MB, File API `filearea=msgattachment`), búsqueda, solo-lectura automático al cerrar clase. | |
| **F3** | Cap `messaging_oversee` + página de supervisión para Director, métrica de tiempo de respuesta por docente, política de retención. | |

## 11. Deploy

- **Moodle**: flujo normal (commit → push GitHub → `git pull` en 100.28.104.64 → `upgrade.php` → `purge_caches` → reload php7.4-fpm). Nueva versión **apilada al final** de `version.php` y mayor que la actual (hoy 20261001106). Si F3 agrega caps: `assign_capabilities_to_internal_roles()` + purgar sesiones/MUC.
- **LXP**: build/deploy por su pipeline (Amplify, rama `isi-prod`).
- **Odoo / Express**: sin cambios.

## 12. Plan de pruebas

- Estudiante de clase A **no** puede leer/escribir hilos de clase B cambiando `threadid`/`classid` (IDOR).
- Docente de clase A no ve hilos de clase B; el docente de apoyo sí ve los de su clase.
- Estudiante con dos clases del mismo curso core → se resuelve la clase correcta.
- Clase cerrada → solo lectura.
- Contadores de no leídos coherentes en ambas UIs; marcar leído.
- Respuesta JSON limpia con `debug=32767` activado.
- Usuario suspendido / desmatriculado pierde acceso a escribir.

## 13. Decisiones pendientes (para confirmar antes de codificar)

1. **Docente de apoyo**: ¿ve y responde las conversaciones de la clase? (propuesta: sí).
2. **Estudiantes con restricción de pago** (`middleware/paymentRestriction.js`): ¿pueden usar Mensajes? (propuesta: sí, la mensajería académica no se bloquea).
3. **Email**: ¿digest cada 15 min, inmediato o ninguno?
4. **Adjuntos** en el MVP o en F2.
5. **Supervisión**: ¿Director/Secretaría pueden leer conversaciones? Si sí, avisarlo en la UI por transparencia.
6. ¿Se desactiva el drawer de mensajería core de Moodle para docentes, para evitar dos canales?
