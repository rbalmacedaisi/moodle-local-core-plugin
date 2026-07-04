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
 * External (admin): create a new broadcast message. Requires the
 * local/grupomakro_core:manageannouncements capability.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\admin\announcement;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/announcement_manager.php');

class create_message extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'title'             => new external_value(PARAM_TEXT, 'Headline of the broadcast',                 VALUE_REQUIRED),
            'messagetext'       => new external_value(PARAM_RAW,  'Body (HTML allowed, plain text recommended)', VALUE_REQUIRED),
            'messagetype'       => new external_value(PARAM_ALPHA, 'info|warning',                              VALUE_DEFAULT, 'info'),
            'audience_scope'    => new external_value(PARAM_ALPHA, 'all|career|group',                          VALUE_DEFAULT, 'all'),
            'audience_careerid' => new external_value(PARAM_INT,   'Learning plan id (when scope=career)',      VALUE_DEFAULT, 0),
            'audience_groupid'  => new external_value(PARAM_INT,   'Moodle group id (when scope=group)',        VALUE_DEFAULT, 0),
            'require_ack'       => new external_value(PARAM_BOOL,  'True when a student must accept',            VALUE_DEFAULT, true),
            'ack_label'         => new external_value(PARAM_TEXT, 'Custom label for the ack checkbox',         VALUE_DEFAULT, ''),
            'priority'          => new external_value(PARAM_INT,   'Higher value wins',                         VALUE_DEFAULT, 50),
            'starts_at'         => new external_value(PARAM_INT,   'Optional unix ts',                          VALUE_DEFAULT, 0),
            'ends_at'           => new external_value(PARAM_INT,   'Optional unix ts',                          VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(
        $title,
        $messagetext,
        $messagetype = 'info',
        $audience_scope = 'all',
        $audience_careerid = 0,
        $audience_groupid = 0,
        $require_ack = true,
        $ack_label = '',
        $priority = 50,
        $starts_at = 0,
        $ends_at = 0
    ) {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'title'             => $title,
            'messagetext'       => $messagetext,
            'messagetype'       => $messagetype,
            'audience_scope'    => $audience_scope,
            'audience_careerid' => $audience_careerid,
            'audience_groupid'  => $audience_groupid,
            'require_ack'       => $require_ack,
            'ack_label'         => $ack_label,
            'priority'          => $priority,
            'starts_at'         => $starts_at,
            'ends_at'           => $ends_at,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manageannouncements', $context);

        $result = \local_grupomakro_core\local\announcement_manager::create(
            $params,
            (int)$USER->id
        );

        if (!$result['ok']) {
            throw new Exception($result['error'] ?? 'create_failed');
        }

        $msg = $result['message'];
        return [
            'ok'                 => true,
            'id'                 => (int)$msg->id,
            'title'              => (string)$msg->title,
            'messagetype'        => (string)$msg->messagetype,
            'audience_scope'     => (string)$msg->audience_scope,
            'audience_careerid'  => (int)$msg->audience_careerid,
            'audience_groupid'   => (int)$msg->audience_groupid,
            'require_ack'        => (bool)$msg->require_ack,
            'ack_label'          => (string)$msg->ack_label,
            'priority'           => (int)$msg->priority,
            'starts_at'          => (int)$msg->starts_at,
            'ends_at'            => (int)$msg->ends_at,
            'recipients'         => (int)$result['count'],
            'timecreated'        => (int)$msg->timecreated,
        ];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'ok'                 => new external_value(PARAM_BOOL, 'True when the broadcast was created'),
            'id'                 => new external_value(PARAM_INT,  'New message id'),
            'title'              => new external_value(PARAM_TEXT, 'Title echoed back'),
            'messagetype'        => new external_value(PARAM_ALPHA, 'info|warning'),
            'audience_scope'     => new external_value(PARAM_ALPHA, 'all|career|group'),
            'audience_careerid'  => new external_value(PARAM_INT,   'Career id'),
            'audience_groupid'   => new external_value(PARAM_INT,   'Group id'),
            'require_ack'        => new external_value(PARAM_BOOL, 'Requires the ack checkbox'),
            'ack_label'          => new external_value(PARAM_TEXT, 'Label for the ack checkbox'),
            'priority'           => new external_value(PARAM_INT,  'Numeric priority'),
            'starts_at'          => new external_value(PARAM_INT,  'Unix ts'),
            'ends_at'            => new external_value(PARAM_INT,  'Unix ts'),
            'recipients'         => new external_value(PARAM_INT,  'Resolved audience size'),
            'timecreated'        => new external_value(PARAM_INT,  'Unix ts'),
        ]);
    }
}
