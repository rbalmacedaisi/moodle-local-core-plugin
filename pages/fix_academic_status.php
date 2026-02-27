<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Ensure the user is logged in and has proper capabilities
require_login();
admin_externalpage_setup('local_grupomakro_core_setup_page');

$action = optional_param('action', '', PARAM_ALPHA);

echo $OUTPUT->header();
echo $OUTPUT->heading('🔧 Inicializar Estados Académicos');

global $DB;

if ($action === 'initialize') {
    echo '<div style="padding: 20px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 5px; margin: 20px 0;">';
    echo '<h3>⏳ Procesando...</h3>';

    try {
        // Get all records where status is NULL or empty
        $sql = "SELECT id, userid, learningplanid, status
                FROM {local_learning_users}
                WHERE userrolename = :rolename
                AND (status IS NULL OR status = '')";

        $records = $DB->get_records_sql($sql, ['rolename' => 'student']);

        $count = 0;
        foreach ($records as $record) {
            $record->status = 'activo'; // Default value
            $DB->update_record('local_learning_users', $record);
            $count++;
        }

        echo '<div style="background: #d4edda; padding: 15px; border-radius: 5px; margin: 10px 0;">';
        echo "<p><strong>✅ Proceso completado exitosamente</strong></p>";
        echo "<p>Se actualizaron <strong>$count</strong> registros con el estado 'activo' por defecto.</p>";
        echo '</div>';

        // Show sample of updated records
        if ($count > 0) {
            echo '<h4>Muestra de registros actualizados:</h4>';
            echo '<table border="1" cellpadding="10" style="border-collapse: collapse; width: 100%;">';
            echo '<tr style="background: #f2f2f2;">';
            echo '<th>ID Usuario</th><th>Nombre</th><th>Plan</th><th>Nuevo Estado</th>';
            echo '</tr>';

            $sample = array_slice($records, 0, 10);
            foreach ($sample as $rec) {
                $user = $DB->get_record('user', ['id' => $rec->userid], 'firstname, lastname');
                $plan = $DB->get_record('local_learning_plans', ['id' => $rec->learningplanid], 'name');

                echo '<tr>';
                echo '<td>' . $rec->userid . '</td>';
                echo '<td>' . ($user ? $user->firstname . ' ' . $user->lastname : 'N/A') . '</td>';
                echo '<td>' . ($plan ? $plan->name : 'N/A') . '</td>';
                echo '<td style="color: green; font-weight: bold;">activo</td>';
                echo '</tr>';
            }

            echo '</table>';

            if ($count > 10) {
                echo '<p style="margin-top: 10px; color: #666;">... y ' . ($count - 10) . ' registros más.</p>';
            }
        }

    } catch (Exception $e) {
        echo '<div style="background: #f8d7da; padding: 15px; border-radius: 5px; margin: 10px 0;">';
        echo '<p><strong>❌ Error:</strong> ' . $e->getMessage() . '</p>';
        echo '</div>';
    }

    echo '</div>';

    echo '<p><a href="' . new moodle_url('/local/grupomakro_core/pages/fix_academic_status.php') . '" class="btn btn-secondary">← Volver</a></p>';

} else {
    // Show current status
    echo '<div style="background: #d1ecf1; padding: 20px; border-radius: 5px; margin: 20px 0;">';
    echo '<h3>📊 Estado Actual</h3>';

    $total = $DB->count_records('local_learning_users', ['userrolename' => 'student']);
    $empty = $DB->count_records_sql(
        "SELECT COUNT(*) FROM {local_learning_users}
         WHERE userrolename = :rolename AND (status IS NULL OR status = '')",
        ['rolename' => 'student']
    );
    $withStatus = $total - $empty;

    echo '<table style="width: 100%; margin: 15px 0;">';
    echo '<tr><td><strong>Total de estudiantes:</strong></td><td style="text-align: right; font-size: 1.2em;">' . $total . '</td></tr>';
    echo '<tr><td><strong>Con estado académico definido:</strong></td><td style="text-align: right; font-size: 1.2em; color: green;">' . $withStatus . '</td></tr>';
    echo '<tr><td><strong>Sin estado académico (vacío):</strong></td><td style="text-align: right; font-size: 1.2em; color: red;">' . $empty . '</td></tr>';
    echo '</table>';

    // Show breakdown by status
    if ($withStatus > 0) {
        echo '<h4>Distribución de estados:</h4>';
        $statuses = $DB->get_records_sql(
            "SELECT status, COUNT(*) as count
             FROM {local_learning_users}
             WHERE userrolename = :rolename AND status IS NOT NULL AND status != ''
             GROUP BY status",
            ['rolename' => 'student']
        );

        echo '<table border="1" cellpadding="8" style="border-collapse: collapse; margin: 10px 0;">';
        echo '<tr style="background: #f2f2f2;"><th>Estado</th><th>Cantidad</th></tr>';
        foreach ($statuses as $stat) {
            $color = 'black';
            if ($stat->status === 'activo') $color = 'green';
            elseif ($stat->status === 'aplazado') $color = 'orange';
            elseif ($stat->status === 'suspendido' || $stat->status === 'retirado') $color = 'red';

            echo '<tr>';
            echo '<td style="color: ' . $color . '; font-weight: bold;">' . $stat->status . '</td>';
            echo '<td style="text-align: right;">' . $stat->count . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }

    echo '</div>';

    // Show warning and action button
    if ($empty > 0) {
        echo '<div style="background: #fff3cd; padding: 20px; border: 1px solid #ffc107; border-radius: 5px; margin: 20px 0;">';
        echo '<h3>⚠️ Acción Requerida</h3>';
        echo '<p>Hay <strong>' . $empty . '</strong> estudiantes sin estado académico definido.</p>';
        echo '<p>Esta herramienta establecerá el estado <strong>"activo"</strong> por defecto para todos los estudiantes que tengan el campo vacío.</p>';
        echo '<p><strong>Nota:</strong> Después de ejecutar este proceso, podrás modificar el estado de cada estudiante individualmente desde el panel del Director Académico.</p>';

        echo '<form method="post" style="margin-top: 20px;">';
        echo '<input type="hidden" name="action" value="initialize">';
        echo '<button type="submit" class="btn btn-warning" style="font-size: 1.1em; padding: 10px 30px;" onclick="return confirm(\'¿Estás seguro de que deseas inicializar los estados académicos para ' . $empty . ' estudiantes?\');">
                🚀 Inicializar Estados Académicos
              </button>';
        echo '</form>';

        echo '</div>';
    } else {
        echo '<div style="background: #d4edda; padding: 20px; border-radius: 5px; margin: 20px 0;">';
        echo '<h3>✅ Todo está correcto</h3>';
        echo '<p>Todos los estudiantes tienen un estado académico definido.</p>';
        echo '</div>';
    }

    // Sample of students without status
    if ($empty > 0 && $empty <= 20) {
        echo '<h3>📋 Estudiantes sin estado académico:</h3>';
        $samples = $DB->get_records_sql(
            "SELECT llu.id, llu.userid, llu.learningplanid, llu.status
             FROM {local_learning_users} llu
             WHERE llu.userrolename = :rolename AND (llu.status IS NULL OR llu.status = '')
             LIMIT 20",
            ['rolename' => 'student']
        );

        echo '<table border="1" cellpadding="8" style="border-collapse: collapse; width: 100%; margin: 10px 0;">';
        echo '<tr style="background: #f2f2f2;"><th>ID Usuario</th><th>Nombre</th><th>Email</th><th>Plan</th><th>Estado Actual</th></tr>';

        foreach ($samples as $s) {
            $user = $DB->get_record('user', ['id' => $s->userid], 'firstname, lastname, email');
            $plan = $DB->get_record('local_learning_plans', ['id' => $s->learningplanid], 'name');

            echo '<tr>';
            echo '<td>' . $s->userid . '</td>';
            echo '<td>' . ($user ? $user->firstname . ' ' . $user->lastname : 'N/A') . '</td>';
            echo '<td>' . ($user ? $user->email : 'N/A') . '</td>';
            echo '<td>' . ($plan ? $plan->name : 'N/A') . '</td>';
            echo '<td style="color: red; font-weight: bold;">' . ($s->status ?: 'VACÍO') . '</td>';
            echo '</tr>';
        }

        echo '</table>';
    }
}

echo '<hr style="margin: 30px 0;">';
echo '<h3>ℹ️ Información</h3>';
echo '<div style="background: #e7f3ff; padding: 15px; border-radius: 5px;">';
echo '<p><strong>Estados Académicos Válidos:</strong></p>';
echo '<ul>';
echo '<li><span style="color: green; font-weight: bold;">activo</span> - Estudiante matriculado y cursando normalmente</li>';
echo '<li><span style="color: orange; font-weight: bold;">aplazado</span> - Estudiante con periodo de gracia o aplazamiento temporal</li>';
echo '<li><span style="color: red; font-weight: bold;">retirado</span> - Estudiante que se retiró de la institución</li>';
echo '<li><span style="color: red; font-weight: bold;">suspendido</span> - Estudiante suspendido temporalmente</li>';
echo '</ul>';
echo '<p><strong>¿Dónde se usa este campo?</strong></p>';
echo '<ul>';
echo '<li>Panel del Director Académico (edición inline con dropdown)</li>';
echo '<li>Reportes y estadísticas de estudiantes</li>';
echo '<li>Filtros de búsqueda por estado académico</li>';
echo '</ul>';
echo '</div>';

echo $OUTPUT->footer();
