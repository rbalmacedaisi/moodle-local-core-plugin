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

class planning extends external_api {

    /**
     * get_demand_analysis_parameters
     */
    public static function get_demand_analysis_parameters() {
        return new external_function_parameters(
            array(
                'periodid' => new external_value(PARAM_INT, 'The ID of the planified period', VALUE_DEFAULT, 0),
                'filters' => new external_value(PARAM_RAW, 'JSON filters (career, jornada, financial_status)', VALUE_DEFAULT, '')
            )
        );
    }

    /**
     * get_demand_analysis
     */
    public static function get_demand_analysis($periodid = 0, $filters = '') {
        global $DB;
        
        $params = self::validate_parameters(self::get_demand_analysis_parameters(), [
            'periodid' => $periodid,
            'filters' => $filters
        ]);
        
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);
        
        $filterData = json_decode($params['filters'], true) ?: [];
        
        // 1. Get all active students
        // We join with user_info_data to get "Jornada" if available
        $jornadaField = $DB->get_record('user_info_field', ['shortname' => 'gmkjourney']);
        $jornadaJoin = "";
        $jornadaSelect = "";
        
        if ($jornadaField) {
            $jornadaJoin = "LEFT JOIN {user_info_data} uid_j ON uid_j.userid = u.id AND uid_j.fieldid = " . $jornadaField->id;
            $jornadaSelect = ", uid_j.data AS jornada";
        }
        
        // Financial Status join
        $financialJoin = "LEFT JOIN {gmk_financial_status} fs ON fs.userid = u.id";
        $financialSelect = ", fs.status as financial_status";
        
        $sql = "SELECT llu.id as uniqid, u.id, u.firstname, u.lastname, lp.id as planid, lp.name as planname, 
                       llu.currentperiodid, p.name as currentperiodname $jornadaSelect $financialSelect
                FROM {user} u
                JOIN {local_learning_users} llu ON llu.userid = u.id
                LEFT JOIN {local_learning_plans} lp ON lp.id = llu.learningplanid
                LEFT JOIN {local_learning_periods} p ON p.id = llu.currentperiodid
                $jornadaJoin
                $financialJoin
                WHERE u.deleted = 0 AND u.suspended = 0 AND llu.userrolename = 'student'";
                
        $students = $DB->get_records_sql($sql);
        
        // 2. Fetch all curricula (courses per plan)
        // Grouped by Plan -> Period -> Course
        $curricula = [];
        $plan_courses = $DB->get_records('local_learning_courses', [], 'learningplanid, periodid, position');
        
        foreach ($plan_courses as $pc) {
            if (!isset($curricula[$pc->learningplanid])) {
                $curricula[$pc->learningplanid] = [];
            }
            // Resolve period name/code (e.g. Q1, Q2)
            // We assume periodid points to local_learning_periods
            if (!isset($curricula[$pc->learningplanid][$pc->periodid])) {
                $curricula[$pc->learningplanid][$pc->periodid] = [];
            }
            $curricula[$pc->learningplanid][$pc->periodid][] = $pc->courseid;
        }
        
        // Period Names Cache
        $period_names = $DB->get_records_menu('local_learning_periods', [], '', 'id, name');
        
        // Course Names Cache
        $course_names = $DB->get_records_menu('course', [], '', 'id, fullname');

        // 3. Demand Calculation & Student List
        // Structure: [PlanName][Jornada][PeriodName][CourseID] = Count
        $demand = [];
        $student_list = []; // NEW: For Impact Analysis
        
        // Pre-fetch all passed courses for these students to avoid N+1
        // ... (existing code for progress_sql) ...
        $progress_sql = "SELECT DISTINCT CONCAT(userid, '_', courseid) as id, 1 
                         FROM {gmk_course_progre} 
                         WHERE grade >= 71";
        $passed_map = $DB->get_records_sql_menu($progress_sql);
        
        // Build Demand
        foreach ($students as $stu) {
            
            // Apply Filters (PHP side)
            if (!empty($filterData['jornada']) && stripos(($stu->jornada ?? ''), $filterData['jornada']) === false) continue;
            if (!empty($filterData['career']) && $stu->planname != $filterData['career']) continue; // Use planname match or ID
            // ... (financial check) ...

            $planid = $stu->planid;
            $jornada = !empty($stu->jornada) ? $stu->jornada : 'Sin Jornada';
            
            if (!isset($curricula[$planid])) continue; 
            
            $stuDetails = [
                'id' => $stu->id,
                'name' => $stu->firstname . ' ' . $stu->lastname,
                'career' => $stu->planname,
                'shift' => $jornada,
                'currentSem' => $stu->currentperiodname, // Or numeric logic
                'theoreticalSem' => 0, // Calculated below
                'pendingSubjects' => [],
                'semesters' => [] // For mode calculation
            ];
            
            foreach ($curricula[$planid] as $perId => $courseIds) {
                $perName = $period_names[$perId] ?? 'Periodo ' . $perId;
                
                foreach ($courseIds as $cid) {
                    $key = $stu->uniqid . '_' . $cid; // OLD key was u.id, check if passed uses u.id
                    // passed_map keys are "userid_courseid". Correct.
                    $passKey = $stu->id . '_' . $cid;
                    
                    if (!isset($passed_map[$passKey])) {
                        // PENDING!
                        
                        // 1. Aggregate
                        if (!isset($demand[$planid])) $demand[$planid] = ['name' => $stu->planname, 'jornadas' => []];
                        if (!isset($demand[$planid]['jornadas'][$jornada])) $demand[$planid]['jornadas'][$jornada] = [];
                        if (!isset($demand[$planid]['jornadas'][$jornada][$perId])) {
                            $demand[$planid]['jornadas'][$jornada][$perId] = [
                                'period_name' => $perName,
                                'courses' => []
                            ];
                        }
                        if (!isset($demand[$planid]['jornadas'][$jornada][$perId]['courses'][$cid])) {
                            $demand[$planid]['jornadas'][$jornada][$perId]['courses'][$cid] = [
                                'id' => $cid,
                                'name' => $course_names[$cid] ?? 'Curso ' . $cid,
                                'count' => 0,
                                'relative_period_id' => $perId // Store relative ID for saving
                            ];
                        }
                        $demand[$planid]['jornadas'][$jornada][$perId]['courses'][$cid]['count']++;
                        
                        // 2. Student Detail
                        $stuDetails['pendingSubjects'][] = [
                            'name' => $course_names[$cid] ?? 'Curso ' . $cid,
                            'semester' => $perName,
                            'periodId' => $perId,
                            'isPriority' => $perId <= $stu->currentperiodid // Simplified logic
                        ];
                        
                        // Track semester for cohort calculation (Mode)
                        if (!isset($stuDetails['semesters'][$perId])) $stuDetails['semesters'][$perId] = 0;
                        $stuDetails['semesters'][$perId]++;
                    }
                }
            }
            
            // Calculate Theoretical Semester (Mode)
            if (!empty($stuDetails['semesters'])) {
                arsort($stuDetails['semesters']);
                $stuDetails['theoreticalSem'] = array_key_first($stuDetails['semesters']);
                $stuDetails['theoreticalSemName'] = $period_names[$stuDetails['theoreticalSem']] ?? $stuDetails['theoreticalSem'];
            }
            
            $student_list[] = $stuDetails;
        }
        
        // ... (selections loading) ...
        $selections = [];
        if ($periodid > 0) {
             $plans = $DB->get_records('gmk_academic_planning', ['academicperiodid' => $periodid]);
             foreach ($plans as $p) {
                 $selections[$p->learningplanid . '_' . $p->courseid] = true;
             }
        }
        
        return [
            'demand' => json_encode($demand),
            'students' => json_encode($student_list),
            'selections' => $selections
        ];
    }

    public static function get_demand_analysis_returns() {
        return new external_single_structure(
            array(
                'demand' => new external_value(PARAM_RAW, 'JSON structure of demand'),
                'students' => new external_value(PARAM_RAW, 'JSON structure of students'),
                'selections' => new external_multiple_structure(new external_value(PARAM_BOOL), 'Map of selected courses', VALUE_OPTIONAL)
            )
        );
    }
    
    // --- Period Management ---

    public static function get_periods_parameters() {
        return new external_function_parameters([]);
    }
    
    public static function get_periods() {
        global $DB;
         $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);
        
        $periods = $DB->get_records('gmk_academic_periods', [], 'startdate DESC');
        foreach ($periods as $p) {
            $p->learningplans = array_values($DB->get_records_menu('gmk_academic_period_lps', ['academicperiodid' => $p->id], '', 'id, learningplanid'));
        }
        
        return array_values($periods);
    }
    
    public static function get_periods_returns() {
        return new external_multiple_structure(
            new external_single_structure([
                'id' => new external_value(PARAM_INT, 'ID'),
                'name' => new external_value(PARAM_TEXT, 'Name'),
                'startdate' => new external_value(PARAM_INT, 'Start Date'),
                'enddate' => new external_value(PARAM_INT, 'End Date'),
                'status' => new external_value(PARAM_INT, 'Status'),
                'learningplans' => new external_multiple_structure(new external_value(PARAM_INT), 'List of Learning Plan IDs', VALUE_OPTIONAL)
            ])
        );
    }
    
    public static function save_period_parameters() {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'ID (0 for new)', VALUE_DEFAULT, 0),
            'name' => new external_value(PARAM_TEXT, 'Name'),
            'startdate' => new external_value(PARAM_INT, 'Start Timestamp'),
            'enddate' => new external_value(PARAM_INT, 'End Timestamp'),
            'status' => new external_value(PARAM_INT, 'Status', VALUE_DEFAULT, 1),
            'learningplans' => new external_multiple_structure(new external_value(PARAM_INT), 'List of Learning Plan IDs', VALUE_DEFAULT, [])
        ]);
    }
    
    public static function save_period($id, $name, $startdate, $enddate, $status, $learningplans = []) {
        global $DB;
         $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);
        
        $rec = new stdClass();
        $rec->name = $name;
        $rec->startdate = $startdate;
        $rec->enddate = $enddate;
        $rec->status = $status;
        $rec->timemodified = time();
        $rec->usermodified = $GLOBALS['USER']->id;
        
        if ($id > 0) {
            $rec->id = $id;
            $DB->update_record('gmk_academic_periods', $rec);
            $periodid = $id;
        } else {
            $rec->timecreated = time();
            $periodid = $DB->insert_record('gmk_academic_periods', $rec);
        }

        // Sync Learning Plan Relations
        $DB->delete_records('gmk_academic_period_lps', ['academicperiodid' => $periodid]);
        foreach ($learningplans as $lpid) {
            $rel = new stdClass();
            $rel->academicperiodid = $periodid;
            $rel->learningplanid = $lpid;
            $rel->usermodified = $GLOBALS['USER']->id;
            $rel->timecreated = time();
            $DB->insert_record('gmk_academic_period_lps', $rel);
        }

        return $periodid;
    }
    
    public static function save_period_returns() {
        return new external_value(PARAM_INT, 'Period ID');
    }
    
    // --- Planning Selection Save ---
    
    public static function save_planning_parameters() {
        return new external_function_parameters([
            'academicperiodid' => new external_value(PARAM_INT, ''),
            'selections' => new external_value(PARAM_RAW, 'JSON array of objects {planid, courseid, periodid, count}')
        ]);
    }
    
    public static function save_planning($academicperiodid, $selections) {
        global $DB;
         $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);
        
        $items = json_decode($selections, true);
        if (!is_array($items)) return false;
        
        $now = time();
        $uid = $GLOBALS['USER']->id;
        
        // Strategy: We can delete existing for this period/plan and re-insert, or upsert.
        // Simplest for checkboxes: Delete all for this academic period and re-insert active ones?
        // Risky if partial update.
        // Better: The frontend sends the "delta" or we handle it item by item.
        // Let's assume frontend sends strictly the "Checked" items.
        // Ideally we would want to sync.
        
        // For simplicity: Loop and Insert if not exists.
        // Note: Managing deletions of unchecked items is needed.
        // Let's assume the user saves ONE plan/jornada block or the whole thing?
        // Currently "save_planning" implies global save.
        
        // Let's Wipe and Re-insert for the given Academic Period? NO, dangerous.
        // Let's try upsert.
        
        foreach ($items as $item) {
            // Check existence
            $exists = $DB->get_record('gmk_academic_planning', [
                'academicperiodid' => $academicperiodid, 
                'learningplanid' => $item['planid'],
                'courseid' => $item['courseid']
            ]);
            
            if (!$item['checked']) {
                if ($exists) {
                    $DB->delete_records('gmk_academic_planning', ['id' => $exists->id]);
                }
                continue;
            }
            
            if (!$exists) {
                $rec = new stdClass();
                $rec->academicperiodid = $academicperiodid;
                $rec->learningplanid = $item['planid'];
                $rec->courseid = $item['courseid'];
                $rec->periodid = $item['periodid']; // stored relative period
                $rec->projected_students = $item['count'];
                $rec->status = 1;
                $rec->timecreated = $now;
                $rec->timemodified = $now;
                $rec->usermodified = $uid;
                $DB->insert_record('gmk_academic_planning', $rec);
            } else {
                // Update count?
                $exists->projected_students = $item['count'];
                $exists->timemodified = $now;
                $DB->update_record('gmk_academic_planning', $exists);
            }
        }
        
        return true;
    }
    
    public static function save_planning_returns() {
         return new external_value(PARAM_BOOL, 'Success');
    }

    // --- Manual Student Management ---

    public static function manually_update_student_period_parameters() {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'User ID'),
            'learningplanid' => new external_value(PARAM_INT, 'Learning Plan ID'),
            'currentperiodid' => new external_value(PARAM_INT, 'Curricular Period ID (Level)', VALUE_DEFAULT, null),
            'academicperiodid' => new external_value(PARAM_INT, 'Academic Period ID (Calendar)', VALUE_DEFAULT, null),
            'status' => new external_value(PARAM_ALPHA, 'Status (activo, aplazado, retirado)', VALUE_DEFAULT, 'activo')
        ]);
    }

    public static function manually_update_student_period($userid, $learningplanid, $currentperiodid = null, $academicperiodid = null, $status = 'activo') {
        global $DB;
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $llu = $DB->get_record('local_learning_users', ['userid' => $userid, 'learningplanid' => $learningplanid]);
        if (!$llu) {
            throw new \moodle_exception('error_user_not_in_plan', 'local_grupomakro_core');
        }

        $rec = new stdClass();
        $rec->id = $llu->id;
        if ($currentperiodid !== null) $rec->currentperiodid = $currentperiodid;
        if ($academicperiodid !== null) $rec->academicperiodid = $academicperiodid;
        $rec->status = $status;
        $rec->timemodified = time();
        $rec->usermodified = $GLOBALS['USER']->id;

        $DB->update_record('local_learning_users', $rec);

        // If status changed to something other than 'activo', we might want to log it in gmk_student_suspension
        if ($status != 'activo') {
            $susp = new stdClass();
            $susp->userid = $userid;
            $susp->status = $status;
            $susp->timecreated = time();
            $susp->usermodified = $GLOBALS['USER']->id;
            $susp->reason = 'Manual override by administrator';
            $DB->insert_record('gmk_student_suspension', $susp);
        }

        return true;
    }

    public static function manually_update_student_period_returns() {
        return new external_value(PARAM_BOOL, 'Success');
    }

}
