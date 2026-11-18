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
 * local_grupomakro_support_faq_feedback: problemas comunes (respuesta guiada).
 * Cuenta que alguien vio una respuesta, que se le soluciono o que sigue con el
 * problema (metricas del tablero de la fase 3). Identidad = $USER.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\support;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_grupomakro_core\local\support\faq;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class faq_feedback extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'faqid' => new external_value(PARAM_INT, 'Common problem id'),
            'outcome' => new external_value(PARAM_ALPHA, 'view | solved | notsolved'),
            'audience' => new external_value(PARAM_ALPHA, 'student | teacher', VALUE_DEFAULT, 'student'),
        ]);
    }

    public static function execute($faqid, $outcome, $audience = 'student'): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(),
            ['faqid' => $faqid, 'outcome' => $outcome, 'audience' => $audience]);
        self::validate_context(context_system::instance());
        require_capability('local/grupomakro_core:support_create', context_system::instance());
        faq::record((int)$params['faqid'], (string)$params['outcome'], (int)$USER->id,
            $params['audience'] === 'teacher' ? 'teacher' : 'student');
        return ['ok' => true];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure(['ok' => new external_value(PARAM_BOOL, 'Recorded')]);
    }
}
