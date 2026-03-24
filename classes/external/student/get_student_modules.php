<?php
namespace local_grupomakro_core\external\student;

use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use context_system;

defined('MOODLE_INTERNAL') || die();

/**
 * Web service: get active module enrollments for a student (used by LXP).
 *
 * @package local_grupomakro_core
 */
class get_student_modules extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'Student user ID'),
        ]);
    }

    public static function execute(int $userid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['userid' => $userid]);
        $context = context_system::instance();
        self::validate_context($context);

        $now = time();

        $records = $DB->get_records_sql(
            "SELECT gme.id,
                    gme.classid,
                    gme.enrolldate,
                    gme.duedate,
                    gme.status,
                    gc.corecourseid,
                    gc.coursename,
                    gc.module_deadline_days,
                    gap.code  AS periodcode,
                    gap.name  AS periodname
               FROM {gmk_module_enrollment} gme
               JOIN {gmk_class} gc  ON gc.id  = gme.classid AND gc.is_module = 1
               JOIN {gmk_academic_periods} gap ON gap.id = gc.periodid
              WHERE gme.userid = :userid
                AND gme.status = 'active'
              ORDER BY gme.duedate ASC",
            ['userid' => $params['userid']]
        );

        $modules = [];
        foreach ($records as $r) {
            $daysRemaining = (int)ceil(((int)$r->duedate - $now) / DAYSECS);
            $modules[] = [
                'enrollmentid'   => (int)$r->id,
                'classid'        => (int)$r->classid,
                'corecourseid'   => (int)$r->corecourseid,
                'coursename'     => (string)($r->coursename ?? ''),
                'periodcode'     => (string)($r->periodcode ?: $r->periodname),
                'enrolldate'     => (int)$r->enrolldate,
                'duedate'        => (int)$r->duedate,
                'daysremaining'  => max(0, $daysRemaining),
                'status'         => (string)$r->status,
            ];
        }

        return $modules;
    }

    public static function execute_returns() {
        return new external_multiple_structure(
            new external_single_structure([
                'enrollmentid'  => new external_value(PARAM_INT,  'Enrollment record ID'),
                'classid'       => new external_value(PARAM_INT,  'Module class ID'),
                'corecourseid'  => new external_value(PARAM_INT,  'Moodle course ID'),
                'coursename'    => new external_value(PARAM_TEXT, 'Subject name'),
                'periodcode'    => new external_value(PARAM_TEXT, 'Academic period code'),
                'enrolldate'    => new external_value(PARAM_INT,  'Enrollment timestamp'),
                'duedate'       => new external_value(PARAM_INT,  'Deadline timestamp'),
                'daysremaining' => new external_value(PARAM_INT,  'Days remaining until deadline'),
                'status'        => new external_value(PARAM_TEXT, 'active|completed|expired'),
            ])
        );
    }
}
