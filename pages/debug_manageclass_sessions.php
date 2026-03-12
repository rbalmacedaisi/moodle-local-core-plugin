<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$classid = optional_param('classid', 0, PARAM_INT);
$classfilter = optional_param('classfilter', '', PARAM_TEXT);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_manageclass_sessions.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Debug sesiones ManageClass');
$PAGE->set_heading('Debug sesiones ManageClass');

function gmk_dbg_table($title, $rows) {
    echo html_writer::tag('h4', s($title));
    if (empty($rows)) {
        echo html_writer::div('Sin registros.', 'alert alert-info');
        return;
    }
    $first = reset($rows);
    $headers = array_keys((array)$first);
    $table = new html_table();
    $table->head = $headers;
    foreach ($rows as $row) {
        $cells = [];
        foreach ($headers as $h) {
            $v = (array)$row;
            $cells[] = isset($v[$h]) ? s((string)$v[$h]) : '';
        }
        $table->data[] = $cells;
    }
    echo html_writer::table($table);
}

echo $OUTPUT->header();
echo html_writer::tag('h3', 'Debug de sesiones en Teacher Dashboard');

echo '<form method="get" style="margin-bottom:16px">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
echo '<label>Class ID: <input type="number" name="classid" value="' . (int)$classid . '" style="width:120px"></label> ';
echo '<label style="margin-left:12px">Filtro nombre: <input type="text" name="classfilter" value="' . s($classfilter) . '" style="width:360px"></label> ';
echo '<button type="submit">Diagnosticar</button>';
echo '</form>';

if ($classid <= 0 && $classfilter !== '') {
    $class = $DB->get_record_sql(
        "SELECT * FROM {gmk_class}
          WHERE " . $DB->sql_like('name', ':name', false, false) . "
       ORDER BY id DESC",
        ['name' => '%' . $classfilter . '%']
    );
    if ($class) {
        $classid = (int)$class->id;
    }
}

if ($classid > 0) {
    $class = $DB->get_record('gmk_class', ['id' => $classid]);
    if (!$class) {
        echo html_writer::div('Clase no encontrada.', 'alert alert-danger');
        echo $OUTPUT->footer();
        exit;
    }

    echo html_writer::tag('h4', 'Clase');
    $classrow = (array)$class;
    echo '<pre>' . s(json_encode($classrow, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';

    $source = 'none';
    $att = null;

    if (!empty($class->attendancemoduleid)) {
        $attcm = $DB->get_record_sql(
            "SELECT cm.id, cm.course, cm.section, cm.instance, m.name AS modulename
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id = :cmid",
            ['cmid' => (int)$class->attendancemoduleid]
        );
        if ($attcm && $attcm->modulename === 'attendance') {
            $att = $DB->get_record('attendance', ['id' => (int)$attcm->instance]);
            if ($att) {
                $source = 'class.attendancemoduleid';
            }
        }
    }

    if (!$att) {
        $mappedattid = $DB->get_field('gmk_bbb_attendance_relation', 'attendanceid', ['classid' => $classid]);
        if ($mappedattid) {
            $att = $DB->get_record('attendance', ['id' => (int)$mappedattid]);
            if ($att) {
                $source = 'relation.attendanceid';
            }
        }
    }

    if (!$att) {
        $mappedattcmid = $DB->get_field('gmk_bbb_attendance_relation', 'attendancemoduleid', ['classid' => $classid]);
        if ($mappedattcmid) {
            $attcm2 = $DB->get_record_sql(
                "SELECT cm.id, cm.instance, m.name AS modulename
                   FROM {course_modules} cm
                   JOIN {modules} m ON m.id = cm.module
                  WHERE cm.id = :cmid",
                ['cmid' => (int)$mappedattcmid]
            );
            if ($attcm2 && $attcm2->modulename === 'attendance') {
                $att = $DB->get_record('attendance', ['id' => (int)$attcm2->instance]);
                if ($att) {
                    $source = 'relation.attendancemoduleid';
                }
            }
        }
    }

    if (!$att && !empty($class->courseid)) {
        $att = $DB->get_record('attendance', ['course' => (int)$class->courseid], '*', IGNORE_MULTIPLE);
        if ($att) {
            $source = 'class.courseid';
        }
    }

    if (!$att && !empty($class->corecourseid) && (int)$class->corecourseid !== (int)$class->courseid) {
        $att = $DB->get_record('attendance', ['course' => (int)$class->corecourseid], '*', IGNORE_MULTIPLE);
        if ($att) {
            $source = 'class.corecourseid';
        }
    }

    $start = (int)$class->initdate - (30 * 24 * 3600);
    $end = !empty($class->enddate) ? ((int)$class->enddate + (60 * 24 * 3600)) : (time() + (365 * 24 * 3600));

    $out = [
        'selected_attendance_source' => $source,
        'selected_attendance_id' => $att ? (int)$att->id : 0,
        'selected_attendance_course' => $att ? (int)$att->course : 0,
        'window_start' => $start,
        'window_end' => $end,
    ];
    echo html_writer::tag('h4', 'Resolución de attendance');
    echo '<pre>' . s(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';

    $relations = $DB->get_records_sql(
        "SELECT id, classid, attendancesessionid, attendanceid, attendancemoduleid, bbbmoduleid, bbbid
           FROM {gmk_bbb_attendance_relation}
          WHERE classid = :classid
       ORDER BY id ASC",
        ['classid' => $classid]
    );
    gmk_dbg_table('Relaciones gmk_bbb_attendance_relation', $relations);

    if ($att) {
        $strict = $DB->get_records_sql(
            "SELECT id, attendanceid, groupid, sessdate, duration, caleventid
               FROM {attendance_sessions}
              WHERE attendanceid = :attid
                AND groupid = :groupid
                AND sessdate >= :start
                AND sessdate <= :end
           ORDER BY sessdate ASC",
            [
                'attid' => (int)$att->id,
                'groupid' => (int)$class->groupid,
                'start' => $start,
                'end' => $end
            ]
        );
        gmk_dbg_table('Sesiones strict (attendanceid + groupid + ventana)', $strict);

        $relsessionids = [];
        foreach ($relations as $rel) {
            if (!empty($rel->attendancesessionid)) {
                $relsessionids[] = (int)$rel->attendancesessionid;
            }
        }
        $relsessionids = array_values(array_unique(array_filter($relsessionids)));

        if (!empty($relsessionids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($relsessionids, SQL_PARAMS_NAMED, 'sid');
            $fromrel = $DB->get_records_sql(
                "SELECT id, attendanceid, groupid, sessdate, duration, caleventid
                   FROM {attendance_sessions}
                  WHERE id $insql
               ORDER BY sessdate ASC",
                $inparams
            );
        } else {
            $fromrel = [];
        }
        gmk_dbg_table('Sesiones por relation.attendancesessionid', $fromrel);

        $fallback = $DB->get_records_sql(
            "SELECT id, attendanceid, groupid, sessdate, duration, caleventid
               FROM {attendance_sessions}
              WHERE attendanceid = :attid
                AND sessdate >= :start
                AND sessdate <= :end
           ORDER BY sessdate ASC",
            [
                'attid' => (int)$att->id,
                'start' => $start,
                'end' => $end
            ]
        );
        gmk_dbg_table('Sesiones fallback (attendanceid + ventana, sin groupid)', $fallback);
    }

    $events = $DB->get_records_sql(
        "SELECT id, courseid, groupid, modulename, instance, timestart, timeduration
           FROM {event}
          WHERE courseid = :courseid
            AND groupid = :groupid
            AND modulename IN ('attendance','bigbluebuttonbn')
       ORDER BY timestart ASC",
        [
            'courseid' => (int)$class->corecourseid,
            'groupid' => (int)$class->groupid
        ]
    );
    gmk_dbg_table('Eventos para class_details (courseid + groupid)', $events);
}

echo $OUTPUT->footer();

