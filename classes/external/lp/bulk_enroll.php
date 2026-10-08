<?php
namespace local_grupomakro_core\external\lp;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');
// Use the canonical sc_learningplans enrollment function (same as Odoo integration).
require_once($CFG->dirroot . '/local/sc_learningplans/external/user/add_learning_user.php');
// Progress manager is optional — only present in some environments.
$_pm = $CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php';
if (file_exists($_pm)) {
    require_once($_pm);
}

use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;

class bulk_enroll extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userids'      => new external_multiple_structure(
                new external_value(PARAM_INT, 'User ID'),
                'List of user IDs to enroll'
            ),
            'targetplanid' => new external_value(PARAM_INT,  'Target learning plan ID'),
            'periodid'     => new external_value(PARAM_INT,  'Target period ID (0 = none)', VALUE_DEFAULT, 0),
            'groupname'    => new external_value(PARAM_TEXT, 'Group name (optional)',        VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(array $userids, int $targetplanid, int $periodid = 0, string $groupname = ''): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userids'      => $userids,
            'targetplanid' => $targetplanid,
            'periodid'     => $periodid,
            'groupname'    => $groupname,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);

        $studentRoleId   = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
        $currentPeriodId = $params['periodid'] > 0 ? (int)$params['periodid'] : null;
        $group           = !empty($params['groupname']) ? $params['groupname'] : null;

        // Resolve active academic period — same logic as Odoo enroll_student.
        $now         = time();
        $twoMonths   = 60 * 24 * 3600;
        $acadRows    = $DB->get_records_sql(
            "SELECT * FROM {gmk_academic_periods}
              WHERE status = 1 AND startdate <= :now AND :now2 <= (startdate + :win)
              ORDER BY startdate DESC",
            ['now' => $now, 'now2' => $now, 'win' => $twoMonths],
            0, 1
        );
        $activeAcadId = $acadRows ? (int)reset($acadRows)->id : 0;

        $results  = [];
        $enrolled = 0;
        $skipped  = 0;
        $errors   = 0;

        foreach (array_unique($params['userids']) as $userid) {
            $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
            if (!$user) {
                $results[] = ['userid' => $userid, 'fullname' => "ID $userid",
                              'status' => 'error', 'message' => 'Usuario no encontrado.'];
                $errors++;
                continue;
            }
            $fullname = trim($user->firstname . ' ' . $user->lastname);

            try {
                // ── Step 1: enroll via the official sc_learningplans function ──────────────
                // This mirrors exactly what Odoo does and ensures role assignment,
                // course enrolment, dependencies, email and event are all handled correctly.
                $result = \add_learning_user_external::add_learning_user(
                    $params['targetplanid'],
                    $userid,
                    $studentRoleId,
                    $currentPeriodId,
                    $group
                );

                // ── Step 2: assign currentsubperiodid + academicperiodid ─────────────────
                // add_learning_user does not set these; Odoo sets them as a post-step.
                $llu = $DB->get_record('local_learning_users', ['id' => $result['id']]);
                if ($llu) {
                    // Resolve which period record to inspect for hassubperiods.
                    if ($currentPeriodId) {
                        $periodRecord = $DB->get_record('local_learning_periods',
                            ['id' => $currentPeriodId], 'id, hassubperiods');
                    } else {
                        $periodRecord = $DB->get_record_sql(
                            "SELECT id, hassubperiods FROM {local_learning_periods}
                              WHERE learningplanid = :planid ORDER BY id ASC LIMIT 1",
                            ['planid' => $params['targetplanid']]
                        );
                    }
                    // Only assign subperiod if the period actually has subperiods configured.
                    if ($periodRecord && !empty($periodRecord->hassubperiods)) {
                        $firstSp = $DB->get_record_sql(
                            "SELECT id FROM {local_learning_subperiods}
                              WHERE periodid = :pid ORDER BY position ASC, id ASC LIMIT 1",
                            ['pid' => $periodRecord->id]
                        );
                        if ($firstSp) {
                            $llu->currentsubperiodid = (int)$firstSp->id;
                        }
                    }
                    if ($activeAcadId) {
                        $llu->academicperiodid = $activeAcadId;
                    }
                    $DB->update_record('local_learning_users', $llu);
                }

                // ── Step 3: initialize progress grid for the target plan ──────────────────
                // create_learningplan_user_progress uses userid+courseid+learningplanid as
                // duplicate key, so it only creates records for the new plan's courses and
                // skips any that already exist (safe for students moving between plans).
                if (class_exists('local_grupomakro_progress_manager')) {
                    \local_grupomakro_progress_manager::create_learningplan_user_progress(
                        $userid, $params['targetplanid'], $studentRoleId
                    );
                }

                $results[] = ['userid' => $userid, 'fullname' => $fullname,
                              'status' => 'ok', 'message' => 'Matriculado exitosamente.'];
                $enrolled++;

            } catch (\moodle_exception $e) {
                if ($e->errorcode === 'learninguserexist') {
                    $results[] = ['userid' => $userid, 'fullname' => $fullname,
                                  'status' => 'skipped', 'message' => 'Ya está matriculado en este plan.'];
                    $skipped++;
                } else {
                    $results[] = ['userid' => $userid, 'fullname' => $fullname,
                                  'status' => 'error', 'message' => $e->getMessage()];
                    $errors++;
                }
            } catch (\Throwable $e) {
                $results[] = ['userid' => $userid, 'fullname' => $fullname,
                              'status' => 'error', 'message' => $e->getMessage()];
                $errors++;
            }
        }

        // Note: usercount is already updated inside add_learning_user for each successful enroll.

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
