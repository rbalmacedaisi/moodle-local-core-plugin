# Agente semanal en la nube: análisis de tickets de la mesa de ayuda

Este archivo **es el prompt** de la rutina programada de Claude Code en la nube
(creada con `/schedule`, cron semanal). La rutina clona este repositorio y sigue estas
instrucciones. Escribe todo lo visible en español.

Pedido de Ramiro (19-nov-2026): un agente que cada semana revise cómo se cerraron los
tickets, identifique cómo se solucionó cada problema, lo tipifique y cuantifique, y deje
sugerencias (problemas comunes, mejoras a respuestas, respuestas rápidas) que se añaden
con un clic por área en Moodle > Configuración de la mesa de ayuda > Asistente en la nube.

## Entorno de la rutina

- Red: solo hace falta salida HTTPS a `lms.isi.edu.pa` (lista de dominios permitidos del
  entorno en la nube).
- Secreto: variable de entorno `MOODLE_AI_TOKEN` con el token que se genera en
  Configuración > Asistente en la nube > Conexión. **Nunca** se escribe en el repositorio,
  en archivos, en commits ni en la salida.
- Endpoint: `https://lms.isi.edu.pa/webservice/rest/server.php` (REST, `moodlewsrestformat=json`).
- La rutina **no** modifica el repositorio: no hace commits, ramas ni PR.

## Reglas duras

1. Solo llamas a dos funciones: `local_grupomakro_support_ai_export_closed` y
   `local_grupomakro_support_ai_submit`. Ninguna otra URL ni función.
2. Los datos ya vienen anonimizados por el servidor (marcadores `[SOLICITANTE]`,
   `[AGENTE]`, `[NOMBRE]`, `[CORREO]`, `[TELEFONO]`, `[CEDULA]`, `[NUMERO]`). No intentes
   deducir identidades. Nunca escribas nombres ni datos personales en lo que envías.
3. Psicología y los temas confidenciales no vienen y no se tratan.
4. Si una llamada devuelve `exception`/`errorcode`, o el JSON no es válido, **detente** y
   reporta el error tal cual (sin el token). No reintentes más de una vez.
5. No imprimas el contenido de los tickets en tu respuesta final: solo conteos y títulos
   de sugerencias.

## Pasos

### 1. Leer los tickets

```bash
curl -sS --fail-with-body "https://lms.isi.edu.pa/webservice/rest/server.php" \
  --data-urlencode "wstoken=${MOODLE_AI_TOKEN}" \
  -d wsfunction=local_grupomakro_support_ai_export_closed \
  -d moodlewsrestformat=json -d limit=200 > respuesta.json
python3 -c "import json;r=json.load(open('respuesta.json'));assert 'json' in r, r;open('export.json','w').write(r['json'])"
```

`export.json` tiene `format: gmk-support-ai-export/1`, `period {from,to}`, `tickets[]` y
`context`. Cada ticket: `id, areaid, area, categoryid, category, requester
(estudiante|docente), status, subject, created, hours_to_first_response,
hours_to_resolution, reopened, rating (0-5), rating_solved (si|no|sin respuesta),
rating_comment, faq_tried {id, problem} | null, messages [{from: solicitante|agente,
text, attachments}], truncated`. `context`: `areas` (con `topics`), `faqs` (problemas
comunes con `views/solved/notsolved`), `canned`, `taxonomy` (tipos usados antes, con
conteo) y `previous_suggestions` (pendientes o atendidas hace poco).

Solo trae tickets aún no tipificados: si una semana falla el envío, la siguiente los vuelve
a traer.

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

### 4. Enviar

Arma `run.json`:

```json
{
  "runkey": "2026-W47",
  "model": "Claude (rutina en la nube de Claude Code)",
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

Si no hubo tickets, envía `classifications` y `suggestions` vacíos: la ejecución queda en
el historial igual.

Primero valida sin guardar:

```bash
curl -sS --fail-with-body "https://lms.isi.edu.pa/webservice/rest/server.php" \
  --data-urlencode "wstoken=${MOODLE_AI_TOKEN}" \
  -d wsfunction=local_grupomakro_support_ai_submit -d moodlewsrestformat=json \
  -d dryrun=1 --data-urlencode "run@run.json"
```

Revisa `report`:

- `classified` debe ser igual al número de tickets exportados.
- Revisa los motivos de `skipped` y `suggestions_skipped`.

Si el rechazo es un error tuyo, corrige `run.json` y valida una sola vez más. Después
envía con `dryrun=0`. Si la respuesta es `already_imported`, esa semana ya se envió: no
hagas nada más.

### 5. Respuesta final

Cinco líneas como máximo:

- tickets tipificados por área;
- los 3 problemas más frecuentes;
- sugerencias nuevas por área (tipo y título);
- rechazos, si los hubo;
- recordatorio: se revisan en Configuración > Asistente en la nube.

## Qué valida el servidor (para no depender del agente)

- Solo acepta tickets resueltos o cerrados, no confidenciales, fuera de Psicología y aún
  no tipificados. Una ejecución no se importa dos veces: `runkey` es único.
- La evidencia de cada sugerencia (cuántos tickets la respaldan) la cuenta el servidor con
  las tipificaciones válidas del área.
- Sanea todos los textos (sin etiquetas, con largo máximo) y descarta los botones que no
  sean `https`, los tipos desconocidos y las áreas confidenciales.
- Rechaza envíos de más de 2 MB, más de 600 tipificaciones o más de 60 sugerencias.
- Vuelve a anonimizar los ejemplos.
- No vuelve a proponer, durante 60 días, lo que alguien descartó.
- Nada llega a estudiantes ni a docentes hasta que una persona pulsa **Añadir**. Añadir
  pasa por la misma validación y auditoría que la configuración manual.
