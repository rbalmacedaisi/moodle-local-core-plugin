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

$masssubject = trim(optional_param('masssubject', '', PARAM_TEXT));
$massplanid = optional_param('massplanid', 0, PARAM_INT);
$massstatuscsv = trim(optional_param('massstatus', '0,1,2,5', PARAM_TEXT));
$masslimit = max(20, min(2000, optional_param('masslimit', 200, PARAM_INT)));
$massonlyvirtual = optional_param('massonlyvirtual', 1, PARAM_BOOL);
$massonlysimilar = optional_param('massonlysimilar', 1, PARAM_BOOL);
$action = optional_param('action', '', PARAM_ALPHA);

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
    return $status === null ? '-' : ($map[$status] ?? ('Estado ' . $status));
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

function dss_hidden_inputs(array $params): string {
    $html = '';
    foreach ($params as $name => $value) {
        if (is_array($value)) {
            foreach ($value as $v) {
                $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name . '[]', 'value' => (string)$v]);
            }
        } else {
            $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => (string)$value]);
        }
    }
    return $html;
}

function dss_parse_statuses(string $csv): array {
    $parts = preg_split('/[,\s;]+/', trim($csv));
    $out = [];
    foreach ($parts as $p) {
        if ($p === '') {
            continue;
        }
        $v = (int)$p;
        if ($v >= 0 && $v <= 999) {
            $out[$v] = $v;
        }
    }
    return array_values($out);
}

function dss_is_manual_final_item(string $itemname): bool {
    $n = dss_norm($itemname);
    return (strpos($n, 'NOTA FINAL INTEGRADA') !== false) ||
        (strpos($n, 'FINAL INTEGRADA') !== false) ||
        (strpos($n, 'NOTA FINAL') !== false);
}

function dss_get_course_grade_signals(int $userid, int $courseid): array {
    global $DB;
    static $cache = [];
    $k = $userid . ':' . $courseid;
    if (isset($cache[$k])) {
        return $cache[$k];
    }

    $rows = $DB->get_records_sql(
        "SELECT gi.itemtype, gi.itemname, COALESCE(gg.finalgrade, gg.rawgrade) AS gradeval
           FROM {grade_items} gi
      LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :uid
          WHERE gi.courseid = :cid",
        ['uid' => $userid, 'cid' => $courseid]
    );
    $manual = null;
    $coursetotal = null;
    foreach ($rows as $r) {
        if ($r->gradeval === null) {
            continue;
        }
        $g = (float)$r->gradeval;
        if ($r->itemtype === 'course') {
            $coursetotal = is_null($coursetotal) ? $g : max($coursetotal, $g);
        }
        if ($g >= 0 && $g <= 100 && dss_is_manual_final_item((string)$r->itemname)) {
            $manual = is_null($manual) ? $g : max($manual, $g);
        }
    }

    $cache[$k] = ['manual' => $manual, 'course_total' => $coursetotal];
    return $cache[$k];
}

function dss_get_class_category_signal(int $userid, int $classid): ?float {
    global $DB;
    static $cache = [];
    $k = $userid . ':' . $classid;
    if (array_key_exists($k, $cache)) {
        return $cache[$k];
    }
    $grade = $DB->get_field_sql(
        "SELECT MAX(COALESCE(gg.finalgrade, gg.rawgrade))
           FROM {gmk_class} c
           JOIN {grade_items} gi ON gi.courseid = c.corecourseid AND gi.itemtype = 'category' AND gi.iteminstance = c.gradecategoryid
      LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :uid
          WHERE c.id = :cid AND c.gradecategoryid > 0",
        ['uid' => $userid, 'cid' => $classid]
    );
    $v = null;
    if ($grade !== false && $grade !== null) {
        $g = (float)$grade;
        $v = ($g >= 0 && $g <= 100) ? $g : null;
    }
    $cache[$k] = $v;
    return $v;
}

function dss_get_group_category_signal(int $userid, int $groupid): ?float {
    global $DB;
    static $cache = [];
    $k = $userid . ':' . $groupid;
    if (array_key_exists($k, $cache)) {
        return $cache[$k];
    }
    $grade = $DB->get_field_sql(
        "SELECT MAX(COALESCE(gg.finalgrade, gg.rawgrade))
           FROM {gmk_class} c
           JOIN {grade_items} gi ON gi.courseid = c.corecourseid AND gi.itemtype = 'category' AND gi.iteminstance = c.gradecategoryid
      LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :uid
          WHERE c.groupid = :gid AND c.gradecategoryid > 0",
        ['uid' => $userid, 'gid' => $groupid]
    );
    $v = null;
    if ($grade !== false && $grade !== null) {
        $g = (float)$grade;
        $v = ($g >= 0 && $g <= 100) ? $g : null;
    }
    $cache[$k] = $v;
    return $v;
}

function dss_resolve_target_class_group(int $userid, int $courseid, int $planid, int $currentclassid, int $currentgroupid): array {
    global $DB;

    if ($currentclassid > 0) {
        $current = $DB->get_record_sql(
            "SELECT gc.id, gc.groupid, (CASE WHEN gm.id IS NULL THEN 0 ELSE 1 END) AS ismember
               FROM {gmk_class} gc
          LEFT JOIN {groups_members} gm ON gm.groupid = gc.groupid AND gm.userid = :uid
              WHERE gc.id = :classid",
            ['uid' => $userid, 'classid' => $currentclassid]
        );
        if ($current && (int)$current->ismember === 1) {
            return ['classid' => (int)$current->id, 'groupid' => (int)$current->groupid];
        }
    }

    $params = ['uid' => $userid, 'cid' => $courseid];
    $planwhere = '';
    if ($planid > 0) {
        $planwhere = ' AND gc.learningplanid = :lpid';
        $params['lpid'] = $planid;
    }

    $best = $DB->get_record_sql(
        "SELECT gc.id, gc.groupid
           FROM {gmk_class} gc
           JOIN {groups_members} gm ON gm.groupid = gc.groupid AND gm.userid = :uid
          WHERE gc.corecourseid = :cid $planwhere
       ORDER BY gc.approved DESC, gc.closed ASC, gc.enddate DESC, gc.id DESC",
        $params,
        IGNORE_MULTIPLE
    );
    if ($best) {
        return ['classid' => (int)$best->id, 'groupid' => (int)$best->groupid];
    }

    $best = $DB->get_record_sql(
        "SELECT gc.id, gc.groupid
           FROM {gmk_class} gc
           JOIN {groups_members} gm ON gm.groupid = gc.groupid AND gm.userid = :uid
          WHERE gc.corecourseid = :cid
       ORDER BY gc.approved DESC, gc.closed ASC, gc.enddate DESC, gc.id DESC",
        ['uid' => $userid, 'cid' => $courseid],
        IGNORE_MULTIPLE
    );
    if ($best) {
        return ['classid' => (int)$best->id, 'groupid' => (int)$best->groupid];
    }

    return ['classid' => $currentclassid, 'groupid' => $currentgroupid];
}

function dss_force_rows_to_inprogress(array $cpids): array {
    global $DB;
    $ids = [];
    foreach ($cpids as $id) {
        $id = (int)$id;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    $ids = array_values($ids);

    $updated = 0;
    $errors = [];
    $now = time();
    foreach ($ids as $id) {
        $cp = $DB->get_record(
            'gmk_course_progre',
            ['id' => $id],
            'id,userid,learningplanid,courseid,classid,groupid',
            IGNORE_MISSING
        );
        if (!$cp) {
            $errors[] = "ID $id no existe.";
            continue;
        }

        $target = dss_resolve_target_class_group(
            (int)$cp->userid,
            (int)$cp->courseid,
            (int)$cp->learningplanid,
            (int)$cp->classid,
            (int)$cp->groupid
        );
        try {
            $DB->update_record('gmk_course_progre', (object)[
                'id' => (int)$cp->id,
                'status' => 2,
                'grade' => 0,
                'progress' => 0,
                'classid' => (int)$target['classid'],
                'groupid' => (int)$target['groupid'],
                'timemodified' => $now,
            ]);
            $updated++;
        } catch (Throwable $e) {
            $errors[] = "ID $id: " . $e->getMessage();
        }
    }
    return ['updated' => $updated, 'errors' => $errors];
}

function dss_load_mass_candidates(array $filters): array {
    global $DB;
    $where = ['u.deleted = 0'];
    $params = [];
    $statuses = $filters['statuses'];

    if (!empty($statuses)) {
        list($insql, $inparams) = $DB->get_in_or_equal($statuses, SQL_PARAMS_NAMED, 'st');
        $where[] = "cp.status $insql";
        $params += $inparams;
    } else {
        $where[] = 'cp.status NOT IN (3,4)';
    }

    if (!empty($filters['planid'])) {
        $where[] = 'cp.learningplanid = :planid';
        $params['planid'] = (int)$filters['planid'];
    }
    // Subject filtering is applied after fetch using accent-insensitive normalization.

    $sql = "SELECT cp.id, cp.userid, cp.learningplanid, cp.periodid, cp.courseid, cp.classid, cp.groupid,
                   cp.status, cp.grade, cp.progress, cp.coursename, cp.timemodified,
                   u.firstname, u.lastname, u.username, u.idnumber,
                   c.fullname AS coursefullname
              FROM {gmk_course_progre} cp
              JOIN {user} u ON u.id = cp.userid
              JOIN {course} c ON c.id = cp.courseid
             WHERE " . implode(' AND ', $where) . "
          ORDER BY cp.timemodified DESC, cp.id DESC";
    $records = array_values($DB->get_records_sql($sql, $params, 0, (int)$filters['limit']));

    $docmap = [];
    $docfieldid = (int)$DB->get_field('user_info_field', 'id', ['shortname' => 'documentnumber'], IGNORE_MISSING);
    if ($docfieldid > 0 && !empty($records)) {
        $userids = [];
        foreach ($records as $r) {
            $userids[(int)$r->userid] = (int)$r->userid;
        }
        if (!empty($userids)) {
            list($uin, $uparams) = $DB->get_in_or_equal(array_values($userids), SQL_PARAMS_NAMED, 'uid');
            $docs = $DB->get_records_sql(
                "SELECT userid, data FROM {user_info_data} WHERE fieldid = :fid AND userid $uin",
                ['fid' => $docfieldid] + $uparams
            );
            foreach ($docs as $d) {
                $docmap[(int)$d->userid] = (string)$d->data;
            }
        }
    }

    $subjectnorm = dss_norm((string)$filters['subject']);
    $out = [];
    foreach ($records as $r) {
        $uid = (int)$r->userid;
        $cid = (int)$r->courseid;
        $cpgrade = is_null($r->grade) ? null : (float)$r->grade;
        if (!is_null($cpgrade) && ($cpgrade < 0 || $cpgrade > 100)) {
            $cpgrade = null;
        }

        $signals = dss_get_course_grade_signals($uid, $cid);
        $manual = $signals['manual'];
        $coursetotal = $signals['course_total'];
        $classcat = (int)$r->classid > 0 ? dss_get_class_category_signal($uid, (int)$r->classid) : null;
        $groupcat = (int)$r->groupid > 0 ? dss_get_group_category_signal($uid, (int)$r->groupid) : null;

        $effective = null;
        $source = 'none';
        if (!is_null($manual)) {
            $effective = $manual;
            $source = 'manual_nota_final_integrada';
        } else if (!is_null($classcat)) {
            $effective = $classcat;
            $source = 'class_category';
        } else if (!is_null($groupcat)) {
            $effective = $groupcat;
            $source = 'group_class_category';
        } else if (!is_null($coursetotal)) {
            $effective = $coursetotal;
            $source = 'course_total';
        } else if (!is_null($cpgrade)) {
            $effective = $cpgrade;
            $source = 'gmk_course_progre';
        }

        $status = (int)$r->status;
        $previrtual = (!is_null($effective) && $effective >= 70.0 && !in_array($status, [3, 4], true));
        $postvirtual = (!is_null($effective) && $effective >= 70.0 && in_array($status, [0, 1], true));
        $similar = (!is_null($effective) && $effective >= 70.0 && in_array($status, [2, 5, 6, 7], true));

        if (!empty($filters['onlyvirtual']) && !$previrtual) {
            continue;
        }
        if (!empty($filters['onlysimilar']) && !$similar) {
            continue;
        }

        $label = trim((string)$r->coursename) !== '' ? (string)$r->coursename : (string)$r->coursefullname;
        if ($subjectnorm !== '' && strpos(dss_norm($label . ' ' . (string)$r->coursefullname), $subjectnorm) === false) {
            continue;
        }

        $out[] = [
            'id' => (int)$r->id,
            'userid' => $uid,
            'documentnumber' => $docmap[$uid] ?? '',
            'username' => (string)$r->username,
            'name' => trim((string)$r->firstname . ' ' . (string)$r->lastname),
            'learningplanid' => (int)$r->learningplanid,
            'periodid' => (int)$r->periodid,
            'courseid' => $cid,
            'coursefullname' => (string)$r->coursefullname,
            'status' => $status,
            'cpgrade' => $cpgrade,
            'classid' => (int)$r->classid,
            'groupid' => (int)$r->groupid,
            'manualgrade' => $manual,
            'classcatgrade' => $classcat,
            'groupcatgrade' => $groupcat,
            'coursetotalgrade' => $coursetotal,
            'effectivegrade' => $effective,
            'effectivegradesource' => $source,
            'previrtual' => $previrtual,
            'postvirtual' => $postvirtual,
            'similar' => $similar,
            'timemodified' => (int)$r->timemodified,
        ];
    }
    return $out;
}

$persistparams = [
    'documentnumber' => $document,
    'subject' => $subject,
    'learningplanid' => $planidparam,
    'masssubject' => $masssubject,
    'massplanid' => $massplanid,
    'massstatus' => $massstatuscsv,
    'masslimit' => $masslimit,
    'massonlyvirtual' => $massonlyvirtual ? 1 : 0,
    'massonlysimilar' => $massonlysimilar ? 1 : 0,
];

$actionmessages = [];
if (in_array($action, ['fixselected', 'fixvisible', 'fixone'], true)) {
    require_sesskey();
    $ids = [];
    if ($action === 'fixone') {
        $ids[] = optional_param('cpid', 0, PARAM_INT);
    } else if ($action === 'fixselected') {
        $ids = optional_param_array('cpids', [], PARAM_INT);
    } else {
        $ids = optional_param_array('visibleids', [], PARAM_INT);
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function($v) { return $v > 0; })));
    if (empty($ids)) {
        $actionmessages[] = ['type' => 'warning', 'text' => 'No se seleccionaron registros para corregir.'];
    } else {
        $r = dss_force_rows_to_inprogress($ids);
        $actionmessages[] = ['type' => 'success', 'text' => 'Registros corregidos a Cursando: ' . (int)$r['updated'] . '.'];
        if (!empty($r['errors'])) {
            $actionmessages[] = ['type' => 'warning', 'text' => implode(' | ', array_slice($r['errors'], 0, 5))];
        }
    }
}

$massfilters = [
    'subject' => $masssubject,
    'planid' => $massplanid,
    'statuses' => dss_parse_statuses($massstatuscsv),
    'limit' => $masslimit,
    'onlyvirtual' => $massonlyvirtual,
    'onlysimilar' => $massonlysimilar,
];
$massrows = dss_load_mass_candidates($massfilters);

$massplanopts = [0 => 'Todos los planes'];
$allplans = $DB->get_records('local_learning_plans', null, 'id ASC', 'id,name');
foreach ($allplans as $p) {
    $massplanopts[(int)$p->id] = '#' . (int)$p->id . ' ' . $p->name;
}

$docfields = $DB->get_records_select('user_info_field', "shortname IN ('documentnumber','document_number','documento','cedula')");
$userid = 0;
$user = null;
if ($document !== '') {
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

echo $OUTPUT->header();
echo html_writer::tag('h3', 'Debug estado de materia (individual + masivo)');
foreach ($actionmessages as $msg) {
    echo $OUTPUT->notification(s($msg['text']), $msg['type']);
}

echo html_writer::tag('h4', 'Analisis individual por estudiante');
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
echo dss_hidden_inputs([
    'masssubject' => $masssubject,
    'massplanid' => $massplanid,
    'massstatus' => $massstatuscsv,
    'masslimit' => $masslimit,
    'massonlyvirtual' => $massonlyvirtual ? 1 : 0,
    'massonlysimilar' => $massonlysimilar ? 1 : 0,
]);
echo ' ';
echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary', 'value' => 'Analizar']);
echo html_writer::end_tag('form');

if (!$user) {
    echo $OUTPUT->notification('No se encontro usuario para el documento indicado.', 'warning');
} else if (empty($plans)) {
    echo $OUTPUT->notification('El usuario no tiene learning plans de estudiante.', 'warning');
} else {
    echo html_writer::tag('p', 'Usuario: ' . s($user->firstname . ' ' . $user->lastname) . ' (userid ' . $userid . ')');

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
    } else {
        $pensum = json_decode((string)($res['pensum'] ?? ''), false);
        if (!$pensum) {
            echo $OUTPUT->notification('Pensum vacio o invalido.', 'warning');
        } else {
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
            } else {
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
                echo html_writer::tag('h5', 'API pensum (vista estudiante)');
                echo dss_table(['periodo', 'courseid', 'materia', 'status api', 'grade api', 'gradesource', 'progressid', 'classid', 'groupid'], $wsrows);
                foreach (array_values($courseids) as $courseid) {
                    echo html_writer::tag('h5', 'Detalle courseid ' . (int)$courseid);
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
                        if ($val !== null && $val >= 0 && $val <= 100 && dss_is_manual_final_item((string)$r->itemname)) {
                            $manual = is_null($manual) ? $val : max($manual, $val);
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
                    echo dss_table(['course_completion_id', 'timecompleted'], [[
                        $cc ? (int)$cc->id : '-',
                        $cc ? dss_dt($cc->timecompleted) : '-',
                    ]]);

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
            }
        }
    }
}

echo html_writer::tag('hr', '');
echo html_writer::tag('h4', 'Deteccion masiva de casos similares');
echo html_writer::tag('p', 'Detecta y corrige filas donde la materia puede verse aprobada por fallback de nota.', ['class' => 'text-muted']);

echo html_writer::start_tag('form', ['method' => 'get']);
echo dss_hidden_inputs(['documentnumber' => $document, 'subject' => $subject, 'learningplanid' => $planidparam]);
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'masssubject', 'value' => $masssubject, 'placeholder' => 'Materia (opcional)']);
echo ' ';
echo html_writer::select($massplanopts, 'massplanid', $massplanid, false);
echo ' ';
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'massstatus', 'value' => $massstatuscsv, 'size' => 12, 'title' => 'Estados por coma']);
echo ' ';
echo html_writer::empty_tag('input', ['type' => 'number', 'name' => 'masslimit', 'value' => (string)$masslimit, 'min' => '20', 'max' => '2000', 'style' => 'width:90px']);
echo ' ';
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'massonlyvirtual', 'value' => '0']);
echo html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'massonlyvirtual', 'value' => '1', 'checked' => $massonlyvirtual ? 'checked' : null, 'id' => 'dssmv']);
echo html_writer::tag('label', 'Solo virtual pre-patch', ['for' => 'dssmv']);
echo ' ';
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'massonlysimilar', 'value' => '0']);
echo html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'massonlysimilar', 'value' => '1', 'checked' => $massonlysimilar ? 'checked' : null, 'id' => 'dssms']);
echo html_writer::tag('label', 'Solo similares (2/5/6/7)', ['for' => 'dssms']);
echo ' ';
echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-secondary', 'value' => 'Buscar']);
echo html_writer::end_tag('form');

echo html_writer::tag('p', 'Resultados: ' . count($massrows) . ' (limite ' . (int)$masslimit . ').');

if (empty($massrows)) {
    echo $OUTPUT->notification('No se encontraron casos con los filtros actuales.', 'info');
} else {
    echo html_writer::start_tag('form', ['method' => 'post']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo dss_hidden_inputs($persistparams);
    echo html_writer::tag('button', 'Corregir seleccionados a Cursando', [
        'type' => 'submit',
        'name' => 'action',
        'value' => 'fixselected',
        'class' => 'btn btn-primary',
    ]);
    echo ' ';
    echo html_writer::tag('button', 'Corregir todos los visibles', [
        'type' => 'submit',
        'name' => 'action',
        'value' => 'fixvisible',
        'class' => 'btn btn-secondary',
    ]);

    $rows = [];
    foreach ($massrows as $r) {
        $fixurl = new moodle_url('/local/grupomakro_core/pages/debug_student_subject_status.php', $persistparams + [
            'action' => 'fixone',
            'cpid' => (int)$r['id'],
            'sesskey' => sesskey(),
        ]);
        $fixone = html_writer::link($fixurl, 'Corregir fila');

        $rows[] = [
            html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'cpids[]', 'value' => (int)$r['id']]),
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'visibleids[]', 'value' => (int)$r['id']]) . (int)$r['id'],
            (int)$r['userid'],
            s($r['documentnumber'] !== '' ? $r['documentnumber'] : $r['username']),
            s($r['name']),
            (int)$r['learningplanid'],
            (int)$r['courseid'],
            s($r['coursefullname']),
            (int)$r['status'] . ' (' . s(dss_status_label((int)$r['status'])) . ')',
            is_null($r['cpgrade']) ? '-' : round((float)$r['cpgrade'], 2),
            (int)$r['classid'],
            (int)$r['groupid'],
            is_null($r['manualgrade']) ? '-' : round((float)$r['manualgrade'], 2),
            is_null($r['classcatgrade']) ? '-' : round((float)$r['classcatgrade'], 2),
            is_null($r['groupcatgrade']) ? '-' : round((float)$r['groupcatgrade'], 2),
            is_null($r['coursetotalgrade']) ? '-' : round((float)$r['coursetotalgrade'], 2),
            is_null($r['effectivegrade']) ? '-' : round((float)$r['effectivegrade'], 2),
            s($r['effectivegradesource']),
            $r['previrtual'] ? 'SI' : 'NO',
            $r['postvirtual'] ? 'SI' : 'NO',
            $r['similar'] ? 'SI' : 'NO',
            dss_dt($r['timemodified']),
            $fixone,
        ];
    }

    echo dss_table(
        ['Sel', 'cp.id', 'userid', 'documento', 'estudiante', 'plan', 'courseid', 'materia', 'status', 'grade cp', 'classid', 'groupid', 'manual', 'class cat', 'group cat', 'course total', 'grade efectiva', 'fuente', 'virtual pre', 'virtual post', 'similar', 'modificado', 'accion'],
        $rows
    );
    echo html_writer::tag('button', 'Corregir seleccionados a Cursando', [
        'type' => 'submit',
        'name' => 'action',
        'value' => 'fixselected',
        'class' => 'btn btn-primary',
    ]);
    echo ' ';
    echo html_writer::tag('button', 'Corregir todos los visibles', [
        'type' => 'submit',
        'name' => 'action',
        'value' => 'fixvisible',
        'class' => 'btn btn-secondary',
    ]);
    echo html_writer::end_tag('form');
}

echo $OUTPUT->footer();
