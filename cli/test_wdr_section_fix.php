<?php
/**
 * Verifica el fix de "WHERE id IS NULL en course_sections" para
 * local_grupomakro_create_express_activity().
 *
 * Reproduce la llamada del Web Service de Express activity exactamente como la
 * hace ajax.php (es decir, bajo el usuario del instructor que dispara el boton).
 *
 * Si el bug esta arreglado, la assign se crea con course_sections.id (PK 889 en
 * este caso). Si el bug volviera, Moodle lanzara el moodle_exception con el
 * mensaje original "No se puede encontrar registro de datos en la tabla
 * course_sections ... WHERE id IS NULL".
 *
 * Se elimina la assign al final para no dejar basura.
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/lib/moodlelib.php');

// 1. Classid de prueba: GASES NOCIVOS (id=9468) del instructor av194763.
//    Esta clase tiene coursesectionid=889 y ordinal=1 en su seccion.
//    Bajo el bug, $moduleinfo->section se le pasaba como 1 (el ordinal).
$classid = 9468;
$username = 'av194763';

$user = $DB->get_record('user', ['username' => $username], '*', MUST_EXIST);
\core\session\manager::set_user($user);
$USER = get_complete_user_data('id', $user->id);
echo "[OK] Usuario logueado como: {$user->username} (id={$user->id})\n";

$class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);
$sec = $DB->get_record('course_sections', ['id' => $class->coursesectionid], '*', MUST_EXIST);
echo "[INFO] classid={$classid} corecourseid={$class->corecourseid} "
   . "coursesectionid={$class->coursesectionid} ordinal={$sec->section}\n";

$testname = 'TEST_RET01_FIX_' . date('Ymd_His');
echo "[INFO] Intentando crear assign '$testname'...\n";

try {
    $result = local_grupomakro_create_express_activity(
        $classid,
        'assign',
        $testname,
        'Asignacion de prueba creada por el script de validacion del fix de course_sections.',
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

    // Verifico que la assign termino en la seccion correcta (no NULL).
    $cm = $DB->get_record('course_modules', ['id' => $result->coursemodule], '*', MUST_EXIST);
    $section = $DB->get_record('course_sections', ['id' => $cm->section], '*', MUST_EXIST);

    if ((int)$cm->section !== (int)$class->coursesectionid) {
        throw new \RuntimeException("FAIL: la assign quedo en la seccion {$cm->section}, se esperaba {$class->coursesectionid}");
    }

    echo "[OK] course_modules.section = {$cm->section} coincide con gmk_class.coursesectionid\n";
    echo "[OK] course_sections.section ordinal = {$section->section} (coincide con ordinal esperado)\n";

    // Verifico que la assign aparece en la sequence de la seccion.
    $seq = array_filter(explode(',', $section->sequence));
    if (!in_array((string)$result->coursemodule, $seq)) {
        throw new \RuntimeException("FAIL: la assign cmid={$result->coursemodule} NO esta en la sequence de la seccion {$section->id} (sequence=" . $section->sequence . ')');
    }
    echo "[OK] cmid={$result->coursemodule} aparece en la sequence de course_sections.id={$section->id}\n";

    // 5. Cleanup: borro la assign + registros relacionados.
    echo "[INFO] Limpiando assign de prueba...\n";
    course_delete_module($result->coursemodule);
    echo "[OK] Cleanup completo.\n";

    echo "\n=== FIX VALIDADO: course_sections recibe el PK correcto, no el ordinal ===\n";
    exit(0);
} catch (\Throwable $e) {
    $msg = $e->getMessage();
    echo "[FAIL] Excepcion capturada: " . get_class($e) . "\n";
    echo "[FAIL] Mensaje: $msg\n";
    if (stripos($msg, 'course_sections') !== false && stripos($msg, 'id IS NULL') !== false) {
        echo "\n*** EL FIX NO FUNCIONA. EL BUG SIGUE PRESENTE. ***\n";
    } else {
        echo "\n*** Fallo por otra razon, no por el bug original. Revisar. ***\n";
    }
    exit(1);
}
