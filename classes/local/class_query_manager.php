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
 * Query builder for the paginated classmanagement view.
 *
 * Centralises the WHERE / ORDER BY / LIMIT clauses used by the
 * local_grupomakro_list_classes_paged web service so that the page and any
 * future caller (reports, exports, etc.) stay in sync.
 *
 * The manager deliberately does NOT do the bulk-prefetch enrichment of
 * list_classes() in locallib.php. The flow is:
 *
 *   1. fetch_page() returns a set of class ids (cheap SQL with LIMIT/OFFSET).
 *   2. The WS calls list_classes(['id' => $ids]) so the same N+1 fix from
 *      version 20260807000 keeps enriching only the rows we render.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Static helper that builds the SQL pieces for the paginated class list.
 */
class class_query_manager {

    /** Whitelisted sort columns (prevent SQL injection through `sort`). */
    public const SORT_COLUMNS = [
        'name'        => 'c.name',
        'timecreated' => 'c.timecreated',
        'timemodified'=> 'c.timemodified',
        'startdate'   => 'c.initdate',
        'enddate'     => 'c.enddate',
        'periodid'    => 'c.periodid',
        'instructor'  => 'u.lastname',
    ];

    /**
     * Build the WHERE clause + bound params for the gmk_class search.
     *
     * Accepted keys in $filters:
     *   - search         (string) Free text matched against name, shift,
     *                              instructor firstname/lastname,
     *                              local_learning_plans.name,
     *                              course.fullname.
     *   - periodid       (int)    gmk_academic_periods.id (institutional).
     *   - learningplanid (int)    local_learning_plans.id.
     *   - corecourseid   (int)    course.id (the "asignatura" filter).
     *   - status         (string) 'active' (closed=0), 'closed' (closed=1),
     *                              'all' (no filter). Default 'active'.
     *
     * @param array $filters
     * @param array $params   Out param: bound parameter map (named).
     * @return string         WHERE fragment (without the leading WHERE).
     */
    public static function build_where(array $filters, array &$params): string {
        global $DB;

        $where = ['1=1'];

        // Status (active = closed=0 is the default for the page).
        $status = isset($filters['status']) ? (string)$filters['status'] : 'active';
        if ($status === 'active') {
            $where[] = 'c.closed = 0';
        } else if ($status === 'closed') {
            $where[] = 'c.closed = 1';
        }
        // 'all' adds no constraint.

        if (!empty($filters['periodid'])) {
            $where[] = 'c.periodid = :periodid';
            $params['periodid'] = (int)$filters['periodid'];
        }
        if (!empty($filters['learningplanid'])) {
            $where[] = 'c.learningplanid = :lpid';
            $params['lpid'] = (int)$filters['learningplanid'];
        }
        if (!empty($filters['corecourseid'])) {
            $where[] = 'c.corecourseid = :ccid';
            $params['ccid'] = (int)$filters['corecourseid'];
        }

        // Free-text search across 5 fields. We use LIKE OR conditions
        // instead of FULLTEXT because the columns live in joined tables
        // (user, local_learning_plans, course) and the existing data set
        // is small enough that the LIKE index hits still hit the new
        // gmkcls_* indexes on c.* for the leading WHERE clauses.
        if (!empty($filters['search'])) {
            $search = trim((string)$filters['search']);
            if ($search !== '') {
                $like = '%' . $DB->sql_like_escape($search) . '%';
                $where[] = '('
                    . $DB->sql_like('c.name', ':sname', false) . ' OR '
                    . $DB->sql_like('c.shift', ':sshift', false) . ' OR '
                    . $DB->sql_like('u.firstname', ':sfn', false) . ' OR '
                    . $DB->sql_like('u.lastname', ':sln', false) . ' OR '
                    . $DB->sql_like('lp.name', ':slpn', false) . ' OR '
                    . $DB->sql_like('co.fullname', ':scofn', false)
                    . ')';
                $params['sname']   = $like;
                $params['sshift']  = $like;
                $params['sfn']     = $like;
                $params['sln']     = $like;
                $params['slpn']    = $like;
                $params['scofn']   = $like;
            }
        }

        return implode(' AND ', $where);
    }

    /**
     * Build the FROM/JOIN fragment that must accompany build_where().
     *
     * Kept separate so callers can reuse the WHERE on a count query
     * without re-typing the JOINs.
     *
     * @return string
     */
    public static function build_from(): string {
        return '{gmk_class} c '
            . 'LEFT JOIN {user} u                   ON u.id = c.instructorid '
            . 'LEFT JOIN {local_learning_plans} lp  ON lp.id = c.learningplanid '
            . 'LEFT JOIN {course} co                ON co.id = c.corecourseid';
    }

    /**
     * Count classes that match the given filters.
     *
     * @param array $filters
     * @return int
     */
    public static function count_filtered(array $filters): int {
        global $DB;
        $params = [];
        $where = self::build_where($filters, $params);
        $from = self::build_from();
        return (int)$DB->count_records_sql("SELECT COUNT(1) FROM $from WHERE $where", $params);
    }

    /**
     * Return a page of class IDs matching the filters.
     *
     * @param array  $filters
     * @param int    $page    0-based
     * @param int    $perpage
     * @param string $sort    One of self::SORT_COLUMNS keys.
     * @param string $dir     'ASC' or 'DESC'.
     * @return int[]          Ordered list of gmk_class.id (PK).
     */
    public static function fetch_page(array $filters, int $page, int $perpage, string $sort = 'timecreated', string $dir = 'DESC'): array {
        global $DB;

        $params = [];
        $where = self::build_where($filters, $params);
        $from = self::build_from();

        $sortcol = self::SORT_COLUMNS[$sort] ?? self::SORT_COLUMNS['timecreated'];
        $dir = (strtoupper($dir) === 'ASC') ? 'ASC' : 'DESC';

        // NOTE: Moodle 4.0.x's get_fieldset_sql() signature is
        //   get_fieldset_sql($sql, array $params = null)
        // and does NOT accept limitfrom/limitnum. We use get_records_sql()
        // instead (which DOES support LIMIT/OFFSET) and then extract the
        // ids in the same order the SQL returned them.
        $sql = "SELECT c.id FROM $from WHERE $where ORDER BY $sortcol $dir, c.id $dir";
        $records = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);

        $ids = [];
        foreach ($records as $rec) {
            $ids[] = (int)$rec->id;
        }
        return $ids;
    }

    /**
     * Facets for the filter bar: which periods and core courses actually
     * have at least one class? Without this the dropdowns would list every
     * period / course in the DB (including those with no classes).
     *
     * @return array{periods: array<int,array{id:int,name:string,count:int}>,
     *               courses: array<int,array{id:int,name:string,count:int}>}
     */
    public static function list_class_facets(): array {
        global $DB;

        $periods = [];
        $prows = $DB->get_records_sql(
            "SELECT p.id, p.name, COUNT(c.id) AS classcount
               FROM {gmk_academic_periods} p
               JOIN {gmk_class} c ON c.periodid = p.id
           GROUP BY p.id, p.name
           ORDER BY p.startdate DESC, p.id DESC"
        );
        foreach ($prows as $r) {
            $periods[] = [
                'id'    => (int)$r->id,
                'name'  => (string)$r->name,
                'count' => (int)$r->classcount,
            ];
        }

        $courses = [];
        $crows = $DB->get_records_sql(
            "SELECT co.id, co.fullname, COUNT(c.id) AS classcount
               FROM {course} co
               JOIN {gmk_class} c ON c.corecourseid = co.id
           GROUP BY co.id, co.fullname
           ORDER BY co.fullname ASC"
        );
        foreach ($crows as $r) {
            $courses[] = [
                'id'    => (int)$r->id,
                'name'  => (string)$r->fullname,
                'count' => (int)$r->classcount,
            ];
        }

        return ['periods' => $periods, 'courses' => $courses];
    }

    /**
     * Build the paginator HTML for the toolbar. Used by both the server-
     * side render (pages/classmanagement.php) and the AMD module
     * (amd/src/class_management_filters.js) so the markup stays in sync.
     *
     * @param int $total      Total classes matching the current filter.
     * @param int $totalpages Total pages.
     * @param int $page       Current page (0-based).
     * @return string         HTML to inject into the paginator container.
     */
    public static function build_paginator_html(int $total, int $totalpages, int $page): string {
        $disabled = $totalpages <= 1;
        $isFirst = $page <= 0;
        $isLast = $page >= $totalpages - 1;

        $pageLabel = get_string('classmgmt:page_x_of_y', 'local_grupomakro_core', (object)[
            'page'  => max(1, $page + 1),
            'total' => max(1, $totalpages),
        ]);

        if ($disabled) {
            return '<span class="text-muted small">' . s($pageLabel) . '</span>';
        }

        $prevLabel = get_string('classmgmt:previous_page', 'local_grupomakro_core');
        $nextLabel = get_string('classmgmt:next_page', 'local_grupomakro_core');

        $firstDisabled = $isFirst ? 'disabled' : '';
        $prevDisabled = $isFirst ? 'disabled' : '';
        $nextDisabled = $isLast ? 'disabled' : '';
        $lastDisabled = $isLast ? 'disabled' : '';

        return ''
            . '<button type="button" class="btn btn-sm btn-outline-secondary" data-page-action="first" ' . $firstDisabled . ' title="Primera">'
            . '<i class="fa fa-step-backward"></i></button> '
            . '<button type="button" class="btn btn-sm btn-outline-secondary" data-page-action="prev" ' . $prevDisabled . '>'
            . '<i class="fa fa-chevron-left"></i> ' . s($prevLabel) . '</button> '
            . '<span class="mx-2 small text-muted">' . s($pageLabel) . '</span> '
            . '<button type="button" class="btn btn-sm btn-outline-secondary" data-page-action="next" ' . $nextDisabled . '>'
            . s($nextLabel) . ' <i class="fa fa-chevron-right"></i></button> '
            . '<button type="button" class="btn btn-sm btn-outline-secondary" data-page-action="last" ' . $lastDisabled . ' title="Ultima">'
            . '<i class="fa fa-step-forward"></i></button>';
    }
}

if (!function_exists('local_grupomakro_core_build_paginator_html')) {
    /**
     * Procedural wrapper so the page can call it without referencing the
     * fully-qualified class name.
     */
    function local_grupomakro_core_build_paginator_html(int $total, int $totalpages, int $page): string {
        return \local_grupomakro_core\local\class_query_manager::build_paginator_html($total, $totalpages, $page);
    }
}
