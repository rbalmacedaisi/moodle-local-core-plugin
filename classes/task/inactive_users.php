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
 * This task is responsible of deleting users who have not completed the resistration process
 * after a certain amount of time.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <dev@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/user/lib.php');

class inactive_users extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskinactiveusers', 'local_grupomakro_core');
    }

    /**
     * Execute task.
     */
    public function execute() {
        global $DB;

        // Get the list of users with records in the gmk_orders table.
        $sql = "SELECT DISTINCT userid FROM {gmk_orders} WHERE status = 'APROBADO'";
        $users = $DB->get_records_sql($sql);

        // Get the inactiveafter_x_hours config value.
        $inactiveafter = get_config('local_grupomakro_core', 'inactiveafter_x_hours');

        // Delete the users where the last login is older than the inactiveafter hours.
        foreach ($users as $user) {
            $lastlogin = $DB->get_field('user', 'lastlogin', ['id' => $user->userid]);
            if ($lastlogin < time() - ($inactiveafter * 60 * 60)) {
                user_delete_user($user);
            }
        }

        
    }
}