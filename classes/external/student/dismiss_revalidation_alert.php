<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Web service: the student dismisses the "revalidation scheduled" popup.
 *
 * Only silences the LXP modal. The revalidation, its invoice and the payment
 * link stay available on the Revalidations page, and a re-scheduled session
 * re-arms the popup (see revalida_manager::create_single_revalidation).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\student;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use context_system;

class dismiss_revalidation_alert extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'User id', VALUE_REQUIRED),
            'revalidationid' => new external_value(PARAM_INT, 'gmk_revalidations.id', VALUE_REQUIRED),
        ]);
    }

    public static function execute($userid, $revalidationid) {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'revalidationid' => $revalidationid,
        ]);

        $context = context_system::instance();
        self::validate_context($context);

        // The row must belong to the user being acted on, so one student can
        // never dismiss another's alert by guessing an id.
        $rec = $DB->get_record('gmk_revalidations', [
            'id' => (int)$params['revalidationid'],
            'userid' => (int)$params['userid'],
        ], 'id, alert_dismissed_at', IGNORE_MISSING);

        if (!$rec) {
            return ['ok' => false, 'dismissed_at' => 0];
        }

        $now = time();
        $DB->update_record('gmk_revalidations', (object)[
            'id' => (int)$rec->id,
            'alert_dismissed_at' => $now,
            'timemodified' => $now,
        ]);

        return ['ok' => true, 'dismissed_at' => $now];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'True when the alert was dismissed'),
            'dismissed_at' => new external_value(PARAM_INT, 'Dismissal timestamp'),
        ]);
    }
}
