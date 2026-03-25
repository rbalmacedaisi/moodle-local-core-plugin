<?php
/**
 * Debug page: Module enrollment diagnostics.
 * Checks DB structure, plugin version, period resolution, and simulates enrollment steps.
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

admin_externalpage_setup('grupomakro_core_manage_courses');

$test_userid     = optional_param('userid', 0, PARAM_INT);
$test_courseid   = optional_param('courseid', 0, PARAM_INT);
$do_enroll       = optional_param('do_enroll', 0, PARAM_INT);

echo $OUTPUT->header();

function dbg_ok($msg)   { echo "<p style='color:#1a7a1a;font-weight:bold'>✅ $msg</p>"; }
function dbg_err($msg)  { echo "<p style='color:#cc0000;font-weight:bold'>❌ $msg</p>"; }
function dbg_warn($msg) { echo "<p style='color:#b36b00;font-weight:bold'>⚠️ $msg</p>"; }
function dbg_info($msg) { echo "<p style='color:#333'>ℹ️ $msg</p>"; }

echo "<h2 style='font-family:sans-serif'>🔬 Debug: Módulos Independientes</h2>";
echo "<hr>";

// ══════════════════════════════════════════════════════════════════════════════
// 1. Plugin version
// ══════════════════════════════════════════════════════════════════════════════
echo "<h3>1. Versión del plugin</h3>";
$installed_version = get_config('local_grupomakro_core', 'version');
$expected_version  = 20260324030;
echo "<table border='1' cellpadding='6' style='border-collapse:collapse'>";
echo "<tr><th>Instalada en DB</th><th>Esperada (version.php)</th><th>Estado</th></tr>";
$ver_ok = (int)$installed_version >= $expected_version;
$color  = $ver_ok ? '#1a7a1a' : '#cc0000';
echo "<tr>
    <td>$installed_version</td>
    <td>$expected_version</td>
    <td style='color:$color;font-weight:bold'>" . ($ver_ok ? '✅ OK' : '❌ UPGRADE PENDIENTE') . "</td>
</tr>";
echo "</table>";
if (!$ver_ok) {
    echo "<div style='background:#fff3cd;border:1px solid #ffc107;padding:10px;margin:10px 0'>";
    echo "<b>Acción requerida:</b> Ejecutar en el servidor:<br>";
    echo "<code>php /var/www/html/moodle/admin/cli/upgrade.php</code></div>";
}

// ══════════════════════════════════════════════════════════════════════════════
// 2. Estructura de tablas
// ══════════════════════════════════════════════════════════════════════════════
echo "<h3>2. Estructura de tablas</h3>";

// gmk_class: is_module + module_deadline_days
$class_cols = $DB->get_columns('gmk_class');
foreach (['is_module', 'module_deadline_days'] as $col) {
    if (isset($class_cols[$col])) {
        dbg_ok("gmk_class.$col existe (tipo: {$class_cols[$col]->type})");
    } else {
        dbg_err("gmk_class.$col NO existe — upgrade no ejecutado");
    }
}

// gmk_module_enrollment
$table_exists = $DB->get_manager()->table_exists(new xmldb_table('gmk_module_enrollment'));
if ($table_exists) {
    dbg_ok("Tabla gmk_module_enrollment existe");
    $enroll_cols = $DB->get_columns('gmk_module_enrollment');
    $required    = ['id', 'classid', 'userid', 'enrolldate', 'duedate', 'status'];
    foreach ($required as $col) {
        if (isset($enroll_cols[$col])) {
            dbg_ok("  &nbsp;&nbsp; gmk_module_enrollment.$col ✓");
        } else {
            dbg_err("  &nbsp;&nbsp; gmk_module_enrollment.$col FALTA");
        }
    }
} else {
    dbg_err("Tabla gmk_module_enrollment NO EXISTE — el upgrade no se ejecutó");
}

// gmk_academic_periods columns
$period_cols = $DB->get_columns('gmk_academic_periods');
dbg_info("Columnas de gmk_academic_periods: " . implode(', ', array_keys($period_cols)));
if (isset($period_cols['code'])) {
    dbg_err("gmk_academic_periods.code existe (atención: el código asume que NO existe)");
} else {
    dbg_ok("gmk_academic_periods NO tiene columna 'code' — correcto, se usa 'name'");
}

// ══════════════════════════════════════════════════════════════════════════════
// 3. Datos existentes de módulos
// ══════════════════════════════════════════════════════════════════════════════
echo "<h3>3. Módulos existentes en gmk_class (is_module=1)</h3>";
try {
    $module_classes = $DB->get_records_sql(
        "SELECT gc.id, c.fullname AS coursename, gc.name, gc.module_deadline_days, gc.periodid, gc.groupid,
                gap.name AS periodname
           FROM {gmk_class} gc
           JOIN {course} c ON c.id = gc.corecourseid
           LEFT JOIN {gmk_academic_periods} gap ON gap.id = gc.periodid
          WHERE gc.is_module = 1
          ORDER BY gc.id DESC
          LIMIT 20"
    );
    if ($module_classes) {
        echo "<table border='1' cellpadding='6' style='border-collapse:collapse;font-size:13px'>";
        echo "<tr><th>ID</th><th>coursename</th><th>name (grupo)</th><th>deadline_days</th><th>periodid</th><th>periodname</th><th>groupid</th><th>Inscritos</th></tr>";
        foreach ($module_classes as $mc) {
            $count = $table_exists
                ? (int)$DB->count_records('gmk_module_enrollment', ['classid' => $mc->id, 'status' => 'active'])
                : '?';
            echo "<tr>
                <td>{$mc->id}</td>
                <td>" . htmlspecialchars($mc->coursename ?? '') . "</td>
                <td>" . htmlspecialchars($mc->name ?? '') . "</td>
                <td>{$mc->module_deadline_days}</td>
                <td>{$mc->periodid}</td>
                <td>" . htmlspecialchars($mc->periodname ?? 'N/A') . "</td>
                <td>{$mc->groupid}</td>
                <td>$count</td>
            </tr>";
        }
        echo "</table>";
    } else {
        dbg_warn("No hay registros en gmk_class con is_module=1");
    }
} catch (Exception $e) {
    dbg_err("Error al consultar gmk_class: " . htmlspecialchars($e->getMessage()));
}

// ══════════════════════════════════════════════════════════════════════════════
// 4. Inscripciones en gmk_module_enrollment
// ══════════════════════════════════════════════════════════════════════════════
echo "<h3>4. Inscripciones recientes en gmk_module_enrollment</h3>";
if ($table_exists) {
    try {
        $enrollments = $DB->get_records_sql(
            "SELECT gme.id, gme.classid, gme.userid, gme.enrolldate, gme.duedate, gme.status,
                    u.firstname, u.lastname,
                    co.fullname AS coursename
               FROM {gmk_module_enrollment} gme
               JOIN {user} u  ON u.id  = gme.userid
               JOIN {gmk_class} gc ON gc.id = gme.classid
               JOIN {course} co ON co.id = gc.corecourseid
              ORDER BY gme.id DESC
              LIMIT 20"
        );
        if ($enrollments) {
            echo "<table border='1' cellpadding='6' style='border-collapse:collapse;font-size:13px'>";
            echo "<tr><th>ID</th><th>Estudiante</th><th>Asignatura</th><th>classid</th><th>Inscripción</th><th>Plazo</th><th>Estado</th></tr>";
            foreach ($enrollments as $e) {
                echo "<tr>
                    <td>{$e->id}</td>
                    <td>" . htmlspecialchars($e->firstname . ' ' . $e->lastname) . "</td>
                    <td>" . htmlspecialchars($e->coursename ?? '') . "</td>
                    <td>{$e->classid}</td>
                    <td>" . date('d/m/Y', $e->enrolldate) . "</td>
                    <td>" . date('d/m/Y', $e->duedate) . "</td>
                    <td>{$e->status}</td>
                </tr>";
            }
            echo "</table>";
        } else {
            dbg_warn("No hay inscripciones registradas en gmk_module_enrollment");
        }
    } catch (Exception $e) {
        dbg_err("Error al consultar gmk_module_enrollment: " . htmlspecialchars($e->getMessage()));
    }
} else {
    dbg_err("No se puede consultar — tabla no existe");
}

// ══════════════════════════════════════════════════════════════════════════════
// 5. Simulación paso a paso de enroll_module
// ══════════════════════════════════════════════════════════════════════════════
echo "<h3>5. Simulación de inscripción</h3>";
echo "<form method='get' style='background:#f5f5f5;padding:12px;border:1px solid #ddd;display:inline-block'>";
echo "<input type='hidden' name='sesskey' value='" . sesskey() . "'>";
echo "<label>User ID estudiante: <input type='number' name='userid' value='$test_userid' style='width:100px'></label>&nbsp;";
echo "<label>Course ID (corecourseid): <input type='number' name='courseid' value='$test_courseid' style='width:100px'></label>&nbsp;";
echo "<label><input type='checkbox' name='do_enroll' value='1'" . ($do_enroll ? ' checked' : '') . "> Ejecutar INSERT real</label>&nbsp;";
echo "<button type='submit'>Simular</button>";
echo "</form>";

if ($test_userid > 0 && $test_courseid > 0) {
    echo "<div style='background:#fff;border:1px solid #ccc;padding:12px;margin-top:10px;font-family:monospace;font-size:13px'>";

    // Step 1: User
    $user = $DB->get_record('user', ['id' => $test_userid, 'deleted' => 0], 'id,firstname,lastname');
    if ($user) {
        dbg_ok("Paso 1 — Usuario encontrado: {$user->firstname} {$user->lastname} (id={$user->id})");
    } else {
        dbg_err("Paso 1 — Usuario id=$test_userid no encontrado o eliminado");
        goto end_simulation;
    }

    // Step 2: Course
    $course = $DB->get_record('course', ['id' => $test_courseid], 'id,fullname,shortname');
    if ($course) {
        dbg_ok("Paso 2 — Curso encontrado: " . htmlspecialchars($course->fullname) . " (id={$course->id})");
    } else {
        dbg_err("Paso 2 — Curso id=$test_courseid no encontrado");
        goto end_simulation;
    }

    // Step 3: Academic period via local_learning_users
    $period = $DB->get_record_sql(
        "SELECT gap.id, gap.name
           FROM {gmk_academic_periods} gap
           JOIN {local_learning_users} llu ON llu.academicperiodid = gap.id
          WHERE llu.userid = :userid
          ORDER BY gap.id DESC
          LIMIT 1",
        ['userid' => $test_userid]
    );
    if ($period) {
        dbg_ok("Paso 3 — Período vía local_learning_users: " . htmlspecialchars($period->name) . " (id={$period->id})");
    } else {
        dbg_warn("Paso 3 — No se encontró período en local_learning_users para userid=$test_userid");
        // Fallback
        $period = $DB->get_record_sql(
            "SELECT id, name FROM {gmk_academic_periods} WHERE status = 1 ORDER BY id DESC LIMIT 1"
        );
        if ($period) {
            dbg_warn("Paso 3 (fallback) — Período activo más reciente: " . htmlspecialchars($period->name) . " (id={$period->id})");
        } else {
            dbg_err("Paso 3 — No existe ningún período académico activo");
            goto end_simulation;
        }
    }

    $periodCode = trim((string)$period->name);
    $courseName = trim((string)$course->fullname);
    $groupName  = $courseName . ' (MÓDULO) ' . $periodCode;
    dbg_info("Nombre del grupo que se usará: <b>" . htmlspecialchars($groupName) . "</b>");

    // Step 4: Find existing module class
    $moduleClass = $DB->get_record_sql(
        "SELECT id, groupid, module_deadline_days FROM {gmk_class}
          WHERE is_module = 1 AND corecourseid = :cid AND periodid = :pid LIMIT 1",
        ['cid' => $test_courseid, 'pid' => $period->id]
    );
    if ($moduleClass) {
        dbg_ok("Paso 4 — gmk_class módulo ya existe: id={$moduleClass->id}, groupid={$moduleClass->groupid}, deadline={$moduleClass->module_deadline_days}");
    } else {
        dbg_warn("Paso 4 — No existe gmk_class módulo para este curso+período (se crearía al inscribir)");
    }

    // Step 5: Check existing enrollment
    if ($moduleClass && $table_exists) {
        $existing = $DB->get_record('gmk_module_enrollment', ['classid' => $moduleClass->id, 'userid' => $test_userid]);
        if ($existing) {
            dbg_warn("Paso 5 — Ya existe inscripción en gmk_module_enrollment: id={$existing->id}, status={$existing->status}, duedate=" . date('d/m/Y', $existing->duedate));
        } else {
            dbg_ok("Paso 5 — No existe inscripción previa para este estudiante en este módulo");
        }
    } elseif (!$table_exists) {
        dbg_err("Paso 5 — No se puede verificar: tabla gmk_module_enrollment no existe");
    } else {
        dbg_info("Paso 5 — Sin clase módulo existente, no se verifica enrollment previo");
    }

    // Step 6: Check manual enrol plugin
    $enrolPlugin = enrol_get_plugin('manual');
    if ($enrolPlugin) {
        dbg_ok("Paso 6 — Plugin 'manual' disponible");
    } else {
        dbg_err("Paso 6 — Plugin 'manual' NO disponible");
    }
    $courseInstances = enrol_get_instances($test_courseid, true);
    $manualInstance  = null;
    foreach ($courseInstances as $inst) {
        if ($inst->enrol === 'manual') { $manualInstance = $inst; break; }
    }
    if ($manualInstance) {
        dbg_ok("Paso 6 — Instancia manual enrol encontrada en el curso (id={$manualInstance->id})");
    } else {
        dbg_err("Paso 6 — NO existe instancia de enrol manual en el curso id=$test_courseid");
    }

    // Step 7: Check student role
    $studentRoleId = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
    if ($studentRoleId) {
        dbg_ok("Paso 6 — Rol 'student' encontrado (id=$studentRoleId)");
    } else {
        dbg_err("Paso 6 — Rol 'student' NO encontrado");
    }

    // Step 8: Actual INSERT if requested
    if ($do_enroll && confirm_sesskey()) {
        if (!$table_exists) {
            dbg_err("INSERT cancelado: tabla gmk_module_enrollment no existe. Ejecutar upgrade primero.");
        } else {
            echo "<hr><b>▶ Ejecutando INSERT real...</b><br>";
            try {
                require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/schedule/enroll_module.php');
                $result = \local_grupomakro_core\external\schedule\enroll_module::execute(
                    $test_userid, $test_courseid, 0
                );
                echo "<pre style='background:#e8ffe8;padding:8px'>" . htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>";
                if ($result['status'] === 'ok') {
                    dbg_ok("INSERT exitoso — duedate: " . date('d/m/Y H:i', $result['duedate']));
                } elseif ($result['status'] === 'warning') {
                    dbg_warn($result['message']);
                } else {
                    dbg_err($result['message']);
                }
            } catch (Throwable $e) {
                dbg_err("EXCEPCIÓN: " . htmlspecialchars($e->getMessage()));
                echo "<pre style='background:#ffe8e8;padding:8px;font-size:12px'>"
                    . htmlspecialchars($e->getTraceAsString()) . "</pre>";
            }
        }
    } else {
        echo "<p style='color:#555'>Para ejecutar el INSERT real, marca la casilla y vuelve a enviar.</p>";
    }

    end_simulation:
    echo "</div>";
}

// ══════════════════════════════════════════════════════════════════════════════
// 6. Períodos académicos disponibles
// ══════════════════════════════════════════════════════════════════════════════
echo "<h3>6. Períodos académicos (gmk_academic_periods)</h3>";
$periods = $DB->get_records('gmk_academic_periods', null, 'id DESC', 'id,name,status', 0, 10);
if ($periods) {
    echo "<table border='1' cellpadding='6' style='border-collapse:collapse;font-size:13px'>";
    echo "<tr><th>ID</th><th>name</th><th>status</th></tr>";
    foreach ($periods as $p) {
        echo "<tr><td>{$p->id}</td><td>" . htmlspecialchars($p->name) . "</td><td>{$p->status}</td></tr>";
    }
    echo "</table>";
} else {
    dbg_err("No hay períodos académicos");
}

echo $OUTPUT->footer();
