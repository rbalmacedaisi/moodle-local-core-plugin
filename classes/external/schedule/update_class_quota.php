<?php
namespace local_grupomakro_core\external\schedule;

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use context_system;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

class update_class_quota extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'classId' => new external_value(PARAM_INT, 'The class ID'),
            'newQuota' => new external_value(PARAM_INT, 'The new quota value'),
        ]);
    }

    public static function execute($classId, $newQuota) {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'classId' => $classId,
            'newQuota' => $newQuota,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        if ($params['newQuota'] < 1) {
            return ['status' => 'error', 'message' => 'El cupo debe ser al menos 1.', 'promoted' => 0];
        }

        $class = $DB->get_record('gmk_class', ['id' => $params['classId']], '*', MUST_EXIST);
        $oldQuota = (int)$class->classroomcapacity;
        $newQuota = (int)$params['newQuota'];

        // Update the quota
        $class->classroomcapacity = $newQuota;
        $class->timemodified = time();
        $class->usermodified = $USER->id;
        $DB->update_record('gmk_class', $class);

        $promoted = 0;

        // If quota increased, promote queue students to pre-registration
        if ($newQuota > $oldQuota) {
            $preRegisteredCount = $DB->count_records('gmk_class_pre_registration', ['classid' => $class->id]);
            $availableSlots = $newQuota - $preRegisteredCount;

            if ($availableSlots > 0) {
                // Get queued students ordered by timecreated (FIFO)
                $queuedStudents = $DB->get_records('gmk_class_queue', ['classid' => $class->id], 'timecreated ASC');

                foreach ($queuedStudents as $queuedStudent) {
                    if ($promoted >= $availableSlots) {
                        break;
                    }

                    // Move from queue to pre-registration
                    $DB->delete_records('gmk_class_queue', ['id' => $queuedStudent->id]);
                    add_user_to_class_pre_registry($queuedStudent->userid, $class);
                    $promoted++;
                }
            }
        }

        $msg = "Cupo actualizado de {$oldQuota} a {$newQuota}.";
        if ($promoted > 0) {
            $msg .= " Se promovieron {$promoted} estudiante(s) de la lista de espera a inscritos.";
        }

        return ['status' => 'ok', 'message' => $msg, 'promoted' => $promoted];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Status code'),
            'message' => new external_value(PARAM_TEXT, 'Message'),
            'promoted' => new external_value(PARAM_INT, 'Number of promoted students'),
        ]);
    }
}
