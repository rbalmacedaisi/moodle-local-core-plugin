# Staging Environment — Moodle Staging Smoke Test

Esta carpeta contiene los artefactos que validan el entorno staging
(https://lms.students.isi.edu.pa) restaurado del dump sanitizado de prod.

## Scripts

### `smoke_moodle_staging.sh` — **gate de "staging verde" (DES-MOODLE-002)**

Test E2E de 8 web services críticos del bridge Moodle↔Odoo↔Express↔LXP.
Ejecutar DESPUÉS del restore + sanitización (DBA-002) para confirmar que
el staging responde antes de promover código o de abrir tráfico LXP.

**Los 8 WS evaluados:**

| # | Web Service | Ruta que valida |
|---|---|---|
| 1 | `core_course_get_courses` | Cursos del sitio (≥1 curso del dump restaurado). |
| 2 | `core_enrol_get_users_courses` | Matrícula de un usuario de muestra. |
| 3 | `core_user_get_users` (idnumber) | Bridge Odoo→Moodle resuelve `partner_vat`↔`mdl_user.idnumber`. |
| 4 | `core_webservice_get_site_info` | Versión + release del Moodle staging. |
| 5 | `gradereport_user_get_grade_items` | `mdl_grade_items` se restauró (sin esto LXP no pinta notas). |
| 6 | `core_completion_update_activity_completion_status_manually` | **Escritura** — descarta un staging pasivo solo-lectura. |
| 7 | `local_grupomakro_get_user_courses` | Bridge Odoo→Moodle read-side con progreso + créditos + ausencias. Sustituye a `local_moodle_sync_users` (no existe en este fork). |
| 8 | `core_course_get_categories` | `mdl_course_categories` se restauró (árbol académico). |

**Uso:**

```bash
MOODLE_URL=https://lms.students.isi.edu.pa \
MOODLE_TOKEN=<wstoken del external service 'smoke-test-staging'> \
./smoke_moodle_staging.sh
echo "EXIT=$?"
# EXIT=0 = staging verde
# EXIT=1 = al menos un WS falló (ver tabla CSV)
```

**Requisitos previos:**

- `curl` y `python3` (REQUERIDOS).
- `jq` (OPCIONAL — si no está, el script lo reemplaza por un shim python3
  con la misma API para las sub-expresiones que usamos: `type`, `length`,
  `type == "X" and (...)`, `if/then/else/end`, selectores `.foo`).
- Un external service Moodle dedicado `smoke-test-staging` con acceso a los
  8 WS anteriores. Crear desde *Site Admin → Plugins → Web services →
  External services → Custom services*. Asignar a un usuario `smoke-bot`
  que **NO** sea admin (preferentemente con un rol custom que tenga las
  capabilities justas — ver más abajo).

**Exit codes:**

| code | significado |
|---|---|
| 0 | todos los 8 WS respondieron OK → staging verde |
| 1 | al menos un WS falló → revisar tabla CSV al final del output |
| 2 | error de configuración (faltan vars, sin curl/python3) |

### `smoke_test_staging.sh` — **smoke pre-DES-MOODLE-002 (legacy)**

Versión anterior con 8 WS distintos, enfocada en paneles administrativos
(no en el bridge Odoo↔Moodle). Sigue siendo útil para validar paneles
internos del plugin (`local_grupomakro_list_classes_paged`,
`local_grupomakro_list_revalidations_director`,
`local_grupomakro_get_failed_subjects_report`, etc.) sin necesidad del
external service con permisos de escritura.

**Uso:** idéntico al anterior.

### `check_moodle_user_fk_integrity.sql` — **validación SQL de FK**

(Ver cabecera del archivo.) Corre en el EC2 Moodle staging después del
restore (DBA-002) y antes de habilitar tráfico LXP. Confirma que no hay
huérfanos en las FK que apuntan a `mdl_user.id` — sin esto, el bridge
Odoo↔Moodle (`moodle.user.moodle_user_id → mdl_user.id`) puede romperse
en producción aunque el smoke E2E pase (porque ningún WS del smoke toca
todas las tablas del fork).

### `config.staging.template.php` — **plantilla config.php**

Referencia del `config.php` usado para levantar el Moodle staging. Los
placeholders `%%TOKEN%%` se sustituyen desde SSM Parameter Store en el
user-data del EC2 (`66-ec2-staging-moodle.yaml` en INF-005).

## Capabilities mínimas del external service `smoke-test-staging`

| Web Service | Capability necesaria |
|---|---|
| `core_course_get_courses` | `moodle/course:view` |
| `core_enrol_get_users_courses` | `moodle/course:view` + `moodle/enrol:view` |
| `core_user_get_users` | `moodle/user:viewdetails` |
| `core_webservice_get_site_info` | (público) |
| `gradereport_user_get_grade_items` | `moodle/grade:view` |
| `core_completion_update_activity_completion_status_manually` | `moodle/course:manageactivities` |
| `local_grupomakro_get_user_courses` | (custom del plugin — `local/grupomakro_core:view_academic_panel` o similar) |
| `core_course_get_categories` | `moodle/category:viewcourselist` |

## Orden de ejecución

```
[Fase 1] INF-005  → stack staging levantado
[Fase 1] DBA-002   → restore + sanitización
[Fase 2] check_moodle_user_fk_integrity.sql   ← DENTRO del EC2 Moodle staging
[Fase 2] crear external service 'smoke-test-staging' + usuario 'smoke-bot'
[Fase 3] ./smoke_moodle_staging.sh             ← ESTE ESCRIPT (gate)
[Fase 3] ./smoke_test_staging.sh               ← cobertura adicional (paneles)
[Fase 4] deploy código por stack contra staging
[Fase 5] E2E login LXP + flow académico completo
```

## Troubleshooting rápido

| Síntoma | Causa probable | Solución |
|---|---|---|
| Todos los WS devuelven `http_403` | Token sin permisos | Verificar capabilities del external service (tabla arriba). |
| `core_course_get_courses` devuelve `empty_array` | Dump no restaurado / tabla vacía | Re-ejecutar DBA-002 (mysql restore). |
| `gradereport_user_get_grade_items` falla | `mdl_grade_items` no restaurada | El dump prod sí la trae — verificar que la blacklist del runbook no la incluye por error. |
| `local_grupomakro_get_user_courses` falla con 500 | Plugin no desplegado en staging | Confirmar que `local/grupomakro_core` está en `master` del EC2 Moodle staging. |
| `local_grupomakro_get_user_courses` falla con 404 | WS renombrado en una versión reciente | Verificar nombre exacto en `db/services.php` del fork desplegado. |
| WS #6 (`core_completion_update_*`) falla con `nopermissions` | Capability `moodle/course:manageactivities` falta | Agregarla al rol `smoke-bot`. Es informativo: el smoke marca FAIL pero NO bloquea los otros 7. |
