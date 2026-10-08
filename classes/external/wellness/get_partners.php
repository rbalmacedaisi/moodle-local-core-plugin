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
 * Public catalogue of wellness partners (RF-01, RF-01.2, RF-01.3).
 * Used by the LXP. Capability: local/grupomakro_core:view_wellness.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\wellness;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wellness_partner_manager.php');

class get_partners extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'keyword'    => new external_value(PARAM_TEXT, 'Optional keyword search', VALUE_DEFAULT, ''),
            'categoryid' => new external_value(PARAM_INT,  'Optional category filter; 0 = all', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute($keyword = '', $categoryid = 0) {
        $params = self::validate_parameters(self::execute_parameters(), [
            'keyword'    => $keyword,
            'categoryid' => $categoryid,
        ]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:view_wellness', $context);

        $rows = \local_grupomakro_core\local\wellness_partner_manager::list_for_students(
            (string)$params['keyword'], (int)$params['categoryid']);

        return [
            'partners'   => array_values(array_map([self::class, 'cast_partner'], $rows)),
            'categories' => \local_grupomakro_core\local\wellness_partner_manager::list_categories(),
        ];
    }

    public static function cast_partner(object $r): array {
        return [
            'id'                  => (int)$r->id,
            'name'                => (string)$r->name,
            'categoryid'          => (int)$r->categoryid,
            'category_name'       => (string)($r->category_name ?? ''),
            'category_slug'       => (string)($r->category_slug ?? ''),
            'benefit_description' => (string)$r->benefit_description,
            'conditions'          => (string)($r->conditions ?? ''),
            'requirements'        => (string)($r->requirements ?? ''),
            'startdate'           => (int)$r->startdate,
            'enddate'             => (int)$r->enddate,
            'contact_label'       => (string)($r->contact_label ?? ''),
            'contact_value'       => (string)($r->contact_value ?? ''),
            'logo_path'           => (string)($r->logo_path ?? ''),
            'is_expired'          => !empty($r->is_expired),
            'is_future'           => !empty($r->is_future),
        ];
    }

    public static function execute_returns() {
        $partner = new external_single_structure([
            'id'                  => new external_value(PARAM_INT,  'Partner id'),
            'name'                => new external_value(PARAM_TEXT, 'Partner name'),
            'categoryid'          => new external_value(PARAM_INT,  'Category id'),
            'category_name'       => new external_value(PARAM_TEXT, 'Category display name'),
            'category_slug'       => new external_value(PARAM_TEXT, 'Category slug'),
            'benefit_description' => new external_value(PARAM_RAW,  'Benefit description'),
            'conditions'          => new external_value(PARAM_RAW,  'Conditions of use'),
            'requirements'        => new external_value(PARAM_RAW,  'Requirements'),
            'startdate'           => new external_value(PARAM_INT,  'Unix ts'),
            'enddate'             => new external_value(PARAM_INT,  'Unix ts'),
            'contact_label'       => new external_value(PARAM_TEXT, 'e.g. Telefono, WhatsApp'),
            'contact_value'       => new external_value(PARAM_TEXT, 'Contact value'),
            'logo_path'           => new external_value(PARAM_TEXT, 'Pluginfile path for the logo'),
            'is_expired'          => new external_value(PARAM_BOOL, 'Past enddate'),
            'is_future'           => new external_value(PARAM_BOOL, 'Not yet started'),
        ]);
        $category = new external_single_structure([
            'id'   => new external_value(PARAM_INT,  'Category id'),
            'name' => new external_value(PARAM_TEXT, 'Category name'),
            'slug' => new external_value(PARAM_TEXT, 'Category slug'),
        ]);
        return new external_single_structure([
            'partners'   => new external_multiple_structure($partner, 'Active partners visible to students'),
            'categories' => new external_multiple_structure($category, 'Category catalogue for the filter dropdown'),
        ]);
    }
}
