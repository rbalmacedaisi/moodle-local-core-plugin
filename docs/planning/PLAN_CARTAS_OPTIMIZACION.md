# Plan: Solicitudes de cartas — diagnóstico, vínculo con la mesa de ayuda, bandeja y ANS

Estado: **implementado en ramas locales** (19-nov-2026, versión 20261001130), pendiente de staging y push. Decisiones de Ramiro en §8; pruebas en `PRUEBAS_CARTAS.md`.
Alcance: plugin `local_grupomakro_core` (base en `origin/master` 2bb26bac, versión 20261001126) y portal Nuxt `isi_moodle_lxp` (`origin/isi-prod` 3f54d43).
Datos: consulta de solo lectura (agregados, sin datos personales) a `isidb` el 19-nov-2026.

---

## 1. Diagnóstico

### 1.1 Lo que hay hoy

| Pieza | Qué hace |
|---|---|
| `classes/local/letters/manager.php` | Máquina de estados `solicitada → pendiente_pago → pagada → generada_digital / pendiente_gestion → pendiente_recoleccion → entregada` (+ `rechazada`, `cancelada`). Factura en Odoo por el proxy Express, webhook de pago (`letters_webhook.php`), PDF con TCPDF + QR de verificación, copia del PDF a Odoo. |
| `pages/letterrequests.php` ("📬 Bandeja de Cartas") | Una tabla HTML de escritorio, filtro por estado, enlaces *Gestionar / Generar / Descargar / Eliminar*. Al pulsar *Gestionar* aparece **debajo de toda la tabla** un select con **todos** los estados y una nota. |
| `pages/lettertypes.php` | Catálogo de tipos (costo, digital/física, auto/manual, sello y firma por URL, plantilla HTML, datasets). |
| Portal `pages/solicitudes-cartas.vue` | Asistente de 3 pasos y una `v-data-table` de 6 columnas con chip de estado, botón de pago y de descarga. |
| Mesa de ayuda | Problema común #9 "Necesito una carta o constancia de estudios" (área Académico, categoría *Otro*) con un texto de 2 pasos y **sin botón** (`links = []`). |

Tipos activos: **Constancia de estudios** (gratis, digital, automática), **Carta para práctica profesional** (gratis, física, manual), **Certificación de créditos y asignaturas** ($10, digital, automática tras el pago).

### 1.2 Lo que dicen los datos (68 solicitudes, mar–nov 2026)

| Tipo | Solicitudes | Estudiantes | Estado actual | Tiempo |
|---|---|---|---|---|
| Constancia de estudios | 34 | 20 | 34 generadas | Instantáneo (0 h). |
| **Práctica profesional** | **29** | **14** | **29 en `pendiente_gestion`, ninguna atendida** | En ese estado **130 días de media, 235 días la más vieja**. |
| Certificación de créditos | 5 | 4 | 2 generadas, **3 en `pendiente_pago`** | Las pagadas tardaron 22 días en pagarse; las 3 sin pagar llevan **~180 días** abiertas. |

Otros hechos:

- **El personal nunca movió una carta de estado.** De todos los eventos, solo hay 2 de alguien distinto al estudiante: el usuario 2912 pulsó *Generar documento* en 2 cartas de práctica… y no las pasó a "lista para recoger", así que siguen en `pendiente_gestion`.
- **14 estudiantes pidieron 29 cartas de práctica**: repiten la solicitud porque no pasa nada (16 combinaciones estudiante + tipo con 2 a 6 solicitudes abiertas).
- **Nadie del personal se entera de que llegó una solicitud**: el módulo solo notifica al estudiante. No hay aviso a Secretaría, ni plazo, ni responsable, ni tarea programada de cartas.
- **Las constancias salen sin sello ni firma**: el tipo tiene `autostamp = 1` y `autosignature = 1`, pero las URL de las imágenes están vacías.
- 4 de 38 PDF no llegaron a Odoo (la copia falla en silencio y no se reintenta).
- Mesa de ayuda: el problema común de cartas tiene 0 vistas y no hay tickets sobre cartas (la mesa lleva 1 día en producción, 4 tickets en total).
- Personas con permiso de gestionar cartas: rol `gmk_secretaria_academica` (2 personas), `gmk_registros_academicos` (1), `administrative` (1); `gmk_director_academico` solo ve.

### 1.3 Dónde se pierde el tiempo

1. **La carta física/manual entra a un buzón que nadie mira.** No hay aviso, ni responsable, ni plazo. Es la causa de los 235 días.
2. **Gestionarla cuesta demasiados clics y es fácil equivocarse**: *Gestionar* → bajar hasta el final de la página → elegir entre 9 estados (aunque solo 2 o 3 sean válidos; si se elige uno inválido sale una pantalla de error) → guardar. *Generar* no avanza el estado de las cartas físicas, así que la carta queda "generada" pero el estudiante no se entera.
3. **Pagos que nunca se cierran**: ni recordatorio ni vencimiento de la factura.
4. **La generación es lenta**: se espera en línea la copia a Odoo (hasta 30 s por cURL) y, si es con costo, la creación de la factura (el portal ya tiene un aviso de "tiempo de espera" por esto).

### 1.4 Qué está mal resuelto en la experiencia

**Personal (Moodle):**
- Tabla de 7 columnas inservible en el móvil; sin búsqueda por nombre o cédula, sin paginación (carga todo y hace 2 consultas por fila).
- No se ve el contexto del estudiante (cédula, carrera, cuatrimestre, estado financiero), ni la observación que escribió, ni el historial.
- *Eliminar* borra la solicitud, sus eventos y el PDF **de forma definitiva** con un `confirm()` de JavaScript y un enlace GET.
- Rechazar no pide motivo y el motivo no se muestra en ningún lado.

**Estudiante (portal):**
- En el móvil la tabla obliga a desplazarse de lado; el estado es un chip con la palabra técnica ("Pendiente de gestión") sin decir qué sigue ni cuándo.
- No ve el motivo de un rechazo, ni dónde y cuándo recoger una carta física.
- El botón de pago sigue visible después de pagar.
- No puede cancelar su propia solicitud y nada le impide crear la misma otra vez.
- No hay forma de preguntar por "esta carta": si escribe a la mesa de ayuda, el agente no sabe de qué carta habla.

### 1.5 Defectos técnicos (se corrigen de paso)

| # | Defecto | Arreglo propuesto |
|---|---|---|
| T1 | Las 6 tablas `gmk_letter_*` solo están en `upgrade.php`, **no en `install.xml`**: una instalación nueva no las tiene. | Agregarlas a `install.xml`. |
| T2 | Si Odoo falla al crear la factura, la solicitud ya quedó grabada en `solicitada` y se lanza el error (hoy 0 casos). | Transacción: o queda en `pendiente_pago` con factura, o no queda. |
| T3 | Si el PDF falla tras el pago, la carta queda en `pagada` sin documento y sin aviso. | Reintento en tarea + alerta ANS de `pagada`. |
| T4 | `create_request` no limita quién ni cuántas veces (docentes y personal pueden pedir; duplicados ilimitados). | Una abierta por estudiante y tipo (aviso con enlace a la existente). |
| T5 | Borrado definitivo por GET. | Se reemplaza por *Anular* (estado `cancelada` con motivo); borrar queda solo para administradores del sitio, por POST. |
| T6 | Copia a Odoo síncrona y sin reintento (4 PDF perdidos). | Tarea ad hoc con reintentos; los 4 pendientes se reintentan al desplegar (con OK). |

---

## 2. Vínculo con la mesa de ayuda (tickets)

### 2.1 Modelo de datos

**Columna en el ticket, no tabla de vínculo**: `gmk_tkt_ticket.letterrequestid` (int, 0 = sin carta, índice). Un ticket habla de una sola carta; una carta puede tener varios tickets en el tiempo (uno cerrado, después otro). La "conversación vigente" de una carta es su ticket abierto más reciente. Una tabla aparte solo haría falta para "un ticket sobre varias cartas", y no es el caso.

**Nueva categoría** en Académico: **"Cartas y constancias"**, con sus agentes en `gmk_tkt_agent` (las personas de Secretaría que gestionan cartas). Esa misma lista de agentes es la que se usa para **asignar el responsable de la carta** (§3.2): el personal se configura en un solo lugar, en *Configuración de la mesa de ayuda*.

### 2.2 Flujos

**A. El estudiante pregunta en la mesa de ayuda.** El problema común #9 cambia:
- Se mueve a la categoría *Cartas y constancias*.
- Muestra arriba **sus cartas abiertas** (tipo, estado en palabras, fecha estimada) con un botón *Hablar de esta carta* en cada una.
- Debajo, el botón **Solicitar una carta** que lleva a `/solicitudes-cartas` en la misma pestaña (nuevo tipo de enlace `portal` en los problemas comunes, para rutas internas; hoy solo hay `web`/`appstore`/`playstore` y abren en otra pestaña).
- Si no tiene cartas abiertas y aun así quiere hablar con alguien, puede abrir el ticket normal (sin carta).

**B. Desde una carta (portal)**: botón **Hablar de esta carta**. Si ya hay un ticket abierto de esa carta, lo abre; si no, crea uno en *Cartas y constancias* con asunto "Carta #123 · Práctica profesional" y lo asigna al **responsable de la carta** (no por reparto ciego). No cuenta para el límite de tickets abiertos del estudiante cuando ya tiene uno de esa carta (reutiliza el mismo).

**C. Desde la bandeja de cartas (personal)**: botón **Escribir al estudiante**. Abre el ticket vigente o crea uno a nombre del estudiante con el primer mensaje del personal (nuevo `support\manager::create_for_letter()`, que no pasa por los límites del estudiante). Sirve para "te falta un dato", "ya puedes pasar a buscarla", etc.

### 2.3 Dónde se ve

| Pantalla | Qué aparece |
|---|---|
| Bandeja de tickets (Moodle) y chat | Chip **"Carta #123 · Pendiente de gestión"** en la tarjeta y en la cabecera del chat; al tocarlo abre la carta en la bandeja de cartas. |
| Chat del estudiante (portal) | Tarjeta fija arriba con la carta: estado, fecha estimada, botones *Pagar* / *Descargar* según corresponda. |
| Bandeja de cartas (Moodle) | En cada carta: **"TKT-2026-0012 · abierto · 2 sin leer"**, que abre el chat. |
| Mis cartas (portal) | Indicador de mensajes sin leer en la carta que tiene conversación. |

### 2.4 Mensajes automáticos en el ticket

Cuando la carta cambia de estado y tiene ticket abierto, se publica un mensaje del sistema (plantillas nuevas en `gmk_tkt_template`, editables y desactivables desde la configuración de la mesa):

| Cambio de la carta | Mensaje en el ticket |
|---|---|
| Asignada / reasignada | "{agente} está gestionando tu carta." |
| Pagada | "Recibimos tu pago. Tu carta estará lista aprox. el {fecha_estimada}." |
| Generada (digital) | "Tu carta está lista. Puedes descargarla desde Mis cartas." |
| Lista para recoger | "Tu carta está lista para recoger en {lugar}, {horario}." |
| Entregada | "Tu carta fue entregada." → el ticket pasa a **resuelto** (el estudiante confirma o se cierra solo a las 24 h hábiles, como cualquier ticket). |
| Rechazada / anulada | "Tu solicitud fue rechazada: {motivo}." → ticket a **resuelto**. |

Los avisos al estudiante por la carta (campanita/correo) se mantienen; si hay ticket abierto no se manda **además** el aviso del mensaje automático, para no duplicar.

---

## 3. Gestión de cartas optimizada

### 3.1 Bandeja del personal nueva (mobile first)

Nueva página `pages/letter_desk.php` + componente Vue `js/components/LetterDesk.js` (mismo patrón y estilos que `SupportDesk.js`). La página vieja redirige a la nueva.

- **Pestañas**: *Mías* · *Sin asignar* · *Vencidas* · *Por recoger* · *Todas*. Contador en cada una.
- **Tarjeta por carta** (en el móvil, una columna): semáforo ANS, tipo, estudiante + cédula, días en el estado, responsable, ticket ligado, observación del estudiante resumida.
- **Filtros**: tipo, estado, responsable, rango de fechas, búsqueda por nombre/cédula/número. Paginado en el servidor (una consulta, sin N+1).
- **Detalle** (panel lateral en escritorio, pantalla completa en el móvil): datos del estudiante (carrera, cuatrimestre, estado académico y financiero), observación, historial de eventos, versiones del PDF con vista previa, ticket.
- **Acciones como botones, solo las válidas para el estado** (adiós al select de 9 estados):
  - *Tomar* / *Asignar a…*
  - *Generar y dejar lista para recoger* (física, un clic) · *Generar y enviar* (digital) · *Regenerar* (nueva versión)
  - *Marcar entregada* (con quién la recibió, opcional)
  - *Rechazar* / *Anular*: **motivo obligatorio** con motivos frecuentes predefinidos; el motivo lo ve el estudiante.
  - *Nota interna* (no la ve el estudiante) · *Escribir al estudiante* (ticket).
- **Acciones masivas** (casillas en las tarjetas): asignar, generar y dejar listas para recoger, marcar entregadas, anular duplicadas. Antes de aplicar muestra cuántas cambian y cuáles se saltan por estado inválido; cada carta se procesa aparte (un fallo no tumba las demás) y se informa el resultado.
- Servicios web nuevos `local_grupomakro_letters_desk_*` (listar, detalle, acción, masiva), con `require_capability('managerequests')`.

### 3.2 Responsable y plazos

Columnas nuevas en `gmk_letter_request`: `assignedto`, `assigned_at`, `status_since`, `due_at`, `sla_level`, `sla_notified_at`, `pickup_note`.

- **Asignación automática al entrar a gestión** (`pendiente_gestion` o `pagada` sin generación automática): el agente de *Cartas y constancias* con menos cartas abiertas que esté en horario (`support\manager::least_loaded` + presencia). Si no hay nadie disponible, queda *Sin asignar* y se avisa a todo el equipo.
- **Aviso al personal cuando llega una carta** (hoy no existe): proveedor nuevo `letter_new_request` al responsable (o al equipo si no hay).
- `due_at` se calcula al entrar a cada estado con `business_hours::add_minutes` y el reloj del responsable o del área Académico (mismo criterio que los tickets: descuenta fuera de horario, domingos y feriados de `gmk_holidays`).

### 3.3 Generación y descarga más rápidas

- La copia a Odoo pasa a una **tarea ad hoc** con reintentos: generar tarda lo que tarda el PDF (~0,1 s), no 30 s.
- La factura de Odoo se sigue creando en línea (el estudiante necesita el enlace), pero dentro de una transacción (T2) y con mensaje claro si Odoo no responde ("intenta en unos minutos", sin dejar basura).
- **Sello y firma**: el catálogo pasa de URL a **subir imagen** (área de archivos del plugin). Hay que cargar las imágenes reales; hasta entonces las constancias siguen saliendo sin ellos (decisión 6).
- Descarga en el portal por un enlace firmado (`pluginfile`) en vez de base64 dentro del JSON: abre directo en el móvil.

### 3.4 Visibilidad para el estudiante (portal)

- **Tarjetas** en lugar de tabla. Cada una con una línea de pasos según el tipo (por ejemplo, física: *Solicitada → En gestión → Lista para recoger → Entregada*; con costo: *Solicitada → Pago → Lista*), el estado en palabras ("La está preparando Secretaría Académica") y **fecha estimada** (`due_at`).
- Botón según el estado: *Pagar* (solo en `pendiente_pago`), *Descargar*, *Hablar de esta carta*, *Cancelar* (solo antes de que se empiece a gestionar).
- Lugar y horario de recogida (texto por tipo en el catálogo) y motivo de rechazo visibles.
- Al pedir: si ya tiene una abierta del mismo tipo, aviso "Ya tienes esta carta en trámite" con enlace a ella y a *Hablar de esta carta*.

---

## 4. Alertas y ANS

### 4.1 Plazos configurables

Columna `sla_json` en `gmk_letter_type` (editable en el catálogo), con un valor general por defecto en la configuración. Minutos **hábiles** por estado:

| Estado | Qué mide | Propuesta por defecto |
|---|---|---|
| `pendiente_gestion` | Que el personal la deje lista | **Práctica profesional: 2 días hábiles**; otros manuales: 2 días hábiles |
| `pagada` | Que se genere tras el pago (solo debería durar segundos) | 1 hora hábil → si no, algo falló (T3) |
| `pendiente_pago` | Que el estudiante pague | Recordatorio al estudiante a los 3 días; **anulación automática a los 15 días** (decisión 4) |
| `pendiente_recoleccion` | Que el estudiante la recoja | Recordatorio al estudiante a los 5 días hábiles; aviso al personal a los 15 |

### 4.2 Niveles y destinatarios (sin duplicar)

Tarea nueva `letters_sla` cada 15 min. Igual que el escalamiento de tickets: cada nivel se dispara **una sola vez por estado**; `sla_level` se reinicia al cambiar de estado; si una pasada salta varios niveles, manda **un solo aviso combinado**.

| Nivel | Cuándo | A quién |
|---|---|---|
| 1 · Por vencer | 75 % del plazo | Responsable (o equipo si no hay) |
| 2 · Vencida | 100 % | Responsable + supervisores de la mesa (`support_supervise` / `gmk_tkt_person` supervisor) |
| 3 · Crítica | 200 % | + gerencia (Joyce Muñoz, la misma de los tickets) |

- Nivel 3 se repite cada `tkt_red_repeat` horas hábiles mientras siga vencida (misma opción que los tickets).
- Las cartas vencidas entran al **resumen diario** que ya manda `support_digest` (una sección más, no un correo aparte).
- Proveedores nuevos en `db/messages.php`: `letter_new_request`, `letter_sla_warning`, `letter_sla_breach`, `letter_escalation`, `letter_payment_reminder`, `letter_pickup_reminder`.
- Las cartas vencidas también encienden la píldora del indicador flotante (`badge.php`) para quien tenga cartas a su nombre.

### 4.3 Métricas para el dashboard administrativo

Clase `classes/local/letters/metrics.php`, sin pantalla propia, para que la otra sesión la consuma:

```php
metrics::pending_by_owner(array $filters = []): array
// Filas: una por responsable + una "sin asignar". Cada fila:
// userid, fullname, roles[], total, by_status{estado: n},
// on_time, due_soon, overdue, critical, oldest_minutes (hábiles), avg_minutes, sla_compliance_30d (%)
// $filters: lettertypeid, status, roleshortname, from, to

metrics::summary(int $from, int $to): array
// por tipo: creadas, terminadas, % dentro del ANS, mediana y p90 del ciclo (hábiles), rechazos y motivos
```

**Contrato acordado con la sesión del dashboard administrativo** (19-nov-2026; ella lo consume, no duplica reglas):

```php
\local_grupomakro_core\local\letters\manager::get_pending_for_staff(int $userid, string $scope = 'mine', int $limit = 3): array
// scope 'mine': cartas con assignedto = $userid.
// scope 'team': todas las abiertas si $userid tiene managerequests (incluye "sin asignar"); si no, [].
// => ['counts' => ['total', 'warning', 'breached', 'critical'],
//     'items'  => [['type', 'ref', 'title', 'subtitle', 'since', 'due', 'level', 'owner_userid'|null, 'url'], ...]]
// level = sla_level: 0 en plazo, 1 por vencer (75 %), 2 vencida (100 %), 3 crítica (200 %).
// type: 'elaborar' (pendiente_gestion), 'sin_recoger' (pendiente_recoleccion), 'atascada' (pagada/solicitada).
// pendiente_pago no entra (espera al estudiante). Orden: level DESC, due ASC. Delegará en metrics.
```

Las **alertas de ANS de cartas las envía solo la tarea `letters_sla`** de este módulo; la tarea de escalamiento del dashboard las ignora (igual que con los tickets).

Servicio web `local_grupomakro_letters_sla_metrics` (lectura, capacidad nueva `local/grupomakro_core:letters_viewmetrics`, que se da a gerencia, dirección académica y a quien tenga `viewallletterrequests`). Dejo la firma documentada en el traspaso para la sesión del dashboard.

---

## 5. Cambios de datos (al desplegar)

Todo en el paso de upgrade, aditivo y con verificación antes y después, siguiendo la regla de integridad:

- Columnas nuevas (§3.2, `gmk_tkt_ticket.letterrequestid`, `gmk_letter_type.sla_json` y `pickup_note`).
- Categoría *Cartas y constancias* (sin agentes hasta que se carguen, decisión 1); el problema común #9 se mueve ahí.
- Solicitudes abiertas existentes: `status_since` = último cambio de estado real; **`due_at` desde el despliegue, no desde la fecha original** (si no, las 29 de práctica disparan el nivel 3 en la primera pasada). Ver decisión 3.

---

## 6. Decisiones que necesito de Ramiro

1. **Responsables**: ¿quiénes son los agentes de *Cartas y constancias* (los que gestionan y reciben las alertas)? ¿Las 2 personas de `gmk_secretaria_academica` y la de `gmk_registros_academicos`? ¿Supervisor = el mismo de la mesa de ayuda?
2. **Plazos**: ¿sirven los de §4.1 (práctica profesional lista en 2 días hábiles)?
3. **Las 29 cartas de práctica atrasadas (14 estudiantes)**: propongo, antes de encender alertas, que Secretaría las revise con la bandeja nueva: anular las duplicadas (dejar la más reciente por estudiante, ~15 se anulan con motivo "solicitud duplicada") y gestionar las 14 restantes. ¿O prefieres que les corra el plazo desde el despliegue y las atiendan con alertas?
4. **Pagos pendientes**: ¿anulación automática a los 15 días sin pago? (Solo se anula en Moodle; la factura en Odoo la tendría que anular Finanzas, o agrego la anulación por el proxy si existe la ruta.) Las 3 actuales tienen ~180 días.
5. **Cierre del ticket**: cuando la carta queda entregada o rechazada, ¿el ticket pasa solo a *resuelto* (§2.4)?
6. **Sello y firma**: ¿quién me pasa las imágenes? Hasta tenerlas, ¿apago `autostamp`/`autosignature` en la constancia para no prometer algo que no sale?
7. **Borrado**: ¿de acuerdo con quitar *Eliminar* al personal (queda *Anular*) y dejarlo solo a administradores del sitio?

---

## 7. Entregas propuestas

Rama propia en cada repositorio (`feat/cartas-tickets-ans` desde `origin/master` y desde `origin/isi-prod`), dos entregas para poder probar por partes:

| Entrega | Contenido |
|---|---|
| **1. Base + vínculo** | T1–T6, responsable y asignación, aviso al personal de carta nueva, columna del ticket, categoría y flujo del problema común, *Hablar de esta carta* (portal y Moodle), mensajes automáticos, tarjetas del estudiante. |
| **2. Bandeja + ANS** | Bandeja nueva con filtros y acciones masivas, plazos por tipo y estado, tarea `letters_sla` con niveles, recordatorios, resumen diario, `metrics.php` + servicio web. |

Cada entrega con: `cli/letters_selftest.php` (flujo completo dentro de una transacción con *rollback*: crear, asignar, ticket ligado, mensajes automáticos, vencer plazos, alertas sin duplicar, métricas), plan de pruebas `docs/planning/PRUEBAS_CARTAS.md`, `php -l`, `nuxt build` y revisión a 375 px.

**Nada se publica sin OK explícito**: ni staging (selftest), ni push a `master` / `isi-prod`, ni los cambios de datos de §5 y decisión 3.

---

## 8. Decisiones de Ramiro (19-nov-2026) y cómo quedaron

Ramiro aprobó las propuestas de §6, salvo las alertas: **"necesito que se empiecen a generar de una vez"**. Además pidió que, por ahora, la generación automática no sea el camino principal: **la bandeja funciona como buzón y el área académica carga la carta que hizo (Word o PDF), con lo que la solicitud se cierra; para las solicitudes anteriores, solo preguntar si ya se gestionaron.**

| # | Decisión | Implementado |
|---|---|---|
| 1 | Responsables: Secretaría Académica (2) y Registros Académicos (1) | El upgrade crea el tema "Cartas y constancias" y pone como agentes a quienes tienen esos roles (en prod: Jean Remice, Veronica Rangel, Lizbeth Aizprua). Supervisores y gerencia: los de la mesa de ayuda. |
| 2 | Plazo 2 días hábiles para preparar | Por defecto 1.560 min hábiles (2 × 08:00–21:00), editable por tipo en el catálogo. Aviso al personal si no se retira a los 15 días hábiles. |
| 3 | Atraso: alertas desde ya, sin triage previo | Verificado antes: las 32 abiertas son de estudiantes reales (sin cuentas ni textos de prueba, todos con plan y activos). El upgrade anula en silencio las **15 duplicadas** (conserva la más reciente, reversible) y marca las **14 restantes** como "anteriores": su plazo corre desde la fecha real, así que alertan como críticas en la primera pasada, en **un solo mensaje agrupado** por persona. En la bandeja cada una pregunta "¿Ya se gestionó?" → "Sí, ya se entregó" (la cierra sin documento) o "No, gestionar ahora". |
| 4 | Anular a los 15 días sin pago | Sí, con recordatorio a los 3 días. Si el estudiante paga después, el webhook la reactiva. La factura en Odoo no se toca. |
| 5 | Ticket a "resuelto" al terminar la carta | Sí (lista para descargar, entregada, rechazada o anulada), con mensaje automático. |
| 6 | Sello y firma | El upgrade apaga `autostamp`/`autosignature` donde no hay imagen (no salían igual). Falta que alguien entregue las imágenes. |
| 7 | Quitar "Eliminar" al personal | Solo administradores del sitio, con confirmación; el personal usa "Anular" con motivo. |
| — | Cargar la carta hecha por el personal | "Cargar carta (PDF/Word)" (máx. 10 MB) con destino: el estudiante la descarga / lista para retirar / ya se entregó. "Generar desde la plantilla" queda como opción secundaria. |

### Diferencias con la propuesta (§2–§4)

- Proveedores de mensajes: 3 en vez de 6 (`letter_new_request`, `letter_sla_alert` para los tres niveles y el resumen, `letter_student_reminder`).
- El resumen diario de cartas lo manda la tarea `letters_sla` (mismo horario `tkt_digest_hour`), aparte del resumen de tickets.
- No se agregó la píldora del indicador flotante ni la descarga por enlace firmado (`pluginfile`): la descarga del portal sigue por el servicio existente (ahora también sirve Word).
- Si hay ticket abierto, el aviso normal de la carta al estudiante se mantiene: el mensaje automático del ticket no notifica por sí solo, así que no hay duplicado.
- El servicio `local_grupomakro_letters_sla_metrics` devuelve `-1` donde no hay dato (porcentajes y ciclos).
