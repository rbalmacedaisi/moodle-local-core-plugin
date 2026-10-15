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
 * Shared return structures for the local_grupomakro_msg_* web services.
 * Shapes match local\messaging\manager::export_*().
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\messaging;

use external_multiple_structure;
use external_single_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class structures {

    public static function user(): external_single_structure {
        return new external_single_structure([
            'id'       => new external_value(PARAM_INT, 'User id'),
            'fullname' => new external_value(PARAM_TEXT, 'Full name'),
            'imageurl' => new external_value(PARAM_RAW, 'Profile image URL (may be empty)'),
            'role'     => new external_value(PARAM_ALPHA, 'student | teacher | support'),
            'threadid' => new external_value(PARAM_INT, 'Existing thread with this user in the class, 0 if none'),
            'unread'   => new external_value(PARAM_INT, 'Unread messages in that thread'),
        ]);
    }

    public static function thread(): external_single_structure {
        return new external_single_structure([
            'id'            => new external_value(PARAM_INT, 'Thread id'),
            'classid'       => new external_value(PARAM_INT, 'gmk_class id'),
            'classname'     => new external_value(PARAM_TEXT, 'Class name'),
            'courseid'      => new external_value(PARAM_INT, 'Core course id'),
            'coursename'    => new external_value(PARAM_TEXT, 'Core course name'),
            'studentid'     => new external_value(PARAM_INT, 'Student of the thread'),
            'myrole'        => new external_value(PARAM_ALPHA, 'Role of the caller: student | teacher | support'),
            'readonly'      => new external_value(PARAM_BOOL, 'True when nobody can write (class closed)'),
            'lastmessageid' => new external_value(PARAM_INT, 'Last message id, 0 if none'),
            'lastmessageat' => new external_value(PARAM_INT, 'Unix ts of the last message'),
            'lastmessage'   => new external_value(PARAM_RAW, 'Plain-text excerpt of the last message'),
            'lastsenderid'  => new external_value(PARAM_INT, 'Sender of the last message'),
            'unread'        => new external_value(PARAM_INT, 'Unread messages for the caller'),
            'counterpart'   => new external_multiple_structure(self::user(), 'The other side: teachers for a student, the student for a teacher'),
        ]);
    }

    public static function message(): external_single_structure {
        return new external_single_structure([
            'id'          => new external_value(PARAM_INT, 'Message id'),
            'threadid'    => new external_value(PARAM_INT, 'Thread id'),
            'senderid'    => new external_value(PARAM_INT, 'Sender user id'),
            'sendername'  => new external_value(PARAM_TEXT, 'Sender full name'),
            'senderrole'  => new external_value(PARAM_ALPHA, 'student | teacher | support'),
            'body'        => new external_value(PARAM_RAW, 'Plain text body (render escaped, keep newlines)'),
            'timecreated' => new external_value(PARAM_INT, 'Unix ts'),
            'mine'        => new external_value(PARAM_BOOL, 'True when the caller sent it'),
        ]);
    }
}
