<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Diplomas AJAX dispatcher.
 *
 * @package    local_grupomakro_core
 * @copyright  2024 Solutto Consulting
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\diploma;

defined('MOODLE_INTERNAL') || die();

use context_system;
use moodle_exception;
use stdClass;

/**
 * Lightweight dispatcher that delegates diploma-related AJAX actions to the
 * underlying manager. Loaded from ajax.php via a "local_grupomakro_diploma_*"
 * action prefix.
 */
final class dispatcher {
    /**
     * Entry point.
     *
     * @param string $action Specific action (after the prefix).
     * @return array Response payload.
     */
    public static function dispatch(string $action): array {
        global $USER;
        $capmanage = 'local/grupomakro_core:managediplomas';
        $capview = 'local/grupomakro_core:viewdiplomas';
        $manager = \local_grupomakro_core\local\diplomas\manager::class;
        switch ($action) {
            case 'list_templates':
                require_capability($capmanage, context_system::instance());
                return ['status' => 'success', 'templates' => $manager::list_templates()];

            case 'get_template':
                require_capability($capmanage, context_system::instance());
                $id = required_param('id', PARAM_INT);
                $tpl = $manager::get_template($id);
                if (!$tpl) {
                    throw new moodle_exception('diploma_template_not_found', 'local_grupomakro_core');
                }
                return ['status' => 'success', 'template' => $manager::export_template($tpl)];

            case 'save_template':
                require_capability($capmanage, context_system::instance());
                $args = required_param('payload', PARAM_RAW);
                $payload = json_decode($args, true);
                if (!is_array($payload)) {
                    throw new moodle_exception('invalidjson');
                }
                $exported = $manager::save_template($payload, $USER->id);
                return ['status' => 'success', 'template' => $exported, 'message' => get_string('diploma_save_template_success', 'local_grupomakro_core')];

            case 'duplicate_template':
                require_capability($capmanage, context_system::instance());
                $id = required_param('id', PARAM_INT);
                $exported = $manager::duplicate_template($id, $USER->id);
                return ['status' => 'success', 'template' => $exported, 'message' => get_string('diploma_template_duplicated', 'local_grupomakro_core')];

            case 'delete_template':
                require_capability($capmanage, context_system::instance());
                $id = required_param('id', PARAM_INT);
                $manager::delete_template($id);
                return ['status' => 'success', 'message' => get_string('diploma_template_deleted', 'local_grupomakro_core')];

            case 'upload_background':
                require_capability($capmanage, context_system::instance());
                $id = required_param('id', PARAM_INT);
                if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
                    throw new moodle_exception('diploma_no_file', 'local_grupomakro_core');
                }
                $content = file_get_contents($_FILES['file']['tmp_name']);
                $result = $manager::save_background(
                    $id,
                    (string)$_FILES['file']['name'],
                    (string)($_FILES['file']['type'] ?: 'application/octet-stream'),
                    $content,
                    $USER->id
                );
                return ['status' => 'success', 'background' => $result];

            case 'list_variables':
                require_capability($capmanage, context_system::instance());
                return ['status' => 'success', 'variables' => $manager::get_variable_catalog_for_api()];

            case 'list_plans':
                require_capability($capview, context_system::instance());
                return ['status' => 'success', 'plans' => $manager::list_learning_plans()];

            case 'count_eligible':
                require_capability($capview, context_system::instance());
                return ['status' => 'success', 'counts' => $manager::count_eligible_by_plan()];

            case 'list_graduands':
                require_capability($capview, context_system::instance());
                $lpid = optional_param('learningplanid', 0, PARAM_INT);
                $search = optional_param('search', '', PARAM_TEXT);
                return ['status' => 'success', 'graduands' => $manager::list_eligible_graduands($lpid ?: null, (string)$search)];

            case 'generate_diplomas':
                require_capability($capmanage, context_system::instance());
                $templateid = required_param('templateid', PARAM_INT);
                $args = required_param('items', PARAM_RAW);
                $items = json_decode($args, true);
                if (!is_array($items)) {
                    throw new moodle_exception('invalidjson');
                }
                $result = $manager::generate_diplomas($templateid, $items, $USER->id);
                $msgkey = $result['errors'] > 0
                    ? 'diploma_generation_partial'
                    : 'diploma_generation_done';
                $msg = get_string($msgkey, 'local_grupomakro_core', $result);
                return ['status' => 'success', 'summary' => $result, 'message' => $msg];

            case 'list_generations':
                require_capability($capview, context_system::instance());
                $templateid = optional_param('templateid', 0, PARAM_INT);
                $status = optional_param('status', '', PARAM_TEXT);
                $search = optional_param('search', '', PARAM_TEXT);
                return ['status' => 'success', 'records' => $manager::list_generations($templateid ?: null, $status !== '' ? $status : null, (string)$search)];

            case 'revoke_generation':
                require_capability($capmanage, context_system::instance());
                $id = required_param('id', PARAM_INT);
                $reason = optional_param('reason', '', PARAM_TEXT);
                $manager::revoke_generation($id, $USER->id, (string)$reason);
                return ['status' => 'success', 'message' => get_string('diploma_revoked_ok', 'local_grupomakro_core')];

            case 'download_generation':
                require_capability($capview, context_system::instance());
                $id = required_param('id', PARAM_INT);
                $payload = $manager::get_generation_pdf($id);
                if (!$payload) {
                    throw new moodle_exception('diploma_document_not_found', 'local_grupomakro_core');
                }
                return ['status' => 'success', 'document' => $payload];

            default:
                throw new moodle_exception('invalidaction', 'error', '', $action);
        }
    }
}
