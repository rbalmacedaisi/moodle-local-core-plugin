<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('grupomakro_core_manage_courses');

echo $OUTPUT->header();
echo "<h1 class='text-2xl font-bold mb-4'>Diagnóstico de Cursos Solapados</h1>";

$periodid = optional_param('periodid', 0, PARAM_INT);

// 1. Get all institutional periods
$periods = $DB->get_records('gmk_academic_periods', [], 'startdate DESC');

echo "<div class='bg-light p-3 border mb-4 rounded'>";
echo "<form method='get'>";
echo "<label class='font-weight-bold'>Seleccione Periodo de Referencia (Planificado):</label><br>";
echo "<select name='periodid' class='form-control w-25 d-inline-block' onchange='this.form.submit()'>";
echo "<option value='0'>-- Seleccione --</option>";
foreach ($periods as $p) {
    $selected = ($p->id == $periodid) ? 'selected' : '';
    echo "<option value='$p->id' $selected>$p->name (" . date('d/m/Y', $p->startdate) . " - " . date('d/m/Y', $p->enddate) . ")</option>";
}
echo "</select>";
echo "</form>";
echo "</div>";

if ($periodid) {
    $basePeriod = $periods[$periodid];
    $start = $basePeriod->startdate;
    $end = $basePeriod->enddate;

    echo "<div class='alert alert-info'>";
    echo "Buscando cursos en OTROS periodos que solapen con <strong>$basePeriod->name</strong> (" . date('d/m/Y', $start) . " a " . date('d/m/Y', $end) . ")";
    echo "</div>";

    // 2. Find classes in gmk_class that overlap
    // Intersection condition: (s1 <= e2) AND (e1 >= s2)
    // Also include classes without a period (periodid = 0 or NULL)
    $sql = "SELECT c.*, p.name as period_name, u.firstname, u.lastname
            FROM {gmk_class} c
            LEFT JOIN {gmk_academic_periods} p ON p.id = c.periodid
            LEFT JOIN {user} u ON u.id = c.instructorid
            WHERE (c.periodid != :baseperiodid OR c.periodid IS NULL OR c.periodid = 0)
              AND c.initdate <= :enddate
              AND c.enddate >= :startdate
            ORDER BY c.initdate ASC";
    
    $overlappingClasses = $DB->get_records_sql($sql, [
        'baseperiodid' => $periodid,
        'startdate' => $start,
        'enddate' => $end
    ]);

    if ($overlappingClasses) {
        echo "<table class='table table-bordered table-striped table-hover'>";
        echo "<thead class='thead-dark'><tr>
                <th>ID</th>
                <th>Periodo Original</th>
                <th>Materia</th>
                <th>Instructor</th>
                <th>Estudiantes</th>
                <th>Inicio</th>
                <th>Fin</th>
                <th>Jornada</th>
                <th>Aula</th>
              </tr></thead>";
        echo "<tbody>";
        foreach ($overlappingClasses as $c) {
            $instructor = $c->firstname ? "$c->firstname $c->lastname" : "Sin asignar";
            $pName = $c->period_name ?: "<span class='badge badge-danger'>SIN PERIODO</span>";
            
            // Get students
            $students = $DB->get_records_sql("
                SELECT u.id, u.idnumber, u.firstname, u.lastname 
                FROM {user} u
                JOIN {gmk_class_queue} q ON q.userid = u.id
                WHERE q.classid = ?", [$c->id]);
            
            $stuList = [];
            foreach ($students as $s) {
                $stuList[] = "$s->firstname $s->lastname (" . ($s->idnumber ?: $s->id) . ")";
            }
            $stuDisplay = "<strong>" . count($stuList) . "</strong>";
            if (count($stuList) > 0) {
                $stuDisplay .= " <i class='fa fa-info-circle text-info' title='" . implode(", ", $stuList) . "'></i>";
                $stuDisplay .= "<br><small class='text-muted'>" . implode("<br>", array_slice($stuList, 0, 3)) . (count($stuList) > 3 ? "..." : "") . "</small>";
            }

            echo "<tr>";
            echo "<td>$c->id</td>";
            echo "<td>$pName</td>";
            echo "<td>$c->name</td>";
            echo "<td>$instructor</td>";
            echo "<td>$stuDisplay</td>";
            echo "<td>" . ($c->initdate ? date('d/m/Y', $c->initdate) : 'N/A') . "</td>";
            echo "<td>" . ($c->enddate ? date('d/m/Y', $c->enddate) : 'N/A') . "</td>";
            echo "<td>$c->shift</td>";
            
            // Resolve room
            $room = $DB->get_field_sql("SELECT r.name FROM {gmk_class_schedules} s JOIN {gmk_classrooms} r ON r.id = s.classroomid WHERE s.classid = ?", [$c->id]);
            echo "<td>" . ($room ?: 'Sin aula') . "</td>";
            echo "</tr>";
        }
        echo "</tbody></table>";
        echo "<p class='mt-2'>Total: <strong>" . count($overlappingClasses) . "</strong> cursos solapados encontrados.</p>";
    } else {
        echo "<div class='alert alert-warning'>No se encontraron cursos de otros periodos que solapen con este rango de fechas.</div>";
    }
}

echo $OUTPUT->footer();
