# Auditoría: Calificación grupal en ISI Moodle

**Fecha**: 2026-10-08
**Alcance**: todo el flujo de "actividad con calificación grupal" (crear → grupos → calificar)
**Módulos auditados**: 7 endpoints PHP + 2 componentes Vue + 1 dispatch en ajax.php + 1 función helper en locallib.php

---

## Resumen ejecutivo

El **backend está completo y funcional** (7 endpoints + 1 helper + 1 flag-table + 1 wizard ya manda el flag). El **frontend tiene dos huecos importantes** que hacen que la funcionalidad "no se vea":

1. **El panel administrativo `ActivityGroupsPanel` está huérfano**: está registrado como `Vue.component('activity-groups-panel', ...)` pero **nunca se monta en ningún template**. El docente no tiene forma visual de crear/gestionar grupos desde el modal de edición de actividad.
2. **`update_activity` no sincroniza el flag de group grading**: si el docente activa/desactiva el flag de calificación grupal mientras edita una actividad existente, el flag en `gmk_activity_grading_flag` queda stale.

Adicionalmente, hay **problemas menores** que ya funcionan pero tienen issues de robustez.

---

## Inventario completo

### Backend PHP (funciona)

| Archivo | Líneas | Propósito | Estado |
|---|---|---|---|
| `classes/external/teacher/activity_group_list.php` | 102 | Lista grupos + flag | ✅ OK |
| `classes/external/teacher/activity_group_create.php` | 147 | Crea un grupo nuevo | ✅ OK |
| `classes/external/teacher/activity_group_update_members.php` | 138 | Add/remove miembros | ✅ OK |
| `classes/external/teacher/activity_group_delete.php` | 77 | Elimina un grupo | ✅ OK |
| `classes/external/teacher/activity_group_set_mode.php` | 72 | Cambia mode/cupo | ✅ OK |
| `classes/external/teacher/save_group_grade.php` | 232 | Califica el grupo | ✅ OK (con pre-flight/confirm) |
| `classes/external/teacher/save_group_quiz_grade.php` | 277 | Califica grupo quiz | ✅ OK (estructura similar) |
| `locallib.php::gmk_get_activity_grading_flag()` | 12 | Lee el flag | ✅ OK |
| `locallib.php::gmk_get_activity_groups()` | 90 | Lista grupos+miembros | ✅ OK |
| `locallib.php::gmk_get_group_existing_grades()` | 32 | Notas previas | ✅ OK |
| `classes/external/teacher/create_express_activity.php` | 228 | Crea actividad + flag | ✅ OK (líneas 158-188) |

### Dispatch en ajax.php (funciona)

- 7 cases correctamente cableados (líneas 1135, 1160, 1180, 1199, 1214, 1230, 1252, 1274, 1285)
- Cada uno con el patrón correcto (`required_param` para list, `args: JSON.stringify` para los demás)

### Frontend Vue

| Archivo | Líneas | Propósito | Estado |
|---|---|---|---|
| `js/components/ActivityGroupsPanel.js` | 529 | Panel admin de grupos | ❌ **HUÉRFANO** |
| `js/components/GroupGradeConfirmModal.js` | 90 | Modal de confirmación | ✅ OK (usado en QuickGrader) |
| `js/components/QuickGrader.js` (líneas 802-852) | 50 | Lógica saveGroupGrade | ✅ OK |
| `js/components/ActivityCreationWizard.js` (líneas 87-150, 593-598) | 100 | Switch de group grading al crear | ✅ OK |

---

## Bugs detallados

### Bug #1 (CRÍTICO): `ActivityGroupsPanel` nunca se monta

**Síntoma del usuario**: "aun no funciona" — la opción de calificación grupal existe en la creación, pero no hay forma de gestionar los grupos después.

**Evidencia**:
```bash
$ grep -rln "activity-groups-panel" --include="*.vue" --include="*.js" --include="*.php"
js/components/ActivityGroupsPanel.js     # se registra a sí mismo como Vue.component
version.php                              # metadata
styles/teacher_experience.css             # estilos
# (nada más — ningún template lo usa)
```

**Causa**: el componente se registró como `Vue.component('activity-groups-panel', ...)` (línea 18 de ActivityGroupsPanel.js) pero **nadie lo renderiza con `<activity-groups-panel ...>...</activity-groups-panel>`**. Falta el cableado en el template de `ActivityCreationWizard.js` (modo edición) o en algún dialog.

**Impacto**: el docente puede crear la actividad con el flag activo, pero después no tiene cómo crear/editar/eliminar grupos. Solo puede usar `save_group_grade` desde QuickGrader, pero eso requiere que el estudiante ya haya sido asignado a un grupo (que solo es posible desde el panel admin).

**Fix**: agregar un `<activity-groups-panel>` en el dialog de edición de actividad del `ActivityCreationWizard.js`, pasando `:cmid="cmid"` + `:modname="modname"` como props. Solo visible si la actividad es `assign` o `quiz` y el flag `enableGroupGrading` está activo.

### Bug #2 (IMPORTANTE): `update_activity` no sincroniza el flag

**Síntoma**: si el docente activa o desactiva el flag de calificación grupal mientras edita una actividad existente, el cambio no se persiste.

**Evidencia**: el case `local_grupomakro_update_activity` (línea 6926 de ajax.php) maneja `cmid, name, intro, tags, visible, duedate, allowsubmissionsfromdate, timeopen, timeclose, attempts` pero **NO** `enableGroupGrading`, `groupMode` ni `groupMaxmembers`. Por contraste, `create_express_activity.php` (líneas 158-188) sí maneja el flag.

**Causa**: cuando se agregó el flag al wizard de creación (línea 593 de ActivityCreationWizard.js) NO se extendió el código del path de edición. El frontend ni siquiera manda esos params en el caso edit (la rama `isEditing` del payload no incluye `enableGroupGrading`).

**Fix**:
1. Extender el case `update_activity` para leer `enableGroupGrading`, `groupMode`, `groupMaxmembers` (todos opcionales; default = no-change).
2. Si el flag se está activando: insertar en `gmk_activity_grading_flag` (idempotente, igual que en create).
3. Si se está desactivando: eliminar la fila (DELETE).
4. Frontend: en `ActivityCreationWizard.js`, en la rama `isEditing`, leer el flag actual del backend y enviarlo en el update.

### Bug #3 (MENOR): `ActivityGroupsPanel` carga `availableStudents` vacío

**Síntoma**: el dialog de crear grupo tiene un `<v-autocomplete>` para elegir estudiantes iniciales, pero `loadAvailableStudents()` (línea 323) está vacío — dice "una mejora futura sería un endpoint que liste estudiantes de la clase".

**Evidencia**: el método no hace ningún fetch. Los estudiantes del autocomplete vendrían vacíos.

**Fix**: agregar `classid` como prop al panel y un endpoint que liste los estudiantes matriculados de la clase (reutilizar `gmk_user_list_for_class` o `get_class_details`). El endpoint ya existe conceptualmente — solo hay que cablearlo.

### Bug #4 (MENOR): `sesskey` viene de `M.cfg.sesskey`

**Síntoma potencial**: si el componente se monta desde una página que no carga `M.cfg`, `sesskey` será `undefined` y ajax.php lo rechazará con "sesskey inválido".

**Evidencia**: línea 307 de ActivityGroupsPanel.js: `sesskey: M.cfg.sesskey` (no usa `window.wsStaticParams`).

**Fix**: usar `...window.wsStaticParams` como hacen el resto de componentes (consistencia + compatibilidad).

### Bug #5 (MENOR): no hay test de integración PHP

**Síntoma**: si alguien rompe el flujo de grupos (cambia el contrato del `gmk_activity_group_member`, elimina un campo del flag, etc), no hay red de seguridad.

**Fix**: crear `cli/test_group_grading_dispatch.php` con round-trip de:
- crear actividad con group grading
- crear grupo + agregar miembros
- save_group_grade pre-flight (warning con notas previas)
- save_group_grade confirm (success)
- delete grupo

---

## Plan de ejecución

### Fase 0 — baseline (1 turno)
1. Crear un test PHP estático que verifique el contrato de los 7 endpoints (`test_group_grading_dispatch.php`). RED: debería fallar si los endpoints devuelven shapes distintos a las esperadas.

### Fase 1 — fix del bug #1 (panel huérfano) (1 turno)
1. Agregar `<activity-groups-panel>` en `ActivityCreationWizard.js`:
   - Modo edición: dentro del dialog existente, debajo del card de "Calificación grupal" (línea 88), mostrar el panel si `enableGroupGrading=1` y la actividad es assign/quiz.
   - Modo creación: lo mismo, pero solo si la actividad ya está creada (es decir, después del primer submit exitoso). Inicialmente no hay cmid, así que el panel no puede listar grupos todavía. Considerar crear el panel como un sub-dialog "Gestionar grupos" que se abra DESPUÉS de crear.
2. Pasar `:cmid="editData.id"` y `:modname="activityType"` como props.
3. Commitar.

### Fase 2 — fix del bug #2 (update flag) (1 turno)
1. Extender el case `update_activity` (línea 6926) para manejar los 3 flags.
2. Extender el frontend: `ActivityCreationWizard.js` en la rama `isEditing` debe leer el flag actual y enviarlo en el payload.
3. Si la actividad es asign/quiz, hacer upsert/delete en `gmk_activity_grading_flag`.
4. Commitar.

### Fase 3 — fix del bug #3 (autocomplete vacío) (0.5 turno)
1. Agregar prop `classid` a `ActivityGroupsPanel.js`.
2. Llamar a un endpoint que liste estudiantes de la clase (revisar si ya existe o crear uno nuevo).
3. Commitar.

### Fase 4 — fix de bug #4 (sesskey) + bug #5 (test) (0.5 turno)
1. Cambiar `sesskey: M.cfg.sesskey` por `...window.wsStaticParams` en todos los axios.post del panel.
2. Commitar.
3. (El test del bug #5 es la fase 0 — ya está cubierto).

---

## Estimación total

- 4 commits, ~150 líneas modificadas
- Riesgo: bajo (todos los cambios son aditivos, no rompen rutas existentes)
- El bug #1 es el más visible para el usuario → atacarlo primero
