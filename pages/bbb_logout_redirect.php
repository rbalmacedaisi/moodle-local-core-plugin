<?php
/**
 * BBB logout bridge.
 *
 * After a BigBlueButton session ends, BBB redirects here.
 * - Students  → Vue LXP course page (/courses/overview/<courseid>)
 * - Teachers / managers → Moodle course view
 */

require_once('../../../config.php');

$courseid = required_param('courseid', PARAM_INT);

require_login();

$context = context_course::instance($courseid);

// Teachers, managers and admins stay in Moodle.
if (has_capability('moodle/course:manageactivities', $context)) {
    redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
}

// Students → Vue LXP.
$base = trim((string)get_config('local_grupomakro_core', 'student_app_url'));
if ($base === '') {
    $base = 'https://students.isi.edu.pa';
}
$base = rtrim($base, '/');

header('Location: ' . $base . '/courses/overview/' . (int)$courseid);
exit;
