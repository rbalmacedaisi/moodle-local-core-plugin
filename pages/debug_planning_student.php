<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/planning_manager.php');

global $DB, $PAGE, $OUTPUT, $CFG;

$context = context_system::instance();
require_login();
require_capability('moodle/site:config', $context);

$PAGE->set_url('/local/grupomakro_core/pages/debug_planning_student.php');
$PAGE->set_context($context);
$PAGE->set_title('Debug: Planificación de Estudiante');
$PAGE->set_heading('Diagnóstico de Filtrado de Estudiantes');
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();
?>

<style>
    .debug-container { max-width: 1400px; margin: 20px auto; font-family: 'Courier New', monospace; }
    .search-box { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
    .search-box input { padding: 10px; width: 300px; font-size: 14px; margin-right: 10px; }
    .search-box button { padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
    .search-box button:hover { background: #0056b3; }
    .student-info { background: #e7f3ff; padding: 15px; border-left: 4px solid #007bff; margin-bottom: 20px; }
    .section { background: white; padding: 20px; margin-bottom: 20px; border: 1px solid #dee2e6; border-radius: 8px; }
    .section h3 { margin-top: 0; color: #495057; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
    .course-row { padding: 10px; margin: 5px 0; border-radius: 4px; font-size: 13px; }
    .course-row.approved { background: #d4edda; border-left: 4px solid #28a745; }
    .course-row.in-progress { background: #fff3cd; border-left: 4px solid #ffc107; }
    .course-row.failed { background: #f8d7da; border-left: 4px solid #dc3545; }
    .course-row.available { background: #d1ecf1; border-left: 4px solid #17a2b8; }
    .course-row.no-available { background: #f8f9fa; border-left: 4px solid #6c757d; }
    .course-row.no-record { background: #e2e3e5; border-left: 4px solid #6c757d; }
    .status-badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-weight: bold; font-size: 11px; margin-left: 10px; }
    .status-0 { background: #6c757d; color: white; }
    .status-1 { background: #17a2b8; color: white; }
    .status-2 { background: #ffc107; color: black; }
    .status-3 { background: #28a745; color: white; }
    .status-4 { background: #28a745; color: white; }
    .status-5 { background: #dc3545; color: white; }
    .status-99 { background: #6f42c1; color: white; }
    .flag { display: inline-block; padding: 3px 6px; border-radius: 3px; font-size: 10px; margin-left: 5px; }
    .flag.yes { background: #28a745; color: white; }
    .flag.no { background: #dc3545; color: white; }
    .code-block { background: #f4f4f4; padding: 10px; border-left: 3px solid #007bff; margin: 10px 0; font-size: 12px; overflow-x: auto; }
    .prereq-list { color: #dc3545; font-size: 11px; margin-left: 20px; }
    table { width: 100%; border-collapse: collapse; font-size: 12px; }
    table th { background: #343a40; color: white; padding: 10px; text-align: left; }
    table td { padding: 8px; border-bottom: 1px solid #dee2e6; }
    table tr:hover { background: #f8f9fa; }
</style>

<div class="debug-container">
    <h1>🔍 Debug: Filtrado de Estudiantes en Planificación</h1>

    <div class="search-box">
        <h3>Buscar Estudiante</h3>
        <form method="GET">
            <input type="text" name="search" placeholder="Nombre o Cédula del estudiante" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" required>
            <button type="submit">🔎 Buscar</button>
        </form>
    </div>

<?php
$searchQuery = $_GET['search'] ?? '';

if (!empty($searchQuery)) {
    // Search for student
    $sql = "SELECT u.id, u.firstname, u.lastname, u.idnumber, u.email,
                   lp.id as planid, lp.name as planname,
                   p.id as periodid, p.name as periodname,
                   sp.id as subperiodid, sp.name as subperiodname
            FROM {user} u
            JOIN {local_learning_users} llu ON llu.userid = u.id AND llu.userrolename = 'student'
            JOIN {local_learning_plans} lp ON lp.id = llu.learningplanid
            LEFT JOIN {local_learning_periods} p ON p.id = llu.currentperiodid
            LEFT JOIN {local_learning_subperiods} sp ON sp.id = llu.currentsubperiodid
            WHERE u.deleted = 0
              AND llu.status = 'activo'
              AND (CONCAT(u.firstname, ' ', u.lastname) LIKE :name
                   OR u.idnumber LIKE :idnumber)
            LIMIT 1";

    $params = [
        'name' => '%' . $searchQuery . '%',
        'idnumber' => '%' . $searchQuery . '%'
    ];

    $student = $DB->get_record_sql($sql, $params);

    if ($student) {
        echo "<div class='student-info'>";
        echo "<h2>👤 Estudiante Encontrado</h2>";
        echo "<strong>Nombre:</strong> {$student->firstname} {$student->lastname}<br>";
        echo "<strong>Cédula/ID:</strong> " . ($student->idnumber ?: $student->id) . "<br>";
        echo "<strong>Email:</strong> {$student->email}<br>";
        echo "<strong>Plan:</strong> {$student->planname}<br>";
        echo "<strong>Nivel Actual:</strong> {$student->periodname}<br>";
        echo "<strong>Bimestre Actual:</strong> {$student->subperiodname}<br>";
        echo "</div>";

        // Get plan structure using reflection
        $reflectionClass = new ReflectionClass('\local_grupomakro_core\local\planning_manager');

        $methodStructure = $reflectionClass->getMethod('get_all_plans_structure');
        $methodStructure->setAccessible(true);
        $structures = $methodStructure->invoke(null);
        $planStructure = $structures[$student->planid] ?? [];

        $methodApproved = $reflectionClass->getMethod('get_all_approved_courses');
        $methodApproved->setAccessible(true);
        $approvedCourses = $methodApproved->invoke(null);

        $methodInProgress = $reflectionClass->getMethod('get_all_in_progress_courses');
        $methodInProgress->setAccessible(true);
        $inProgressCourses = $methodInProgress->invoke(null);

        $methodFailed = $reflectionClass->getMethod('get_all_failed_courses');
        $methodFailed->setAccessible(true);
        $failedCourses = $methodFailed->invoke(null);

        $methodMigration = $reflectionClass->getMethod('get_all_migration_pending_courses');
        $methodMigration->setAccessible(true);
        $migrationCourses = $methodMigration->invoke(null);

        $methodNoAvailable = $reflectionClass->getMethod('get_all_no_available_courses');
        $methodNoAvailable->setAccessible(true);
        $noAvailableCourses = $methodNoAvailable->invoke(null);

        $methodAvailable = $reflectionClass->getMethod('get_all_available_courses');
        $methodAvailable->setAccessible(true);
        $availableCourses = $methodAvailable->invoke(null);

        $studentApproved = $approvedCourses[$student->id] ?? [];
        $studentInProgress = $inProgressCourses[$student->id] ?? [];
        $studentFailed = $failedCourses[$student->id] ?? [];
        $studentMigration = $migrationCourses[$student->id] ?? [];
        $studentNoAvailable = $noAvailableCourses[$student->id] ?? [];
        $studentAvailable = $availableCourses[$student->id] ?? [];

        // Get raw status records from DB
        $rawStatuses = $DB->get_records('gmk_course_progre', ['userid' => $student->id], '', 'courseid, status, grade');

        // Build courseId -> fullname + shortname map for all courses in plan (for prereq display)
        $courseInfoMap = [];
        foreach ($planStructure as $c) {
            $courseInfoMap[$c->id] = $c->fullname;
        }
        // Also fetch shortnames for prereq raw value display
        $allCourseShortnames = $DB->get_records_sql(
            "SELECT id, shortname, fullname FROM {course} WHERE id IN (
                SELECT DISTINCT courseid FROM {local_learning_plan_courses} WHERE learningplanid = :planid
            )",
            ['planid' => $student->planid]
        );
        $idToShortname = [];
        foreach ($allCourseShortnames as $cs) {
            $idToShortname[$cs->id] = $cs->shortname;
            $courseInfoMap[$cs->id] = $cs->fullname; // fill any gaps
        }

        // Read raw prereq_shortnames from customfield_data for display
        $preFieldId = $DB->get_field('customfield_field', 'id', ['shortname' => 'pre']);
        $prereqRawMap = []; // courseId => raw string from customfield
        if ($preFieldId) {
            $rawPrereqData = $DB->get_records_sql(
                "SELECT cfd.instanceid as courseid, cfd.value FROM {customfield_data} cfd WHERE cfd.fieldid = :fid",
                ['fid' => $preFieldId]
            );
            foreach ($rawPrereqData as $rpd) {
                if (!empty($rpd->value)) {
                    $prereqRawMap[$rpd->courseid] = $rpd->value;
                }
            }
        }

        // Calculate target level using reflection
        $methodParseSemester = $reflectionClass->getMethod('parse_semester_number');
        $methodParseSemester->setAccessible(true);
        $currentLevel = $methodParseSemester->invoke(null, $student->periodname);

        $methodIsBimestre2 = $reflectionClass->getMethod('is_bimestre_two');
        $methodIsBimestre2->setAccessible(true);
        $isBimestre2 = $methodIsBimestre2->invoke(null, $student->subperiodname);

        $targetLevel = $isBimestre2 ? ($currentLevel + 1) : $currentLevel;

        echo "<div class='section'>";
        echo "<h3>📊 Cálculo de Nivel Objetivo</h3>";
        echo "<div class='code-block'>";
        echo "currentLevel = {$currentLevel}<br>";
        echo "isBimestre2 = " . ($isBimestre2 ? 'TRUE' : 'FALSE') . "<br>";
        echo "targetLevel = {$targetLevel}<br>";
        echo "<br><em>Fórmula: targetLevel = isBimestre2 ? (currentLevel + 1) : currentLevel</em>";
        echo "</div>";
        echo "</div>";

        // Show all courses with detailed status
        echo "<div class='section'>";
        echo "<h3>📚 Todas las Materias del Plan (Análisis Detallado)</h3>";
        echo "<table>";
        echo "<thead><tr>";
        echo "<th>Materia</th>";
        echo "<th>Nivel</th>";
        echo "<th>Bim</th>";
        echo "<th>Status DB</th>";
        echo "<th>Flags</th>";
        echo "<th>Filtros</th>";
        echo "<th>Resultado</th>";
        echo "</tr></thead>";
        echo "<tbody>";

        $includedCount = 0;
        $excludedCount = 0;

        foreach ($planStructure as $course) {
            $courseId = $course->id;
            $dbStatus = isset($rawStatuses[$courseId]) ? $rawStatuses[$courseId]->status : null;
            $dbGrade = isset($rawStatuses[$courseId]) ? $rawStatuses[$courseId]->grade : null;

            // Apply the SAME logic as planning_manager.php
            $isApproved = isset($studentApproved[$courseId]);
            $isInProgress = isset($studentInProgress[$courseId]);
            $isMigrationPending = isset($studentMigration[$courseId]);
            $isReprobada = isset($studentFailed[$courseId]);
            $isExplicitlyNoAvailable = isset($studentNoAvailable[$courseId]);
            $isExplicitlyAvailable = isset($studentAvailable[$courseId]);

            // Check exclusion
            $excluded = $isApproved || $isInProgress || $isMigrationPending || $isReprobada;

            // Check if has status record
            $hasStatusRecord = $isApproved || $isInProgress || $isMigrationPending ||
                               $isReprobada || $isExplicitlyNoAvailable || $isExplicitlyAvailable;

            // Check if should be excluded due to status not being 0 or 1
            $excludedByStatus = $hasStatusRecord && !$isExplicitlyNoAvailable && !$isExplicitlyAvailable;

            $finallyExcluded = $excluded || $excludedByStatus;

            // Check prerequisites — same logic as planning_manager.php lines 236-238:
            // met = approved (3,4) OR in-progress (2) OR migration-pending (99)
            $prereqsMet = true;
            $missingPrereqs = [];
            if (!empty($course->prereqs)) {
                foreach ($course->prereqs as $prereqId) {
                    $prereqMet = isset($studentApproved[$prereqId])
                              || isset($studentInProgress[$prereqId])
                              || isset($studentMigration[$prereqId]);
                    if (!$prereqMet) {
                        $prereqsMet = false;
                        $missingPrereqs[] = $prereqId;
                    }
                }
            }

            $isPriority = ($prereqsMet && $course->semester_num <= $targetLevel);

            // Determine row class
            $rowClass = '';
            if ($isApproved) $rowClass = 'approved';
            elseif ($isInProgress) $rowClass = 'in-progress';
            elseif ($isReprobada) $rowClass = 'failed';
            elseif ($isExplicitlyAvailable) $rowClass = 'available';
            elseif ($isExplicitlyNoAvailable) $rowClass = 'no-available';
            elseif ($dbStatus === null) $rowClass = 'no-record';

            echo "<tr>";
            echo "<td class='course-row {$rowClass}'><strong>{$course->fullname}</strong></td>";
            echo "<td>{$course->semester_num}</td>";
            echo "<td>{$course->bimestre}</td>";

            // Status column
            echo "<td>";
            if ($dbStatus !== null) {
                $statusLabel = '';
                switch ($dbStatus) {
                    case 0: $statusLabel = 'No Disponible'; break;
                    case 1: $statusLabel = 'Disponible'; break;
                    case 2: $statusLabel = 'En Curso'; break;
                    case 3: $statusLabel = 'Completada'; break;
                    case 4: $statusLabel = 'Aprobada'; break;
                    case 5: $statusLabel = 'Reprobada'; break;
                    case 99: $statusLabel = 'Migración'; break;
                    default: $statusLabel = 'Otro (' . $dbStatus . ')';
                }
                echo "<span class='status-badge status-{$dbStatus}'>{$dbStatus}: {$statusLabel}</span>";
                if ($dbGrade !== null) {
                    echo "<br><small>Nota: {$dbGrade}</small>";
                }
            } else {
                echo "<em style='color: #6c757d;'>Sin registro</em>";
            }
            echo "</td>";

            // Flags column
            echo "<td style='font-size: 10px;'>";
            echo "Aprobada: <span class='flag " . ($isApproved ? 'yes' : 'no') . "'>" . ($isApproved ? 'SÍ' : 'NO') . "</span><br>";
            echo "En Curso: <span class='flag " . ($isInProgress ? 'yes' : 'no') . "'>" . ($isInProgress ? 'SÍ' : 'NO') . "</span><br>";
            echo "Reprobada: <span class='flag " . ($isReprobada ? 'yes' : 'no') . "'>" . ($isReprobada ? 'SÍ' : 'NO') . "</span><br>";
            echo "Status 0: <span class='flag " . ($isExplicitlyNoAvailable ? 'yes' : 'no') . "'>" . ($isExplicitlyNoAvailable ? 'SÍ' : 'NO') . "</span><br>";
            echo "Status 1: <span class='flag " . ($isExplicitlyAvailable ? 'yes' : 'no') . "'>" . ($isExplicitlyAvailable ? 'SÍ' : 'NO') . "</span>";
            echo "</td>";

            // Filters column
            echo "<td style='font-size: 10px;'>";
            echo "Prereqs OK: <span class='flag " . ($prereqsMet ? 'yes' : 'no') . "'>" . ($prereqsMet ? 'SÍ' : 'NO') . "</span><br>";
            if (!empty($course->prereqs)) {
                foreach ($course->prereqs as $prereqId) {
                    $isPrereqApproved  = isset($studentApproved[$prereqId]);
                    $isPrereqInProg    = isset($studentInProgress[$prereqId]);
                    $isPrereqMigration = isset($studentMigration[$prereqId]);
                    $prereqOk = $isPrereqApproved || $isPrereqInProg || $isPrereqMigration;
                    $prereqName = $courseInfoMap[$prereqId] ?? "ID:{$prereqId}";
                    $reason = [];
                    if ($isPrereqApproved) $reason[] = 'Aprobada';
                    if ($isPrereqInProg)   $reason[] = 'En Curso';
                    if ($isPrereqMigration) $reason[] = 'Migración';
                    $reasonStr = $prereqOk ? implode('+', $reason) : '—no cumplida—';
                    $color = $prereqOk ? '#28a745' : '#dc3545';
                    echo "<div style='margin-left:8px; color:{$color};'>↳ " . htmlspecialchars($prereqName) . " [{$reasonStr}]</div>";
                }
            }
            if (!$prereqsMet) {
                // Show raw customfield value for this course to help debug shortname resolution
                $rawPre = $prereqRawMap[$courseId] ?? null;
                if ($rawPre) {
                    echo "<div class='prereq-list'>Raw pre field: " . htmlspecialchars($rawPre) . "</div>";
                }
            }
            echo "Nivel ≤ Target: <span class='flag " . (($course->semester_num <= $targetLevel) ? 'yes' : 'no') . "'>" . (($course->semester_num <= $targetLevel) ? 'SÍ' : 'NO') . "</span><br>";
            echo "isPriority: <span class='flag " . ($isPriority ? 'yes' : 'no') . "'>" . ($isPriority ? 'SÍ' : 'NO') . "</span>";
            echo "</td>";

            // Result column
            echo "<td style='font-weight: bold;'>";
            if ($finallyExcluded) {
                echo "<span style='color: #dc3545;'>❌ EXCLUIDA</span><br>";
                echo "<small style='color: #6c757d;'>";
                if ($isApproved) echo "Motivo: Aprobada<br>";
                if ($isInProgress) echo "Motivo: En Curso<br>";
                if ($isReprobada) echo "Motivo: Reprobada<br>";
                if ($isMigrationPending) echo "Motivo: Migración<br>";
                if ($excludedByStatus && !$excluded) echo "Motivo: Status no es 0 o 1<br>";
                echo "</small>";
                $excludedCount++;
            } else {
                echo "<span style='color: #28a745;'>✅ INCLUIDA</span><br>";
                echo "<small style='color: #6c757d;'>Se proyecta en demanda</small>";
                $includedCount++;
            }
            echo "</td>";
            echo "</tr>";
        }

        echo "</tbody>";
        echo "</table>";
        echo "<p><strong>Total Incluidas:</strong> {$includedCount} | <strong>Total Excluidas:</strong> {$excludedCount}</p>";
        echo "</div>";

        // ── Focused section: "No disponible" courses where prereqs seem met ──
        echo "<div class='section' style='border-left: 4px solid #dc3545;'>";
        echo "<h3>⚠️ Asignaturas 'No Disponible' con Prelaciones Aparentemente Cumplidas</h3>";
        echo "<p style='font-size:12px; color:#6c757d;'>Estas materias tienen status 0 en BD y sus prelaciones están aprobadas/en-curso/migración, pero NO aparecen como pendientes en la planificación. Aquí se detalla por qué.</p>";

        $noAvailableWithMetPrereqs = [];
        foreach ($planStructure as $course) {
            $courseId2 = $course->id;
            if (!isset($studentNoAvailable[$courseId2])) continue; // must be status 0

            // Check if prereqs are met
            $prereqsMetHere = true;
            foreach ($course->prereqs as $prereqId) {
                $prereqMet2 = isset($studentApproved[$prereqId])
                           || isset($studentInProgress[$prereqId])
                           || isset($studentMigration[$prereqId]);
                if (!$prereqMet2) { $prereqsMetHere = false; break; }
            }

            if ($prereqsMetHere) {
                $noAvailableWithMetPrereqs[] = $course;
            }
        }

        if (empty($noAvailableWithMetPrereqs)) {
            echo "<p style='color:#28a745;'>✅ No se detectaron casos problemáticos: todas las asignaturas 'No Disponible' con prelaciones cumplidas están correctamente excluidas o no existen.</p>";
        } else {
            echo "<table>";
            echo "<thead><tr><th>Materia</th><th>Nivel/Bim</th><th>Prelaciones</th><th>Status DB</th><th>Por qué NO es pendiente</th></tr></thead><tbody>";

            foreach ($noAvailableWithMetPrereqs as $course) {
                $courseId2 = $course->id;
                $dbStatus2 = isset($rawStatuses[$courseId2]) ? $rawStatuses[$courseId2]->status : null;

                echo "<tr style='background:#fff3cd;'>";
                echo "<td><strong>" . htmlspecialchars($course->fullname) . "</strong><br><small style='color:#6c757d;'>ID: {$courseId2}</small></td>";
                echo "<td>Nivel {$course->semester_num} / Bim {$course->bimestre}</td>";

                // Prereq detail
                echo "<td style='font-size:11px;'>";
                if (empty($course->prereqs)) {
                    echo "<em>Sin prelaciones</em>";
                } else {
                    foreach ($course->prereqs as $prereqId) {
                        $pName = $courseInfoMap[$prereqId] ?? "ID:{$prereqId}";
                        $pApproved  = isset($studentApproved[$prereqId])  ? '✅Aprobada' : '';
                        $pInProg    = isset($studentInProgress[$prereqId]) ? '✅En Curso' : '';
                        $pMig       = isset($studentMigration[$prereqId])  ? '✅Migración' : '';
                        $pStatus    = $pApproved . $pInProg . $pMig ?: '❌ Sin estado aprobatorio';
                        $pRawStatus = isset($rawStatuses[$prereqId]) ? 'DB status=' . $rawStatuses[$prereqId]->status : 'sin registro';
                        echo htmlspecialchars($pName) . "<br><span style='color:#6c757d;'>$pStatus ($pRawStatus)</span><br>";
                    }
                    // Also show raw prereq customfield
                    $rawPre2 = $prereqRawMap[$courseId2] ?? null;
                    if ($rawPre2) {
                        echo "<span style='color:#856404; font-style:italic;'>Raw: " . htmlspecialchars($rawPre2) . "</span>";
                    }
                }
                echo "</td>";

                echo "<td><span class='status-badge status-0'>0: No Disponible</span></td>";

                // Reason it won't appear as pending
                echo "<td style='font-size:11px;'>";
                echo "Status 0 = 'No Disponible' → la materia existe en <code>get_all_no_available_courses()</code>.<br>";
                echo "En planning_manager, status 0 excluye de <em>available</em> y <em>no_available</em> buckets.<br>";
                echo "<strong style='color:#dc3545;'>La materia NO se agrega a pendingSubjects porque su status en DB es 0 (bloqueada).</strong><br>";
                echo "Para que aparezca como pendiente, el status debe estar <em>sin registro</em> o en 1 (disponible).";
                echo "</td>";
                echo "</tr>";
            }
            echo "</tbody></table>";
        }
        echo "</div>";

        // Summary of SQL queries
        echo "<div class='section'>";
        echo "<h3>🔍 Consultas SQL Ejecutadas</h3>";
        echo "<div class='code-block'>";
        echo "<strong>1. Materias Aprobadas (status 3 o 4):</strong><br>";
        echo "SELECT id, userid, courseid FROM {gmk_course_progre} WHERE status IN (3, 4)<br><br>";

        echo "<strong>2. Materias En Curso (status 2):</strong><br>";
        echo "SELECT id, userid, courseid FROM {gmk_course_progre} WHERE status = 2<br><br>";

        echo "<strong>3. Materias Reprobadas (status 5):</strong><br>";
        echo "SELECT id, userid, courseid FROM {gmk_course_progre} WHERE status = 5<br><br>";

        echo "<strong>4. Materias No Disponibles (status 0):</strong><br>";
        echo "SELECT id, userid, courseid FROM {gmk_course_progre} WHERE status = 0<br><br>";

        echo "<strong>5. Materias Disponibles (status 1):</strong><br>";
        echo "SELECT id, userid, courseid FROM {gmk_course_progre} WHERE status = 1<br>";
        echo "</div>";
        echo "</div>";

        // Show raw DB records
        echo "<div class='section'>";
        echo "<h3>💾 Registros en gmk_course_progre para este estudiante</h3>";
        $allRecords = $DB->get_records_sql(
            "SELECT gcp.id, gcp.courseid, c.fullname, gcp.status, gcp.grade, gcp.timemodified
             FROM {gmk_course_progre} gcp
             JOIN {course} c ON c.id = gcp.courseid
             WHERE gcp.userid = :userid
             ORDER BY c.fullname",
            ['userid' => $student->id]
        );

        if ($allRecords) {
            echo "<table>";
            echo "<thead><tr><th>ID Registro</th><th>Materia</th><th>Status</th><th>Nota</th><th>Última Modificación</th></tr></thead>";
            echo "<tbody>";
            foreach ($allRecords as $rec) {
                $statusLabel = '';
                switch ($rec->status) {
                    case 0: $statusLabel = 'No Disponible'; break;
                    case 1: $statusLabel = 'Disponible'; break;
                    case 2: $statusLabel = 'En Curso'; break;
                    case 3: $statusLabel = 'Completada'; break;
                    case 4: $statusLabel = 'Aprobada'; break;
                    case 5: $statusLabel = 'Reprobada'; break;
                    case 99: $statusLabel = 'Migración'; break;
                    default: $statusLabel = 'Otro';
                }
                echo "<tr>";
                echo "<td>{$rec->id}</td>";
                echo "<td>{$rec->fullname}</td>";
                echo "<td><span class='status-badge status-{$rec->status}'>{$rec->status}: {$statusLabel}</span></td>";
                echo "<td>" . ($rec->grade !== null ? $rec->grade : '-') . "</td>";
                echo "<td>" . date('Y-m-d H:i:s', $rec->timemodified) . "</td>";
                echo "</tr>";
            }
            echo "</tbody>";
            echo "</table>";
        } else {
            echo "<p><em>No hay registros en gmk_course_progre para este estudiante.</em></p>";
        }
        echo "</div>";

    } else {
        echo "<div class='section' style='background: #f8d7da; border-left: 4px solid #dc3545;'>";
        echo "<h3>❌ No se encontró ningún estudiante</h3>";
        echo "<p>No se encontró ningún estudiante activo con el criterio de búsqueda: <strong>" . htmlspecialchars($searchQuery) . "</strong></p>";
        echo "</div>";
    }
}
?>

</div>

<?php
echo $OUTPUT->footer();
?>
