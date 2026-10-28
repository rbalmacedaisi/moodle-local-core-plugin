<?php
/**
 * Simula (por defecto) o aplica el avance de cuatrimestre/bimestre de los estudiantes activos.
 *
 *   php advance_academic_progress.php            -> simulacion, no escribe nada
 *   php advance_academic_progress.php --apply    -> aplica
 *   php advance_academic_progress.php --verbose  -> lista cada cambio
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params(['apply' => false, 'verbose' => false, 'help' => false], ['h' => 'help']);
if ($options['help']) {
    echo "Uso: php advance_academic_progress.php [--apply] [--verbose]\n";
    exit(0);
}
$sum = \local_grupomakro_core\local\academic_progress::run(!empty($options['apply']), function ($l) {
    mtrace($l);
});
mtrace(empty($options['apply']) ? 'SIMULACION (no se escribio nada)' : 'APLICADO');
$changes = $sum['changes'];
unset($sum['changes']);
mtrace(json_encode($sum));
if (!empty($options['verbose'])) {
    foreach ($changes as $c) {
        mtrace("usuario {$c['userid']} plan {$c['planid']} -> {$c['level']}");
    }
}
