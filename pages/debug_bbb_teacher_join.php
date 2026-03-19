<?php
// Debug page for BBB join failures on teacher dashboard.

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

global $DB, $PAGE, $OUTPUT, $USER, $CFG;

require_login();
$syscontext = context_system::instance();
require_capability('moodle/site:config', $syscontext);
admin_externalpage_setup('grupomakro_core_debug_bbb_teacher_join');

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_bbb_teacher_join.php'));
$PAGE->set_context($syscontext);
$PAGE->set_title('Debug BBB Join Teacher');
$PAGE->set_heading('Debug BBB Join Teacher');
$PAGE->set_pagelayout('admin');

$teacherid = optional_param('teacherid', 0, PARAM_INT);
$teacherquery = trim(optional_param('teacher', 'CAMILO ANDRES CAITA MURCIA', PARAM_RAW_TRIMMED));
$classid = optional_param('classid', 0, PARAM_INT);
$classquery = trim(optional_param('classname', '', PARAM_RAW_TRIMMED));
$onlyactive = optional_param('onlyactive', 1, PARAM_INT);
$maxclasses = optional_param('maxclasses', 120, PARAM_INT);
$maxclasses = max(10, min(500, $maxclasses));
$tab = optional_param('tab', 'teacher', PARAM_ALPHA);
$globalscan = optional_param('g_scan', 1, PARAM_INT);
$globalonlyactive = optional_param('g_onlyactive', 1, PARAM_INT);
$globalmaxclasses = optional_param('g_maxclasses', 300, PARAM_INT);
$globalmaxclasses = max(20, min(2000, $globalmaxclasses));
$globalperiodid = optional_param('g_periodid', 0, PARAM_INT);
$globalname = trim(optional_param('g_name', '', PARAM_RAW_TRIMMED));
$repairaction = optional_param('repair', '', PARAM_ALPHAEXT);
$repairclassid = optional_param('repairclassid', 0, PARAM_INT);

/**
 * Escape helper.
 * @param mixed $v
 * @return string
 */
function dbgtj_h($v): string {
    return s((string)$v);
}

/**
 * Normalize text for accent-insensitive matching.
 * @param string $text
 * @return string
 */
function dbgtj_norm(string $text): string {
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($ascii !== false && $ascii !== '') {
        $text = $ascii;
    }
    $text = core_text::strtolower($text);
    $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim((string)$text);
}

/**
 * Parse comma-separated integer list.
 * @param string $raw
 * @return int[]
 */
function dbgtj_parse_int_list(string $raw): array {
    $out = [];
    foreach (explode(',', $raw) as $part) {
        $id = (int)trim((string)$part);
        if ($id > 0) {
            $out[$id] = $id;
        }
    }
    return array_values($out);
}

/**
 * Extract class id token from BBB activity name pattern "<class name>-<classid>-<timestamp>".
 * @param string $bbbname
 * @return int
 */
function dbgtj_extract_classid_from_bbb_name(string $bbbname): int {
    $bbbname = trim($bbbname);
    if ($bbbname === '') {
        return 0;
    }
    if (preg_match('/-(\d+)-(\d{6,})$/', $bbbname, $m)) {
        return (int)$m[1];
    }
    return 0;
}

/**
 * Detect other classes that also reference the same BBB course module id.
 * Checks both relation table and class.bbbmoduleids field.
 *
 * @param int $cmid
 * @param int $classid
 * @return int[]
 */
function dbgtj_other_classes_using_bbb_cmid(int $cmid, int $classid): array {
    global $DB;

    static $cachebuilt = false;
    static $cmclassmap = [];

    if ($cmid <= 0) {
        return [];
    }

    if (!$cachebuilt) {
        $relrows = $DB->get_records_sql(
            "SELECT id, classid, bbbmoduleid
               FROM {gmk_bbb_attendance_relation}
              WHERE classid > 0
                AND bbbmoduleid > 0"
        );
        foreach ($relrows as $rr) {
            $otherid = (int)($rr->classid ?? 0);
            $rcmid = (int)($rr->bbbmoduleid ?? 0);
            if ($otherid > 0 && $rcmid > 0) {
                if (!isset($cmclassmap[$rcmid])) {
                    $cmclassmap[$rcmid] = [];
                }
                $cmclassmap[$rcmid][$otherid] = 1;
            }
        }

        $classrows = $DB->get_records_select(
            'gmk_class',
            'bbbmoduleids IS NOT NULL AND bbbmoduleids <> :empty',
            ['empty' => ''],
            '',
            'id,bbbmoduleids'
        );
        foreach ($classrows as $crow) {
            $otherid = (int)$crow->id;
            if ($otherid <= 0) {
                continue;
            }
            $ids = dbgtj_parse_int_list((string)($crow->bbbmoduleids ?? ''));
            foreach ($ids as $rcmid) {
                $rcmid = (int)$rcmid;
                if ($rcmid <= 0) {
                    continue;
                }
                if (!isset($cmclassmap[$rcmid])) {
                    $cmclassmap[$rcmid] = [];
                }
                $cmclassmap[$rcmid][$otherid] = 1;
            }
        }
        $cachebuilt = true;
    }

    $out = [];
    $classset = $cmclassmap[(int)$cmid] ?? [];
    foreach ($classset as $otherid => $v) {
        $otherid = (int)$otherid;
        if ($otherid > 0 && $otherid !== (int)$classid) {
            $out[$otherid] = $otherid;
        }
    }
    ksort($out);
    return array_values($out);
}

/**
 * Return explicit moderator user IDs from participant rules.
 * @param array $rules
 * @return array<int,int>
 */
function dbgtj_extract_explicit_moderator_users(array $rules): array {
    $out = [];
    foreach ($rules as $r) {
        $seltype = (string)($r['selectiontype'] ?? '');
        $selid = (string)($r['selectionid'] ?? '');
        $role = core_text::strtolower((string)($r['role'] ?? ''));
        if ($seltype === 'user' && $role === 'moderator' && preg_match('/^\d+$/', $selid)) {
            $uid = (int)$selid;
            if ($uid > 0) {
                $out[$uid] = $uid;
            }
        }
    }
    ksort($out);
    return $out;
}

/**
 * Return explicit moderator users from raw participants JSON.
 * @param string $json
 * @return array<int,int>
 */
function dbgtj_extract_explicit_moderator_users_from_json(string $json): array {
    $json = trim($json);
    if ($json === '') {
        return [];
    }
    $rules = json_decode($json, true);
    if (!is_array($rules)) {
        return [];
    }
    return dbgtj_extract_explicit_moderator_users($rules);
}

/**
 * Evaluates moderator status for a target user (without current admin-user contamination).
 * @param context $context
 * @param array $participantlist
 * @param int $userid
 * @return bool
 */
function dbgtj_is_moderator_for_user(context $context, array $participantlist, int $userid): bool {
    if ($userid <= 0) {
        return false;
    }
    if (has_capability('moodle/site:config', $context, $userid)) {
        return true;
    }
    if (empty($participantlist)) {
        return false;
    }

    $roleids = [];
    $roleshortnames = [];
    $assignments = get_user_roles($context, $userid, true);
    foreach ((array)$assignments as $ra) {
        if (!empty($ra->roleid)) {
            $roleids[(int)$ra->roleid] = 1;
        }
        if (!empty($ra->shortname)) {
            $roleshortnames[core_text::strtolower((string)$ra->shortname)] = 1;
        }
    }

    foreach ($participantlist as $participant) {
        $role = core_text::strtolower((string)($participant['role'] ?? ''));
        if ($role === 'viewer') {
            continue;
        }
        $seltype = (string)($participant['selectiontype'] ?? '');
        $selid = (string)($participant['selectionid'] ?? '');
        if ($seltype === 'all') {
            return true;
        }
        if ($seltype === 'user') {
            if (preg_match('/^\d+$/', $selid) && (int)$selid === (int)$userid) {
                return true;
            }
            continue;
        }
        if ($seltype === 'role') {
            if (preg_match('/^\d+$/', $selid) && !empty($roleids[(int)$selid])) {
                return true;
            }
            $sn = core_text::strtolower(trim($selid));
            if ($sn !== '' && !empty($roleshortnames[$sn])) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Resolve teacher from id/query.
 * @param int $teacherid
 * @param string $teacherquery
 * @return array{selected:?stdClass,candidates:array<int,stdClass>,warning:string}
 */
function dbgtj_resolve_teacher(int $teacherid, string $teacherquery): array {
    global $DB;

    $selected = null;
    $candidates = [];
    $warning = '';

    if ($teacherid > 0) {
        $selected = $DB->get_record('user', ['id' => $teacherid, 'deleted' => 0], 'id,username,firstname,lastname,email,idnumber,suspended', IGNORE_MISSING);
        if ($selected) {
            $candidates[(int)$selected->id] = $selected;
        }
    }

    if (!$selected && $teacherquery !== '') {
        $sql = "SELECT u.id, u.username, u.firstname, u.lastname, u.email, u.idnumber, u.suspended
                  FROM {user} u
                 WHERE u.deleted = 0
                   AND (
                        " . $DB->sql_like("CONCAT(u.firstname, ' ', u.lastname)", ':q1', false, false) . "
                        OR " . $DB->sql_like('u.username', ':q2', false, false) . "
                        OR " . $DB->sql_like('u.email', ':q3', false, false) . "
                        OR " . $DB->sql_like('u.idnumber', ':q4', false, false) . "
                   )
              ORDER BY u.firstname ASC, u.lastname ASC";
        $rows = $DB->get_records_sql($sql, [
            'q1' => '%' . $teacherquery . '%',
            'q2' => '%' . $teacherquery . '%',
            'q3' => '%' . $teacherquery . '%',
            'q4' => '%' . $teacherquery . '%',
        ]);
        foreach ($rows as $row) {
            $candidates[(int)$row->id] = $row;
        }

        if (!empty($candidates)) {
            $qnorm = dbgtj_norm($teacherquery);
            $exact = [];
            foreach ($candidates as $u) {
                $fullname = dbgtj_norm(trim((string)$u->firstname . ' ' . (string)$u->lastname));
                if ($qnorm !== '' && $fullname === $qnorm) {
                    $exact[(int)$u->id] = $u;
                }
            }
            if (count($exact) === 1) {
                $selected = reset($exact);
            } else {
                $selected = reset($candidates);
                if (count($candidates) > 1) {
                    $warning = 'Multiple teacher matches found. Using the first match. Use teacherid to force one.';
                }
            }
        }
    }

    return ['selected' => $selected, 'candidates' => $candidates, 'warning' => $warning];
}

/**
 * Return role shortnames assigned to user in context (including inherited).
 * @param context $context
 * @param int $userid
 * @return string
 */
function dbgtj_context_roles(context $context, int $userid): string {
    $roles = get_user_roles($context, $userid, true);
    if (empty($roles)) {
        return '-';
    }
    $names = [];
    foreach ($roles as $ra) {
        if (!empty($ra->shortname)) {
            $names[(string)$ra->shortname] = (string)$ra->shortname;
        }
    }
    if (empty($names)) {
        return '-';
    }
    ksort($names);
    return implode(', ', array_values($names));
}

/**
 * Build participant rule summary for display.
 * @param array $rules
 * @return string
 */
function dbgtj_participant_rules_text(array $rules): string {
    global $DB;
    if (empty($rules)) {
        return '-';
    }
    static $rolemap = null;
    if ($rolemap === null) {
        $rolemap = [];
        $roles = $DB->get_records('role', null, '', 'id,shortname,name');
        foreach ($roles as $r) {
            $label = trim((string)$r->shortname) !== '' ? (string)$r->shortname : (string)$r->name;
            $rolemap[(int)$r->id] = $label;
        }
    }
    $parts = [];
    foreach ($rules as $r) {
        $seltype = (string)($r['selectiontype'] ?? '');
        $selid = (string)($r['selectionid'] ?? '');
        $role = (string)($r['role'] ?? '');
        if ($seltype === 'role' && is_numeric($selid)) {
            $rid = (int)$selid;
            if (!empty($rolemap[$rid])) {
                $selid = $rolemap[$rid] . "({$rid})";
            }
        }
        $parts[] = $seltype . ':' . $selid . '=>' . $role;
    }
    return implode(' | ', $parts);
}

/**
 * Calls BBB isMeetingRunning API for quick state.
 * @param string $meetingid
 * @return array{status:string,running:?bool,message:string}
 */
function dbgtj_bbb_running_state(string $meetingid): array {
    global $CFG;

    $meetingid = trim($meetingid);
    if ($meetingid === '') {
        return ['status' => 'skip', 'running' => null, 'message' => 'empty_meetingid'];
    }

    $server = trim((string)get_config('core', 'bigbluebuttonbn_server_url'));
    if ($server === '') {
        $server = trim((string)($CFG->bigbluebuttonbn_server_url ?? ''));
    }
    $secret = trim((string)get_config('core', 'bigbluebuttonbn_shared_secret'));
    if ($secret === '') {
        $secret = trim((string)($CFG->bigbluebuttonbn_shared_secret ?? ''));
    }

    if ($server === '' || $secret === '') {
        return ['status' => 'skip', 'running' => null, 'message' => 'bbb_server_or_secret_missing'];
    }

    if (substr($server, -1) !== '/') {
        $server .= '/';
    }
    $action = 'isMeetingRunning';
    $query = 'meetingID=' . urlencode($meetingid);
    $checksum = sha1($action . $query . $secret);
    $url = $server . 'api/' . $action . '?' . $query . '&checksum=' . $checksum;

    $ctx = stream_context_create(['http' => ['timeout' => 5], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $xmlraw = @file_get_contents($url, false, $ctx);
    if ($xmlraw === false || trim((string)$xmlraw) === '') {
        return ['status' => 'error', 'running' => null, 'message' => 'bbb_api_unreachable'];
    }
    $xml = @simplexml_load_string($xmlraw);
    if (!$xml) {
        return ['status' => 'error', 'running' => null, 'message' => 'bbb_api_invalid_xml'];
    }
    $returncode = strtolower((string)($xml->returncode ?? ''));
    if ($returncode !== 'success') {
        $msg = trim((string)($xml->message ?? 'bbb_api_return_not_success'));
        return ['status' => 'error', 'running' => null, 'message' => $msg];
    }
    $running = strtolower((string)($xml->running ?? 'false')) === 'true';
    return ['status' => 'ok', 'running' => $running, 'message' => $running ? 'running' : 'not_running'];
}

/**
 * Repairs BBB participant rules for one class, setting current class instructor as moderator.
 * @param int $classid
 * @return array{type:string,message:string,details:array<int,string>}
 */
function dbgtj_repair_moderator_rules_for_class(int $classid): array {
    global $DB, $CFG;

    $class = $DB->get_record('gmk_class', ['id' => $classid], '*', IGNORE_MISSING);
    if (!$class) {
        return [
            'type' => 'bad',
            'message' => 'Repair target class not found.',
            'details' => ['classid=' . (int)$classid]
        ];
    }
    if ((int)$class->instructorid <= 0) {
        return [
            'type' => 'bad',
            'message' => 'Class has no instructor assigned. Cannot set BBB moderator.',
            'details' => ['classid=' . (int)$classid]
        ];
    }

    $targetcmids = [];
    foreach (dbgtj_parse_int_list((string)$class->bbbmoduleids) as $cmid) {
        $targetcmids[$cmid] = $cmid;
    }
    $rels = $DB->get_records('gmk_bbb_attendance_relation', ['classid' => (int)$classid], '', 'id,bbbmoduleid');
    foreach ($rels as $rel) {
        $cmid = (int)($rel->bbbmoduleid ?? 0);
        if ($cmid > 0) {
            $targetcmids[$cmid] = $cmid;
        }
    }
    $targetcmids = array_values($targetcmids);
    if (empty($targetcmids)) {
        return [
            'type' => 'bad',
            'message' => 'No BBB cmids found in class mapping/relation.',
            'details' => ['classid=' . (int)$classid]
        ];
    }

    $participants = json_encode([
        [
            'selectiontype' => 'user',
            'selectionid' => (int)$class->instructorid,
            'role' => 'moderator',
        ],
        [
            'selectiontype' => 'all',
            'selectionid' => 'all',
            'role' => 'viewer',
        ],
    ], JSON_UNESCAPED_UNICODE);

    $updated = 0;
    $verifiedok = 0;
    $verifyfailed = 0;
    $skipped = 0;
    $foreignskipped = 0;
    $sharedskipped = 0;
    $errors = [];
    $time = time();
    foreach ($targetcmids as $cmid) {
        $cm = $DB->get_record_sql(
            "SELECT cm.id, cm.instance, m.name AS modulename, b.name AS bbbname
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
          LEFT JOIN {bigbluebuttonbn} b ON b.id = cm.instance
              WHERE cm.id = :cmid",
            ['cmid' => (int)$cmid],
            IGNORE_MISSING
        );
        if (!$cm || (string)$cm->modulename !== 'bigbluebuttonbn' || (int)$cm->instance <= 0) {
            $skipped++;
            $errors[] = 'cmid=' . (int)$cmid . ' skipped (invalid/non-bbb)';
            continue;
        }
        $tokenclassid = dbgtj_extract_classid_from_bbb_name((string)($cm->bbbname ?? ''));
        if ($tokenclassid > 0 && $tokenclassid !== (int)$classid) {
            $foreignskipped++;
            $errors[] = 'cmid=' . (int)$cmid . ' skipped_foreign_ref tokenclassid=' . (int)$tokenclassid . ' classid=' . (int)$classid;
            continue;
        }
        $sharedwith = dbgtj_other_classes_using_bbb_cmid((int)$cmid, (int)$classid);
        if (!empty($sharedwith)) {
            $sharedskipped++;
            $errors[] = 'cmid=' . (int)$cmid . ' skipped_shared_ref classid=' . (int)$classid .
                ' shared_with=' . implode(',', $sharedwith);
            continue;
        }

        $instanceid = (int)$cm->instance;
        $DB->set_field('bigbluebuttonbn', 'participants', $participants, ['id' => $instanceid]);
        $DB->set_field('bigbluebuttonbn', 'timemodified', $time, ['id' => $instanceid]);

        // Verify write by re-reading participants and checking against BBB helper interpretation.
        $after = $DB->get_record('bigbluebuttonbn', ['id' => $instanceid], 'id,participants', IGNORE_MISSING);
        $afterrawmods = [];
        $afterhelpermods = [];
        $afterismoderator = false;
        if ($after) {
            $afterrawmods = dbgtj_extract_explicit_moderator_users_from_json((string)$after->participants);
            $cmcontext = context_module::instance((int)$cmid, IGNORE_MISSING);
            if ($cmcontext && class_exists('\mod_bigbluebuttonbn\local\helpers\roles')) {
                $helperrules = \mod_bigbluebuttonbn\local\helpers\roles::get_participant_list($after, $cmcontext);
                $afterhelpermods = dbgtj_extract_explicit_moderator_users((array)$helperrules);
                $afterismoderator = dbgtj_is_moderator_for_user($cmcontext, (array)$helperrules, (int)$class->instructorid);
            }
        }
        if ($afterismoderator) {
            $verifiedok++;
        } else {
            $verifyfailed++;
            $errors[] = 'cmid=' . (int)$cmid . ' verify_failed expected_moderator=' . (int)$class->instructorid .
                ' raw=' . (!empty($afterrawmods) ? implode(',', array_values($afterrawmods)) : 'none') .
                ' helper=' . (!empty($afterhelpermods) ? implode(',', array_values($afterhelpermods)) : 'none');
        }
        $updated++;
    }

    if ((int)$class->corecourseid > 0) {
        if (!function_exists('rebuild_course_cache')) {
            require_once($CFG->libdir . '/modinfolib.php');
        }
        rebuild_course_cache((int)$class->corecourseid, true);
    }

    return [
        'type' => ($updated > 0 && $verifyfailed === 0 ? 'ok' : 'warn'),
        'message' => 'Repair moderator rules done. Updated BBB modules: ' . (int)$updated .
            '. Verified: ' . (int)$verifiedok . '. Verify failed: ' . (int)$verifyfailed .
            '. Skipped: ' . (int)$skipped . '. Foreign skipped: ' . (int)$foreignskipped .
            '. Shared skipped: ' . (int)$sharedskipped . '.',
        'details' => array_merge([
            'classid=' . (int)$classid,
            'instructorid=' . (int)$class->instructorid,
            'courseid=' . (int)$class->corecourseid,
            'cache_rebuilt=1',
            'foreign_skipped=' . (int)$foreignskipped,
            'shared_skipped=' . (int)$sharedskipped,
            'hint=if foreign/shared skipped > 0 run "Sanitize Mapping" first, then run repair again'
        ], $errors)
    ];
}

/**
 * Remove cross-class BBB references from gmk_class.bbbmoduleids and relation rows.
 * Keeps only BBB cmids whose BBB name token matches current class id.
 *
 * @param int $classid
 * @return array{type:string,message:string,details:array<int,string>}
 */
function dbgtj_sanitize_class_bbb_mapping(int $classid): array {
    global $DB;

    $class = $DB->get_record('gmk_class', ['id' => (int)$classid], '*', IGNORE_MISSING);
    if (!$class) {
        return [
            'type' => 'bad',
            'message' => 'Class not found for sanitize.',
            'details' => ['classid=' . (int)$classid]
        ];
    }

    $cmids = [];
    foreach (dbgtj_parse_int_list((string)($class->bbbmoduleids ?? '')) as $cmid) {
        $cmids[$cmid] = $cmid;
    }
    $relations = $DB->get_records('gmk_bbb_attendance_relation', ['classid' => (int)$classid], '', 'id,bbbmoduleid');
    foreach ($relations as $rel) {
        $cmid = (int)($rel->bbbmoduleid ?? 0);
        if ($cmid > 0) {
            $cmids[$cmid] = $cmid;
        }
    }
    $cmids = array_values($cmids);

    if (empty($cmids)) {
        return [
            'type' => 'warn',
            'message' => 'No BBB cmids to sanitize for class.',
            'details' => ['classid=' . (int)$classid]
        ];
    }

    list($insql, $params) = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'scm');
    $cms = $DB->get_records_sql(
        "SELECT cm.id, cm.instance, m.name AS modulename, b.name AS bbbname
           FROM {course_modules} cm
           JOIN {modules} m ON m.id = cm.module
      LEFT JOIN {bigbluebuttonbn} b ON b.id = cm.instance
          WHERE cm.id {$insql}",
        $params
    );

    $owned = [];
    $foreign = [];
    $invalid = [];
    foreach ($cmids as $cmid) {
        $cm = $cms[(int)$cmid] ?? null;
        if (!$cm || (string)$cm->modulename !== 'bigbluebuttonbn') {
            $invalid[] = (int)$cmid;
            continue;
        }
        $tokenclassid = dbgtj_extract_classid_from_bbb_name((string)($cm->bbbname ?? ''));
        if ($tokenclassid > 0 && $tokenclassid !== (int)$classid) {
            $foreign[] = (int)$cmid;
            continue;
        }
        $owned[] = (int)$cmid;
    }
    $owned = array_values(array_unique($owned));
    sort($owned);

    $newcmtext = !empty($owned) ? implode(',', $owned) : null;
    $DB->set_field('gmk_class', 'bbbmoduleids', $newcmtext, ['id' => (int)$classid]);

    $deletedrelations = 0;
    if (!empty($foreign)) {
        list($finsql, $fparams) = $DB->get_in_or_equal($foreign, SQL_PARAMS_NAMED, 'fcm');
        $sql = "classid = :cid AND bbbmoduleid {$finsql}";
        $fparams['cid'] = (int)$classid;
        $deletedrelations = $DB->count_records_select('gmk_bbb_attendance_relation', $sql, $fparams);
        $DB->delete_records_select('gmk_bbb_attendance_relation', $sql, $fparams);
    }

    return [
        'type' => 'ok',
        'message' => 'Sanitize mapping done. Owned=' . count($owned) . ' Foreign=' . count($foreign) .
            ' Invalid=' . count($invalid) . ' Relation rows deleted=' . (int)$deletedrelations . '.',
        'details' => [
            'classid=' . (int)$classid,
            'old_count=' . count($cmids),
            'owned=' . (!empty($owned) ? implode(',', $owned) : 'none'),
            'foreign=' . (!empty($foreign) ? implode(',', $foreign) : 'none'),
            'invalid=' . (!empty($invalid) ? implode(',', $invalid) : 'none'),
            'new_bbbmoduleids=' . ($newcmtext === null ? 'NULL' : $newcmtext)
        ]
    ];
}

$teacherresolve = dbgtj_resolve_teacher($teacherid, $teacherquery);
$teacher = $teacherresolve['selected'];

echo $OUTPUT->header();

echo '<style>
.dbgtj-wrap{max-width:1700px;margin:14px auto}
.dbgtj-card{background:#fff;border:1px solid #dee2e6;border-radius:8px;padding:14px;margin-bottom:14px}
.dbgtj-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
.dbgtj-table{width:100%;border-collapse:collapse;font-size:12px}
.dbgtj-table th{background:#1f2937;color:#fff;padding:7px;border:1px solid #374151;text-align:left}
.dbgtj-table td{padding:6px;border:1px solid #dee2e6;vertical-align:top}
.dbgtj-ok{color:#166534;font-weight:700}
.dbgtj-bad{color:#b91c1c;font-weight:700}
.dbgtj-warn{color:#9a6700;font-weight:700}
.dbgtj-muted{color:#6b7280}
.dbgtj-badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700}
.dbgtj-badge-ok{background:#dcfce7;color:#166534}
.dbgtj-badge-bad{background:#fee2e2;color:#991b1b}
.dbgtj-badge-warn{background:#fef3c7;color:#92400e}
.dbgtj-pre{white-space:pre-wrap;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:8px}
</style>';

echo '<div class="dbgtj-wrap">';
echo '<div class="dbgtj-card" style="padding:10px 14px">';
$teachertaburl = new moodle_url('/local/grupomakro_core/pages/debug_bbb_teacher_join.php', [
    'tab' => 'teacher',
    'teacher' => $teacherquery,
    'teacherid' => $teacherid,
    'classname' => $classquery,
    'classid' => $classid,
    'onlyactive' => $onlyactive,
    'maxclasses' => $maxclasses,
]);
$globaltaburl = new moodle_url('/local/grupomakro_core/pages/debug_bbb_teacher_join.php', [
    'tab' => 'global',
    'g_scan' => $globalscan,
    'g_onlyactive' => $globalonlyactive,
    'g_maxclasses' => $globalmaxclasses,
    'g_periodid' => $globalperiodid,
    'g_name' => $globalname,
]);
echo '<a class="btn ' . ($tab === 'teacher' ? 'btn-primary' : 'btn-secondary') . '" style="margin-right:8px" href="' . $teachertaburl->out(false) . '">Teacher Diagnose</a>';
echo '<a class="btn ' . ($tab === 'global' ? 'btn-primary' : 'btn-secondary') . '" href="' . $globaltaburl->out(false) . '">Global Scan</a>';
echo '</div>';

if ($tab === 'global') {
    $repairfeedback = ['type' => '', 'message' => '', 'details' => []];
    if ($repairaction !== '') {
        if (!confirm_sesskey()) {
            $repairfeedback = [
                'type' => 'bad',
                'message' => 'Invalid sesskey for repair action.',
                'details' => []
            ];
        } else if ($repairaction === 'fixmoderator' && $repairclassid > 0) {
            $repairfeedback = dbgtj_repair_moderator_rules_for_class((int)$repairclassid);
        } else if ($repairaction === 'sanitizebbb' && $repairclassid > 0) {
            $repairfeedback = dbgtj_sanitize_class_bbb_mapping((int)$repairclassid);
        }
    }

    echo '<div class="dbgtj-card">';
    echo '<h3 style="margin-top:0">Global BBB Moderator Mismatch Scan</h3>';
    echo '<p class="dbgtj-muted" style="margin-top:0">Finds classes where BBB participant rules can block teacher join (wait=1 + instructor not moderator).</p>';
    echo '<p class="dbgtj-muted" style="margin-top:-6px">Root cause hint: if BBB participants are empty/invalid, BBB falls back to module owner as moderator. If class instructor changed later and participants were not synced, join_url can be empty.</p>';
    echo '<form method="get">';
    echo '<input type="hidden" name="tab" value="global">';
    echo '<div class="dbgtj-grid">';
    echo '<div><label>Only active classes</label><select name="g_onlyactive" class="form-control">';
    echo '<option value="1"' . ((int)$globalonlyactive === 1 ? ' selected' : '') . '>Yes</option>';
    echo '<option value="0"' . ((int)$globalonlyactive === 0 ? ' selected' : '') . '>No</option>';
    echo '</select></div>';
    echo '<div><label>Period ID</label><input type="number" name="g_periodid" value="' . (int)$globalperiodid . '" class="form-control"></div>';
    echo '<div><label>Class name filter</label><input type="text" name="g_name" value="' . dbgtj_h($globalname) . '" class="form-control"></div>';
    echo '<div><label>Max classes</label><input type="number" name="g_maxclasses" value="' . (int)$globalmaxclasses . '" class="form-control"></div>';
    echo '</div>';
    echo '<div style="margin-top:10px"><button class="btn btn-primary" type="submit" name="g_scan" value="1">Scan all</button></div>';
    echo '</form>';
    echo '</div>';

    if (!empty($repairfeedback['message'])) {
        $cls = 'dbgtj-warn';
        if ($repairfeedback['type'] === 'ok') {
            $cls = 'dbgtj-ok';
        } else if ($repairfeedback['type'] === 'bad') {
            $cls = 'dbgtj-bad';
        }
        echo '<div class="dbgtj-card">';
        echo '<strong class="' . $cls . '">' . dbgtj_h((string)$repairfeedback['message']) . '</strong>';
        if (!empty($repairfeedback['details'])) {
            echo '<div class="dbgtj-pre" style="margin-top:8px">' . dbgtj_h(implode("\n", (array)$repairfeedback['details'])) . '</div>';
        }
        echo '</div>';
    }

    if ((int)$globalscan === 1) {
        $globalsql = "SELECT c.id, c.name, c.instructorid, c.corecourseid, c.groupid, c.periodid, c.closed, c.approved, c.bbbmoduleids,
                             u.firstname AS t_firstname, u.lastname AS t_lastname, u.username AS t_username,
                             cr.fullname AS coursename
                        FROM {gmk_class} c
                   LEFT JOIN {user} u ON u.id = c.instructorid
                   LEFT JOIN {course} cr ON cr.id = c.corecourseid
                       WHERE 1=1";
        $globalparams = [];
        if ((int)$globalonlyactive === 1) {
            $globalsql .= " AND c.closed = 0";
        }
        if ((int)$globalperiodid > 0) {
            $globalsql .= " AND c.periodid = :periodid";
            $globalparams['periodid'] = (int)$globalperiodid;
        }
        if ($globalname !== '') {
            $globalsql .= " AND " . $DB->sql_like('c.name', ':gname', false, false);
            $globalparams['gname'] = '%' . $globalname . '%';
        }
        $globalsql .= " ORDER BY c.id DESC";
        $globalclasses = $DB->get_records_sql($globalsql, $globalparams, 0, (int)$globalmaxclasses);

        $scanstats = [
            'classes' => 0,
            'classes_with_risk' => 0,
            'cm_checked' => 0,
            'risk_cross_class_mapping' => 0,
            'risk_shared_refs' => 0,
            'risk_explicit_mismatch' => 0,
            'risk_wait_not_moderator' => 0,
            'risk_no_mapping' => 0,
            'risk_invalid_cmid' => 0,
        ];

        $rows = [];
        foreach ($globalclasses as $gclass) {
            $scanstats['classes']++;
            $issues = [];
            $riskexplicit = 0;
            $riskwaitnotmod = 0;
            $riskcrossmap = 0;
            $riskshared = 0;
            $invalidcm = 0;
            $cmchecked = 0;

            $cmids = [];
            foreach (dbgtj_parse_int_list((string)$gclass->bbbmoduleids) as $cmid) {
                $cmids[$cmid] = $cmid;
            }
            $grels = $DB->get_records('gmk_bbb_attendance_relation', ['classid' => (int)$gclass->id], '', 'id,bbbmoduleid');
            foreach ($grels as $grel) {
                $relcmid = (int)($grel->bbbmoduleid ?? 0);
                if ($relcmid > 0) {
                    $cmids[$relcmid] = $relcmid;
                }
            }
            $cmids = array_values($cmids);
            if (empty($cmids)) {
                $issues[] = 'no_bbb_mapping';
                $scanstats['risk_no_mapping']++;
            } else {
                list($insql, $inparams) = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'gc');
                $gcmrows = $DB->get_records_sql(
                    "SELECT cm.id, cm.instance, cm.course, m.name AS modulename
                       FROM {course_modules} cm
                       JOIN {modules} m ON m.id = cm.module
                      WHERE cm.id {$insql}",
                    $inparams
                );

                foreach ($cmids as $cmiditem) {
                    $cmchecked++;
                    $scanstats['cm_checked']++;
                    $gcm = $gcmrows[(int)$cmiditem] ?? null;
                    if (!$gcm || (string)$gcm->modulename !== 'bigbluebuttonbn' || (int)$gcm->instance <= 0) {
                        $invalidcm++;
                        $scanstats['risk_invalid_cmid']++;
                        continue;
                    }
                    $bbb = $DB->get_record('bigbluebuttonbn', ['id' => (int)$gcm->instance], '*', IGNORE_MISSING);
                    if (!$bbb) {
                        $invalidcm++;
                        $scanstats['risk_invalid_cmid']++;
                        continue;
                    }
                    $tokenclassid = dbgtj_extract_classid_from_bbb_name((string)($bbb->name ?? ''));
                    if ($tokenclassid > 0 && $tokenclassid !== (int)$gclass->id) {
                        $riskcrossmap++;
                        $scanstats['risk_cross_class_mapping']++;
                    }
                    $sharedwith = dbgtj_other_classes_using_bbb_cmid((int)$cmiditem, (int)$gclass->id);
                    if (!empty($sharedwith)) {
                        $riskshared++;
                        $scanstats['risk_shared_refs']++;
                    }
                    if ((int)$gclass->instructorid <= 0) {
                        continue;
                    }
                    $wait = isset($bbb->wait) ? (int)$bbb->wait : 0;
                    $cmcontext = context_module::instance((int)$gcm->id, IGNORE_MISSING);
                    if (!$cmcontext || !class_exists('\mod_bigbluebuttonbn\local\helpers\roles')) {
                        continue;
                    }
                    $rules = \mod_bigbluebuttonbn\local\helpers\roles::get_participant_list($bbb, $cmcontext);
                    $ismod = dbgtj_is_moderator_for_user($cmcontext, (array)$rules, (int)$gclass->instructorid);
                    $explicitmods = dbgtj_extract_explicit_moderator_users((array)$rules);
                    if ($wait === 1 && !empty($explicitmods) && empty($explicitmods[(int)$gclass->instructorid])) {
                        $riskexplicit++;
                        $scanstats['risk_explicit_mismatch']++;
                    }
                    if ($wait === 1 && !$ismod) {
                        $riskwaitnotmod++;
                        $scanstats['risk_wait_not_moderator']++;
                    }
                }
            }

            if ($invalidcm > 0) {
                $issues[] = 'invalid_or_missing_bbb_cmid=' . $invalidcm;
            }
            if ($riskcrossmap > 0) {
                $issues[] = 'cross_class_bbb_mapping=' . $riskcrossmap;
            }
            if ($riskshared > 0) {
                $issues[] = 'shared_bbb_cmid_ref=' . $riskshared;
            }
            if ($riskexplicit > 0) {
                $issues[] = 'explicit_moderator_mismatch=' . $riskexplicit;
            }
            if ($riskwaitnotmod > 0) {
                $issues[] = 'wait1_instructor_not_moderator=' . $riskwaitnotmod;
            }
            if (!empty($issues)) {
                $scanstats['classes_with_risk']++;
                $rows[] = [
                    'class' => $gclass,
                    'cmchecked' => $cmchecked,
                    'riskcrossmap' => $riskcrossmap,
                    'riskshared' => $riskshared,
                    'riskexplicit' => $riskexplicit,
                    'riskwaitnotmod' => $riskwaitnotmod,
                    'issues' => $issues,
                ];
            }
        }

        echo '<div class="dbgtj-card">';
        echo '<h4 style="margin-top:0">Scan summary</h4>';
        echo '<div class="dbgtj-grid">';
        echo '<div>Classes scanned: <strong>' . (int)$scanstats['classes'] . '</strong></div>';
        echo '<div>Classes with risk: <strong>' . (int)$scanstats['classes_with_risk'] . '</strong></div>';
        echo '<div>CM checked: <strong>' . (int)$scanstats['cm_checked'] . '</strong></div>';
        echo '<div>Cross-class BBB mapping: <strong>' . (int)$scanstats['risk_cross_class_mapping'] . '</strong></div>';
        echo '<div>Shared BBB cmid refs: <strong>' . (int)$scanstats['risk_shared_refs'] . '</strong></div>';
        echo '<div>Explicit moderator mismatch: <strong>' . (int)$scanstats['risk_explicit_mismatch'] . '</strong></div>';
        echo '<div>Wait=1 and instructor not moderator: <strong>' . (int)$scanstats['risk_wait_not_moderator'] . '</strong></div>';
        echo '<div>No BBB mapping: <strong>' . (int)$scanstats['risk_no_mapping'] . '</strong></div>';
        echo '<div>Invalid/missing BBB cmid: <strong>' . (int)$scanstats['risk_invalid_cmid'] . '</strong></div>';
        echo '</div>';
        echo '</div>';

        if (!empty($rows)) {
            echo '<div class="dbgtj-card">';
            echo '<table class="dbgtj-table">';
            echo '<thead><tr><th>Class</th><th>Instructor</th><th>Course</th><th>Period</th><th>CM checked</th><th>Risk cross mapping</th><th>Risk shared refs</th><th>Risk explicit mismatch</th><th>Risk wait/not moderator</th><th>Issues</th><th>Actions</th></tr></thead><tbody>';
            foreach ($rows as $row) {
                $cls = $row['class'];
                $teacherfullname = trim((string)($cls->t_firstname ?? '') . ' ' . (string)($cls->t_lastname ?? ''));
                if ($teacherfullname === '') {
                    $teacherfullname = '-';
                }
                $durl = new moodle_url('/local/grupomakro_core/pages/debug_bbb_teacher_join.php', [
                    'tab' => 'teacher',
                    'teacherid' => (int)$cls->instructorid,
                    'teacher' => $teacherfullname,
                    'classid' => (int)$cls->id,
                    'onlyactive' => 0,
                    'maxclasses' => 50,
                ]);
                $rurl = new moodle_url('/local/grupomakro_core/pages/debug_bbb_teacher_join.php', [
                    'tab' => 'global',
                    'g_scan' => 1,
                    'g_onlyactive' => (int)$globalonlyactive,
                    'g_maxclasses' => (int)$globalmaxclasses,
                    'g_periodid' => (int)$globalperiodid,
                    'g_name' => $globalname,
                    'repair' => 'fixmoderator',
                    'repairclassid' => (int)$cls->id,
                    'sesskey' => sesskey(),
                ]);
                $surl = new moodle_url('/local/grupomakro_core/pages/debug_bbb_teacher_join.php', [
                    'tab' => 'global',
                    'g_scan' => 1,
                    'g_onlyactive' => (int)$globalonlyactive,
                    'g_maxclasses' => (int)$globalmaxclasses,
                    'g_periodid' => (int)$globalperiodid,
                    'g_name' => $globalname,
                    'repair' => 'sanitizebbb',
                    'repairclassid' => (int)$cls->id,
                    'sesskey' => sesskey(),
                ]);
                echo '<tr>';
                echo '<td>#' . (int)$cls->id . ' ' . dbgtj_h((string)$cls->name) . '</td>';
                echo '<td>id=' . (int)$cls->instructorid . ' ' . dbgtj_h($teacherfullname) . '</td>';
                echo '<td>id=' . (int)$cls->corecourseid . ' ' . dbgtj_h((string)($cls->coursename ?? '')) . '</td>';
                echo '<td>' . (int)$cls->periodid . '</td>';
                echo '<td>' . (int)$row['cmchecked'] . '</td>';
                echo '<td>' . (int)$row['riskcrossmap'] . '</td>';
                echo '<td>' . (int)$row['riskshared'] . '</td>';
                echo '<td>' . (int)$row['riskexplicit'] . '</td>';
                echo '<td>' . (int)$row['riskwaitnotmod'] . '</td>';
                echo '<td>' . dbgtj_h(implode(' | ', (array)$row['issues'])) . '</td>';
                echo '<td><a class="btn btn-secondary btn-sm" style="margin-right:6px" href="' . $durl->out(false) . '">Diagnose</a><a class="btn btn-secondary btn-sm" style="margin-right:6px" href="' . $rurl->out(false) . '">Repair</a><a class="btn btn-secondary btn-sm" href="' . $surl->out(false) . '">Sanitize Mapping</a></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</div>';
        } else {
            echo '<div class="dbgtj-card"><span class="dbgtj-ok">No risky classes detected with current filters.</span></div>';
        }
    }

    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

echo '<div class="dbgtj-card">';
echo '<h3 style="margin-top:0">Debug BBB Join Teacher</h3>';
echo '<p class="dbgtj-muted" style="margin-top:0">Diagnoses why join_url is empty for teacher BBB access. Focuses on moderator resolution, wait flag, and join capability.</p>';
echo '<form method="get">';
echo '<input type="hidden" name="tab" value="teacher">';
echo '<div class="dbgtj-grid">';
echo '<div><label>Teacher</label><input type="text" name="teacher" value="' . dbgtj_h($teacherquery) . '" class="form-control"></div>';
echo '<div><label>Teacher ID</label><input type="number" name="teacherid" value="' . (int)$teacherid . '" class="form-control"></div>';
echo '<div><label>Class name filter</label><input type="text" name="classname" value="' . dbgtj_h($classquery) . '" class="form-control"></div>';
echo '<div><label>Class ID</label><input type="number" name="classid" value="' . (int)$classid . '" class="form-control"></div>';
echo '<div><label>Only active classes</label><select name="onlyactive" class="form-control">';
echo '<option value="1"' . ((int)$onlyactive === 1 ? ' selected' : '') . '>Yes</option>';
echo '<option value="0"' . ((int)$onlyactive === 0 ? ' selected' : '') . '>No</option>';
echo '</select></div>';
echo '<div><label>Max classes</label><input type="number" name="maxclasses" value="' . (int)$maxclasses . '" class="form-control"></div>';
echo '</div>';
echo '<div style="margin-top:10px"><button class="btn btn-primary" type="submit">Diagnose</button></div>';
echo '</form>';
echo '</div>';

if (!empty($teacherresolve['warning'])) {
    echo '<div class="dbgtj-card"><span class="dbgtj-warn">' . dbgtj_h($teacherresolve['warning']) . '</span></div>';
}

if (!empty($teacherresolve['candidates']) && count($teacherresolve['candidates']) > 1 && !$teacherid) {
    echo '<div class="dbgtj-card">';
    echo '<strong>Teacher candidates</strong>';
    echo '<table class="dbgtj-table" style="margin-top:8px"><thead><tr><th>User ID</th><th>Name</th><th>Username</th><th>Email</th><th>Action</th></tr></thead><tbody>';
    foreach ($teacherresolve['candidates'] as $cand) {
        $pickurl = new moodle_url('/local/grupomakro_core/pages/debug_bbb_teacher_join.php', [
            'tab' => 'teacher',
            'teacherid' => (int)$cand->id,
            'teacher' => $teacherquery,
            'classname' => $classquery,
            'classid' => $classid,
            'onlyactive' => $onlyactive,
            'maxclasses' => $maxclasses,
        ]);
        echo '<tr>';
        echo '<td>' . (int)$cand->id . '</td>';
        echo '<td>' . dbgtj_h(trim((string)$cand->firstname . ' ' . (string)$cand->lastname)) . '</td>';
        echo '<td>' . dbgtj_h((string)$cand->username) . '</td>';
        echo '<td>' . dbgtj_h((string)$cand->email) . '</td>';
        echo '<td><a class="btn btn-secondary btn-sm" href="' . $pickurl->out(false) . '">Use</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
}

if (!$teacher) {
    echo '<div class="dbgtj-card"><span class="dbgtj-bad">Teacher not found.</span></div>';
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

$teacherid = (int)$teacher->id;
$teachername = trim((string)$teacher->firstname . ' ' . (string)$teacher->lastname);
$teacherisadmin = is_siteadmin($teacherid);
$repairfeedback = ['type' => '', 'message' => '', 'details' => []];

if ($repairaction !== '') {
    if (!confirm_sesskey()) {
        $repairfeedback = [
            'type' => 'bad',
            'message' => 'Invalid sesskey for repair action.',
            'details' => []
        ];
    } else if ($repairaction === 'fixmoderator' && $repairclassid > 0) {
        $repairfeedback = dbgtj_repair_moderator_rules_for_class((int)$repairclassid);
    } else if ($repairaction === 'sanitizebbb' && $repairclassid > 0) {
        $repairfeedback = dbgtj_sanitize_class_bbb_mapping((int)$repairclassid);
    }
}

echo '<div class="dbgtj-card">';
echo '<strong>Selected teacher</strong><br>';
echo 'User ID: ' . (int)$teacherid . ' | Name: ' . dbgtj_h($teachername) . ' | Username: ' . dbgtj_h((string)$teacher->username);
echo ' | Email: ' . dbgtj_h((string)$teacher->email) . ' | Admin: ' . ($teacherisadmin ? 'YES' : 'NO');
echo '</div>';

if (!empty($repairfeedback['message'])) {
    $cls = 'dbgtj-warn';
    if ($repairfeedback['type'] === 'ok') {
        $cls = 'dbgtj-ok';
    } else if ($repairfeedback['type'] === 'bad') {
        $cls = 'dbgtj-bad';
    }
    echo '<div class="dbgtj-card">';
    echo '<strong class="' . $cls . '">' . dbgtj_h((string)$repairfeedback['message']) . '</strong>';
    if (!empty($repairfeedback['details'])) {
        echo '<div class="dbgtj-pre" style="margin-top:8px">' . dbgtj_h(implode("\n", (array)$repairfeedback['details'])) . '</div>';
    }
    echo '</div>';
}

$classsql = "SELECT c.id, c.name, c.instructorid, c.corecourseid, c.groupid, c.periodid, c.closed, c.approved, c.enddate, c.bbbmoduleids,
                    c.attendancemoduleid, c.coursesectionid,
                    cr.fullname AS coursename, g.name AS groupname
               FROM {gmk_class} c
          LEFT JOIN {course} cr ON cr.id = c.corecourseid
          LEFT JOIN {groups} g ON g.id = c.groupid
              WHERE c.instructorid = :teacherid";
$classparams = ['teacherid' => $teacherid];
if ((int)$classid > 0) {
    $classsql .= " AND c.id = :classid";
    $classparams['classid'] = (int)$classid;
}
if ($classquery !== '') {
    $classsql .= " AND " . $DB->sql_like('c.name', ':classname', false, false);
    $classparams['classname'] = '%' . $classquery . '%';
}
if ((int)$onlyactive === 1) {
    $classsql .= " AND c.closed = 0";
}
$classsql .= " ORDER BY c.closed ASC, c.id DESC";

$allclasses = $DB->get_records_sql($classsql, $classparams, 0, $maxclasses);

echo '<div class="dbgtj-card">';
echo 'Classes found for teacher: <strong>' . count($allclasses) . '</strong>';
if (!empty($allclasses) && count($allclasses) >= $maxclasses) {
    echo ' <span class="dbgtj-warn">(limited to ' . (int)$maxclasses . ')</span>';
}
echo '</div>';

if (empty($allclasses)) {
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

$hasdeletion = isset($DB->get_columns('course_modules')['deletioninprogress']);
$summary = [
    'cm_missing' => 0,
    'cm_not_bbb' => 0,
    'bbb_missing' => 0,
    'cross_class_mapping' => 0,
    'shared_cmid_refs' => 0,
    'no_join_capability' => 0,
    'not_moderator_wait' => 0,
    'not_moderator_wait_running' => 0,
    'likely_ok' => 0,
    'relation_missing' => 0,
];

foreach ($allclasses as $class) {
    $cid = (int)$class->id;
    $relations = $DB->get_records('gmk_bbb_attendance_relation', ['classid' => $cid], 'id ASC');
    if (empty($relations)) {
        $summary['relation_missing']++;
    }

    $cmids = [];
    foreach (dbgtj_parse_int_list((string)$class->bbbmoduleids) as $mid) {
        $cmids[$mid] = $mid;
    }
    foreach ($relations as $rel) {
        $bbcm = (int)($rel->bbbmoduleid ?? 0);
        if ($bbcm > 0) {
            $cmids[$bbcm] = $bbcm;
        }
    }
    $cmids = array_values($cmids);

    echo '<div class="dbgtj-card">';
    echo '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px">';
    echo '<h4 style="margin:0 0 8px 0">Class #' . $cid . ' - ' . dbgtj_h((string)$class->name) . '</h4>';
    $repairurl = new moodle_url('/local/grupomakro_core/pages/debug_bbb_teacher_join.php', [
        'tab' => 'teacher',
        'teacher' => $teacherquery,
        'teacherid' => (int)$teacherid,
        'classname' => $classquery,
        'classid' => (int)$classid,
        'onlyactive' => (int)$onlyactive,
        'maxclasses' => (int)$maxclasses,
        'repair' => 'fixmoderator',
        'repairclassid' => (int)$cid,
        'sesskey' => sesskey(),
    ]);
    $sanitizeurl = new moodle_url('/local/grupomakro_core/pages/debug_bbb_teacher_join.php', [
        'tab' => 'teacher',
        'teacher' => $teacherquery,
        'teacherid' => (int)$teacherid,
        'classname' => $classquery,
        'classid' => (int)$classid,
        'onlyactive' => (int)$onlyactive,
        'maxclasses' => (int)$maxclasses,
        'repair' => 'sanitizebbb',
        'repairclassid' => (int)$cid,
        'sesskey' => sesskey(),
    ]);
    echo '<div><a class="btn btn-secondary btn-sm" style="margin-right:6px" href="' . $repairurl->out(false) . '">Repair Moderator Rules</a><a class="btn btn-secondary btn-sm" href="' . $sanitizeurl->out(false) . '">Sanitize Mapping</a></div>';
    echo '</div>';
    echo '<div class="dbgtj-grid" style="margin-bottom:10px">';
    echo '<div>Course: ' . (int)$class->corecourseid . ' - ' . dbgtj_h((string)($class->coursename ?? '')) . '</div>';
    echo '<div>Group: ' . (int)$class->groupid . ' - ' . dbgtj_h((string)($class->groupname ?? '')) . '</div>';
    echo '<div>Approved/Closed: ' . (int)$class->approved . '/' . (int)$class->closed . '</div>';
    echo '<div>Section/Attendance: ' . (int)$class->coursesectionid . ' / ' . (int)$class->attendancemoduleid . '</div>';
    echo '<div>Class bbbmoduleids: ' . dbgtj_h((string)$class->bbbmoduleids) . '</div>';
    echo '<div>Relation rows: ' . count($relations) . '</div>';
    echo '</div>';

    if (empty($relations)) {
        echo '<div class="dbgtj-bad">No rows in gmk_bbb_attendance_relation for this class.</div>';
    } else {
        echo '<details style="margin-bottom:8px"><summary>Relation rows</summary>';
        echo '<table class="dbgtj-table" style="margin-top:8px"><thead><tr><th>ID</th><th>attendancesessionid</th><th>bbbmoduleid</th><th>bbbid</th><th>attendanceid</th></tr></thead><tbody>';
        foreach ($relations as $rel) {
            echo '<tr>';
            echo '<td>' . (int)$rel->id . '</td>';
            echo '<td>' . (int)($rel->attendancesessionid ?? 0) . '</td>';
            echo '<td>' . (int)($rel->bbbmoduleid ?? 0) . '</td>';
            echo '<td>' . (int)($rel->bbbid ?? 0) . '</td>';
            echo '<td>' . (int)($rel->attendanceid ?? 0) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</details>';
    }

    if (empty($cmids)) {
        echo '<div class="dbgtj-bad">No BBB cmid candidates found from class.bbbmoduleids or relation rows.</div>';
        echo '</div>';
        continue;
    }

    list($insql, $inparams) = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED, 'cm');
    $cmsql = "SELECT cm.id, cm.module, cm.instance, cm.course, cm.section, cm.visible, m.name AS modulename" .
        ($hasdeletion ? ", cm.deletioninprogress AS deletioninprogress" : ", 0 AS deletioninprogress") . "
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id {$insql}";
    $cmrows = $DB->get_records_sql($cmsql, $inparams);

    echo '<table class="dbgtj-table">';
    echo '<thead><tr>';
    echo '<th>CMID</th><th>Module</th><th>BBB instance</th><th>token classid</th><th>wait</th><th>meeting running</th><th>teacher roles in cm</th>';
    echo '<th>join cap</th><th>group visible(0)</th><th>is moderator</th><th>explicit moderator users</th><th>predicted result</th><th>participant rules</th><th>raw participants</th><th>notes</th>';
    echo '</tr></thead><tbody>';

    foreach ($cmids as $cmiditem) {
        $cm = $cmrows[(int)$cmiditem] ?? null;
        $module = '-';
        $bbbname = '-';
        $wait = null;
        $runningstate = ['status' => 'skip', 'running' => null, 'message' => '-'];
        $cmroles = '-';
        $joincap = false;
        $groupvisible = false;
        $ismod = false;
        $predicted = '';
        $notes = [];
        $ruletext = '-';
        $explicitmodusers = [];
        $explicitmodtext = '-';
        $rawparticipantstext = '-';
        $tokenclassid = 0;
        $crossmapping = false;
        $sharedwithclasses = [];

        if (!$cm) {
            $predicted = 'invalid_cmid';
            $summary['cm_missing']++;
            $notes[] = 'cm not found';
        } else if ((string)$cm->modulename !== 'bigbluebuttonbn') {
            $module = (string)$cm->modulename;
            $predicted = 'invalid_module';
            $summary['cm_not_bbb']++;
            $notes[] = 'cmid is not bigbluebuttonbn';
        } else {
            $module = 'bigbluebuttonbn';
            if (!empty($cm->deletioninprogress)) {
                $notes[] = 'deletioninprogress=1';
            }

            $bbb = $DB->get_record('bigbluebuttonbn', ['id' => (int)$cm->instance], '*', IGNORE_MISSING);
            if (!$bbb) {
                $predicted = 'bbb_instance_missing';
                $summary['bbb_missing']++;
                $notes[] = 'bbb instance not found';
            } else {
                $bbbname = (string)$bbb->name . ' (#' . (int)$bbb->id . ')';
                $wait = isset($bbb->wait) ? (int)$bbb->wait : 0;
                $runningstate = dbgtj_bbb_running_state((string)($bbb->meetingid ?? ''));
                $rawparticipants = trim((string)($bbb->participants ?? ''));
                $tokenclassid = dbgtj_extract_classid_from_bbb_name((string)($bbb->name ?? ''));
                if ($tokenclassid > 0 && $tokenclassid !== (int)$cid) {
                    $crossmapping = true;
                    $notes[] = 'cross_class_bbb_mapping tokenclassid=' . (int)$tokenclassid . ' classid=' . (int)$cid;
                }
                $sharedwithclasses = dbgtj_other_classes_using_bbb_cmid((int)$cm->id, (int)$cid);
                if (!empty($sharedwithclasses)) {
                    $notes[] = 'shared_bbb_cmid_with_classes=' . implode(',', $sharedwithclasses);
                }
                if ($rawparticipants === '') {
                    $rawparticipantstext = 'EMPTY';
                } else {
                    $decodedraw = json_decode($rawparticipants, true);
                    $jsonok = (json_last_error() === JSON_ERROR_NONE && is_array($decodedraw));
                    $rawparticipantstext = ($jsonok ? 'JSON_OK ' : 'JSON_ERR ') . substr($rawparticipants, 0, 180);
                }

                $cmcontext = context_module::instance((int)$cm->id, IGNORE_MISSING);
                $courseobj = $DB->get_record('course', ['id' => (int)$cm->course], '*', IGNORE_MISSING);
                $cmobj = get_coursemodule_from_id('bigbluebuttonbn', (int)$cm->id, 0, false, IGNORE_MISSING);

                if ($cmcontext && $cmobj && $courseobj) {
                    $cmroles = dbgtj_context_roles($cmcontext, $teacherid);
                    $joincap = has_capability('mod/bigbluebuttonbn:join', $cmcontext, $teacherid);
                    $groupvisible = groups_group_visible(0, $courseobj, $cmobj, $teacherid);

                    if (class_exists('\mod_bigbluebuttonbn\local\helpers\roles')) {
                        $rules = \mod_bigbluebuttonbn\local\helpers\roles::get_participant_list($bbb, $cmcontext);
                        $ruletext = dbgtj_participant_rules_text($rules);
                        $ismod = dbgtj_is_moderator_for_user($cmcontext, (array)$rules, $teacherid);
                        $explicitmodusers = dbgtj_extract_explicit_moderator_users((array)$rules);
                        if (!empty($explicitmodusers)) {
                            ksort($explicitmodusers);
                            $explicitmodtext = implode(',', array_values($explicitmodusers));
                            if (empty($explicitmodusers[$teacherid])) {
                                $notes[] = 'moderator_user_mismatch expected=' . (int)$teacherid . ' configured=' . $explicitmodtext;
                            }
                        }
                    } else {
                        $notes[] = 'roles helper class not found';
                    }

                    $canjoin = $teacherisadmin || ($joincap && $groupvisible);
                    $mustwait = ((int)$wait === 1) && !$teacherisadmin && !$ismod;
                    $explicitmismatchwait = ((int)$wait === 1)
                        && !$teacherisadmin
                        && !empty($explicitmodusers)
                        && empty($explicitmodusers[$teacherid]);

                    if ($crossmapping) {
                        $predicted = 'cross_class_bbb_mapping';
                        $summary['cross_class_mapping']++;
                    } else if (!empty($sharedwithclasses)) {
                        $predicted = 'shared_bbb_cmid_mapping';
                        $summary['shared_cmid_refs']++;
                    } else if (!$canjoin) {
                        $predicted = 'no_join_capability_or_group_visibility';
                        $summary['no_join_capability']++;
                    } else if ($explicitmismatchwait) {
                        if ($runningstate['status'] === 'ok' && $runningstate['running'] === false) {
                            $predicted = 'join_url_empty_moderator_user_mismatch (deterministic)';
                            $summary['not_moderator_wait']++;
                        } else {
                            $predicted = 'moderator_user_mismatch + wait=1 (likely fail until moderator starts)';
                            $summary['not_moderator_wait_running']++;
                        }
                    } else if ($mustwait) {
                        if ($runningstate['status'] === 'ok' && $runningstate['running'] === false) {
                            $predicted = 'join_url_empty_waiting_for_moderator (deterministic)';
                            $summary['not_moderator_wait']++;
                        } else {
                            $predicted = 'not_moderator_wait=1 (may fail when room not running)';
                            $summary['not_moderator_wait_running']++;
                        }
                    } else {
                        $predicted = 'should_join_ok';
                        $summary['likely_ok']++;
                    }
                } else {
                    $predicted = 'context_or_cm_missing';
                    $notes[] = 'cm context or cm object not resolved';
                }
            }
        }

        $runninglabel = '-';
        if ($runningstate['status'] === 'ok') {
            $runninglabel = $runningstate['running'] ? 'YES' : 'NO';
        } else if ($runningstate['status'] === 'skip') {
            $runninglabel = 'SKIP (' . dbgtj_h($runningstate['message']) . ')';
        } else {
            $runninglabel = 'ERR (' . dbgtj_h($runningstate['message']) . ')';
        }

        $predclass = 'dbgtj-ok';
        if (strpos($predicted, 'wait') !== false || strpos($predicted, 'mismatch') !== false) {
            $predclass = 'dbgtj-warn';
        }
        if (strpos($predicted, 'no_join') !== false || strpos($predicted, 'invalid') !== false || strpos($predicted, 'missing') !== false || strpos($predicted, 'cross_class') !== false || strpos($predicted, 'shared_bbb_cmid') !== false) {
            $predclass = 'dbgtj-bad';
        }

        echo '<tr>';
        echo '<td>' . (int)$cmiditem . '</td>';
        echo '<td>' . dbgtj_h($module) . '</td>';
        echo '<td>' . dbgtj_h($bbbname) . '</td>';
        echo '<td>' . ($tokenclassid > 0 ? (int)$tokenclassid : '-') . '</td>';
        echo '<td>' . (($wait === null) ? '-' : (int)$wait) . '</td>';
        echo '<td>' . $runninglabel . '</td>';
        echo '<td>' . dbgtj_h($cmroles) . '</td>';
        echo '<td>' . ($joincap ? '<span class="dbgtj-badge dbgtj-badge-ok">YES</span>' : '<span class="dbgtj-badge dbgtj-badge-bad">NO</span>') . '</td>';
        echo '<td>' . ($groupvisible ? '<span class="dbgtj-badge dbgtj-badge-ok">YES</span>' : '<span class="dbgtj-badge dbgtj-badge-bad">NO</span>') . '</td>';
        echo '<td>' . ($ismod ? '<span class="dbgtj-badge dbgtj-badge-ok">YES</span>' : '<span class="dbgtj-badge dbgtj-badge-bad">NO</span>') . '</td>';
        echo '<td>' . dbgtj_h($explicitmodtext) . '</td>';
        echo '<td><span class="' . $predclass . '">' . dbgtj_h($predicted) . '</span></td>';
        echo '<td><div class="dbgtj-pre">' . dbgtj_h($ruletext) . '</div></td>';
        echo '<td><div class="dbgtj-pre">' . dbgtj_h($rawparticipantstext) . '</div></td>';
        echo '<td>' . dbgtj_h(implode(' | ', $notes)) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
}

echo '<div class="dbgtj-card">';
echo '<h4 style="margin-top:0">Summary</h4>';
echo '<div class="dbgtj-grid">';
echo '<div>classes with no relation: <strong>' . (int)$summary['relation_missing'] . '</strong></div>';
echo '<div>invalid cmid: <strong>' . (int)$summary['cm_missing'] . '</strong></div>';
echo '<div>cm not bbb: <strong>' . (int)$summary['cm_not_bbb'] . '</strong></div>';
echo '<div>bbb instance missing: <strong>' . (int)$summary['bbb_missing'] . '</strong></div>';
echo '<div>cross-class BBB mapping: <strong>' . (int)$summary['cross_class_mapping'] . '</strong></div>';
echo '<div>shared BBB cmid refs: <strong>' . (int)$summary['shared_cmid_refs'] . '</strong></div>';
echo '<div>no join capability/group visibility: <strong>' . (int)$summary['no_join_capability'] . '</strong></div>';
echo '<div>not moderator + wait + meeting not running: <strong>' . (int)$summary['not_moderator_wait'] . '</strong></div>';
echo '<div>not moderator + wait (running unknown/running): <strong>' . (int)$summary['not_moderator_wait_running'] . '</strong></div>';
echo '<div>likely ok: <strong>' . (int)$summary['likely_ok'] . '</strong></div>';
echo '</div>';
echo '</div>';

echo '</div>';
echo $OUTPUT->footer();
