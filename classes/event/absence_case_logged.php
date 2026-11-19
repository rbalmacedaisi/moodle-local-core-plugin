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
 * Seguimiento de inasistencias: se registro una gestion en un caso
 * (gmk_abs_case_log). Deja rastro en el log estandar de Moodle ademas de la
 * tabla propia, que es de solo agregar.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\event;

defined('MOODLE_INTERNAL') || die();

class absence_case_logged extends \core\event\base {

    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'gmk_abs_case_log';
    }

    public static function get_name() {
        return get_string('event_absence_case_logged', 'local_grupomakro_core');
    }

    public function get_description() {
        $o = $this->other;
        return "El usuario {$this->userid} registró la gestión {$this->objectid} (resultado '" . ($o['result'] ?? '')
            . "', estado '" . ($o['status'] ?? '') . "') en el caso de inasistencia " . (int)($o['caseid'] ?? 0)
            . " del estudiante {$this->relateduserid}.";
    }

    public function get_url() {
        return new \moodle_url('/local/grupomakro_core/pages/absence_cases.php', ['case' => (int)($this->other['caseid'] ?? 0)]);
    }

    public static function get_objectid_mapping() {
        return \core\event\base::NOT_MAPPED;
    }

    public static function get_other_mapping() {
        return false;
    }
}
