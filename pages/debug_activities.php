<?php
/**
 * Debug: Actividades de clase por docente
 * Diagnostica por qué un docente no ve actividades en la pestaña ACTIVIDADES.
 *
 * Uso: /local/grupomakro_core/pages/debug_activities.php?username=ba947649
 *      /local/grupomakro_core/pages/debug_activities.php?username=ba947649&classid=123
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/modinfolib.php');
require_once($CFG->libdir . '/gradelib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_activities.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Debug: Actividades de clase');
$PAGE->set_heading('Debug: Actividades de clase');
$PAGE->set_pagelayout('admin');

$username = optional_param('username', '', PARAM_RAW);
$classid  = optional_param('classid', 0, PARAM_INT);

echo $OUTPUT->header();

function dbg_ok($msg)  { echo '<p style="color:#1b5e20;margin:2px 0">&#x2705; ' . s($msg) . '</p>'; }
function dbg_err($msg) { echo '<p style="color:#b71c1c;margin:2px 0">&#x274C; ' . s($msg) . '</p>'; }
function dbg_warn($msg){ echo '<p style="color:#e65100;margin:2px 0">&#x26A0;&#xFE0F; ' . s($msg) . '</p>'; }
function dbg_info($msg){ echo '<p style="color:#1565c0;margin:2px 0">&#x2139;&#xFE0F; ' . s($msg) . '</p>'; }

function section_box($title) {
    echo '<div style="background:#f5f5f5;border-left:4px solid #1976d2;padding:12px 16px;margin:16px 0 6px">'
       . '<strong style="font-size:1rem">' . s($title) . '</strong></div>';
}

function data_table($rows) {
    echo '<table style="border-collapse:collapse;font-size:0.85rem;margin:4px 0 12px">';
    foreach ($rows as $row) {
        $k = $row[0]; $v = $row[1];
        $style = 'border:1px solid #ddd;padding:4px 10px';
        echo "<tr><td style='{$style};background:#fafafa;font-weight:bold;white-space:nowrap'>" . s($k) . "</td>"
           . "<td style='{$style}'>" . s((string)$v) . "</td></tr>";
    }
    echo '</table>';
}
?>

<style>body{font-family:sans-serif} pre{background:#263238;color:#eceff1;padding:12px;border-radius:4px;overflow-x:auto;font-size:0.8rem} code{background:#e8eaf6;padding:1px 4px;border-radius:3px;font-size:0.82rem}</style>

<form method="get" style="margin-bottom:20px;display:flex;gap:10px;align-items:flex-end">
    <div><label><strong>Username / cédula del docente</strong><br>
        <input name="username" value="<?php echo s($username); ?>" style="padding:6px 10px;border:1px solid #bbb;border-radius:4px;width:220px">
    </label></div>
    <div><label><strong>Class ID (opcional — filtra una clase)</strong><br>
        <input name="classid" value="<?php echo $classid ?: ''; ?>" type="number" style="padding:6px 10px;border:1px solid #bbb;border-radius:4px;width:120px">
    </label></div>
    <button type="submit" style="padding:7px 20px;background:#1976d2;color:white;border:none;border-radius:4px;cursor:pointer">Diagnosticar</button>
</form>

<?php if (!$username):
    echo '<p>Ingresa el <strong>username</strong> (c&eacute;dula) del docente y presiona Diagnosticar.</p>';
    echo $OUTPUT->footer(); exit;
endif;

global $DB;

// ============================================================
// STEP 1: Usuario
// ============================================================
section_box('1. Usuario en Moodle');

$user = $DB->get_record('user', ['username' => $username, 'deleted' => 0]);
if (!$user) {
    $user = $DB->get_record('user', ['username' => strtolower($username), 'deleted' => 0]);
}
if (!$user) {
    dbg_err("Usuario con username '$username' NO encontrado en Moodle.");
    echo $OUTPUT->footer(); exit;
}

dbg_ok("Usuario encontrado: {$user->firstname} {$user->lastname} (ID: {$user->id})");
data_table([
    ['ID',        $user->id],
    ['Username',  $user->username],
    ['Email',     $user->email],
    ['Auth',      $user->auth],
    ['Suspended', $user->suspended ? 'SI' : 'No'],
]);

// ============================================================
// STEP 2: Clases
// ============================================================
section_box('2. Clases asignadas como instructor (gmk_class)');

$classes = $DB->get_records('gmk_class', ['instructorid' => $user->id], 'id DESC');

if (empty($classes)) {
    dbg_err("No hay clases en gmk_class con instructorid={$user->id}.");
    echo $OUTPUT->footer(); exit;
}

dbg_ok("Se encontraron " . count($classes) . " clase(s).");

// Tabla resumen
echo '<table style="border-collapse:collapse;font-size:0.82rem;margin:6px 0 12px;width:100%">';
echo '<tr style="background:#1976d2;color:white">
    <th style="padding:5px 8px">ID</th>
    <th style="padding:5px 8px">Nombre</th>
    <th style="padding:5px 8px">corecourseid</th>
    <th style="padding:5px 8px">coursesectionid</th>
    <th style="padding:5px 8px">groupid</th>
    <th style="padding:5px 8px">attendancemoduleid</th>
    <th style="padding:5px 8px">Ver detalle</th>
</tr>';
foreach ($classes as $c) {
    $hl = ($classid && $c->id == $classid) ? 'background:#fff9c4' : '';
    $sec_color = empty($c->coursesectionid) ? 'color:red;font-weight:bold' : '';
    $link = new moodle_url('/local/grupomakro_core/pages/debug_activities.php',
        ['username' => $username, 'classid' => $c->id]);
    echo "<tr style='{$hl}'>
        <td style='padding:4px 8px;border-bottom:1px solid #eee'>{$c->id}</td>
        <td style='padding:4px 8px;border-bottom:1px solid #eee'>" . s($c->name ?? '') . "</td>
        <td style='padding:4px 8px;border-bottom:1px solid #eee'>{$c->corecourseid}</td>
        <td style='padding:4px 8px;border-bottom:1px solid #eee;{$sec_color}'>" . ($c->coursesectionid ?: 'NULL/0') . "</td>
        <td style='padding:4px 8px;border-bottom:1px solid #eee'>" . ($c->groupid ?: '0') . "</td>
        <td style='padding:4px 8px;border-bottom:1px solid #eee'>" . ($c->attendancemoduleid ?: '0') . "</td>
        <td style='padding:4px 8px;border-bottom:1px solid #eee'><a href='{$link}'>Detalle</a></td>
    </tr>";
}
echo '</table>';

// Filtrar clases a diagnosticar en detalle
$debug_classes = $classid ? array_filter($classes, function($c) use ($classid){ return $c->id == $classid; }) : $classes;
if ($classid && empty($debug_classes)) {
    dbg_warn("classid=$classid no pertenece a este docente. Mostrando todas.");
    $debug_classes = $classes;
}

// ============================================================
// STEP 3: Detalle por clase
// ============================================================
foreach ($debug_classes as $class) {
    section_box("3. Detalle — Clase ID {$class->id}: " . s($class->name ?? '(sin nombre)'));

    // 3a. Curso Moodle
    echo '<p style="margin:8px 0 2px"><strong>3a. Curso Moodle (corecourseid={$class->corecourseid})</strong></p>';
    $course = $DB->get_record('course', ['id' => $class->corecourseid]);
    if (!$course) {
        dbg_err("Curso Moodle ID {$class->corecourseid} NO existe en mdl_course.");
        continue;
    }
    dbg_ok("Curso: [{$course->shortname}] {$course->fullname} (ID: {$course->id})");

    // 3a-2. Verificar inscripción del docente en el curso Moodle
    echo '<p style="margin:8px 0 2px"><strong>3a-2. Inscripcion del docente en el curso Moodle</strong></p>';
    $enrolcheck = $DB->get_record_sql(
        "SELECT ue.id, ue.status, ue.timestart, ue.timeend, r.shortname AS rolename
           FROM {user_enrolments} ue
           JOIN {enrol} e ON e.id = ue.enrolid
           JOIN {role_assignments} ra ON ra.userid = ue.userid AND ra.contextid = (SELECT id FROM {context} WHERE contextlevel=50 AND instanceid=e.courseid)
           JOIN {role} r ON r.id = ra.roleid
          WHERE ue.userid = :userid AND e.courseid = :courseid
          LIMIT 1",
        ['userid' => $user->id, 'courseid' => $class->corecourseid]
    );
    if (!$enrolcheck) {
        // Try simpler check
        $enrolsimple = $DB->get_record_sql(
            "SELECT ue.id, ue.status FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE ue.userid = :userid AND e.courseid = :courseid LIMIT 1",
            ['userid' => $user->id, 'courseid' => $class->corecourseid]
        );
        if (!$enrolsimple) {
            dbg_err("El docente NO esta inscrito en el curso Moodle ID {$class->corecourseid}. Esto causara que uservisible=false para todas las actividades.");
            dbg_info("FIX: Inscribir manualmente al docente en el curso con rol editingteacher.");
        } else {
            dbg_warn("Inscripcion encontrada (status={$enrolsimple->status}) pero sin role_assignment claro. Status=1 significa suspendido.");
        }
    } else {
        $statusLabel = $enrolcheck->status == 0 ? 'activa' : 'SUSPENDIDA';
        if ($enrolcheck->status == 0) {
            dbg_ok("Inscripcion {$statusLabel} con rol: {$enrolcheck->rolename}");
        } else {
            dbg_err("Inscripcion SUSPENDIDA (status=1) con rol: {$enrolcheck->rolename}. El docente no puede ver las actividades.");
        }
    }

    // 3b. coursesectionid
    echo '<p style="margin:8px 0 2px"><strong>3b. coursesectionid</strong></p>';
    if (empty($class->coursesectionid)) {
        dbg_err("coursesectionid esta vacio (NULL o 0). El endpoint get_all_activities devuelve activities:[] cuando esto pasa.");
        dbg_info("FIX: Actualizar gmk_class.coursesectionid con el ID correcto de course_sections.");
    } else {
        $section = $DB->get_record('course_sections', ['id' => $class->coursesectionid]);
        if (!$section) {
            dbg_err("coursesectionid={$class->coursesectionid} NO existe en mdl_course_sections.");
        } else {
            $match = ((int)$section->course === (int)$class->corecourseid);
            dbg_ok("Seccion encontrada: section_num={$section->section}, curso={$section->course}, name=" . s($section->name ?: '(sin nombre)'));
            if (!$match) {
                dbg_err("La seccion pertenece al curso {$section->course} pero la clase apunta a {$class->corecourseid}. Mismatch!");
            }
        }
    }

    // 3c. Modules en la section via SQL directo
    echo '<p style="margin:8px 0 2px"><strong>3c. course_modules en la seccion (SQL directo)</strong></p>';
    if (!empty($class->coursesectionid)) {
        $raw_cms = $DB->get_records_sql(
            "SELECT cm.id, cm.instance, cm.visible, cm.section, m.name AS modname
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :courseid AND cm.section = :sectionid
           ORDER BY cm.id",
            ['courseid' => (int)$class->corecourseid, 'sectionid' => (int)$class->coursesectionid]
        );

        if (empty($raw_cms)) {
            dbg_err("No hay course_modules con course={$class->corecourseid} AND section={$class->coursesectionid}.");
        } else {
            dbg_ok("Encontrados " . count($raw_cms) . " course_module(s):");
            echo '<table style="border-collapse:collapse;font-size:0.82rem;margin:4px 0">';
            echo '<tr style="background:#e3f2fd"><th style="padding:3px 8px">cmid</th><th style="padding:3px 8px">modname</th><th style="padding:3px 8px">instance</th><th style="padding:3px 8px">section</th><th style="padding:3px 8px">visible</th></tr>';
            $has_bbb = false; $has_att = false;
            foreach ($raw_cms as $cmr) {
                $vc = $cmr->visible ? 'green' : '#b71c1c';
                echo "<tr>
                    <td style='padding:3px 8px;border-bottom:1px solid #eee'>{$cmr->id}</td>
                    <td style='padding:3px 8px;border-bottom:1px solid #eee'><strong>{$cmr->modname}</strong></td>
                    <td style='padding:3px 8px;border-bottom:1px solid #eee'>{$cmr->instance}</td>
                    <td style='padding:3px 8px;border-bottom:1px solid #eee'>{$cmr->section}</td>
                    <td style='padding:3px 8px;border-bottom:1px solid #eee;color:{$vc}'>" . ($cmr->visible ? 'visible' : 'OCULTO') . "</td>
                </tr>";
                if ($cmr->modname === 'bigbluebuttonbn') $has_bbb = true;
                if ($cmr->modname === 'attendance') $has_att = true;
            }
            echo '</table>';
            $has_bbb ? dbg_ok("BigBlueButton (BBB) esta en la seccion.") : dbg_warn("BigBlueButton NO esta en la seccion.");
            $has_att ? dbg_ok("Asistencia (attendance) esta en la seccion.") : dbg_warn("Asistencia (attendance) NO esta en la seccion.");
        }
    } else {
        dbg_warn("Omitido — coursesectionid vacio.");
    }

    // 3d. get_fast_modinfo — simular filtro uservisible
    echo '<p style="margin:8px 0 2px"><strong>3d. Simulacion get_fast_modinfo + filtro uservisible AS TEACHER (userid=' . $user->id . ')</strong></p>';
    try {
        $modinfo = get_fast_modinfo($class->corecourseid, $user->id);

        if (empty($class->coursesectionid)) {
            dbg_err("coursesectionid vacio → cms=[] → response activities:[]");
        } else {
            $section_info = $modinfo->get_section_info_by_id((int)$class->coursesectionid);
            if (!$section_info) {
                dbg_err("get_section_info_by_id({$class->coursesectionid}) retorno null. La seccion no existe en modinfo.");
                dbg_info("Secciones disponibles en el curso segun modinfo:");
                foreach ($modinfo->get_section_info_all() as $si) {
                    echo "<code style='margin-left:20px;display:block'>id={$si->id} section_num={$si->section} name=" . s($si->name ?: '(sin nombre)') . "</code>";
                }
            } else {
                $section_num = $section_info->__get('section');
                dbg_ok("section_info OK → section_num=$section_num");
                $all_sections = $modinfo->get_sections();
                if (!isset($all_sections[$section_num])) {
                    dbg_err("get_sections() no tiene clave section_num=$section_num.");
                } else {
                    $cmids = $all_sections[$section_num];
                    $visible_list = [];
                    $hidden_list  = [];
                    foreach ($cmids as $cmid) {
                        $cm = $modinfo->get_cm($cmid);
                        if ($cm->modname === 'label') continue;
                        if (!$cm->uservisible) {
                            $hidden_list[] = "cmid=$cmid [{$cm->modname}] '{$cm->name}' — uservisible=false";
                        } else {
                            $visible_list[] = ['id' => $cm->id, 'name' => $cm->name, 'modname' => $cm->modname];
                        }
                    }
                    foreach ($hidden_list as $hl) { dbg_warn("FILTRADO: $hl"); }
                    if (empty($visible_list)) {
                        dbg_err("Ninguna actividad pasa el filtro → activities:[] en la respuesta.");
                    } else {
                        dbg_ok(count($visible_list) . " actividad(es) pasarian el filtro:");
                        foreach ($visible_list as $act) {
                            echo "<code style='margin-left:20px;display:block'>cmid={$act['id']} [{$act['modname']}] " . s($act['name']) . "</code>";
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        dbg_err("get_fast_modinfo lanzó excepción: " . $e->getMessage());
    }

    // 3e. attendancemoduleid y bbb_relation
    echo '<p style="margin:8px 0 2px"><strong>3e. attendancemoduleid y gmk_bbb_attendance_relation</strong></p>';
    if (empty($class->attendancemoduleid)) {
        dbg_warn("attendancemoduleid esta vacio en gmk_class.");
    } else {
        $att_cm = $DB->get_record('course_modules', ['id' => $class->attendancemoduleid]);
        if (!$att_cm) {
            dbg_err("attendancemoduleid={$class->attendancemoduleid} NO existe en course_modules.");
        } else {
            dbg_ok("Attendance CM: id={$att_cm->id}, section={$att_cm->section}, visible={$att_cm->visible}.");
            if ((int)$att_cm->section !== (int)$class->coursesectionid) {
                dbg_warn("Attendance esta en section={$att_cm->section} pero clase tiene coursesectionid={$class->coursesectionid}.");
            }
        }
    }

    $bbb_rel = $DB->get_record('gmk_bbb_attendance_relation', ['classid' => $class->id]);
    if (!$bbb_rel) {
        dbg_warn("Sin registro en gmk_bbb_attendance_relation para classid={$class->id}.");
    } else {
        dbg_ok("gmk_bbb_attendance_relation: attendanceid={$bbb_rel->attendanceid}, attendancemoduleid={$bbb_rel->attendancemoduleid}.");
    }

    // 3f. Respuesta JSON final simulada
    echo '<p style="margin:8px 0 2px"><strong>3f. JSON que retornaria get_all_activities ahora mismo (como el docente)</strong></p>';
    $context_course = context_course::instance($class->corecourseid);
    $PAGE->set_context($context_course);
    try {
        $mi2 = get_fast_modinfo($class->corecourseid, $user->id);
        $cms2 = [];
        if (!empty($class->coursesectionid)) {
            $si2 = $mi2->get_section_info_by_id((int)$class->coursesectionid);
            if ($si2) {
                $sn2 = $si2->__get('section');
                $all2 = $mi2->get_sections();
                if (isset($all2[$sn2])) {
                    foreach ($all2[$sn2] as $cmid) { $cms2[] = $mi2->get_cm($cmid); }
                }
            }
        }
        $activities2 = [];
        foreach ($cms2 as $cm2) {
            if (!$cm2->uservisible || $cm2->modname === 'label') continue;
            $activities2[] = ['id' => $cm2->id, 'name' => $cm2->name, 'modname' => $cm2->modname];
        }
        echo '<pre>' . json_encode(['status' => 'success', 'activities' => $activities2], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
        if (empty($activities2)) {
            dbg_err("CONFIRMADO: el endpoint retornaria activities:[] para esta clase. Ver pasos anteriores para la causa raiz.");
        } else {
            dbg_ok("El endpoint retornaria " . count($activities2) . " actividad(es). Si el frontend muestra vacio, el problema esta en el componente Vue o en el classId que recibe.");
        }
    } catch (Exception $e) {
        dbg_err("Error simulando endpoint: " . $e->getMessage());
    }

    echo '<hr style="margin:24px 0">';
}

echo $OUTPUT->footer();
