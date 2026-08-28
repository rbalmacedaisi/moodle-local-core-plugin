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
 * Register a student to an event (RF-02.2, RF-09.2).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\wellness;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wellness_registration_manager.php');

class register_event extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'eventid'  => new external_value(PARAM_INT,  'Event id', VALUE_REQUIRED),
            'modality' => new external_value(PARAM_ALPHA, 'presencial|virtual (only when event modality=mixto)', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute($eventid, $modality = '') {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'eventid' => $eventid, 'modality' => $modality,
        ]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:view_wellness', $context);

        $r = \local_grupomakro_core\local\wellness_registration_manager::register(
            (int)$params['eventid'],
            (int)$USER->id,
            (string)$params['modality'],
            'lxp',
            0
        );
        return $r + ['eventid' => (int)$params['eventid'], 'userid' => (int)$USER->id];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'ok'            => new external_value(PARAM_BOOL, 'True when registration succeeded'),
            'status'        => new external_value(PARAM_TEXT, 'confirmada | lista_de_espera | (empty on error)'),
            'registrationid'=> new external_value(PARAM_INT,  'Id of the registration row'),
            'error'         => new external_value(PARAM_TEXT, 'Error code if ok=false'),
            'eventid'       => new external_value(PARAM_INT,  'Echoed event id'),
            'userid'        => new external_value(PARAM_INT,  'Echoed user id'),
        ]);
    }
}
