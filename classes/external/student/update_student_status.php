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
 * External function to update student status (both general and academic status).
 *
 * @package    local_grupomakro_core
 * @copyright  2025 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\student;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/user/profile/lib.php');

/**
 * External function 'local_grupomakro_update_student_status' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2025 Antigravity
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_student_status extends external_api {

    /**
     * Describes parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'User ID', VALUE_REQUIRED),
            'field' => new external_value(PARAM_TEXT, 'Field to update: studentstatus or academicstatus', VALUE_REQUIRED),
            'value' => new external_value(PARAM_TEXT, 'New value for the field', VALUE_REQUIRED),
        ]);
    }

    /**
     * Update student status field.
     *
     * @param int $userid User ID
     * @param string $field Field name (studentstatus or academicstatus)
     * @param string $value New value
     * @return array Result
     */
    public static function execute($userid, $field, $value) {
        global $DB;

        // Validate parameters
        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'field' => $field,
            'value' => $value
        ]);

        // Validate context and capability
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        // Validate field name
        $allowedFields = ['studentstatus', 'academicstatus'];
        if (!in_array($params['field'], $allowedFields)) {
            return [
                'status' => 'error',
                'message' => 'Invalid field name. Allowed: studentstatus, academicstatus'
            ];
        }

        try {
            // Validate user exists
            $user = $DB->get_record('user', ['id' => $params['userid']], '*', MUST_EXIST);

            if ($params['field'] === 'studentstatus') {
                // Update custom profile field
                $fieldRecord = $DB->get_record('user_info_field', ['shortname' => 'studentstatus']);

                if (!$fieldRecord) {
                    return [
                        'status' => 'error',
                        'message' => "Profile field 'studentstatus' not found"
                    ];
                }

                // Check if data exists for this user/field
                $existingData = $DB->get_record('user_info_data', [
                    'userid' => $params['userid'],
                    'fieldid' => $fieldRecord->id
                ]);

                if ($existingData) {
                    // Update existing
                    $existingData->data = $params['value'];
                    $existingData->dataformat = 0;
                    $DB->update_record('user_info_data', $existingData);
                } else {
                    // Insert new
                    $newData = new \stdClass();
                    $newData->userid = $params['userid'];
                    $newData->fieldid = $fieldRecord->id;
                    $newData->data = $params['value'];
                    $newData->dataformat = 0;
                    $DB->insert_record('user_info_data', $newData);
                }

            } else { // academicstatus
                // Update local_learning_users.status field
                // Validate value
                $validStatuses = ['activo', 'aplazado', 'retirado', 'suspendido', 'desertor'];
                $valueLower = strtolower($params['value']);

                if (!in_array($valueLower, $validStatuses)) {
                    return [
                        'status' => 'error',
                        'message' => "Invalid status. Allowed values: " . implode(', ', $validStatuses)
                    ];
                }

                // Update ALL enrollment records for this user
                $enrollments = $DB->get_records('local_learning_users', ['userid' => $params['userid']]);

                if (empty($enrollments)) {
                    return [
                        'status' => 'error',
                        'message' => 'No enrollment records found for this user'
                    ];
                }

                $updated = 0;
                foreach ($enrollments as $enrollment) {
                    $enrollment->status = $valueLower;
                    $enrollment->timemodified = time();
                    $DB->update_record('local_learning_users', $enrollment);
                    $updated++;
                }
            }

            // Log the change
            \gmk_log("Updated {$params['field']} for user {$params['userid']} to '{$params['value']}'");

            return [
                'status' => 'success',
                'message' => 'Status updated successfully',
                'field' => $params['field'],
                'value' => $params['value']
            ];

        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Describes return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Status of the operation'),
            'message' => new external_value(PARAM_TEXT, 'Result message'),
            'field' => new external_value(PARAM_TEXT, 'Field updated', VALUE_OPTIONAL),
            'value' => new external_value(PARAM_TEXT, 'New value', VALUE_OPTIONAL),
        ]);
    }
}
