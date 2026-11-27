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
 * Dashboard administrativo: fuentes registradas. Agregar una bandeja nueva =
 * una clase en sources/ y una linea aqui.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\dashboard;

defined('MOODLE_INTERNAL') || die();

class registry {

    const SOURCES = [
        sources\tickets::class,
        sources\letters::class,
        sources\withdrawals::class,
        sources\withdrawal_escalations::class,
        sources\withdrawals_formal::class,
        sources\planning::class,
        sources\class_approval::class,
        sources\class_closing::class,
        sources\revalidas::class,
        sources\absence_blocks::class,
        sources\wellness_events::class,
        sources\psychology::class,
        sources\modules_paid::class,
        sources\modules_overdue::class,
        sources\webhook_dlq::class,
    ];

    /**
     * @param bool $all include the ones switched off in the settings
     * @return source[] key => source
     */
    public static function sources(bool $all = false): array {
        $out = [];
        foreach (self::SOURCES as $class) {
            $s = new $class();
            if ($all || config::source_enabled($s->key())) {
                $out[$s->key()] = $s;
            }
        }
        return $out;
    }
}
