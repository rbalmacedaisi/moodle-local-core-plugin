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
 * Scheduled task: mark students with >= 3 absences as inactive.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\task;

defined('MOODLE_INTERNAL') || die();

class mark_absent_students_inactive extends \core\task\scheduled_task {

    public function get_name() {
        return 'Marcar inactivos por inasistencia';
    }

    public function execute() {
        global $CFG;

        require_once($CFG->dirroot . '/local/grupomakro_core/pages/absence_helpers.php');

        $result = absd_run_absence_inactivation_check();

        mtrace('Absence inactivation check complete.');
        mtrace('  Processed:       ' . ($result['processed']      ?? 0));
        mtrace('  Marked inactive: ' . ($result['marked_inactive'] ?? 0));
        mtrace('  Exempt (skipped):' . ($result['skipped_exempt']  ?? 0));

        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $err) {
                mtrace('  ERROR: ' . $err);
            }
        }
    }
}
