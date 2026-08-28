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
 * Admin: list every wellness partner (active and inactive) + categories.
 * Used by the back-office Vue table. RF-09.1.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\admin\wellness;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wellness_partner_manager.php');

class admin_list_partners extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([]);
    }

    public static function execute() {
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manage_wellness', $context);

        $rows = \local_grupomakro_core\local\wellness_partner_manager::list_for_admin();
        $cats = \local_grupomakro_core\local\wellness_partner_manager::list_categories();

        return [
            'partners'    => array_values(array_map(function ($r) {
                return [
                    'id'                  => (int)$r->id,
                    'name'                => (string)$r->name,
                    'categoryid'          => (int)$r->categoryid,
                    'category_name'       => (string)$r->category_name,
                    'benefit_description' => (string)$r->benefit_description,
                    'conditions'          => (string)$r->conditions,
                    'requirements'        => (string)$r->requirements,
                    'startdate'           => (int)$r->startdate,
                    'enddate'             => (int)$r->enddate,
                    'contact_label'       => (string)$r->contact_label,
                    'contact_value'       => (string)$r->contact_value,
                    'logo_path'           => (string)$r->logo_path,
                    'sort'                => (int)$r->sort,
                    'active'              => (int)$r->active,
                    'timecreated'         => (int)$r->timecreated,
                    'timemodified'        => (int)$r->timemodified,
                ];
            }, $rows)),
            'categories' => $cats,
        ];
    }

    public static function execute_returns() {
        $partner = new external_single_structure([
            'id'                  => new external_value(PARAM_INT,  'Partner id'),
            'name'                => new external_value(PARAM_TEXT, 'Partner name'),
            'categoryid'          => new external_value(PARAM_INT,  'Category id'),
            'category_name'       => new external_value(PARAM_TEXT, 'Category name'),
            'benefit_description' => new external_value(PARAM_RAW,  'Benefit'),
            'conditions'          => new external_value(PARAM_RAW,  'Conditions'),
            'requirements'        => new external_value(PARAM_RAW,  'Requirements'),
            'startdate'           => new external_value(PARAM_INT,  'Unix ts'),
            'enddate'             => new external_value(PARAM_INT,  'Unix ts'),
            'contact_label'       => new external_value(PARAM_TEXT, 'Contact label'),
            'contact_value'       => new external_value(PARAM_TEXT, 'Contact value'),
            'logo_path'           => new external_value(PARAM_TEXT, 'Logo path'),
            'sort'                => new external_value(PARAM_INT,  'Sort'),
            'active'              => new external_value(PARAM_INT,  '0/1'),
            'timecreated'         => new external_value(PARAM_INT,  'Unix ts'),
            'timemodified'        => new external_value(PARAM_INT,  'Unix ts'),
        ]);
        $cat = new external_single_structure([
            'id'   => new external_value(PARAM_INT,  'Category id'),
            'name' => new external_value(PARAM_TEXT, 'Category name'),
            'slug' => new external_value(PARAM_TEXT, 'Category slug'),
        ]);
        return new external_single_structure([
            'partners'   => new external_multiple_structure($partner, 'All partners'),
            'categories' => new external_multiple_structure($cat, 'Active categories'),
        ]);
    }
}
