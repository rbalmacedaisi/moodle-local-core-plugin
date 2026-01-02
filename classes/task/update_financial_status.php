<?php
namespace local_grupomakro_core\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

/**
 * Scheduled task to update student financial status from Odoo.
 *
 * @package    local_grupomakro_core
 * @copyright  2024 Grupomakro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_financial_status extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskupdatefinancialstatus', 'local_grupomakro_core');
    }

    /**
     * Do the job.
     * Throw exceptions on errors (the job will be retried).
     */
    public function execute() {
        mtrace('Starting financial status update...');

        // Sync a batch of users. 
        // We call it multiple times to process more if needed, 
        // or just once per run depending on volume.
        // Let's do a loop to process up to 200 users per run (4 batches of 50)
        
        $batches = 4;
        $totalUpdated = 0;

        for ($i = 0; $i < $batches; $i++) {
            $result = local_grupomakro_sync_financial_status();
            
            if (isset($result['error'])) {
                mtrace('Error: ' . $result['error']);
                if (isset($result['details'])) {
                    mtrace('Details: ' . $result['details']);
                }
                break; // Stop on error
            }

            if (empty($result['updated'])) {
                mtrace('No more users to update.');
                break;
            }

            $totalUpdated += $result['updated'];
            mtrace("Batch $i: Updated {$result['updated']} users.");
            
            // Small pause to be nice to the proxy
            sleep(1);
        }

        mtrace("Completed. Total updated: $totalUpdated");
    }
}
