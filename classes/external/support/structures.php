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
 * Mesa de ayuda: estructuras de retorno compartidas por los WS support_*.
 * Deben coincidir con local\support\manager::export_*().
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\support;

use external_multiple_structure;
use external_single_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class structures {

    public static function ticket(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Ticket id'),
            'number' => new external_value(PARAM_TEXT, 'TKT-AAAA-NNNN'),
            'subject' => new external_value(PARAM_TEXT, 'Subject'),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Status code'),
            'statuslabel' => new external_value(PARAM_TEXT, 'Status label for the caller'),
            'isopen' => new external_value(PARAM_BOOL, 'Open'),
            'areaid' => new external_value(PARAM_INT, 'Area id'),
            'areaname' => new external_value(PARAM_TEXT, 'Area'),
            'categoryid' => new external_value(PARAM_INT, 'Topic id'),
            'categoryname' => new external_value(PARAM_TEXT, 'Topic'),
            'priority' => new external_value(PARAM_INT, '0 low, 1 normal, 2 high'),
            'confidential' => new external_value(PARAM_BOOL, 'Confidential'),
            'userid' => new external_value(PARAM_INT, 'Requester id'),
            'requestername' => new external_value(PARAM_TEXT, 'Requester'),
            'requesteridnumber' => new external_value(PARAM_TEXT, 'Requester id number (staff only)'),
            'requesteremail' => new external_value(PARAM_TEXT, 'Requester email (staff only)'),
            'assignedto' => new external_value(PARAM_INT, 'Assigned agent id'),
            'assignedname' => new external_value(PARAM_TEXT, 'Assigned agent'),
            'myrole' => new external_value(PARAM_ALPHA, 'student | agent | supervisor'),
            'canwrite' => new external_value(PARAM_BOOL, 'Caller may post'),
            'actions' => new external_multiple_structure(new external_value(PARAM_ALPHANUMEXT, 'Action')),
            'waitingminutes' => new external_value(PARAM_INT, 'Business minutes waiting for staff (phase 2)'),
            'waitingclock' => new external_value(PARAM_INT, 'Wall-clock minutes waiting for staff'),
            'semaphore' => new external_value(PARAM_ALPHANUMEXT, 'none | green | green_warn | yellow | orange | red'),
            'escalationlevel' => new external_value(PARAM_INT, 'Last escalation level processed (0-4)'),
            'requesterrole' => new external_value(PARAM_ALPHA, 'student | teacher'),
            'rating' => new external_value(PARAM_INT, 'Rating 1-5 (0 = none)'),
            'unread' => new external_value(PARAM_INT, 'Unread messages for the caller'),
            'lastmessage' => new external_value(PARAM_RAW, 'Excerpt of the last visible message'),
            'lastmessageat' => new external_value(PARAM_INT, 'Last message time'),
            'first_response_at' => new external_value(PARAM_INT, 'First staff reply'),
            'resolved_at' => new external_value(PARAM_INT, 'Resolved time'),
            'closed_at' => new external_value(PARAM_INT, 'Closed time'),
            'reopened_count' => new external_value(PARAM_INT, 'Times reopened'),
            'timecreated' => new external_value(PARAM_INT, 'Created'),
            'timemodified' => new external_value(PARAM_INT, 'Modified'),
        ]);
    }

    public static function message(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Message id'),
            'ticketid' => new external_value(PARAM_INT, 'Ticket id'),
            'senderid' => new external_value(PARAM_INT, 'Sender id (0 = system)'),
            'sendername' => new external_value(PARAM_TEXT, 'Sender'),
            'senderrole' => new external_value(PARAM_ALPHA, 'student | agent | supervisor | system'),
            'body' => new external_value(PARAM_RAW, 'Plain text body'),
            'internal' => new external_value(PARAM_BOOL, 'Internal note'),
            'timecreated' => new external_value(PARAM_INT, 'Sent'),
            'mine' => new external_value(PARAM_BOOL, 'Sent by the caller'),
        ]);
    }

    public static function event(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Event id'),
            'eventtype' => new external_value(PARAM_ALPHANUMEXT, 'Type'),
            'description' => new external_value(PARAM_TEXT, 'Readable description'),
            'actorname' => new external_value(PARAM_TEXT, 'Who'),
            'timecreated' => new external_value(PARAM_INT, 'When'),
        ]);
    }

    public static function tickets(): external_multiple_structure {
        return new external_multiple_structure(self::ticket());
    }
}
