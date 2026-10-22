<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Target of the wellness event QR: records the student's attendance.
 *
 * Flow (mirrors attendance_qr_bridge.php):
 *   1. No Moodle session -> LXP /attendance/qr?evento=ID&t=TOKEN, which signs
 *      the student in and sends them back here with lxp=1.
 *   2. Token and check-in window validated, mora refused.
 *   3. Result shown by the LXP (/attendance/evento), or /restriccion-pago for mora.
 *
 * GET params: id (event id), t (QR token), lxp (1 when coming back from the LXP).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wellness_event_checkin.php');

use local_grupomakro_core\local\wellness_event_checkin;

$eventid = required_param('id', PARAM_INT);
$token = optional_param('t', '', PARAM_ALPHANUM);
$fromlxp = optional_param('lxp', 0, PARAM_INT);

$appbase = rtrim(trim((string)get_config('local_grupomakro_core', 'student_app_url')), '/');
if ($appbase === '') {
    $appbase = 'https://students.isi.edu.pa';
}

if (!isloggedin() || isguestuser()) {
    if (!$fromlxp) {
        // The custom theme ignores wantsurl, so let the LXP handle the login and come back.
        header('Location: ' . $appbase . '/attendance/qr?' . http_build_query(['evento' => $eventid, 't' => $token], '', '&'));
        exit;
    }
    // Came back from the LXP still without a Moodle session: fall back to Moodle's login.
    require_login();
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/wellness_event_checkin.php', ['id' => $eventid]));

if (!wellness_event_checkin::valid_token($eventid, $token)) {
    $outcome = ['result' => 'invalid', 'title' => ''];
} else {
    $outcome = wellness_event_checkin::checkin($eventid, (int)$USER->id);
}

if ($outcome['result'] === 'mora') {
    redirect(new moodle_url($appbase . '/restriccion-pago', ['motivo' => 'mora', 'origen' => 'evento']));
}

redirect(new moodle_url($appbase . '/attendance/evento', [
    'resultado' => $outcome['result'],
    'titulo'    => $outcome['title'],
]));
