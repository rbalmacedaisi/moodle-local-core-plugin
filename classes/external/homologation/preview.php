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
 * Previews the pending homologations for a set of rules (or all saved active
 * rules when none are passed).
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
 * preview external function.
 */
class preview {

    /**
     * @param array $rules When empty, all saved active rules are used.
     * @return array {status, rows, summary}
     */
    public static function execute(array $rules = []): array {
        \core_php_time_limit::raise();
        raise_memory_limit(MEMORY_EXTRA);

        require_capability('moodle/site:config', context_system::instance());

        if (empty($rules)) {
            $rules = homologation_engine::load_active_rules();
        }
        $result = homologation_engine::compute_pending($rules);

        return [
            'status'  => 'ok',
            'rows'    => $result['rows'],
            'summary' => $result['summary'],
        ];
    }
}
