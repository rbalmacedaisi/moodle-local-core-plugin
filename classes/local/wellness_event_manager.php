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
 * Manager for wellness events: CRUD over gmk_wellness_event +
 * gmk_wellness_event_attachment, plus the public catalogue used by the LXP
 * (RF-02, RF-04, RF-05, RF-09.2).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local;

defined('MOODLE_INTERNAL') || die();

class wellness_event_manager {

    /** Allowed values for the category column. */
    public const CATEGORIES = ['deportivo', 'feria', 'taller', 'charla', 'otro'];

    /** Allowed values for the modality column. */
    public const MODALITIES = ['presencial', 'virtual', 'mixto'];

    /**
     * LXP catalogue. Honors visibility window, registration window and
     * active flag. Expired events are excluded unless $includepast=true.
     *
     * @param string $keyword Optional search on title/summary.
     * @param string $category Optional category filter; '' = all.
     * @param int $from Unix ts; 0 = from now.
     * @param int $to Unix ts; 0 = unbounded.
     * @param bool $includepast When true, includes events whose enddate < from.
     * @param int $now Reference ts for windows. Defaults to time().
     * @return array<int,object>
     */
    public static function list_for_students(
        string $keyword = '',
        string $category = '',
        int $from = 0,
        int $to = 0,
        bool $includepast = false,
        int $now = 0
    ): array {
        global $DB;
        $now = $now ?: time();

        $sql = "SELECT e.id, e.title, e.summary, e.description, e.category,
                       e.startdate, e.enddate, e.modality, e.location, e.virtual_url,
                       e.capacity, e.requires_registration, e.allow_waitlist,
                       e.registration_opens_at, e.registration_closes_at,
                       e.organizer_name, e.organizer_email, e.cover_path,
                       e.active, e.timecreated
                  FROM {gmk_wellness_event} e
                 WHERE e.active = 1";
        $params = [];

        if (!$includepast) {
            $sql .= ' AND (e.enddate = 0 OR e.enddate >= :nowend)';
            $params['nowend'] = $now;
        }
        if ($from > 0) {
            $sql .= ' AND (e.enddate = 0 OR e.enddate >= :fromstart)';
            $params['fromstart'] = $from;
        }
        if ($to > 0) {
            $sql .= ' AND e.startdate <= :toend';
            $params['toend'] = $to;
        }
        if (in_array($category, self::CATEGORIES, true)) {
            $sql .= ' AND e.category = :cat';
            $params['cat'] = $category;
        }
        if (trim($keyword) !== '') {
            $kw = $DB->sql_like_escape(trim($keyword));
            $sql .= ' AND ( ' . $DB->sql_like('e.title', ':kw1', false) . ' OR '
                          . $DB->sql_like('e.summary', ':kw2', false) . ' )';
            $params['kw1'] = '%' . $kw . '%';
            $params['kw2'] = '%' . $kw . '%';
        }
        $sql .= ' ORDER BY e.startdate ASC, e.timecreated DESC';

        $rows = $DB->get_records_sql($sql, $params);
        return array_values(array_map(function ($r) use ($now) {
            $r->id                     = (int)$r->id;
            $r->startdate              = (int)$r->startdate;
            $r->enddate                = (int)$r->enddate;
            $r->capacity               = (int)$r->capacity;
            $r->requires_registration  = (int)$r->requires_registration;
            $r->allow_waitlist         = (int)$r->allow_waitlist;
            $r->registration_opens_at  = (int)$r->registration_opens_at;
            $r->registration_closes_at = (int)$r->registration_closes_at;
            $r->timecreated            = (int)$r->timecreated;

            $r->registration_open = self::is_registration_open($r, $now);
            $r->event_started    = $r->startdate > 0 && $r->startdate <= $now;
            $r->event_ended      = $r->enddate   > 0 && $r->enddate   <= $now;
            return $r;
        }, $rows));
    }

    /**
     * Admin catalogue (includes inactive rows).
     *
     * @return array<int,object>
     */
    public static function list_for_admin(): array {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT e.*,
                    (SELECT COUNT(*) FROM {gmk_wellness_registration} r
                      WHERE r.eventid = e.id AND r.status NOT IN ('cancelada')) AS registered_count
               FROM {gmk_wellness_event} e
           ORDER BY e.startdate DESC, e.timecreated DESC"
        );
        return array_values(array_map(function ($r) {
            $r->id                     = (int)$r->id;
            $r->startdate              = (int)$r->startdate;
            $r->enddate                = (int)$r->enddate;
            $r->capacity               = (int)$r->capacity;
            $r->requires_registration  = (int)$r->requires_registration;
            $r->allow_waitlist         = (int)$r->allow_waitlist;
            $r->registration_opens_at  = (int)$r->registration_opens_at;
            $r->registration_closes_at = (int)$r->registration_closes_at;
            $r->active                 = (int)$r->active;
            $r->registered_count       = (int)($r->registered_count ?? 0);
            $r->timecreated            = (int)$r->timecreated;
            $r->timemodified           = (int)$r->timemodified;
            return $r;
        }, $rows));
    }

    /**
     * Return a single event enriched with attachments and the registered
     * count. Returns null if the row doesn't exist.
     */
    public static function get(int $id): ?object {
        global $DB;
        $row = $DB->get_record('gmk_wellness_event', ['id' => $id]);
        if (!$row) {
            return null;
        }
        $row->attachments = self::list_attachments($id);
        $row->registered_count = (int)$DB->count_records('gmk_wellness_registration',
            ['eventid' => $id]);
        $row->registered_confirmed = (int)$DB->count_records('gmk_wellness_registration',
            ['eventid' => $id, 'status' => 'confirmada']);
        $row->registered_waitlist = (int)$DB->count_records('gmk_wellness_registration',
            ['eventid' => $id, 'status' => 'lista_de_espera']);
        foreach (['startdate', 'enddate', 'capacity', 'requires_registration',
                  'allow_waitlist', 'registration_opens_at', 'registration_closes_at',
                  'active', 'timecreated', 'timemodified', 'usermodified'] as $f) {
            if (isset($row->$f)) {
                $row->$f = (int)$row->$f;
            }
        }
        return $row;
    }

    /**
     * Read attachments attached to an event, ordered by sort.
     *
     * @return array<int,object>
     */
    public static function list_attachments(int $eventid): array {
        global $DB;
        $rows = $DB->get_records('gmk_wellness_event_attachment',
            ['eventid' => $eventid], 'sort, id');
        return array_values(array_map(function ($r) {
            $r->id         = (int)$r->id;
            $r->eventid    = (int)$r->eventid;
            $r->filesize   = (int)$r->filesize;
            $r->sort       = (int)$r->sort;
            $r->timecreated = (int)$r->timecreated;
            return $r;
        }, $rows));
    }

    /**
     * Upsert an event. Returns the row id.
     *
     * @param array $payload Fields + attachments (key 'attachments' => [{kind,label,url,file_path,mimetype,filesize,sort}]).
     * @param int $authorid Moodle user id writing the row.
     */
    public static function upsert(array $payload, int $authorid): int {
        global $DB;
        $now = time();

        $title = trim((string)($payload['title'] ?? ''));
        if ($title === '') {
            throw new \moodle_exception('wellness_event_title_required', 'local_grupomakro_core');
        }
        $category = strtolower(trim((string)($payload['category'] ?? 'otro')));
        if (!in_array($category, self::CATEGORIES, true)) {
            $category = 'otro';
        }
        $modality = strtolower(trim((string)($payload['modality'] ?? 'presencial')));
        if (!in_array($modality, self::MODALITIES, true)) {
            $modality = 'presencial';
        }
        $start = (int)($payload['startdate'] ?? 0);
        $end   = (int)($payload['enddate'] ?? 0);
        if ($start <= 0) {
            throw new \moodle_exception('wellness_event_start_required', 'local_grupomakro_core');
        }
        if ($end > 0 && $end < $start) {
            throw new \moodle_exception('wellness_event_end_before_start', 'local_grupomakro_core');
        }

        $record = (object)[
            'title'                 => mb_substr($title, 0, 255),
            'summary'               => mb_substr((string)($payload['summary'] ?? ''), 0, 255),
            'description'           => (string)($payload['description'] ?? ''),
            'category'              => $category,
            'startdate'             => $start,
            'enddate'               => $end,
            'modality'              => $modality,
            'location'              => mb_substr((string)($payload['location'] ?? ''), 0, 255),
            'virtual_url'           => mb_substr((string)($payload['virtual_url'] ?? ''), 0, 255),
            'capacity'              => max(0, (int)($payload['capacity'] ?? 0)),
            'requires_registration' => !empty($payload['requires_registration']) ? 1 : 0,
            'allow_waitlist'        => !empty($payload['allow_waitlist']) ? 1 : 0,
            'registration_opens_at' => (int)($payload['registration_opens_at'] ?? 0),
            'registration_closes_at'=> (int)($payload['registration_closes_at'] ?? 0),
            'organizer_name'        => mb_substr((string)($payload['organizer_name'] ?? ''), 0, 128),
            'organizer_email'       => mb_substr((string)($payload['organizer_email'] ?? ''), 0, 255),
            'cover_path'            => (string)($payload['cover_path'] ?? ''),
            'active'                => !empty($payload['active']) ? 1 : 0,
            'usermodified'          => $authorid,
            'timemodified'          => $now,
        ];

        $id = (int)($payload['id'] ?? 0);
        $trans = $DB->start_delegated_transaction();
        try {
            if ($id > 0) {
                if (!$DB->record_exists('gmk_wellness_event', ['id' => $id])) {
                    throw new \moodle_exception('wellness_event_not_found', 'local_grupomakro_core');
                }
                $record->id = $id;
                $record->timecreated = (int)$DB->get_field('gmk_wellness_event', 'timecreated', ['id' => $id]);
                $DB->update_record('gmk_wellness_event', $record);
            } else {
                $record->timecreated = $now;
                $id = (int)$DB->insert_record('gmk_wellness_event', $record);
            }

            // Re-sync attachments: simplest contract is "delete all then insert"
            // because the LXP always sends the full ordered list. Avoids
            // orphaned rows from drags/edits.
            if (array_key_exists('attachments', $payload)) {
                $DB->delete_records('gmk_wellness_event_attachment', ['eventid' => $id]);
                $sort = 0;
                foreach ((array)$payload['attachments'] as $att) {
                    $kind = strtolower(trim((string)($att['kind'] ?? 'handout')));
                    if (!in_array($kind, ['handout', 'recording', 'link', 'other'], true)) {
                        $kind = 'handout';
                    }
                    $DB->insert_record('gmk_wellness_event_attachment', (object)[
                        'eventid'    => $id,
                        'kind'       => $kind,
                        'label'      => mb_substr((string)($att['label'] ?? ''), 0, 128),
                        'url'        => mb_substr((string)($att['url'] ?? ''), 0, 255),
                        'file_path'  => mb_substr((string)($att['file_path'] ?? ''), 0, 255),
                        'mimetype'   => mb_substr((string)($att['mimetype'] ?? ''), 0, 64),
                        'filesize'   => max(0, (int)($att['filesize'] ?? 0)),
                        'sort'       => $sort++,
                        'timecreated'=> $now,
                    ]);
                }
            }
            $trans->allow_commit();
        } catch (\Throwable $e) {
            $trans->rollback($e);
            throw $e;
        }

        return $id;
    }

    /**
     * Soft-delete (active=0).
     */
    public static function set_active(int $id, int $active, int $authorid): bool {
        global $DB;
        if (!$DB->record_exists('gmk_wellness_event', ['id' => $id])) {
            return false;
        }
        $DB->set_field('gmk_wellness_event', 'active', $active ? 1 : 0, ['id' => $id]);
        $DB->set_field('gmk_wellness_event', 'timemodified', time(), ['id' => $id]);
        $DB->set_field('gmk_wellness_event', 'usermodified', $authorid, ['id' => $id]);
        return true;
    }

    /**
     * Resolves whether registration is currently open for a student.
     *
     * Rules:
     *  - requires_registration must be true.
     *  - Active flag.
     *  - registration_opens_at <= now <= registration_closes_at (zero means unbounded).
     *  - Event has not ended yet.
     */
    public static function is_registration_open(object $event, int $now = 0): bool {
        $now = $now ?: time();
        if ((int)$event->active !== 1) {
            return false;
        }
        if ((int)$event->requires_registration !== 1) {
            return false;
        }
        if ($event->registration_opens_at > 0 && $event->registration_opens_at > $now) {
            return false;
        }
        if ($event->registration_closes_at > 0 && $event->registration_closes_at < $now) {
            return false;
        }
        if ($event->enddate > 0 && $event->enddate < $now) {
            return false;
        }
        return true;
    }

    /**
     * Return a (csv) export of the registrations to a single event.
     *
     * @return string CSV body with header row.
     */
    public static function export_registrations_csv(int $eventid): string {
        global $DB;
        $event = $DB->get_record('gmk_wellness_event', ['id' => $eventid], 'id, title, startdate');
        if (!$event) {
            throw new \moodle_exception('wellness_event_not_found', 'local_grupomakro_core');
        }

        $rows = $DB->get_records_sql(
            "SELECT r.status, r.modality, r.registered_at, r.cancelled_at, r.attended_at, r.source,
                    u.id AS userid, u.firstname, u.lastname, u.email,
                    u.username
               FROM {gmk_wellness_registration} r
               JOIN {user} u ON u.id = r.userid
              WHERE r.eventid = :eid
           ORDER BY r.registered_at ASC",
            ['eid' => $eventid]
        );

        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, ['userid', 'username', 'firstname', 'lastname', 'email',
                      'status', 'modality', 'registered_at', 'attended_at',
                      'cancelled_at', 'source']);
        // Excel/LibreOffice interpret values starting with =, +, -, @ as
        // formulas (CSV injection). Prefix an apostrophe so the cell renders
        // as plain text. Numeric and timestamp columns are safe by construction.
        $csvSafe = function (string $v): string {
            if ($v === '') return $v;
            $first = $v[0];
            if (in_array($first, ['=', '+', '-', '@'], true)) {
                return "'" . $v;
            }
            return $v;
        };
        foreach ($rows as $r) {
            fputcsv($fh, [
                (int)$r->userid,
                $csvSafe((string)$r->username),
                $csvSafe((string)$r->firstname),
                $csvSafe((string)$r->lastname),
                $csvSafe((string)$r->email),
                $csvSafe((string)$r->status),
                $csvSafe((string)$r->modality),
                (int)$r->registered_at ? userdate((int)$r->registered_at) : '',
                (int)$r->attended_at   ? userdate((int)$r->attended_at)   : '',
                (int)$r->cancelled_at  ? userdate((int)$r->cancelled_at)  : '',
                $csvSafe((string)$r->source),
            ]);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return (string)$csv;
    }
}
