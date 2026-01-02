<?php
/**
 * External function to update student financial status manually.
 *
 * @package    local_grupomakro_core
 * @copyright  2024 Grupomakro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\student;

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use local_grupomakro_core\task\update_financial_status;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

class update_status extends external_api {

    /**
     * Parameters for execute.
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'User ID to update. If 0, attempts to update batch.', VALUE_DEFAULT, 0)
        ]);
    }

    /**
     * Execute the update.
     */
    public static function execute($userid = 0) {
        $params = self::validate_parameters(self::execute_parameters(), ['userid' => $userid]);
        $targetUserIds = [];
        
        if (!empty($params['userid'])) {
            $context = \context_user::instance($params['userid']);
            self::validate_context($context); // Or system context if admin doing it?
            // Usually academic director does this. Let's use system context for now as it's an admin-like task
            // or we check capabilities.
            $targetUserIds[] = $params['userid'];
        } else {
             $context = \context_system::instance();
             self::validate_context($context);
        }
        
        require_capability('moodle/site:config', \context_system::instance()); // Security check - restrict to admins/managers

        $result = local_grupomakro_sync_financial_status($targetUserIds);

        if (isset($result['error'])) {
             // Temporary: use generic exception to show the error detail directly
             throw new \moodle_exception('generalexceptionmessage', 'error', '', $result['error'] . ' - ' . ($result['details'] ?? ''));
        }

        return [
            'success' => true,
            'message' => 'Update complete',
            'updated_count' => $result['updated'] ?? 0
        ];
    }

    /**
     * Return structure.
     */
    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'message' => new external_value(PARAM_TEXT, 'Message'),
            'updated_count' => new external_value(PARAM_INT, 'Number of users updated')
        ]);
    }
}
