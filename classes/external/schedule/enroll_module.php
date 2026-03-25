<?php
namespace local_grupomakro_core\external\schedule;

use context_system;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/group/lib.php');

/**
 * Enroll a student in an independent study module.
 *
 * Logic:
 *  1. Find the student's current academic period via local_learning_users.
 *  2. Find or create a gmk_class with is_module=1 for the given corecourseid + academicperiodid.
 *     The Moodle group is named  "{coursename} (MÓDULO) {period_code}".
 *  3. Enrol the student in the Moodle course and add them to the module group.
 *  4. Create a gmk_module_enrollment record with enrolldate=now and duedate=now+deadline_days*86400.
 *
 * @package local_grupomakro_core
 */
class enroll_module {

    /**
     * Handle the action and return a JSON-compatible array.
     *
     * @param int $userId
     * @param int $coreCourseId
     * @param int $learningPlanId  Used only for progress_manager sync (optional).
     * @return array {status, message, duedate}
     */
    public static function execute(int $userId, int $coreCourseId, int $learningPlanId = 0): array {
        global $DB, $USER;

        $context = context_system::instance();
        require_capability('moodle/site:config', $context);

        // ── 1. Validate user and course ──────────────────────────────────────────
        $user = $DB->get_record('user', ['id' => $userId, 'deleted' => 0], 'id,firstname,lastname', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $coreCourseId], 'id,fullname,shortname', MUST_EXIST);

        // ── 2. Get the last period that has already started ───────────────────────
        $academicPeriod = $DB->get_record_sql(
            "SELECT id, name FROM {gmk_academic_periods}
              WHERE startdate > 0 AND startdate <= :now
              ORDER BY startdate DESC
              LIMIT 1",
            ['now' => time()]
        );

        if (!$academicPeriod) {
            // Fallback: most recent period regardless of startdate
            $academicPeriod = $DB->get_record_sql(
                "SELECT id, name FROM {gmk_academic_periods}
                  ORDER BY id DESC
                  LIMIT 1"
            );
        }

        if (!$academicPeriod) {
            return ['status' => 'error', 'message' => 'No se encontró período académico activo.', 'duedate' => 0];
        }

        $periodCode = trim((string)$academicPeriod->name);
        $courseName = trim((string)$course->fullname);
        $groupName  = $courseName . ' (MÓDULO) ' . $periodCode;

        // ── 3. Find or create the module class ────────────────────────────────────
        $moduleClass = $DB->get_record_sql(
            "SELECT id, groupid, module_deadline_days
               FROM {gmk_class}
              WHERE is_module = 1
                AND corecourseid = :cid
                AND periodid = :pid
              LIMIT 1",
            ['cid' => $coreCourseId, 'pid' => $academicPeriod->id]
        );

        if (!$moduleClass) {
            // Create Moodle group first
            $groupData              = new \stdClass();
            $groupData->courseid    = $coreCourseId;
            $groupData->name        = $groupName;
            $groupData->idnumber    = 'modulo-' . $coreCourseId . '-' . $academicPeriod->id;
            $groupData->description = 'Módulo independiente: ' . $groupName;
            $groupData->descriptionformat = 1;

            // Reuse if group with same idnumber already exists
            $existingGroup = $DB->get_record('groups', [
                'idnumber' => $groupData->idnumber,
                'courseid' => $coreCourseId,
            ]);
            $groupId = $existingGroup ? (int)$existingGroup->id : (int)groups_create_group($groupData);

            if (!$groupId) {
                return ['status' => 'error', 'message' => 'No se pudo crear el grupo del módulo.', 'duedate' => 0];
            }

            $now = time();
            $newClass = new \stdClass();
            $newClass->name               = $groupName;
                        $newClass->type               = 1; // Virtual (módulo asíncrono)
            $newClass->is_module          = 1;
            $newClass->module_deadline_days = 25;
            $newClass->corecourseid       = $coreCourseId;
            $newClass->groupid            = $groupId;
            $newClass->periodid           = (int)$academicPeriod->id;
            $newClass->learningplanid     = $learningPlanId ?: 0;
            $newClass->courseid           = 0;
            $newClass->instructorid       = 0;
            $newClass->inittime           = '';
            $newClass->endtime            = '';
            $newClass->initdate           = $now;
            $newClass->enddate            = 0; // No expiry on the class itself
            $newClass->classdays          = '0/0/0/0/0/0/0';
            $newClass->approved           = 1;
            $newClass->closed             = 0;
            $newClass->gradecategoryid    = 0;
            $newClass->usermodified       = (int)$USER->id;
            $newClass->timecreated        = $now;
            $newClass->timemodified       = $now;

            $newClass->id = $DB->insert_record('gmk_class', $newClass);
            $moduleClass  = $DB->get_record('gmk_class', ['id' => $newClass->id]);
        }

        $classId     = (int)$moduleClass->id;
        $groupId     = (int)$moduleClass->groupid;
        $deadlineDays = (int)($moduleClass->module_deadline_days ?: 25);

        // ── 4. Check for existing enrollment ──────────────────────────────────────
        $existing = $DB->get_record('gmk_module_enrollment', ['classid' => $classId, 'userid' => $userId]);
        if ($existing) {
            // Ensure the student is still in the regular class group (retroactive fix)
            // Use the same period logic: last started period
            $retro_period = $DB->get_record_sql(
                "SELECT id FROM {gmk_academic_periods}
                  WHERE startdate > 0 AND startdate <= :now
                  ORDER BY startdate DESC LIMIT 1",
                ['now' => time()]
            );
            $retro_pid = $retro_period ? (int)$retro_period->id : (int)$academicPeriod->id;
            $regularClassCheck = $DB->get_record_sql(
                "SELECT groupid FROM {gmk_class}
                  WHERE corecourseid = :cid AND periodid = :pid AND is_module = 0 AND groupid > 0
                  ORDER BY id DESC LIMIT 1",
                ['cid' => $coreCourseId, 'pid' => $retro_pid]
            );
            if ($regularClassCheck && !empty($regularClassCheck->groupid)) {
                if (!groups_is_member((int)$regularClassCheck->groupid, $userId)) {
                    groups_add_member((int)$regularClassCheck->groupid, $userId);
                }
            }
            $dueDateFormatted = userdate((int)$existing->duedate, get_string('strftimedatefullshort', 'langconfig'));
            return [
                'status'     => 'warning',
                'message'    => 'El estudiante ya está inscrito en este módulo. Período: ' . $periodCode . '. Plazo: ' . $dueDateFormatted,
                'duedate'    => (int)$existing->duedate,
                'periodname' => $periodCode,
            ];
        }

        // ── 5. Enrol student in Moodle course and add to group ────────────────────
        $enrolPlugin = enrol_get_plugin('manual');
        $courseInstance = get_manual_enroll($coreCourseId);
        $studentRoleId  = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);

        if ($enrolPlugin && $courseInstance && $studentRoleId) {
            $enrolPlugin->enrol_user($courseInstance, $userId, $studentRoleId);
        }

        if ($groupId > 0 && !groups_is_member($groupId, $userId)) {
            groups_add_member($groupId, $userId);
        }

        // ── 5b. Also add to the regular class group so group-restricted
        //        course sections remain visible to the module student ──────────────
        $regularClass = $DB->get_record_sql(
            "SELECT groupid FROM {gmk_class}
              WHERE corecourseid = :cid AND periodid = :pid AND is_module = 0 AND groupid > 0
              ORDER BY id DESC
              LIMIT 1",
            ['cid' => $coreCourseId, 'pid' => (int)$academicPeriod->id]
        );
        if ($regularClass && !empty($regularClass->groupid)) {
            if (!groups_is_member((int)$regularClass->groupid, $userId)) {
                groups_add_member((int)$regularClass->groupid, $userId);
            }
        }

        // ── 6. Create enrollment record ───────────────────────────────────────────
        $now     = time();
        $dueDate = $now + ($deadlineDays * DAYSECS);

        $enrollment               = new \stdClass();
        $enrollment->classid      = $classId;
        $enrollment->userid       = $userId;
        $enrollment->enrolldate   = $now;
        $enrollment->duedate      = $dueDate;
        $enrollment->status       = 'active';
        $enrollment->timecreated  = $now;
        $enrollment->timemodified = $now;
        $enrollment->usermodified = (int)$USER->id;

        $DB->insert_record('gmk_module_enrollment', $enrollment);

        $dueDateFormatted = userdate($dueDate, get_string('strftimedatefullshort', 'langconfig'));
        return [
            'status'     => 'ok',
            'message'    => 'Inscrito en módulo correctamente. Período: ' . $periodCode . '. Plazo: ' . $dueDateFormatted,
            'duedate'    => $dueDate,
            'periodname' => $periodCode,
        ];
    }
}
