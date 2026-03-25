<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

use local_grupomakro_core\external\student\get_student_learning_plan_pensum;

global $DB, $PAGE, $OUTPUT;

$context = context_system::instance();
require_login();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_student_subject_status.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Debug estado materia');
$PAGE->set_heading('Debug estado materia');

$document = trim(optional_param('documentnumber', 'c02196964', PARAM_TEXT));
$subject = trim(optional_param('subject', 'INGLES I', PARAM_TEXT));
$planidparam = optional_param('learningplanid', 0, PARAM_INT);

function dss_norm(string $text): string {
    if (function_exists('mb_strtoupper')) {
        $text = mb_strtoupper($text, 'UTF-8');
    } else {
        $text = strtoupper($text);
    }
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($ascii !== false) {
        $text = $ascii;
    }
    $text = preg_replace('/[^A-Z0-9 ]+/', ' ', $text);
    return trim(preg_replace('/\s+/', ' ', $text));
}

function dss_status_label(?int $status): string {
    $map = [
        0 => 'No disponible',
        1 => 'Disponible',
        2 => 'Cursando',
        3 => 'Completada',
        4 => 'Aprobada',
        5 => 'Reprobada',
        6 => 'Pendiente revalida',
        7 => 'Revalidando',
        99 => 'Migracion',
    ];
    if ($status === null) {
        return '-';
    }
    return $map[$status] ?? ('Estado ' . $status);
}

function dss_dt($ts): string {
    $ts = (int)$ts;
    return $ts > 0 ? userdate($ts, '%Y-%m-%d %H:%M:%S') : '-';
}

function dss_table(array $head, array $rows): string {
    $t = new html_table();
    $t->head = $head;
    $t->data = $rows;
    $t->attributes['class'] = 'generaltable';
    return html_writer::table($t);
}

echo $OUTPUT->header();
echo html_writer::tag('h3', 'Debug estado de materia para estudiante');

$docfields = $DB->get_records_select('user_info_field', "shortname IN ('documentnumber','document_number','documento','cedula')");
$userid = 0;
$user = null;

if (!empty($docfields)) {
    $fieldids = array_map(static function($f) { return (int)$f->id; }, $docfields);
    list($in, $paramsin) = $DB->get_in_or_equal($fieldids, SQL_PARAMS_NAMED, 'f');
    $user = $DB->get_record_sql(
        "SELECT u.id, u.firstname, u.lastname, u.username, u.email, u.idnumber
           FROM {user} u
           JOIN {user_info_data} uid ON uid.userid = u.id
          WHERE uid.fieldid $in
            AND uid.data = :doc
       ORDER BY u.deleted ASC, u.suspended ASC, u.id ASC",
        ['doc' => $document] + $paramsin
    );
}
if (!$user) {
    $user = $DB->get_record_sql(
        "SELECT id, firstname, lastname, username, email, idnumber
           FROM {user}
          WHERE idnumber = :doc OR username = :doc2
       ORDER BY deleted ASC, suspended ASC, id ASC",
        ['doc' => $document, 'doc2' => $document]
    );
}

$plans = [];
if ($user) {
    $userid = (int)$user->id;
    $plans = $DB->get_records_sql(
        "SELECT lu.learningplanid, lu.status, lp.name
           FROM {local_learning_users} lu
           JOIN {local_learning_plans} lp ON lp.id = lu.learningplanid
          WHERE lu.userid = :uid
            AND (lu.userroleid = 5 OR lu.userrolename = 'student')
       ORDER BY (CASE WHEN lu.status = 'activo' THEN 0 ELSE 1 END), lu.id DESC",
        ['uid' => $userid]
    );
}

echo html_writer::start_tag('form', ['method' => 'get']);
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'documentnumber', 'value' => $document, 'placeholder' => 'Documento']);
echo ' ';
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'subject', 'value' => $subject, 'placeholder' => 'Materia']);
echo ' ';
$planopts = [0 => 'Auto plan activo'];
foreach ($plans as $p) {
    $planopts[(int)$p->learningplanid] = '#' . (int)$p->learningplanid . ' ' . $p->name . ' [' . $p->status . ']';
}
echo html_writer::select($planopts, 'learningplanid', $planidparam, false);
echo ' ';
echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary', 'value' => 'Analizar']);
echo html_writer::end_tag('form');

if (!$user) {
    echo $OUTPUT->notification('No se encontro usuario para el documento indicado.', 'error');
    echo $OUTPUT->footer();
    return;
}

echo html_writer::tag('p', 'Usuario: ' . s($user->firstname . ' ' . $user->lastname) . ' (userid ' . $userid . ')');

if (empty($plans)) {
    echo $OUTPUT->notification('El usuario no tiene learning plans de estudiante.', 'warning');
    echo $OUTPUT->footer();
    return;
}

$selectedplanid = 0;
if ($planidparam > 0 && isset($plans[$planidparam])) {
    $selectedplanid = $planidparam;
} else {
    foreach ($plans as $p) {
        if ((string)$p->status === 'activo') {
            $selectedplanid = (int)$p->learningplanid;
            break;
        }
    }
    if ($selectedplanid <= 0) {
        $first = reset($plans);
        $selectedplanid = (int)$first->learningplanid;
    }
}

echo html_writer::tag('p', 'Plan analizado: #' . $selectedplanid . ' ' . s($plans[$selectedplanid]->name));

$res = get_student_learning_plan_pensum::execute((string)$userid, (string)$selectedplanid);
if ((int)($res['status'] ?? -1) !== 1) {
    echo $OUTPUT->notification('Error API pensum: ' . s($res['message'] ?? 'sin mensaje'), 'error');
    echo $OUTPUT->footer();
    return;
}

$pensum = json_decode((string)($res['pensum'] ?? ''), false);
if (!$pensum) {
    echo $OUTPUT->notification('Pensum vacio o invalido.', 'warning');
    echo $OUTPUT->footer();
    return;
}

$subjectnorm = dss_norm($subject);
$matches = [];
foreach ((array)$pensum as $periodnode) {
    $periodid = (int)($periodnode->id ?? 0);
    $periodname = (string)($periodnode->periodName ?? '');
    $courses = isset($periodnode->courses) && is_array($periodnode->courses) ? $periodnode->courses : [];
    foreach ($courses as $c) {
        $name = (string)($c->coursename ?? '');
        if ($subjectnorm !== '' && strpos(dss_norm($name), $subjectnorm) === false) {
            continue;
        }
        $matches[] = [
            'periodid' => $periodid,
            'periodname' => $periodname,
            'learningcourseid' => (int)($c->learningcourseid ?? 0),
            'courseid' => (int)($c->courseid ?? 0),
            'coursename' => $name,
            'status' => isset($c->status) ? (int)$c->status : 0,
            'statuslabel' => (string)($c->statusLabel ?? ''),
            'grade' => (string)($c->grade ?? '-'),
            'gradesource' => (string)($c->gradesource ?? ''),
            'progressid' => (int)($c->progressid ?? 0),
            'progressclassid' => (int)($c->progressclassid ?? 0),
            'progressgroupid' => (int)($c->progressgroupid ?? 0),
        ];
    }
}

if (empty($matches)) {
    echo $OUTPUT->notification('No hubo coincidencias para la materia en la API de pensum.', 'warning');
    echo $OUTPUT->footer();
    return;
}

$wsrows = [];
$courseids = [];
foreach ($matches as $m) {
    $wsrows[] = [
        (int)$m['periodid'] . ' - ' . s($m['periodname']),
        (int)$m['courseid'],
        s($m['coursename']),
        (int)$m['status'] . ' (' . s($m['statuslabel'] ?: dss_status_label((int)$m['status'])) . ')',
        s($m['grade']),
        s($m['gradesource']),
        (int)$m['progressid'],
        (int)$m['progressclassid'],
        (int)$m['progressgroupid'],
    ];
    $courseids[(int)$m['courseid']] = (int)$m['courseid'];
}
echo html_writer::tag('h4', 'API pensum (vista estudiante)');
echo dss_table(['periodo', 'courseid', 'materia', 'status api', 'grade api', 'gradesource', 'progressid', 'classid', 'groupid'], $wsrows);

foreach (array_values($courseids) as $courseid) {
    echo html_writer::tag('h4', 'Detalle courseid ' . (int)$courseid);

    $cp = $DB->get_records_sql(
        "SELECT id, learningplanid, periodid, classid, groupid, status, grade, progress, coursename, timecreated, timemodified
           FROM {gmk_course_progre}
          WHERE userid = :uid AND courseid = :cid
       ORDER BY timemodified DESC, id DESC",
        ['uid' => $userid, 'cid' => $courseid]
    );
    $cprows = [];
    foreach ($cp as $r) {
        $cprows[] = [
            (int)$r->id,
            (int)$r->learningplanid,
            (int)$r->periodid,
            (int)$r->classid,
            (int)$r->groupid,
            (int)$r->status . ' (' . dss_status_label((int)$r->status) . ')',
            is_null($r->grade) ? '-' : round((float)$r->grade, 2),
            is_null($r->progress) ? '-' : round((float)$r->progress, 2),
            s((string)$r->coursename),
            dss_dt($r->timecreated),
            dss_dt($r->timemodified),
        ];
    }
    echo dss_table(['id', 'plan', 'period', 'classid', 'groupid', 'status cp', 'grade cp', 'progress cp', 'coursename cp', 'created', 'modified'], $cprows);

    $gi = $DB->get_records_sql(
        "SELECT gi.id, gi.itemtype, gi.itemname, gi.grademax, COALESCE(gg.finalgrade, gg.rawgrade) AS gradeval
           FROM {grade_items} gi
      LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :uid
          WHERE gi.courseid = :cid
       ORDER BY gi.itemtype, gi.id",
        ['uid' => $userid, 'cid' => $courseid]
    );
    $manual = null;
    $coursetotal = null;
    $girows = [];
    foreach ($gi as $r) {
        $val = $r->gradeval === null ? null : (float)$r->gradeval;
        if ($r->itemtype === 'course' && $val !== null) {
            $coursetotal = is_null($coursetotal) ? $val : max($coursetotal, $val);
        }
        if ($val !== null && $val >= 0 && $val <= 100) {
            $n = dss_norm((string)$r->itemname);
            if (strpos($n, 'NOTA FINAL INTEGRADA') !== false || strpos($n, 'FINAL INTEGRADA') !== false || strpos($n, 'NOTA FINAL') !== false) {
                $manual = is_null($manual) ? $val : max($manual, $val);
            }
        }
        $girows[] = [
            (int)$r->id,
            s((string)$r->itemtype),
            s((string)$r->itemname),
            is_null($r->grademax) ? '-' : round((float)$r->grademax, 2),
            is_null($val) ? '-' : round($val, 2),
        ];
    }
    echo dss_table(['itemid', 'itemtype', 'itemname', 'grademax', 'gradeval'], $girows);

    $cc = $DB->get_record('course_completions', ['userid' => $userid, 'course' => $courseid], '*', IGNORE_MISSING);
    $ccrows = [[
        $cc ? (int)$cc->id : '-',
        $cc ? dss_dt($cc->timecompleted) : '-',
    ]];
    echo dss_table(['course_completion_id', 'timecompleted'], $ccrows);

    $passmap = gmk_get_user_passed_course_map_fast($userid, [$courseid], 70.0);
    $fastpassed = !empty($passmap[$courseid]);
    echo html_writer::start_tag('ul');
    echo html_writer::tag('li', 'manual_nota_final_integrada: ' . (is_null($manual) ? '-' : round($manual, 2)));
    echo html_writer::tag('li', 'course_total: ' . (is_null($coursetotal) ? '-' : round($coursetotal, 2)));
    echo html_writer::tag('li', 'gmk_get_user_passed_course_map_fast (>=70): ' . ($fastpassed ? 'TRUE' : 'FALSE'));
    if ($cc && (int)$cc->timecompleted > 0) {
        echo html_writer::tag('li', 'course_completions marca completado.');
    }
    echo html_writer::end_tag('ul');
}

echo $OUTPUT->footer();

