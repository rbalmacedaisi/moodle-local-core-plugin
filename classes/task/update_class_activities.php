<?php
namespace local_grupomakro_core\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

/**
 * Adhoc task to update class activities in background.
 */
class update_class_activities extends \core\task\adhoc_task {
    public function execute() {
        global $DB;
        $data = $this->get_custom_data();
        $classId = $data->classId;
        $updating = $data->updating;

        $class = $DB->get_record('gmk_class', ['id' => $classId]);
        if ($class) {
             create_class_activities($class, $updating);
        }
    }
}
