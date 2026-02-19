<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/planning_manager.php');

use local_grupomakro_core\local\planning_manager;

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/debug_mapping.php'));
$PAGE->set_context($context);
$PAGE->set_title("Debug de Mapeo de Cohortes");
$PAGE->set_heading("Debug de Mapeo de Cohortes");

echo $OUTPUT->header();

global $DB;

// 1. Get Fields for Jornada and Entry Period
$jornadaField = $DB->get_record('user_info_field', ['shortname' => 'jornada']);
$periodoIngresoField = $DB->get_record('user_info_field', ['shortname' => 'periodo_ingreso']);

$jornadaSelect = ""; $jornadaJoin = "";
if ($jornadaField) {
    $jornadaJoin = "LEFT JOIN {user_info_data} uid_j ON uid_j.userid = u.id AND uid_j.fieldid = " . $jornadaField->id;
    $jornadaSelect = ", uid_j.data AS shift";
}

$piSelect = ""; $piJoin = "";
if ($periodoIngresoField) {
    $piJoin = "LEFT JOIN {user_info_data} uid_pi ON uid_pi.userid = u.id AND uid_pi.fieldid = " . $periodoIngresoField->id;
    $piSelect = ", uid_pi.data AS entry_period";
}

// 2. Main Query (Same as planning_manager.php but with more raw info)
$sql = "SELECT llu.id as subscriptionid, u.id, u.firstname, u.lastname, u.idnumber,
               lp.name as planname,
               p.name as periodname,
               sp.name as subperiodname
               $jornadaSelect
               $piSelect
        FROM {user} u
        JOIN {local_learning_users} llu ON llu.userid = u.id AND llu.userrolename = 'student'
        JOIN {local_learning_plans} lp ON lp.id = llu.learningplanid
        LEFT JOIN {local_learning_periods} p ON p.id = llu.currentperiodid
        LEFT JOIN {local_learning_subperiods} sp ON sp.id = llu.currentsubperiodid
        $jornadaJoin
        $piJoin
        WHERE u.deleted = 0 AND u.suspended = 0
        ORDER BY u.lastname ASC, u.firstname ASC";

$records = $DB->get_records_sql($sql);

echo "<h3>Segmentación de Estudiantes: RAW vs Mapeado</h3>";
echo "<p>Esta página muestra cómo el sistema interpreta las columnas <strong>Periodo Actual</strong> y <strong>Bimestre</strong> para agrupar a los alumnos.</p>";

echo "<style>
    table { width: 100%; border-collapse: collapse; margin-top: 20px; font-family: sans-serif; font-size: 13px; }
    th { background: #f4f4f4; padding: 10px; border: 1px solid #ddd; text-align: left; }
    td { padding: 8px; border: 1px solid #ddd; }
    .raw { color: #888; font-family: monospace; }
    .parsed { font-weight: bold; color: #2c3e50; }
    .destiny { background: #eefbff; font-weight: bold; color: #005a87; }
    .error { color: red; font-weight: bold; }
</style>";

echo "<table>";
echo "<thead>
    <tr>
        <th>Estudiante</th>
        <th>Plan / Carrera</th>
        <th>DB: Periodo (Raw)</th>
        <th>Parsed: Nivel</th>
        <th>DB: Bimestre (Raw)</th>
        <th>Lógica de Destino (Próx. Periodo)</th>
        <th>Cohorte Proyectada (Key)</th>
    </tr>
</thead>";
echo "<tbody>";

foreach ($records as $r) {
    $rawPeriod = $r->periodname ?: 'NULL';
    $rawSubperiod = $r->subperiodname ?: 'Bimestre I (Default)';
    
    // Simulating planning_manager::parse_semester_number exactly
    $levelNum = 1;
    if ($r->periodname) {
        $romans = ['X' => 10, 'IX' => 9, 'VIII' => 8, 'VII' => 7, 'VI' => 6, 'V' => 5, 'IV' => 4, 'III' => 3, 'II' => 2, 'I' => 1];
        $found = false;
        foreach ($romans as $rom => $v) {
            if (stripos($r->periodname, $rom) !== false) {
                $levelNum = $v;
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            if (preg_match('/\d+/', $r->periodname, $matches)) {
                $levelNum = (int)$matches[0];
            }
        }
    }

    // DESTINY LOGIC (As implemented in academic_planning.php)
    $isBimestre2 = stripos($rawSubperiod, 'II') !== false;
    
    $planningLevel = $levelNum;
    $planningBimestre = 'Bimestre II';
    
    if ($isBimestre2) {
        $planningLevel = $levelNum + 1;
        $planningBimestre = 'Bimestre I';
    }

    $cohortKey = "{$r->planname} - {$r->shift} - Nivel {$planningLevel} - Bimestre " . ($planningBimestre === 'Bimestre I' ? 'I' : 'II');

    echo "<tr>";
    echo "<td>{$r->firstname} {$r->lastname}<br><small>{$r->idnumber}</small></td>";
    echo "<td>{$r->planname}</td>";
    echo "<td class='raw'>$rawPeriod</td>";
    echo "<td class='parsed'>Nivel $levelNum</td>";
    echo "<td class='raw'>$rawSubperiod</td>";
    echo "<td class='destiny'>Ir a: Nivel $planningLevel, $planningBimestre</td>";
    echo "<td class='destiny' style='font-size: 11px;'>$cohortKey</td>";
    echo "</tr>";
}

echo "</tbody></table>";

echo $OUTPUT->footer();
