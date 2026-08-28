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
 * Manager for the wellness partner directory (RF-01, RF-09.1).
 *
 * CRUD over gmk_wellness_partner_cats and gmk_wellness_partner plus the
 * catalogue read paths the LXP needs (search by keyword + filter by category,
 * visibility window, fileareas for logos).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local;

defined('MOODLE_INTERNAL') || die();

class wellness_partner_manager {

    /**
     * Allowed values for the gmk_wellness_partner_cats table.
     */
    public static function allowed_categories(): array {
        return ['salud', 'retail', 'tecnologia', 'educacion', 'ocio', 'transporte', 'otro'];
    }

    /**
     * Default seed catalogue. Imported on first install via CLI; admins may
     * edit/extend through the back-office.
     *
     * @return array<int,array{key:string,label:string,sort:int}>
     */
    public static function seed_categories(): array {
        return [
            ['key' => 'salud',       'label' => 'Salud',         'sort' => 10],
            ['key' => 'educacion',   'label' => 'EducaciÃ³n',     'sort' => 20],
            ['key' => 'tecnologia',  'label' => 'TecnologÃ­a',    'sort' => 30],
            ['key' => 'retail',      'label' => 'Retail',        'sort' => 40],
            ['key' => 'transporte',  'label' => 'Transporte',    'sort' => 50],
            ['key' => 'ocio',        'label' => 'Ocio y cultura','sort' => 60],
            ['key' => 'otro',        'label' => 'Otro',          'sort' => 99],
        ];
    }

    /**
     * Seed categories on a fresh install. Idempotent: skips rows whose name
     * already exists.
     *
     * @return int Number of categories created.
     */
    public static function seed_categories_if_empty(): int {
        global $DB;
        if ($DB->record_exists('gmk_wellness_partner_cats', [])) {
            return 0;
        }
        $now = time();
        $created = 0;
        foreach (self::seed_categories() as $seed) {
            $record = (object)[
                'name'         => $seed['label'],
                'slug'         => $seed['key'],
                'sort'         => $seed['sort'],
                'active'       => 1,
                'usermodified' => 0,
                'timecreated'  => $now,
                'timemodified' => $now,
            ];
            $DB->insert_record('gmk_wellness_partner_cats', $record);
            $created++;
        }
        return $created;
    }

    /**
     * Public catalogue used by the LXP. Filters out inactive rows, hides
     * partners outside the validity window, supports keyword search and an
     * optional category filter.
     *
     * @param string $keyword Search applied against name/benefit_description (LIKE).
     * @param int $categoryid Optional category id; 0 = all.
     * @param int $now Reference unix ts (defaults to current time, exposed for tests).
     * @return array<int,object>
     */
    public static function list_for_students(string $keyword = '', int $categoryid = 0, int $now = 0): array {
        global $DB;
        $now = $now ?: time();
        $nows = (string)$now;

        $sql = "SELECT p.id, p.name, p.categoryid, p.benefit_description, p.conditions,
                       p.requirements, p.startdate, p.enddate,
                       p.contact_label, p.contact_value, p.logo_path,
                       p.sort, p.timecreated,
                       c.name AS category_name, c.slug AS category_slug
                  FROM {gmk_wellness_partner} p
                  JOIN {gmk_wellness_partner_cats} c ON c.id = p.categoryid
                 WHERE p.active = 1
                   AND c.active = 1
                   AND (p.startdate = 0 OR p.startdate <= :now1)
                   AND (p.enddate   = 0 OR p.enddate   >= :now2)";
        $params = ['now1' => $now, 'now2' => $now];

        if ($categoryid > 0) {
            $sql .= ' AND p.categoryid = :cid';
            $params['cid'] = $categoryid;
        }
        if (trim($keyword) !== '') {
            $sql .= ' AND ( ' . $DB->sql_like('p.name', ':kw1', false) . ' OR '
                          . $DB->sql_like('p.benefit_description', ':kw2', false) . ' )';
            $params['kw1'] = '%' . trim($keyword) . '%';
            $params['kw2'] = '%' . trim($keyword) . '%';
        }
        $sql .= ' ORDER BY c.sort, p.sort, p.name';

        $rows = $DB->get_records_sql($sql, $params);
        return array_values(array_map(function ($r) use ($now) {
            $r->id            = (int)$r->id;
            $r->categoryid    = (int)$r->categoryid;
            $r->startdate     = (int)$r->startdate;
            $r->enddate       = (int)$r->enddate;
            $r->sort          = (int)$r->sort;
            $r->is_expired    = $r->enddate > 0 && $r->enddate < $now;
            $r->is_future     = $r->startdate > $now;
            return $r;
        }, $rows));
    }

    /**
     * Admin catalogue: includes inactive rows and the unfiltered list. Used
     * by the back-office Vue table.
     *
     * @return array<int,object>
     */
    public static function list_for_admin(): array {
        global $DB;
        $sql = "SELECT p.*, c.name AS category_name, c.slug AS category_slug
                  FROM {gmk_wellness_partner} p
                  JOIN {gmk_wellness_partner_cats} c ON c.id = p.categoryid
              ORDER BY c.sort, p.sort, p.name";
        $rows = $DB->get_records_sql($sql);
        return array_values(array_map(function ($r) {
            $r->id            = (int)$r->id;
            $r->categoryid    = (int)$r->categoryid;
            $r->startdate     = (int)$r->startdate;
            $r->enddate       = (int)$r->enddate;
            $r->sort          = (int)$r->sort;
            $r->active        = (int)$r->active;
            $r->timecreated   = (int)$r->timecreated;
            $r->timemodified  = (int)$r->timemodified;
            return $r;
        }, $rows));
    }

    /**
     * Return the flat list of active categories for filter dropdowns.
     *
     * @return array<int,array{id:int,name:string,slug:string}>
     */
    public static function list_categories(): array {
        global $DB;
        $rows = $DB->get_records('gmk_wellness_partner_cats',
            ['active' => 1], 'sort, name', 'id, name, slug');
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['id' => (int)$r->id, 'name' => (string)$r->name, 'slug' => (string)$r->slug];
        }
        return $out;
    }

    /**
     * Read a single partner row in the admin-shape.
     */
    public static function get(int $id): ?object {
        global $DB;
        $row = $DB->get_record('gmk_wellness_partner', ['id' => $id]);
        if (!$row) {
            return null;
        }
        return self::cast($row);
    }

    /**
     * Upsert a partner. Returns the row id.
     *
     * @param array $payload Associative input. Required: name, categoryid, benefit_description.
     *                       Optional: conditions, requirements, startdate, enddate,
     *                       contact_label, contact_value, logo_path, sort, active.
     * @param int $authorid Moodle user id writing the row.
     */
    public static function upsert(array $payload, int $authorid): int {
        global $DB;

        $name   = trim((string)($payload['name'] ?? ''));
        if ($name === '') {
            throw new \moodle_exception('wellness_partner_name_required', 'local_grupomakro_core');
        }
        $categoryid = (int)($payload['categoryid'] ?? 0);
        if ($categoryid <= 0 || !$DB->record_exists('gmk_wellness_partner_cats', ['id' => $categoryid])) {
            throw new \moodle_exception('wellness_partner_category_required', 'local_grupomakro_core');
        }
        $benefit = trim((string)($payload['benefit_description'] ?? ''));
        if ($benefit === '') {
            throw new \moodle_exception('wellness_partner_benefit_required', 'local_grupomakro_core');
        }

        $now = time();
        $record = (object)[
            'name'                => mb_substr($name, 0, 255),
            'categoryid'          => $categoryid,
            'benefit_description' => $benefit,
            'conditions'          => (string)($payload['conditions'] ?? ''),
            'requirements'        => (string)($payload['requirements'] ?? ''),
            'startdate'           => (int)($payload['startdate'] ?? 0),
            'enddate'             => (int)($payload['enddate'] ?? 0),
            'contact_label'       => mb_substr((string)($payload['contact_label'] ?? ''), 0, 64),
            'contact_value'       => mb_substr((string)($payload['contact_value'] ?? ''), 0, 255),
            'logo_path'           => (string)($payload['logo_path'] ?? ''),
            'sort'                => (int)($payload['sort'] ?? 0),
            'active'              => !empty($payload['active']) ? 1 : 0,
            'usermodified'        => $authorid,
            'timemodified'        => $now,
        ];

        $id = (int)($payload['id'] ?? 0);
        if ($id > 0) {
            $existing = $DB->get_record('gmk_wellness_partner', ['id' => $id]);
            if (!$existing) {
                throw new \moodle_exception('wellness_partner_not_found', 'local_grupomakro_core');
            }
            $record->id = $id;
            $record->timecreated = (int)$existing->timecreated ?: $now;
            $DB->update_record('gmk_wellness_partner', $record);
            return $id;
        }

        $record->timecreated = $now;
        return (int)$DB->insert_record('gmk_wellness_partner', $record);
    }

    /**
     * Soft-delete (active=0). Hard delete is intentionally not exposed.
     */
    public static function set_active(int $id, int $active, int $authorid): bool {
        global $DB;
        if (!$DB->record_exists('gmk_wellness_partner', ['id' => $id])) {
            return false;
        }
        $DB->set_field('gmk_wellness_partner', 'active', $active ? 1 : 0, ['id' => $id]);
        $DB->set_field('gmk_wellness_partner', 'timemodified', time(), ['id' => $id]);
        $DB->set_field('gmk_wellness_partner', 'usermodified', $authorid, ['id' => $id]);
        return true;
    }

    /**
     * Save the logo path picked by the upload helper. The actual file lives
     * in the filearea `wellness_partner_logo` and the path is what the
     * frontend appends to `pluginfile.php`.
     */
    public static function update_logo_path(int $id, string $path): bool {
        global $DB;
        if (!$DB->record_exists('gmk_wellness_partner', ['id' => $id])) {
            return false;
        }
        $DB->set_field('gmk_wellness_partner', 'logo_path', $path, ['id' => $id]);
        $DB->set_field('gmk_wellness_partner', 'timemodified', time(), ['id' => $id]);
        return true;
    }

    /**
     * Cast a raw DB row to integer fields so the frontend does not need
     * to coerce booleans.
     */
    private static function cast(object $row): object {
        foreach (['id', 'categoryid', 'startdate', 'enddate', 'sort', 'active', 'timecreated', 'timemodified', 'usermodified'] as $f) {
            if (isset($row->$f)) {
                $row->$f = (int)$row->$f;
            }
        }
        return $row;
    }
}
