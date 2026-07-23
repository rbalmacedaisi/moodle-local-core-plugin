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
 * Lists the saved homologation rules with resolved plan/course names.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Solutto Consulting <dev@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\homologation;

use context_system;

defined('MOODLE_INTERNAL') || die();

/**
 * list_rules external function.
 */
class list_rules {

    public static function execute(): array {
        global $DB;

        require_capability('moodle/site:config', context_system::instance());

        $rows = $DB->get_records('gmk_homologation_rules', null, 'id ASC');
        if (empty($rows)) {
            return ['status' => 'ok', 'rules' => []];
        }

        $planids = [];
        $courseids = [];
        foreach ($rows as $r) {
            $planids[(int)$r->origin_planid] = true;
            $planids[(int)$r->dest_planid] = true;
            $courseids[(int)$r->origin_courseid] = true;
            $courseids[(int)$r->dest_courseid] = true;
        }

        $planNames = [];
        if ($planids) {
            list($pin, $pp) = $DB->get_in_or_equal(array_keys($planids), SQL_PARAMS_NAMED, 'p');
            foreach ($DB->get_records_select('local_learning_plans', "id $pin", $pp, '', 'id, name') as $p) {
                $planNames[(int)$p->id] = (string)$p->name;
            }
        }
        $courseNames = [];
        if ($courseids) {
            list($cin, $cp) = $DB->get_in_or_equal(array_keys($courseids), SQL_PARAMS_NAMED, 'c');
            foreach ($DB->get_records_select('course', "id $cin", $cp, '', 'id, fullname') as $c) {
                $courseNames[(int)$c->id] = (string)$c->fullname;
            }
        }

        $rules = [];
        foreach ($rows as $r) {
            $rules[] = [
                'id'                => (int)$r->id,
                'origin_planid'     => (int)$r->origin_planid,
                'origin_planname'   => $planNames[(int)$r->origin_planid] ?? ('Plan #' . $r->origin_planid),
                'origin_courseid'   => (int)$r->origin_courseid,
                'origin_coursename' => $courseNames[(int)$r->origin_courseid] ?? ('#' . $r->origin_courseid),
                'dest_planid'       => (int)$r->dest_planid,
                'dest_planname'     => $planNames[(int)$r->dest_planid] ?? ('Plan #' . $r->dest_planid),
                'dest_courseid'     => (int)$r->dest_courseid,
                'dest_coursename'   => $courseNames[(int)$r->dest_courseid] ?? ('#' . $r->dest_courseid),
                'homologation_type' => (string)$r->homologation_type,
                'active'            => (int)$r->active,
            ];
        }

        return ['status' => 'ok', 'rules' => $rules];
    }
}
