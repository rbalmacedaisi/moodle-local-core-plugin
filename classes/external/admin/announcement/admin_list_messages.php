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
 * External (admin): list every existing broadcast message with audience and
 * acknowledgement summary, toggle their active flag, and pull the dropdown
 * catalogues the admin form needs (careers, groups).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\admin\announcement;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/announcement_manager.php');

class admin_list_messages extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([]);
    }

    public static function execute() {
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:viewannouncements', $context);

        $messages  = \local_grupomakro_core\local\announcement_manager::list_messages();
        $careers   = \local_grupomakro_core\local\announcement_manager::list_careers();
        $groups    = \local_grupomakro_core\local\announcement_manager::list_groups();
        return [
            'messages' => $messages,
            'careers'  => $careers,
            'groups'   => $groups,
        ];
    }

    public static function execute_returns() {
        $msgstructure = new external_single_structure([
            'id'                  => new external_value(PARAM_INT,    'Message id'),
            'title'               => new external_value(PARAM_TEXT,   'Title'),
            'messagetext'         => new external_value(PARAM_RAW,    'HTML message body'),
            'messagetype'         => new external_value(PARAM_ALPHA,  'info|warning'),
            'audience_scope'      => new external_value(PARAM_ALPHA,  'all|career|group'),
            'audience_careerid'   => new external_value(PARAM_INT,    'Career id'),
            'audience_groupid'    => new external_value(PARAM_INT,    'Group id'),
            'priority'            => new external_value(PARAM_INT,    'Numeric priority'),
            'require_ack'         => new external_value(PARAM_BOOL,   'Requires ack checkbox'),
            'ack_label'           => new external_value(PARAM_TEXT,   'Label shown next to the ack checkbox'),
            'starts_at'           => new external_value(PARAM_INT,    'Unix ts'),
            'ends_at'             => new external_value(PARAM_INT,    'Unix ts'),
            'timecreated'         => new external_value(PARAM_INT,    'Unix ts'),
            'timemodified'        => new external_value(PARAM_INT,    'Unix ts'),
            'authorid'            => new external_value(PARAM_INT,    'Author id'),
            'authorname'          => new external_value(PARAM_TEXT,   'Author full name'),
            'active'              => new external_value(PARAM_BOOL,   'Broadcast is active'),
            'recipients'          => new external_value(PARAM_INT,    'Total audience size'),
            'acked'               => new external_value(PARAM_INT,    'Number of recipients that acknowledged'),
        ]);

        $carstructure = new external_single_structure([
            'id'   => new external_value(PARAM_INT,  'local_learning_plans.id'),
            'name' => new external_value(PARAM_TEXT, 'Career name'),
        ]);

        $grpstructure = new external_single_structure([
            'id'         => new external_value(PARAM_INT,  'groups.id'),
            'name'       => new external_value(PARAM_TEXT, 'Group name'),
            'courseid'   => new external_value(PARAM_INT,  'Course id'),
            'coursename' => new external_value(PARAM_TEXT, 'Course fullname'),
        ]);

        return new external_single_structure([
            'messages' => new external_multiple_structure($msgstructure, 'Existing broadcasts'),
            'careers'  => new external_multiple_structure($carstructure, 'Career catalogue'),
            'groups'   => new external_multiple_structure($grpstructure, 'Group catalogue'),
        ]);
    }
}
