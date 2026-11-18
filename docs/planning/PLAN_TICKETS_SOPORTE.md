# Plan: Mesa de ayuda con tickets y chat (Gestión Académica y TI)

Estado: decisiones de la fase 0 tomadas por Ramiro el 17-nov-2026 (ver §0); el resto del documento ya las refleja.
Fase 1 en producción desde el 18-nov-2026 (20261001120). Fase 2 implementada el 18-nov-2026 en la rama `feat/tickets-soporte-fase2` (20261001121), pendiente de staging; pruebas en `PRUEBAS_TICKETS_FASE2.md`. Push web y Telegram (capas 4 y 5) quedan para la fase 2-bis.
Alcance: plugin `local_grupomakro_core` (Moodle 4.0) y portal de estudiantes (Nuxt, `isi_moodle_lxp`).

---

## 0. Decisiones tomadas (17-nov-2026)

| Tema | Decisión |
|---|---|
| Gerencia | **Joyce Muñoz** (usuario Moodle 2886, direccionacademica@isi.edu.pa) recibe las alertas de desatención y el resumen diario. |
| Tiempos (semáforo) | **Verde** hasta 20 min (meta) · **Amarillo** desde 1 h ("ya se va atrasando") · **Naranja** desde 3 h · **Rojo** desde 5 h. Ver §6.2. |
| Categorías | **Académico**, **Soporte Tecnológico**, **Bienestar**, **Psicología** y **Finanzas**. Finanzas no abre ticket: muestra un botón de WhatsApp al número de Finanzas, +507 6892-0543. |
| Asignación | Se rutea por tema (ver §6.1):<br>• Gestión académica (horarios, asignaturas, notas, gestión con docentes): reparto entre **Jean Remice** y **Lizbeth Aizprua**.<br>• Asistencia a clases y bienestar institucional: **Jorge Oviedo**.<br>• Quejas: **Psicología**. |
| Cierre | Se cierra si el estudiante confirma que está resuelto, o si pasa **1 día sin respuesta del estudiante** después del último mensaje del personal. |
| Mensajes | El sistema manda **mensajes automáticos** en cada etapa y un **mensaje de calificación de la atención** al cerrar (ver §7.1). |
| Seguridad | Corregidos los servicios de avisos que aceptaban un `userid` del cliente (ver §11). |

Confirmado el 17-nov-2026:
- **Soporte Tecnológico** lo atiende **Esteban Montoya** (TI).
- **Psicología** la atiende **Dulce Jurado**. Sus tickets son **confidenciales**: solo los ven Dulce y gerencia (Joyce Muñoz); ni Secretaría ni Bienestar.
- **El semáforo corre solo en horario hábil.** Se descuentan las horas fuera de horario, los domingos y los feriados de `gmk_holidays`.

---

## 1. Objetivo y principios

El estudiante pide atención desde el portal sobre un tema concreto, se abre un **ticket** con chat. Lo atiende el área que corresponde (Académica o TI).

Principios que guían todo el diseño:

1. **Ningún ticket sin dueño.** Todo ticket tiene siempre un área y, en minutos, una persona responsable.
2. **Ningún mensaje sin ver.** Ni el estudiante ni el personal pueden "no enterarse": aviso bloqueante, distintivo permanente y correo.
3. **Todo tiempo se mide.** Cada ticket lleva reloj de primera respuesta y de solución (SLA). Al vencerse, escala solo.
4. **Todo queda auditado.** Cada cambio de estado, asignación y mensaje queda en un historial que no se borra.

---

## 2. Lo que ya existe y se reutiliza

| Pieza existente | Uso en la mesa de ayuda |
|---|---|
| Mensajería estudiante-docente (`gmk_msg_thread/message/read`, `messaging/manager.php`, `pages/mensajes`) | Mismo modelo de hilo + mensaje + puntero de lectura, misma forma de servicios (bandeja, abrir, mensajes paginados, enviar, marcar leído, contador) y el mismo sondeo (cada 15 s el chat abierto). |
| Avisos con confirmación (`gmk_admin_message` + `AnnouncementSystem.vue`, diálogo persistente con prioridad) | Patrón del aviso "no se puede ignorar" en el portal: cuando llega respuesta, diálogo persistente que no se cierra sin abrir el ticket. |
| Solicitudes de cartas (`letters/manager.php`: transiciones validadas + `gmk_letter_request_event`) | Plantilla de la máquina de estados del ticket y de su historial. |
| Contador anual de retiros (`gmk_wdr_seq`, RET-AAAA-NNNN) | Numeración `TKT-2026-0001`. |
| `message_send` con proveedores en `db/messages.php` | Notificaciones por campanita, correo y app móvil. |
| Ruteo por persona de Bienestar (`gmk_wellness_staff_role`, correo alterno y avisos on/off) | Modelo para la tabla de agentes por área. |
| Roles internos (`gmk_secretaria_academica`, `gmk_director_academico`, `gmk_psicologo`, `gmk_bienestar`, `gmk_soporte_ti`, `gmk_director_general`) y la matriz de capacidades en `upgradelib.php` | Permisos de la mesa de ayuda. |
| Feriados (`gmk_holidays`) | Calcular SLA en horas hábiles. |

Lo que **no** existe y hay que construir:
- tickets, categorías, asignación y SLA;
- un indicador flotante en todas las páginas de Moodle (hoy no hay ningún `before_footer`);
- una tarea de escalamiento a gerencia;
- un rol de gerencia;
- adjuntos en el chat.

---

## 3. Actores y permisos

| Actor | Qué hace | Cómo se define |
|---|---|---|
| Estudiante | Crea tickets, chatea, confirma la solución y califica. Solo ve los suyos. | Usuario del portal |
| Agente académico | Toma y atiende tickets del área Académica | Rol `gmk_secretaria_academica` (y quien se agregue como agente) |
| Agente TI | Toma y atiende tickets del área TI | Rol `gmk_soporte_ti` |
| Supervisor (vista constante) | Ve **todas** las bandejas en vivo, excepto los tickets confidenciales de Psicología. Reasigna, responde y recibe alertas de vencimiento. | Secretaría Académica, Dirección Académica, Psicología, Bienestar |
| Gerencia | Recibe alertas de tickets desatendidos (rojo, 5 h) y un resumen diario. Ve los indicadores. | **Joyce Muñoz** (2886). Se le da la capacidad `support_receive_escalations` con un rol nuevo `gmk_gerencia`, para no darle permisos de otros roles. |

Capacidades nuevas en `db/access.php`:

| Capacidad | Para qué |
|---|---|
| `support_create` | Estudiante (o cualquier usuario autenticado) crea tickets |
| `support_agent` | Atender tickets del área a la que pertenece |
| `support_supervise` | Ver todas las áreas, reasignar, ver indicadores |
| `support_viewconfidential` | Ver tickets de categorías confidenciales |
| `support_manage` | Configurar áreas, categorías, SLA, agentes y respuestas rápidas |
| `support_receive_escalations` | Gerencia: recibir alertas y el resumen diario |

**Privacidad.** Las categorías marcadas como confidenciales (por ejemplo, temas personales que deriven a Psicología) solo las ven el agente asignado y quien tenga `support_viewconfidential`. La regla vale aunque el lector sea supervisor.

---

## 4. Modelo de datos

Tablas nuevas con prefijo `gmk_tkt_`:

| Tabla | Campos clave |
|---|---|
| `gmk_tkt_area` | id, code (`academica`, `ti`), name, active, horario de atención (JSON: días y horas) |
| `gmk_tkt_category` | id, areaid, name, description, priority_default, sla_first_response_min, sla_resolution_min, confidential, requires_attachment, active, sortorder |
| `gmk_tkt_agent` | id, areaid, userid, can_auto_assign, max_open (tope de carga), available (presente/ausente), timemodified |
| `gmk_tkt_ticket` | id, number (`TKT-2026-0001`), userid (estudiante), areaid, categoryid, subject, status, priority, assignedto, learningplanid/classid (contexto opcional), first_response_at, first_response_due, resolution_due, resolved_at, closed_at, reopened_count, escalation_level, last_student_msg_at, last_staff_msg_at, rating, rating_comment, timecreated, timemodified |
| `gmk_tkt_message` | id, ticketid, senderid, senderrole (`student`/`agent`/`supervisor`/`system`), body, internal (nota interna que el estudiante no ve), hasattachments, deleted, timecreated |
| `gmk_tkt_read` | ticketid, userid, lastreadmessageid, timeread (igual que `gmk_msg_read`) |
| `gmk_tkt_event` | ticketid, eventtype, oldvalue, newvalue, actorid, metadata, timecreated: historial inmutable de estados, asignaciones, escalamientos y vencimientos |
| `gmk_tkt_seq` | year, last: contador de numeración |
| `gmk_tkt_canned` | id, areaid, title, body: respuestas rápidas |

Índices: (status, areaid), (assignedto, status), (userid, status), (first_response_due), (resolution_due).

Adjuntos: área de archivos de Moodle (`filearea = ticket_message`, itemid = message id), con límite de tamaño y tipos permitidos.

---

## 5. Ciclo de vida del ticket

```
nuevo ──► asignado ──► en_atencion ──► esperando_estudiante ──► resuelto ──► cerrado
  │           ▲            │   ▲              │                    │
  │           └─reasignar──┘   └──responde────┘                    └─► reabierto ─► en_atencion
  └─► cancelado (solo el estudiante, antes de la primera respuesta)
```

Reglas de cada transición. Se validan en el servidor, como en cartas, y cada una deja un registro en `gmk_tkt_event`.

1. **Nuevo.** Al crearlo se fijan las fechas límite de primera respuesta y de solución, según la categoría y en horas hábiles.
2. **Asignado.** Ocurre automáticamente al crearse (ver §6) o cuando un agente pulsa "Tomar".
3. **En atención.** El agente envió su primera respuesta. Ahí se registra `first_response_at`.
4. **Esperando estudiante.** El agente pidió información o respondió. El semáforo se **detiene**. A las 12 h sin respuesta del estudiante se le envía un recordatorio automático: "Si no recibimos respuesta, cerraremos tu solicitud en 12 h".
5. **Resuelto.** El agente marca la solución. El estudiante ve "¿Se resolvió tu caso?" y tiene dos opciones:
   - **Sí:** el ticket se cierra y se le pide la calificación.
   - **No:** el ticket se reabre y vuelve al mismo agente con prioridad alta.
6. **Cerrado.** Ocurre si el estudiante confirma, o si pasa **1 día** sin respuesta suya tras el último mensaje del personal. Un ticket cerrado no admite mensajes; el estudiante puede abrir otro vinculado.

Un ticket **no se puede cerrar sin al menos una respuesta del personal**. Así nadie "limpia" la bandeja sin atender.

---

## 6. Dinámica de atención: que nadie se quede sin atención

### 6.1 Asignación
Ruteo por categoría y tema. Lo elige el estudiante al crear la solicitud; el agente puede corregirlo después.

| Categoría | Temas (subcategoría) | Atiende |
|---|---|---|
| Académico | Horarios, asignaturas y matrícula, notas, gestión con docentes, otro | Reparto entre **Jean Remice** (2732) y **Lizbeth Aizprua** (2912) |
| Académico | Asistencia a clases (faltas, QR, justificaciones) | **Jorge Oviedo** (2737) |
| Bienestar | Bienestar institucional (eventos, carné, apoyo, etc.) | **Jorge Oviedo** (2737) |
| Psicología | Quejas y temas personales. **Confidencial**: solo Psicología y gerencia. | **Dulce Jurado** (2765) |
| Soporte Tecnológico | Acceso y contraseña, plataforma y aula virtual, app, correo | **Esteban Montoya** (2756, TI) |
| Finanzas | — | **No abre ticket.** Muestra el botón "Escribir a Finanzas por WhatsApp" (`https://wa.me/50768920543`). |

Reglas generales:
- **Reparto equilibrado:** si un tema tiene varios agentes, va al agente disponible con menos tickets abiertos.
- **Sin agentes disponibles:** el ticket queda en la **cola del área** ("Sin asignar"). Esa cola se ve destacada en rojo para todos los agentes y supervisores del área.
- **"Tomar":** cualquier agente puede tomar un ticket de la cola. Un supervisor puede reasignar en cualquier momento, y queda registrado.
- **Ausencias:** un agente que marca "Ausente" deja de recibir tickets. Sus tickets abiertos con vencimiento en las próximas 2 h vuelven a la cola del área.

### 6.2 Semáforo de atención

El reloj mide **cuánto lleva el estudiante esperando una respuesta del personal**. Arranca:
- al crear el ticket;
- cada vez que el estudiante escribe y el último mensaje es suyo.

Se detiene cuando el personal responde. Así cubre la primera respuesta y también cada mensaje posterior.

| Espera | Color | Significado |
|---|---|---|
| 0 – 20 min | 🟢 Verde | Dentro de la meta |
| 20 min – 1 h | 🟢 Verde con aviso | Fuera de la meta, todavía aceptable |
| 1 h – 3 h | 🟡 Amarillo | Se va atrasando |
| 3 h – 5 h | 🟠 Naranja | Tirando a rojo |
| 5 h o más | 🔴 Rojo | Desatendido |

**Decidido: el reloj corre solo en horario hábil.** Se descuentan las noches, los domingos y los feriados de `gmk_holidays`. Un mensaje enviado fuera de horario empieza a contar al abrir la jornada siguiente, y el estudiante recibe el mensaje automático de "fuera de horario".

El horario hábil es configurable por área (§12-bis). Valor inicial aprobado el 17-nov-2026:
- **Académico y Bienestar:** lunes a viernes de 8:00 a 21:00 y sábados de 8:00 a 16:00.
- **Psicología:** lunes a viernes de 8:00 a 17:00.
- **Soporte Tecnológico:** lunes a viernes de 8:00 a 21:00 y sábados de 8:00 a 16:00.

### 6.3 Escalera de escalamiento
Una tarea programada cada 5 minutos revisa los relojes:

| Espera | Acción |
|---|---|
| 20 min | Recordatorio al agente asignado (campanita y aviso flotante que no se cierra solo). Mensaje automático al estudiante: "Tu solicitud está en cola, te responderemos en breve". |
| 1 h (amarillo) | Alerta a los **supervisores** (Secretaría Académica, Dirección Académica, Psicología, Bienestar). Si el ticket sigue sin asignar, se asigna al agente con menor carga. |
| 3 h (naranja) | Segunda alerta a supervisores. El ticket sube a prioridad alta y se destaca en todas las bandejas. Si el agente está ausente, vuelve a la cola. |
| 5 h (rojo) | Alerta a **gerencia (Joyce Muñoz)** por correo y campanita, con estudiante, categoría, tiempo de espera y agente responsable. El aviso se repite cada 2 h mientras siga en rojo. |

Cada escalamiento sube `escalation_level` y deja un evento, para que la alerta no se repita a cada pasada.

### 6.4 Resumen diario a gerencia (7:00)
- Tickets abiertos, vencidos y desatendidos por área.
- Los 10 que más llevan esperando.
- Tiempo promedio de primera respuesta del día anterior.
- Calificación promedio.

Se envía por correo y queda también en el tablero.

---

## 7. Experiencia del estudiante (portal)

1. **Botón flotante "Ayuda"**, fijo en la esquina inferior de todas las páginas del portal.
   - Muestra un número rojo si hay mensajes sin leer.
   - Se monta en `layouts/default.vue`, junto a los demás sistemas de avisos.
2. **Nueva solicitud**, en 3 pasos:
   1. Área (Académica o Tecnología).
   2. Categoría: lista corta con ejemplos, como "Notas y calificaciones", "Horarios y matrícula", "Cartas y documentos", "No puedo entrar / contraseña", "Asistencia y QR", "Plataforma o aula virtual" u "Otro".
   3. Descripción y adjunto.

   Antes de enviar se muestra la ayuda frecuente de esa categoría (opcional, fase 3).
3. **Mis solicitudes:** lista con número, estado en lenguaje claro ("Recibida", "En atención", "Te pedimos información", "Resuelta") y tiempo estimado de respuesta.
4. **Chat del ticket:** el mismo diseño de `pages/mensajes`, con "escribiendo/visto" vía el puntero de lectura.
5. **Aviso que no se puede ignorar** cuando el personal responde:
   - **Diálogo persistente:** con el patrón de `AnnouncementSystem`, sin botón de cerrar. Dice: "Gestión Académica respondió tu solicitud TKT-2026-0123". Su único botón es "Ver respuesta", que abre el chat y marca leído. Si hay varios tickets se muestran en cola.
   - **Distintivo rojo** en el botón flotante y en el encabezado, y título de la pestaña parpadeando: "(1) Nueva respuesta".
   - **Copia por correo y por la app móvil** (`message_send`), por si el estudiante no está en el portal.
   - **Si "Te pedimos información"** sigue sin respuesta del estudiante, el diálogo vuelve a aparecer en cada ingreso hasta que responda o se cierre el ticket (1 día).
6. **Al resolverse:** diálogo de confirmación y calificación de 1 a 5 estrellas.
7. **Finanzas:** al elegir esta categoría no se crea ticket. Aparece el botón "Escribir a Finanzas por WhatsApp", que abre `https://wa.me/50768920543` con un texto inicial que incluye su nombre y cédula.

### 7.1 Mensajes automáticos

Aparecen en el chat como mensajes del "Sistema" y llegan también por campanita y correo.

| Momento | Mensaje (borrador) |
|---|---|
| Al crear | "Recibimos tu solicitud **TKT-2026-0123** sobre *{categoría}*. Te atenderá *{agente o área}*. Tiempo habitual de respuesta: 20 minutos en horario de atención." |
| Al asignar o reasignar | "*{Agente}* está atendiendo tu solicitud." |
| 20 min sin respuesta | "Tu solicitud sigue en cola; te responderemos en breve. Gracias por tu paciencia." |
| Fuera de horario | "Recibimos tu mensaje fuera del horario de atención (*{horario}*). Te responderemos al inicio de la siguiente jornada." |
| 12 h sin respuesta del estudiante | "¿Sigues necesitando ayuda? Si no recibimos respuesta, cerraremos tu solicitud en 12 h." |
| Al resolver | "Marcamos tu solicitud como resuelta. ¿Se resolvió tu caso? [Sí] [No]" |
| Al cerrar | "Tu solicitud **TKT-2026-0123** fue cerrada. ¿Cómo calificarías la atención recibida?" |

**Mensaje de calificación de la atención.** Al cerrar, el estudiante ve un diálogo persistente en el portal (patrón de `AnnouncementSystem`), que reaparece hasta que responda o lo descarte:
- 1 a 5 estrellas;
- "¿Te resolvimos lo que necesitabas?" (Sí/No);
- comentario opcional.

Puede descartarlo con "Ahora no"; en ese caso reaparece una sola vez al día siguiente. Las calificaciones de 1 o 2 estrellas generan un aviso a los supervisores para revisar el caso.

---

## 8. Experiencia del personal (Moodle)

### 8.1 Bandeja de tickets (`pages/support_desk.php`, Vue como las demás bandejas)
- Pestañas:
  - **Sin asignar** (rojo si hay alguno)
  - **Míos**
  - **Mi área**
  - **Vencidos**
  - **Todos**: solo supervisores
  - **Cerrados**
- Cada fila: número, estudiante (con carrera y cuatrimestre), categoría, estado, agente y semáforo de SLA con tiempo restante (verde, amarillo al 50 %, rojo vencido).
- Panel del ticket:
  - chat con el estudiante y notas internas en amarillo;
  - respuestas rápidas;
  - adjuntos;
  - botones Tomar, Reasignar, Pedir información, Resolver y Cambiar prioridad o categoría;
  - historial de eventos.
- Ficha lateral del estudiante: estado académico y financiero, y tickets anteriores, con enlace al panel académico.

### 8.2 Indicador flotante en todas las páginas de Moodle
Hoy no hay nada inyectado globalmente; se agrega el callback `local_grupomakro_core_before_footer()` en `lib.php`.

- **Quién lo ve:** solo usuarios con `support_agent` o `support_supervise`, nunca estudiantes ni docentes sin rol de soporte.
- **Forma:** una píldora fija en la esquina inferior derecha con 3 contadores:
  - **Sin asignar** de mis áreas
  - **Míos** abiertos (con mensajes nuevos del estudiante resaltados)
  - **Vencidos**

  Al hacer clic se abre la bandeja.
- **Ticket nuevo o mensaje nuevo de un estudiante en un ticket mío:**
  - la píldora pulsa en rojo;
  - aparece un aviso que **no se cierra solo**, con botones "Abrir" y "Tomar".
- **Ticket vencido:** el aviso se repite en cada página hasta que alguien lo atienda.
- **Supervisores:** ven además un mini-tablero desplegable con totales por área y el agente con más pendientes.
- **Técnica:** un JS ligero en AMD, con sondeo cada 30 s solo con la pestaña visible, contra un servicio único `support_get_badge`. Ese servicio devuelve 3 números, está cacheado 20 s y consulta solo tablas indexadas, para no cargar el servidor.

### 8.3 Disponibilidad
Interruptor "Disponible / Ausente" en la píldora. Cada cambio queda registrado y alimenta la asignación automática.

---

### 8.4 Docentes también abren tickets (a TI)
Decidido el 17-nov-2026. El docente no usa el portal de estudiantes, así que en Moodle tiene su propio botón "Ayuda TI":
- lo agrega el mismo `before_footer` que el indicador flotante, visible a quien tenga rol de profesor en algún curso;
- también aparece en el panel docente (`teacher_dashboard`).

El docente solo ve las categorías marcadas "disponible para docentes" (por defecto, Soporte Tecnológico). El resto funciona igual: chat, semáforo y calificación. Sus respuestas le llegan con el aviso persistente y la campanita de Moodle.

### 8.5 Modo "incisivo" para TI y administradores
Objetivo: que para Esteban (TI) y los administradores sea imposible no enterarse de un mensaje. Se logra con **capas**: cada una cubre el hueco de la anterior, desde la pestaña abierta hasta el celular con el navegador cerrado.

| Capa | Dónde llega | Cómo funciona |
|---|---|---|
| 1. Bloqueo en pantalla | Cualquier página de Moodle abierta | Con un mensaje nuevo sin atender, aparece una **ventana a pantalla completa** que no se cierra con clic fuera ni con Esc. Opciones: "Abrir chat", "Tomar", o "Posponer 5 min" (máximo 2 veces). Tras posponer, vuelve. |
| 2. Sonido y título | Pestaña abierta, aunque esté en segundo plano | Suena una alerta que se repite cada 30 s hasta abrirlo. El título de la pestaña parpadea: "🔴 (2) Ticket nuevo – TI". El favicon lleva un punto rojo. |
| 3. Notificación del sistema operativo | Navegador abierto, aunque esté minimizado o en otra aplicación | API `Notification` del navegador. El sistema pide el permiso la primera vez y lo vuelve a pedir si fue denegado. Se usa la opción `requireInteraction`, para que la notificación **no desaparezca sola** en Windows y macOS. |
| 4. Push web | Navegador cerrado (Chrome o Edge corriendo en segundo plano) y Android | Service worker del plugin + Web Push con claves VAPID, guardando la suscripción de cada agente. Llega como notificación nativa aunque Moodle no esté abierto. |
| 5. Celular por Telegram | Teléfono, en cualquier lugar | Bot de Telegram de la institución: gratis, inmediato y fácil de integrar. Cada agente vincula su cuenta con un código desde Moodle. El mensaje dice *"TKT-2026-0123 · Soporte Tecnológico · Docente: Juan Pérez · espera 12 min"* con botón "Abrir". **No incluye el contenido del chat.** |
| 6. Escalamiento por falta de acuse | Respaldo y supervisores | Si el agente no abre ni toma el ticket en **5 min hábiles**, el aviso pasa a su respaldo y a los administradores. El tiempo es configurable y es aparte del semáforo de 20 min. |

Detalles importantes:
- **Acuse obligatorio.** El aviso solo se apaga cuando el agente **abre** el ticket o lo **toma**. Ver la píldora no cuenta. Así se mide el "tiempo hasta acuse" y se sabe si alguien ignora avisos.
- **Respeta la disponibilidad.** El modo incisivo se activa solo si el agente está "Disponible" y en su horario hábil. Fuera de horario solo hay campanita, y el ticket espera la jornada siguiente según §6.2.
- **Configurable por persona y rol** (§12-bis): cada capa se activa o desactiva y el sonido se elige, con valores por defecto para TI y administradores:
  - **Agente de TI:** todas las capas.
  - **Administradores:** bloqueo solo con tickets en naranja o rojo; Telegram activo.
  - **Agentes académicos:** sin bloqueo a pantalla completa. Les llegan sonido, notificación del sistema y Telegram.
- **Privacidad:** las capas 3, 4 y 5 nunca muestran el texto del mensaje, solo número, categoría, quién y tiempo de espera. En Psicología ni siquiera el nombre del estudiante: dicen "Ticket confidencial".
- **WhatsApp como alternativa a Telegram:** se puede, con la API oficial de WhatsApp Business (Meta Cloud API). Tiene costo por mensaje, requiere plantillas aprobadas por Meta y un número dedicado. Recomendado empezar con Telegram y evaluar WhatsApp después.

Fase sugerida: las capas 1 a 3 entran en la fase 2, con el indicador flotante. Las capas 4 y 5 (push web y Telegram) son una fase 2-bis corta, porque necesitan las claves VAPID y crear el bot.

## 9. Indicadores y tablero de supervisión

Tablero con filtros por fecha, área, categoría y agente:

1. Tickets abiertos, sin asignar y vencidos, en vivo.
2. Tiempo promedio y percentil 90 de primera respuesta.
3. Porcentaje atendido dentro del SLA, de primera respuesta y de solución.
4. Tiempo promedio de solución.
5. Tickets por categoría, que muestran qué genera más consultas y alimentan la ayuda frecuente.
6. Carga y desempeño por agente: abiertos, cerrados y tiempo de respuesta.
7. Satisfacción promedio y comentarios.
8. Reaperturas: tickets que se dieron por resueltos sin estarlo.

Exportable a Excel con el mismo mecanismo de `export_students.php`.

---

## 10. Notificaciones

Proveedores nuevos en `db/messages.php`, con canales popup, correo y app:

| Proveedor | Quién lo recibe | Cuándo |
|---|---|---|
| `support_reply` | Estudiante | El personal respondió o pidió información |
| `support_resolved` | Estudiante | El ticket se marcó resuelto |
| `support_new_ticket` | Agentes del área | Ticket nuevo sin asignar |
| `support_assigned` | Agente | Se le asignó un ticket |
| `support_student_message` | Agente asignado | El estudiante escribió |
| `support_sla_warning` | Agente | 20 min esperando |
| `support_sla_breach` | Supervisores del área | 1 h (amarillo) y 3 h (naranja) |
| `support_escalation` | Gerencia | 5 h esperando (rojo), y cada 2 h mientras siga en rojo |
| `support_daily_digest` | Gerencia y supervisores | 7:00 |

Para no saturar: en el chat, como máximo 1 correo cada 10 min por ticket y persona; la campanita y el aviso en pantalla sí son inmediatos.

---

## 11. Seguridad e integridad

- **Identidad:** todos los servicios usan `$USER` del lado servidor; nunca un `userid` enviado por el cliente. El estudiante solo lee sus tickets y nunca las notas internas.
- **Contenido:** los mensajes se guardan como texto sanitizado, igual que en la mensajería actual. Los adjuntos se validan por tipo y tamaño.
- **Límites de creación:** por estudiante, como máximo 5 tickets abiertos y 10 por día, para evitar spam.
- **Datos:** los eventos son inmutables y no hay borrado físico de mensajes (solo `deleted` para moderación, con registro).
- **Tareas programadas:** idempotentes, para que el mismo escalamiento no se envíe dos veces; usan `escalation_level` y los eventos.
- **Corregido el 17-nov-2026:** los servicios `get_pending_admin_messages` y `acknowledge_admin_message` aceptaban un `userid` del cliente, y cualquier usuario autenticado podía leer o aceptar avisos de otro. Ahora usan siempre el usuario de la sesión y rechazan un `userid` distinto. El portal ya enviaba el propio, así que sigue funcionando sin cambios.

---

## 12. Fases de implementación

| Fase | Contenido | Resultado |
|---|---|---|
| **0. Definiciones** (1 reunión) | Áreas, categorías, SLA, horarios, quién es agente en cada área, quién es gerencia, qué categorías son confidenciales | Tabla de configuración inicial |
| **1. Núcleo (MVP)** | Tablas, máquina de estados con historial, crear ticket y chat en el portal, bandeja del personal (Tomar, responder, resolver), asignación manual y automática, notificaciones básicas, distintivo en el portal | Estudiantes y personal ya se comunican por tickets |
| **2. Que nadie se quede sin atención** | Relojes SLA en horas hábiles, tarea de escalamiento cada 5 min, alertas a supervisores y gerencia, indicador flotante en Moodle, aviso persistente en el portal, disponibilidad de agentes, resumen diario | Cumplimiento del objetivo central |
| **3. Calidad** | Tablero de indicadores, calificación, adjuntos, respuestas rápidas, ayuda frecuente por categoría, exportación | Gestión con datos |
| **4. Opcional** | Integración con WhatsApp o correo entrante, chatbot de primer nivel para preguntas frecuentes | Más canales |

Cada fase sale primero a staging con datos de prueba. Antes de producción, la lista de verificación incluye:
- crear un ticket;
- vencerlo a propósito con un SLA de 1 min y comprobar la escalera completa de alertas;
- confirmar que el estudiante ve el aviso bloqueante y que el agente ve la píldora.

Reutilizar la mensajería y el patrón de avisos reduce bastante el trabajo de las fases 1 y 2.

---

## 12-bis. Todo configurable desde el sistema

Requisito de Ramiro (17-nov-2026): nada de lo decidido queda fijo en el código. Habrá una página de configuración `pages/support_settings.php`, con la capacidad `support_manage`.

Cada cambio deja un evento de auditoría con quién, cuándo, valor anterior y valor nuevo.

| Sección | Qué se configura | Valor inicial |
|---|---|---|
| Áreas | Nombre, activa o inactiva, **horario hábil por día** (varios tramos por día) | Académico y Bienestar: L–V 8:00–21:00 y S 8:00–16:00 · Psicología: L–V 8:00–17:00 · Soporte Tecnológico: L–V 8:00–21:00 y S 8:00–16:00 |
| Categorías y temas | Nombre, área, descripción para el estudiante, orden, confidencial sí o no, adjunto obligatorio, activa | Académico (horarios, asignaturas y matrícula, notas, gestión con docentes, asistencia a clases, otro), Soporte Tecnológico, Bienestar, Psicología (confidencial), Finanzas |
| Categoría de enlace externo | Una categoría puede no abrir ticket y mostrar un botón con enlace, texto del botón y mensaje inicial | Finanzas → WhatsApp `https://wa.me/50768920543` |
| Agentes por tema | Uno o varios agentes por tema, reparto equilibrado sí o no, tope de tickets abiertos, disponible o ausente | Académico: Jean Remice y Lizbeth Aizprua · Asistencia y Bienestar: Jorge Oviedo · Psicología: Dulce Jurado · TI: Esteban Montoya |
| Respaldo por ausencia | Para cada agente o tema, a quién pasan sus tickets si está ausente | Asistencia (Jorge) → Jean y Lizbeth · Psicología (Dulce) → Joyce Muñoz · TI (Esteban) → cola visible para supervisores |
| Supervisores | Quién tiene vista constante (por rol o por persona) | Secretaría Académica, Dirección Académica, Psicología, Bienestar |
| Gerencia | Personas que reciben escalamientos y el resumen diario | Joyce Muñoz |
| Semáforo | Umbrales en minutos hábiles para verde, amarillo, naranja y rojo; repetición del aviso en rojo | 20 / 60 / 180 / 300 min; repetir cada 120 min |
| Escalamiento | Para cada umbral: a quién avisar (agente, supervisores, gerencia) y si reasigna | Según §6.3 |
| Cierre | Horas sin respuesta del estudiante para el recordatorio y para el cierre | Recordatorio a las 12 h, cierre a las 24 h |
| Mensajes automáticos | Texto editable de cada mensaje automático, con variables `{numero}`, `{categoria}`, `{agente}`, `{horario}` y `{estudiante}` | Borradores de §7.1 |
| Calificación | Activa o no, umbral de calificación baja que avisa a supervisores, reaparición si la descarta | Activa; 1–2 estrellas avisan; reaparece una vez |
| Límites | Tickets abiertos por estudiante, tickets por día, tamaño y tipos de adjuntos | 5 abiertos, 10 por día, 10 MB, pdf/jpg/png/docx |
| Resumen diario | Hora de envío y destinatarios | 7:00 · Joyce Muñoz y supervisores |

Notas de implementación:
- Estos valores viven en tablas (`gmk_tkt_area`, `gmk_tkt_category`, `gmk_tkt_agent`, nueva `gmk_tkt_backup` y nueva `gmk_tkt_template`) y en `config_plugins`; no en constantes.
- La instalación carga los valores iniciales una sola vez.
- La tarea de escalamiento y el cálculo de horas hábiles leen la configuración en cada pasada, así que un cambio aplica sin desplegar.
- El cálculo de horas hábiles es una función única (`business_time::elapsed($from, $to, $areaid)`) con pruebas: cruce de medianoche, sábado, domingo, feriado y cambio de horario.

## 13. Decisiones pendientes

Ninguna de la fase 0. Por confirmar al llegar a la fase 2-bis:
1. Crear el bot de Telegram de la institución (nombre y cuenta dueña).
2. ¿WhatsApp Business más adelante?
