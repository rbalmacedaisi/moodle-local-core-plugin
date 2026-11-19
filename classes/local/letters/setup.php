<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Cartas (20261001130): carga inicial y limpieza del atraso.
 *
 * seed(): aditivo e idempotente (no borra nada): tema "Cartas y constancias"
 * con su equipo (roles gmk_secretaria_academica y gmk_registros_academicos),
 * problema comun de cartas en ese tema con boton al portal, plantillas
 * letter_*, sello/firma apagados donde no hay imagen y punto de partida de los
 * plazos de las solicitudes abiertas (marcadas "legacy": la bandeja pregunta si
 * ya se gestionaron).
 *
 * dedupe(): solicitudes abiertas repetidas (mismo estudiante y tipo): se
 * conserva la mas reciente y las demas se anulan EN SILENCIO con motivo
 * "Solicitud duplicada", guardando en el evento el estado anterior (reversible).
 * Con $apply = false solo informa (cli/letters_backlog.php).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\letters;

defined('MOODLE_INTERNAL') || die();

class setup {

    const CATEGORY_NAME = 'Cartas y constancias';

    const TEAM_ROLES = ['gmk_secretaria_academica', 'gmk_registros_academicos'];

    /**
     * @return array what was done
     */
    public static function seed(int $now = 0): array {
        global $DB;
        $now = $now ?: time();
        $done = [];

        // 1) Tema de la mesa de ayuda + equipo.
        $area = $DB->get_record('gmk_tkt_area', ['code' => 'academico']);
        $categoryid = (int)get_config('local_grupomakro_core', team::CATEGORY_CONFIG);
        if ($area && (!$categoryid || !$DB->record_exists('gmk_tkt_category', ['id' => $categoryid]))) {
            $existing = $DB->get_record('gmk_tkt_category', ['areaid' => (int)$area->id, 'name' => self::CATEGORY_NAME]);
            if ($existing) {
                $categoryid = (int)$existing->id;
            } else {
                $other = $DB->get_record('gmk_tkt_category', ['areaid' => (int)$area->id, 'name' => 'Otro']);
                $sort = $other ? max(0, (int)$other->sortorder - 1)
                    : (int)$DB->get_field_sql('SELECT MAX(sortorder) FROM {gmk_tkt_category} WHERE areaid = ?',
                        [(int)$area->id]) + 1;
                $categoryid = (int)$DB->insert_record('gmk_tkt_category', (object)[
                    'areaid' => (int)$area->id, 'name' => self::CATEGORY_NAME,
                    'description' => 'Preguntas sobre una carta o constancia ya solicitada.',
                    'confidential' => 0, 'requires_attachment' => 0, 'autoassign' => 1, 'priority_default' => 1,
                    'sla_first_response_min' => 20, 'sla_resolution_min' => 0, 'active' => 1, 'sortorder' => $sort,
                    'timecreated' => $now, 'timemodified' => $now,
                ]);
                $done[] = 'tema ' . $categoryid;
            }
            set_config(team::CATEGORY_CONFIG, $categoryid, 'local_grupomakro_core');
            team::reset_caches();
        }
        if ($categoryid > 0 && !$DB->record_exists('gmk_tkt_agent', ['categoryid' => $categoryid])) {
            [$in, $params] = $DB->get_in_or_equal(self::TEAM_ROLES, SQL_PARAMS_NAMED, 'r');
            $users = $DB->get_fieldset_sql("SELECT DISTINCT ra.userid
                  FROM {role_assignments} ra
                  JOIN {role} ro ON ro.id = ra.roleid
                  JOIN {context} c ON c.id = ra.contextid AND c.contextlevel = " . CONTEXT_SYSTEM . "
                  JOIN {user} u ON u.id = ra.userid AND u.deleted = 0 AND u.suspended = 0
                 WHERE ro.shortname $in", $params);
            foreach ($users as $uid) {
                $DB->insert_record('gmk_tkt_agent', (object)['categoryid' => $categoryid, 'userid' => (int)$uid,
                    'can_auto_assign' => 1, 'max_open' => 0, 'timecreated' => $now, 'timemodified' => $now]);
            }
            $done[] = 'equipo ' . count($users);
        }

        // 2) Problema comun de cartas: a este tema y con boton al portal.
        if ($area && $categoryid > 0) {
            $faqs = $DB->get_records_select('gmk_tkt_faq', 'areaid = :a AND (' . $DB->sql_like('problem', ':p1', false)
                . ' OR ' . $DB->sql_like('problem', ':p2', false) . ')',
                ['a' => (int)$area->id, 'p1' => '%carta%', 'p2' => '%constancia%']);
            foreach ($faqs as $faq) {
                $update = ['id' => (int)$faq->id, 'categoryid' => $categoryid, 'timemodified' => $now];
                $links = json_decode((string)$faq->links, true);
                if (empty($links)) {
                    $update['links'] = json_encode([['label' => 'Ir a Solicitudes de Cartas',
                        'url' => '/solicitudes-cartas', 'kind' => 'portal']]);
                }
                $DB->update_record('gmk_tkt_faq', (object)$update);
                $done[] = 'faq ' . (int)$faq->id;
            }
        }

        // 3) Plantillas de mensajes automaticos.
        foreach (tickets::TEMPLATE_SEED as $code => [$name, $body]) {
            if (!$DB->record_exists('gmk_tkt_template', ['code' => $code])) {
                $DB->insert_record('gmk_tkt_template', (object)['code' => $code, 'name' => $name, 'body' => $body,
                    'enabled' => 1, 'timemodified' => $now]);
            }
        }

        // 4) Sello/firma activados sin imagen: no salen, se apagan (decision 6).
        foreach ($DB->get_records('gmk_letter_type') as $t) {
            $u = [];
            if (!empty($t->autostamp) && trim((string)$t->stampimageurl) === '') {
                $u['autostamp'] = 0;
            }
            if (!empty($t->autosignature) && trim((string)$t->signatureimageurl) === '') {
                $u['autosignature'] = 0;
            }
            if ($u) {
                $DB->update_record('gmk_letter_type', (object)(['id' => (int)$t->id, 'timemodified' => $now] + $u));
                $done[] = 'sello/firma tipo ' . (int)$t->id;
            }
        }

        // 5) Solicitudes abiertas: entrada al estado actual y marca "legacy".
        [$in, $params] = $DB->get_in_or_equal(manager::OPEN_STATUSES, SQL_PARAMS_NAMED, 'st');
        $types = $DB->get_records('gmk_letter_type');
        $n = 0;
        foreach ($DB->get_records_select('gmk_letter_request', "status $in AND status_since = 0", $params) as $r) {
            $since = (int)$DB->get_field_sql("SELECT MAX(timecreated) FROM {gmk_letter_request_event}
                 WHERE requestid = :r AND eventtype = 'status_change' AND newstatus = :s",
                ['r' => (int)$r->id, 's' => (string)$r->status]) ?: (int)$r->timemodified;
            $type = $types[(int)$r->lettertypeid] ?? null;
            $budget = sla::budget($type, (string)$r->status);
            $DB->update_record('gmk_letter_request', (object)[
                'id' => (int)$r->id,
                'status_since' => $since,
                'due_at' => $budget > 0 ? \local_grupomakro_core\local\support\business_hours::add_minutes($since, $budget,
                    team::area()) : 0,
                'legacy' => in_array((string)$r->status, team::OWNED_STATUSES, true) ? 1 : 0,
            ]);
            $n++;
        }
        $done[] = 'abiertas ' . $n;
        return $done;
    }

    /**
     * Repeated open requests (same student and type): keep the newest.
     *
     * @return array [[keep, cancel[], userid, lettertypeid, status]]
     */
    public static function duplicates(): array {
        global $DB;
        [$in, $params] = $DB->get_in_or_equal(manager::OPEN_STATUSES, SQL_PARAMS_NAMED, 'st');
        $rows = $DB->get_records_select('gmk_letter_request', "status $in", $params, 'userid, lettertypeid, id DESC',
            'id, userid, lettertypeid, status');
        $groups = [];
        foreach ($rows as $r) {
            $groups[(int)$r->userid . '-' . (int)$r->lettertypeid][] = $r;
        }
        $out = [];
        foreach ($groups as $g) {
            if (count($g) < 2) {
                continue;
            }
            $keep = array_shift($g);
            $out[] = ['keep' => (int)$keep->id, 'cancel' => array_map(function($r) {
                return (int)$r->id;
            }, $g), 'userid' => (int)$keep->userid, 'lettertypeid' => (int)$keep->lettertypeid,
                'statuses' => array_map(function($r) {
                    return (string)$r->status;
                }, $g)];
        }
        return $out;
    }

    /**
     * Cancels the duplicates silently (no student notification). Reversible:
     * the event keeps the previous status.
     *
     * @return int cancelled
     */
    public static function dedupe(bool $apply): int {
        $n = 0;
        foreach (self::duplicates() as $g) {
            foreach ($g['cancel'] as $id) {
                if ($apply) {
                    manager::set_request_status($id, manager::STATUS_CANCELADA, 0,
                        'Solicitud duplicada: se mantiene la #' . $g['keep'], ['duplicate_of' => $g['keep']],
                        ['notify' => false]);
                }
                $n++;
            }
        }
        return $n;
    }
}
