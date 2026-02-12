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
                            // 3.1. Sync Academic Progress (Level up if applicable)
                            // This ensures their "Semestre/Cuatrimestre" is up to date before moving them to new calendar slot.
                            local_grupomakro_progress_manager::sync_student_period($stu->userid, $stu->learningplanid);
                            
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

        // ============================================================
        // 5. Check for Sub-period (Block) Transitions in ACTIVE periods
        // ============================================================
        mtrace("Checking for Sub-period (Block) transitions...");
        
        $activePeriods = $DB->get_records('gmk_academic_periods', ['status' => 1]);
        
        foreach ($activePeriods as $p) {
             // Get Calendar Details
             $cal = $DB->get_record('gmk_academic_calendar', ['academicperiodid' => $p->id]);
             if (!$cal || !$cal->hassubperiods) continue;
             
             // Determine Current Target Block Index (0 = First Subperiod, 1 = Second Subperiod)
             $targetBlockIndex = -1;
             $blockName = "";

             // Logic: If we are past Block 2 Start, we force move to Block 2.
             // If we are past Block 1 Start but not Block 2, we ensure Block 1.
             
             if ($cal->block2start > 0 && $now >= $cal->block2start) {
                 $targetBlockIndex = 1; // 2nd position
                 $blockName = "Block 2 (Starts " . date('Y-m-d', $cal->block2start) . ")";
             } elseif ($cal->block1start > 0 && $now >= $cal->block1start) {
                 $targetBlockIndex = 0; // 1st position
                 $blockName = "Block 1 (Starts " . date('Y-m-d', $cal->block1start) . ")";
             }
             
             if ($targetBlockIndex >= 0) {
                 mtrace("Period {$p->name} is currently in: $blockName. verifying student subperiods...");
                 
                 // Get students in this institutional period
                 $students = $DB->get_records('local_learning_users', [
                     'academicperiodid' => $p->id, 
                     'userrolename' => 'student'
                 ]);
                 
                 foreach ($students as $stu) {
                     try {
                         // Get available subperiods for the student's current curriculum level
                         $subperiods = $DB->get_records('local_learning_subperiods', [
                             'periodid' => $stu->currentperiodid
                         ], 'id ASC'); // Ordered by ID implies 1st, 2nd...
                         
                         $subperiods = array_values($subperiods); // Re-index 0, 1
                         
                         if (isset($subperiods[$targetBlockIndex])) {
                             $targetSubperiod = $subperiods[$targetBlockIndex];
                             
                             if ($stu->currentsubperiodid != $targetSubperiod->id) {
                                 // Update
                                 $stu->currentsubperiodid = $targetSubperiod->id;
                                 $stu->timemodified = $now;
                                 $DB->update_record('local_learning_users', $stu);
                                 mtrace("Updated User {$stu->userid} to subperiod {$targetSubperiod->name} (Block " . ($targetBlockIndex+1) . ")");
                             }
                         }
                     } catch (\Exception $e) {
                         mtrace("Error syncing block for user {$stu->userid}: " . $e->getMessage());
                     }
                 }
             }
        }

        mtrace('Task completed.');
    }
}
