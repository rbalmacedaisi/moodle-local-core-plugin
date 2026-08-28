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
 * Wellness dynamic form manager: schema-driven forms attached to events (RF-06).
 *
 * The schema stored in gmk_wellness_dynamic_form.schema_json is a small,
 * documented contract (NOT a full JSON Schema document) with shape:
 *  {
 *    "fields": [
 *      {
 *        "name":     "dietary_preference",
 *        "label":    "RestricciÃ³n alimentaria",
 *        "type":     "text|textarea|select|multiselect|checkbox|number|date",
 *        "required": true,
 *        "options":  ["Vegetariano", "Vegano", "Sin gluten"],
 *        "max":      100,        // text|textarea
 *        "min":      0,          // number
 *        "pattern":  "^[0-9]+$"  // text
 *      },
 *      ...
 *    ]
 *  }
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local;

defined('MOODLE_INTERNAL') || die();

class wellness_dynamic_form_manager {

    /** Allowed field types in the schema. */
    public const FIELD_TYPES = [
        'text', 'textarea', 'select', 'multiselect',
        'checkbox', 'number', 'date', 'email',
    ];

    /**
     * Admin list of all forms (active and inactive).
     *
     * @return array<int,object>
     */
    public static function list_all(): array {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT f.*, e.title AS event_title,
                    (SELECT COUNT(*) FROM {gmk_wellness_form_resp} r
                      WHERE r.formid = f.id) AS response_count
               FROM {gmk_wellness_dynamic_form} f
          LEFT JOIN {gmk_wellness_event} e ON e.id = f.eventid
           ORDER BY f.active DESC, f.timecreated DESC"
        );
        return array_values(array_map(function ($r) {
            $r->id            = (int)$r->id;
            $r->eventid       = (int)$r->eventid;
            $r->active        = (int)$r->active;
            $r->timecreated   = (int)$r->timecreated;
            $r->timemodified  = (int)$r->timemodified;
            $r->response_count = (int)($r->response_count ?? 0);
            return $r;
        }, $rows));
    }

    /**
     * Public read for the LXP: returns the schema of the active form
     * attached to an event, or null if there is none.
     */
    public static function get_for_event(int $eventid): ?object {
        global $DB;
        $row = $DB->get_record('gmk_wellness_dynamic_form',
            ['eventid' => $eventid, 'active' => 1]);
        if (!$row) {
            return null;
        }
        $row->id           = (int)$row->id;
        $row->eventid      = (int)$row->eventid;
        $row->active       = (int)$row->active;
        $row->timecreated  = (int)$row->timecreated;
        $row->timemodified = (int)$row->timemodified;
        $row->schema       = self::decode_schema($row->schema_json);
        return $row;
    }

    /**
     * Upsert a dynamic form. The caller must already have validated the
     * schema shape with self::validate_schema().
     */
    public static function upsert(array $payload, int $authorid): int {
        global $DB;
        $title = trim((string)($payload['title'] ?? ''));
        if ($title === '') {
            throw new \moodle_exception('wellness_form_title_required', 'local_grupomakro_core');
        }
        $schemaRaw = (string)($payload['schema_json'] ?? '');
        if ($schemaRaw === '') {
            throw new \moodle_exception('wellness_form_schema_required', 'local_grupomakro_core');
        }
        $schema = self::decode_schema($schemaRaw);
        $fields = self::validate_schema($schema);

        $now = time();
        $record = (object)[
            'eventid'      => (int)($payload['eventid'] ?? 0),
            'title'        => mb_substr($title, 0, 255),
            'description'  => (string)($payload['description'] ?? ''),
            'schema_json'  => json_encode($schema, JSON_UNESCAPED_UNICODE),
            'active'       => !empty($payload['active']) ? 1 : 0,
            'usermodified' => $authorid,
            'timemodified' => $now,
        ];
        if ($record->eventid > 0 && !$DB->record_exists('gmk_wellness_event', ['id' => $record->eventid])) {
            $record->eventid = 0; // graceful: treat as reusable
        }

        $id = (int)($payload['id'] ?? 0);
        if ($id > 0) {
            if (!$DB->record_exists('gmk_wellness_dynamic_form', ['id' => $id])) {
                throw new \moodle_exception('wellness_form_not_found', 'local_grupomakro_core');
            }
            $record->id = $id;
            $record->timecreated = (int)$DB->get_field('gmk_wellness_dynamic_form', 'timecreated', ['id' => $id]);
            $DB->update_record('gmk_wellness_dynamic_form', $record);
            return $id;
        }
        $record->timecreated = $now;
        return (int)$DB->insert_record('gmk_wellness_dynamic_form', $record);
    }

    public static function set_active(int $id, int $active): bool {
        global $DB;
        if (!$DB->record_exists('gmk_wellness_dynamic_form', ['id' => $id])) {
            return false;
        }
        $DB->set_field('gmk_wellness_dynamic_form', 'active', $active ? 1 : 0, ['id' => $id]);
        $DB->set_field('gmk_wellness_dynamic_form', 'timemodified', time(), ['id' => $id]);
        return true;
    }

    /**
     * Persist a student's submission. UNIQUE (formid, userid) means a second
     * submission overwrites the previous one.
     *
     * @return array{ok:bool, error?:string, responseid?:int}
     */
    public static function submit(int $formid, int $userid, array $answers): array {
        global $DB;
        $form = $DB->get_record('gmk_wellness_dynamic_form', ['id' => $formid]);
        if (!$form || (int)$form->active !== 1) {
            return ['ok' => false, 'error' => 'form_not_found'];
        }
        if (!$DB->record_exists('user', ['id' => $userid, 'deleted' => 0, 'suspended' => 0])) {
            return ['ok' => false, 'error' => 'user_invalid'];
        }

        $schema = self::decode_schema($form->schema_json);
        $errors = self::validate_answers($schema, $answers);
        if (!empty($errors)) {
            return ['ok' => false, 'error' => 'invalid_answers', 'field_errors' => $errors];
        }

        $existing = $DB->get_record('gmk_wellness_form_resp',
            ['formid' => $formid, 'userid' => $userid]);
        $now = time();
        $payload = [
            'formid'       => $formid,
            'userid'       => $userid,
            'eventid'      => (int)$form->eventid,
            'answers_json' => json_encode(self::normalise_answers($answers, $schema), JSON_UNESCAPED_UNICODE),
            'submitted_at' => $now,
        ];
        if ($existing) {
            $payload['id'] = (int)$existing->id;
            $DB->update_record('gmk_wellness_form_resp', (object)$payload);
            return ['ok' => true, 'responseid' => (int)$existing->id];
        }
        $newid = (int)$DB->insert_record('gmk_wellness_form_resp', (object)$payload);
        return ['ok' => true, 'responseid' => $newid];
    }

    /**
     * Return the schema as decoded array.
     */
    public static function decode_schema(string $json): array {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return ['fields' => []];
        }
        if (!isset($decoded['fields']) || !is_array($decoded['fields'])) {
            $decoded['fields'] = [];
        }
        return $decoded;
    }

    /**
     * Throw if schema is malformed. Returns the validated fields list.
     *
     * @return array<int,array> The validated fields array.
     */
    public static function validate_schema(array $schema): array {
        $fields = $schema['fields'] ?? [];
        if (!is_array($fields) || count($fields) === 0) {
            throw new \moodle_exception('wellness_form_schema_empty', 'local_grupomakro_core');
        }
        $seen = [];
        foreach ($fields as $i => $f) {
            if (!is_array($f)) {
                throw new \moodle_exception('wellness_form_field_invalid', 'local_grupomakro_core');
            }
            $name  = trim((string)($f['name'] ?? ''));
            $label = trim((string)($f['label'] ?? ''));
            $type  = strtolower(trim((string)($f['type'] ?? '')));
            if ($name === '' || $label === '' || !in_array($type, self::FIELD_TYPES, true)) {
                throw new \moodle_exception('wellness_form_field_invalid', 'local_grupomakro_core');
            }
            if (in_array($name, $seen, true)) {
                throw new \moodle_exception('wellness_form_field_duplicate', 'local_grupomakro_core');
            }
            $seen[] = $name;
            if (($type === 'select' || $type === 'multiselect') && empty($f['options'])) {
                throw new \moodle_exception('wellness_form_field_options_required', 'local_grupomakro_core');
            }
        }
        return $fields;
    }

    /**
     * Validate a candidate answers object against the schema.
     *
     * @return array<string,string> Map of field_name -> error message (empty if valid).
     */
    public static function validate_answers(array $schema, array $answers): array {
        $errors = [];
        foreach (($schema['fields'] ?? []) as $f) {
            $name = (string)$f['name'];
            $type = (string)$f['type'];
            $required = !empty($f['required']);
            $value = $answers[$name] ?? null;
            $isEmpty = ($value === null || $value === '' || $value === []);

            if ($required && $isEmpty) {
                $errors[$name] = 'required';
                continue;
            }
            if ($isEmpty) {
                continue;
            }

            switch ($type) {
                case 'text':
                case 'textarea':
                    if (!is_string($value)) {
                        $errors[$name] = 'not_string';
                    } else {
                        $max = (int)($f['max'] ?? 0);
                        if ($max > 0 && mb_strlen($value) > $max) {
                            $errors[$name] = 'too_long';
                        }
                    }
                    break;
                case 'email':
                    if (!is_string($value) || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $errors[$name] = 'invalid_email';
                    }
                    break;
                case 'number':
                    if (!is_numeric($value)) {
                        $errors[$name] = 'not_numeric';
                    }
                    break;
                case 'date':
                    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
                        $errors[$name] = 'invalid_date';
                    }
                    break;
                case 'select':
                    if (!in_array($value, (array)($f['options'] ?? []), true)) {
                        $errors[$name] = 'invalid_option';
                    }
                    break;
                case 'multiselect':
                    if (!is_array($value)) {
                        $errors[$name] = 'not_array';
                    } else {
                        foreach ($value as $v) {
                            if (!in_array($v, (array)($f['options'] ?? []), true)) {
                                $errors[$name] = 'invalid_option';
                                break;
                            }
                        }
                    }
                    break;
                case 'checkbox':
                    if (!is_bool($value)) {
                        $errors[$name] = 'not_bool';
                    }
                    break;
            }
        }
        return $errors;
    }

    /**
     * Cast answers to the right scalar per field type so the JSON stored
     * is consistent regardless of what the client sent.
     */
    private static function normalise_answers(array $answers, array $schema): array {
        $out = [];
        foreach (($schema['fields'] ?? []) as $f) {
            $name = (string)$f['name'];
            $type = (string)$f['type'];
            if (!array_key_exists($name, $answers)) {
                if ($type === 'multiselect') { $out[$name] = []; }
                elseif ($type === 'checkbox') { $out[$name] = false; }
                else { $out[$name] = null; }
                continue;
            }
            $value = $answers[$name];
            switch ($type) {
                case 'number':    $out[$name] = is_numeric($value) ? 0 + $value : null; break;
                case 'checkbox':  $out[$name] = (bool)$value; break;
                case 'multiselect':
                    $out[$name] = is_array($value) ? array_values($value) : [];
                    break;
                default:          $out[$name] = (string)$value; break;
            }
        }
        return $out;
    }
}
