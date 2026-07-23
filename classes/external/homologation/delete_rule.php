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
 * Deletes a saved homologation rule.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Solutto Consulting <dev@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\homologation;

use context_system;

defined('MOODLE_INTERNAL') || die();

/**
 * delete_rule external function.
 */
class delete_rule {

    public static function execute(int $id): array {
        global $DB;

        require_capability('moodle/site:config', context_system::instance());

        if ($id <= 0) {
            return ['status' => 'error', 'message' => 'Id de regla inválido.'];
        }
        $DB->delete_records('gmk_homologation_rules', ['id' => $id]);
        return ['status' => 'ok', 'message' => 'Regla eliminada.'];
    }
}
