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
 * External: list the admin broadcast messages visible to the calling student.
 * Sorted by priority DESC so the LXP can surface the highest-priority notice
 * (and have it take precedence over lower-priority sources such as the
 * absence alert system).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\student;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/announcement_manager.php');

class get_pending_admin_messages extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'User ID', VALUE_REQUIRED),
        ]);
    }

    public static function execute($userid) {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['userid' => $userid]);

        $context = context_system::instance();
        self::validate_context($context);

        if (!$DB->record_exists('user', ['id' => $params['userid'], 'deleted' => 0])) {
            throw new Exception('user_not_found');
        }

        $messages = \local_grupomakro_core\local\announcement_manager::get_pending_for_user((int)$params['userid']);

        $highest = null;
        if (!empty($messages)) {
            $highest = (int)($messages[0]['priority'] ?? 0);
        }

        return [
            'messages'       => $messages,
            'count'          => count($messages),
            'highest_priority' => $highest,
            'absence_priority' => \local_grupomakro_core\local\announcement_manager::ABSENCE_PRIORITY,
            'has_priority_over_absence' => $highest !== null && $highest >= \local_grupomakro_core\local\announcement_manager::ABSENCE_PRIORITY,
        ];
    }

    public static function execute_returns() {
        $msgstructure = new external_single_structure([
            'id'                 => new external_value(PARAM_INT,  'Message id'),
            'title'              => new external_value(PARAM_TEXT, 'Message title'),
            'message'            => new external_value(PARAM_RAW,  'Message body (may contain HTML)'),
            'type'               => new external_value(PARAM_ALPHA, 'info|warning'),
            'audience_scope'     => new external_value(PARAM_ALPHA, 'all|career|group'),
            'audience_careerid'  => new external_value(PARAM_INT,   'Career id if scope=career'),
            'audience_groupid'   => new external_value(PARAM_INT,   'Group id if scope=group'),
            'require_ack'        => new external_value(PARAM_BOOL,  'True when an acknowledgement checkbox must be ticked'),
            'ack_label'          => new external_value(PARAM_TEXT, 'Text rendered next to the acknowledgement checkbox'),
            'priority'           => new external_value(PARAM_INT,   'Higher value wins when several notices stack up'),
            'starts_at'          => new external_value(PARAM_INT,   'Unix ts at which the broadcast becomes visible (0 = always)'),
            'ends_at'            => new external_value(PARAM_INT,   'Unix ts at which the broadcast stops being visible (0 = never)'),
            'timecreated'        => new external_value(PARAM_INT,   'Unix ts of creation'),
            'authorid'           => new external_value(PARAM_INT,   'Author user id'),
            'acknowledged'       => new external_value(PARAM_BOOL,  'True when the calling student already accepted this broadcast'),
            'timeacknowledged'   => new external_value(PARAM_INT,   'Unix ts of the acknowledgement'),
        ]);

        return new external_single_structure([
            'messages'                 => new external_multiple_structure($msgstructure, 'Pending admin messages, priority DESC'),
            'count'                    => new external_value(PARAM_INT, 'How many messages are pending for the user'),
            'highest_priority'         => new external_value(PARAM_INT, 'Priority of the highest-ranked pending message; null means none.'),
            'absence_priority'         => new external_value(PARAM_INT, 'Priority value used by the absence alert system'),
            'has_priority_over_absence' => new external_value(PARAM_BOOL, 'True when at least one pending broadcast beats the absence alerts'),
        ]);
    }
}
