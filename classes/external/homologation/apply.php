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
 * Applies the pending homologations for a set of rules (or all saved active
 * rules when none are passed), reusing homologate_course_grade per row.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Solutto Consulting <dev@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\homologation;

use context_system;
use local_grupomakro_core\local\homologation_engine;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->dirroot . '/local/grupomakro_core/classes/local/homologation_engine.php');

/**
 * apply external function.
 */
class apply {

    /**
     * @param array $rules When empty, all saved active rules are used.
     * @return array {status, applied, errors, results, summary}
     */
    public static function execute(array $rules = []): array {
        require_capability('moodle/site:config', context_system::instance());

        if (empty($rules)) {
            $rules = homologation_engine::load_active_rules();
        }
        $result = homologation_engine::apply($rules);

        return [
            'status'  => 'ok',
            'applied' => $result['applied'],
            'errors'  => $result['errors'],
            'results' => $result['results'],
            'summary' => $result['summary'],
        ];
    }
}
