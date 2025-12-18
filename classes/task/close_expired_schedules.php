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
        global $DB;

        mtrace('Starting close_expired_schedules task...');

        // Current timestamp
        $now = time();

        // 1. Find classes that are NOT closed (closed = 0) AND enddate < NOW AND enddate > 0
        // We ensure enddate > 0 to avoid closing classes with no set enddate if that's a possibility,
        // though our default is 0. If enddate is 0, it means "no end date" or "not set", so we shouldn't close it?
        // Let's assume enddate > 0 is required for auto-closure.

        // 1. Find classes that are NOT closed (closed = 0) AND enddate < NOW AND enddate > 0
        $sql = "SELECT id, corecourseid, gradecategoryid FROM {gmk_class} WHERE closed = 0 AND enddate > 0 AND enddate < :now";
        
        $expired_classes = $DB->get_records_sql($sql, ['now' => $now]);
        $count = count($expired_classes);

        if ($count > 0) {
            mtrace("Found $count expired schedules. Closing and locking grades...");

            foreach ($expired_classes as $class) {
                try {
                    // Lock grades
                    // We need to include locallib if not already available, but scheduled tasks usually need explicit includes
                    // However, we are in the same plugin. Let's assume locallib needs to be required.
                    require_once($GLOBALS['CFG']->dirroot . '/local/grupomakro_core/locallib.php');
                    
                    toggle_class_grade_lock($class->id, true);

                    // Update database
                    $update_class = new \stdClass();
                    $update_class->id = $class->id;
                    $update_class->closed = 1;
                    $update_class->timemodified = $now;
                    
                    $DB->update_record('gmk_class', $update_class);
                    
                } catch (\Exception $e) {
                    mtrace("Error closing class ID {$class->id}: " . $e->getMessage());
                }
            }
            mtrace("Finished closing schedules.");
        } else {
            mtrace("No expired schedules found to close.");
        }

        mtrace('Task completed.');
    }
}
