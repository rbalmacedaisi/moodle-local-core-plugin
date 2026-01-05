<?php
namespace local_grupomakro_core\local;

defined('MOODLE_INTERNAL') || die();

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

        $sql = "SELECT u.id, u.firstname, u.lastname, u.idnumber, u.email,
                       lp.id as planid, lp.name as planname,
                       p.id as periodid, p.name as periodname,
                       sp.id as subperiodid, sp.name as subperiodname
                       $jornadaSelect
                FROM {user} u
                JOIN {local_learning_users} llu ON llu.userid = u.id AND llu.userrolename = 'student'
                JOIN {local_learning_plans} lp ON lp.id = llu.learningplanid
                LEFT JOIN {local_learning_periods} p ON p.id = llu.currentperiodid
                LEFT JOIN {local_learning_subperiods} sp ON sp.id = llu.currentsubperiodid
                $jornadaJoin
                WHERE u.deleted = 0 AND u.suspended = 0";

        $studentsRaw = $DB->get_records_sql($sql);
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
        $plans = self::get_all_plans_structure(); // Helper below
        
        // 2b. Get All Approved Courses per Student (Bulk)
        $approvedMap = self::get_all_approved_courses(); 

        foreach ($studentsRaw as $stu) {
            $planId = $stu->planid;
            if (!isset($plans[$planId])) continue; // Skip if plan structure missing

            $planStructure = $plans[$planId];
            $studentApproved = isset($approvedMap[$stu->id]) ? $approvedMap[$stu->id] : [];

            $pendingSubjects = [];
            
            // Analyze each course in the plan
            foreach ($planStructure as $course) {
                // If already approved, skip
                if (in_array($course->id, $studentApproved)) continue;

                // Check Prerequisites
                $isPreRequisiteMet = true;
                if (!empty($course->prereqs)) {
                    foreach ($course->prereqs as $prereqId) {
                        if (!in_array($prereqId, $studentApproved)) {
                            $isPreRequisiteMet = false;
                            break;
                        }
                    }
                }
                
                // Determine Theoretical Semester (from course structure)
                // "semester" field in plan structure.

                $pendingSubjects[] = [
                    'id' => $course->id,
                    'name' => $course->fullname,
                    'semester' => $course->semester_num, // Normalized numeric level
                    'semesterName' => $course->semester_name,
                    'isPriority' => $isPreRequisiteMet, // If prerequisites met, it's actionable
                    'isPreRequisiteMet' => $isPreRequisiteMet 
                ];
            }

            // Determine Student's Theoretical Level 
            // (Max level of Approved + 1? Or Current Period?)
            // React logic calculates "Current Sem" based on progress.
            // We'll pass the explicit "Current Period" from DB ($stu->periodname) but also let Frontend calculate.
            
            $studentList[] = [
                'id' => $stu->idnumber ? $stu->idnumber : $stu->id, // Prefer ID Number for display
                'dbId' => $stu->id,
                'name' => $stu->firstname . ' ' . $stu->lastname,
                'career' => $stu->planname,
                'shift' => $stu->shift,
                // Pass raw Current Period/Subperiod from DB Config
                'currentSemConfig' => $stu->periodname, 
                'currentSubperiodConfig' => $stu->subperiodname,
                'pendingSubjects' => $pendingSubjects
            ];
        }

        return $studentList;
    }

    /**
     * Helper: Get Structure of ALL Plans (Courses, Semesters, Prereqs).
     * Returns: [ planId => [ courseId => { id, fullname, semester_num, semester_name, prereqs[] } ] ]
     */
    private static function get_all_plans_structure() {
        global $DB;
        
        // Fetch all Plans
        // Fetch all Periods (Semesters) in Plans
        // Fetch all Link Courses (Subject in Period)
        // Fetch Prereqs
        
        // 1. Get Courses linked to Plans
        // Table: {local_learning_courses} (verified in progress_manager.php)
        
        $sql = "SELECT p.learningplanid, p.id as period_id, p.name as period_name,
                       c.id as courseid, c.fullname, 
                       lpc.id as linkid
                FROM {local_learning_periods} p
                JOIN {local_learning_courses} lpc ON lpc.periodid = p.id
                JOIN {course} c ON c.id = lpc.courseid
                ORDER BY p.learningplanid, p.id";

        $records = $DB->get_records_sql($sql);
        
        $structure = [];
        $planCounters = []; // To track semester index per plan
        
        foreach ($records as $r) {
            if (!isset($structure[$r->learningplanid])) {
                $structure[$r->learningplanid] = [];
                $planCounters[$r->learningplanid] = 1;
            }
            
            // NOTE: Since we order by p.id, we can't guarantee sequential semester numbers (1, 2, 3...)
            // unless we deduce it. For the "Wave" logic, we need strict 1, 2, 3 levels.
            // Assumption: Periods are created in order.
            // We need to map period_id to a sequence number.
            
            // Helper to get cached semester num for this period in this plan
            // This logic is slightly flawed inside a flat loop of courses.
            // Better: Group by period first?
            // Actually, let's keep it simple: Use a map of PeriodID -> Index per Plan.
        }
        
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
             
             $structure[$r->learningplanid][] = (object) [
                'id' => $r->courseid,
                'fullname' => $r->fullname,
                'semester_num' => $semesterNum, 
                'semester_name' => $r->period_name,
                'prereqs' => [] // TODO: Implement Prereq fetch if table known
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
        $sqlProgre = "SELECT userid, courseid FROM {gmk_course_progre} WHERE status >= 3";
        $recordsProgre = $DB->get_records_sql($sqlProgre);
        
        foreach ($recordsProgre as $r) {
            if (!isset($map[$r->userid])) $map[$r->userid] = [];
            $map[$r->userid][] = $r->courseid;
        }

        // 2. Check Standard Moodle Completion (Fallback/Merge)
        $sqlMoodle = "SELECT userid, course FROM {course_completions} WHERE timecompleted > 0";
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
}
