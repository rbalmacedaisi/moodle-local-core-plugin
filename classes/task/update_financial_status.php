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
        mtrace('Starting financial status update (cron residual 6h)...');

        // Red de seguridad residual. El flujo principal ya cubre la
        // mayoria de los cambios en tiempo real via:
        //   - webhook Express -> Moodle (gmk_financial_webhook_dlq)
        //   - hook user_loggedin con throttle 6h
        // Este cron solo limpia filas que quedaron stale (lastupdated > 24h)
        // y nunca se actualizaron por ninguna de las dos vias anteriores.
        // 1 lote por ejecucion evita saturar el proxy; 4 ejecuciones/dia
        // dan una ventana maxima de 6h entre refreshes exitosos.
        $batches = 1;
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
                mtrace('No stale users to update.');
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
