<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Repair the damage of class regenerations: false absences and orphan recordings.
 *
 * Before 7a178c9 a regeneration (schedule change in Planificacion Academica,
 * republish) deleted every BBB room of a class and created attendance sessions
 * for past dates; the automatic absence process then marked every student absent
 * in those sessions, and the recordings of the deleted rooms stopped showing.
 *
 *  A. False-absence sessions: created after their own end time (timemodified >
 *     sessdate + duration), nobody with an attending status, BBB room never used.
 *     They are deleted with their logs, relation and unused room; then the absence
 *     state of every student of the class is recomputed (counts, alerts, blocks)
 *     and the inactivation check runs again (reactivates who fell below threshold).
 *  C. Orphan recordings (their BBB activity was deleted): mapped through the BBB
 *     server name "...-<classid>-<timestamp>" and start time to the class session
 *     they belong to, and re-pointed to that session's room; a session without a
 *     room gets a new (closed, past) room linked to it. BBB truncates long names
 *     ("...-9870-17907300..."), which is accepted too; when the name does not
 *     carry the class id at all, the recording is attributed by its course (from
 *     the meeting id "<hash>-<courseid>-<bbbid>") and start time, only when exactly
 *     one class of that course has a session at that time.
 *
 * Usage:
 *   php local/grupomakro_core/cli/repair_false_absences_and_recordings.php            (plan only, no writes)
 *   php local/grupomakro_core/cli/repair_false_absences_and_recordings.php --apply [--classid=N[,M...]]
 *       [--recordings-only]   skip A (false sessions): only re-link recordings
 *
 * @package    local_grupomakro_core
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/attendance/locallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/pages/absence_helpers.php');

list($options) = cli_get_params(['apply' => false, 'classid' => '', 'recordings-only' => false]);
$apply = !empty($options['apply']);
$onlyclasses = array_filter(array_map('intval', explode(',', (string)$options['classid'])));
$recordingsonly = !empty($options['recordings-only']);
\core\session\manager::set_user(get_admin());
$now = time();
$report = '/tmp/repair_false_absences_' . ($apply ? 'apply' : 'plan') . '_' . date('Ymd_His') . '.csv';
$csv = fopen($report, 'w');
fputcsv($csv, ['accion', 'claseid', 'clase', 'sesionid', 'fecha_sesion', 'detalle']);

cli_writeln(($apply ? 'APLICANDO' : 'PLAN (sin escrituras)') . " - detalle en $report");

// Classes keyed by "attendanceid-groupid".
$classbyatt = [];
foreach ($DB->get_records_sql("SELECT c.*, cm.instance AS attid
        FROM {gmk_class} c JOIN {course_modules} cm ON cm.id = c.attendancemoduleid") as $c) {
    $classbyatt[$c->attid . '-' . $c->groupid] = $c;
}
$bbbused = function ($bbbid) use ($DB): bool {
    if (!$bbbid) {
        return false;
    }
    return $DB->record_exists_select('bigbluebuttonbn_logs',
            "bigbluebuttonbnid = :b AND log IN ('Create','Join','Summary')", ['b' => $bbbid])
        || $DB->record_exists('bigbluebuttonbn_recordings', ['bigbluebuttonbnid' => $bbbid]);
};

// ---------------------------------------------------------------- A
$sessions = $DB->get_records_sql("SELECT s.*,
        (SELECT COUNT(1) FROM {attendance_log} l WHERE l.sessionid = s.id) AS logs,
        (SELECT COUNT(1) FROM {attendance_log} l JOIN {attendance_statuses} st ON st.id = l.statusid
          WHERE l.sessionid = s.id AND st.grade > 0) AS attended
      FROM {attendance_sessions} s
     WHERE s.sessdate < :now AND s.timemodified > s.sessdate + s.duration", ['now' => $now]);
$false = [];
foreach ($sessions as $s) {
    $class = $classbyatt[$s->attendanceid . '-' . $s->groupid] ?? null;
    if ($recordingsonly || !$class || ($onlyclasses && !in_array((int)$class->id, $onlyclasses, true))) {
        continue;
    }
    if ((int)$s->attended > 0 || (int)$s->logs === 0) {
        continue;
    }
    $rels = $DB->get_records('gmk_bbb_attendance_relation', ['attendancesessionid' => $s->id]);
    $used = false;
    foreach ($rels as $r) {
        $used = $used || $bbbused((int)$r->bbbid);
    }
    if ($used) {
        continue;
    }
    $false[] = ['s' => $s, 'c' => $class, 'rels' => $rels];
}
$affectedclasses = [];
foreach ($false as $x) {
    $s = $x['s'];
    $c = $x['c'];
    $affectedclasses[(int)$c->id] = $c;
    fputcsv($csv, ['eliminar_sesion_falsa', $c->id, $c->name, $s->id, userdate($s->sessdate, '%Y-%m-%d %H:%M'),
        "{$s->logs} faltas; creada " . userdate($s->timemodified, '%Y-%m-%d %H:%M')]);
    if (!$apply) {
        continue;
    }
    $cm = get_coursemodule_from_instance('attendance', $s->attendanceid, 0, false, MUST_EXIST);
    $att = $DB->get_record('attendance', ['id' => $s->attendanceid], '*', MUST_EXIST);
    $structure = new \mod_attendance_structure($att, $cm, get_course($cm->course));
    foreach ($x['rels'] as $r) {
        $DB->delete_records('gmk_bbb_attendance_relation', ['id' => $r->id]);
        if ((int)$r->bbbmoduleid > 0 && !$bbbused((int)$r->bbbid)) {
            try {
                course_delete_module((int)$r->bbbmoduleid);
            } catch (\Throwable $e) {
                cli_writeln("  aviso: no se pudo borrar la sala cm {$r->bbbmoduleid}: " . $e->getMessage());
            }
            $ids = array_filter(array_map('intval', explode(',', (string)$DB->get_field('gmk_class', 'bbbmoduleids', ['id' => $c->id]))));
            $ids = array_values(array_diff($ids, [(int)$r->bbbmoduleid]));
            $DB->set_field('gmk_class', 'bbbmoduleids', $ids ? implode(',', $ids) : null, ['id' => $c->id]);
        }
    }
    $structure->delete_sessions([(int)$s->id]);
}
cli_writeln('A. sesiones falsas: ' . count($false) . ' en ' . count($affectedclasses) . ' clases, '
    . array_sum(array_map(fn($x) => (int)$x['s']->logs, $false)) . ' registros de falta');

// ---------------------------------------------------------------- C
$url = rtrim(get_config('core', 'bigbluebuttonbn_server_url') ?: $CFG->bigbluebuttonbn_server_url, '/') . '/';
$secret = get_config('core', 'bigbluebuttonbn_shared_secret') ?: ($CFG->bigbluebuttonbn_shared_secret ?? '');
$orph = $DB->get_records_sql("SELECT r.* FROM {bigbluebuttonbn_recordings} r WHERE r.status IN (1, 2)
     AND NOT EXISTS (SELECT 1 FROM {bigbluebuttonbn} b WHERE b.id = r.bigbluebuttonbnid)");
$meta = [];
foreach (array_chunk(array_values(array_map(fn($r) => $r->recordingid, $orph)), 40) as $chunk) {
    $q = http_build_query(['recordID' => implode(',', $chunk)]);
    $xml = @simplexml_load_string(@file_get_contents($url . 'api/getRecordings?' . $q . '&checksum=' . sha1('getRecordings' . $q . $secret)));
    if ($xml && isset($xml->recordings->recording)) {
        foreach ($xml->recordings->recording as $rr) {
            $meta[(string)$rr->recordID] = ['name' => (string)$rr->name, 'start' => (int)((int)$rr->startTime / 1000),
                'meetingid' => (string)$rr->meetingID];
        }
    }
}
$bbbmoduleid = (int)gmk_get_module_id_by_name('bigbluebuttonbn');
$cstats = ['reasignada' => 0, 'sala_nueva' => 0, 'sin_sesion' => 0, 'no_es_de_clase' => 0, 'no_en_servidor' => 0,
    'por_curso_y_hora' => 0, 'ambigua' => 0];
// Classes of a course with a session around a moment (fallback when the name lacks the class id).
$classesatmoment = function (int $courseid, int $start) use ($DB): array {
    return $DB->get_records_sql("SELECT DISTINCT c.*
          FROM {gmk_class} c
          JOIN {course_modules} cm ON cm.id = c.attendancemoduleid
          JOIN {attendance_sessions} s ON s.attendanceid = cm.instance AND s.groupid = c.groupid
         WHERE c.corecourseid = :c AND s.sessdate BETWEEN :f AND :t",
        ['c' => $courseid, 'f' => $start - 3600, 't' => $start + 1800]);
};
$newroomforsession = [];
foreach ($orph as $r) {
    $m = $meta[$r->recordingid] ?? null;
    if (!$m) {
        $cstats['no_en_servidor']++;
        continue;
    }
    // "<class name>-<classid>-<timestamp>", possibly truncated by BBB ("-9870-17907300...").
    $class = null;
    if (preg_match('/-(\d{3,6})-(\d{9,11}|\d*\.{3})$/', $m['name'], $mm)) {
        $class = $DB->get_record('gmk_class', ['id' => (int)$mm[1]]) ?: null;
    }
    if (!$class && preg_match('/-(\d+)-\d+(\[\d+\])?$/', $m['meetingid'], $mm)) {
        // Only class rooms: the (truncated) recording name must be the start of the class name.
        $prefix = trim(preg_replace('/(-\d*)?\.{3}$/', '', $m['name']));
        $cands = array_filter($classesatmoment((int)$mm[1], $m['start']),
            fn($c) => $prefix !== '' && strpos($c->name . '-' . $c->id, $prefix) === 0);
        if (count($cands) === 1) {
            $class = reset($cands);
            $cstats['por_curso_y_hora']++;
        } else if (count($cands) > 1) {
            $cstats['ambigua']++;
            fputcsv($csv, ['grabacion_ambigua', '', $m['name'], '', userdate($m['start'], '%Y-%m-%d %H:%M'),
                $r->recordingid . ' clases: ' . implode(',', array_keys($cands))]);
            continue;
        }
    }
    if (!$class) {
        $cstats['no_es_de_clase']++;
        continue;
    }
    if ($onlyclasses && !in_array((int)$class->id, $onlyclasses, true)) {
        continue;
    }
    $attid = (int)$DB->get_field('course_modules', 'instance', ['id' => $class->attendancemoduleid]);
    $sess = $attid ? $DB->get_records_sql("SELECT s.* FROM {attendance_sessions} s
          WHERE s.attendanceid = :a AND s.groupid = :g AND s.sessdate BETWEEN :f AND :t
       ORDER BY ABS(s.sessdate - :st)",
        ['a' => $attid, 'g' => $class->groupid, 'f' => $m['start'] - 6 * 3600, 't' => $m['start'] + 3 * 3600, 'st' => $m['start']], 0, 1) : [];
    $s = $sess ? reset($sess) : null;
    if (!$s) {
        $cstats['sin_sesion']++;
        fputcsv($csv, ['grabacion_sin_sesion', $class->id, $class->name, '', userdate($m['start'], '%Y-%m-%d %H:%M'), $r->recordingid]);
        continue;
    }
    $rel = $DB->get_record_sql("SELECT r.* FROM {gmk_bbb_attendance_relation} r
          JOIN {bigbluebuttonbn} b ON b.id = r.bbbid
         WHERE r.attendancesessionid = :s ORDER BY r.id DESC", ['s' => $s->id], IGNORE_MULTIPLE);
    $targetbbb = $rel ? (int)$rel->bbbid : ($newroomforsession[(int)$s->id] ?? 0);
    $action = $targetbbb ? 'reasignar_grabacion' : 'crear_sala_y_reasignar';
    fputcsv($csv, [$action, $class->id, $class->name, $s->id, userdate($s->sessdate, '%Y-%m-%d %H:%M'),
        $r->recordingid . ($targetbbb ? " -> bbb $targetbbb" : '')]);
    if ($targetbbb) {
        $cstats['reasignada']++;
    } else {
        $cstats['sala_nueva']++;
    }
    if (!$apply) {
        if (!$targetbbb) {
            $newroomforsession[(int)$s->id] = -1; // Same session's next recording reuses the room.
        }
        continue;
    }
    if (!$targetbbb) {
        // Links of this session to rooms that no longer exist are dead: drop them.
        $DB->execute("DELETE FROM {gmk_bbb_attendance_relation}
                       WHERE attendancesessionid = :s
                         AND NOT EXISTS (SELECT 1 FROM {bigbluebuttonbn} b WHERE b.id = bbbid)", ['s' => $s->id]);
        $class->course = get_course($class->corecourseid);
        $sectionnum = (int)$DB->get_field('course_sections', 'section', ['id' => $class->coursesectionid], MUST_EXIST);
        $room = create_big_blue_button_activity($class, (int)$s->sessdate, (int)$s->sessdate + (int)$s->duration,
            $bbbmoduleid, $sectionnum);
        gmk_ensure_cmid_in_section_sequence((int)$class->coursesectionid, (int)$room->coursemodule);
        $DB->insert_record('gmk_bbb_attendance_relation', (object)[
            'attendancesessionid' => (int)$s->id,
            'bbbmoduleid' => (int)$room->coursemodule,
            'bbbid' => (int)$room->instance,
            'classid' => (int)$class->id,
            'attendancemoduleid' => (int)$class->attendancemoduleid,
            'attendanceid' => $attid,
            'sectionid' => (int)$class->coursesectionid,
            'usermodified' => get_admin()->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $ids = array_filter(array_map('intval', explode(',', (string)$DB->get_field('gmk_class', 'bbbmoduleids', ['id' => $class->id]))));
        $ids[] = (int)$room->coursemodule;
        $DB->set_field('gmk_class', 'bbbmoduleids', implode(',', array_unique($ids)), ['id' => $class->id]);
        $targetbbb = (int)$room->instance;
        $newroomforsession[(int)$s->id] = $targetbbb;
    }
    $DB->update_record('bigbluebuttonbn_recordings', (object)[
        'id' => $r->id, 'bigbluebuttonbnid' => $targetbbb,
        'courseid' => (int)$DB->get_field('bigbluebuttonbn', 'course', ['id' => $targetbbb]),
        'headless' => 0, 'timemodified' => time(),
    ]);
}
cli_writeln('C. grabaciones: ' . json_encode($cstats));

// ---------------------------------------------------------------- recompute absences
if ($apply && $affectedclasses) {
    $users = 0;
    foreach ($affectedclasses as $c) {
        foreach (absd_get_class_enrolled_userids((int)$c->id) as $uid) {
            try {
                absd_recompute_user_class_state($c, (int)$uid);
                $users++;
            } catch (\Throwable $e) {
                cli_writeln("  aviso: recalculo clase {$c->id} usuario $uid: " . $e->getMessage());
            }
        }
        gmk_invalidate_schedule_caches((int)$c->id);
    }
    $check = absd_run_absence_inactivation_check();
    cli_writeln("Inasistencias recalculadas para $users estudiante(s); reactivados: " . (int)($check['reactivated'] ?? 0));
}
fclose($csv);
cli_writeln('Listo.');
