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
 * Business logic for the Failed Subjects Report.
 *
 * Identifies active students with failed subjects (status IN 5,7 in
 * gmk_course_progre) and matches them against gmk_class rows projected to
 * open in a given academic period, considering the student's jornada
 * (stored in user_info_field shortname='gmkjourney', kept in sync by
 * moodle_user_sync from Odoo's res.partner.x_studio_jornada).
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

class failed_subjects_manager {

    /** @var int Cache TTL for the Odoo contact lookup (seconds). */
    const CONTACT_CACHE_TTL = 300;

    /** @var int Cache TTL for the full report (seconds). */
    const REPORT_CACHE_TTL = 60;

    /** @var array<int,array{ts:int,data:array}> Per-process contact cache. */
    private static $contactcache = [];

    /** @var array<string,array{ts:int,data:array}> Per-process report cache. */
    private static $reportcache = [];

    /** @var array<int,string> Cached field ids for user_info_field. */
    private static $fieldids = [];

    /**
     * Returns the id of a user_info_field by shortname, cached.
     */
    private static function get_field_id(string $shortname): int {
        global $DB;
        if (isset(self::$fieldids[$shortname])) {
            return self::$fieldids[$shortname];
        }
        $rec = $DB->get_record('user_info_field', ['shortname' => $shortname], 'id', IGNORE_MISSING);
        $id = $rec ? (int)$rec->id : 0;
        self::$fieldids[$shortname] = $id;
        return $id;
    }

    /**
     * Normalize a raw jornada value to a canonical token used by
     * gmk_class.shift (Diurno / Nocturno / Sabatino). Returns '' when
     * the value cannot be mapped.
     */
    public static function normalize_jornada(string $raw): string {
        $s = mb_strtolower(trim($raw), 'UTF-8');
        if ($s === '') {
            return '';
        }
        if (strpos($s, 'diurn') !== false || $s === 'd') {
            return 'Diurno';
        }
        if (strpos($s, 'nocturn') !== false || $s === 'n') {
            return 'Nocturno';
        }
        if (strpos($s, 'sabatin') !== false || $s === 's') {
            return 'Sabatino';
        }
        return ucfirst($s);
    }

    /**
     * Returns true if course fullname is excluded from the report
     * (PRACTICA PROFESIONAL / PROYECTO DE GRADO cannot be re-taken).
     */
    public static function is_excluded_course(string $fullname): bool {
        $fn = mb_strtoupper($fullname, 'UTF-8');
        if (strpos($fn, 'PRACTICA PROFESIONAL') !== false) { return true; }
        if (strpos($fn, 'PRÁCTICA PROFESIONAL') !== false) { return true; }
        if (strpos($fn, 'PROYECTO DE GRADO') !== false) { return true; }
        return false;
    }

    /**
     * Build the failed-subjects report for a given academic period.
     *
     * Each row represents a (student, course) pair where the student has
     * a failed/revalidating subject in their plan and the report is asked
     * whether that course is projected to open in the requested period
     * with a matching jornada. A student can appear multiple times if
     * they have multiple failed subjects.
     *
     * @param int $periodid gmk_academic_periods.id
     * @param array $filters {search, learningplanid, jornada, hasclass, hasquota, financial_status}
     * @param int $page 0-based
     * @param int $perpage
     * @return array{rows:array, total:int, summary:array}
     */
    public static function build_report(int $periodid, array $filters, int $page = 0, int $perpage = 50): array {
        global $DB;

        $cachekey = self::cache_key($periodid, $filters);
        $cached = self::$reportcache[$cachekey] ?? null;
        if ($cached && (time() - $cached['ts']) < self::REPORT_CACHE_TTL) {
            return self::paginate($cached['data'], $page, $perpage);
        }

        $jornadaFieldId = self::get_field_id('gmkjourney');
        $cedulaFieldId  = self::get_field_id('documentnumber');

        $jornadaJoin = '';
        $jornadaSelect = "'' AS jornada";
        if ($jornadaFieldId > 0) {
            $jornadaJoin = "LEFT JOIN {user_info_data} uid_j
                              ON uid_j.userid = u.id AND uid_j.fieldid = $jornadaFieldId";
            $jornadaSelect = "uid_j.data AS jornada";
        }

        $cedulaJoin = '';
        $cedulaSelect = "'' AS cedula";
        if ($cedulaFieldId > 0) {
            $cedulaJoin = "LEFT JOIN {user_info_data} uid_c
                             ON uid_c.userid = u.id AND uid_c.fieldid = $cedulaFieldId";
            $cedulaSelect = "uid_c.data AS cedula";
        }

        // Deduplicate gmk_course_progre: keep only the most recent row
        // per (userid, courseid, learningplanid) — historical data has
        // orphan / duplicated rows that would otherwise explode the
        // report and break get_records_sql() which requires unique
        // first columns.
        $sql = "SELECT cp.id AS progress_id,
                       cp.userid, cp.courseid, cp.grade AS last_grade,
                       cp.timemodified AS failed_at,
                       cp.learningplanid, cp.status AS progress_status,
                       u.firstname, u.lastname, u.email AS user_email, u.idnumber,
                       c.fullname AS coursename,
                       lp.name AS planname,
                       $jornadaSelect,
                       $cedulaSelect
                  FROM {gmk_course_progre} cp
                  JOIN (
                      SELECT userid, courseid, learningplanid, MAX(id) AS maxid
                        FROM {gmk_course_progre}
                       WHERE status IN (5, 7)
                         AND courseid > 0
                    GROUP BY userid, courseid, learningplanid
                  ) latest
                    ON latest.maxid = cp.id
                  JOIN {user} u ON u.id = cp.userid
                       AND u.deleted = 0 AND u.suspended = 0
                  JOIN {local_learning_users} llu
                       ON llu.userid = cp.userid
                      AND llu.userrolename = 'student'
                      AND llu.status = 'activo'
                  JOIN {local_learning_plans} lp
                       ON lp.id = cp.learningplanid
                  JOIN {course} c ON c.id = cp.courseid
                  $jornadaJoin
                  $cedulaJoin
                 ORDER BY u.lastname, u.firstname, c.fullname";

        $records = $DB->get_recordset_sql($sql);
        $list = [];
        foreach ($records as $r) {
            $list[] = $r;
        }
        $records->close();

        // Filter excluded courses (PRACTICA/PROYECTO).
        $records = array_filter($list, function($r) {
            return !self::is_excluded_course($r->coursename);
        });

        // Pre-load all classes projected to open in the requested period.
        $classesByCourseShift = [];
        if ($periodid > 0) {
            $classSql = "SELECT id, courseid, corecourseid, shift, periodid,
                                classroomcapacity, groupid, approved, closed,
                                name AS classname
                           FROM {gmk_class}
                          WHERE periodid = :pid
                            AND closed = 0";
            $cls = $DB->get_records_sql($classSql, ['pid' => $periodid]);
            foreach ($cls as $c) {
                $shift = self::normalize_jornada((string)$c->shift);
                $classesByCourseShift[$c->courseid . '|' . $shift][] = $c;
            }
        }

        // Pre-load groups_members counts per classid in one query to
        // avoid N+1.
        $allClassIds = [];
        foreach ($classesByCourseShift as $list) {
            foreach ($list as $c) { $allClassIds[] = (int)$c->id; }
        }
        $countsByClass = [];
        if (!empty($allClassIds)) {
            [$insql, $inparams] = $DB->get_in_or_equal($allClassIds, SQL_PARAMS_NAMED, 'cid');
            $csql = "SELECT groupid, COUNT(DISTINCT userid) AS c
                       FROM {groups_members}
                      WHERE groupid $insql
                   GROUP BY groupid";
            $crow = $DB->get_records_sql($csql, $inparams);
            foreach ($crow as $g => $row) {
                $countsByClass[(int)$g] = (int)$row->c;
            }
        }

        $rows = [];
        foreach ($records as $r) {
            $jornada = self::normalize_jornada((string)($r->jornada ?? ''));
            $courseClasses = $classesByCourseShift[$r->courseid . '|' . $jornada] ?? [];

            $target = null;
            $enrolled = 0;
            $capacity = 0;
            $isFull = false;
            if (!empty($courseClasses)) {
                // Pick the class with the lowest enrollment so the admin
                // sees the most available option first.
                $best = null;
                foreach ($courseClasses as $cand) {
                    $cnt = (int)($countsByClass[(int)$cand->id] ?? 0);
                    if ($best === null || $cnt < $best['count']) {
                        $best = ['class' => $cand, 'count' => $cnt];
                    }
                }
                if ($best !== null) {
                    $target = $best['class'];
                    $enrolled = $best['count'];
                    $capacity = (int)$target->classroomcapacity;
                    $isFull = $capacity > 0 && $enrolled >= $capacity;
                }
            }

            $contact = self::get_contact_cached((int)$r->userid, $r->cedula);

            $row = [
                'progress_id'        => (int)$r->progress_id,
                'userid'             => (int)$r->userid,
                'student_name'       => trim($r->firstname . ' ' . $r->lastname),
                'student_idnumber'   => (string)($r->idnumber ?? ''),
                'user_email'         => (string)($r->user_email ?? ''),
                'cedula'             => (string)($r->cedula ?? ''),
                'phone'              => $contact['phone'] ?? '',
                'mobile'             => $contact['mobile'] ?? '',
                'contact_email'      => $contact['email'] ?? '',
                'financial_status'   => $contact['financial_status'] ?? '',
                'financial_label'    => $contact['financial_label'] ?? '',
                'jornada_estudiante' => $jornada,
                'courseid'           => (int)$r->courseid,
                'coursename'         => (string)$r->coursename,
                'last_grade'         => (float)$r->last_grade,
                'failed_at'          => (int)$r->failed_at,
                'learningplanid'     => (int)$r->learningplanid,
                'planname'           => (string)$r->planname,
                'progress_status'    => (int)$r->progress_status,
                'classid'            => $target ? (int)$target->id : null,
                'classname'          => $target ? (string)$target->classname : '',
                'corecourseid'       => $target ? (int)$target->corecourseid : (int)$r->courseid,
                'jornada_grupo'      => $target ? self::normalize_jornada((string)$target->shift) : '',
                'jornada_match'      => $target !== null,
                'classroomcapacity'  => $capacity,
                'enrolled_count'     => $enrolled,
                'is_full'            => $isFull,
            ];
            $rows[] = $row;
        }

        // Apply filters.
        $rows = self::apply_filters($rows, $filters);

        // Build summary counts.
        $summary = self::build_summary($rows, $periodid);

        self::$reportcache[$cachekey] = ['ts' => time(), 'data' => $rows];

        return self::paginate_with_summary($rows, $summary, $page, $perpage);
    }

    /**
     * Apply search/jornada/learningplanid/hasclass/hasquota filters.
     */
    private static function apply_filters(array $rows, array $filters): array {
        $search = trim((string)($filters['search'] ?? ''));
        $jornada = self::normalize_jornada((string)($filters['jornada'] ?? ''));
        $lpid = (int)($filters['learningplanid'] ?? 0);
        $hasclass = $filters['hasclass'] ?? null; // 'yes' | 'no' | null
        $hasquota = $filters['hasquota'] ?? null; // 'yes' | 'no' | null
        $fs = trim((string)($filters['financial_status'] ?? ''));

        return array_values(array_filter($rows, function($r) use ($search, $jornada, $lpid, $hasclass, $hasquota, $fs) {
            if ($search !== '') {
                $hay = mb_strtolower($r['student_name'] . ' ' . $r['cedula'] . ' ' . $r['coursename'] . ' ' . $r['student_idnumber'], 'UTF-8');
                if (strpos($hay, mb_strtolower($search, 'UTF-8')) === false) {
                    return false;
                }
            }
            if ($jornada !== '' && $r['jornada_estudiante'] !== $jornada) {
                return false;
            }
            if ($lpid > 0 && $r['learningplanid'] !== $lpid) {
                return false;
            }
            if ($hasclass === 'yes' && !$r['classid']) { return false; }
            if ($hasclass === 'no'  &&  $r['classid']) { return false; }
            if ($hasquota === 'yes' && ($r['classid'] === null || $r['is_full'])) { return false; }
            if ($hasquota === 'no'  && ($r['classid'] !== null && !$r['is_full'])) { return false; }
            if ($fs !== '' && strcasecmp((string)$r['financial_status'], $fs) !== 0) {
                return false;
            }
            return true;
        }));
    }

    private static function build_summary(array $rows, int $periodid): array {
        $students = [];
        $withClass = 0;
        $withQuota = 0;
        $fullClasses = 0;
        foreach ($rows as $r) {
            $students[$r['userid']] = true;
            if ($r['classid']) { $withClass++; }
            if ($r['classid'] && !$r['is_full']) { $withQuota++; }
            if ($r['classid'] && $r['is_full']) { $fullClasses++; }
        }
        return [
            'students'      => count($students),
            'failed_total'  => count($rows),
            'with_class'    => $withClass,
            'with_quota'    => $withQuota,
            'full_classes'  => $fullClasses,
            'periodid'      => $periodid,
        ];
    }

    /**
     * @return array{rows:array, total:int, summary:array, page:int, perpage:int}
     */
    private static function paginate_with_summary(array $rows, array $summary, int $page, int $perpage): array {
        $total = count($rows);
        $offset = $page * $perpage;
        return [
            'rows'    => array_slice($rows, $offset, $perpage),
            'total'   => $total,
            'summary' => $summary,
            'page'    => $page,
            'perpage' => $perpage,
        ];
    }

    private static function paginate(array $rows, int $page, int $perpage): array {
        $total = count($rows);
        $offset = $page * $perpage;
        return [
            'rows'    => array_slice($rows, $offset, $perpage),
            'total'   => $total,
            'summary' => self::build_summary($rows, 0),
            'page'    => $page,
            'perpage' => $perpage,
        ];
    }

    private static function cache_key(int $periodid, array $filters): string {
        ksort($filters);
        return $periodid . '|' . md5(json_encode($filters));
    }

    /**
     * Get student contact (cedula, phone, mobile, email) from the local
     * Moodle tables plus an optional Odoo XML-RPC lookup. Cached per
     * userid for CONTACT_CACHE_TTL seconds per process.
     */
    public static function get_contact_cached(int $userid, string $cedulafallback = ''): array {
        $cached = self::$contactcache[$userid] ?? null;
        if ($cached && (time() - $cached['ts']) < self::CONTACT_CACHE_TTL) {
            return $cached['data'];
        }

        global $DB;
        $data = [
            'phone' => '',
            'mobile' => '',
            'email' => '',
            'financial_status' => '',
            'financial_label' => '',
        ];

        // Financial status from local gmk_financial_status.
        $fs = $DB->get_record_sql(
            "SELECT status, reason FROM {gmk_financial_status}
              WHERE userid = :uid
           ORDER BY lastupdated DESC LIMIT 1",
            ['uid' => $userid]
        );
        if ($fs) {
            $data['financial_status'] = (string)$fs->status;
            $data['financial_label']  = (string)$fs->status; // localized in JS.
        }

        // Try Odoo (best-effort). Failure is non-fatal.
        $odooContact = self::fetch_odoo_contact($userid, $cedulafallback);
        if ($odooContact) {
            $data['phone']  = (string)($odooContact['phone'] ?? '');
            $data['mobile'] = (string)($odooContact['mobile'] ?? '');
            $data['email']  = (string)($odooContact['email'] ?? '');
        }

        self::$contactcache[$userid] = ['ts' => time(), 'data' => $data];
        return $data;
    }

    /**
     * Fetch a contact from Odoo via the local moodle.user mapping and
     * a direct XML-RPC call. Returns null if any step fails.
     */
    private static function fetch_odoo_contact(int $userid, string $cedulafallback): ?array {
        global $DB, $CFG;

        $mu = $DB->get_record('moodle.user', ['moodle_user_id' => (string)$userid], 'partner_id', IGNORE_MISSING);
        if (!$mu) {
            // Try by idnumber (cedula) — moodle.user_sync sets this.
            $mu = $DB->get_record('moodle.user', ['documentNumber' => $cedulafallback], 'partner_id', IGNORE_MISSING);
        }
        if (!$mu || empty($mu->partner_id)) {
            return null;
        }

        $url = get_config('local_grupomakro_core', 'odoo_proxy_url') ?: 'https://lms.isi.edu.pa:4000';
        // Odoo is reached via Express proxy with admin secret because we
        // do not want to expose Odoo credentials in this plugin. The
        // proxy exposes /api/admin/partner-contact?partner_id=... when
        // x-admin-secret is present.
        $secret = get_config('local_grupomakro_core', 'odoo_proxy_admin_secret') ?: '';

        if ($secret === '') {
            return null;
        }

        $endpoint = rtrim($url, '/') . '/api/admin/partner-contact?partner_id=' . (int)$mu->partner_id;
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTPHEADER => [
                'x-admin-secret: ' . $secret,
                'Accept: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !$body) {
            return null;
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            return null;
        }
        return $json;
    }

    /**
     * Enrol a single student in the given class. Wraps the existing
     * enrolApprovedScheduleStudents() helper. If force_over is true and
     * the class is full, the enrolment still proceeds and the action is
     * logged in gmk_class_absence_history with a "force" reason so the
     * audit trail is preserved.
     */
    public static function enrol_student_in_class(int $userid, int $classid, bool $force_over = false): array {
        global $DB, $USER, $CFG;

        require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');

        $class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);

        if (!$class->approved) {
            return ['status' => 'error', 'message' => 'La clase no está aprobada.'];
        }
        if ($class->closed) {
            return ['status' => 'error', 'message' => 'La clase está cerrada.'];
        }

        // Compute current enrollment count.
        $currentCount = 0;
        if (!empty($class->groupid)) {
            $currentCount = (int)$DB->count_records('groups_members', ['groupid' => $class->groupid]);
        }
        $quota = (int)$class->classroomcapacity;
        $isFull = $quota > 0 && $currentCount >= $quota;

        if ($isFull && !$force_over) {
            return [
                'status' => 'quota_exceeded',
                'message' => "El grupo está lleno ({$currentCount}/{$quota}).",
                'enrolled_count' => $currentCount,
                'classroomcapacity' => $quota,
            ];
        }

        // Already enrolled?
        if (!empty($class->groupid) && groups_is_member((int)$class->groupid, $userid)) {
            return [
                'status' => 'already_enrolled',
                'message' => 'El estudiante ya está matriculado en este grupo.',
                'enrolled_count' => $currentCount,
                'classroomcapacity' => $quota,
            ];
        }

        $student = (object)['userid' => $userid];
        $results = enrolApprovedScheduleStudents([$student], $class);
        $ok = !empty($results[$userid]);

        if ($ok) {
            $newCount = $currentCount + 1;
            // Log force-over action in the absence history table as the
            // canonical audit trail (it is already an append-only log).
            if ($isFull && $force_over) {
                $log = (object)[
                    'userid'       => $userid,
                    'classid'      => $classid,
                    'sessionid'    => 0,
                    'count_after'  => $newCount,
                    'level_after'  => 0,
                    'action'       => 'force_enroll',
                    'details'      => 'admin_force_over_quota (userid=' . (int)$USER->id . ')',
                    'timecreated'  => time(),
                ];
                try {
                    $DB->insert_record('gmk_class_absence_history', $log);
                } catch (\Throwable $t) {
                    // best-effort: never fail enrolment because of an
                    // audit log problem.
                    error_log('[failed_subjects] force_enroll log skipped: ' . $t->getMessage());
                }
            }
            return [
                'status'          => 'ok',
                'message'         => 'Matrícula realizada correctamente.',
                'enrolled_count'  => $newCount,
                'classroomcapacity' => $quota,
                'forced'          => $isFull && $force_over,
            ];
        }

        return ['status' => 'error', 'message' => 'No se pudo matricular al estudiante.'];
    }

    /**
     * Invalidate the per-process caches. Useful for tests or when the
     * admin wants to force a refresh from the UI.
     */
    public static function clear_cache(): void {
        self::$contactcache = [];
        self::$reportcache = [];
        self::$fieldids = [];
    }
}
