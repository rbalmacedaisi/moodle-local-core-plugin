<?php
defined('MOODLE_INTERNAL') || die();

namespace local_grupomakro_core\external\lp;

use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;

require_once($CFG->dirroot . '/local/sc_learningplans/libs/userlib.php');
require_once($CFG->dirroot . '/local/sc_learningplans/libs/plan_deplib.php');
require_once($CFG->dirroot . '/local/sc_learningplans/classes/event/learningplanuser_added.php');

class bulk_enroll extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userids'      => new external_multiple_structure(
                new external_value(PARAM_INT, 'User ID'),
                'List of user IDs to enroll'
            ),
            'targetplanid' => new external_value(PARAM_INT,  'Target learning plan ID'),
            'periodid'     => new external_value(PARAM_INT,  'Target period ID (0 = auto)', VALUE_DEFAULT, 0),
            'groupname'    => new external_value(PARAM_TEXT, 'Group name (optional)',        VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(array $userids, int $targetplanid, int $periodid = 0, string $groupname = ''): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userids'      => $userids,
            'targetplanid' => $targetplanid,
            'periodid'     => $periodid,
            'groupname'    => $groupname,
        ]);

        $plan = $DB->get_record('local_learning_plans', ['id' => $params['targetplanid']]);
        if (!$plan) {
            return ['results' => json_encode([]), 'enrolled' => 0, 'skipped' => 0, 'errors' => 0,
                    'message' => 'Plan de aprendizaje no encontrado.'];
        }

        $studentRoleId = $DB->get_field('role', 'id', ['shortname' => 'student']);
        $currentPeriodId = $params['periodid'] > 0 ? $params['periodid'] : null;
        $group = !empty($params['groupname']) ? $params['groupname'] : null;

        $results   = [];
        $enrolled  = 0;
        $skipped   = 0;
        $errors    = 0;

        foreach (array_unique($params['userids']) as $userid) {
            $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
            if (!$user) {
                $results[] = ['userid' => $userid, 'fullname' => "ID $userid", 'status' => 'error',
                              'message' => 'Usuario no encontrado.'];
                $errors++;
                continue;
            }
            $fullname = trim($user->firstname . ' ' . $user->lastname);

            // Skip if already enrolled.
            $existing = $DB->get_record('local_learning_users', [
                'learningplanid' => $params['targetplanid'],
                'userid'         => $userid,
            ]);
            if ($existing) {
                $results[] = ['userid' => $userid, 'fullname' => $fullname, 'status' => 'skipped',
                              'message' => 'Ya está matriculado en este plan.'];
                $skipped++;
                continue;
            }

            try {
                // Create the local_learning_users record.
                $record = new \stdClass();
                $record->learningplanid  = (int)$params['targetplanid'];
                $record->userid          = (int)$userid;
                $record->userroleid      = (int)$studentRoleId;
                $record->userrolename    = 'student';
                $record->currentperiodid = $currentPeriodId;
                $record->groupname       = $group;
                $record->usermodified    = (int)$USER->id;
                $record->timecreated     = time();
                $record->timemodified    = time();
                $record->id = $DB->insert_record('local_learning_users', $record);

                // Enrol in courses (same as add_learning_user).
                enrol_user_in_learningplan_courses($params['targetplanid'], $userid, $studentRoleId, $group);

                // Trigger dependencies.
                sc_learningplan_trigger_dependencies($params['targetplanid'], $userid, $studentRoleId, $group);

                // Send enrolment email if configured.
                send_email_user_enroled($params['targetplanid'], $userid, $studentRoleId);

                // Fire event.
                $event = \local_sc_learningplans\event\learningplanuser_added::create([
                    'context'      => \context_system::instance(),
                    'objectid'     => $record->id,
                    'relateduserid'=> $userid,
                    'other'        => ['learningPlanId' => $params['targetplanid'], 'roleId' => $studentRoleId],
                ]);
                $event->trigger();

                $results[] = ['userid' => $userid, 'fullname' => $fullname, 'status' => 'ok',
                              'message' => 'Matriculado exitosamente.'];
                $enrolled++;
            } catch (\Throwable $e) {
                $results[] = ['userid' => $userid, 'fullname' => $fullname, 'status' => 'error',
                              'message' => $e->getMessage()];
                $errors++;
            }
        }

        // Update usercount on the plan.
        if ($enrolled > 0) {
            $plan->usercount = (int)$plan->usercount + $enrolled;
            $DB->update_record('local_learning_plans', $plan);
        }

        return [
            'results'  => json_encode($results),
            'enrolled' => $enrolled,
            'skipped'  => $skipped,
            'errors'   => $errors,
            'message'  => '',
        ];
    }

    public static function execute_returns(): \external_description {
        return new external_single_structure([
            'results'  => new external_value(PARAM_RAW,  'JSON array of per-user results'),
            'enrolled' => new external_value(PARAM_INT,  'Number of successfully enrolled'),
            'skipped'  => new external_value(PARAM_INT,  'Number already enrolled (skipped)'),
            'errors'   => new external_value(PARAM_INT,  'Number of errors'),
            'message'  => new external_value(PARAM_TEXT, 'Global error message', VALUE_DEFAULT, ''),
        ]);
    }
}
