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
 * local_grupomakro_support_get_assignable: Personas a las que se puede reasignar el ticket y temas a los que se puede mover.
 *
 * Mesa de ayuda (20261001120). Identidad = $USER siempre; el acceso lo decide
 * local\support\manager.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\support;

use context_system;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_grupomakro_core\local\support\manager;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_assignable extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'ticketid' => new external_value(PARAM_INT, 'Ticket id'),
            'categoryid' => new external_value(PARAM_INT, 'Destination topic when moving it (0 = current)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute($ticketid, $categoryid = 0): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['ticketid' => $ticketid,
            'categoryid' => $categoryid]);
        self::validate_context(context_system::instance());
        $userid = (int)$USER->id;
        global $DB;
        [$ticket, $role] = manager::require_ticket((int)$params['ticketid'], $userid);
        if ($role === manager::ROLE_REQUESTER) {
            throw new \moodle_exception('tkt_err_noaccess', 'local_grupomakro_core');
        }
        $target = $ticket;
        if ((int)$params['categoryid'] > 0 && (int)$params['categoryid'] !== (int)$ticket->categoryid) {
            // 20261001122: agents of another topic/area, for "Reasignar" with a move.
            if (!manager::may_reassign($ticket, $userid)) {
                throw new \moodle_exception('tkt_err_cannotassign', 'local_grupomakro_core');
            }
            $transfer = manager::transfer_target($ticket, (int)$params['categoryid']);
            if (!$transfer) {
                throw new \moodle_exception('tkt_err_badcategory', 'local_grupomakro_core');
            }
            $target = $transfer->ticket;
        }
        $users = [];
        foreach (manager::assignable_staff($target) as $u) {
            $users[] = ['id' => (int)$u->id, 'fullname' => fullname($u), 'openload' => (int)$u->openload,
                'available' => (bool)$u->available];
        }
        $categories = [];
        $rows = $DB->get_records_sql("SELECT c.id, c.name, a.name AS areaname, c.confidential
                                        FROM {gmk_tkt_category} c
                                        JOIN {gmk_tkt_area} a ON a.id = c.areaid
                                       WHERE c.active = 1 AND a.active = 1 AND a.external_url = ''
                                    ORDER BY a.sortorder, c.sortorder, c.id");
        foreach ($rows as $c) {
            $categories[] = ['id' => (int)$c->id, 'name' => format_string($c->name),
                'areaname' => format_string($c->areaname), 'confidential' => (bool)$c->confidential];
        }
        return ['users' => $users, 'categories' => $categories];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'users' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'User id'),
                'fullname' => new external_value(PARAM_TEXT, 'Name'),
                'openload' => new external_value(PARAM_INT, 'Open tickets assigned'),
                'available' => new external_value(PARAM_BOOL, 'Available'),
            ])),
            'categories' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Topic id'),
                'name' => new external_value(PARAM_TEXT, 'Topic'),
                'areaname' => new external_value(PARAM_TEXT, 'Area'),
                'confidential' => new external_value(PARAM_BOOL, 'Confidential'),
            ])),
        ]);
    }
}
