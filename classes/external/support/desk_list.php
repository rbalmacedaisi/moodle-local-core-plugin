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
 * local_grupomakro_support_desk_list: Bandeja del personal por pestana, con contadores.
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

class desk_list extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'tab' => new external_value(PARAM_ALPHA, 'unassigned | mine | area | overdue | all | closed', VALUE_DEFAULT, 'mine'),
            'search' => new external_value(PARAM_TEXT, 'Number, subject or student', VALUE_DEFAULT, ''),
            'page' => new external_value(PARAM_INT, 'Page', VALUE_DEFAULT, 0),
            'perpage' => new external_value(PARAM_INT, 'Per page', VALUE_DEFAULT, 50),
            'areaid' => new external_value(PARAM_INT, 'Quick filter: area (0 = all)', VALUE_DEFAULT, 0),
            'level' => new external_value(PARAM_ALPHA, 'Quick filter: red | orange | yellow | green (open tabs)', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute($tab, $search, $page, $perpage, $areaid = 0, $level = ''): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['tab' => $tab, 'search' => $search, 'page' => $page,
            'perpage' => $perpage, 'areaid' => $areaid, 'level' => $level]);
        self::validate_context(context_system::instance());
        $userid = (int)$USER->id;
        $perpage = max(1, min(200, (int)$params['perpage']));
        $data = manager::desk_list($userid, (string)$params['tab'], (string)$params['search'], (int)$params['page'], $perpage,
            (int)$params['areaid'], (string)$params['level']);
        $p = manager::profile($userid);
        $counts = [];
        foreach ($data['counts'] as $tab => $n) {
            $counts[] = ['tab' => $tab, 'count' => $n];
        }
        $levels = [];
        foreach ($data['levels'] as $level => $n) {
            $levels[] = ['level' => $level, 'count' => $n];
        }
        return [
            'areas' => $data['areas'],
            'levels' => $levels,
            'searchmin' => manager::SEARCH_MIN,
            'tickets' => $data['tickets'],
            'total' => $data['total'],
            'tab' => $data['tab'],
            'counts' => $counts,
            'me' => [
                'available' => manager::is_available($userid),
                'supervisor' => (bool)$p->supervisor,
                'viewconfidential' => (bool)$p->viewconf,
                'manage' => (bool)$p->manage,
                'isagent' => !empty($p->agentcats),
                'reassign' => (bool)$p->reassign,
            ],
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'tickets' => structures::tickets(),
            'total' => new external_value(PARAM_INT, 'Total in the tab'),
            'tab' => new external_value(PARAM_ALPHA, 'Effective tab (search = searching all visible tickets)'),
            'searchmin' => new external_value(PARAM_INT, 'Minimum characters for the search'),
            'areas' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Area id'),
                'name' => new external_value(PARAM_TEXT, 'Area'),
                'count' => new external_value(PARAM_INT, 'Tickets of the view in this area'),
            ])),
            'levels' => new external_multiple_structure(new external_single_structure([
                'level' => new external_value(PARAM_ALPHA, 'red | orange | yellow | green'),
                'count' => new external_value(PARAM_INT, 'Tickets of the view (and area) at this level'),
            ])),
            'counts' => new external_multiple_structure(new external_single_structure([
                'tab' => new external_value(PARAM_ALPHA, 'Tab'),
                'count' => new external_value(PARAM_INT, 'Tickets'),
            ])),
            'me' => new external_single_structure([
                'available' => new external_value(PARAM_BOOL, 'Available for auto-assignment'),
                'supervisor' => new external_value(PARAM_BOOL, 'Supervisor'),
                'viewconfidential' => new external_value(PARAM_BOOL, 'Sees confidential tickets'),
                'manage' => new external_value(PARAM_BOOL, 'May configure'),
                'isagent' => new external_value(PARAM_BOOL, 'Listed as agent of a topic'),
                'reassign' => new external_value(PARAM_BOOL, 'May reassign any non-confidential ticket (Todos tab)'),
            ]),
        ]);
    }
}
