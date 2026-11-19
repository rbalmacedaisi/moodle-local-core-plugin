# Análisis semanal de tickets de la mesa de ayuda (tarea programada local)

Este archivo **es el prompt** de la tarea programada local de la app de Claude
`analisis-semanal-mesa-ayuda` (lunes 7:00, hora de Panamá). La tarea corre en el
equipo de Ramiro, entra por SSH al servidor de Moodle y usa dos CLI del plugin. Escribe
todo lo visible en español.

Pedido de Ramiro (19-nov-2026): un agente que cada semana revise cómo se cerraron los
tickets, identifique cómo se solucionó cada problema, lo tipifique y cuantifique, y deje
sugerencias (problemas comunes, mejoras a respuestas, respuestas rápidas) que se añaden
con un clic por área en Moodle > Configuración de la mesa de ayuda > Asistente semanal.

No hay proveedor de IA, API key, token ni servicio web en Moodle para esto: la versión
20261001127 quitó el servicio `gmk_support_ai`, sus tokens y el usuario técnico.

## Entorno de la tarea

- Acceso: SSH al servidor de Moodle de producción con el usuario y la llave de
  `RUNBOOK-HOSTINGER.md` (repositorio de infraestructura). La llave nunca se copia al
  repositorio, a archivos temporales compartidos ni a la salida.
- Los CLI se corren desde la raíz de Moodle del servidor como `www-data`:
  `sudo -u www-data php7.4 local/grupomakro_core/cli/<script>.php …`.
- Archivos de trabajo: solo en `/tmp/gmk_ai_<semana>/` del servidor (creado con
  `umask 077`) y en el directorio temporal local de la tarea. Se borran al terminar.
- La tarea **no** modifica el repositorio: no hace commits, ramas ni PR.

## Reglas duras

1. En el servidor solo corres `support_ai_export.php` y `support_ai_import.php` (más
   `mkdir`, `cat`, `rm` de tus archivos de trabajo). Ningún otro comando que escriba,
   ninguna consulta directa a la base de datos.
2. Los datos ya vienen anonimizados por el servidor (marcadores `[SOLICITANTE]`,
   `[AGENTE]`, `[NOMBRE]`, `[CORREO]`, `[TELEFONO]`, `[CEDULA]`, `[NUMERO]`). No intentes
   deducir identidades. Nunca escribas nombres ni datos personales en lo que importas.
3. Psicología y los temas confidenciales no vienen y no se tratan.
4. Si un CLI termina con error o el JSON no es válido, **detente** y reporta el error tal
   cual. No reintentes más de una vez.
5. No imprimas el contenido de los tickets en tu respuesta final: solo conteos y títulos
   de sugerencias.

## Pasos

### 1. Leer los tickets

```bash
ssh <servidor-moodle> 'cd <raíz de Moodle> && sudo -u www-data php7.4 \
  local/grupomakro_core/cli/support_ai_export.php --limit=200' > export.json
```

`export.json` (copia local) tiene `format: gmk-support-ai-export/1`, `period {from,to}`,
`tickets[]` y `context`. Cada ticket: `id, areaid, area, categoryid, category, requester
(estudiante|docente), status, subject, created, hours_to_first_response,
hours_to_resolution, reopened, rating (0-5), rating_solved (si|no|sin respuesta),
rating_comment, faq_tried {id, problem} | null, messages [{from: solicitante|agente,
text, attachments}], truncated`. `context`: `areas` (con `topics`), `faqs` (problemas
comunes con `views/solved/notsolved`), `canned`, `taxonomy` (tipos usados antes, con
conteo) y `previous_suggestions` (pendientes o atendidas hace poco).

Solo trae tickets aún no tipificados: si una semana falla la importación, la siguiente
los vuelve a traer.

### 2. Tipificar cada ticket

Para cada ticket:

- `problemtype`: etiqueta corta en minúsculas con guiones bajos. **Reutiliza** la de
  `context.taxonomy` del mismo área cuando sea el mismo problema.
- `problemlabel`: frase corta en español.
- `resolutiontype` y `resolutionlabel`: cómo se resolvió de verdad según la conversación
  (por ejemplo `guia_pasos`, `cambio_navegador`, `gestion_interna`, `derivado_otra_area`,
  `informacion_plazos`, `sin_respuesta_estudiante`).
- `resolved`: `si`, `no` o `incierto`. Usa la conversación, `rating_solved` y `reopened`.

### 3. Cuantificar y proponer

Cuenta por área y `problemtype`: esta semana más `context.taxonomy`. Propón **como máximo
3 sugerencias por área**, solo con evidencia. Una semana sin sugerencias está bien.

- `faq_new` (problema común nuevo): el mismo problema en 3 o más tickets (sumando la
  historia), que la persona pueda resolver **sola** con pasos, que no esté ya cubierto por
  `context.faqs` ni en `previous_suggestions`.
  Campos: `areaid`, `categoryid` (tema al que va la solicitud si no se soluciona; 0 = el
  primero), `problemtype`, `problem` (como lo diría el estudiante, máx. 255), `answer`,
  `links`, `audience` (`all|student|teacher`), `rationale`, `ticketids`, `examples`.
- `faq_improve` (mejorar una respuesta existente): un problema común con
  `notsolved >= 5` y `notsolved >= solved`, o tickets cuyo `faq_tried` es ese problema y
  muestran el paso que falta.
  Campos: `faqid`, `problemtype`, `answer` (respuesta completa nueva), `links` (opcional),
  `rationale` (qué faltaba), `ticketids`, `examples`.
- `canned` (respuesta rápida para agentes): 3 o más tickets en los que el agente respondió
  en esencia lo mismo y hace falta una persona.
  Campos: `areaid`, `problemtype`, `title` (máx. 150), `body` (puede saludar con
  `[SOLICITANTE]`), `rationale`, `ticketids`.

Formato de `answer`: una línea `1. …` por paso, `- …` para viñetas, `**negrita**`.
Sin HTML.

Botones (`links`): máximo 6, solo `https`, `kind` `web|appstore|playstore`. Solo usa
enlaces que aparezcan en los tickets o en `context.faqs`, o de isi.edu.pa,
apps.apple.com, play.google.com o google.com. Nunca inventes URLs.

`examples`: hasta 3 frases cortas copiadas de `export.json` (ya anonimizadas, máx. 300
caracteres).

### 4. Importar

Arma `run.json`:

```json
{
  "runkey": "2026-W47",
  "model": "Claude (tarea programada local de la app de Claude)",
  "period": {"from": 0, "to": 0},
  "usage": {"input_tokens": 0, "output_tokens": 0},
  "summary": "1 a 3 frases sobre la semana.",
  "classifications": [
    {"ticketid": 123, "problemtype": "no_encuentra_clase_virtual",
     "problemlabel": "No encuentra la clase virtual", "resolutiontype": "guia_pasos",
     "resolutionlabel": "Se le guió paso a paso", "resolved": "si"}
  ],
  "suggestions": [
    {"kind": "faq_new", "areaid": 2, "categoryid": 5, "problemtype": "no_encuentra_clase_virtual",
     "problem": "No encuentro mi clase virtual", "answer": "1. …\n2. …",
     "links": [{"label": "Abrir la plataforma", "url": "https://students.isi.edu.pa/", "kind": "web"}],
     "audience": "student", "rationale": "…", "ticketids": [123, 130],
     "examples": ["[SOLICITANTE]: no me aparece el botón …"]}
  ]
}
```

- `runkey` = semana ISO actual (`AAAA-Wnn`).
- `period` = el de `export.json`.

Si no hubo tickets, importa `classifications` y `suggestions` vacíos: la ejecución queda
en el historial igual.

Súbelo al servidor y valida primero sin guardar:

```bash
ssh <servidor-moodle> 'umask 077 && mkdir -p /tmp/gmk_ai_2026-W47 && cat > /tmp/gmk_ai_2026-W47/run.json' < run.json
ssh <servidor-moodle> 'cd <raíz de Moodle> && sudo -u www-data php7.4 \
  local/grupomakro_core/cli/support_ai_import.php --file=/tmp/gmk_ai_2026-W47/run.json --dry-run'
```

(Si `www-data` no puede leer el archivo, usa `chmod 644` solo sobre `run.json`; no tiene
datos personales.)

Revisa el informe (JSON):

- `status` debe ser `dry_run`;
- `classified` debe ser igual al número de tickets exportados;
- revisa los motivos de `skipped` y `suggestions_skipped`.

Si el rechazo es un error tuyo, corrige `run.json` y valida una sola vez más. Después
importa sin `--dry-run`. Si `status` es `already_imported`, esa semana ya se importó: no
hagas nada más. Al final borra `/tmp/gmk_ai_<semana>/` en el servidor y tus copias
locales de `export.json` y `run.json`.

### 5. Respuesta final

Cinco líneas como máximo:

- tickets tipificados por área;
- los 3 problemas más frecuentes;
- sugerencias nuevas por área (tipo y título);
- rechazos, si los hubo;
- recordatorio: se revisan en Configuración de la mesa de ayuda > Asistente semanal.

## Qué valida el servidor (para no depender del agente)

- Solo acepta tickets resueltos o cerrados, no confidenciales, fuera de Psicología y aún
  no tipificados. Una ejecución no se importa dos veces: `runkey` es único.
- La evidencia de cada sugerencia (cuántos tickets la respaldan) la cuenta el servidor con
  las tipificaciones válidas del área.
- Sanea todos los textos (sin etiquetas, con largo máximo) y descarta los botones que no
  sean `https`, los tipos desconocidos y las áreas confidenciales.
- Rechaza archivos de más de 2 MB, más de 600 tipificaciones o más de 60 sugerencias.
- Vuelve a anonimizar los ejemplos.
- No vuelve a proponer, durante 60 días, lo que alguien descartó.
- Nada llega a estudiantes ni a docentes hasta que una persona pulsa **Añadir**. Añadir
  pasa por la misma validación y auditoría que la configuración manual.
