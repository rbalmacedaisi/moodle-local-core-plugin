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
        $jornadaGroupBy = $jornadaField ? ", uid_j.data" : "";

        $piField = $DB->get_record('user_info_field', ['shortname' => 'gmkperiodoingreso']);
        $piJoin = $piField ? "LEFT JOIN {user_info_data} uid_pi ON uid_pi.userid = u.id AND uid_pi.fieldid = " . $piField->id : "";
        $piSelect = $piField ? ", uid_pi.data AS entry_period" : ", '' as entry_period";
        $piGroupBy = $piField ? ", uid_pi.data" : "";

        $sql = "SELECT llu.id as recordid, u.id, u.idnumber, u.firstname, u.lastname, lp.id as planid, lp.name as planname, 
                       llu.currentperiodid, p.name as currentperiodname,
                       llu.currentsubperiodid, sp.name as currentsubperiodname $jornadaSelect $piSelect
                FROM {user} u
                JOIN {local_learning_users} llu ON llu.userid = u.id
                LEFT JOIN {local_learning_plans} lp ON lp.id = llu.learningplanid
                LEFT JOIN {local_learning_periods} p ON p.id = llu.currentperiodid
                LEFT JOIN {local_learning_subperiods} sp ON sp.id = llu.currentsubperiodid
                $jornadaJoin
                $piJoin
                WHERE u.deleted = 0 AND u.suspended = 0 AND llu.userrolename = 'student' 
                  AND llu.status = 'activo'
                GROUP BY llu.id, u.id, u.idnumber, u.firstname, u.lastname, lp.id, lp.name, 
                         llu.currentperiodid, p.name, llu.currentsubperiodid, sp.name $jornadaGroupBy $piGroupBy"; 

        $students = $DB->get_records_sql($sql);

        // 2. Pre-fetch Curricula WITH subperiod position
        $sql = "SELECT lpc.id, lpc.learningplanid, lpc.periodid, lpc.courseid, 
                       (COALESCE(sp.position + 1, 0)) as subperiod_pos
                FROM {local_learning_courses} lpc
                LEFT JOIN {local_learning_subperiods} sp ON sp.id = lpc.subperiodid";
        $plan_courses = $DB->get_records_sql($sql);
        
        $curricula = [];
        $curricula_subperiods = []; // To lookup subperiod for planning/projections
        $reverse_curricula = []; // To lookup Moodle ID from Subject ID
        foreach ($plan_courses as $pc) {
            $curricula[$pc->learningplanid][$pc->periodid][$pc->courseid] = [
                'subjectid' => $pc->id,
                'subperiod_pos' => $pc->subperiod_pos
            ];
            $curricula_subperiods[$pc->learningplanid][$pc->courseid] = $pc->subperiod_pos;
            $reverse_curricula[$pc->id] = $pc->courseid;
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
            $idToUse = !empty($stu->idnumber) ? $stu->idnumber : (string)$stu->id;
            
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
                              $resolvedSubjId = $subpos['subjectid'];
                              if (empty($resolvedSubjId)) {
                                  // Global fallback lookup if missing in curricular map
                                  $globalSubj = $DB->get_record('local_learning_courses', ['courseid' => $cid], 'id', IGNORE_MULTIPLE);
                                  $resolvedSubjId = $globalSubj ? $globalSubj->id : 0;
                                  if (empty($resolvedSubjId)) {
                                      gmk_log("WARNING: get_demand_data Part A - No se pudo resolver subjectid para Moodle Course $cid");
                                  }
                              }

                              $demand[$career][$jornada][$semNum]['course_counts'][$cid] = [
                                  'count' => 0,
                                  'subperiod' => $subpos['subperiod_pos'],
                                  'subjectid' => $resolvedSubjId,
                                  'levelid' => $planningLevelId,
                                  'students' => [],
                                  'plan_map' => []
                              ];
                          }
                         
                         if (!isset($demand[$career][$jornada][$semNum]['course_counts'][$cid]['plan_map'][$stu->planid])) {
                             $demand[$career][$jornada][$semNum]['course_counts'][$cid]['plan_map'][$stu->planid] = [
                                 'subjectid' => $resolvedSubjId,
                                 'levelid' => $planningLevelId
                             ];
                         }

                         $demand[$career][$jornada][$semNum]['course_counts'][$cid]['count']++;
                         $demand[$career][$jornada][$semNum]['course_counts'][$cid]['students'][] = $stu->id;
                     }
                }
            }
            
            $semName = $period_names[$planningLevelId] ?? 'Nivel ' . $planningLevelId;
            $semNum = self::parse_semester_number($semName);

            $student_list[] = [
                'id' => $idToUse,
                'dbId' => $stu->id,
                'name' => $stu->firstname . ' ' . $stu->lastname,
                'career' => $career,
                'planid' => $stu->planid,
                'shift' => $jornada,
                'semester' => $semNum,
                'entry_period' => $stu->entry_period ?? 'Sin Definir'
            ];

            if ($pending_count > 0) {
                $demand[$career][$jornada][$semNum]['student_count']++;
            }
        }

        // B. Fetch Manual Projections (New Entrants)
        $projections = $DB->get_records('gmk_academic_projections', ['academicperiodid' => $periodid]);
        
        foreach ($projections as $proj) {
            $career_name = $proj->career;
            $jornada = $proj->shift;
            $semNum = 1; 
            
            // Try to find the learning plan ID for this career name
            $lpId = array_search($career_name, $plan_names);
            if (!$lpId || !isset($curricula[$lpId])) continue;

            // Find Level 1 for this plan
            $level1 = $DB->get_record_sql("SELECT id, name FROM {local_learning_periods} WHERE learningplanid = ? ORDER BY id ASC", [$lpId], IGNORE_MULTIPLE);
            if (!$level1 || !isset($curricula[$lpId][$level1->id])) continue;

            if (!isset($demand[$career_name])) $demand[$career_name] = [];
            if (!isset($demand[$career_name][$jornada])) $demand[$career_name][$jornada] = [];
            if (!isset($demand[$career_name][$jornada][$semNum])) {
                $demand[$career_name][$jornada][$semNum] = [
                    'semester_name' => $level1->name,
                    'student_count' => 0,
                    'course_counts' => []
                ];
            }
            
            foreach ($curricula[$lpId][$level1->id] as $moodleId => $info) {
                if (!isset($demand[$career_name][$jornada][$semNum]['course_counts'][$moodleId])) {
                    $demand[$career_name][$jornada][$semNum]['course_counts'][$moodleId] = [
                        'count' => 0,
                        'subperiod' => $info['subperiod_pos'],
                        'subjectid' => $info['subjectid'],
                        'levelid' => $level1->id,
                        'students' => [],
                        'plan_map' => []
                    ];
                }
                if (!isset($demand[$career_name][$jornada][$semNum]['course_counts'][$moodleId]['plan_map'][$lpId])) {
                    $demand[$career_name][$jornada][$semNum]['course_counts'][$moodleId]['plan_map'][$lpId] = [
                        'subjectid' => $info['subjectid'],
                        'levelid' => $level1->id
                    ];
                }
                $demand[$career_name][$jornada][$semNum]['course_counts'][$moodleId]['count'] += $proj->count;
            }
            $demand[$career_name][$jornada][$semNum]['student_count'] += $proj->count;
        }

        // C. Fetch Planning Selections (from Planning Tab Matrix)
        $planning = $DB->get_records('gmk_academic_planning', ['academicperiodid' => $periodid]);
        $plan_names = $DB->get_records_menu('local_learning_plans', [], '', 'id, name');

        foreach ($planning as $pp) {
            $career = $plan_names[$pp->learningplanid] ?? 'Plan ' . $pp->learningplanid;
            $subjId = $pp->courseid; // gmk_academic_planning stores Subject ID
            $count = $pp->projected_students;
            
            // Map Subject ID to Moodle ID
            $moodleId = $reverse_curricula[$subjId] ?? 0;
            if (!$moodleId) continue;

            $semName = $period_names[$pp->periodid] ?? '';
            $semNum = self::parse_semester_number($semName);
            
            if (!isset($demand[$career])) $demand[$career] = [];

            // We apply to all shifts or Matutina
            $shiftsToUpdate = array_keys($demand[$career]);
            if (empty($shiftsToUpdate)) $shiftsToUpdate = ['Matutina'];

            foreach ($shiftsToUpdate as $jornada) {
                if (!isset($demand[$career][$jornada])) $demand[$career][$jornada] = [];
                if (!isset($demand[$career][$jornada][$semNum])) {
                    $demand[$career][$jornada][$semNum] = [
                        'semester_name' => $semName ?: ('Nivel ' . $semNum),
                        'student_count' => 0,
                        'course_counts' => []
                    ];
                }
                if (!isset($demand[$career][$jornada][$semNum]['course_counts'][$moodleId])) {
                    $demand[$career][$jornada][$semNum]['course_counts'][$moodleId] = [
                        'count' => 0,
                        'subperiod' => $curricula_subperiods[$pp->learningplanid][$moodleId] ?? 0,
                        'subjectid' => $subjId,
                        'levelid' => $pp->periodid,
                        'students' => [],
                        'plan_map' => []
                    ];
                }
                
                if (!isset($demand[$career][$jornada][$semNum]['course_counts'][$moodleId]['plan_map'][$pp->learningplanid])) {
                    $demand[$career][$jornada][$semNum]['course_counts'][$moodleId]['plan_map'][$pp->learningplanid] = [
                        'subjectid' => $subjId,
                        'levelid' => $pp->periodid
                    ];
                }

                $demand[$career][$jornada][$semNum]['course_counts'][$moodleId]['count'] += $count;
                if ($count > 0 && $demand[$career][$jornada][$semNum]['student_count'] < $count) {
                    $demand[$career][$jornada][$semNum]['student_count'] = $count;
                }
                
                if (empty($subjId)) {
                    gmk_log("WARNING: get_demand_data Part C - subjId es 0 para planning record " . $pp->id);
                }
            }
        }
        
        // Final sanity check
        gmk_log("DEBUG: get_demand_data finalizando. Demand careers: " . implode(',', array_keys($demand)));

        // Prepare subjects
        $course_names = $DB->get_records_menu('course', [], '', 'id, fullname');
        $subjects = [];
        foreach ($course_names as $id => $name) {
            $subjects[] = ['id' => $id, 'name' => $name];
        }

        return [
            'demand_tree' => json_encode($demand),
            'student_list' => $student_list,
            'projections' => array_values($projections),
            'subjects' => $subjects
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
                    'semester' => new external_value(PARAM_INT, ''),
                    'entry_period' => new external_value(PARAM_TEXT, '', VALUE_OPTIONAL)
                ])
            ),
            'projections' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, ''),
                    'career' => new external_value(PARAM_TEXT, ''),
                    'shift' => new external_value(PARAM_TEXT, ''),
                    'count' => new external_value(PARAM_INT, '')
                ])
            ),
            'subjects' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, ''),
                    'name' => new external_value(PARAM_TEXT, '')
                ]), 'List of all subjects', VALUE_OPTIONAL
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
        gmk_log("Iniciando guardado para Periodo Institucional: $periodid. Clases en payload: " . count($data));
        
        $transaction = $DB->start_delegated_transaction();
        
        try {
            // Need to decide: Wipe existing classes for this period? 
            // Or Update?
            // "Save Generation" implies overwriting the schedule for this period.
            // But we must be careful not to delete classes that have grades/attendance if we are re-running mid-term.
            // Start of term -> Safe to wipe.
            
            // For now, let's iterate and Create/Update.
            // Assumption: Frontend sends 'id' if updating.
            // To avoid duplicates while allowing updates:
            // 1. Identify existing numeric IDs in the payload
            $validIds = [];
            foreach ($data as $cls) {
                if (!empty($cls['id']) && is_numeric($cls['id'])) {
                    $validIds[] = $cls['id'];
                }
            }

            // 2. Delete classes for this period that are NOT in the payload (optional: only if you want full sync)
            if (!empty($validIds)) {
                $sql = "periodid = ? AND id NOT IN (" . implode(',', $validIds) . ")";
                $DB->delete_records_select('gmk_class', $sql, [$periodid]);
            } else {
                $DB->delete_records('gmk_class', ['periodid' => $periodid]);
            }

            $teachers_cache = [];
            $courses_cache = [];
            $classrooms_cache = [];
            
            $periodRec = $DB->get_record('gmk_academic_periods', ['id' => $periodid]);
            $periodStart = $periodRec ? $periodRec->startdate : time();
            $periodEnd = $periodRec ? $periodRec->enddate : time();

            foreach ($data as $cls) {
                $classRec = new stdClass();
                $isUpdate = false;
                if (!empty($cls['id']) && is_numeric($cls['id'])) {
                    $classRec->id = $cls['id'];
                    $isUpdate = true;
                }

                $courseId = $cls['courseid'];
                
                if (empty($courseId) || $courseId == "0") {
                    if (!empty($cls['subjectName'])) {
                        $subjByRef = $DB->get_record_sql("SELECT lc.id, lc.courseid FROM {local_learning_courses} lc 
                                                         JOIN {course} c ON c.id = lc.courseid 
                                                         WHERE c.fullname = ? OR c.shortname = ? 
                                                         ORDER BY lc.id DESC", [$cls['subjectName'], $cls['subjectName']], IGNORE_MULTIPLE);
                        if ($subjByRef) {
                            $courseId = $subjByRef->id;
                            // Also heal corecourseid if it's currently missing in the payload
                            if (empty($cls['corecourseid'])) {
                                $cls['corecourseid'] = $subjByRef->courseid;
                            }
                        }
                    }
                    if ((empty($courseId) || $courseId == "0") && !empty($cls['corecourseid'])) {
                        $subjByCore = $DB->get_record('local_learning_courses', ['courseid' => $cls['corecourseid']], 'id', IGNORE_MULTIPLE);
                        if ($subjByCore) $courseId = $subjByCore->id;
                    }
                    if (!empty($courseId) && $courseId != "0") {
                        gmk_log("HEALING: Resolved courseid " . $courseId . " for class: " . ($cls['subjectName'] ?? 'unnamed'));
                    }
                }
                
                $classRec->periodid = $periodid;
                $classRec->courseid = $courseId;
                $classRec->learningplanid = $cls['learningplanid'] ?? 0;
                $classRec->name = $cls['subjectName'] ?? 'Clase Auto';

                // Ensure we store the Subject ID (local_learning_courses.id) in courseid
                $classRec->courseid = $courseId; 
                // Core Moodle Course ID
                $classRec->corecourseid = $subjMeta ? $subjMeta->courseid : ($cls['corecourseid'] ?? 0);
                // Learning Plan
                $classRec->learningplanid = $cls['learningplanid'] ?? ($subjMeta ? $subjMeta->learningplanid : 0);
                
                // Note: periodid in DB stores the Institutional Period for filtering.
                // The Academic Level (Level ID) is derived by list_classes using courseid.
                $classRec->periodid = (int)$periodid; 
                
                // Lookup instructor ID prioritizing teacherName
                $tname = trim($cls['teacherName'] ?? '');
                if (!empty($tname) && !is_numeric($tname)) {
                    if (!array_key_exists($tname, $teachers_cache)) {
                        $sql = "SELECT id FROM {user} WHERE ".$DB->sql_concat('firstname', "' '", 'lastname')." = :name";
                        $tid = $DB->get_field_sql($sql, ['name' => $tname], IGNORE_MULTIPLE);
                        $teachers_cache[$tname] = $tid ?: 0;
                    }
                    $classRec->instructorid = $teachers_cache[$tname];
                } else {
                    $classRec->instructorid = $cls['instructorid'] ?? 0;
                }
                
                $classRec->groupid = $cls['subGroup'] ?? 0;
                $classRec->subperiodid = $cls['subperiod'] ?? 0;
                $classRec->type = $cls['type'] ?? 0; 
                $classRec->typelabel = $cls['typeLabel'] ?? 'Presencial';
                
                // Metadata Persistence
                $classRec->shift = $cls['shift'] ?? '';
                $classRec->level_label = $cls['levelDisplay'] ?? '';
                $classRec->career_label = $cls['career'] ?? '';

                $classRec->inittime = $cls['start'] ?? '';
                $classRec->endtime = $cls['end'] ?? '';
                $classRec->inithourformatted = $classRec->inittime ? date('h:i A', strtotime($classRec->inittime)) : '';
                $classRec->endhourformatted = $classRec->endtime ? date('h:i A', strtotime($classRec->endtime)) : '';
                
                if ($classRec->inittime && $classRec->endtime) {
                    $sTS = strtotime($classRec->inittime);
                    $eTS = strtotime($classRec->endtime);
                    $classRec->inittimets = (date('H', $sTS) * 3600) + (date('i', $sTS) * 60);
                    $classRec->endtimets = (date('H', $eTS) * 3600) + (date('i', $eTS) * 60);
                    $classRec->classduration = $classRec->endtimets - $classRec->inittimets;
                } else {
                    $classRec->inittimets = 0;
                    $classRec->endtimets = 0;
                    $classRec->classduration = 0;
                }

                // Date logic based on Subperiod (Block)
                $classRec->initdate = $periodStart;
                $classRec->enddate = $periodEnd;
                
                $calendar = $DB->get_record('gmk_academic_calendar', ['academicperiodid' => $periodid]);
                if ($calendar) {
                    if ($classRec->subperiodid == 1 && !empty($calendar->block1start)) {
                        $classRec->initdate = $calendar->block1start;
                        $classRec->enddate = $calendar->block1end;
                    } else if ($classRec->subperiodid == 2 && !empty($calendar->block2start)) {
                        $classRec->initdate = $calendar->block2start;
                        $classRec->enddate = $calendar->block2end;
                    }
                }

                $classRec->classdays = $cls['classdays'] ?? '0/0/0/0/0/0/0';
                $classRec->approved = 1;
                $classRec->active = 1;
                $classRec->timemodified = time();
                $classRec->usermodified = $GLOBALS['USER']->id;
                
                if ($isUpdate) {
                    $DB->update_record('gmk_class', $classRec);
                    $classid = $classRec->id;
                } else {
                    $classRec->timecreated = time();
                    $classid = $DB->insert_record('gmk_class', $classRec);
                }

                // Save Students to Queue
                $DB->delete_records('gmk_class_queue', ['classid' => $classid]);
                if (!empty($cls['studentIds']) && is_array($cls['studentIds'])) {
                    foreach ($cls['studentIds'] as $uid) {
                        $q = new stdClass();
                        $q->classid = $classid;
                        $q->userid = $uid;
                        $q->courseid = $cls['courseid'];
                        $q->timecreated = time();
                        $q->timemodified = time();
                        $q->usermodified = $GLOBALS['USER']->id;
                        $DB->insert_record('gmk_class_queue', $q);
                    }
                }
                
                // Save Schedule Details (Sessions/Stripes)
                $DB->delete_records('gmk_class_schedules', ['classid' => $classid]);
                $sessionsToSave = [];
                
                if (isset($cls['sessions']) && is_array($cls['sessions'])) {
                    $sessionsToSave = $cls['sessions'];
                } else if (!empty($cls['day']) && $cls['day'] !== 'N/A') {
                    $sessionsToSave[] = [
                        'day' => $cls['day'],
                        'start' => $cls['start'],
                        'end' => $cls['end'],
                        'classroomid' => (!empty($cls['room']) && $cls['room'] !== 'Sin aula') ? $cls['room'] : null,
                        'excluded_dates' => $cls['excluded_dates'] ?? null
                    ];
                }
                
                foreach ($sessionsToSave as $sess) {
                    $sLink = new stdClass();
                    $sLink->classid = $classid;
                    $sLink->day = $sess['day'];
                    $sLink->start_time = $sess['start']; 
                    $sLink->end_time = $sess['end'];
                    $sLink->classroomid = null;
                    
                    $cid = $sess['classroomid'] ?? null;
                    if (!empty($cid)) {
                        if (is_numeric($cid)) {
                            $sLink->classroomid = $cid;
                        } else {
                            $rname = trim($cid);
                            if (!array_key_exists($rname, $classrooms_cache)) {
                                $rid = $DB->get_field('gmk_classrooms', 'id', ['name' => $rname], IGNORE_MULTIPLE);
                                $classrooms_cache[$rname] = $rid ?: null;
                            }
                            $sLink->classroomid = $classrooms_cache[$rname];
                        }
                    }
                    $sLink->excluded_dates = !empty($sess['excluded_dates']) ? (is_array($sess['excluded_dates']) ? json_encode($sess['excluded_dates']) : $sess['excluded_dates']) : null;
                    $sLink->usermodified = $GLOBALS['USER']->id;
                    $sLink->timecreated = time();
                    $sLink->timemodified = time();
                    
                    $DB->insert_record('gmk_class_schedules', $sLink);
                }
            }
            gmk_log("Guardado exitoso para Periodo $periodid");
            $transaction->allow_commit();
            return true;
            
        } catch (\Exception $e) {
            $transaction->rollback($e);
            gmk_log("ERROR en save_generation_result: " . $e->getMessage());
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
                       lp.name as career, c.type, c.typelabel, c.subperiodid as subperiod, c.groupid as subGroup, c.learningplanid,
                       c.shift, c.level_label, c.career_label, c.periodid as institutional_period_id, c.corecourseid
                FROM {gmk_class} c
                LEFT JOIN {user} u ON u.id = c.instructorid
                LEFT JOIN {local_learning_plans} lp ON lp.id = c.learningplanid
                WHERE c.periodid = :periodid";
        
        $classes = $DB->get_records_sql($sql, ['periodid' => $periodid]);
        $result = [];
        
        $classrooms_cache = [];
        $subjects_metadata_cache = [];

        foreach ($classes as $c) {
            $sessions = $DB->get_records('gmk_class_schedules', ['classid' => $c->id]);
            $sessArr = [];
            foreach ($sessions as $s) {
                // Resolve classroom name
                $roomName = 'Sin aula';
                if (!empty($s->classroomid)) {
                    if (!isset($classrooms_cache[$s->classroomid])) {
                        $rinfo = $DB->get_field('gmk_classrooms', 'name', ['id' => $s->classroomid], IGNORE_MISSING);
                        $classrooms_cache[$s->classroomid] = $rinfo ? $rinfo : $s->classroomid;
                    }
                    $roomName = $classrooms_cache[$s->classroomid];
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
            
            // Derive Academic Metadata from Subject ID (courseid)
            $academic_period_id = 0;
            
            // HEALING: If courseid is 0 but we have corecourseid or name, try to resolve it
            if (empty($c->courseid) || $c->courseid == "0") {
                if (!empty($c->corecourseid)) {
                    $subjByCore = $DB->get_record('local_learning_courses', ['courseid' => $c->corecourseid], 'id, learningplanid, periodid', IGNORE_MULTIPLE);
                    if ($subjByCore) {
                        $c->courseid = $subjByCore->id;
                        $c->learningplanid = $subjByCore->learningplanid;
                        $academic_period_id = $subjByCore->periodid;
                    }
                }
                if ((empty($c->courseid) || $c->courseid == "0") && !empty($c->subjectname)) {
                    $subjByName = $DB->get_record_sql("SELECT lc.id, lc.learningplanid, lc.periodid, lc.courseid FROM {local_learning_courses} lc 
                                                       JOIN {course} co ON co.id = lc.courseid 
                                                       WHERE co.fullname = ? OR co.shortname = ? 
                                                       ORDER BY lc.id DESC", [$c->subjectname, $c->subjectname], IGNORE_MULTIPLE);
                    if ($subjByName) {
                        $c->courseid = $subjByName->id;
                        $c->learningplanid = $subjByName->learningplanid;
                        $academic_period_id = $subjByName->periodid;
                        $c->corecourseid = $subjByName->courseid;
                    }
                }
            }

            if (!empty($c->courseid) && $c->courseid != "0") {
                if (!isset($subjects_metadata_cache[$c->courseid])) {
                    $subj = $DB->get_record('local_learning_courses', ['id' => $c->courseid], 'id, learningplanid, periodid, courseid');
                    if (!$subj) {
                        $subj = $DB->get_record('local_learning_courses', ['courseid' => $c->courseid], 'id, learningplanid, periodid, courseid', IGNORE_MULTIPLE);
                    }
                    $subjects_metadata_cache[$c->courseid] = $subj ?: null;
                }
                
                $meta = $subjects_metadata_cache[$c->courseid];
                if ($meta) {
                    $c->learningplanid = $meta->learningplanid;
                    $academic_period_id = $meta->periodid;
                    $c->courseid = $meta->id;
                }
            }

            $subjectName = $c->subjectname ?? $c->name ?? ('Materia ' . $c->courseid);

            $result[] = [
                'id' => $c->id,
                'courseid' => $c->courseid,
                'subjectName' => $subjectName,
                'teacherName' => ($c->instructorid && !empty($c->firstname)) ? ($c->firstname . ' ' . $c->lastname) : null,
                'instructorid' => $c->instructorid,
                'day' => empty($sessArr) ? 'N/A' : $sessArr[0]['day'],
                'start' => empty($sessArr) ? '00:00' : $sessArr[0]['start'],
                'end' => empty($sessArr) ? '00:00' : $sessArr[0]['end'],
                'room' => empty($sessArr) ? 'Sin aula' : $sessArr[0]['roomName'],
                'corecourseid' => (int)($c->corecourseid ?? 0),
                'studentCount' => (int)$DB->count_records('gmk_class_queue', ['classid' => $c->id]),
                'studentIds' => array_values($DB->get_fieldset_select('gmk_class_queue', 'userid', 'classid = ?', [$c->id])),
                'career' => !empty($c->career_label) ? $c->career_label : ($c->career ?? 'General'),
                'shift' => !empty($c->shift) ? $c->shift : 'No Definida', 
                'levelDisplay' => !empty($c->level_label) ? $c->level_label : 'Nivel X', 
                'subGroup' => (int)($c->subgroup ?? 0),
                'subperiod' => (int)($c->subperiod ?? 1),
                'type' => (int)($c->type ?? 0),
                'typeLabel' => $c->typelabel ?? 'Presencial',
                'learningplanid' => (int)($c->learningplanid ?? 0),
                'periodid' => (int)($academic_period_id ?: ($c->institutional_period_id ?? 0)), 
                'sessions' => $sessArr
            ];
        }

        return $result;
    }

    public static function get_generated_schedules_returns() {
        return new external_value(PARAM_RAW, 'JSON representation of schedules array');
    }
}
