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
 * Scheduled task: warn teachers whose class gradebook weights don't total 100%
 * once the class is past its grace period (week 3).
 *
 * Without a 100% total the plugin cannot compute a final grade for the class,
 * which in turn means no student is ever flagged as eligible for revalidation
 * and the "Gestión de Reválidas" section stays invisible to the teacher. This
 * task is the push half of that safety net; the dashboard banner is the pull
 * half (see classes/external/teacher/get_dashboard_data.php).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\task;

defined('MOODLE_INTERNAL') || die();

use local_grupomakro_core\local\revalida_manager;

class warn_incomplete_gradebook_weights extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task:warn_incomplete_gradebook_weights', 'local_grupomakro_core');
    }

    public function execute() {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

        $now = time();
        $classes = $DB->get_records_select(
            'gmk_class',
            'approved = 1 AND closed = 0 AND enddate > :now',
            ['now' => $now],
            'enddate ASC',
            'id, name, initdate, enddate, courseid, corecourseid, instructorid, supportinstructorid'
        );

        // teacherid => [ ['name' => class name, 'pct' => float, 'url' => string], ... ]
        $byteacher = [];
        $flagged = 0;

        foreach ($classes as $class) {
            if (!revalida_manager::weights_warning_due($class, $now)) {
                continue;
            }
            $flagged++;
            $status = revalida_manager::get_weights_status((int)$class->id);
            $entry = [
                'name' => (string)$class->name,
                'pct'  => (float)$status['pct'],
                'url'  => revalida_manager::gradebook_setup_url($class),
            ];

            // Both the main instructor and the support teacher can fix the
            // gradebook, so both get told.
            foreach ([(int)$class->instructorid, (int)$class->supportinstructorid] as $teacherid) {
                if ($teacherid > 0) {
                    $byteacher[$teacherid][] = $entry;
                }
            }
        }

        $sent = 0;
        $errors = 0;
        foreach ($byteacher as $teacherid => $entries) {
            try {
                if ($this->notify_teacher((int)$teacherid, $entries)) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                $errors++;
                mtrace('  ERROR notificando al docente ' . $teacherid . ': ' . $e->getMessage());
            }
        }

        mtrace('Gradebook weights check complete.');
        mtrace('  Clases activas revisadas: ' . count($classes));
        mtrace('  Clases sin 100%:          ' . $flagged);
        mtrace('  Docentes notificados:     ' . $sent);
        if ($errors > 0) {
            mtrace('  Errores de envío:         ' . $errors);
        }
    }

    /**
     * Sends one consolidated message per teacher listing every class of theirs
     * whose weights are incomplete.
     *
     * @param int $teacherid
     * @param array $entries
     * @return bool True when the message was handed to the message API.
     */
    protected function notify_teacher(int $teacherid, array $entries): bool {
        $user = \core_user::get_user($teacherid);
        if (!$user || $user->deleted || $user->suspended) {
            return false;
        }

        $count = count($entries);
        $lines = [];
        $htmlitems = [];
        foreach ($entries as $entry) {
            $pct = rtrim(rtrim(number_format($entry['pct'], 1, '.', ''), '0'), '.');
            $lines[] = '- ' . $entry['name'] . ' (suma ' . $pct . '%)';
            $htmlitems[] = '<li>' . s($entry['name']) . ' — suma <strong>' . s($pct) . '%</strong>'
                . ($entry['url'] !== '' ? ' · <a href="' . s($entry['url']) . '">Abrir libro de calificaciones</a>' : '')
                . '</li>';
        }

        $subject = get_string('msg:gradebook_weights_incomplete:subject', 'local_grupomakro_core', $count);
        $intro = get_string('msg:gradebook_weights_incomplete:intro', 'local_grupomakro_core', $count);
        $plain = $intro . "\n\n" . implode("\n", $lines);
        $html = '<p>' . s($intro) . '</p><ul>' . implode('', $htmlitems) . '</ul>';

        $message = new \core\message\message();
        $message->component = 'local_grupomakro_core';
        $message->name = 'gradebook_weights_incomplete';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = $subject;
        $message->fullmessage = $plain;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = $html;
        $message->smallmessage = $subject;
        $message->notification = 1;
        $message->contexturl = (new \moodle_url('/local/grupomakro_core/pages/teacher_dashboard.php'))->out(false);
        $message->contexturlname = get_string('msg:gradebook_weights_incomplete:contexturlname', 'local_grupomakro_core');

        return (bool)message_send($message);
    }
}
