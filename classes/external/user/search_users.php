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
        ]);
    }

    /**
     * Execute.
     */
    public static function execute($query) {
        global $DB, $CFG;

        $params = self::validate_parameters(self::execute_parameters(), ['query' => $query]);
        $query = trim($params['query']);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context); // Admin only for now

        if (strlen($query) < 3) {
            return []; // Minimum 3 chars
        }

        // Simple search
        $sql = "SELECT id, firstname, lastname, email, username 
                FROM {user} 
                WHERE deleted = 0 AND suspended = 0 
                AND (firstname LIKE :q1 OR lastname LIKE :q2 OR email LIKE :q3 OR username LIKE :q4)
                ORDER BY firstname ASC, lastname ASC";
        
        $q = '%' . $DB->sql_like_escape($query) . '%';
        $users = $DB->get_records_sql($sql, ['q1'=>$q, 'q2'=>$q, 'q3'=>$q, 'q4'=>$q], 0, 20);

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
