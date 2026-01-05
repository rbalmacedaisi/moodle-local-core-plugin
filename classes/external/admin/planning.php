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
        $jornadaField = $DB->get_record('user_info_field', ['shortname' => 'jornada']);
        $jornadaJoin = "";
        $jornadaSelect = "";
        
        if ($jornadaField) {
            $jornadaJoin = "LEFT JOIN {user_info_data} uid_j ON uid_j.userid = u.id AND uid_j.fieldid = " . $jornadaField->id;
            $jornadaSelect = ", uid_j.data AS jornada";
        }
        
        // Financial Status join
        $financialJoin = "LEFT JOIN {gmk_financial_status} fs ON fs.userid = u.id";
        $financialSelect = ", fs.status as financial_status";
        
        $sql = "SELECT u.id, u.firstname, u.lastname, lp.id as planid, lp.name as planname, 
                       llu.currentperiodid, p.name as currentperiodname $jornadaSelect $financialSelect
                FROM {user} u
                JOIN {local_learning_users} llu ON llu.userid = u.id
                JOIN {local_learning_plans} lp ON lp.id = llu.learningplanid
                JOIN {local_learning_periods} p ON p.id = llu.currentperiodid
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

        // 3. Demand Calculation
        // Structure: [PlanName][Jornada][PeriodName][CourseID] = Count
        $demand = [];
        
        // Pre-fetch all passed courses for these students to avoid N+1
        // SELECT userid, courseid FROM gmk_course_progre WHERE grade >= ... (Assume grade >= 71 or status=passed)
        $progress_sql = "SELECT CONCAT(userid, '_', courseid) as id, 1 
                         FROM {gmk_course_progre} 
                         WHERE grade >= 71"; // Hardcoded passing grade for now, ideally verified
        $passed_map = $DB->get_records_sql_menu($progress_sql);
        
        // Build Demand
        foreach ($students as $stu) {
            
            // Apply Filters (PHP side for "Jornada" flexible matching)
            if (!empty($filterData['jornada']) && stripos(($stu->jornada ?? ''), $filterData['jornada']) === false) {
                continue;
            }
            if (!empty($filterData['career']) && $stu->planid != $filterData['career']) {
                continue;
            }
            if (!empty($filterData['financial_status']) && stripos(($stu->financial_status ?? ''), $filterData['financial_status']) === false) {
                 // Special case: 'active' filter might mean 'al_dia' or similar
                 continue;
            }

            $planid = $stu->planid;
            $jornada = !empty($stu->jornada) ? $stu->jornada : 'Sin Jornada';
            
            if (!isset($curricula[$planid])) continue; // No curriculum for this plan
            
            // Iterate through ALL periods of the plan to find pending subjects
            // Simple Logic: If not passed, it's pending.
            // Advanced Logic: Only the NEXT period subjects? Or ALL pending?
            // User asked: "identificar la cantidad de estudiantes que tienen pendiente por ver cada asignatura"
            // This implies ALL pending subjects, potential backtracking.
            
            foreach ($curricula[$planid] as $perId => $courseIds) {
                $perName = $period_names[$perId] ?? 'Periodo ' . $perId;
                
                foreach ($courseIds as $cid) {
                    $key = $stu->id . '_' . $cid;
                    if (!isset($passed_map[$key])) {
                        // PENDING!
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
                                'count' => 0
                            ];
                        }
                        
                        $demand[$planid]['jornadas'][$jornada][$perId]['courses'][$cid]['count']++;
                    }
                }
            }
        }
        
        // 4. Load Planning Selection (if periodid provided)
        $selections = [];
        if ($periodid > 0) {
             $plans = $DB->get_records('gmk_academic_planning', ['academicperiodid' => $periodid]);
             foreach ($plans as $p) {
                 $selections[$p->learningplanid . '_' . $p->courseid] = true;
             }
        }
        
        return [
            'demand' => $demand,
            'selections' => $selections
        ];
    }

    public static function get_demand_analysis_returns() {
        return new external_single_structure(
            array(
                'demand' => new external_value(PARAM_RAW, 'JSON structure of demand'), // Returning Raw JSON for flexibility with dynamic keys
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
        
        return array_values($DB->get_records('gmk_academic_periods', [], 'startdate DESC'));
    }
    
    public static function get_periods_returns() {
        return new external_multiple_structure(
            new external_single_structure([
                'id' => new external_value(PARAM_INT, 'ID'),
                'name' => new external_value(PARAM_TEXT, 'Name'),
                'startdate' => new external_value(PARAM_INT, 'Start Date'),
                'enddate' => new external_value(PARAM_INT, 'End Date'),
                'status' => new external_value(PARAM_INT, 'Status')
            ])
        );
    }
    
    public static function save_period_parameters() {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'ID (0 for new)', VALUE_DEFAULT, 0),
            'name' => new external_value(PARAM_TEXT, 'Name'),
            'startdate' => new external_value(PARAM_INT, 'Start Timestamp'),
            'enddate' => new external_value(PARAM_INT, 'End Timestamp'),
            'status' => new external_value(PARAM_INT, 'Status', VALUE_DEFAULT, 1)
        ]);
    }
    
    public static function save_period($id, $name, $startdate, $enddate, $status) {
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
            return $id;
        } else {
            $rec->timecreated = time();
            return $DB->insert_record('gmk_academic_periods', $rec);
        }
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

}
