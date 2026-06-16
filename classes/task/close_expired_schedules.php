<?php
namespace local_grupomakro_core\task;

use core\task\scheduled_task;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

class close_expired_schedules extends scheduled_task {

    /**
     * Get the name of the task.
     *
     * @return string
     */
    public function get_name() {
        // In a real plugin, this should be a language string.
        // For now we return a direct string or use get_string if we had it.
        return get_string('taskcloseexpiredschedules', 'local_grupomakro_core');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB, $CFG;

        mtrace('Starting close_expired_schedules task...');

        // Canonical closure logic (grade recalc + academic status + audit log + grade lock).
        require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

        // Current timestamp
        $now = time();

        // Find classes that have been expired for at least 1 week (7-day grace period before auto-closing).
        $threshold = $now - (7 * DAYSECS);
        $sql = "SELECT id, corecourseid FROM {gmk_class} WHERE closed = 0 AND enddate > 0 AND enddate < :threshold";

        $expired_classes = $DB->get_records_sql($sql, ['threshold' => $threshold]);
        $count = count($expired_classes);

        if ($count === 0) {
            mtrace("No expired schedules found to close.");
            mtrace('Task completed.');
            return;
        }

        mtrace("Found $count expired classes. Closing with full grade recalculation + academic status...");

        $closed = 0; $skipped = 0; $failed = 0;
        foreach ($expired_classes as $class) {
            try {
                // Same closure path as manage_courses.php (local_grupomakro_close_class_period):
                // validates weights, auto-marks pending attendance absent, computes final grades,
                // sets academic status in gmk_course_progre, writes gmk_class_closure_log, locks grades.
                $res = gmk_close_class_with_grade_recalc((int)$class->id);

                if (!empty($res['ok'])) {
                    $s = isset($res['summary']) ? $res['summary'] : [];
                    mtrace(sprintf("  class %d: CLOSED — approved=%d failed=%d revalid=%d no_grade=%d",
                        $class->id, $s['approved'] ?? 0, $s['failed'] ?? 0, $s['revalid'] ?? 0, $s['no_grade'] ?? 0));
                    $closed++;
                } else {
                    // Validation failed (e.g. weights != 100%, no gradable items, no CURSANDO students).
                    // Leave the class OPEN for manual review — never force a wrong close.
                    mtrace("  class {$class->id}: SKIPPED — " . ($res['error'] ?? 'unknown'));
                    $skipped++;
                }
            } catch (\Throwable $e) {
                mtrace("  class {$class->id}: ERROR — " . $e->getMessage());
                $failed++;
            }
        }

        mtrace("Finished. closed=$closed skipped=$skipped errors=$failed of $count.");
        mtrace('Task completed.');
    }
}
