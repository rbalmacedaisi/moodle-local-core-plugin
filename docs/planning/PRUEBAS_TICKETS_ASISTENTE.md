# Pruebas: asistente semanal de la mesa de ayuda (20261001126 → 20261001127)

Prompt y formato del análisis: `docs/agents/analisis_semanal_tickets.md`.

Diseño (decisión de Ramiro, 19-nov-2026):

- No hay proveedor de IA, API key, token ni servicio web en Moodle.
- El análisis es una **tarea programada local de la app de Claude**
  (`analisis-semanal-mesa-ayuda`, lunes 7:00). Entra por SSH y usa los CLI
  `support_ai_export.php` (JSON anonimizado) y `support_ai_import.php` (con `--dry-run`).
- Moodle guarda las tipificaciones y sugerencias en `gmk_tkt_ai_*`. Se revisan en
  Configuración de la mesa de ayuda > **Asistente semanal** (Sugerencias, Tipificación,
  Historial). Añadir, Editar y añadir, y Descartar quedan en la auditoría.
- Psicología queda excluida por completo.
- 20261001127 quita lo que dejó 20261001126 para la rutina en la nube:
  - el servicio `gmk_support_ai`, sus tokens, usuarios autorizados y funciones;
  - las funciones `support_ai_export_closed`, `support_ai_submit` y `support_ai_token`;
  - el usuario técnico `gmk_ia_asistente`: se borra si no dejó datos y se suspende si
    dejó;
  - el rol `gmk_ia_asistente`;
  - la capacidad `support_ai_agent`.

  Queda el evento `ai_service_removed` en `gmk_tkt_event`. No toca ningún otro servicio ni
  token.

## 0. Antes de desplegar

| # | Qué | Resultado |
|---|-----|-----------|
| 0.1 | `php -l` en todo lo tocado, `node --check` en los JS | Limpio |
| 0.2 | Ninguna referencia a `ai_token`, `ai_export_closed`, `ai_submit`, `gmk_support_ai` o `support_ai_agent` fuera de la limpieza, el autotest y los documentos | Limpio |

## 1. Staging (solo CLI)

Requiere OK de Ramiro: escribe en isidb_staging.

1. Antes, como referencia, contar los tokens de servicios web
   (`SELECT COUNT(*) FROM mdl_external_tokens`) y anotar si existen el servicio
   `gmk_support_ai` y el usuario `gmk_ia_asistente`.
2. Subir el código y correr
   `sudo -u www-data php7.4 admin/cli/upgrade.php --non-interactive`.
   Esperado:
   - la línea `gmk_support_ai: {...}` con lo que quitó;
   - el conteo de tokens baja solo en los del servicio `gmk_support_ai` (0 si nunca se
     generó uno);
   - no queda el servicio, sus funciones ni la capacidad `support_ai_agent`.
3. Correr el autotest (agente simulado, todo se revierte):
   `sudo -u www-data php7.4 local/grupomakro_core/cli/support_ai_selftest.php --student=<id de prueba>`.
   Esperado: todo PASS y "ejecuciones: N antes, N después". Qué cubre:
   - **Anonimización:** nombre, usuario, correo, teléfono, cédula, terceros, tokens en
     enlaces y número TKT intacto. Sin notas internas, sin Psicología ni temas
     confidenciales.
   - **Importación:** `dry-run` no deja nada; la evidencia la calcula el servidor;
     idempotencia (runkey, ticket ya tipificado, exportación posterior); deduplicación.
   - **Acciones con un clic:** Añadir (auditoría `config_faq` y `ai_suggestion_add`),
     Editar y añadir, respuesta rápida, Descartar y Recuperar, doble acción rechazada.
   - **Datos maliciosos en la importación:** etiquetas, enlaces `javascript:`, `data:` y
     `http`, tipos y áreas inválidos o confidenciales, más de 60 sugerencias.
   - **Permisos:** un estudiante no entra a la pantalla del asistente.
   - **Sin servicio web (20261001127):**
     - tras la actualización no queda el servicio, sus funciones, tokens del agente ni la
       capacidad;
     - la limpieza, corrida sobre restos simulados, borra solo lo suyo y no toca otro
       servicio ni token;
     - queda auditada;
     - la segunda vez no hace nada;
     - el usuario técnico con datos se suspende en vez de borrarse.
4. Correr de nuevo `upgrade.php`: no hay nada que actualizar y no aparece un evento
   `ai_service_removed` nuevo.
5. Exportación real para revisar a mano (solo lectura, más el evento `ai_export`):
   `sudo -u www-data php7.4 local/grupomakro_core/cli/support_ai_export.php --limit=20`.
   En 5 tickets revisar que no haya nombres, correos, teléfonos, cédulas ni notas internas,
   y que no aparezca ningún ticket de Psicología.

## 2. Producción

Requiere OK de Ramiro: el push a master despliega solo.

1. Antes del push, contar los tokens (hoy unos 887) y revisar si existe el servicio
   `gmk_support_ai`. Después de la actualización, el conteo baja solo en los tokens de
   ese servicio.
2. Configuración de la mesa de ayuda: la pestaña se llama **Asistente semanal** y tiene
   Sugerencias, Tipificación e Historial. No hay Conexión ni token.
3. Correr la tarea `analisis-semanal-mesa-ayuda` a mano una vez y revisar que el Historial
   muestre la ejecución y "Última exportación de tickets".
4. En el celular (375 px) probar:
   - Sugerencias, el filtro por área y "Ver la respuesta actual";
   - Añadir (pide confirmación);
   - Editar y añadir;
   - Descartar y Recuperar;
   - Tipificación e Historial.
5. Correr de nuevo la tarea la misma semana: responde `already_imported`.

## 3. Reversión

- **Cortar el análisis:** pausar o borrar la tarea programada en la app de Claude.
- **Quitar sugerencias:** Descartar.
- **Quitar un problema común añadido:** desactivarlo o borrarlo en Problemas comunes. Queda
  en la auditoría.
- La limpieza de 20261001127 no se revierte sola. Si hiciera falta volver a la rutina en la
  nube, habría que volver a desplegar 20261001126 con una versión mayor y generar un token
  nuevo.

## Límites conocidos

- Los nombres de terceros que estén escritos en minúsculas o que no sean usuarios de la
  plataforma pueden quedar en el texto. Los de la persona y los agentes del ticket se
  quitan siempre, escritos de cualquier forma.
- Las respuestas rápidas se guardan en `gmk_tkt_canned`, pero la bandeja todavía no las
  ofrece al agente (fase 3).
