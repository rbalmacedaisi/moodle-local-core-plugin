# Prompt: Gestión de usuarios, roles y capacidades en Moodle (ISI fork)

Pega el bloque de abajo a un agente cuando haya que:
- Diagnosticar por qué un usuario no puede ver / editar / hacer algo en Moodle.
- Asignar, revocar o reasignar roles (`gmk_director_academico`, `gmk_secretaria_academica`, etc.).
- Matricular o desmatricular usuarios de cursos / cohortes.
- Auditar campos custom del plugin `local_grupomakro_core` (usertype, documento, jornada, etc.).
- Explorar la base de datos de Moodle para responder "¿quién puede hacer X?" o "¿qué usuario cumple Y?".

Está escrito para alguien que **no conoce este proyecto**, así que es autocontenido.

Actualizado al plugin **20261001068**.

---

## PROMPT (copiar desde aquí)

### Arquitectura del sistema

Esto **no es Moodle estándar**: es un fork institucional con 5 piezas:

| Pieza | Qué hace | Dónde corre | BD |
|---|---|---|---|
| **Moodle (fork)** | LMS — cursos, actividades, calificaciones, asistencia | `100.28.104.64` (EC2 prod), `/var/www/html/moodle` | `isidb` (MySQL) |
| **Odoo** | ERP — contabilidad, facturación, estudiantes como contactos | `34.224.173.39` (EC2 prod) | `odoo_staging` (Postgres) |
| **Plugin Odoo `moodle`** | Sincroniza contactos/estudiantes Odoo → Moodle (espejo de usuarios) | Dentro del contenedor `odoo-odoo-1` | — |
| **Servidor Express** (`rest_express`) | API que el LXP (interfaz del estudiante) usa para hablar con Odoo | `https://lms.isi.edu.pa:4000` | — |
| **LXP (Vue)** | Interfaz del estudiante, separada de Moodle | `https://lms.isi.edu.pa/lxp` | — |

El plugin `local_grupomakro_core` (este repo) vive **dentro del fork de Moodle** y coordina los 5 roles administrativos internos:

```
gmk_director_academico        → Director Académico        (rol 14)
gmk_secretaria_academica       → Secretaría Académica      (rol 15)
gmk_registros_academicos       → Registros Académicos      (rol 16)
gmk_soporte_ti                 → Soporte TI                (rol 17)
gmk_bienestar                  → Coordinador de Bienestar  (rol 18)
gmk_psicologo                  → Psicólogo/a               (rol 19)
administrative                → Administrativo (legacy)    (rol 12)
```

Estos roles **no vienen de Moodle core** — los define este plugin (`db/upgradelib.php::create_roles()`). Los siteadmins originales fueron migrados a estos roles por el CLI `cli/migrate_siteadmins_to_roles.php` (PR `20261001007`).

### Conexiones

#### SSH al server Moodle
```bash
ssh -i "C:/cred/moodle.pem" ubuntu@100.28.104.64
```

#### BD Moodle (MySQL)
- Host: `52.20.149.225`
- Base: `isidb`
- Usuario: `isi`
- Password: `zLK9UjZVtCyYJTvDg4rk8F*` (vía `--defaults-file` para evitar problemas de escape)
- **Prefijo de tablas: `isi_`** (NO `mdl_`)

Forma segura de conectar desde el server Moodle:
```bash
ssh -i "C:/cred/moodle.pem" ubuntu@100.28.104.64 \
  "mysql --defaults-file=/tmp/my.cnf isidb < /tmp/query.sql"
```

Forma alternativa (con `mysql` CLI local sin defaults file):
```bash
mysql -h 52.20.149.225 -u isi -p"zLK9UjZVtCyYJTvDg4rk8F*" isidb -BNe "SELECT ..."
```

⚠ En PowerShell el `*` se expande como wildcard — usar `--defaults-file` o escapar con `` ` ``.

#### Repo local (plugin)
```
C:\Users\Ramiro\Documents\TI\Proyectos\Plug Ins Moodle\grupomakro_core
```

Branch `master`, sin remote configurado pero **sí tiene** remote a `https://github.com/rbalmacedaisi/moodle-local-core-plugin.git` (verificado — el server hace `git pull origin master` desde ahí).

#### Deploy Moodle (flujo completo)

1. **Local**: commit + push al remote de GitHub.
2. **Server**: SSH al server, `git pull`, ejecutar upgrade, purgar caches, recargar PHP-FPM.
   ```bash
   ssh -i "C:/cred/moodle.pem" ubuntu@100.28.104.64
   cd /var/www/html/moodle/local/grupomakro_core
   git -c safe.directory='*' pull --no-edit origin master
   cd /var/www/html/moodle
   sudo -u www-data php admin/cli/upgrade.php --non-interactive
   sudo -u www-data php admin/cli/purge_caches.php
   sudo systemctl reload php7.4-fpm
   ```
3. **Purga sesiones activas** de los usuarios afectados para forzar re-login con caps nuevas:
   ```sql
   DELETE FROM isi_sessions WHERE userid IN (<id1>, <id2>);
   ```
4. **Truncar cache MUC** si los permisos no se reflejan:
   ```sql
   TRUNCATE TABLE isi_cache_filters;
   TRUNCATE TABLE isi_cache_flags;
   ```

### Regla de oro: audita ANTES de cambiar nada

**Una capability concedida casi nunca es suficiente para que el usuario pueda hacer lo que pide.** Este plugin reparte el control de acceso en **nueve capas** y cada una puede bloquear por su cuenta:

1. **La página** — `grep require_capability pages/<x>.php`
2. **Controles paralelos dentro de la misma página** — el archivo puede tener su propio gate arriba Y repetir el control abajo por su cuenta.
3. **El árbol de administración** (`settings.php`) — 27 páginas llaman `admin_externalpage_setup()`, que busca la página en el árbol y le hace `check_access()`.
4. **El menú** (`lib.php`, `local_grupomakro_core_extend_navigation`) — mapa `[capability, página, etiqueta]`.
5. **Los `case` de `ajax.php`** — `grep -n "case 'local_grupomakro_<accion>'" ajax.php` y mirar su `require_capability`.
6. **`db/services.php`** — campo `capabilities` del web service.
7. **El `require_capability` dentro del `execute()` de la clase externa** (`classes/external/**`).
8. **Flags JS en la página** — `grep -n "is_siteadmin" pages/<x>.php` y mirar qué variable emite.
9. **Capabilities CORE de Moodle que las APIs usan por dentro** — ver sección "Trampas conocidas".

### Tablas clave de Moodle (con prefijo `isi_`)

| Tabla | Para qué la uso |
|---|---|
| `isi_user` | Usuarios (`id`, `username`, `firstname`, `lastname`, `email`, `suspended`, `deleted`, `auth`, `timecreated`) |
| `isi_role` | Roles (`id`, `shortname`, `name`, `archetype`) |
| `isi_role_assignments` | Asignaciones de rol (`userid`, `roleid`, `contextid`, `component`, `itemid`, `timemodified`) |
| `isi_role_capabilities` | Capabilities por rol y contexto (`roleid`, `capability`, `contextid`, `permission`) |
| `isi_context` | Jerarquía de contextos (`id`, `contextlevel`, `instanceid`, `path`) — `contextlevel`: 10=system, 30=cat, 40=course, 50=module, 70=mod (course_modules), 80=block |
| `isi_capabilities` | Catálogo de caps (`name`, `component`, `riskbitmask`) |
| `isi_enrol` | Métodos de matriculación por curso (`enrol`, `status`, `courseid`) |
| `isi_user_enrolments` | Usuarios matriculados (`userid`, `enrolid`, `status`) |
| `isi_course` | Cursos (`id`, `shortname`, `fullname`, `category`, `visible`) |
| `isi_course_modules` | Actividades (`id`, `course`, `module`, `instance`, `visible`, `deletioninprogress`) |
| `isi_user_info_field` | **Custom fields del plugin** (`id`, `shortname`, `name`, `datatype`, `param1`) — ej: `usertype`, `accountmanager`, `birthdate`, `documenttype`, `documentnumber`, `needfirsttuition`, `personalemail`, `periodo_ingreso`, `gmkjourney` |
| `isi_user_info_data` | Valores de custom fields (`userid`, `fieldid`, `data`) |
| `isi_cohort` | Cohortes globales (`id`, `name`, `contextid`) |
| `isi_cohort_members` | Miembros de cohorte (`cohortid`, `userid`) |
| `isi_sessions` | Sesiones activas (`sid`, `userid`, `timecreated`, `timemodified`, `firstip`, `lastip`) |
| `isi_config_plugins` | Versión del plugin (`plugin`, `name`, `value`) — `value` es el savepoint |
| `isi_cache_filters` / `isi_cache_flags` | Cache MUC — truncar cuando los permisos no se reflejan |

### Queries SQL de diagnóstico — copiar y adaptar

#### 1. Versión actual del plugin
```sql
SELECT value FROM isi_config_plugins
 WHERE plugin = 'local_grupomakro_core' AND name = 'version';
```
→ Esperado en producción actual: `20261001068`.

#### 2. Listar todos los roles `gmk_*` con su id
```sql
SELECT id, shortname, name, archetype FROM isi_role WHERE shortname LIKE 'gmk_%' ORDER BY id;
```

#### 3. Ver qué caps tiene un rol específico en `context_system` (contextid=1)
```sql
SELECT rc.capability, rc.permission
  FROM isi_role_capabilities rc
  JOIN isi_role r ON r.id = rc.roleid
 WHERE r.shortname = 'gmk_director_academico'
   AND rc.contextid = 1
 ORDER BY rc.capability;
```
→ Reemplazar el shortname por el que se esté auditando.

#### 4. Ver TODOS los contexts donde una cap está concedida/prevenida (override)
```sql
SELECT r.shortname, ctx.contextlevel, ctx.instanceid, rc.permission
  FROM isi_role_capabilities rc
  JOIN isi_context ctx ON ctx.id = rc.contextid
  JOIN isi_role r ON r.id = rc.roleid
 WHERE rc.capability = 'moodle/course:manageactivities'
 ORDER BY ctx.contextlevel, ctx.instanceid;
```
→ `permission = -1` = CAP_PROHIBIT (bloquea), `0` = CAP_PREVENT, `1` = CAP_ALLOW.

#### 5. ¿Qué caps le faltan a un usuario específico para una operación?
```sql
-- Para un user_id y un rol, mostrar todas las caps que el rol tiene en system
SELECT rc.capability
  FROM isi_role_capabilities rc
  JOIN isi_role r ON r.id = rc.roleid
  JOIN isi_role_assignments ra ON ra.roleid = r.id
 WHERE ra.userid = <userid>
   AND rc.contextid = 1
   AND rc.permission = 1
   AND rc.capability LIKE 'mod/attendance:%';
```
→ Útil para "¿por qué este usuario no puede X?" — listar caps concedidas y comparar con las que la operación requiere.

#### 6. ¿En qué contextos está asignado un usuario?
```sql
SELECT u.username, u.firstname, u.lastname,
       ctx.contextlevel, ctx.instanceid, ra.roleid, r.shortname
  FROM isi_role_assignments ra
  JOIN isi_user u ON u.id = ra.userid
  JOIN isi_context ctx ON ctx.id = ra.contextid
  JOIN isi_role r ON r.id = ra.roleid
 WHERE u.username = '<username>'
 ORDER BY ctx.contextlevel, ctx.instanceid;
```
→ Esperado para los roles `gmk_*`: **un solo row con contextlevel=10 (system), instanceid=0**. Si el Director tiene también assignments a nivel de curso, está bien; si NO tiene el system-level, las caps a nivel sistema **no se heredan**.

#### 7. Buscar un usuario
```sql
-- Por username exacto:
SELECT id, username, firstname, lastname, email, suspended, deleted, auth
  FROM isi_user WHERE username = '<username>';

-- Por nombre (autocompletar):
SELECT id, username, firstname, lastname, email, suspended, deleted, auth
  FROM isi_user WHERE LOWER(CONCAT(firstname, ' ', lastname)) LIKE '%<texto>%'
 LIMIT 20;

-- Usuarios suspendidos o borrados:
SELECT id, username, firstname, lastname, suspended, deleted, lastaccess
  FROM isi_user WHERE suspended = 1 OR deleted = 1 ORDER BY lastaccess DESC LIMIT 50;
```

#### 8. Listar todos los usuarios con un rol `gmk_*` específico
```sql
SELECT u.id, u.username, u.firstname, u.lastname, u.email, u.lastaccess,
       ra.timemodified AS role_assigned_on, ctx.contextlevel
  FROM isi_role_assignments ra
  JOIN isi_user u ON u.id = ra.userid
  JOIN isi_context ctx ON ctx.id = ra.contextid
  JOIN isi_role r ON r.id = ra.roleid
 WHERE r.shortname = '<role_shortname>'
   AND u.deleted = 0
   AND u.suspended = 0
 ORDER BY u.lastname, u.firstname;
```

#### 9. Custom fields del plugin para un usuario
```sql
SELECT uif.shortname, uif.name, uif.datatype, uid.data
  FROM isi_user_info_data uid
  JOIN isi_user_info_field uif ON uif.id = uid.fieldid
  JOIN isi_user u ON u.id = uid.userid
 WHERE u.username = '<username>'
 ORDER BY uif.shortname;
```
→ Para Bienestar/Director/Psicólogo, los campos custom importantes son: `usertype` (Estudiante vs Acudiente), `documenttype`, `documentnumber`, `periodo_ingreso`, `gmkjourney`.

#### 10. Cohortes y sus miembros
```sql
SELECT c.id, c.name, c.contextid, c.visible,
       (SELECT COUNT(*) FROM isi_cohort_members WHERE cohortid = c.id) AS members
  FROM isi_cohort c ORDER BY c.name;

-- Miembros de una cohorte:
SELECT u.id, u.username, u.firstname, u.lastname
  FROM isi_cohort_members cm
  JOIN isi_user u ON u.id = cm.userid
 WHERE cm.cohortid = <cohort_id>
 ORDER BY u.lastname, u.firstname;
```

#### 11. Matriculación: cursos de un usuario
```sql
SELECT c.id AS courseid, c.shortname, c.fullname,
       e.enrol, ue.status, ue.timestart, ue.timeend
  FROM isi_user_enrolments ue
  JOIN isi_enrol e ON e.id = ue.enrolid
  JOIN isi_course c ON c.id = e.courseid
  JOIN isi_user u ON u.id = ue.userid
 WHERE u.username = '<username>'
   AND c.id <> 1  -- excluir site course
 ORDER BY c.fullname;
```

#### 12. Sesiones activas de un usuario
```sql
SELECT s.id, s.sid, s.timecreated, s.timemodified, s.firstip, s.lastip
  FROM isi_sessions s
  JOIN isi_user u ON u.id = s.userid
 WHERE u.username = '<username>'
 ORDER BY s.timemodified DESC;
```

#### 13. Diagnóstico de "este usuario no puede ver X" en una actividad específica
```sql
-- 1. Hallar la actividad y su context_module:
SELECT cm.id AS cmid, cm.course, a.name, ctx.id AS ctxid, ctx.path
  FROM isi_course_modules cm
  JOIN isi_modules m ON m.id = cm.module
  JOIN isi_assign a ON a.id = cm.instance
  JOIN isi_context ctx ON ctx.contextlevel = 70 AND ctx.instanceid = cm.id
 WHERE m.name = 'assign' AND a.name LIKE '%<texto>%';

-- 2. Ver caps otorgadas al usuario en ese context (vía role en context_system o course):
SELECT DISTINCT rc.capability, rc.permission
  FROM isi_role_assignments ra
  JOIN isi_role_capabilities rc
    ON rc.roleid = ra.roleid
    AND (rc.contextid = 1
         OR rc.contextid = (SELECT parent FROM isi_context WHERE id = <ctxid>)
         OR rc.contextid = <ctxid>)
  JOIN isi_user u ON u.id = ra.userid
 WHERE u.username = '<username>'
   AND rc.capability IN ('mod/assign:view','mod/assign:grade','mod/assign:viewgrades')
   AND rc.permission > 0;
```
→ Si la query no devuelve filas, ese user no tiene la cap requerida en el context del módulo. Las caps del system-level (`contextid=1`) SIEMPRE aparecen porque se heredan; las caps a nivel de course pueden aparecer como `contextid = parent` (el course donde vive la actividad).

### Capabilities CORE de Moodle — la trampa que más ha costado

Los roles `gmk_*` nacieron **sin ninguna capability del core**. Varias APIs de Moodle comprueban permisos core por dentro y fallan de formas engañosas:

| API / página | Cap core necesaria | Síntoma si falta |
|---|---|---|
| `add_moduleinfo()` (crear cualquier actividad) crea además su **evento de calendario** | `moodle/calendar:manageentries` | Transacción muere a medias: la instancia queda **sin `course_module`**. En BBB se ve *"no hay sesión vinculada"* |
| `course/view.php`, `grade/index.php`, etc. | `moodle/course:view` | Redirige a `/enrol/index.php` aunque tengas caps de grado |
| Selector **"Añadir una actividad o recurso"** | Una `mod/<modname>:addinstance` por cada módulo instalado (27 en este sitio) | Lista vacía aunque tengas `moodle/course:manageactivities` |
| `cm_info::update_user_visible()` (al abrir cualquier actividad) | `mod/<modname>:view` por cada módulo | *"Esta actividad está actualmente oculta y no la puede ver"* aunque `visible=1` |
| `mod/attendance:view/takeattendances/changeattendances/manageattendances/viewreports` | abrir/modificar asistencia nativa | botón/tab Calificar no aparece; redirige a `/enrol/index.php` |
| `mod/assign:grade + mod/assign:viewgrades` | calificar entregas | botón *"Calificar todas las entregas"* no aparece en `/mod/assign/view.php` |
| `moodle/grade:edit + gradereport/singleview:view` | editar notas en Grader report / Single view | Celdas del gradebook no son editables |
| `moodle/grade:manage + moodle/grade:manageletters` | reestructurar gradebook | Acceso denegado a `/grade/edit/tree/index.php` |
| `moodle/user:viewdetails + viewalldetails` | ver perfil de un usuario | *"Lo sentimos, pero no tiene los permisos para hacer esto"* en `/user/profile.php` |
| `moodle/user:update` | editar perfil / resetear password de otros usuarios | Idem en `/user/editadvanced.php`. **AVISO**: esta cap llega a cualquier cuenta que NO sea siteadmin y permite resetear passwords; no se puede acotar a estudiantes. |
| `moodle/course:ignoreavailabilityrestrictions` | ver contenido de secciones restringidas a un grupo (cada clase en su propia sección) | *"esta actividad está actualmente oculta"* pese a `visible=1` |

### Trampas conocidas en este fork

#### A. `mod/attendance:manage` no existe en este sitio
La cap real de "gestionar sesiones" en este Moodle se llama **`mod/attendance:manageattendances`**. Si ves en código o PRs que alguien añadió `mod/attendance:manage`, eso va a reventar `assign_capability()` con `coding_exception: "Capability 'mod/attendance:manage' was not found"` y **`create_roles()` falla antes de aplicar las caps del Director y Secretaría** (porque vienen antes en el array).

**Para verificar en cualquier sitio:** `SELECT name FROM {prefix}capabilities WHERE name LIKE 'mod/attendance%';` y comparar contra el `db/access.php` del módulo instalado.

#### B. `debug=32767 + debugdisplay=1`
Cualquier `debugging()` en la ruta de un web service **se imprime dentro de la respuesta JSON** y rompe el parseo del front. Síntoma: "la página carga pero no trae datos". No es un problema de permisos — es un `debugging()` mal puesto en el código.

#### C. `assign_capability()` corre con `$overwrite = false`
Solo **añade**, nunca revoca. Para quitar un permiso hay que:
- Usar la UI de Moodle (`/admin/roles/define.php` o `/admin/roles/assign.php`).
- O `DELETE FROM {prefix}role_capabilities WHERE roleid=X AND capability='...';` directamente en BD.

#### D. `version.php` es una PILA
PHP solo toma **la última asignación** como versión efectiva:
```php
$plugin->version = 20261001060;  // override
$plugin->version = 20261001061;  // override
$plugin->version = 20261001068;  // ← esta es la efectiva
```
**Antes de numerar un upgrade nuevo**, comprobar:
```bash
grep -oE 'plugin->version   = [0-9]+' version.php | tail -3
mysql ... -e "SELECT value FROM isi_config_plugins WHERE plugin='local_grupomakro_core' AND name='version';"
```
El nuevo debe ser **mayor** que ambas. Si es menor, el upgrade se aborta con *"No se puede pasar de X a Y"*. Y un `if ($oldversion < N)` con N menor que el actual **nunca se ejecuta**.

#### E. Cache de permisos por sesión
Moodle cachea los permisos resueltos por sesión en `isi_cache_filters` y `isi_cache_flags`. Si el usuario no ve cambios de caps después de un upgrade:
1. Confirmar que las caps están en BD (`SELECT rc.* FROM {prefix}role_capabilities ...`).
2. Confirmar que no hay overrides PREVENT/PROHIBIT.
3. **Borrar las sesiones** del usuario (forzar re-login): `DELETE FROM {prefix}sessions WHERE userid = <id>;`
4. Si aún no se reflejan, **truncar cache MUC**: `TRUNCATE TABLE {prefix}cache_filters; TRUNCATE TABLE {prefix}cache_flags;`

#### F. `course_modules.deletioninprogress = 1` rompe diagnósticos
Si vale `1`, `uservisible` sale NO **hasta para siteadmin**. Si un usuario reporta "no veo X" pero tú ves "todo bien", chequea este campo primero:
```sql
SELECT id, course, module, visible, deletioninprogress FROM isi_course_modules WHERE id = <cmid>;
```

#### G. Cap de Moodle renombrada según la versión instalada
Este sitio tiene `mod/attendance:manageattendances` (no `:manage`). Siempre que añadas una cap del core a un bundle, **primero** verifica que existe:
```sql
SELECT 1 FROM {prefix}capabilities WHERE name = '<cap>';
```
Si devuelve 0 filas, **no existe** y `assign_capability()` reventará. Investiga el nombre real en `db/access.php` del módulo instalado:
```bash
ssh ubuntu@<server> grep -E "'<plugin>:[a-z]+'" /var/www/html/moodle/<plugin>/db/access.php
```

### Procedimiento para hacer un cambio de caps

1. **Audit** (sección "Regla de oro" arriba).
2. **Verifica que las caps del core existen** en `{prefix}capabilities` (trampa G).
3. **Edita** `db/upgradelib.php` — añade la cap al array `$role_caps` del rol correspondiente, con comentario.
4. **Edita** `db/upgrade.php` — añade un step `if ($oldversion < NUEVA_VERSION) { assign_capabilities_to_internal_roles(); upgrade_plugin_savepoint(true, NUEVA_VERSION, 'local', 'grupomakro_core'); }`.
5. **Edita** `version.php` — apila una línea `$plugin->version = NUEVA_VERSION; // comentario` **al final** del stack (PHP toma la última).
6. **Commit + push + pull + upgrade + purge + FPM reload** (sección "Deploy").
7. **Verifica con SQL** que las caps están en `{prefix}role_capabilities` con `permission=1` y **NO hay overrides** PREVENT/PROHIBIT.
8. **Purga sesiones** del usuario afectado + opcionalmente cache MUC.
9. **Pide al usuario cerrar el navegador y re-loguear.**

### Procedimiento para asignar / revocar un rol a un usuario

```sql
-- Asignar el rol gmk_director_academico (id=14) al usuario id=N en context system (id=1):
INSERT INTO isi_role_assignments (roleid, contextid, userid, component, itemid, timemodified)
  VALUES (14, 1, N, '', 0, UNIX_TIMESTAMP());

-- Revocar (quitar todas las asignaciones del rol gmk_director_academico al usuario N):
DELETE FROM isi_role_assignments
 WHERE userid = N AND roleid = 14;

-- Reasignar: primero revocar, luego asignar (en una transacción):
START TRANSACTION;
DELETE FROM isi_role_assignments WHERE userid = N AND roleid IN (14, 15);
INSERT INTO isi_role_assignments (roleid, contextid, userid, component, itemid, timemodified)
  VALUES (15, 1, N, '', 0, UNIX_TIMESTAMP());
COMMIT;
```

Después **purgar sesiones y cache MUC**:
```sql
DELETE FROM isi_sessions WHERE userid = N;
TRUNCATE TABLE isi_cache_filters;
TRUNCATE TABLE isi_cache_flags;
```

### Procedimiento para matricular / desmatricular

```sql
-- Matricular al usuario id=N en el curso id=C por 'manual' enrol:
INSERT INTO isi_user_enrolments (enrolid, userid, status, timestart, timeend, modifierid, timecreated, timemodified)
  SELECT e.id, N, 0, 0, 0, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
    FROM isi_enrol e WHERE e.courseid = C AND e.enrol = 'manual';

-- Desmatricular:
DELETE FROM isi_user_enrolments
 WHERE userid = N AND enrolid IN (SELECT id FROM isi_enrol WHERE courseid = C);
```

Para **matriculación masiva** hay un script CLI del plugin: `php local/grupomakro_core/cli/bulk_update_students.php` (verificar opciones con `--help`).

### Procedimiento para añadir un custom field al usuario

Los custom fields del plugin están en `isi_user_info_field` (catálogo) y `isi_user_info_data` (valores). El plugin crea 9 al instalar:
`usertype`, `accountmanager`, `birthdate`, `documenttype`, `documentnumber`, `needfirsttuition`, `personalemail`, `periodo_ingreso`, `gmkjourney`.

Para añadir uno nuevo:
1. Insertar fila en `isi_user_info_field` (ver los existentes para copiar la estructura).
2. Decidir si los usuarios existentes necesitan valor por defecto.
3. Para que el plugin muestre el campo en la UI, agregar la string en `lang/es/local_grupomakro_core.php` y `lang/en/local_grupomakro_core.php`.

Para **leer todos los valores de un campo** de todos los usuarios:
```sql
SELECT u.id, u.username, uid.data
  FROM isi_user_info_data uid
  JOIN isi_user_info_field uif ON uif.id = uid.fieldid
  JOIN isi_user u ON u.id = uid.userid
 WHERE uif.shortname = 'usertype'
 ORDER BY uid.data, u.lastname;
```

### Verificación HTTP de un usuario real

Para verificar que un usuario específico puede hacer algo, no basta con que el upgrade diga Éxito. Lo correcto:

```php
// Script CLI en /var/www/html/moodle (borrar al terminar)
\core\session\manager::set_user(
    $DB->get_record('user', ['username' => '<username>'], '*', MUST_EXIST)
);
var_dump(has_capability('moodle/course:manageactivities', context_system::instance()));
var_dump(has_capability('mod/assign:grade',
    context_module::instance(<cmid>)));
```

O mejor, por HTTP con sesión real (más realista):

```bash
# 1. Crear sesión escribiéndola a mano en BD:
SID=$(uuidgen)
PASSHASH='<hash de la password>';  # ver auth/db/auth.php o Moodle docs
mysql ... -e "INSERT INTO isi_sessions (sid, userid, ...) VALUES ('$SID', <userid>, ...)"

# 2. Hacer request con esa sesión:
curl -sI -b "MoodleSession=$SID" "https://lms.isi.edu.pa/mod/assign/view.php?id=<cmid>"
# 200 = entra; 303 a /login/ = sesión mal creada; 303/404 en otra URL = falta cap

# 3. Borrar la fila después:
mysql ... -e "DELETE FROM isi_sessions WHERE sid = '$SID'"
```

Para descargas, validar que el resultado es real (`content_type` de xlsx y los dos primeros bytes `PK`), no solo que devuelva 200.

### Cómo informar

Di con claridad:
- **Qué concediste / qué dejaste fuera / por qué.**
- **Qué queries usaste para verificar**, con el resultado (incluye "permission=1 para 5 filas" o similar).
- **El savepoint aplicado** (versión del plugin en BD).
- **Trampas que detectaste** (por ejemplo: "este sitio no tiene `mod/attendance:manage`, usé `manageattendances`").
- **Limitaciones del cambio** (caps en context_system llegan a TODOS los cursos; `moodle/user:update` permite editar cualquier cuenta no-siteadmin y resetear passwords).
- **Si el síntoma era cache, dilo** — el upgrade funcionó pero el usuario no había hecho re-login.

### Diagnóstico express cuando "no funciona"

Cuando un usuario reporta "no puedo hacer X", seguir este orden:

1. **¿La cap existe en BD?** Verificar la asignación al rol con la query 3.
2. **¿Hay override PREVENT/PROHIBIT?** Query 4.
3. **¿El usuario tiene el rol asignado?** Query 6.
4. **¿La cap requerida por la operación es del core?** Sección "Trampas conocidas" arriba.
5. **¿El usuario cerró sesión y volvió a entrar?** Si no, hay cache de sesión.
6. **¿La operación requiere `mod/<modname>:view` o `:addinstance`?** (Trampa core)
7. **¿La página nativa tiene un redirect o filter raro?** Buscar en `lib.php` `local_grupomakro_core_before_http_headers` y `local_grupomakro_core_guard_blocked_course`.
8. **¿Hay un `is_siteadmin()` escondiendo la UI?** Buscar en el JS del componente (`grep -n "is_siteadmin" js/components/<x>.js`).
9. **Si nada aplica**: leer el stack trace del error en el log de Moodle (`/var/log/moodle/moodle.log` o `data/error.log`) y en los logs de PHP-FPM (`/var/log/php7.4-fpm.log`).

---

## Resumen de hosts y credenciales (referencia rápida)

| Componente | Endpoint | User | Key/Auth |
|---|---|---|---|
| Server Moodle | `100.28.104.64` (SSH) | `ubuntu` | `C:\cred\moodle.pem` |
| BD Moodle | `52.20.149.225` | `isi` | `zLK9UjZVtCyYJTvDg4rk8F*` (prefijo `isi_`) |
| EC2 Odoo prod | `34.224.173.39` | `ec2-user` | `C:\cred\odoo-aws-key` |
| GitHub Moodle | `https://github.com/rbalmacedaisi/moodle-local-core-plugin.git` | `rbalmacedaisi` | token en git |
| GitLab Odoo | `https://gitlab.com/rbalmacedaisi/isi-erp-odoo-local` | `rbalmaceda2` | token en `C:\cred\gitlab personal access token key.txt` |
| AWS account | `905418411665` | `openclaw_pc_oficina_ramiro` | access keys en `C:\Users\Ramiro\Downloads\` |
| Moodle prod URL | `https://lms.isi.edu.pa` | — | — |
| Proxy Express prod | `https://lms.isi.edu.pa:4000` | — | — |

---

## (fin del prompt)
