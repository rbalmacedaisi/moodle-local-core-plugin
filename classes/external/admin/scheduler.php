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

        return [
            'classrooms' => array_values($classrooms),
            'holidays' => array_values($holidays),
            'loads' => array_values($loads)
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
            )
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
                ])
            ),
            'loads' => new external_multiple_structure(
                new external_single_structure([
                    'subjectname' => new external_value(PARAM_TEXT, ''),
                    'total_hours' => new external_value(PARAM_INT, ''),
                    'intensity' => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, 0)
                ])
            )
        ]);
    }

    public static function save_scheduler_config($periodid, $holidays, $loads) {
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
                       llu.currentperiodid, p.name as currentperiodname $jornadaSelect
                FROM {user} u
                JOIN {local_learning_users} llu ON llu.userid = u.id
                LEFT JOIN {local_learning_plans} lp ON lp.id = llu.learningplanid
                LEFT JOIN {local_learning_periods} p ON p.id = llu.currentperiodid
                $jornadaJoin
                WHERE u.deleted = 0 AND u.suspended = 0 AND llu.userrolename = 'student' 
                  AND llu.status = 'activo'"; 
                  // Filter by academic period if relevant? 
                  // Maybe we only want students active in this 'academicperiodid' if we enforced migration.
                  // For now, get all active students.

        $students = $DB->get_records_sql($sql);

        // 2. Pre-fetch Curricula
        $plan_courses = $DB->get_records('local_learning_courses', [], 'learningplanid, periodid, position');
        $curricula = [];
        foreach ($plan_courses as $pc) {
            $curricula[$pc->learningplanid][$pc->periodid][] = $pc->courseid;
        }

        // 3. Pre-fetch Passed Courses
        $progress_sql = "SELECT CONCAT(userid, '_', courseid) as id, 1 FROM {gmk_course_progre} WHERE grade >= 71";
        $passed_map = $DB->get_records_sql_menu($progress_sql);

        // 4. Pre-fetch Period Names (for mapping numbers)
        $period_names = $DB->get_records_menu('local_learning_periods', [], '', 'id, name');
        
        // 5. Build Demand
        $demand = []; // [Career][Jornada][Semester] -> { students: [], courses: [] }
        $student_list = [];

        foreach ($students as $stu) {
            if (!isset($curricula[$stu->planid])) continue;

            $career = $stu->planname;
            $jornada = $stu->jornada ?? 'Sin Jornada';
            
            // Determine Student's "Semester" (Mode of pending subjects or currentperiodid)
            // Let's use currentperiodid as base, but check pending in that period.
            
            $pending_in_current = 0;
            $current_period_level = $stu->currentperiodid; 
            
            // Check courses in current period level
            if (isset($curricula[$stu->planid][$current_period_level])) {
                foreach ($curricula[$stu->planid][$current_period_level] as $cid) {
                     if (!isset($passed_map[$stu->id . '_' . $cid])) {
                         $pending_in_current++;
                         
                         // Add to global demand
                         $semName = $period_names[$current_period_level] ?? 'Nivel ' . $current_period_level;
                         $semNum = self::parse_semester_number($semName); // Helper
                         
                         // Init
                         if (!isset($demand[$career])) $demand[$career] = [];
                         if (!isset($demand[$career][$jornada])) $demand[$career][$jornada] = [];
                         if (!isset($demand[$career][$jornada][$semNum])) {
                             $demand[$career][$jornada][$semNum] = [
                                 'semester_name' => $semName,
                                 'student_count' => 0,
                                 'course_counts' => []
                             ];
                         }
                         
                         // Increment Course Count
                         if (!isset($demand[$career][$jornada][$semNum]['course_counts'][$cid])) {
                             $demand[$career][$jornada][$semNum]['course_counts'][$cid] = 0;
                         }
                         $demand[$career][$jornada][$semNum]['course_counts'][$cid]++;
                     }
                }
            }
            
            // Logic: If student has pending subjects in current level, they count for that level.
            // We simplify: One student counts for One level (their current one).
            // But they demand specific subjects.
            
            if ($pending_in_current > 0) {
                 $semName = $period_names[$current_period_level] ?? 'Nivel ' . $current_period_level;
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

        // B. Fetch Manual Projections
        $projections = $DB->get_records('gmk_academic_projections', ['academicperiodid' => $periodid]);
        
        // Merge Projections into Demand
        foreach ($projections as $proj) {
            $career = $proj->career;
            $jornada = $proj->shift; // Ensure naming consistency (shift/jornada)
            
            // Projections usually are for "Semester 1" (New Entrants)
            $semNum = 1; 
            
            if (!isset($demand[$career])) $demand[$career] = [];
            if (!isset($demand[$career][$jornada])) $demand[$career][$jornada] = [];
             if (!isset($demand[$career][$jornada][$semNum])) {
                 $demand[$career][$jornada][$semNum] = [
                     'semester_name' => 'Cuatrimestre I',
                     'student_count' => 0,
                     'course_counts' => [] // Need to fill with Semester 1 courses?
                 ];
             }
             
             $demand[$career][$jornada][$semNum]['student_count'] += $proj->count;
             // Note: Course counts for projections need to be inferred by Frontend or added here.
             // We'll leave it to frontend to multiply Projection Count * Semester 1 Courses.
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
        
        $data = json_decode($schedules, true);
        if (!is_array($data)) return false;
        
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
                if (!empty($cls['id'])) $classRec->id = $cls['id'];
                
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
            return false;
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
        // Fallback: look for digits
        if (preg_match('/(\d+)/', $name, $matches)) {
            return (int)$matches[1];
        }
        return 0;
    }
}
