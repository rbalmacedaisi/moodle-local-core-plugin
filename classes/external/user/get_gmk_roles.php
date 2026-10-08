<?php
// This file is part of Moodle - http://moodle.org/
//
// Web service: returns the caller's staff-role flags (workflow-matrix gmk_*
// roles at system context, plus the real siteadmin flag). Used by the LXP to
// decide whether to enforce student-side restrictions (contract / mora) and
// to skip the student dashboard on login.
//
// Without this, users promoted to gmk_director_academico,
// gmk_secretaria_academica, gmk_registros_academicos, gmk_soporte_ti,
// gmk_bienestar, gmk_psicologo or the legacy 'administrative' role would be
// treated as students by the LXP (no contract -> "no tienes contrato" error
// page) even though their role means they should use the LMS admin tree, not
// the LXP. local/soluttolms_core/token.php only flags teacher / manager /
// editingteacher / coursecreator / siteadmin as "manager", so none of these
// staff roles get bounced to the LMS at login time; this WS is what closes
// that gap for the LXP.
//
// Auth: any logged-in user can call this on their own behalf. Returns only
// the caller's roles, never another user's, to avoid leaking the role
// structure to the LXP.
//
// @package    local_grupomakro_core
// @copyright  2026
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

namespace local_grupomakro_core\external\user;

use external_api;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;
use context_system;

defined('MOODLE_INTERNAL') || die();

class get_gmk_roles extends external_api {

    /**
     * Non-gmk_* roles that also mean "this person is staff and belongs in the
     * LMS admin tree, not in the student flow". 'administrative' is the legacy
     * role that predates the workflow matrix (letters, requests, orders,
     * diplomas, wellness, absence dashboard); 'manager' is Moodle's own.
     */
    const STAFF_ROLES = ['manager', 'administrative'];

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function execute(): array {
        global $DB, $USER;

        $context = context_system::instance();
        self::validate_context($context);

        $userid = (int)$USER->id;
        if ($userid <= 0 || \isguestuser($userid)) {
            return [
                'is_siteadmin' => false,
                'gmk_roles' => [],
            ];
        }

        // Real siteadmin check. The previous implementation derived this from a
        // 'manager' role assignment at system context, which is always false on
        // this site: site admins live in $CFG->siteadmins, not in
        // {role_assignments}, so the flag never fired.
        $issiteadmin = \is_siteadmin($userid);

        list($insql, $inparams) = $DB->get_in_or_equal(self::STAFF_ROLES, SQL_PARAMS_NAMED, 'staff');
        $likesql = $DB->sql_like('r.shortname', ':gmkprefix', false, false, false, '|');
        $params = $inparams + [
            'uid' => $userid,
            'gmkprefix' => 'gmk|_%',
        ];

        $rows = $DB->get_records_sql(
            "SELECT DISTINCT r.shortname
               FROM {role_assignments} ra
               JOIN {role} r ON r.id = ra.roleid
               JOIN {context} c ON c.id = ra.contextid
              WHERE ra.userid = :uid
                AND c.contextlevel = " . CONTEXT_SYSTEM . "
                AND ($likesql OR r.shortname $insql)",
            $params
        );

        $gmkroles = [];
        foreach ($rows as $row) {
            $gmkroles[] = $row->shortname;
        }

        return [
            'is_siteadmin' => $issiteadmin,
            'gmk_roles' => array_values($gmkroles),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'is_siteadmin' => new external_value(PARAM_BOOL, 'True if the caller is a Moodle site administrator'),
            'gmk_roles' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Shortname of a staff role assigned to the caller at system context'),
                'Staff role shortnames (gmk_director_academico, gmk_secretaria_academica, administrative, manager, ...)',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }
}
