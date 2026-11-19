<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Cartas (20261001130): servicios de la bandeja del personal
 * (local_grupomakro_letters_desk_*) y de metricas
 * (local_grupomakro_letters_sla_metrics). Todos exigen managerequests, salvo
 * las metricas (letters_viewmetrics). Identidad = $USER siempre.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\letter;

use context_system;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_grupomakro_core\local\letters\desk;
use local_grupomakro_core\local\letters\metrics;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class desk_api extends external_api {

    // ----- list -----

    public static function listing_parameters(): external_function_parameters {
        return new external_function_parameters([
            'tab' => new external_value(PARAM_ALPHA, 'mine | unassigned | overdue | pickup | legacy | open | closed | all',
                VALUE_DEFAULT, 'mine'),
            'search' => new external_value(PARAM_TEXT, 'Name, email, document number or #id', VALUE_DEFAULT, ''),
            'lettertypeid' => new external_value(PARAM_INT, 'Letter type (0 = all)', VALUE_DEFAULT, 0),
            'status' => new external_value(PARAM_ALPHANUMEXT, 'Status (empty = all)', VALUE_DEFAULT, ''),
            'assignedto' => new external_value(PARAM_INT, '-1 all, 0 unassigned, >0 owner', VALUE_DEFAULT, -1),
            'from' => new external_value(PARAM_INT, 'Created from (timestamp)', VALUE_DEFAULT, 0),
            'to' => new external_value(PARAM_INT, 'Created until (timestamp)', VALUE_DEFAULT, 0),
            'page' => new external_value(PARAM_INT, 'Page', VALUE_DEFAULT, 0),
            'perpage' => new external_value(PARAM_INT, 'Per page', VALUE_DEFAULT, 30),
        ]);
    }

    public static function listing($tab, $search, $lettertypeid, $status, $assignedto, $from, $to, $page, $perpage): array {
        global $USER;
        $p = self::validate_parameters(self::listing_parameters(), compact('tab', 'search', 'lettertypeid', 'status',
            'assignedto', 'from', 'to', 'page', 'perpage'));
        self::validate_context(context_system::instance());
        $out = desk::listing((int)$USER->id, (string)$p['tab'], [
            'search' => $p['search'], 'lettertypeid' => $p['lettertypeid'], 'status' => $p['status'],
            'assignedto' => $p['assignedto'], 'from' => $p['from'], 'to' => $p['to'],
        ], (int)$p['page'], (int)$p['perpage']);
        $counts = [];
        foreach ($out['counts'] as $k => $n) {
            $counts[] = ['tab' => $k, 'count' => $n];
        }
        global $DB;
        $types = [];
        foreach ($DB->get_records('gmk_letter_type', null, 'name', 'id, name, active') as $t) {
            $types[] = ['id' => (int)$t->id, 'name' => format_string($t->name), 'active' => (int)$t->active];
        }
        return ['items' => $out['items'], 'total' => $out['total'], 'tab' => $out['tab'], 'counts' => $counts,
            'types' => $types, 'assignable' => desk::assignable((int)$USER->id),
            'canmetrics' => has_capability('local/grupomakro_core:letters_viewmetrics', context_system::instance())];
    }

    public static function listing_returns(): external_single_structure {
        return new external_single_structure([
            'items' => new external_multiple_structure(structures::desk_row()),
            'total' => new external_value(PARAM_INT, 'Total in this tab'),
            'tab' => new external_value(PARAM_ALPHA, 'Tab shown'),
            'counts' => new external_multiple_structure(new external_single_structure([
                'tab' => new external_value(PARAM_ALPHA, 'Tab'),
                'count' => new external_value(PARAM_INT, 'Count'),
            ])),
            'types' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Type id'),
                'name' => new external_value(PARAM_TEXT, 'Type'),
                'active' => new external_value(PARAM_INT, 'Active'),
            ])),
            'assignable' => self::assignable_structure(),
            'canmetrics' => new external_value(PARAM_BOOL, 'May see the metrics'),
        ]);
    }

    private static function assignable_structure(): external_multiple_structure {
        return new external_multiple_structure(new external_single_structure([
            'id' => new external_value(PARAM_INT, 'User id'),
            'fullname' => new external_value(PARAM_TEXT, 'Name'),
            'load' => new external_value(PARAM_INT, 'Open letters owned'),
        ]));
    }

    // ----- get -----

    public static function get_parameters(): external_function_parameters {
        return new external_function_parameters(['requestid' => new external_value(PARAM_INT, 'Request id')]);
    }

    public static function get($requestid): array {
        global $USER;
        $p = self::validate_parameters(self::get_parameters(), ['requestid' => $requestid]);
        self::validate_context(context_system::instance());
        return desk::detail((int)$p['requestid'], (int)$USER->id);
    }

    public static function get_returns(): external_single_structure {
        return structures::desk_detail();
    }

    // ----- action -----

    public static function action_parameters(): external_function_parameters {
        return new external_function_parameters([
            'requestid' => new external_value(PARAM_INT, 'Request id'),
            'action' => new external_value(PARAM_ALPHANUMEXT, 'take | assign | note | message | generate | ready_pickup | '
                . 'delivered | handled | keep | reject | cancel | delete'),
            'targetuserid' => new external_value(PARAM_INT, 'Owner for assign', VALUE_DEFAULT, 0),
            'reason' => new external_value(PARAM_TEXT, 'Reason / comment', VALUE_DEFAULT, ''),
            'note' => new external_value(PARAM_TEXT, 'Internal note', VALUE_DEFAULT, ''),
            'body' => new external_value(PARAM_RAW, 'Message to the student (ticket)', VALUE_DEFAULT, ''),
        ]);
    }

    public static function action($requestid, $action, $targetuserid, $reason, $note, $body): array {
        global $USER;
        $p = self::validate_parameters(self::action_parameters(), compact('requestid', 'action', 'targetuserid',
            'reason', 'note', 'body'));
        self::validate_context(context_system::instance());
        $out = desk::action((int)$p['requestid'], (int)$USER->id, (string)$p['action'], $p);
        $ret = ['ticketid' => (int)$out['ticketid'], 'deleted' => $out['request'] === null];
        if ($out['request'] !== null) {
            $ret['request'] = $out['request'];
        }
        return $ret;
    }

    public static function action_returns(): external_single_structure {
        return new external_single_structure([
            'request' => new external_single_structure(structures::desk_row()->keys, 'Row after the action', VALUE_OPTIONAL),
            'ticketid' => new external_value(PARAM_INT, 'Ticket written to (message)'),
            'deleted' => new external_value(PARAM_BOOL, 'The request no longer exists'),
        ]);
    }

    // ----- bulk -----

    public static function bulk_parameters(): external_function_parameters {
        return new external_function_parameters([
            'requestids' => new external_multiple_structure(new external_value(PARAM_INT, 'Request id')),
            'action' => new external_value(PARAM_ALPHANUMEXT, 'take | assign | ready_pickup | delivered | handled | keep | cancel'),
            'targetuserid' => new external_value(PARAM_INT, 'Owner for assign', VALUE_DEFAULT, 0),
            'reason' => new external_value(PARAM_TEXT, 'Reason (cancel)', VALUE_DEFAULT, ''),
            'dryrun' => new external_value(PARAM_BOOL, 'Only report what would change', VALUE_DEFAULT, true),
        ]);
    }

    public static function bulk($requestids, $action, $targetuserid, $reason, $dryrun): array {
        global $USER;
        $p = self::validate_parameters(self::bulk_parameters(), compact('requestids', 'action', 'targetuserid',
            'reason', 'dryrun'));
        self::validate_context(context_system::instance());
        return desk::bulk($p['requestids'], (int)$USER->id, (string)$p['action'],
            ['targetuserid' => $p['targetuserid'], 'reason' => $p['reason']], (bool)$p['dryrun']);
    }

    public static function bulk_returns(): external_single_structure {
        $item = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Request id'),
            'reason' => new external_value(PARAM_TEXT, 'Why'),
        ]);
        return new external_single_structure([
            'done' => new external_multiple_structure(new external_value(PARAM_INT, 'Request id')),
            'skipped' => new external_multiple_structure($item),
            'failed' => new external_multiple_structure($item),
        ]);
    }

    // ----- metrics (dashboard administrativo) -----

    public static function metrics_parameters(): external_function_parameters {
        return new external_function_parameters([
            'from' => new external_value(PARAM_INT, 'Summary from (0 = 30 days ago)', VALUE_DEFAULT, 0),
            'to' => new external_value(PARAM_INT, 'Summary until (0 = now)', VALUE_DEFAULT, 0),
            'lettertypeid' => new external_value(PARAM_INT, 'Filter by type (0 = all)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function metrics($from, $to, $lettertypeid): array {
        $p = self::validate_parameters(self::metrics_parameters(), compact('from', 'to', 'lettertypeid'));
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:letters_viewmetrics', $context);
        $now = time();
        $to = (int)$p['to'] ?: $now;
        $from = (int)$p['from'] ?: $to - 30 * DAYSECS;
        $owners = [];
        foreach (metrics::pending_by_owner(['lettertypeid' => (int)$p['lettertypeid']], $now) as $o) {
            $bystatus = [];
            foreach ($o['by_status'] as $s => $n) {
                $bystatus[] = ['status' => $s, 'count' => $n];
            }
            $o['by_status'] = $bystatus;
            $o['roles'] = implode(',', $o['roles']);
            $o['sla_compliance_30d'] = $o['sla_compliance_30d'] === null ? -1 : (float)$o['sla_compliance_30d'];
            $owners[] = $o;
        }
        $summary = [];
        foreach (metrics::summary($from, $to) as $s) {
            foreach (['within_sla_pct', 'cycle_median_minutes', 'cycle_p90_minutes'] as $k) {
                $s[$k] = $s[$k] === null ? -1 : (float)$s[$k];
            }
            $summary[] = $s;
        }
        return ['owners' => $owners, 'summary' => $summary, 'from' => $from, 'to' => $to];
    }

    public static function metrics_returns(): external_single_structure {
        return new external_single_structure([
            'owners' => new external_multiple_structure(new external_single_structure([
                'userid' => new external_value(PARAM_INT, 'Owner (0 = unassigned)'),
                'fullname' => new external_value(PARAM_TEXT, 'Name'),
                'roles' => new external_value(PARAM_TEXT, 'System roles, comma separated'),
                'total' => new external_value(PARAM_INT, 'Open letters'),
                'by_status' => new external_multiple_structure(new external_single_structure([
                    'status' => new external_value(PARAM_ALPHANUMEXT, 'Status'),
                    'count' => new external_value(PARAM_INT, 'Count'),
                ])),
                'on_time' => new external_value(PARAM_INT, 'Level 0'),
                'due_soon' => new external_value(PARAM_INT, 'Level 1'),
                'overdue' => new external_value(PARAM_INT, 'Level 2'),
                'critical' => new external_value(PARAM_INT, 'Level 3'),
                'oldest_minutes' => new external_value(PARAM_INT, 'Oldest, business minutes in its status'),
                'avg_minutes' => new external_value(PARAM_INT, 'Average business minutes in status'),
                'sla_compliance_30d' => new external_value(PARAM_FLOAT, '% finished in 30 d without being overdue (-1 = n/a)'),
            ])),
            'summary' => new external_multiple_structure(new external_single_structure([
                'lettertypeid' => new external_value(PARAM_INT, 'Type'),
                'name' => new external_value(PARAM_TEXT, 'Type name'),
                'created' => new external_value(PARAM_INT, 'Created in the period'),
                'finished' => new external_value(PARAM_INT, 'Ready or delivered'),
                'rejected' => new external_value(PARAM_INT, 'Rejected'),
                'cancelled' => new external_value(PARAM_INT, 'Cancelled'),
                'open' => new external_value(PARAM_INT, 'Still open'),
                'within_sla_pct' => new external_value(PARAM_FLOAT, '% finished without being overdue (-1 = n/a)'),
                'cycle_median_minutes' => new external_value(PARAM_FLOAT, 'Median cycle, business minutes (-1 = n/a)'),
                'cycle_p90_minutes' => new external_value(PARAM_FLOAT, 'P90 cycle, business minutes (-1 = n/a)'),
            ])),
            'from' => new external_value(PARAM_INT, 'From'),
            'to' => new external_value(PARAM_INT, 'To'),
        ]);
    }
}
