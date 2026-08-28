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
 * Admin: upsert a wellness partner (RF-09.1). Used by the back-office
 * Vue form to create or edit a partner. Soft-delete is a separate WS.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\admin\wellness;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wellness_partner_manager.php');

class admin_save_partner extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'id'                  => new external_value(PARAM_INT,  '0 to create', VALUE_DEFAULT, 0),
            'name'                => new external_value(PARAM_TEXT, 'Partner name', VALUE_REQUIRED),
            'categoryid'          => new external_value(PARAM_INT,  'Category id', VALUE_REQUIRED),
            'benefit_description' => new external_value(PARAM_RAW,  'Benefit', VALUE_REQUIRED),
            'conditions'          => new external_value(PARAM_RAW,  'Conditions', VALUE_DEFAULT, ''),
            'requirements'        => new external_value(PARAM_RAW,  'Requirements', VALUE_DEFAULT, ''),
            'startdate'           => new external_value(PARAM_INT,  'Unix ts (0 = always)', VALUE_DEFAULT, 0),
            'enddate'             => new external_value(PARAM_INT,  'Unix ts (0 = never)', VALUE_DEFAULT, 0),
            'contact_label'       => new external_value(PARAM_TEXT, 'Contact label', VALUE_DEFAULT, ''),
            'contact_value'       => new external_value(PARAM_TEXT, 'Contact value', VALUE_DEFAULT, ''),
            'logo_path'           => new external_value(PARAM_TEXT, 'Pluginfile path', VALUE_DEFAULT, ''),
            'sort'                => new external_value(PARAM_INT,  'Sort order', VALUE_DEFAULT, 0),
            'active'              => new external_value(PARAM_BOOL, 'Active flag', VALUE_DEFAULT, true),
        ]);
    }

    public static function execute(
        $name, $categoryid, $benefit_description,
        $id = 0, $conditions = '', $requirements = '',
        $startdate = 0, $enddate = 0,
        $contact_label = '', $contact_value = '',
        $logo_path = '', $sort = 0, $active = true
    ) {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'id' => $id, 'name' => $name, 'categoryid' => $categoryid,
            'benefit_description' => $benefit_description,
            'conditions' => $conditions, 'requirements' => $requirements,
            'startdate' => $startdate, 'enddate' => $enddate,
            'contact_label' => $contact_label, 'contact_value' => $contact_value,
            'logo_path' => $logo_path, 'sort' => $sort, 'active' => $active,
        ]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manage_wellness', $context);

        try {
            $id = \local_grupomakro_core\local\wellness_partner_manager::upsert($params, (int)$USER->id);
        } catch (\moodle_exception $e) {
            throw new Exception($e->getMessage());
        }
        return ['ok' => true, 'id' => $id];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'True on success'),
            'id' => new external_value(PARAM_INT,  'Partner id'),
        ]);
    }
}
