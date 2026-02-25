<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('grupomakro_core_manage_courses');

echo $OUTPUT->header();
global $DB;

$periodid = optional_param('periodid', 1, PARAM_INT); // Default to 2026-I if possible

echo "<h1>Simulación de API local_grupomakro_get_generated_schedules</h1>";
echo "<p>Periodo: $periodid</p>";

// Simulate the logic in scheduler.php
$sql = "SELECT c.id, c.courseid, c.name as subjectName, c.instructorid, u.firstname, u.lastname,
               lp.name as career, c.type, c.typelabel, c.subperiodid as subperiod, c.groupid as subGroup, c.learningplanid,
               c.shift, c.level_label, c.career_label, c.periodid as institutional_period_id, c.corecourseid,
               c.initdate, c.enddate, c.inittime, c.endtime, c.classdays
        FROM {gmk_class} c
        LEFT JOIN {user} u ON u.id = c.instructorid
        LEFT JOIN {local_learning_plans} lp ON lp.id = c.learningplanid
        WHERE c.periodid = :periodid";

$params = ['periodid' => $periodid];

// Overlap logic
$period = $DB->get_record('gmk_academic_periods', ['id' => $periodid], 'startdate, enddate');
if ($period) {
    $sql .= " OR ((c.periodid != :periodid2 OR c.periodid IS NULL OR c.periodid = 0) 
                  AND c.initdate <= :enddate AND c.enddate >= :startdate)";
    $params['periodid2'] = $periodid;
    $params['startdate'] = $period->startdate;
    $params['enddate'] = $period->enddate;
}

$classes = $DB->get_records_sql($sql, $params);
$result = [];
$classrooms_cache = [];

foreach ($classes as $c) {
    if ($c->id != 125) continue; // Focus on 125 for now to avoid noise
    
    $sessions = $DB->get_records('gmk_class_schedules', ['classid' => $c->id]);
    $sessArr = [];
    foreach ($sessions as $s) {
        $roomName = 'Sin aula';
        if (!empty($s->classroomid)) {
            $roomName = $DB->get_field('gmk_classrooms', 'name', ['id' => $s->classroomid]);
        }
        $sessArr[] = [
            'day' => $s->day,
            'start' => $s->start_time,
            'end' => $s->end_time,
            'classroomid' => $s->classroomid,
            'roomName' => $roomName,
            'excluded_dates' => !empty($s->excluded_dates) ? json_decode($s->excluded_dates, true) : []
        ];
    }
    
    // Fallback logic
    if (empty($sessArr) && !empty($c->inittime) && $c->inittime !== '00:00') {
        $dayBitmask = explode('/', $c->classdays);
        $dayNames = ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado', 'Domingo'];
        foreach ($dayBitmask as $idx => $val) {
            if ($val == '1' && isset($dayNames[$idx])) {
                $sessArr[] = [
                    'day' => $dayNames[$idx],
                    'start' => $c->inittime,
                    'end' => $c->endtime,
                    'roomName' => 'Sin aula',
                    'excluded_dates' => []
                ];
            }
        }
    }

    $res = [
        'id' => (int)$c->id,
        'subjectName' => $c->subjectname ?? $c->name ?? $c->subjectName,
        'day' => empty($sessArr) ? 'N/A' : $sessArr[0]['day'],
        'start' => empty($sessArr) ? '00:00' : $sessArr[0]['start'],
        'end' => empty($sessArr) ? '00:00' : $sessArr[0]['end'],
        'isExternal' => (bool)($c->institutional_period_id != $periodid),
        'sessions' => $sessArr
    ];
    $result[] = $res;
}

echo "<h2>Resultados para Clase 125:</h2>";
echo "<pre>" . json_encode($result, JSON_PRETTY_PRINT) . "</pre>";

echo $OUTPUT->footer();
