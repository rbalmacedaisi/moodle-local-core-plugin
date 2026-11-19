# Asistente semanal de la mesa de ayuda (agente de Claude Code)

Pedido de Ramiro (19-nov-2026): un agente que cada semana revise cómo se cerraron los
tickets, identifique cómo se solucionó cada problema, lo tipifique y cuantifique, y deje
sugerencias de problemas comunes y respuestas en la configuración de la mesa de ayuda,
para añadir con un clic por área.

Decisión de Ramiro: el agente **no** corre dentro de Moodle ni con una API key. Es una
**tarea programada de Claude Code** en su PC (app de escritorio). Moodle solo pone dos
CLI y la pantalla de sugerencias. Psicología queda **excluida por completo**.

Este archivo es la copia versionada del prompt de la tarea programada
(`C:\Users\Ramiro\.claude\scheduled-tasks\asistente-mesa-ayuda\SKILL.md`). Si se cambia
uno, se cambia el otro.

## Cómo fluye

```
PC de Ramiro (Claude Code, semanal)                 VPS Moodle (Hostinger)
-----------------------------------                 -----------------------
1. ssh ... support_ai_export.php      ───────────▶  lee tickets resueltos/cerrados sin analizar,
   (recibe JSON YA anonimizado)       ◀───────────  anonimiza AQUÍ (nombres, correos, teléfonos,
                                                     cédulas, terceros, tokens), sin notas internas,
                                                     sin Psicología ni temas confidenciales
2. Claude analiza y escribe resultados.json
3. scp + support_ai_import.php --dry-run ─────────▶  valida, cuenta y revierte
4. support_ai_import.php               ───────────▶  guarda tipificaciones y sugerencias
                                                     (gmk_tkt_ai_*); nada llega a estudiantes
5. Ramiro / gerencia en Configuración > Asistente IA: Añadir · Editar y añadir · Descartar
```

- Ningún dato personal sale del servidor: la anonimización se hace en PHP antes de
  imprimir el JSON (`assistant::anonymize`).
- La evidencia de cada sugerencia (cuántos tickets la respaldan) la cuenta el servidor
  con las tipificaciones válidas, no el agente.
- Idempotencia: `runkey` único (semana ISO) y un ticket se tipifica una sola vez; la
  exportación ya no trae tickets analizados.
- Lo descartado no se vuelve a proponer en 60 días.
- Añadir pasa por `settings::apply('faq'|'canned', 'save', …)`: misma validación y
  auditoría (`config_faq`, `ai_suggestion_add`) que la configuración manual.

## Formato de resultados.json

```json
{
  "runkey": "2026-W47",
  "model": "Claude (tarea programada de Claude Code)",
  "period": {"from": 1763000000, "to": 1763600000},
  "usage": {"input_tokens": 0, "output_tokens": 0},
  "summary": "Una o dos frases en español sobre la semana.",
  "classifications": [
    {"ticketid": 123, "problemtype": "no_encuentra_clase_virtual",
     "problemlabel": "No encuentra la clase virtual",
     "resolutiontype": "guia_pasos", "resolutionlabel": "Se le guió paso a paso",
     "resolved": "si"}
  ],
  "suggestions": [
    {"kind": "faq_new", "areaid": 2, "categoryid": 5, "problemtype": "no_encuentra_clase_virtual",
     "problem": "No encuentro mi clase virtual", "answer": "1. ...\n2. ...",
     "links": [{"label": "Abrir la plataforma", "url": "https://students.isi.edu.pa/", "kind": "web"}],
     "audience": "student", "rationale": "Por qué conviene", "ticketids": [123, 130],
     "examples": ["[SOLICITANTE]: no me aparece el botón ..."]},
    {"kind": "faq_improve", "faqid": 7, "problemtype": "videoconferencia_no_abre",
     "answer": "1. ...", "links": [], "rationale": "...", "ticketids": [140]},
    {"kind": "canned", "areaid": 1, "problemtype": "revision_de_nota",
     "title": "Revisión de nota: plazos", "body": "Texto para el agente", "ticketids": [150]}
  ]
}
```

- `resolved`: `si`, `no` o `incierto`.
- `problemtype` / `resolutiontype`: etiqueta corta en minúsculas con guiones bajos; el
  servidor la normaliza. Reutilizar las de `context.taxonomy` cuando aplique.
- `kind`: `faq_new` (problema común nuevo), `faq_improve` (reemplaza la respuesta de un
  problema común existente), `canned` (respuesta rápida para agentes; se guarda en
  `gmk_tkt_canned`, la bandeja todavía no la ofrece: fase 3).
- `audience`: `all`, `student` o `teacher`. `links.kind`: `web`, `appstore`, `playstore`;
  solo `https`.

## Prompt de la tarea programada

(El texto exacto está en el SKILL.md; resumen de las reglas que sigue)

1. Una ejecución por semana ISO: si ya existe la carpeta de la semana con
   `import-report.json` correcto, no hace nada.
2. Exporta por SSH con `sudo -u www-data php7.4 …/cli/support_ai_export.php --limit=200`.
3. Tipifica cada ticket y propone como máximo 3 sugerencias por área, con estas reglas:
   - `faq_new`: el mismo problema en 3 o más tickets (sumando la historia de
     `context.taxonomy`), que la persona pueda resolver sola con pasos, que no cubra ya un
     problema común existente ni esté en `previous_suggestions`.
   - `faq_improve`: un problema común con `notsolved` ≥ 5 y ≥ `solved`, o tickets abiertos
     tras probarlo (`faq_tried`) que muestran el paso que falta.
   - `canned`: 3 o más tickets donde el agente respondió en esencia lo mismo y hace falta
     una persona (no autoservicio).
   - Nunca nombres ni datos personales; nunca enlaces inventados (solo los que aparecen en
     tickets o problemas comunes, o dominios oficiales: isi.edu.pa, apps.apple.com,
     play.google.com, google.com).
4. Sube el JSON a `/tmp`, importa primero con `--dry-run`, luego de verdad, y borra el
   archivo temporal.
5. Deja `informe.md` en `C:\Users\Ramiro\Documents\Claude\asistente-mesa-ayuda\<semana>\`.
6. No ejecuta ningún otro comando en el servidor.
