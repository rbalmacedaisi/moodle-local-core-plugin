<?php
namespace local_grupomakro_core\task;

use core\task\scheduled_task;
use local_grupomakro_progress_manager;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');

class process_period_transition extends scheduled_task {

    /**
     * Get the name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskprocessperiodtransition', 'local_grupomakro_core');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        mtrace('Starting process_period_transition task...');

        // 1. Find ACTIVE periods that have ended (enddate < NOW)
        // We look for status = 1 (Active)
        $now = time();
        $sql = "SELECT * FROM {gmk_academic_periods} WHERE status = 1 AND enddate < :now";
        $expiredPeriods = $DB->get_records_sql($sql, ['now' => $now]);

        if (empty($expiredPeriods)) {
            mtrace("No expired active periods found.");
            return;
        }

        foreach ($expiredPeriods as $period) {
            mtrace("Processing expring period: {$period->name} (ID: {$period->id})...");
            
            // 2. Find the NEXT period for related learning plans
            // We need to know which students to move. Students are linked to this period via local_learning_users.academicperiodid
            // But next period depends on the plan.
            
            // Get all plans linked to this period (just for reference/logging)
            //$linkedPlans = $DB->get_records('gmk_academic_period_lps', ['academicperiodid' => $period->id]);
            
            // 3. Get Students currently in this Academic Period
            // We process by batch to avoid memory issues
            $sqlStudents = "SELECT llu.*, u.username 
                            FROM {local_learning_users} llu
                            JOIN {user} u ON u.id = llu.userid
                            WHERE llu.academicperiodid = :periodid AND u.deleted = 0 AND u.suspended = 0";
            
            $students = $DB->get_records_sql($sqlStudents, ['periodid' => $period->id]);
            $count = count($students);
            mtrace("Found $count students in period {$period->name}.");
            
            if ($count > 0) {
                // Find next active period candidates (startdate > this.enddate)
                // We assume there is ONE global next period for the institution generally, 
                // OR we have to find the specific next period for each plan.
                // Let's try to find a period that starts shortly after this one ends.
                
                $nextPeriod = $DB->get_record_sql("SELECT * FROM {gmk_academic_periods} 
                                                   WHERE startdate >= :enddate AND status = 1 
                                                   ORDER BY startdate ASC", 
                                                   ['enddate' => $period->enddate], IGNORE_MULTIPLE); // Get first one
                
                if (!$nextPeriod) {
                    mtrace("WARNING: No next active period found after " . date('Y-m-d', $period->enddate) . ". Students will remain in current period.");
                } else {
                    mtrace("Target next period: {$nextPeriod->name} (ID: {$nextPeriod->id})");
                    
                    foreach ($students as $stu) {
                        try {
                            // El nivel curricular (cuatrimestre/bimestre) NO se toca aqui: lo calcula
                            // la tarea advance_academic_progress. sync_student_period lo reescribia por
                            // materias aprobadas y deshacia ese calculo.

                            // 3.2. Move to Next Institutional Period
                            $stu->academicperiodid = $nextPeriod->id;
                            $stu->timemodified = time();
                            $DB->update_record('local_learning_users', $stu);
                            
                            mtrace("Moved User {$stu->userid} ({$stu->username}) to {$nextPeriod->name}.");
                            
                        } catch (\Exception $e) {
                            mtrace("Error processing user {$stu->userid}: " . $e->getMessage());
                        }
                    }
                }
            }

            $period->status = 0; // Close it
            $period->timemodified = time();
            $DB->update_record('gmk_academic_periods', $period);
            mtrace("Closed period {$period->name}.");
        }

        // Los bimestres ya no se fuerzan por calendario aqui (pisaba el nivel de todos los estudiantes
        // del periodo institucional): ver advance_academic_progress.

        mtrace('Task completed.');
    }
}
