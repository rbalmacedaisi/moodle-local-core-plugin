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
require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/student/get_student_gradebook.php');

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

    /** @var array<string,float|null> Per-process grade cache, key = "userid_courseid". */
    private static $gradecache = [];

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
        // Note: we do NOT pre-filter by local_learning_users.status in
        // SQL anymore — the academic status filter is applied in PHP so
        // the report can show rows of students that are currently
        // "aplazado" / "retirado" too (useful for the admin). The
        // hard-join in the WHERE is removed in favour of a LEFT JOIN.
        $sql = "SELECT cp.id AS progress_id,
                       cp.userid, cp.courseid, cp.grade AS last_grade,
                       cp.timemodified AS failed_at,
                       cp.learningplanid, cp.status AS progress_status,
                       u.firstname, u.lastname, u.email AS user_email, u.idnumber,
                       c.fullname AS coursename,
                       c.id AS corecourseid,
                       lp.name AS planname,
                       $jornadaSelect,
                       $cedulaSelect,
                       MIN(llu.status) AS academic_status
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
                  LEFT JOIN {local_learning_users} llu
                       ON llu.userid = cp.userid
                      AND llu.userrolename = 'student'
                  JOIN {local_learning_plans} lp
                       ON lp.id = cp.learningplanid
                  JOIN {course} c ON c.id = cp.courseid
                  $jornadaJoin
                  $cedulaJoin
              GROUP BY cp.id, cp.userid, cp.courseid, cp.grade, cp.timemodified,
                       cp.learningplanid, cp.status, u.firstname, u.lastname,
                       u.email, u.idnumber, c.fullname, c.id, lp.name,
                       uid_j.data, uid_c.data
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

        $rows = [];
        foreach ($records as $r) {
            $jornada = self::normalize_jornada((string)($r->jornada ?? ''));
            $courseClasses = $classesByCourseShift[$r->courseid . '|' . $jornada] ?? [];

            $target = null;
            $enrolled = 0;
            $capacity = 0;
            $isFull = false;
            if (!empty($courseClasses)) {
                // Pick the class with the most free quota so the admin
                // sees the most available option first. Capacity count
                // uses get_class_participants() — the same helper that
                // powers list_classes — to keep numbers consistent
                // with the academic panel.
                $best = null;
                foreach ($courseClasses as $cand) {
                    $cp = get_class_participants($cand);
                    $cnt = is_object($cp) ? (int)count((array)$cp->preRegisteredStudents) : 0;
                    if ($best === null || $cnt < $best['count']) {
                        $best = ['class' => $cand, 'count' => $cnt, 'participants' => $cp];
                    }
                }
                if ($best !== null) {
                    $target = $best['class'];
                    $enrolled = $best['count'];
                    $capacity = (int)$target->classroomcapacity;
                    $isFull = $capacity > 0 && $enrolled >= $capacity;
                }
            }

            // Recompute the grade using the EXACT same formula as
            // grademodal.js -> gradebookWeightedTotal: sum of
            // (grade / grade_max) * weight_pct across all items with
            // weight_pct > 0, rounded to 1 decimal. This is the same
            // methodology used by the studenttable modal.
            $corecourseid = $target ? (int)$target->corecourseid : (int)$r->corecourseid;
            $computedGrade = self::compute_gradebook_weighted_total(
                (int)$r->userid, $corecourseid
            );

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
                'academic_status'    => (string)($r->academic_status ?? ''),
                'jornada_estudiante' => $jornada,
                'courseid'           => (int)$r->courseid,
                'coursename'         => (string)$r->coursename,
                'last_grade'         => (float)$r->last_grade,
                'computed_grade'     => $computedGrade,
                'failed_at'          => (int)$r->failed_at,
                'learningplanid'     => (int)$r->learningplanid,
                'planname'           => (string)$r->planname,
                'progress_status'    => (int)$r->progress_status,
                'classid'            => $target ? (int)$target->id : null,
                'classname'          => $target ? (string)$target->classname : '',
                'corecourseid'       => $corecourseid,
                'jornada_grupo'      => $target ? self::normalize_jornada((string)$target->shift) : '',
                'jornada_match'      => $target !== null,
                'classroomcapacity'  => $capacity,
                'enrolled_count'     => $enrolled,
                'is_full'            => $isFull,
                'available_classes'  => self::list_available_classes(
                    (int)$r->courseid, $periodid, $jornada
                ),
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
     * Apply search/jornada/learningplanid/hasclass/hasquota/student_status
     * /financial_status filters.
     */
    private static function apply_filters(array $rows, array $filters): array {
        $search = trim((string)($filters['search'] ?? ''));
        $jornada = self::normalize_jornada((string)($filters['jornada'] ?? ''));
        $lpid = (int)($filters['learningplanid'] ?? 0);
        $hasclass = $filters['hasclass'] ?? null; // 'yes' | 'no' | null
        $hasquota = $filters['hasquota'] ?? null; // 'yes' | 'no' | null
        $fs = trim((string)($filters['financial_status'] ?? ''));
        $studentStatus = trim((string)($filters['student_status'] ?? ''));

        return array_values(array_filter($rows, function($r) use ($search, $jornada, $lpid, $hasclass, $hasquota, $fs, $studentStatus) {
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
            if ($studentStatus !== '' && strcasecmp((string)$r['academic_status'], $studentStatus) !== 0) {
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
     * Fetch a contact from the Express proxy which in turn reads Odoo's
     * res.partner. The lookup is by documentNumber (cedula) which is
     * present in user_info_data and matches res.partner.vat in Odoo.
     *
     * The Express endpoint is /api/admin/partner-contact?documentNumber=X
     * (header x-admin-secret required). If the endpoint is not deployed
     * yet, the call will fail and we return null — the report still
     * works without Odoo contact data (it just shows "—").
     *
     * Returns null on any error.
     */
    private static function fetch_odoo_contact(int $userid, string $cedulafallback): ?array {
        $cedula = trim($cedulafallback);
        if ($cedula === '') {
            // Fallback to Moodle idnumber when the custom field is empty.
            global $DB;
            $cedula = (string)$DB->get_field('user', 'idnumber', ['id' => $userid]);
        }
        if ($cedula === '') {
            return null;
        }

        $url = get_config('local_grupomakro_core', 'odoo_proxy_url') ?: 'https://lms.isi.edu.pa:4000';
        $secret = get_config('local_grupomakro_core', 'odoo_proxy_admin_secret') ?: '';
        if ($secret === '') {
            return null;
        }

        $endpoint = rtrim($url, '/') . '/api/admin/partner-contact?documentNumber=' . urlencode($cedula);
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
     * Compute the gradebook weighted total for a (user, course) pair.
     * This is the EXACT same formula used by the student gradebook
     * modal (grademodal.js -> gradebookWeightedTotal): sum of
     * (grade / grade_max) * weight_pct across all items with
     * weight_pct > 0, rounded to 1 decimal. Result is null if no
     * gradable items exist.
     */
    public static function compute_gradebook_weighted_total(int $userid, int $courseid): ?float {
        $key = $userid . '_' . $courseid;
        if (array_key_exists($key, self::$gradecache)) {
            return self::$gradecache[$key];
        }
        if ($userid <= 0 || $courseid <= 0) {
            self::$gradecache[$key] = null;
            return null;
        }
        $result = \local_grupomakro_core\external\student\get_student_gradebook
            ::execute($userid, $courseid);
        $gbRaw = isset($result['gradebook']) ? $result['gradebook'] : '[]';
        $gb = json_decode($gbRaw, true);
        if (!is_array($gb) || empty($gb)) {
            self::$gradecache[$key] = null;
            return null;
        }
        $sum = 0.0;
        $hasItems = false;
        foreach ($gb as $cat) {
            foreach (($cat['items'] ?? []) as $item) {
                $wpct = (float)($item['weight_pct'] ?? 0);
                if ($wpct <= 0) { continue; }
                $grade = ($item['grade'] !== null && $item['grade'] !== '')
                    ? (float)$item['grade'] : 0.0;
                $max = (isset($item['grade_max']) && (float)$item['grade_max'] > 0)
                    ? (float)$item['grade_max'] : 100.0;
                $sum += ($grade / $max) * $wpct;
                $hasItems = true;
            }
        }
        $val = $hasItems ? round($sum * 10) / 10 : null;
        self::$gradecache[$key] = $val;
        return $val;
    }

    /**
     * List the projected gmk_class rows available for a (course,
     * period) tuple, regardless of the student's jornada. Returns each
     * class with its normalized shift, capacity and current enrollment
     * count (via get_class_participants), so the UI can offer a
     * "Matricular aquí" picker for each candidate.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function list_available_classes(int $courseid, int $periodid, string $studentJornada = ''): array {
        if ($courseid <= 0 || $periodid <= 0) {
            return [];
        }
        global $DB;
        $classes = $DB->get_records('gmk_class', [
            'courseid' => $courseid,
            'periodid' => $periodid,
            'closed'   => 0,
        ], 'shift ASC, name ASC');

        $out = [];
        foreach ($classes as $c) {
            $shift = self::normalize_jornada((string)$c->shift);
            $cp = get_class_participants($c);
            $enrolled = is_object($cp) ? (int)count((array)$cp->preRegisteredStudents) : 0;
            $capacity = (int)$c->classroomcapacity;
            $isFull   = $capacity > 0 && $enrolled >= $capacity;
            $out[] = [
                'classid'           => (int)$c->id,
                'classname'         => (string)$c->name,
                'shift'             => $shift,
                'jornada_match'     => $studentJornada !== '' && $shift === $studentJornada,
                'classroomcapacity' => $capacity,
                'enrolled_count'    => $enrolled,
                'is_full'           => $isFull,
            ];
        }
        return $out;
    }

    /**
     * Refresh the financial status of a single student against Odoo.
     * Reuses the legacy local_grupomakro_sync_financial_status() that
     * the academicpanel "Refrescar financiero" button uses.
     *
     * @return array{status:string, financial_status?:string, financial_label?:string, message?:string}
     */
    public static function refresh_financial_status(int $userid): array {
        if (!function_exists('local_grupomakro_sync_financial_status')) {
            return ['status' => 'error', 'message' => 'sync helper not available'];
        }
        $result = local_grupomakro_sync_financial_status([$userid]);
        if (!empty($result['error'])) {
            return ['status' => 'error', 'message' => $result['error']];
        }
        // Invalidate caches so the next fetchReport() picks up the new
        // financial_status row.
        self::$contactcache = [];
        self::$reportcache = [];
        // Re-read the local row so we can return the new value.
        global $DB;
        $fs = $DB->get_record_sql(
            "SELECT status, reason FROM {gmk_financial_status}
              WHERE userid = :uid
           ORDER BY lastupdated DESC LIMIT 1",
            ['uid' => $userid]
        );
        return [
            'status'           => 'ok',
            'updated'          => (int)($result['updated'] ?? 0),
            'financial_status' => $fs ? (string)$fs->status : '',
            'financial_label'  => $fs ? (string)$fs->status : '',
            'financial_reason' => $fs ? (string)($fs->reason ?? '') : '',
        ];
    }

    /**
     * Invalidate the per-process caches. Useful for tests or when the
     * admin wants to force a refresh from the UI.
     */
    public static function clear_cache(): void {
        self::$contactcache = [];
        self::$reportcache = [];
        self::$fieldids = [];
        self::$gradecache = [];
    }
}
