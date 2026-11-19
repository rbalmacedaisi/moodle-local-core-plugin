# Pruebas: asistente semanal en la nube (20261001126)

Rama `feat/tickets-asistente-ia` (desde origin/master `9495cf09`). Prompt y formato del
agente: `docs/agents/analisis_semanal_tickets.md`.

Diseño (decisión de Ramiro, 19-nov-2026):

- No hay proveedor de IA ni API key dentro de Moodle.
- El análisis lo hace una **rutina de Claude Code en la nube** con cron semanal.
- Moodle solo expone dos servicios web (`support_ai_export_closed`, `support_ai_submit`)
  con el token de un usuario técnico dedicado. El token se genera y se revoca en la
  configuración.
- Psicología queda excluida por completo.

## 0. Antes de desplegar (hecho el 19-nov)

| # | Qué | Resultado |
|---|-----|-----------|
| 0.1 | `php -l` en todo lo tocado, `node --check` en los JS, `install.xml` válido | Limpio |
| 0.2 | Anonimizador en un arnés aislado sin base de datos (11 casos) | 0 fallas |
| 0.3 | Pantalla con datos simulados a 375 px | Sin desborde ni errores de consola |

## 1. Staging (solo CLI)

Requiere OK de Ramiro: escribe en isidb_staging y revierte todo al final.

1. Subir el código y correr
   `sudo -u www-data php7.4 admin/cli/upgrade.php --non-interactive`.
   Esperado:
   - se crean las tablas `gmk_tkt_ai_run`, `gmk_tkt_ai_class` y `gmk_tkt_ai_sugg`;
   - existe el servicio `gmk_support_ai` (usuarios restringidos) con las dos funciones;
   - existen las capacidades `support_assistant` (gerencia y director académico) y
     `support_ai_agent` (sin rol todavía).
2. Correr el autotest con agente simulado y reversión:
   `sudo -u www-data php7.4 local/grupomakro_core/cli/support_ai_selftest.php --student=<id de prueba>`.
   Esperado: todo PASS y "ejecuciones: N antes, N después".
   Qué cubre:
   - **Anonimización:** nombre, usuario, correo, teléfono, cédula, terceros y tokens en
     enlaces. Sin notas internas. Sin Psicología ni temas confidenciales.
   - **Importación:**
     - `dryrun` no deja nada;
     - la evidencia la calcula el servidor;
     - idempotencia (runkey, ticket ya tipificado, exportación posterior);
     - deduplicación de sugerencias.
   - **Acciones con un clic:**
     - Añadir crea el problema común y queda en la auditoría (`config_faq`,
       `ai_suggestion_add`);
     - Editar y añadir no toca los contadores;
     - respuesta rápida;
     - Descartar (no se vuelve a proponer) y Recuperar;
     - una doble acción se rechaza.
   - **Agente en la nube:**
     - el token crea un usuario técnico con un rol mínimo;
     - la pantalla nunca devuelve el token;
     - generar uno nuevo invalida el anterior;
     - `export_closed` y `submit` se llaman como el usuario técnico.
   - **Datos maliciosos en `submit`:**
     - etiquetas HTML;
     - enlaces `javascript:`, `data:` y `http`;
     - tipos y áreas inválidos, áreas confidenciales;
     - más de 2 MB, JSON roto, más de 60 sugerencias.
   - **Permisos y revocación:**
     - el agente no entra a la configuración;
     - un estudiante no entra a los endpoints;
     - revocar borra el token y suspende al usuario.
3. Exportación real para revisar a mano (solo lectura, más el evento `ai_export`):
   `sudo -u www-data php7.4 local/grupomakro_core/cli/support_ai_export.php --limit=20`.
   En 5 tickets revisar que no haya nombres, correos, teléfonos, cédulas ni notas internas,
   y que no aparezca ningún ticket de Psicología.

## 2. Producción

Requiere OK de Ramiro: el push a master despliega solo.

1. Revisar que los servicios web y el protocolo REST estén activos. La sección Conexión
   avisa si no lo están. Activarlos es un cambio de configuración del sitio: aparte y con
   OK.
2. En Configuración de la mesa de ayuda > **Asistente en la nube** > Conexión, pulsar
   **Generar token**. Se muestra una sola vez: copiarlo.
3. Prueba de conexión desde fuera, en modo validación (no guarda nada):

   ```
   curl -sS https://lms.isi.edu.pa/webservice/rest/server.php \
     --data-urlencode wstoken=<token> \
     -d wsfunction=local_grupomakro_support_ai_submit -d moodlewsrestformat=json \
     -d dryrun=1 --data-urlencode 'run={"runkey":"prueba"}'
   ```

   Esperado: `"status":"dry_run"`.
4. Crear la rutina en la nube (con `/schedule`, con OK de Ramiro):
   - repositorio: github.com/rbalmacedaisi/moodle-local-core-plugin;
   - prompt: "Sigue docs/agents/analisis_semanal_tickets.md";
   - red permitida: `lms.isi.edu.pa`;
   - secreto: `MOODLE_AI_TOKEN`;
   - cron semanal (propuesta: lunes 7:00, hora de Panamá).
5. Correr la rutina a mano una vez y revisar:
   - el Historial muestra la ejecución;
   - Conexión muestra "Último uso" y "Última lectura del agente".
6. En el celular (375 px) probar:
   - Sugerencias, el filtro por área y "Ver la respuesta actual";
   - los ejemplos;
   - Añadir (pide confirmación);
   - Editar y añadir (abre el editor de problemas comunes y el botón dice Añadir);
   - Descartar y Recuperar;
   - la vista Tipificación (con las barras de las últimas 8 semanas), Historial y
     Conexión.
7. Correr de nuevo la rutina la misma semana: responde `already_imported`.

## 3. Reversión

- **Cortar el agente al instante:** Revocar en Conexión. Borra el token y suspende al
  usuario técnico. También se puede pausar o borrar la rutina en claude.ai.
- **Quitar sugerencias:** Descartar.
- **Quitar un problema común añadido:** desactivarlo o borrarlo en Problemas comunes. Queda
  en la auditoría.
- Las tablas `gmk_tkt_ai_*` no afectan a nada más.

## Límites conocidos

- Los nombres de terceros que estén escritos en minúsculas o que no sean usuarios de la
  plataforma pueden quedar en el texto. Los de la persona y los agentes del ticket se
  quitan siempre, escritos de cualquier forma.
- Moodle guarda el token en claro en `external_tokens` (así funciona Moodle). La
  pantalla solo muestra los 4 últimos caracteres.
- Las respuestas rápidas se guardan en `gmk_tkt_canned`, pero la bandeja todavía no las
  ofrece al agente (fase 3).
