<?php
namespace local_grupomakro_core\external\user;

use external_api;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;
use context_system;

defined('MOODLE_INTERNAL') || die();

class search_users extends external_api {

    /**
     * Parameters.
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'query' => new external_value(PARAM_TEXT, 'Search query (name, email)'),
            // Optional: callers that render a short dropdown (e.g. the wellness
            // staff roster) ask for fewer rows. Omitted by older callers.
            'limit' => new external_value(PARAM_INT, 'Max rows (1-50)', VALUE_DEFAULT, 20),
        ]);
    }

    /**
     * Execute.
     */
    public static function execute($query, $limit = 20) {
        global $DB, $CFG;

        $params = self::validate_parameters(self::execute_parameters(),
            ['query' => $query, 'limit' => $limit]);
        $query = trim($params['query']);
        $limit = min(50, max(1, (int)$params['limit']));

        $context = context_system::instance();
        self::validate_context($context);
        // Site admins keep full access. The wellness back-office needs the same
        // picker to assign staff, and those pages are gated by their own
        // capabilities, so accept either of them instead of forcing siteadmin.
        if (!has_capability('moodle/site:config', $context)
            && !has_capability('local/grupomakro_core:manage_wellness', $context)
            && !has_capability('local/grupomakro_core:manage_psychology_appointments', $context)) {
            require_capability('moodle/site:config', $context);
        }

        if (\core_text::strlen($query) < 3) {
            return []; // Minimum 3 chars
        }

        // Simple search
        $sql = "SELECT id, firstname, lastname, email, username 
                FROM {user} 
                WHERE deleted = 0 AND suspended = 0 
                AND (firstname LIKE :q1 OR lastname LIKE :q2 OR email LIKE :q3 OR username LIKE :q4)
                ORDER BY firstname ASC, lastname ASC";
        
        $q = '%' . $DB->sql_like_escape($query) . '%';
        $users = $DB->get_records_sql($sql, ['q1'=>$q, 'q2'=>$q, 'q3'=>$q, 'q4'=>$q], 0, $limit);

        $result = [];
        foreach ($users as $u) {
            $result[] = [
                'id' => $u->id,
                'fullname' => fullname($u),
                'email' => $u->email,
                'username' => $u->username
            ];
        }

        return $result;
    }

    /**
     * Returns.
     */
    public static function execute_returns() {
        return new external_multiple_structure(
            new external_single_structure([
                'id' => new external_value(PARAM_INT, 'User ID'),
                'fullname' => new external_value(PARAM_TEXT, 'Full Name'),
                'email' => new external_value(PARAM_TEXT, 'Email'),
                'username' => new external_value(PARAM_TEXT, 'Username'),
            ])
        );
    }
}
