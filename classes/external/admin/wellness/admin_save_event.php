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
 * Admin: upsert a wellness event + its attachments. RF-09.2.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\admin\wellness;

use context_system;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wellness_event_manager.php');

class admin_save_event extends external_api {

    public static function execute_parameters() {
        $att = new external_single_structure([
            'kind'      => new external_value(PARAM_ALPHA,'handout|recording|link|other', VALUE_DEFAULT, 'handout'),
            'label'     => new external_value(PARAM_TEXT, 'Label', VALUE_DEFAULT, ''),
            'url'       => new external_value(PARAM_TEXT, 'External URL', VALUE_DEFAULT, ''),
            'file_path' => new external_value(PARAM_TEXT, 'Pluginfile path', VALUE_DEFAULT, ''),
            'mimetype'  => new external_value(PARAM_TEXT, 'MIME type', VALUE_DEFAULT, ''),
            'filesize'  => new external_value(PARAM_INT,  'Filesize bytes', VALUE_DEFAULT, 0),
        ]);
        return new external_function_parameters([
            'id'                     => new external_value(PARAM_INT,   '0 to create', VALUE_DEFAULT, 0),
            'title'                  => new external_value(PARAM_TEXT, 'Title', VALUE_REQUIRED),
            'summary'                => new external_value(PARAM_TEXT, 'Teaser', VALUE_DEFAULT, ''),
            'description'            => new external_value(PARAM_RAW,  'Body', VALUE_DEFAULT, ''),
            'category'               => new external_value(PARAM_ALPHA,'deportivo|feria|taller|charla|otro', VALUE_DEFAULT, 'otro'),
            'startdate'              => new external_value(PARAM_INT,   'Start unix ts', VALUE_REQUIRED),
            'enddate'                => new external_value(PARAM_INT,   'End unix ts', VALUE_DEFAULT, 0),
            'modality'               => new external_value(PARAM_ALPHA,'presencial|virtual|mixto', VALUE_DEFAULT, 'presencial'),
            'location'               => new external_value(PARAM_TEXT, 'Location', VALUE_DEFAULT, ''),
            'virtual_url'            => new external_value(PARAM_TEXT, 'Virtual URL', VALUE_DEFAULT, ''),
            'capacity'               => new external_value(PARAM_INT,   'Capacity (0 = unlimited)', VALUE_DEFAULT, 0),
            'requires_registration'  => new external_value(PARAM_BOOL,  'Requires registration', VALUE_DEFAULT, true),
            'allow_waitlist'         => new external_value(PARAM_BOOL,  'Allow waitlist when full', VALUE_DEFAULT, false),
            'registration_opens_at'  => new external_value(PARAM_INT,   'Registration opens unix ts', VALUE_DEFAULT, 0),
            'registration_closes_at' => new external_value(PARAM_INT,   'Registration closes unix ts', VALUE_DEFAULT, 0),
            'organizer_name'         => new external_value(PARAM_TEXT, 'Organizer name', VALUE_DEFAULT, ''),
            'organizer_email'        => new external_value(PARAM_TEXT, 'Organizer email', VALUE_DEFAULT, ''),
            'cover_path'             => new external_value(PARAM_TEXT, 'Cover image pluginfile path', VALUE_DEFAULT, ''),
            'active'                 => new external_value(PARAM_BOOL,  'Active flag', VALUE_DEFAULT, true),
            'attachments'            => new external_value(PARAM_RAW,   'JSON array of attachment objects', VALUE_DEFAULT, '[]'),
        ]);
    }

    public static function execute(
        $title, $startdate,
        $id = 0, $summary = '', $description = '', $category = 'otro',
        $enddate = 0, $modality = 'presencial', $location = '', $virtual_url = '',
        $capacity = 0, $requires_registration = true, $allow_waitlist = false,
        $registration_opens_at = 0, $registration_closes_at = 0,
        $organizer_name = '', $organizer_email = '', $cover_path = '',
        $active = true, $attachments = '[]'
    ) {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'id' => $id, 'title' => $title, 'summary' => $summary, 'description' => $description,
            'category' => $category, 'startdate' => $startdate, 'enddate' => $enddate,
            'modality' => $modality, 'location' => $location, 'virtual_url' => $virtual_url,
            'capacity' => $capacity, 'requires_registration' => $requires_registration,
            'allow_waitlist' => $allow_waitlist,
            'registration_opens_at' => $registration_opens_at,
            'registration_closes_at' => $registration_closes_at,
            'organizer_name' => $organizer_name, 'organizer_email' => $organizer_email,
            'cover_path' => $cover_path, 'active' => $active, 'attachments' => $attachments,
        ]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manage_wellness', $context);

        $payload = $params;
        $attJson = json_decode((string)$params['attachments'], true);
        $payload['attachments'] = is_array($attJson) ? $attJson : [];

        try {
            $newid = \local_grupomakro_core\local\wellness_event_manager::upsert($payload, (int)$USER->id);
        } catch (\moodle_exception $e) {
            throw new Exception($e->getMessage());
        }
        return ['ok' => true, 'id' => (int)$newid];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'True on success'),
            'id' => new external_value(PARAM_INT,  'Event id'),
        ]);
    }
}
