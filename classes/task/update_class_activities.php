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

        // Notify user if userId is provided
        if (!empty($data->userId)) {
            $user = \core_user::get_user($data->userId);
            if ($user) {
                $message = new \core\message\message();
                $message->component = 'local_grupomakro_core';
                $message->name = 'classupdated'; // Define this in db/messages.php if stricter validation is needed, or use generic
                $message->userfrom = \core_user::get_noreply_user();
                $message->userto = $user;
                $message->subject = "Actualización de clase completada: " . $class->name;
                $message->fullmessage = "El proceso de actualización de horarios y actividades para la clase '{$class->name}' ha finalizado exitosamente.";
                $message->fullmessageformat = FORMAT_MARKDOWN;
                $message->fullmessagehtml = "<p>El proceso de actualización de horarios y actividades para la clase <strong>{$class->name}</strong> ha finalizado exitosamente.</p>";
                $message->smallmessage = "Clase '{$class->name}' actualizada.";
                $message->notification = 1;

                message_send($message);
            }
        }
    }
}
