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
 * Mesa de ayuda: lectura y escritura de la configuracion (§12-bis).
 *
 * Cada cambio deja un evento en gmk_tkt_event (ticketid 0, eventtype
 * config_<seccion>) con quien, cuando, valor anterior y valor nuevo. Nada se
 * borra si ya lo usan tickets: en ese caso se desactiva.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\support;

use invalid_parameter_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

class settings {

    const SECTIONS = ['area', 'category', 'agent', 'backup', 'person', 'template', 'config', 'presence', 'agenthours'];

    public static function export(): array {
        global $DB;
        $areas = [];
        foreach ($DB->get_records('gmk_tkt_area', null, 'sortorder, id') as $a) {
            $areas[] = [
                'id' => (int)$a->id, 'code' => $a->code, 'name' => $a->name, 'description' => (string)$a->description,
                'icon' => $a->icon, 'hours' => json_decode((string)$a->hours, true) ?: new stdClass(),
                'hourstext' => business_hours::describe($a),
                'external_url' => $a->external_url, 'external_label' => $a->external_label,
                'external_message' => (string)$a->external_message, 'teacher_visible' => (int)$a->teacher_visible,
                'active' => (int)$a->active, 'sortorder' => (int)$a->sortorder,
            ];
        }
        $categories = [];
        foreach ($DB->get_records('gmk_tkt_category', null, 'areaid, sortorder, id') as $c) {
            $categories[] = [
                'id' => (int)$c->id, 'areaid' => (int)$c->areaid, 'name' => $c->name, 'description' => (string)$c->description,
                'confidential' => (int)$c->confidential, 'requires_attachment' => (int)$c->requires_attachment,
                'autoassign' => (int)$c->autoassign, 'priority_default' => (int)$c->priority_default,
                'sla_first_response_min' => (int)$c->sla_first_response_min, 'active' => (int)$c->active,
                'sortorder' => (int)$c->sortorder,
            ];
        }
        $agents = $DB->get_records('gmk_tkt_agent', null, 'categoryid, id');
        $backups = $DB->get_records('gmk_tkt_backup', null, 'userid, sortorder, id');
        $persons = $DB->get_records('gmk_tkt_person', null, 'kind, id');
        $userids = [];
        foreach ($agents as $r) {
            $userids[] = $r->userid;
        }
        foreach ($backups as $r) {
            $userids[] = $r->userid;
            $userids[] = $r->backupuserid;
        }
        foreach ($persons as $r) {
            $userids[] = $r->userid;
        }
        $users = manager::load_users($userids, true);
        $name = function($id) use ($users) {
            return isset($users[(int)$id]) ? fullname($users[(int)$id]) : ('Usuario ' . (int)$id . ' (no existe)');
        };
        $presence = [];
        foreach ($DB->get_records('gmk_tkt_presence') as $p) {
            $presence[(int)$p->userid] = (int)$p->available;
        }
        $out = [
            'areas' => $areas,
            'categories' => $categories,
            'agents' => array_values(array_map(function($r) use ($name, $presence) {
                return ['id' => (int)$r->id, 'categoryid' => (int)$r->categoryid, 'userid' => (int)$r->userid,
                    'fullname' => $name($r->userid), 'can_auto_assign' => (int)$r->can_auto_assign,
                    'max_open' => (int)$r->max_open, 'available' => $presence[(int)$r->userid] ?? 1];
            }, $agents)),
            'backups' => array_values(array_map(function($r) use ($name) {
                return ['id' => (int)$r->id, 'userid' => (int)$r->userid, 'fullname' => $name($r->userid),
                    'backupuserid' => (int)$r->backupuserid, 'backupname' => $name($r->backupuserid),
                    'categoryid' => (int)$r->categoryid, 'sortorder' => (int)$r->sortorder];
            }, $backups)),
            'persons' => array_values(array_map(function($r) use ($name) {
                return ['id' => (int)$r->id, 'kind' => $r->kind, 'userid' => (int)$r->userid, 'fullname' => $name($r->userid)];
            }, $persons)),
            'templates' => array_values(array_map(function($t) {
                return ['id' => (int)$t->id, 'code' => $t->code, 'name' => $t->name, 'body' => (string)$t->body,
                    'enabled' => (int)$t->enabled];
            }, $DB->get_records('gmk_tkt_template', null, 'id'))),
            'config' => config::all(),
            'agenthours' => self::export_agent_hours(),
            // Names for the per-person incisive profiles (phase 2).
            'usernames' => (function() {
                $users = manager::load_users(array_keys((array)(config::get_json('tkt_incisive')['users'] ?? [])));
                $out = new stdClass();
                foreach ($users as $u) {
                    $out->{(string)$u->id} = fullname($u);
                }
                return $out;
            })(),
            'variables' => ['{numero}', '{categoria}', '{area}', '{agente}', '{horario}', '{estudiante}'],
            'audit' => self::recent_audit(),
        ];
        return $out;
    }

    /**
     * Every agent (or backup) with their own hours, or the areas they inherit from.
     */
    private static function export_agent_hours(): array {
        global $DB;
        $areasof = [];
        foreach ($DB->get_records_sql("SELECT a.id, a.userid, c.areaid
                                           FROM {gmk_tkt_agent} a
                                           JOIN {gmk_tkt_category} c ON c.id = a.categoryid") as $r) {
            $areasof[(int)$r->userid][(int)$r->areaid] = true;
        }
        $ids = array_keys($areasof);
        foreach ($DB->get_records_select('gmk_tkt_presence', 'hours IS NOT NULL', [], '', 'id, userid') as $r) {
            $ids[] = (int)$r->userid;
        }
        $users = manager::load_users($ids);
        $presence = [];
        foreach ($DB->get_records('gmk_tkt_presence') as $r) {
            $presence[(int)$r->userid] = $r;
        }
        $out = [];
        foreach ($users as $u) {
            $own = trim((string)($presence[(int)$u->id]->hours ?? ''));
            $areanames = [];
            foreach (array_keys($areasof[(int)$u->id] ?? []) as $aid) {
                $area = policy::area($aid);
                if ($area) {
                    $areanames[] = format_string($area->name) . ($area->hours ? ' (' . business_hours::describe($area) . ')' : '');
                }
            }
            $out[] = [
                'userid' => (int)$u->id,
                'fullname' => fullname($u),
                'hours' => $own !== '' ? (json_decode($own, true) ?: new stdClass()) : null,
                'hourstext' => $own !== '' ? business_hours::describe((object)['hours' => $own]) : '',
                'inherits' => implode('; ', $areanames),
            ];
        }
        usort($out, function($a, $b) {
            return strcmp($a['fullname'], $b['fullname']);
        });
        return $out;
    }

    private static function recent_audit(): array {
        global $DB;
        $rows = $DB->get_records_select('gmk_tkt_event', "ticketid = 0", [], 'id DESC', '*', 0, 50);
        $users = manager::load_users(array_map(function($r) {
            return (int)$r->actorid;
        }, $rows));
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['id' => (int)$r->id, 'eventtype' => $r->eventtype,
                'actorname' => isset($users[(int)$r->actorid]) ? fullname($users[(int)$r->actorid]) : 'Sistema',
                'oldvalue' => (string)$r->oldvalue, 'newvalue' => (string)$r->newvalue, 'timecreated' => (int)$r->timecreated];
        }
        return $out;
    }

    public static function apply(string $section, string $op, array $data, int $actorid): void {
        global $DB;
        if (!in_array($section, self::SECTIONS, true) || !in_array($op, ['save', 'delete'], true)) {
            throw new invalid_parameter_exception('Unknown section or op');
        }
        $transaction = $DB->start_delegated_transaction();
        $method = $op . '_' . $section;
        if (!method_exists(self::class, $method)) {
            throw new invalid_parameter_exception('Operation not supported: ' . $op . ' ' . $section);
        }
        [$old, $new] = self::$method($data, $actorid);
        manager::log_event(0, 'config_' . $section, $old === null ? '' : json_encode($old), $new === null ? '' : json_encode($new),
            $actorid, ['op' => $op]);
        $transaction->allow_commit();
        manager::reset_caches();
    }

    // --- Areas ------------------------------------------------------------

    private static function save_area(array $d): array {
        global $DB;
        $now = time();
        $row = (object)[
            'name' => self::text($d, 'name', 100, true),
            'description' => self::text($d, 'description', 1000),
            'icon' => self::text($d, 'icon', 60),
            'hours' => isset($d['hours']) && $d['hours'] !== ''
                ? business_hours::normalise(is_string($d['hours']) ? $d['hours'] : json_encode($d['hours'])) : '',
            'external_url' => self::url($d['external_url'] ?? ''),
            'external_label' => self::text($d, 'external_label', 100),
            'external_message' => self::text($d, 'external_message', 1000),
            'teacher_visible' => self::flag($d, 'teacher_visible'),
            'active' => self::flag($d, 'active', 1),
            'sortorder' => (int)($d['sortorder'] ?? 0),
            'timemodified' => $now,
        ];
        $id = (int)($d['id'] ?? 0);
        if ($id > 0) {
            $old = $DB->get_record('gmk_tkt_area', ['id' => $id], '*', MUST_EXIST);
            $row->id = $id;
            $DB->update_record('gmk_tkt_area', $row);
            return [$old, $row];
        }
        $code = clean_param((string)($d['code'] ?? ''), PARAM_ALPHANUMEXT);
        if ($code === '' || $DB->record_exists('gmk_tkt_area', ['code' => $code])) {
            throw new invalid_parameter_exception('Código de área vacío o repetido');
        }
        $row->code = $code;
        $row->timecreated = $now;
        $row->id = $DB->insert_record('gmk_tkt_area', $row);
        return [null, $row];
    }

    private static function delete_area(array $d): array {
        global $DB;
        $old = $DB->get_record('gmk_tkt_area', ['id' => (int)($d['id'] ?? 0)], '*', MUST_EXIST);
        if ($DB->record_exists('gmk_tkt_category', ['areaid' => $old->id])
                || $DB->record_exists('gmk_tkt_ticket', ['areaid' => $old->id])) {
            throw new invalid_parameter_exception('El área tiene temas o tickets: desactívala en lugar de borrarla.');
        }
        $DB->delete_records('gmk_tkt_area', ['id' => $old->id]);
        return [$old, null];
    }

    // --- Topics -----------------------------------------------------------

    private static function save_category(array $d): array {
        global $DB;
        $now = time();
        $areaid = (int)($d['areaid'] ?? 0);
        if (!$DB->record_exists('gmk_tkt_area', ['id' => $areaid])) {
            throw new invalid_parameter_exception('Área inválida');
        }
        $priority = (int)($d['priority_default'] ?? 1);
        $row = (object)[
            'areaid' => $areaid,
            'name' => self::text($d, 'name', 150, true),
            'description' => self::text($d, 'description', 1000),
            'confidential' => self::flag($d, 'confidential'),
            'requires_attachment' => self::flag($d, 'requires_attachment'),
            'autoassign' => self::flag($d, 'autoassign', 1),
            'priority_default' => max(0, min(2, $priority)),
            'sla_first_response_min' => max(0, (int)($d['sla_first_response_min'] ?? 0)),
            'active' => self::flag($d, 'active', 1),
            'sortorder' => (int)($d['sortorder'] ?? 0),
            'timemodified' => $now,
        ];
        $id = (int)($d['id'] ?? 0);
        if ($id > 0) {
            $old = $DB->get_record('gmk_tkt_category', ['id' => $id], '*', MUST_EXIST);
            $row->id = $id;
            $DB->update_record('gmk_tkt_category', $row);
            return [$old, $row];
        }
        $row->sla_resolution_min = 0;
        $row->timecreated = $now;
        $row->id = $DB->insert_record('gmk_tkt_category', $row);
        return [null, $row];
    }

    private static function delete_category(array $d): array {
        global $DB;
        $old = $DB->get_record('gmk_tkt_category', ['id' => (int)($d['id'] ?? 0)], '*', MUST_EXIST);
        if ($DB->record_exists('gmk_tkt_ticket', ['categoryid' => $old->id])) {
            throw new invalid_parameter_exception('El tema tiene tickets: desactívalo en lugar de borrarlo.');
        }
        $DB->delete_records('gmk_tkt_agent', ['categoryid' => $old->id]);
        $DB->delete_records('gmk_tkt_backup', ['categoryid' => $old->id]);
        $DB->delete_records('gmk_tkt_category', ['id' => $old->id]);
        return [$old, null];
    }

    // --- Agents, backups, persons ----------------------------------------------

    private static function save_agent(array $d): array {
        global $DB;
        $now = time();
        $id = (int)($d['id'] ?? 0);
        $row = (object)[
            'can_auto_assign' => self::flag($d, 'can_auto_assign', 1),
            'max_open' => max(0, (int)($d['max_open'] ?? 0)),
            'timemodified' => $now,
        ];
        if ($id > 0) {
            $old = $DB->get_record('gmk_tkt_agent', ['id' => $id], '*', MUST_EXIST);
            $row->id = $id;
            $DB->update_record('gmk_tkt_agent', $row);
            return [$old, $row];
        }
        $row->categoryid = (int)($d['categoryid'] ?? 0);
        $row->userid = self::user($d['userid'] ?? 0);
        if (!$DB->record_exists('gmk_tkt_category', ['id' => $row->categoryid])) {
            throw new invalid_parameter_exception('Tema inválido');
        }
        if ($DB->record_exists('gmk_tkt_agent', ['categoryid' => $row->categoryid, 'userid' => $row->userid])) {
            throw new invalid_parameter_exception('Esa persona ya es agente de ese tema');
        }
        $row->timecreated = $now;
        $row->id = $DB->insert_record('gmk_tkt_agent', $row);
        return [null, $row];
    }

    private static function delete_agent(array $d): array {
        global $DB;
        $old = $DB->get_record('gmk_tkt_agent', ['id' => (int)($d['id'] ?? 0)], '*', MUST_EXIST);
        $DB->delete_records('gmk_tkt_agent', ['id' => $old->id]);
        return [$old, null];
    }

    private static function save_backup(array $d): array {
        global $DB;
        $row = (object)[
            'userid' => self::user($d['userid'] ?? 0),
            'backupuserid' => self::user($d['backupuserid'] ?? 0),
            'categoryid' => max(0, (int)($d['categoryid'] ?? 0)),
            'sortorder' => (int)($d['sortorder'] ?? 0),
        ];
        if ($row->userid === $row->backupuserid) {
            throw new invalid_parameter_exception('Una persona no puede ser su propio respaldo');
        }
        if ($row->categoryid > 0 && !$DB->record_exists('gmk_tkt_category', ['id' => $row->categoryid])) {
            throw new invalid_parameter_exception('Tema inválido');
        }
        $id = (int)($d['id'] ?? 0);
        if ($id > 0) {
            $old = $DB->get_record('gmk_tkt_backup', ['id' => $id], '*', MUST_EXIST);
            $row->id = $id;
            $DB->update_record('gmk_tkt_backup', $row);
            return [$old, $row];
        }
        $row->timecreated = time();
        $row->id = $DB->insert_record('gmk_tkt_backup', $row);
        return [null, $row];
    }

    private static function delete_backup(array $d): array {
        global $DB;
        $old = $DB->get_record('gmk_tkt_backup', ['id' => (int)($d['id'] ?? 0)], '*', MUST_EXIST);
        $DB->delete_records('gmk_tkt_backup', ['id' => $old->id]);
        return [$old, null];
    }

    private static function save_person(array $d): array {
        global $DB;
        $kind = (string)($d['kind'] ?? '');
        if (!in_array($kind, ['supervisor', 'gerencia'], true)) {
            throw new invalid_parameter_exception('Tipo inválido');
        }
        $row = (object)['kind' => $kind, 'userid' => self::user($d['userid'] ?? 0), 'timecreated' => time()];
        if ($DB->record_exists('gmk_tkt_person', ['kind' => $kind, 'userid' => $row->userid])) {
            throw new invalid_parameter_exception('Esa persona ya está en la lista');
        }
        $row->id = $DB->insert_record('gmk_tkt_person', $row);
        return [null, $row];
    }

    private static function delete_person(array $d): array {
        global $DB;
        $old = $DB->get_record('gmk_tkt_person', ['id' => (int)($d['id'] ?? 0)], '*', MUST_EXIST);
        $DB->delete_records('gmk_tkt_person', ['id' => $old->id]);
        return [$old, null];
    }

    private static function save_presence(array $d, int $actorid): array {
        $userid = self::user($d['userid'] ?? 0);
        $old = manager::is_available($userid);
        $new = !empty($d['available']);
        manager::set_availability($userid, $new, $actorid);
        return [['userid' => $userid, 'available' => $old], ['userid' => $userid, 'available' => $new]];
    }

    /**
     * Own business hours of an agent; empty = back to the area's hours.
     */
    private static function save_agenthours(array $d, int $actorid): array {
        global $DB;
        $userid = self::user($d['userid'] ?? 0);
        $raw = $d['hours'] ?? '';
        $hours = null;
        if ($raw !== '' && $raw !== null) {
            $hours = business_hours::normalise(is_string($raw) ? $raw : json_encode($raw));
            if (!business_hours::parse($hours)) {
                // An agent "closed every day" makes no sense: treat it as "use the area".
                $hours = null;
            }
        }
        $row = $DB->get_record('gmk_tkt_presence', ['userid' => $userid]);
        $old = $row ? ($row->hours === null ? null : (string)$row->hours) : null;
        if ($row) {
            $DB->update_record('gmk_tkt_presence', (object)['id' => $row->id, 'hours' => $hours, 'modifiedby' => $actorid,
                'timemodified' => time()]);
        } else {
            $DB->insert_record('gmk_tkt_presence', (object)['userid' => $userid, 'available' => 1, 'hours' => $hours,
                'modifiedby' => $actorid, 'timemodified' => time()]);
        }
        policy::reset_caches();
        badge::invalidate($userid);
        return [['userid' => $userid, 'hours' => $old], ['userid' => $userid, 'hours' => $hours]];
    }

    // --- Templates and parameters ---------------------------------------------

    private static function save_template(array $d): array {
        global $DB;
        $old = $DB->get_record('gmk_tkt_template', ['id' => (int)($d['id'] ?? 0)], '*', MUST_EXIST);
        $body = manager::clean_body((string)($d['body'] ?? ''));
        if ($body === '') {
            throw new invalid_parameter_exception('El texto no puede quedar vacío (desactiva el mensaje si no se usa)');
        }
        $row = (object)['id' => $old->id, 'body' => $body, 'enabled' => self::flag($d, 'enabled', 1),
            'timemodified' => time()];
        $DB->update_record('gmk_tkt_template', $row);
        return [$old, $row];
    }

    private static function save_config(array $d): array {
        $name = (string)($d['name'] ?? '');
        $old = config::set($name, $d['value'] ?? '');
        return [[$name => $old], [$name => config::get($name)]];
    }

    // --- Helpers ----------------------------------------------------------------

    private static function text(array $d, string $key, int $max, bool $required = false): string {
        $value = trim(clean_param((string)($d[$key] ?? ''), PARAM_TEXT));
        if ($required && $value === '') {
            throw new invalid_parameter_exception('Falta el campo ' . $key);
        }
        return \core_text::substr($value, 0, $max);
    }

    private static function flag(array $d, string $key, int $default = 0): int {
        return array_key_exists($key, $d) ? (int)!empty($d[$key]) : $default;
    }

    private static function url($value): string {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }
        if (!preg_match('#^https://#i', $value) || clean_param($value, PARAM_URL) !== $value) {
            throw new invalid_parameter_exception('El enlace externo debe ser una URL https válida');
        }
        return \core_text::substr($value, 0, 255);
    }

    private static function user($userid): int {
        global $DB;
        $userid = (int)$userid;
        if ($userid <= 0 || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            throw new invalid_parameter_exception('Usuario inválido');
        }
        return $userid;
    }
}
