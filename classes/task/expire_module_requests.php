<?php
// This file is part of Moodle - http://moodle.org/
//
// Scheduled task that flips pending module invoice requests to 'expired'
// once their expires_at window has elapsed.

namespace local_grupomakro_core\task;

use local_grupomakro_core\local\module_invoice_manager;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->dirroot . '/local/grupomakro_core/classes/local/module_invoice_manager.php');

/**
 * Cron: expire stale pending module invoice requests.
 */
class expire_module_requests extends \core\task\scheduled_task {

    /**
     * Returns the localized task name (shown in the Moodle scheduled tasks admin page).
     */
    public function get_name(): string {
        return get_string('task_expire_module_requests', 'local_grupomakro_core');
    }

    /**
     * Runs the expiry sweep.
     */
    public function execute(): void {
        $count = module_invoice_manager::expire_pending();
        if ($count > 0) {
            mtrace("[local_grupomakro_core] expired {$count} pending module invoice request(s).");
        }
    }
}