# Pruebas: asistente semanal de la mesa de ayuda (20261001126)

Rama `feat/tickets-asistente-ia` (desde origin/master `9495cf09`). Diseño y formato:
`docs/asistente/AGENTE_SEMANAL.md`.

## 0. Antes de desplegar

| # | Qué | Cómo | Esperado |
|---|-----|------|----------|
| 0.1 | Sintaxis | `php -l` en todo lo tocado; `node --check` en los JS | Sin errores |
| 0.2 | Anonimizador (aislado) | Arnés local sin base (11 casos) | 0 fallas (hecho 19-nov) |
| 0.3 | Pantalla con datos simulados, 375 px | Arnés local | Sin desborde ni errores de consola (hecho 19-nov) |

## 1. Staging (solo CLI, con OK de Ramiro: escribe en isidb_staging y lo revierte)

1. Subir el código a staging y `sudo -u www-data php7.4 admin/cli/upgrade.php --non-interactive`.
   Esperado: crea `gmk_tkt_ai_run`, `gmk_tkt_ai_class`, `gmk_tkt_ai_sugg`; capacidad
   `support_assistant` para gerencia y director académico (los mismos de `support_manage`).
2. Autotest con agente simulado y rollback:
   `sudo -u www-data php7.4 local/grupomakro_core/cli/support_ai_selftest.php --student=<id de prueba>`
   Esperado: todo PASS y "ejecuciones: N antes, N después; problemas comunes: M / M".
   Cubre: anonimización (nombre, usuario, correo, teléfono, cédula, terceros, tokens),
   sin notas internas, sin Psicología ni confidenciales, `--dry-run` no deja nada,
   evidencia calculada por el servidor, idempotencia (runkey, ticket ya tipificado,
   exportación posterior), deduplicación, Añadir con auditoría, Editar y añadir
   (contadores intactos), respuesta rápida, Descartar (no vuelve en 60 días),
   Recuperar, doble acción rechazada, pantalla y capacidad.
3. Exportación real (solo lectura salvo el evento `ai_export`):
   `sudo -u www-data php7.4 local/grupomakro_core/cli/support_ai_export.php --limit=20`
   Revisar a mano 5 tickets: ningún nombre, correo, teléfono ni cédula; ninguna nota
   interna; ningún ticket de Psicología.

## 2. Producción (con OK de Ramiro: push a master = autodeploy)

1. Tras el autodeploy, la pestaña **Asistente IA** aparece en Configuración de la mesa
   de ayuda para quien tenga `support_manage` + `support_assistant`, y no para un agente.
2. Primera ejecución manual de la tarea programada ("Ejecutar ahora" en la app):
   - el informe en `C:\Users\Ramiro\Documents\Claude\asistente-mesa-ayuda\<semana>\informe.md`;
   - el `--dry-run` y la importación real coinciden en conteos;
   - en Historial aparece la ejecución con sus tickets tipificados.
3. En el celular (375 px): Sugerencias, filtro por área, Ver respuesta actual, ejemplos,
   Añadir (pide confirmación), Editar y añadir (abre el editor de problemas comunes con el
   texto propuesto; el botón dice Añadir), Descartar, Recuperar. Tipificación e Historial
   como tarjetas.
4. Tras Añadir un problema común: aparece en la pestaña Problemas comunes y en el flujo
   del estudiante; Auditoría muestra `config_faq` y `ai_suggestion_add`.
5. Repetir la tarea la misma semana: no hace nada (ya importada).

## 3. Reversión

- Quitar sugerencias: Descartar. Quitar un problema común añadido: desactivarlo o
  borrarlo en Problemas comunes (queda auditado).
- Apagar el asistente: desactivar la tarea programada en la app de Claude. Las tablas
  `gmk_tkt_ai_*` no afectan a nada más.

## Límites conocidos

- Nombres de terceros escritos en minúsculas o que no son usuarios de la plataforma
  pueden quedar en el texto exportado (los de la persona y los agentes del ticket se
  quitan siempre, en cualquier forma).
- La tarea corre solo con la app de Claude abierta; si la PC está apagada, corre al
  abrirla.
- Las respuestas rápidas se guardan en `gmk_tkt_canned`, pero la bandeja todavía no las
  ofrece al agente (fase 3).
