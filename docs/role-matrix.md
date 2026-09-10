# Matriz de Roles, Capacidades, Páginas y Web Services

> **Audiencia**: equipo de Registros Académicos, Soporte TI, Coordinación.
> **Propósito**: documento de referencia para entender quién puede hacer qué en el plugin `local_grupomakro_core` (el "core" que extiende Moodle con todo el flujo académico del Instituto).
> **Versión del plugin**: 20261001007+ (post-PR7).
> **Última actualización**: septiembre 2026.

## 1. Resumen ejecutivo

El plugin reemplazó el esquema previo de "todos son siteadmins" por una matriz de **6 roles operativos** (más el `manager` para super-admins). Cada rol tiene un bundle de capabilities que se asignan automáticamente al crearlo. La asignación de un usuario a un rol se hace en `/admin/roles/users.php` (UI estándar de Moodle) o ejecutando `php cli/migrate_siteadmins_to_roles.php` (migración masiva).

| Rol | # caps | Tamaño del bundle | Quién lo usa |
|---|---|---|---|
| `manager` | 587 | muy grande | Super-admins (2-3 personas) |
| `gmk_director_academico` | 45 | grande | Director Académico |
| `gmk_secretaria_academica` | 33 | grande | Secretaría Académica |
| `gmk_registros_academicos` | 18 | mediano | Registros / Cartas / Diplomas |
| `gmk_soporte_ti` | 6 | pequeño | Soporte TI / Debug |
| `gmk_bienestar` | 4 | pequeño | Coordinador del módulo Wellness |
| `gmk_psicologo` | 1 | mínimo | Psicólogo/a (agenda psicológica) |

## 2. Catálogo de roles

### 2.1 `manager` (rol nativo de Moodle)

- **Propósito**: super-admin con acceso total a Moodle. Equivalente al "siteadmin" histórico.
- **Caps**: 587 (incluye `moodle/site:config` y todo lo demás).
- **Quiénes lo conservan tras PR7**: 3 personas (admin principal, Joyce Muñoz, Walber Castillo). Ver `cli/migrate_siteadmins_to_roles.php` para el mapping completo.
- **Restricción importante**: este rol NO es operacional. Cualquiera con este rol puede tocar la base de datos. Limitar a 2-3 personas.

### 2.2 `gmk_director_academico` (Director Académico)

- **Propósito**: oversight académico, decisiones estructurales, aprobaciones finales.
- **Caps**: 45.
- **Páginas clave que desbloquea** (entre otras):
  - `/local/grupomakro_core/pages/academicpanel.php` — Panel del Director (KPIs financieros, gestión de cohortes, cierre de período).
  - `/local/grupomakro_core/pages/schedulepanel.php` + `scheduleapproval.php` — Aprobación de horarios.
  - `/local/grupomakro_core/pages/academic_planning.php` — Cierre de período académico.
  - `/local/grupomakro_core/pages/revalidations_director.php` — Dashboard de reválidas a nivel instituto.
  - `/local/grupomakro_core/pages/academic_demand_gaps.php` — Brechas de demanda académica.
  - `/local/grupomakro_core/pages/announcements.php` — Crear/editar anuncios.
- **WS críticos**: `create_class`, `update_class`, `delete_class`, `close_current_period`, `update_student_status`, `status_change_execute`, `create_extemporaneous_revalidation`, `revert_homologation`, `homologate_course_grade`, `approve_course_class_schedules`, `refresh_revalidations_bulk`, `admin_save_wellness_event`.
- **Cap exclusivo del Director** (no en otros): `create_extemporaneous_revalidations` (crear reválidas fuera de ventana temporal), `annul_movement` (anular movimientos académicos ya registrados).
- **Quién lo usa hoy**: José Joel Rodríguez (j.rodriguez@isi.edu.pa).

### 2.3 `gmk_secretaria_academica` (Secretaría Académica)

- **Propósito**: operación diaria académica — gestión de clases, horarios, asistencia, calificaciones, módulos independientes.
- **Caps**: 33.
- **Páginas clave**:
  - `classmanagement.php` — Listado paginado de todas las clases.
  - `createclass.php` / `editclass.php` — Crear/editar clases.
  - `schedulepanel.php` + `schedules.php` + `scheduleapproval.php` — Gestión y aprobación de horarios.
  - `availability.php` / `availabilitypanel.php` — Disponibilidad de docentes.
  - `users.php` — Gestión de estudiantes por clase.
  - `attendance_pdf.php` — PDFs de asistencia.
  - `module_management.php` — Módulos independientes.
  - `active_students_by_class.php`, `student_population.php` — Reportes.
- **WS críticos**: `bulk_enroll`, `import_users`, `attendance_qr_*`, `reopen_assignment`, `save_grade`, `get_dashboard_data` (contexto del docente), `update_student_status` (cambios operativos), `student_class_revalid_enrol`.
- **NO tiene acceso a**: `create_extemporaneous_revalidation` (solo Director), `annul_movement` (solo Director), `manage_orders`/`manage_institutional_contracts` (solo Registros), debug pages.
- **Quién lo usa hoy**: Jean Remice (j.remice@isi.edu.pa), Veronica Rangel (v.rangel@isi.edu.pa).

### 2.4 `gmk_registros_academicos` (Registros Académicos)

- **Propósito**: gestión documental — constancias, certificados, contratos institucionales, exportación de datos.
- **Caps**: 18.
- **Páginas clave**:
  - `lettertypes.php` — Catálogo de tipos de cartas.
  - `letterrequests.php` — Bandeja de solicitudes de cartas.
  - `orders.php` — Órdenes.
  - `createcontract.php` / `editcontract.php` — Contratos individuales.
  - `institutionmanagement.php` — Gestión de instituciones.
  - `institutionalcontracts.php` + `createcontractinstitutional.php` + `editcontractinstitutionals.php` — Contratos institucionales.
  - `credit_report.php` — Reporte de créditos académicos.
  - `financial_planning.php` — Análisis financiero.
  - `diplomageneration.php` + `diplomatemplates.php` — Diplomas.
  - `download_all_students.php` + `export_*.php` — Exports.
- **WS críticos**: `diploma_dispatcher`, `letter_create_request` (también usado por estudiantes), `letter_get_types`, `manage_orders`, `manage_institutions`, `manage_institutional_contracts`, `export_students`.
- **NO tiene acceso a**: edición directa de clases, schedules, asistencia, debug.
- **Quién lo usa hoy**: Lizbeth Aizprua (laizprua@isi.edu.pa).

### 2.5 `gmk_soporte_ti` (Soporte TI)

- **Propósito**: integración con sistemas externos (Odoo, Express), debug de problemas técnicos, reset de sesiones BBB.
- **Caps**: 6.
- **Páginas clave**:
  - Todo el árbol **Debug** del admin tree (90+ páginas: `debug_*.php`, `fix_*.php`, `migrate_*.php`, `repair_*.php`, etc.).
  - `manage_meetings.php` + `sync_bbb_recordings.php` — Reset de sesiones BigBlueButton.
  - `financial_health.php` — Health check de la sincronización financiera.
  - `financial_webhook.php` + `financial_webhook_dlq.php` — Dead-letter queue de webhooks.
  - `bypass_financial.php` + `grace_period.php` + `financial_source.php` — Configuración financiera.
  - `view_log.php` — Visor del log de sincronización.
- **WS críticos**: `odoo_enroll_student`, `odoo_unenroll_student`, `odoo_update_status`, `manage_financial_webhooks`, `manage_financial_config`, `view_log`.
- **NO tiene acceso a**: contenido académico (clases, notas, diplomas), UI operativa.
- **Quién lo usa hoy**: Esteban Montoya (e.montoya@isi.edu.pa).

### 2.6 `gmk_bienestar` (Coordinador de Bienestar)

- **Propósito**: gestión integral del módulo Wellness — eventos, convenios, partners, avisos.
- **Caps**: 4.
- **Páginas clave**:
  - `wellness_dashboard.php` — Panel del módulo.
  - `wellness_psychology_panel.php` — Agenda psicológica (también la maneja el rol Psicólogo, pero el coordinador también la ve).
  - `wellness_staff_panel.php` — Staff asignado.
  - `announcements.php` — Anuncios del módulo.
- **WS críticos**: todos los `admin/wellness/*` (admin_save_event, admin_list_partners, admin_save_partner, etc.) + `manageannouncements` + `manage_psychology_appointments` (puede gestionar también la agenda psicológica).
- **NO tiene acceso a**: nada académico, nada técnico.
- **Quién lo usa hoy**: Jorge Oviedo (j.oviedo@isi.edu.pa).

### 2.7 `gmk_psicologo` (Psicólogo/a)

- **Propósito**: gestión exclusiva de la agenda de citas psicológicas. **No** puede crear eventos ni convenios (eso es del Coordinador).
- **Caps**: 1 (`manage_psychology_appointments`).
- **Páginas clave**:
  - `wellness_psychology_panel.php` — La agenda psicológica. Es la única página administrativa que ve.
- **WS críticos**: `admin/wellness/admin_list_psychology_appointments`, `admin/wellness/admin_update_psychology_appointment`, `admin/wellness/admin_save_psychology_slots`.
- **NO tiene acceso a**: nada más.
- **Quién lo usa hoy**: Dulce Jurado (d.jurado@isi.edu.pa).

## 3. Matriz consolidada: rol × capability

Esta es la lista completa de capabilities definidas en `db/access.php` y quién las recibe. Las capacidades se asignan vía:
1. `manager` archetype (caps nativas de Moodle) — todos los siteadmins las reciben.
2. Bundle por rol en `db/upgradelib.php::assign_capabilities_to_internal_roles()`.

### Workflow 1 — Estructura académica

| Cap | Director | Sec | Reg | TI | Bien | Psi | manager |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `manage_academic_calendar` | ✓ | | | | | | ✓ |
| `manage_academic_planning` | ✓ | | | | | | ✓ |
| `view_academic_demand_gaps` | ✓ | ✓ | | | | | ✓ |
| `view_overlap_analytics` | ✓ | ✓ | | | | | ✓ |
| `view_student_timeline` | ✓ | ✓ | ✓ | | | | ✓ |
| `manage_student_timeline` | ✓ | | | | | | ✓ |
| `manage_teacher_availability` | ✓ | ✓ | | | | | ✓ |
| `view_student_population` | ✓ | ✓ | ✓ | | | | ✓ |
| `view_active_students_by_class` | ✓ | ✓ | ✓ | | | | ✓ |
| `view_academic_panel` | ✓ | ✓ | | | | | ✓ |

### Workflow 2 — Clases y docentes

| Cap | Director | Sec | Reg | TI | Bien | Psi | manager |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `view_classmanagement` | ✓ | ✓ | | | | | ✓ |
| `manage_classes` | ✓ | ✓ | | | | | ✓ |
| `manage_courses` | ✓ | ✓ | | | | | ✓ |
| `manage_meetings` | ✓ | ✓ | | ✓ | | | ✓ |
| `manage_teachers` | ✓ | ✓ | | | | | ✓ |
| `editsupportteacher` | ✓ | ✓ | | | | | ✓ |
| `manage_modules` | ✓ | ✓ | | | | | ✓ |

### Workflow 3 — Estudiantes y matrícula

| Cap | Director | Sec | Reg | TI | Bien | Psi | manager |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `manage_users` | ✓ | | | | | | ✓ |
| `bulk_enroll` | ✓ | ✓ | | | | | ✓ |
| `import_users` | ✓ | ✓ | | | | | ✓ |
| `export_students` | ✓ | ✓ | ✓ | | | | ✓ |
| `manageacademicstatus` | ✓ | ✓ | | | | | ✓ |
| `view_classmanagement` | ✓ | ✓ | | | | | ✓ |
| `view_revalidations_dashboard` | ✓ | ✓ | | | | | ✓ |
| `create_extemporaneous_revalidations` | ✓ | | | | | | ✓ |
| `enrol_from_failed_subjects_report` | ✓ | ✓ | | | | | ✓ |
| `view_failed_subjects_report` | ✓ | ✓ | | | | | ✓ |
| `view_movement_audit` | ✓ | ✓ | | | | | ✓ |
| `annul_movement` | ✓ | | | | | | ✓ |

### Workflow 4 — Asistencia y calificaciones

| Cap | Director | Sec | Reg | TI | Bien | Psi | manager |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `viewabsencedashboard` | ✓ | ✓ | ✓ | | | | ✓ |
| `view_attendance_pdf` | ✓ | ✓ | ✓ | | | | ✓ |
| `bulk_attendance_actions` | ✓ | ✓ | | | | | ✓ |
| `view_grade_report` | ✓ | ✓ | ✓ | | | | ✓ |

### Workflow 5 — Cartas, contratos, instituciones

| Cap | Director | Sec | Reg | TI | Bien | Psi | manager |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `viewallletterrequests` | ✓ | ✓ | ✓ | | | | ✓ |
| `manageletters` | | | ✓ | | | | ✓ |
| `managerequests` | | ✓ | ✓ | | | | ✓ |
| `seeallorders` | | | ✓ | | | | ✓ |
| `manage_orders` | ✓ | | ✓ | | | | ✓ |
| `manage_institutional_contracts` | | | ✓ | | | | ✓ |
| `manage_institutions` | ✓ | | ✓ | | | | ✓ |
| `view_credit_report` | ✓ | | ✓ | | | | ✓ |
| `view_financial_planning` | ✓ | | ✓ | | | | ✓ |
| `managediplomas` | ✓ | | ✓ | | | | ✓ |
| `viewdiplomas` | ✓ | ✓ | ✓ | | | | ✓ |
| `verifydiplomas` | | | | | | | ✓ (público) |

### Workflow 6 — Infraestructura técnica

| Cap | Director | Sec | Reg | TI | Bien | Psi | manager |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `manage_debug` | | | | ✓ | | | ✓ |
| `view_log` | | | | ✓ | | | ✓ |
| `view_financial_health` | | | | ✓ | | | ✓ |
| `manage_financial_webhooks` | | | | ✓ | | | ✓ |
| `manage_financial_config` | ✓ | | | ✓ | | | ✓ |

### Workflow 7 — Wellness

| Cap | Director | Sec | Reg | TI | Bien | Psi | manager |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| `view_wellness` | | | | | | | ✓ (todos) |
| `manage_wellness` | | | | | ✓ | | ✓ |
| `manage_psychology_appointments` | | | | | ✓ | ✓ | ✓ |
| `manageannouncements` | | | | | ✓ | | ✓ |
| `viewannouncements` | | | | | ✓ | | ✓ |

## 4. Mapeo de páginas por rol

Estas son las páginas del admin tree. La columna "✓" indica que el rol ve la entrada en el menú.

| Página | Director | Sec | Reg | TI | Bien | Psi |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| Panel del Director (`academicpanel`) | ✓ | ✓ | | | | |
| Horarios (`schedulepanel`, `scheduleapproval`, `schedules`, `schedule_weekly_view`) | ✓ | ✓ | | | | |
| Clases (`classmanagement`) | ✓ | ✓ | | | | |
| Disponibilidad docente (`availability*`) | ✓ | ✓ | | | | |
| Instituciones (`institutionmanagement`, `institutionalcontracts`) | ✓ | | ✓ | | | |
| Línea de tiempo (`student_timeline`, `student_timeline_career`) | ✓ | ✓ | ✓ | | | |
| Módulos independientes (`module_management`) | ✓ | ✓ | | | | |
| Docentes (`teachers`, `teacher_profile`, `inactive_teacher_dashboard`) | ✓ | ✓ | | | | |
| Importar usuarios (`import_users`) | ✓ | ✓ | | | | |
| Importar notas (`import_grades`) | | | | | | | (solo `manager`)
| Eliminación masiva (`bulk_delete_users`) | | | | | | | (solo `manager`)
| Gestión de cursos (`manage_courses`) | ✓ | ✓ | | | | |
| Sesiones virtuales (`manage_meetings`, `sync_bbb_recordings`) | ✓ | ✓ | | ✓ | | |
| Bypass financiero (`bypass_financial`, `grace_period`, `financial_source`) | ✓ | | | ✓ | | |
| Periodo de gracia (`grace_period`) | ✓ | | | ✓ | | |
| Debug y diagnóstico (todo el árbol Debug) | | | | ✓ | | |
| Solapamientos (`overlap_analytics`) | ✓ | ✓ | | | | |
| Brechas de demanda (`academic_demand_gaps`) | ✓ | ✓ | | | | |
| Vista semanal (`schedule_weekly_view`) | ✓ | ✓ | | | | |
| Activos por clase (`active_students_by_class`) | ✓ | ✓ | ✓ | | | |
| Análisis financiero docente (`financial_planning`) | ✓ | | ✓ | | | |
| Sincronizar grabaciones BBB (`sync_bbb_recordings`) | ✓ | ✓ | | ✓ | | |
| Pob. estudiantil (`student_population`) | ✓ | ✓ | ✓ | | | |
| Dashboard de asistencia (`absence_dashboard`) | ✓ | ✓ | ✓ | | | |
| Auditoría de cursos (`debug_class_courseid_audit`) | | | | ✓ | | |
| Catálogo de cartas (`lettertypes`) | | | ✓ | | | |
| Bandeja de cartas (`letterrequests`) | | ✓ | ✓ | | | |
| Matrícula masiva a plan (`bulk_enroll`) | ✓ | ✓ | | | | |
| Diplomas (`diplomageneration`, `diplomatemplates`) | ✓ | | ✓ | | | |
| Wellness (`wellness_dashboard`, `wellness_psychology_panel`, `wellness_staff_panel`) | | | | | ✓ | ✓ |
| Reválidas director (`revalidations_director`) | ✓ | | | | | |
| Asignaturas reprobadas (`failed_subjects_report`) | ✓ | ✓ | | | | |
| Anuncios admin (`announcements`) | | | | | ✓ | |

## 5. Mapeo de Web Services por rol

Los WS en `db/services.php` tienen un campo `capabilities` que el WS framework chequea a nivel sistema antes de invocar la función. La función también chequea un `require_capability()` interno (defense-in-depth, PR6).

| WS | Director | Sec | Reg | TI | Bien | Psi |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| `local_grupomakro_create_user` | ✓ | | | | | |
| `local_grupomakro_get_user_status` | ✓ | | | | | |
| `local_grupomakro_create_class` | ✓ | ✓ | | | | |
| `local_grupomakro_update_class` | ✓ | ✓ | | | | |
| `local_grupomakro_delete_class` | ✓ | ✓ | | | | |
| `local_grupomakro_toggle_class_status` | ✓ | ✓ | | | | |
| `local_grupomakro_list_classes` (paged) | ✓ | ✓ | | | | |
| `local_grupomakro_get_potential_class_teachers` | ✓ | ✓ | | | | |
| `local_grupomakro_odoo_enroll_student` | | | | ✓ | | |
| `local_grupomakro_odoo_unenroll_student` | | | | ✓ | | |
| `local_grupomakro_odoo_update_status` | | | | ✓ | | |
| `local_grupomakro_odoo_homologate_course_grade` | ✓ | | | | | |
| `local_grupomakro_revert_homologation` | ✓ | | | | | |
| `local_grupomakro_close_current_period` | ✓ | | | | | |
| `local_grupomakro_upload_academic_calendar_period` | ✓ | | | | | |
| `local_grupomakro_manage_meetings`, `sync_bbb_recordings`, `*_copy_activity`, `*_reschedule_activity` | ✓ | ✓ | | ✓ | | |
| `local_grupomakro_approve_course_class_schedules`, `*_change_students_schedules`, `*_revert_approval` | ✓ | ✓ | | | | |
| `local_grupomakro_delete_course_class_schedule`, `*_delete_student_from_class_schedule` | ✓ | ✓ | | | | |
| `local_grupomakro_update_class_quota` | ✓ | ✓ | | | | |
| `local_grupomakro_withdraw_from_course` | ✓ | ✓ | | | | |
| `local_grupomakro_enroll_module` | ✓ | ✓ | | | | |
| `local_grupomakro_get_*_schedules*` | ✓ | ✓ | | | | |
| `local_grupomakro_bulk_enroll`, `local_grupomakro_get_lp_students` | ✓ | ✓ | | | | |
| `local_grupomakro_manage_users`, `*_sync_progress` | ✓ | | | | | |
| `local_grupomakro_update_student_status`, `*_status_change_execute`, `*_status_change_preview`, `*_status_change_history` | ✓ | ✓ | | | | |
| `local_grupomakro_get_homologation_audit` | ✓ | ✓ | | | | |
| `local_grupomakro_create_institution` y familia | ✓ | | ✓ | | | |
| `local_grupomakro_create_institution_contract` y familia | | | ✓ | | | |
| `local_grupomakro_create_contract_user`, `*_bulk_create_contract_user`, etc. | | | ✓ | | | |
| `local_grupomakro_manage_orders` (legacy `seeallorders`) | ✓ | | ✓ | | | |
| `local_grupomakro_letter_create_request` (estudiantes también) | | | ✓ | | | |
| `local_grupomakro_letter_get_*` | ✓ | ✓ | ✓ | | | |
| `local_grupomakro_letter_download` (estudiantes también) | | | | | | | (cualquiera con sesión)
| `local_grupomakro_diploma_dispatcher` | ✓ | | ✓ | | | |
| `local_grupomakro_list_revalidations_director`, `*_refresh_*`, `*_create_extemporaneous_revalidation` | ✓ | | | | | |
| `local_grupomakro_get_failed_subjects_*` | ✓ | ✓ | | | | |
| `local_grupomakro_enrol_student_from_failed_subjects` | ✓ | ✓ | | | | |
| `local_grupomakro_get_financial_counts` | ✓ | ✓ | | | | |
| `local_grupomakro_get_student_*` (varios de solo lectura) | | | | | | | (estudiantes leen los suyos)
| `local_grupomakro_calendar_get_calendar_events` | | | | | | | (todos)
| `local_grupomakro_*_wellness_admin_*` | | | | | ✓ | ✓ |
| `local_grupomakro_*_wellness_public_*` | | | | | | | (estudiantes)
| `local_grupomakro_teacher_*` (cambio password, profile, dashboard, save_grade, reopen_assignment, save_quiz_grading) | | | | | | | (teachers via context)
| `local_grupomakro_manage_debug`, `*_view_log` | | | | ✓ | | |

## 6. Procedimiento para asignar/rotar roles

### Asignar un usuario a un rol (caso normal)

1. Entrar a `/admin/roles/users.php` como super-admin (`manager`).
2. En "Assign roles in context: System", seleccionar el rol deseado (ej: `gmk_secretaria_academica`).
3. Buscar al usuario por nombre o email.
4. Asignar.

### Verificar el bundle de caps de un rol

1. `/admin/roles/define.php?action=edit&roleid=<ID>` — muestra todas las capabilities y permite editarlas (no recomendado, pero útil para auditar).
2. Comparar contra este documento (sección 3).

### Verificar el bundle de caps efectivo de un usuario

1. `/admin/roles/users.php?userid=<ID>` — lista los roles asignados al usuario.
2. `/local/grupomakro_core/classes/local/...` — no hay UI directa, pero la función `has_capability('local/grupomakro_core:manage_classes', context_system::instance(), $user)` en un script PHP lo confirma.

### Troubleshooting: un usuario "no ve" una página que debería ver

1. Confirmar que el rol está asignado: `/admin/roles/users.php?userid=<ID>`.
2. Confirmar que la cap está en el bundle del rol: este documento (sección 3).
3. Confirmar que la página está registrada con esa cap en `db/services.php` y `settings.php`.
4. **Borrar caché de Moodle** después de cambiar caps: `Site administration → Development → Purge all caches`.

### Revertir una asignación (caso Fernanda)

1. `/admin/roles/users.php?userid=<ID>` → "Unassign" en el rol correspondiente.
2. Si el usuario debe perder `manager` también: usar la CLI o hacerlo manualmente.

## 7. Procedimiento de deploy de un nuevo role (futuro)

Si en el futuro hay que añadir un nuevo rol:

1. Definir el bundle de caps en `db/upgradelib.php::create_roles()` y `assign_capabilities_to_internal_roles()`.
2. Definir las caps en `db/access.php` con su `archetype` (probablemente `user`).
3. Crear upgrade step en `db/upgrade.php`.
4. Bumpear `version.php`.
5. Actualizar este documento (sección 2, 3, 4, 5).
6. Asignar usuarios vía UI o extender la CLI.

## 8. FAQ

**P: ¿Por qué hay un `manager` separado de los `gmk_*`?**
R: `manager` es el rol nativo de Moodle con 587 caps (incluye `moodle/site:config`). Los `gmk_*` son roles custom creados por este plugin, con bundles específicos. `manager` es para 2-3 personas de soporte máximo. Los `gmk_*` son operacionales.

**P: ¿Puede una persona tener varios roles?**
R: Sí. Moodle lo soporta nativamente. Un usuario puede ser `gmk_secretaria_academica` + `gmk_registros_academicos` y tener la unión de capabilities de ambos.

**P: ¿Cómo sé qué caps tiene un usuario en la práctica?**
R: La función interna `has_capability($cap, $context, $user)` en PHP lo determina. Para auditar, se puede correr un script que itere sobre todos los usuarios y muestre el bundle efectivo.

**P: ¿Los roles `gmk_*` se pueden renombrar?**
R: Sí, vía `/admin/roles/define.php`. Pero los `shortname` no se deberían cambiar (están en código). Si se cambia el nombre visible, actualizar este documento.

**P: ¿Por qué los WS admin aparecen con `capabilities` vacío en `db/services.php` después de PR5/6?**
R: PR5 agregó caps a casi todos los WS. PR6 puso caps vacíos a los WS de teacher para que la validación sea a nivel de función (los teachers no tienen caps a nivel sistema). Ver `db/services.php` y la sección 5 de este documento.

**P: ¿Qué pasa si rompo un role?**
R: El CLI `cli/migrate_siteadmins_to_roles.php --reverse` restaura los `manager` originales. Luego se repara el código y se vuelve a deployar.

## 9. Anexo: archivos clave del plugin

- `db/access.php` — definición de capabilities (con sus archetypes y contextlevels).
- `db/upgradelib.php` — `create_roles()` y `assign_capabilities_to_internal_roles()` (definición de roles y bundle de caps por rol).
- `db/upgrade.php` — upgrade steps versionados (incluye el step 20261001007 que crea `gmk_psicologo`).
- `db/services.php` — definición de WS con su campo `capabilities`.
- `settings.php` — definición de admin tree pages con su 4º parámetro de cap.
- `cli/migrate_siteadmins_to_roles.php` — script de migración inicial.
- `classes/external/*` — implementación de los WS (con `require_capability()` de defense-in-depth).
- `pages/*.php` — implementación de las páginas admin (con `require_capability()` de page-level).

## 10. Cambios en este documento por PR

| Versión plugin | Cambio |
|---|---|
| `20261001000` (PR1) | Crear 5 roles `gmk_*` |
| `20261001001` (PR2) | 33 caps nuevas + matriz por rol |
| `20261001002` (PR3) | Gates de páginas operativas |
| `20261001003` (PR3b) | Gates de debug → `manage_debug` |
| `20261001004` (PR4) | `admin_externalpage()` con cap |
| `20261001005` (PR5) | `capabilities` en `db/services.php` |
| `20261001006` (PR6) | `require_capability()` defense-in-depth en clases WS |
| `20261001007` (PR7) | `gmk_psicologo` + CLI de migración siteadmins |

---

**Mantenedor**: equipo de desarrollo.
**Contacto**: en el canal `#isi-moodle` del Slack institucional.
