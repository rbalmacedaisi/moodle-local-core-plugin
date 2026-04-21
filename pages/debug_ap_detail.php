<?php
require_once(__DIR__ . '/../../../config.php');
global $DB, $PAGE, $OUTPUT, $CFG;

$context = context_system::instance();
require_login();
require_capability('moodle/site:config', $context);

$PAGE->set_url('/local/grupomakro_core/pages/debug_ap_detail.php');
$PAGE->set_context($context);
$PAGE->set_title('Debug - Academic Planning Detail');
$PAGE->set_heading('Debug: gmk_academic_planning Detail Analysis');
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();

echo '<style>
body { font-family: "Segoe UI", Tahoma, sans-serif; background: #f8fafc; padding: 20px; }
.debug-container { max-width: 1400px; margin: 0 auto; }
h1 { color: #1e293b; border-bottom: 3px solid #3b82f6; padding-bottom: 10px; }
h2 { color: #334155; margin-top: 30px; border-left: 4px solid #3b82f6; padding-left: 12px; }
h3 { color: #475569; margin-top: 20px; }
.card { background: white; border-radius: 12px; padding: 20px; margin: 15px 0; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border: 1px solid #e2e8f0; }
table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 13px; }
th { background: #f1f5f9; padding: 10px; text-align: left; font-weight: 600; color: #334155; border-bottom: 2px solid #cbd5e1; position: sticky; top: 0; z-index: 10; }
td { padding: 8px 10px; border-bottom: 1px solid #e2e8f0; }
tr:hover { background: #f8fafc; }
.badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.badge-green { background: #dcfce7; color: #166534; }
.badge-red { background: #fee2e2; color: #991b1b; }
.badge-yellow { background: #fef3c7; color: #92400e; }
.badge-blue { background: #dbeafe; color: #1e40af; }
.badge-gray { background: #f1f5f9; color: #475569; }
pre { background: #1e293b; color: #e2e8f0; padding: 15px; border-radius: 8px; overflow-x: auto; font-size: 12px; }
.overflow-auto { overflow-x: auto; max-height: 500px; overflow-y: auto; }
</style>';

echo '<div class="debug-container">';
echo '<h1>Debug: Academic Planning Detail para 2026-III (P-II)</h1>';

echo '<div class="card">';
echo '<h2>Cursos en gmk_academic_planning con status != 2 (No Ignored)</h2>';
echo '<p>Estos son los cursos que DEBERIAN permitir que los estudiantes pasen los filtros en get_demand_data</p>';

$sql = "SELECT ap.*, c.fullname as coursename, lp.name as planname
        FROM {gmk_academic_planning} ap
        LEFT JOIN {course} c ON c.id = ap.courseid
        LEFT JOIN {local_learning_plans} lp ON lp.id = ap.learningplanid
        WHERE ap.academicperiodid = 4 AND ap.status != 2
        ORDER BY ap.courseid";

$nonIgnored = $DB->get_records_sql($sql);

echo '<p><span class="badge badge-green">' . count($nonIgnored) . '</span> cursos no ignorados en academicperiodid=4</p>';

if ($nonIgnored) {
    echo '<div class="overflow-auto">';
    echo '<table>';
    echo '<tr><th>ID</th><th>courseid</th><th>course name</th><th>learningplanid</th><th>plan name</th><th>status</th><th>projected_students</th></tr>';
    foreach ($nonIgnored as $ni) {
        $badgeClass = $ni->status == 1 ? 'badge-green' : 'badge-yellow';
        echo "<tr>
            <td>{$ni->id}</td>
            <td>{$ni->courseid}</td>
            <td><strong>{$ni->coursename}</strong></td>
            <td>{$ni->learningplanid}</td>
            <td>{$ni->planname}</td>
            <td><span class='badge {$badgeClass}'>{$ni->status}</span></td>
            <td>{$ni->projected_students}</td>
        </tr>";
    }
    echo '</table>';
    echo '</div>';
} else {
    echo '<p class="badge badge-red">NO HAY cursos no ignorados en academicperiodid=4!</p>';
}
echo '</div>';

echo '<div class="card">';
echo '<h2>Cursos en gmk_academic_planning con status = 1 (Confirmados/Disponibles)</h2>';

$sql2 = "SELECT ap.*, c.fullname as coursename, lp.name as planname
        FROM {gmk_academic_planning} ap
        LEFT JOIN {course} c ON c.id = ap.courseid
        LEFT JOIN {local_learning_plans} lp ON lp.id = ap.learningplanid
        WHERE ap.academicperiodid = 4 AND ap.status = 1
        ORDER BY ap.courseid";

$confirmed = $DB->get_records_sql($sql2);

echo '<p><span class="badge badge-green">' . count($confirmed) . '</span> cursos confirmados en academicperiodid=4</p>';

if ($confirmed) {
    echo '<div class="overflow-auto">';
    echo '<table>';
    echo '<tr><th>ID</th><th>courseid</th><th>course name</th><th>learningplanid</th><th>plan name</th><th>projected_students</th></tr>';
    foreach ($confirmed as $c) {
        echo "<tr>
            <td>{$c->id}</td>
            <td>{$c->courseid}</td>
            <td><strong>{$c->coursename}</strong></td>
            <td>{$c->learningplanid}</td>
            <td>{$c->planname}</td>
            <td>{$c->projected_students}</td>
        </tr>";
    }
    echo '</table>';
    echo '</div>';
}
echo '</div>';

echo '<div class="card">';
echo '<h2>¿Por qué los estudiantes NO pasan el filtro course_ignored?</h2>';
echo '<p>Un estudiante pasa el filtro si AL MENOS UNA de sus pending subjects:</p>';
echo '<ul>';
echo '<li>NO está en gmk_academic_planning con status=2 (no está ignorada)</li>';
echo '<li>O tiene status=1 (confirmada)</li>';
echo '</ul>';

$sql3 = "SELECT courseid, COUNT(*) as total 
         FROM {gmk_academic_planning} 
         WHERE academicperiodid = 4 AND status != 2 
         GROUP BY courseid 
         ORDER BY total DESC";
$coursesNotIgnored = $DB->get_records_sql($sql3);

$validCourseIds = [];
foreach ($coursesNotIgnored as $cn) {
    $validCourseIds[] = $cn->courseid;
}

echo '<p><strong>Cursos válidos (no ignored) en academicperiodid=4:</strong> ' . implode(', ', $validCourseIds) . '</p>';

if (empty($validCourseIds)) {
    echo '<p class="badge badge-red">NO HAY cursos válidos! Por eso ningún estudiante pasa.</p>';
}
echo '</div>';

echo '<div class="card">';
echo '<h2>Resumen</h2>';
echo '<table>';
echo '<tr><th>Metric</th><th>Value</th></tr>';
echo '<tr><td>Total registros en academicperiodid=4</td><td>' . $DB->count_records('gmk_academic_planning', ['academicperiodid' => 4]) . '</td></tr>';
echo '<tr><td>Registros con status=1 (confirmados)</td><td>' . $DB->count_records('gmk_academic_planning', ['academicperiodid' => 4, 'status' => 1]) . '</td></tr>';
echo '<tr><td>Registros con status=2 (ignorados)</td><td>' . $DB->count_records('gmk_academic_planning', ['academicperiodid' => 4, 'status' => 2]) . '</td></tr>';
echo '<tr><td>Registros con status != 2</td><td>' . count($nonIgnored) . '</td></tr>';
echo '</table>';
echo '</div>';

echo '</div>';
echo $OUTPUT->footer();