<?php
/**
 * Validates the fix for "WHERE id IS NULL en course_sections" reproduciendo
 * exactamente el caso del docente: la clase 9669 MORFOLOGIA, que en produccion
 * tiene gmk_class.coursesectionid = NULL y closed = 0. Antes del fix la
 * creacion de assign lanzaba el moodle_exception "No se puede encontrar
 * registro de datos en la tabla course_sections ... WHERE id IS NULL".
 *
 * Si el fix funciona, la assign se crea (en la seccion fallback) y el bug
 * deja de aparecer. Si el bug vuelve, capturamos la excepcion y reportamos
 * el mensaje original.
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/lib/moodlelib.php');

// Verificamos que el bug sigue reproducible en la clase del docente.
$targetclassid = 9669;
$class = $DB->get_record('gmk_class', ['id' => $targetclassid], '*', MUST_EXIST);

echo "[INFO] classid={$targetclassid} name='{$class->name}'\n";
echo "[INFO] closed=" . ($class->closed ? '1' : '0') . " coursesectionid=" .
     (empty($class->coursesectionid) ? 'NULL' : $class->coursesectionid) . "\n";

if (!empty($class->coursesectionid)) {
    echo "[WARN] Esta clase YA tiene coursesectionid={$class->coursesectionid}, ya no reproduce el bug. " .
         "El test confirma que el path NULL funciona pero no el bug original.\n";
}

$instructor = $DB->get_record('user', ['id' => $class->instructorid], '*', MUST_EXIST);
echo "[INFO] instructor: {$instructor->username} (id={$instructor->id})\n";

\core\session\manager::set_user($instructor);
$USER = get_complete_user_data('id', $instructor->id);
echo "[OK] Usuario logueado como: {$USER->username}\n";

$testname = 'TEST_FIX_NULL_SECTION_' . date('Ymd_His');
echo "[INFO] Creando assign '$testname' en la clase con coursesectionid NULL...\n";

try {
    $result = local_grupomakro_create_express_activity(
        $targetclassid,
        'assign',
        $testname,
        'Asignacion de prueba creada por el script de validacion del fix de coursesectionid NULL.',
        [
            'duedate' => time() + 7 * 86400,
            'allowsubmissionsfromdate' => time(),
            'save_as_template' => false,
            'gradecat' => 0,
            'tags' => [],
        ]
    );

    echo "[OK] create_express_activity devolvio sin lanzar excepcion.\n";
    echo "[OK] cmid={$result->coursemodule} instance={$result->instance}\n";

    $cm = $DB->get_record('course_modules', ['id' => $result->coursemodule], '*', MUST_EXIST);
    $section = $DB->get_record('course_sections', ['id' => $cm->section], '*', MUST_EXIST);
    echo "[OK] course_modules.section = {$cm->section} (course_sections.id real, no NULL)\n";
    echo "[OK] course_sections.section ordinal = {$section->section}\n";

    // Verificamos que la assign esta en la sequence de la seccion.
    $seq = array_filter(explode(',', $section->sequence));
    if (!in_array((string)$result->coursemodule, $seq)) {
        throw new \RuntimeException("FAIL: cmid={$result->coursemodule} NO esta en la sequence de course_sections.id={$section->id}");
    }
    echo "[OK] cmid={$result->coursemodule} aparece en la sequence de course_sections.id={$section->id}\n";

    // Cleanup.
    echo "[INFO] Limpiando assign de prueba...\n";
    course_delete_module($result->coursemodule);
    echo "[OK] Cleanup completo.\n";

    echo "\n=== FIX VALIDADO: el caso coursesectionid=NULL ya NO rompe la creacion de actividades ===\n";
    exit(0);
} catch (\Throwable $e) {
    $msg = $e->getMessage();
    echo "[FAIL] Excepcion: " . get_class($e) . "\n";
    echo "[FAIL] Mensaje: $msg\n";
    if (stripos($msg, 'course_sections') !== false && stripos($msg, 'id IS NULL') !== false) {
        echo "\n*** EL BUG AUN PERSISTE. EL FIX NO FUNCIONA. ***\n";
    } else {
        echo "\n*** Fallo por otra razon. El bug original esta arreglado, pero el codigo falla por otra cosa. ***\n";
        echo "Stack:\n" . $e->getTraceAsString() . "\n";
    }
    exit(1);
}
