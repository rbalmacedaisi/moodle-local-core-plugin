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
 * Returns contact details (phone, mobile, email) and the full list of
 * historical failed subjects for a single student. Used by the
 * detail drawer in the failed-subjects report.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\admin\failed_subjects;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/failed_subjects_manager.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use context_system;

class get_student_detail extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Moodle user id'),
        ]);
    }

    public static function execute(int $userid): array {
        global $DB;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:view_failed_subjects_report', $context);

        $u = $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname, email, idnumber, phone1, phone2', MUST_EXIST);

        // Cedula from user_info_field.
        $cedula = '';
        $cedulaField = $DB->get_record('user_info_field', ['shortname' => 'documentnumber'], 'id', IGNORE_MISSING);
        if ($cedulaField) {
            $cedula = (string)$DB->get_field('user_info_data', 'data',
                ['userid' => $userid, 'fieldid' => $cedulaField->id]);
        }

        // Jornada.
        $jornada = '';
        $jField = $DB->get_record('user_info_field', ['shortname' => 'gmkjourney'], 'id', IGNORE_MISSING);
        if ($jField) {
            $jornada = (string)$DB->get_field('user_info_data', 'data',
                ['userid' => $userid, 'fieldid' => $jField->id]);
        }

        // Contact from Odoo (with cache).
        $contact = \local_grupomakro_core\local\failed_subjects_manager::get_contact_cached(
            (int)$userid,
            $cedula
        );

        // Plans the student belongs to.
        $plans = $DB->get_records_sql(
            "SELECT lp.id, lp.name, llu.currentperiodid, lp_p.name AS currentperiodname, llu.status
               FROM {local_learning_users} llu
               JOIN {local_learning_plans} lp ON lp.id = llu.learningplanid
          LEFT JOIN {local_learning_periods} lp_p ON lp_p.id = llu.currentperiodid
              WHERE llu.userid = :uid AND llu.userrolename = 'student'",
            ['uid' => $userid]
        );
        $plansOut = [];
        foreach ($plans as $p) {
            $plansOut[] = [
                'id'                => (int)$p->id,
                'name'              => (string)$p->name,
                'currentperiodid'   => (int)($p->currentperiodid ?? 0),
                'currentperiodname' => (string)($p->currentperiodname ?? ''),
                'status'            => (string)($p->status ?? ''),
            ];
        }

        // All historical failed / revalidating subjects for this student.
        $history = $DB->get_records_sql(
            "SELECT cp.id AS progress_id, cp.courseid, cp.status, cp.grade, cp.timemodified,
                    c.fullname AS coursename, lp.name AS planname
               FROM {gmk_course_progre} cp
               JOIN {course} c ON c.id = cp.courseid
          LEFT JOIN {local_learning_plans} lp ON lp.id = cp.learningplanid
              WHERE cp.userid = :uid AND cp.status IN (5, 7)
           ORDER BY cp.timemodified DESC",
            ['uid' => $userid]
        );
        $historyOut = [];
        foreach ($history as $h) {
            $historyOut[] = [
                'progress_id'  => (int)$h->progress_id,
                'courseid'     => (int)$h->courseid,
                'coursename'   => (string)$h->coursename,
                'planname'     => (string)($h->planname ?? ''),
                'status'       => (int)$h->status,
                'grade'        => (float)$h->grade,
                'timemodified' => (int)$h->timemodified,
            ];
        }

        $profileUrl = (new \moodle_url('/user/profile.php', ['id' => $userid]))->out(false);

        return [
            'userid'           => (int)$u->id,
            'firstname'        => (string)$u->firstname,
            'lastname'         => (string)$u->lastname,
            'fullname'         => trim($u->firstname . ' ' . $u->lastname),
            'idnumber'         => (string)($u->idnumber ?? ''),
            'email'            => (string)($u->email ?? ''),
            'phone1'           => (string)($u->phone1 ?? ''),
            'phone2'           => (string)($u->phone2 ?? ''),
            'cedula'           => $cedula,
            'jornada'          => $jornada,
            'contact_phone'    => (string)($contact['phone'] ?? ''),
            'contact_mobile'   => (string)($contact['mobile'] ?? ''),
            'contact_email'    => (string)($contact['email'] ?? ''),
            'financial_status' => (string)($contact['financial_status'] ?? ''),
            'financial_label'  => (string)($contact['financial_label'] ?? ''),
            'profile_url'      => $profileUrl,
            'plans'            => $plansOut,
            'history'          => $historyOut,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'userid'           => new external_value(PARAM_INT, 'Moodle userid'),
            'firstname'        => new external_value(PARAM_RAW, 'First name'),
            'lastname'         => new external_value(PARAM_RAW, 'Last name'),
            'fullname'         => new external_value(PARAM_RAW, 'Full name'),
            'idnumber'         => new external_value(PARAM_RAW, 'Moodle idnumber'),
            'email'            => new external_value(PARAM_RAW, 'Email'),
            'phone1'           => new external_value(PARAM_RAW, 'Phone 1 (Moodle)'),
            'phone2'           => new external_value(PARAM_RAW, 'Phone 2 (Moodle)'),
            'cedula'           => new external_value(PARAM_RAW, 'Cedula'),
            'jornada'          => new external_value(PARAM_RAW, 'Jornada (raw)'),
            'contact_phone'    => new external_value(PARAM_RAW, 'Phone from Odoo'),
            'contact_mobile'   => new external_value(PARAM_RAW, 'Mobile from Odoo'),
            'contact_email'    => new external_value(PARAM_RAW, 'Email from Odoo'),
            'financial_status' => new external_value(PARAM_RAW, 'Financial status code'),
            'financial_label'  => new external_value(PARAM_RAW, 'Financial status label'),
            'profile_url'      => new external_value(PARAM_URL, 'Link to Moodle profile'),
            'plans'            => new external_multiple_structure(new external_single_structure([
                'id'                => new external_value(PARAM_INT, 'Plan id'),
                'name'              => new external_value(PARAM_RAW, 'Plan name'),
                'currentperiodid'   => new external_value(PARAM_INT, 'Current period id'),
                'currentperiodname' => new external_value(PARAM_RAW, 'Current period name'),
                'status'            => new external_value(PARAM_RAW, 'activo|aplazado|retirado'),
            ])),
            'history' => new external_multiple_structure(new external_single_structure([
                'progress_id'  => new external_value(PARAM_INT, 'Progress id'),
                'courseid'     => new external_value(PARAM_INT, 'Course id'),
                'coursename'   => new external_value(PARAM_RAW, 'Course name'),
                'planname'     => new external_value(PARAM_RAW, 'Plan name'),
                'status'       => new external_value(PARAM_INT, 'Status (5 or 7)'),
                'grade'        => new external_value(PARAM_FLOAT, 'Grade'),
                'timemodified' => new external_value(PARAM_INT, 'Modified timestamp'),
            ])),
        ]);
    }
}
