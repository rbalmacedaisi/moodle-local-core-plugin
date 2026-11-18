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
 * local_grupomakro_support_search_users: Busca usuarios activos por nombre, correo o cedula (configuracion).
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

class search_users extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query' => new external_value(PARAM_TEXT, 'Text'),
        ]);
    }

    public static function execute($query): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['query' => $query]);
        self::validate_context(context_system::instance());
        $userid = (int)$USER->id;
        global $DB;
        require_capability('local/grupomakro_core:support_manage', context_system::instance());
        $q = trim((string)$params['query']);
        if (\core_text::strlen($q) < 2) {
            return ['users' => []];
        }
        $like = [];
        $sqlparams = [];
        foreach (['firstname', 'lastname', 'email', 'idnumber', 'username'] as $i => $f) {
            $like[] = $DB->sql_like($f, ':q' . $i, false, false);
            $sqlparams['q' . $i] = '%' . $DB->sql_like_escape($q) . '%';
        }
        $full = $DB->sql_like($DB->sql_fullname(), ':qf', false, false);
        $sqlparams['qf'] = '%' . $DB->sql_like_escape($q) . '%';
        $rows = $DB->get_records_select('user', 'deleted = 0 AND suspended = 0 AND (' . implode(' OR ', $like) . " OR $full)",
            $sqlparams, 'lastname, firstname',
            'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename, email', 0, 20);
        $out = [];
        foreach ($rows as $u) {
            $out[] = ['id' => (int)$u->id, 'fullname' => fullname($u), 'email' => (string)$u->email];
        }
        return ['users' => $out];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'users' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'User id'),
                'fullname' => new external_value(PARAM_TEXT, 'Name'),
                'email' => new external_value(PARAM_TEXT, 'Email'),
            ])),
        ]);
    }
}
