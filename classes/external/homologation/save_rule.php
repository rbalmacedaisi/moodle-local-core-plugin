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
 * Saves (upserts) a homologation rule.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Solutto Consulting <dev@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\homologation;

use context_system;
use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * save_rule external function.
 */
class save_rule {

    /** @var string[] */
    const ALLOWED_TYPES = ['suficiencia', 'migracion', 'homologacion', 'practica'];

    public static function execute(
        int $originPlanId,
        int $originCourseId,
        int $destPlanId,
        int $destCourseId,
        string $type = 'homologacion'
    ): array {
        global $DB, $USER;

        require_capability('moodle/site:config', context_system::instance());

        if ($originPlanId <= 0 || $originCourseId <= 0 || $destPlanId <= 0 || $destCourseId <= 0) {
            return ['status' => 'error', 'message' => 'Selección incompleta: falta plan o asignatura.'];
        }
        if ($originPlanId === $destPlanId && $originCourseId === $destCourseId) {
            return ['status' => 'error', 'message' => 'El origen y el destino no pueden ser la misma asignatura del mismo plan.'];
        }
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            $type = 'homologacion';
        }

        // Validate that both courses belong to their declared plan.
        $originOk = $DB->record_exists('local_learning_courses', ['learningplanid' => $originPlanId, 'courseid' => $originCourseId]);
        $destOk   = $DB->record_exists('local_learning_courses', ['learningplanid' => $destPlanId, 'courseid' => $destCourseId]);
        if (!$originOk) {
            return ['status' => 'error', 'message' => 'La asignatura de origen no pertenece al plan de origen.'];
        }
        if (!$destOk) {
            return ['status' => 'error', 'message' => 'La asignatura de destino no pertenece al plan de destino.'];
        }

        $now = time();
        $existing = $DB->get_record('gmk_homologation_rules', [
            'origin_planid'   => $originPlanId,
            'origin_courseid' => $originCourseId,
            'dest_planid'     => $destPlanId,
            'dest_courseid'   => $destCourseId,
        ]);

        if ($existing) {
            $existing->homologation_type = $type;
            $existing->active = 1;
            $existing->usermodified = (int)$USER->id;
            $existing->timemodified = $now;
            $DB->update_record('gmk_homologation_rules', $existing);
            $id = (int)$existing->id;
        } else {
            $rec = new stdClass();
            $rec->origin_planid = $originPlanId;
            $rec->origin_courseid = $originCourseId;
            $rec->dest_planid = $destPlanId;
            $rec->dest_courseid = $destCourseId;
            $rec->homologation_type = $type;
            $rec->active = 1;
            $rec->usermodified = (int)$USER->id;
            $rec->timecreated = $now;
            $rec->timemodified = $now;
            $id = (int)$DB->insert_record('gmk_homologation_rules', $rec);
        }

        return ['status' => 'ok', 'id' => $id, 'message' => 'Regla guardada.'];
    }
}
