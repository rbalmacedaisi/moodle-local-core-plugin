<?php
namespace local_grupomakro_core\external\admin;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use stdClass;

class scheduler extends external_api {

    // --- 1. Scheduler Context (Config) ---

    public static function get_scheduler_context_parameters() {
        return new external_function_parameters([
            'periodid' => new external_value(PARAM_INT, 'Academic Period ID')
        ]);
    }

    public static function get_scheduler_context($periodid) {
        global $DB;
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        // 1. Get Classrooms
        $classrooms = $DB->get_records('gmk_classrooms', ['active' => 1], 'name ASC');

        // 2. Get Holidays for this period
        $holidays = $DB->get_records('gmk_holidays', ['academicperiodid' => $periodid], 'date ASC');

        // 3. Get Subject Loads for this period
        $loads = $DB->get_records('gmk_subject_loads', ['academicperiodid' => $periodid], 'subjectname ASC');

        // 4. Get Period Record and Careers
        $periodRec = $DB->get_record('gmk_academic_periods', ['id' => $periodid]);
        $period = null;
        $configSettings = '';
        $careers = [];

        if ($periodRec) {
            $period = [
                'id' => $periodRec->id,
                'name' => $periodRec->name,
                'start' => date('Y-m-d', $periodRec->startdate),
                'end' => date('Y-m-d', $periodRec->enddate)
            ];
            $configSettings = $periodRec->configsettings ?: '';

            // Extract Careers from linked learning plans
            $lpIds = json_decode($periodRec->learningplans, true);
            if ($lpIds && is_array($lpIds)) {
                list($insql, $inparams) = $DB->get_in_or_equal($lpIds);
                $plans = $DB->get_records_select('local_learning_plans', "id $insql", $inparams, 'name ASC', 'id, name');
                foreach ($plans as $p) {
                    $careers[] = $p->name;
                }
            }
        }

        return [
            'classrooms' => array_values($classrooms),
            'holidays' => array_values($holidays),
            'loads' => array_values($loads),
            'period' => $period,
            'configSettings' => $configSettings,
            'careers' => $careers
        ];
    }

    public static function get_scheduler_context_returns() {
        return new external_single_structure([
            'classrooms' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'ID'),
                    'name' => new external_value(PARAM_TEXT, 'Name'),
                    'capacity' => new external_value(PARAM_INT, 'Capacity'),
                    'type' => new external_value(PARAM_TEXT, 'Type'),
                    'active' => new external_value(PARAM_INT, 'Active')
                ])
            ),
            'holidays' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'ID'),
                    'date' => new external_value(PARAM_INT, 'Timestamp'),
                    'name' => new external_value(PARAM_TEXT, 'Name'),
                    'type' => new external_value(PARAM_TEXT, 'Type')
                ])
            ),
            'loads' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'ID'),
                    'subjectname' => new external_value(PARAM_TEXT, 'Subject Name'),
                    'total_hours' => new external_value(PARAM_INT, 'Total Hours'),
                    'intensity' => new external_value(PARAM_FLOAT, 'Weekly Intensity', VALUE_OPTIONAL)
                ])
            ),
            'period' => new external_single_structure([
                'id' => new external_value(PARAM_INT, 'ID'),
                'name' => new external_value(PARAM_TEXT, 'Name'),
                'start' => new external_value(PARAM_TEXT, 'Start Date'),
                'end' => new external_value(PARAM_TEXT, 'End Date')
            ], 'Period metadata', VALUE_OPTIONAL),
            'configSettings' => new external_value(PARAM_RAW, 'JSON encoded config settings', VALUE_OPTIONAL),
            'careers' => new external_multiple_structure(new external_value(PARAM_TEXT, 'Career name'), 'List of career names', VALUE_OPTIONAL)
        ]);
    }

    // --- 2. Save Config ---

    public static function save_scheduler_config_parameters() {
        return new external_function_parameters([
            'periodid' => new external_value(PARAM_INT, 'Period ID'),
            'holidays' => new external_multiple_structure(
                new external_single_structure([
                    'date' => new external_value(PARAM_INT, ''),
                    'name' => new external_value(PARAM_TEXT, ''),
                    'type' => new external_value(PARAM_TEXT, '')
                ]),
                'List of holidays',
                VALUE_DEFAULT,
                []
            ),
            'loads' => new external_multiple_structure(
                new external_single_structure([
                    'subjectname' => new external_value(PARAM_TEXT, ''),
                    'total_hours' => new external_value(PARAM_INT, ''),
                    'intensity' => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, 0)
                ]),
                'List of subject loads',
                VALUE_DEFAULT,
                []
            ),
            'configsettings' => new external_value(PARAM_RAW, 'JSON string with shift/internal parameters', VALUE_DEFAULT, '')
        ]);
    }

    public static function save_scheduler_config($periodid, $holidays, $loads, $configsettings = '') {
        global $DB;
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $transaction = $DB->start_delegated_transaction();

        try {
            // Replace Holidays
            $DB->delete_records('gmk_holidays', ['academicperiodid' => $periodid]);
            foreach ($holidays as $h) {
                $rec = (object)$h;
                $rec->academicperiodid = $periodid;
                $rec->usermodified = $GLOBALS['USER']->id;
                $rec->timecreated = time();
                $rec->timemodified = time();
                $DB->insert_record('gmk_holidays', $rec);
            }

            // Replace Loads
            // Note: This wipes custom loads. Be careful if UI doesn't send all.
            // Assuming UI sends full list of configured loads.
            $DB->delete_records('gmk_subject_loads', ['academicperiodid' => $periodid]);
            foreach ($loads as $l) {
                $rec = (object)$l;
                $rec->academicperiodid = $periodid;
                $rec->usermodified = $GLOBALS['USER']->id;
                $rec->timecreated = time();
                $rec->timemodified = time();
                $DB->insert_record('gmk_subject_loads', $rec);
            }

            // Update configuration settings in period if provided
            if ($configsettings !== '') {
                $DB->set_field('gmk_academic_periods', 'configsettings', $configsettings, ['id' => $periodid]);
            }

            $transaction->allow_commit();
            return true;

        } catch (\Exception $e) {
            $transaction->rollback($e);
            return false;
        }
    }

    public static function save_scheduler_config_returns() {
        return new external_value(PARAM_BOOL, 'Success');
    }

    // --- 3. Demand Data ---

    public static function get_demand_data_parameters() {
        return new external_function_parameters([
            'periodid' => new external_value(PARAM_INT, 'Academic Period ID')
        ]);
    }

    public static function get_demand_data($periodid) {
        global $DB;
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        // A. Fetch Real Students & their pending subjects
        // Logic similar to planning::get_demand_analysis but we return flat list + grouped structure
        
        // 1. Get Students (Active, Enrolled)
        // Optimization: We could reuse planning filters, but here we want global demand for the period.
        // We assume students 'moved' to this period or planning to enroll.
        // Actually, we should look at 'currentperiodid' relative to their plan.
        
        $jornadaField = $DB->get_record('user_info_field', ['shortname' => 'gmkjourney']);
        $jornadaJoin = $jornadaField ? "LEFT JOIN {user_info_data} uid_j ON uid_j.userid = u.id AND uid_j.fieldid = " . $jornadaField->id : "";
        $jornadaSelect = $jornadaField ? ", uid_j.data AS jornada" : "";

        $sql = "SELECT u.id, u.firstname, u.lastname, lp.id as planid, lp.name as planname, 
                       llu.currentperiodid, p.name as currentperiodname,
                       llu.currentsubperiodid, sp.name as currentsubperiodname $jornadaSelect
                FROM {user} u
                JOIN {local_learning_users} llu ON llu.userid = u.id
                LEFT JOIN {local_learning_plans} lp ON lp.id = llu.learningplanid
                LEFT JOIN {local_learning_periods} p ON p.id = llu.currentperiodid
                LEFT JOIN {local_learning_subperiods} sp ON sp.id = llu.currentsubperiodid
                $jornadaJoin
                WHERE u.deleted = 0 AND u.suspended = 0 AND llu.userrolename = 'student' 
                  AND llu.status = 'activo'"; 

        $students = $DB->get_records_sql($sql);

        // 2. Pre-fetch Curricula WITH subperiod position
        $sql = "SELECT lpc.id, lpc.learningplanid, lpc.periodid, lpc.courseid, 
                       (COALESCE(sp.position + 1, 0)) as subperiod_pos
                FROM {local_learning_courses} lpc
                LEFT JOIN {local_learning_subperiods} sp ON sp.id = lpc.subperiodid";
        $plan_courses = $DB->get_records_sql($sql);
        
        $curricula = [];
        $curricula_subperiods = []; // To lookup subperiod for planning/projections
        foreach ($plan_courses as $pc) {
            $curricula[$pc->learningplanid][$pc->periodid][$pc->courseid] = $pc->subperiod_pos;
            $curricula_subperiods[$pc->learningplanid][$pc->courseid] = $pc->subperiod_pos;
        }

        // 3. Pre-fetch Passed Courses
        $progress_sql = "SELECT DISTINCT CONCAT(userid, '_', courseid) as id, 1 FROM {gmk_course_progre} WHERE status >= 3";
        $passed_map = $DB->get_records_sql_menu($progress_sql);

        // 4. Pre-fetch Period Names
        $period_names = $DB->get_records_menu('local_learning_periods', [], '', 'id, name');
        
        // 5. Build Demand
        $demand = []; 
        $student_list = [];

        foreach ($students as $stu) {
            if (!isset($curricula[$stu->planid])) continue;

            $career = $stu->planname;
            $jornada = $stu->jornada ?? 'Sin Jornada';
            
            // WAVE LOGIC: If student is in Bimestre II, they project to Next Level Bimestre I
            $levelId = $stu->currentperiodid;
            $subName = $stu->currentsubperiodname ?? '';
            $isBimestre2 = (stripos($subName, 'II') !== false || stripos($subName, '2') !== false);
            
            $planningLevelId = $levelId;
            if ($isBimestre2) {
                // Find next period in the same plan
                $nextPeriod = $DB->get_record_sql("SELECT id FROM {local_learning_periods} 
                                                  WHERE learningplanid = :planid AND id > :curid 
                                                  ORDER BY id ASC", 
                                                  ['planid' => $stu->planid, 'curid' => $levelId], 
                                                  IGNORE_MULTIPLE);
                if ($nextPeriod) {
                    $planningLevelId = $nextPeriod->id;
                }
            }
            
            $pending_count = 0;
            if (isset($curricula[$stu->planid][$planningLevelId])) {
                foreach ($curricula[$stu->planid][$planningLevelId] as $cid => $subpos) {
                     if (!isset($passed_map[$stu->id . '_' . $cid])) {
                         $pending_count++;
                         
                         $semName = $period_names[$planningLevelId] ?? 'Nivel ' . $planningLevelId;
                         $semNum = self::parse_semester_number($semName);
                         
                         if (!isset($demand[$career])) $demand[$career] = [];
                         if (!isset($demand[$career][$jornada])) $demand[$career][$jornada] = [];
                         if (!isset($demand[$career][$jornada][$semNum])) {
                             $demand[$career][$jornada][$semNum] = [
                                 'semester_name' => $semName,
                                 'student_count' => 0,
                                 'course_counts' => []
                             ];
                         }
                         
                         if (!isset($demand[$career][$jornada][$semNum]['course_counts'][$cid])) {
                             $demand[$career][$jornada][$semNum]['course_counts'][$cid] = [
                                 'count' => 0,
                                 'subperiod' => $subpos,
                                 'students' => []
                             ];
                         }
                         $demand[$career][$jornada][$semNum]['course_counts'][$cid]['count']++;
                         $demand[$career][$jornada][$semNum]['course_counts'][$cid]['students'][] = $stu->id;
                     }
                }
            }
            
            if ($pending_count > 0) {
                 $semName = $period_names[$planningLevelId] ?? 'Nivel ' . $planningLevelId;
                 $semNum = self::parse_semester_number($semName);
                 $demand[$career][$jornada][$semNum]['student_count']++;
                 
                 $student_list[] = [
                     'id' => $stu->id,
                     'name' => $stu->firstname . ' ' . $stu->lastname,
                     'career' => $career,
                     'shift' => $jornada,
                     'semester' => $semNum
                 ];
            }
        }

        // B. Fetch Manual Projections (New Entrants)
        $projections = $DB->get_records('gmk_academic_projections', ['academicperiodid' => $periodid]);
        
        foreach ($projections as $proj) {
            $career = $proj->career;
            $jornada = $proj->shift;
            $semNum = 1; 
            
            if (!isset($demand[$career])) $demand[$career] = [];
            if (!isset($demand[$career][$jornada])) $demand[$career][$jornada] = [];
             if (!isset($demand[$career][$jornada][$semNum])) {
                 $demand[$career][$jornada][$semNum] = [
                     'semester_name' => 'Nivel I',
                     'student_count' => 0,
                     'course_counts' => []
                 ];
             }
             
             if (!isset($demand[$career][$jornada][$semNum]['course_counts'][$cid])) {
                 $demand[$career][$jornada][$semNum]['course_counts'][$cid] = [
                     'count' => 0,
                     'subperiod' => $curricula_subperiods[$proj->learningplanid][$cid] ?? 0,
                     'students' => []
                 ];
             }
             
             $demand[$career][$jornada][$semNum]['course_counts'][$cid]['count'] += $proj->count;
             $demand[$career][$jornada][$semNum]['student_count'] += $proj->count;
        }

        // C. Fetch Planning Selections (from Planning Tab Matrix)
        $planning = $DB->get_records('gmk_academic_planning', ['academicperiodid' => $periodid]);
        $plan_names = $DB->get_records_menu('local_learning_plans', [], '', 'id, name');

        foreach ($planning as $pp) {
            $career = $plan_names[$pp->learningplanid] ?? 'Plan ' . $pp->learningplanid;
            $cid = $pp->courseid;
            $count = $pp->projected_students;
            
            // Note: gmk_academic_planning currently lacks 'shift'. 
            // We append to all shifts found for this career/level, or a default one (Matutina).
            if (isset($demand[$career])) {
                $semName = $period_names[$pp->periodid] ?? '';
                $semNum = self::parse_semester_number($semName);
                
                $shiftsToUpdate = array_keys($demand[$career]);
                if (empty($shiftsToUpdate)) $shiftsToUpdate = ['Matutina']; // Default if no real students found

                foreach ($shiftsToUpdate as $jornada) {
                    if (!isset($demand[$career][$jornada])) $demand[$career][$jornada] = [];
                    if (!isset($demand[$career][$jornada][$semNum])) {
                        $demand[$career][$jornada][$semNum] = [
                            'semester_name' => $semName ?: ('Nivel ' . $semNum),
                            'student_count' => 0,
                            'course_counts' => []
                        ];
                    }
                    if (!isset($demand[$career][$jornada][$semNum]['course_counts'][$cid])) {
                        $demand[$career][$jornada][$semNum]['course_counts'][$cid] = [
                            'count' => 0,
                            'subperiod' => $curricula_subperiods[$pp->learningplanid][$cid] ?? 0,
                            'students' => []
                        ];
                    }
                    $demand[$career][$jornada][$semNum]['course_counts'][$cid]['count'] += $count;
                    // If this is a manual projection with no students, we add to student_count too
                    if ($count > 0 && $demand[$career][$jornada][$semNum]['student_count'] < $count) {
                        $demand[$career][$jornada][$semNum]['student_count'] = $count;
                    }
                }
            }
        }

        return [
            'demand_tree' => json_encode($demand),
            'student_list' => $student_list,
            'projections' => array_values($projections)
        ];
    }

    public static function get_demand_data_returns() {
        return new external_single_structure([
            'demand_tree' => new external_value(PARAM_RAW, 'JSON nested structure [Career][Jornada][Sem]'),
            'student_list' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, ''),
                    'name' => new external_value(PARAM_TEXT, ''),
                    'career' => new external_value(PARAM_TEXT, ''),
                    'shift' => new external_value(PARAM_TEXT, ''),
                    'semester' => new external_value(PARAM_INT, '')
                ])
            ),
            'projections' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, ''),
                    'career' => new external_value(PARAM_TEXT, ''),
                    'shift' => new external_value(PARAM_TEXT, ''),
                    'count' => new external_value(PARAM_INT, '')
                ])
            )
        ]);
    }
    
    // --- 4. Save Projections ---
    
    public static function save_projections_parameters() {
        return new external_function_parameters([
            'periodid' => new external_value(PARAM_INT, ''),
            'projections' => new external_multiple_structure(
                new external_single_structure([
                    'career' => new external_value(PARAM_TEXT, ''),
                    'shift' => new external_value(PARAM_TEXT, ''),
                    'count' => new external_value(PARAM_INT, '')
                ])
            )
        ]);
    }
    
    public static function save_projections($periodid, $projections) {
        global $DB;
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);
        
        $DB->delete_records('gmk_academic_projections', ['academicperiodid' => $periodid]);
        
        foreach ($projections as $p) {
            $rec = (object)$p;
            $rec->academicperiodid = $periodid;
            $rec->usermodified = $GLOBALS['USER']->id;
            $rec->timecreated = time();
            $rec->timemodified = time();
            $DB->insert_record('gmk_academic_projections', $rec);
        }
        
        return true;
    }
    
    public static function save_projections_returns() {
        return new external_value(PARAM_BOOL, '');
    }
    
    // --- 5. Save Generated Schedule ---
    
    public static function save_generation_result_parameters() {
         return new external_function_parameters([
            'periodid' => new external_value(PARAM_INT, 'Academic Period ID'),
            'schedules' => new external_value(PARAM_RAW, 'JSON array of schedule objects')
         ]);
    }
    
    public static function save_generation_result($periodid, $schedules) {
        global $DB;
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);
        
        $data = is_string($schedules) ? json_decode($schedules, true) : $schedules;
        if (!is_array($data)) return 'El payload de horarios no es un array válido. Tipo recibido: ' . gettype($schedules);
        
        //$transaction = $DB->start_delegated_transaction();
        
        try {
            // Need to decide: Wipe existing classes for this period? 
            // Or Update?
            // "Save Generation" implies overwriting the schedule for this period.
            // But we must be careful not to delete classes that have grades/attendance if we are re-running mid-term.
            // Start of term -> Safe to wipe.
            
            // For now, let's iterate and Create/Update.
            // Assumption: Frontend sends 'id' if updating.
            
            foreach ($data as $cls) {
                $classRec = new stdClass();
                if (!empty($cls['id']) && is_numeric($cls['id'])) {
                    $classRec->id = $cls['id'];
                }
                
                $classRec->periodid = $periodid; // Linking to Calendar Period
                $classRec->courseid = $cls['courseid'];
                $classRec->instructorid = $cls['instructorid'] ?? 0;
                $classRec->name = $cls['name'] ?? 'Clase Auto'; 
                $classRec->groupid = 0; // Or passed group?
                
                // Common fields defaults
                $classRec->type = 0;
                $classRec->learningplanid = $cls['learningplanid'] ?? 0;
                $classRec->inittime = '';
                $classRec->endtime = '';
                $classRec->classdays = '0/0/0/0/0/0/0'; // Replaced by gmk_class_schedules
                $classRec->approved = 1;
                $classRec->active = 1;
                $classRec->timemodified = time();
                $classRec->usermodified = $GLOBALS['USER']->id;
                
                if (isset($classRec->id)) {
                    $DB->update_record('gmk_class', $classRec);
                    $classid = $classRec->id;
                } else {
                    $classRec->timecreated = time();
                    $classid = $DB->insert_record('gmk_class', $classRec);
                }
                
                // Save Schedule Details (Sessions/Stripes)
                if (isset($cls['sessions']) && is_array($cls['sessions'])) {
                    $DB->delete_records('gmk_class_schedules', ['classid' => $classid]);
                    
                    foreach ($cls['sessions'] as $sess) {
                        $sLink = new stdClass();
                        $sLink->classid = $classid;
                        $sLink->day = $sess['day']; // 'Monday', 'Tuesday'...
                        $sLink->start_time = $sess['start']; // '08:00'
                        $sLink->end_time = $sess['end'];
                        $sLink->classroomid = $sess['classroomid'] ?? null;
                        $sLink->usermodified = $GLOBALS['USER']->id;
                        $sLink->timecreated = time();
                        $sLink->timemodified = time();
                        
                        $DB->insert_record('gmk_class_schedules', $sLink);
                    }
                }
            }
            
            //$transaction->allow_commit();
            return true;
            
        } catch (\Exception $e) {
            //$transaction->rollback($e);
            return $e->getMessage();
        }
    }
    
    public static function save_generation_result_returns() {
        return new external_value(PARAM_BOOL, '');
    }

    // --- Helpers ---
    private static function parse_semester_number($name) {
        if (preg_match('/(I|II|III|IV|V|VI|VII|VIII|IX|X|XI|XII)$/i', $name, $matches)) {
            $romans = [
                'I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 
                'VI' => 6, 'VII' => 7, 'VIII' => 8, 'IX' => 9, 'X' => 10, 
                'XI' => 11, 'XII' => 12
            ];
            return $romans[strtoupper($matches[1])] ?? 0;
        }
        if (preg_match('/(\d+)/', $name, $matches)) {
            return (int)$matches[1];
        }
        return 0;
    }

    // --- 6. Fetch Generated Schedules ---
    public static function get_generated_schedules_parameters() {
        return new external_function_parameters([
            'periodid' => new external_value(PARAM_INT, 'Academic Period ID')
        ]);
    }

    public static function get_generated_schedules($periodid) {
        global $DB;
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $sql = "SELECT c.id, c.courseid, c.name as subjectName, c.instructorid, u.firstname, u.lastname,
                       lp.name as career, c.type, c.subperiodid as subperiod, c.groupid as subGroup, c.learningplanid
                FROM {gmk_class} c
                LEFT JOIN {user} u ON u.id = c.instructorid
                LEFT JOIN {local_learning_plans} lp ON lp.id = c.learningplanid
                WHERE c.periodid = :periodid";
        
        $classes = $DB->get_records_sql($sql, ['periodid' => $periodid]);
        $result = [];

        foreach ($classes as $c) {
            $sessions = $DB->get_records('gmk_class_schedules', ['classid' => $c->id]);
            $sessArr = [];
            foreach ($sessions as $s) {
                $sessArr[] = [
                    'day' => $s->day,
                    'start' => $s->start_time,
                    'end' => $s->end_time,
                    'classroomid' => $s->classroomid
                ];
            }

            // Estimate shift and level based on career/course if we had tight mapping,
            // but for generic visualization, we supply defaults if unknown.
            $result[] = [
                'id' => $c->id,
                'courseid' => $c->courseid,
                'subjectName' => $c->subjectname ?? 'Materia ' . $c->courseid,
                'teacherName' => ($c->instructorid && $c->firstname) ? ($c->firstname . ' ' . $c->lastname) : null,
                'day' => empty($sessArr) ? 'N/A' : $sessArr[0]['day'],
                'start' => empty($sessArr) ? '00:00' : $sessArr[0]['start'],
                'end' => empty($sessArr) ? '00:00' : $sessArr[0]['end'],
                'room' => empty($sessArr) || !$sessArr[0]['classroomid'] ? 'Sin aula' : $sessArr[0]['classroomid'],
                'studentCount' => 0, // Would need to query gmk_class_queue if used
                'career' => $c->career ?? 'General',
                'shift' => 'No Definida', 
                'levelDisplay' => 'Nivel X', 
                'subGroup' => $c->subgroup,
                'subperiod' => $c->subperiod,
                'sessions' => $sessArr
            ];
        }

        return $result;
    }

    public static function get_generated_schedules_returns() {
        return new external_value(PARAM_RAW, 'JSON representation of schedules array');
    }
}
