<?php
namespace local_grupomakro_core\local;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

class planning_manager {

    /**
     * Fetch raw data for the Academic Planner Projection Engine.
     * Returns a flat list of students with their metadata and PENDING subjects.
     * 
     * @param int $periodId Currently active period (context for "Current Status").
     * @return array
     */
    public static function get_planning_data($periodId) {
        global $DB;

        // 1. Fetch Active Students in Learning Plans
        // We join with Period/Subperiod to get current location
        // We join with User to get valid users
        // We fetch 'Jornada' from user_info_data (shortname 'gmkjourney')
        
        $jornadaField = $DB->get_record('user_info_field', ['shortname' => 'gmkjourney']);
        $jornadaJoin = "";
        $jornadaSelect = ", '' as shift";
        
        if ($jornadaField) {
            $jornadaJoin = "LEFT JOIN {user_info_data} uid_j ON uid_j.userid = u.id AND uid_j.fieldid = " . $jornadaField->id;
            $jornadaSelect = ", uid_j.data AS shift";
        }

        // Fetch Periodo de Ingreso custom field
        $periodoIngresoField = $DB->get_record('user_info_field', ['shortname' => 'periodo_ingreso']);
        $piJoin = "";
        $piSelect = ", '' as entry_period";
        if ($periodoIngresoField) {
            $piJoin = "LEFT JOIN {user_info_data} uid_pi ON uid_pi.userid = u.id AND uid_pi.fieldid = " . $periodoIngresoField->id;
            $piSelect = ", uid_pi.data AS entry_period";
        }

        // Use llu.id as unique key to prevent "Duplicate value" error when user has multiple plans
        $sql = "SELECT llu.id as subscriptionid, u.id, u.firstname, u.lastname, u.idnumber, u.email,
                       lp.id as planid, lp.name as planname,
                       p.id as periodid, p.name as periodname,
                       sp.id as subperiodid, sp.name as subperiodname
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
                ORDER BY llu.id ASC"; // Order by ID to process older first, so newer overwrites older in loop

        $subscriptionsRaw = $DB->get_records_sql($sql);
        
        // Deduplicate students, keeping the latest subscription (highest ID)
        $studentsRaw = [];
        foreach ($subscriptionsRaw as $sub) {
            $studentsRaw[$sub->id] = $sub; // $sub->id is user ID here
        }
        $studentList = [];

        // 2. Fetch Progress (Grades) to determine Pending Subjects
        // We need all grades for these students to filter out 'Approved'.
        // Assuming 'gmk_course_progre' table holds grades or 'completion'.
        // Actually, we usually use standard Moodle completion or a custom table.
        // Based on previous context, 'gmk_course_progre' was mentioned or similar.
        // Let's assume we use the standard Moodle grade_grades or the custom completion table if it exists.
        // CHECK: The user previously shared 'classes/local/progress_manager.php'. I should check how it determines 'Approved'.
        
        // RE-CHECK progress_manager.php logic for "Approved".
        // Use a helper if possible, but for Bulk Performance, we might need a single query.
        
        // For now, let's assume we fetch ALL courses in the Plan, and filter out those the student has PASSED.
        
        // 2a. Get All Courses per Plan (Structure)
        // 3. Process Students Pending Subjects (Wave Engine - Backend Portion)
        $students = [];
        
        // Optimize: Fetch all structures once
        $structures = self::get_all_plans_structure();
        
        // 2b. Get All Approved Courses per Student (Bulk)
        $approvedCourses = self::get_all_approved_courses(); 

        // Flatten ALL subjects from ALL plans into a master list for the Frontend Matrix
        $allSubjects = [];
        
        // Fetch all courses linked to learning plans to build a master list
        // Fetch all courses linked to learning plans to build a master list
        // FIX: Use lpc.id (Link ID) as key to avoid losing courses that might be reused (though less critical here than above)
        $plan_courses_sql = "SELECT lpc.id as linkid, lpc.courseid, lpc.periodid, c.fullname AS coursename, p.name AS periodname
                             FROM {local_learning_courses} lpc
                             JOIN {course} c ON c.id = lpc.courseid
                             JOIN {local_learning_periods} p ON p.id = lpc.periodid";
        $plan_courses_records = $DB->get_records_sql($plan_courses_sql);

        foreach ($plan_courses_records as $pc) {
            if (!isset($allSubjects[$pc->courseid])) {
                $allSubjects[$pc->courseid] = [
                    'id' => $pc->courseid,
                    'name' => $pc->coursename,
                    'semester_num' => self::parse_semester_number($pc->periodname),
                    'semester_name' => $pc->periodname
                ];
            }
        }

        // B. Fetch Subject-Specific Projections (from Matrix)
        $planningProjections = $DB->get_records('gmk_academic_planning', ['academicperiodid' => $periodId]);
        
        // C. Fetch General Projections (New Entrants)
        $projections = $DB->get_records('gmk_academic_projections', ['academicperiodid' => $periodId]);

        foreach ($studentsRaw as $u) {
            $planId = $u->planid;
            if (!isset($structures[$planId])) continue; // Skip if no structure found

            $planStructure = $structures[$planId];
            
            // Get student Progress
            $approved = $approvedCourses[$u->id] ?? [];
            
            // Calculate Pending (Simple Prereq Check: Is not approved)
            // Ideally check Prereqs here. For MVP: Pending = In Plan AND Not Approved.
            
            $pending = [];
            foreach ($planStructure as $course) {
                if (!in_array($course->id, $approved)) {
                    // Check Prerequisites
                    $isPreRequisiteMet = true;
                    if (!empty($course->prereqs)) {
                        foreach ($course->prereqs as $prereqId) {
                            if (!in_array($prereqId, $approved)) {
                                $isPreRequisiteMet = false;
                                break;
                            }
                        }
                    }

                    // For "Demand Analysis", we generally only want what is OPEN for the student to take.
                    // i.e., Prereqs MUST be met.
                    // We store 'isPreRequisiteMet' status.
                    // However, we also need to store 'isPriority' for the "Wave" logic (scheduling engine).
                    
                    $pending[] = [
                        'id' => $course->id,
                        'name' => $course->fullname,
                        'semester' => $course->semester_num, // Normalized numeric level
                        'semesterName' => $course->semester_name,
                        'isPriority' => $isPreRequisiteMet, 
                        'isPreRequisiteMet' => $isPreRequisiteMet 
                    ];
                }
            }

            // Determine Student's Theoretical Level 
            // If DB config is missing (common issue), fallback to the first Pending Subject's semester
            $dbPeriodName = $u->periodname;
            if (empty($dbPeriodName) && !empty($pending)) {
                // Pending is sorted by Plan Structure (Semester Order)
                // Use the first pending subject's semester as the current level
                $firstPending = reset($pending);
                $dbPeriodName = $firstPending['semesterName']; 
            }
            
            $studentList[] = [
                'id' => $u->idnumber ? $u->idnumber : $u->id, // Prefer ID Number for display
                'dbId' => $u->id,
                'name' => $u->firstname . ' ' . $u->lastname,
                'career' => $u->planname,
                'shift' => $u->shift,
                // Pass raw Current Period/Subperiod from DB Config, or Fallback
                'currentSemConfig' => $dbPeriodName, 
                'currentSubperiodConfig' => $u->subperiodname,
                'entry_period' => $u->entry_period,
                'pendingSubjects' => $pending
            ];

        }

        return [
            'students' => $studentList,
            'all_subjects' => array_values($allSubjects), // New: Master list of courses
            'projections' => array_values($projections),
            'planning_projections' => array_values($planningProjections)
        ];
    }

    /**
     * Helper: Get Structure of ALL Plans (Courses, Semesters, Prereqs).
     * Returns: [ planId => [ courseId => { id, fullname, semester_num, semester_name, prereqs[] } ] ]
     */
    private static function get_all_plans_structure() {
        global $DB;
        
        // 0. Get Prerequisite Custom Field ID
        $preFieldId = $DB->get_field('customfield_field', 'id', ['shortname' => 'pre']);

        // 1. Get Courses linked to Plans WITH Prereq Shortnames
        // Left Join customfield_data
        
        $joinCustom = "";
        $selectCustom = "";
        
        if ($preFieldId) {
            $joinCustom = "LEFT JOIN {customfield_data} cfd ON cfd.instanceid = c.id AND cfd.fieldid = $preFieldId";
            $selectCustom = ", cfd.value as prereq_shortnames";
        }

        // FIX: Use lpc.id (Link ID) as the first column (key) to ensure uniqueness.
        // Previously, it was using p.learningplanid (or implied first col), causing massive data loss (only 1 row per plan).
        $sql = "SELECT lpc.id as linkid, p.learningplanid, p.id as period_id, p.name as period_name,
                       c.id as courseid, c.fullname, c.shortname
                       $selectCustom
                FROM {local_learning_periods} p
                JOIN {local_learning_courses} lpc ON lpc.periodid = p.id
                JOIN {course} c ON c.id = lpc.courseid
                $joinCustom
                ORDER BY p.learningplanid, p.id";

        $records = $DB->get_records_sql($sql);
        
        // 2. Build Shortname -> ID Map for resolution
        $allCourses = $DB->get_records('course', [], '', 'shortname, id');
        $shortnameToId = [];
        foreach ($allCourses as $c) {
            $shortnameToId[$c->shortname] = $c->id;
        }

        $structure = [];
        $planCounters = []; // Reuse logic if needed, but we use map below
        
        // Re-process for structure:
        $planPeriodMap = []; // planId => [ periodId => index ]
        
        foreach ($records as $r) {
             if (!isset($planPeriodMap[$r->learningplanid])) {
                 $planPeriodMap[$r->learningplanid] = [];
             }
             if (!isset($planPeriodMap[$r->learningplanid][$r->period_id])) {
                 $planPeriodMap[$r->learningplanid][$r->period_id] = count($planPeriodMap[$r->learningplanid]) + 1;
             }
             
             $semesterNum = $planPeriodMap[$r->learningplanid][$r->period_id];
             
             // Resolve Prereqs
             $prereqs = [];
             if (!empty($r->prereq_shortnames)) {
                 $shorts = explode(',', $r->prereq_shortnames);
                 foreach ($shorts as $s) {
                     $s = trim($s);
                     if (isset($shortnameToId[$s])) {
                         $prereqs[] = $shortnameToId[$s];
                     }
                 }
             }

             $structure[$r->learningplanid][] = (object) [
                'id' => $r->courseid,
                'fullname' => $r->fullname,
                'name' => $r->fullname, // Alias for frontend compatibility
                'semester_num' => $semesterNum, 
                'semester_name' => $r->period_name,
                'prereqs' => $prereqs
            ];
        }
        return $structure;
    }

    /**
     * Helper: Get All Approved Courses for All Students.
     * Checks both 'gmk_course_progre' (Status 3 or 4) AND 'course_completions'.
     * returns [ userid => [ courseId1, courseId2... ] ]
     */
    private static function get_all_approved_courses() {
        global $DB;
        
        $map = [];

        // 1. Check Custom Progress Table (gmk_course_progre)
        // Status 3 = Completed, 4 = Approved.
        // 1. Check Custom Progress Table (gmk_course_progre)
        // Status 3 = Completed, 4 = Approved.
        // FIX: Use 'id' as first column (key) to prevent deduplication by userid
        $sqlProgre = "SELECT id, userid, courseid FROM {gmk_course_progre} WHERE status >= 3";
        $recordsProgre = $DB->get_records_sql($sqlProgre);
        
        foreach ($recordsProgre as $r) {
            if (!isset($map[$r->userid])) $map[$r->userid] = [];
            $map[$r->userid][] = $r->courseid;
        }

        // 2. Check Standard Moodle Completion (Fallback/Merge)
        // FIX: Use 'id' as first column (key)
        $sqlMoodle = "SELECT id, userid, course FROM {course_completions} WHERE timecompleted > 0";
        $recordsMoodle = $DB->get_records_sql($sqlMoodle);

        foreach ($recordsMoodle as $r) {
            if (!isset($map[$r->userid])) $map[$r->userid] = [];
            // Avoid duplicates
            if (!in_array($r->course, $map[$r->userid])) {
                $map[$r->userid][] = $r->course;
            }
        }

        return $map;
    }

    /**
     * Fetch Context for Scheduler (Classrooms, Holidays).
     */
    public static function get_scheduler_context($periodId) {
        global $DB;
        
        $classrooms = $DB->get_records('gmk_classrooms', [], 'name ASC');
        $holidays = $DB->get_records('gmk_holidays', ['academicperiodid' => $periodId], 'date ASC');
        
        // Format holidays
        $formattedHolidays = [];
        foreach ($holidays as $h) {
            $h->formatted_date = date('Y-m-d', $h->date);
            $formattedHolidays[] = $h;
        }

        return [
            'classrooms' => array_values($classrooms),
            'holidays' => $formattedHolidays,
            'loads' => [] // Future: Teacher loads
        ];
    }

    /**
     * Process Planning Data into Demand Tree for Scheduler.
     * Returns:
     * - demand_tree: Nested structure [Career][Shift][Level] -> { students, courses }
     * - student_list: Flat list (same as planning data)
     * - projections: Manual projections
     * - subjects: Catalog of subjects
     */
    public static function get_demand_data($periodId) {
        // Reuse the Planning Engine to get the raw projection of students/subjects
        $planningData = self::get_planning_data($periodId);
        
        $students = $planningData['students'];
        $tree = [];

        foreach ($students as $stu) {
            $career = $stu['career'] ?: 'General';
            $shift = $stu['shift'] ?: 'Sin Jornada'; // Should come from user_info_data
            
            // Iterate Pending Subjects to build demand
            foreach ($stu['pendingSubjects'] as $subj) {
                // If subject is NOT Priority (e.g. unmet prerequisites), maybe we shouldn't schedule it automatically?
                // For now, "Wave" logic usually schedules everything pending.
                // Let's stick to including it.
                
                // UPDATE: For demand analysis, we ONLY want to show subjects the student CAN take.
                if (empty($subj['isPreRequisiteMet'])) {
                    continue; 
                }
                
                // Normalized Level Key for sorting
                // OLD LOGIC: Group by SUBJECT SEMESTER
                // $levelKey = $subj['semesterName'] ?: ('Nivel ' . $subj['semester']);

                // NEW LOGIC (Requested Refactor): Group by STUDENT CURRENT LEVEL AND BLOCK
                $levelLabel = $stu['currentSemConfig'] ?: 'Sin Nivel';
                $subLabel = $stu['currentSubperiodConfig'] ?: '';
                $levelKey = $subLabel ? "$levelLabel - $subLabel" : $levelLabel;

                // Init Path
                if (!isset($tree[$career][$shift][$levelKey])) {
                    $tree[$career][$shift][$levelKey] = [
                        'semester_name' => $levelKey,
                        'student_count' => 0, // Unique students in this bucket? Or total seats?
                        // 'student_ids' => [],
                        'course_counts' => []
                    ];
                }

                // Increment Course
                $courseId = $subj['id'];
                if (!isset($tree[$career][$shift][$levelKey]['course_counts'][$courseId])) {
                     $tree[$career][$shift][$levelKey]['course_counts'][$courseId] = [
                         'count' => 0,
                         'students' => []
                     ];
                }
                $tree[$career][$shift][$levelKey]['course_counts'][$courseId]['count']++;
                $tree[$career][$shift][$levelKey]['course_counts'][$courseId]['students'][] = $stu['id']; // Use 'id' which matches the student_list key
            }
        }
        
        // Post-processing: Calculate student_count per bucket (approximate or exact?)
        // In the loop above, we can't easily count unique students per level unless we track IDs.
        // Let's do a second pass or use a set.
        
        // Re-loop for student counts
        foreach ($students as $stu) {
             $career = $stu['career'] ?: 'General';
             $shift = $stu['shift'] ?: 'Sin Jornada';
             
             // Track which levels this student hits
             $levelsSeen = [];
             
             foreach ($stu['pendingSubjects'] as $subj) {
                 // Match the grouping logic used above
                 $levelLabel = $stu['currentSemConfig'] ?: 'Sin Nivel';
                 $subLabel = $stu['currentSubperiodConfig'] ?: '';
                 $levelKey = $subLabel ? "$levelLabel - $subLabel" : $levelLabel;
                 
                 // Initialize tree path if not exists (handling edge case where student has no subjects but we want to count them? No, only demand matters)
                 // But wait, if tree node created above, it exists.
                 if (isset($tree[$career][$shift][$levelKey])) {
                     if (!isset($levelsSeen[$levelKey])) {
                         $levelsSeen[$levelKey] = true;
                         $tree[$career][$shift][$levelKey]['student_count']++;
                     }
                 }
             }
        }

        return [
            'demand_tree' => $tree,
            'student_list' => $planningData['students'],
            'projections' => $planningData['planning_projections'], // Use the planning projections (manual overrides)
            'subjects' => isset($planningData['all_subjects']) ? $planningData['all_subjects'] : [] 
        ];
    }

    /**
     * Helper to extract numeric level from names like "Periodo IV" or "Nivel 2"
     */
    private static function parse_semester_number($name) {
        if (empty($name)) return 1;
        
        // Check for Romans
        $romans = ['X' => 10, 'IX' => 9, 'VIII' => 8, 'VII' => 7, 'VI' => 6, 'V' => 5, 'IV' => 4, 'III' => 3, 'II' => 2, 'I' => 1];
        foreach ($romans as $r => $v) {
            if (stripos($name, $r) !== false) return $v;
        }
        
        // Fallback to digits
        if (preg_match('/\d+/', $name, $matches)) {
            return (int)$matches[0];
        }
        
        return 1;
    }
}
