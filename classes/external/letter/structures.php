<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Cartas (20261001130): estructuras compartidas de los servicios web.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\letter;

use external_multiple_structure;
use external_single_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class structures {

    /**
     * One request as the student sees it (get_my_requests).
     */
    public static function student_request(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Request id'),
            'userid' => new external_value(PARAM_INT, 'User id'),
            'lettertypeid' => new external_value(PARAM_INT, 'Letter type id'),
            'lettertypename' => new external_value(PARAM_TEXT, 'Letter type name'),
            'lettertypecode' => new external_value(PARAM_TEXT, 'Letter type code'),
            'status' => new external_value(PARAM_TEXT, 'Status'),
            'statuslabel' => new external_value(PARAM_TEXT, 'Status label'),
            'studentlabel' => new external_value(PARAM_TEXT, 'Status in plain words for the student'),
            'steps' => new external_multiple_structure(new external_single_structure([
                'label' => new external_value(PARAM_TEXT, 'Step'),
                'state' => new external_value(PARAM_ALPHA, 'done | current | todo | failed'),
            ])),
            'observation' => new external_value(PARAM_RAW, 'Observation'),
            'deliverymode' => new external_value(PARAM_ALPHA, 'digital | fisica'),
            'due_at' => new external_value(PARAM_INT, 'Estimated date of the current step (0 = none)'),
            'rejection_reason' => new external_value(PARAM_RAW, 'Rejection reason'),
            'cancel_reason' => new external_value(PARAM_RAW, 'Cancellation reason'),
            'pickup_note' => new external_value(PARAM_RAW, 'Where and when to pick it up'),
            'can_cancel' => new external_value(PARAM_INT, 'The student may cancel it'),
            'can_pay' => new external_value(PARAM_INT, 'Payment pending with link'),
            'cost_snapshot' => new external_value(PARAM_FLOAT, 'Cost'),
            'invoice_id' => new external_value(PARAM_TEXT, 'Invoice id'),
            'invoice_number' => new external_value(PARAM_TEXT, 'Invoice number'),
            'payment_link' => new external_value(PARAM_RAW, 'Payment link'),
            'document_available' => new external_value(PARAM_INT, 'Document available'),
            'document_filename' => new external_value(PARAM_TEXT, 'Document filename'),
            'document_mimetype' => new external_value(PARAM_TEXT, 'Document mimetype'),
            'document_verification_url' => new external_value(PARAM_RAW, 'Public verification URL'),
            'ticket' => self::ticket_summary(),
            'timecreated' => new external_value(PARAM_INT, 'Creation timestamp'),
            'timemodified' => new external_value(PARAM_INT, 'Modification timestamp'),
        ]);
    }

    public static function ticket_summary(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Ticket id (0 = none)'),
            'number' => new external_value(PARAM_TEXT, 'TKT-AAAA-NNNN'),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Ticket status'),
            'statuslabel' => new external_value(PARAM_TEXT, 'Ticket status label'),
            'isopen' => new external_value(PARAM_BOOL, 'Open'),
            'unread' => new external_value(PARAM_INT, 'Unread messages for the caller'),
        ]);
    }

    /**
     * One row of the staff desk.
     */
    public static function desk_row(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Request id'),
            'userid' => new external_value(PARAM_INT, 'Student id'),
            'studentname' => new external_value(PARAM_TEXT, 'Student'),
            'documentnumber' => new external_value(PARAM_TEXT, 'Student document number'),
            'lettertypeid' => new external_value(PARAM_INT, 'Letter type id'),
            'typename' => new external_value(PARAM_TEXT, 'Letter type'),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Status'),
            'statuslabel' => new external_value(PARAM_TEXT, 'Status label'),
            'deliverymode' => new external_value(PARAM_ALPHA, 'digital | fisica'),
            'cost' => new external_value(PARAM_FLOAT, 'Cost'),
            'observation' => new external_value(PARAM_RAW, 'Student observation'),
            'assignedto' => new external_value(PARAM_INT, 'Owner (0 = none)'),
            'assignedname' => new external_value(PARAM_TEXT, 'Owner'),
            'status_since' => new external_value(PARAM_INT, 'Entered the current status'),
            'waiting' => new external_value(PARAM_TEXT, 'Business time in the current status'),
            'due_at' => new external_value(PARAM_INT, 'Deadline of the current status (0 = none)'),
            'sla_level' => new external_value(PARAM_INT, '0 on time, 1 due soon, 2 overdue, 3 critical'),
            'legacy' => new external_value(PARAM_INT, 'Before the desk: ask whether it was already handled'),
            'document_available' => new external_value(PARAM_INT, 'Has a document'),
            'document_filename' => new external_value(PARAM_TEXT, 'Latest document'),
            'document_source' => new external_value(PARAM_ALPHA, 'generated | uploaded'),
            'ticketid' => new external_value(PARAM_INT, 'Latest ticket (0 = none)'),
            'ticketnumber' => new external_value(PARAM_TEXT, 'Ticket number'),
            'ticketstatus' => new external_value(PARAM_TEXT, 'Ticket status label'),
            'ticketopen' => new external_value(PARAM_BOOL, 'Ticket open'),
            'ticketunread' => new external_value(PARAM_INT, 'Unread ticket messages'),
            'actions' => new external_multiple_structure(new external_value(PARAM_ALPHANUMEXT, 'Action')),
            'timecreated' => new external_value(PARAM_INT, 'Created'),
            'timemodified' => new external_value(PARAM_INT, 'Modified'),
        ]);
    }

    public static function desk_detail(): external_single_structure {
        $keys = self::desk_row()->keys;
        $keys += [
            'email' => new external_value(PARAM_RAW, 'Student email'),
            'careers' => new external_value(PARAM_TEXT, 'Careers, one per line'),
            'financial' => new external_value(PARAM_TEXT, 'Financial status'),
            'rejection_reason' => new external_value(PARAM_RAW, 'Rejection reason'),
            'cancel_reason' => new external_value(PARAM_RAW, 'Cancellation reason'),
            'pickup_note' => new external_value(PARAM_RAW, 'Pickup note of the type'),
            'invoice_number' => new external_value(PARAM_TEXT, 'Invoice'),
            'history' => new external_multiple_structure(new external_single_structure([
                'type' => new external_value(PARAM_ALPHANUMEXT, 'Event type'),
                'text' => new external_value(PARAM_RAW, 'Text'),
                'actor' => new external_value(PARAM_TEXT, 'Who'),
                'time' => new external_value(PARAM_INT, 'When'),
            ])),
            'documents' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Document id'),
                'version' => new external_value(PARAM_INT, 'Version'),
                'filename' => new external_value(PARAM_TEXT, 'File'),
                'source' => new external_value(PARAM_ALPHA, 'generated | uploaded'),
                'size' => new external_value(PARAM_INT, 'Bytes'),
                'time' => new external_value(PARAM_INT, 'When'),
                'odoo' => new external_value(PARAM_BOOL, 'Copied to Odoo'),
            ])),
            'reasons' => new external_multiple_structure(new external_value(PARAM_TEXT, 'Frequent reason')),
        ];
        return new external_single_structure($keys);
    }
}
