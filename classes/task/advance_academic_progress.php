<?php
namespace local_grupomakro_core\task;

use core\task\scheduled_task;
use local_grupomakro_core\local\academic_progress;

defined('MOODLE_INTERNAL') || die();

/**
 * Sube de cuatrimestre/bimestre a los estudiantes activos al iniciar cada franja de 2 meses.
 * Es idempotente: corre todos los dias y solo escribe cuando hay que subir de nivel.
 */
class advance_academic_progress extends scheduled_task {

    public function get_name() {
        return get_string('taskadvanceacademicprogress', 'local_grupomakro_core');
    }

    public function execute() {
        $sum = academic_progress::run(true, function ($line) {
            mtrace($line);
        });
        mtrace("Avance academico: evaluados={$sum['evaluados']} actualizados={$sum['actualizados']} "
            . "sin_cambio={$sum['sin_cambio']} adelantados={$sum['adelantados']} sin_ingreso={$sum['sin_ingreso']} "
            . "sin_estructura={$sum['sin_estructura']}");
    }
}
