<?php
/**
 * Debug page for academic_demand_gaps.php (single student).
 *
 * It reproduces tab "gaps" filters and inclusion gates for one student.
 *
 * @package local_grupomakro_core
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$q = optional_param('q', '', PARAM_RAW_TRIMMED);
$userid = optional_param('userid', 0, PARAM_INT);
$filterplanid = optional_param('planid', 0, PARAM_INT);
$filtershift = optional_param('shift', '', PARAM_RAW_TRIMMED);
$filtermin = optional_param('min', 'both', PARAM_ALPHA); // zero | one | both
$filtersearch = optional_param('search', '', PARAM_RAW_TRIMMED);

if (!in_array($filtermin, array('zero', 'one', 'both'), true)) {
    $filtermin = 'both';
}

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_academic_demand_gaps_student.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Debug academic demand gaps');
$PAGE->set_heading('Debug academic demand gaps');

/**
 * Escape HTML.
 * @param mixed $value
 * @return string
 */
function adgds_h($value): string {
    if ($value === null) {
        return '';
    }
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Render badge.
 * @param string $text
 * @param string $kind
 * @return string
 */
function adgds_badge(string $text, string $kind = 'info'): string {
    $styles = array(
        'ok' => 'background:#198754;color:#fff',
        'no' => 'background:#dc3545;color:#fff',
        'warn' => 'background:#fd7e14;color:#fff',
        'info' => 'background:#0d6efd;color:#fff',
        'muted' => 'background:#6c757d;color:#fff',
    );
    $style = isset($styles[$kind]) ? $styles[$kind] : $styles['muted'];
    return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;' .
        $style . '">' . adgds_h($text) . '</span>';
}

/**
 * Normalize text for accent-insensitive checks.
 * @param string $text
 * @return string
 */
function adgds_norm(string $text): string {
    $txt = trim($text);
    if ($txt === '') {
        return '';
    }
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $txt);
    if ($ascii !== false && $ascii !== '') {
        $txt = $ascii;
    }
    if (function_exists('mb_strtoupper')) {
        $txt = mb_strtoupper($txt, 'UTF-8');
    } else {
        $txt = strtoupper($txt);
    }
    $txt = preg_replace('/\s+/', ' ', $txt);
    return trim((string)$txt);
}

/**
 * Case-insensitive contains.
 * @param string $needle
 * @param string $haystack
 * @return bool
 */
function adgds_like_match(string $needle, string $haystack): bool {
    $needle = trim($needle);
    if ($needle === '') {
        return true;
    }
    return stripos($haystack, $needle) !== false;
}

/**
 * Returns true when course is excluded by name.
 * @param string $fullname
 * @return bool
 */
function adgds_is_excluded_course(string $fullname): bool {
    $name = adgds_norm($fullname);
    if (strpos($name, 'PRACTICA PROFESIONAL') !== false) {
        return true;
    }
    if (strpos($name, 'PROYECTO DE GRADO') !== false) {
        return true;
    }
    return false;
}

// 1) Resolve jornada custom field.
$jornadafieldid = 0;
$allshifts = array();
try {
    $jornadafield = $DB->get_record('user_info_field', array('shortname' => 'gmkjourney'), 'id', IGNORE_MISSING);
    if ($jornadafield) {
        $jornadafieldid = (int)$jornadafield->id;
    }
    if ($jornadafieldid > 0) {
        $allshifts = $DB->get_fieldset_sql(
            "SELECT DISTINCT data
               FROM {user_info_data}
              WHERE fieldid = :fid
                AND " . $DB->sql_isnotempty('user_info_data', 'data', false, false) . "
           ORDER BY data ASC",
            array('fid' => $jornadafieldid)
        );
        $allshifts = array_values(array_filter((array)$allshifts));
    }
} catch (Exception $e) {
    // Keep defaults.
}

// 2) Plans list.
$plansmap = array();
try {
    $planrows = $DB->get_records('local_learning_plans', null, 'name ASC', 'id,name');
    foreach ($planrows as $pr) {
        $plansmap[(int)$pr->id] = (string)$pr->name;
    }
} catch (Exception $e) {
    // Keep defaults.
}

// 3) Build soldadura-exclusive course ids (same approach as academic_demand_gaps.php).
$soldaduraexclusivecourseids = array();
try {
    $structurerows = $DB->get_records_sql(
        "SELECT lpc.id AS rid, p.learningplanid, c.id AS courseid
           FROM {local_learning_periods} p
           JOIN {local_learning_courses} lpc ON lpc.periodid = p.id
           JOIN {course} c ON c.id = lpc.courseid
        ORDER BY p.learningplanid ASC, p.id ASC"
    );

    $coursesbyplan = array();
    foreach ($structurerows as $sr) {
        $pid = (int)$sr->learningplanid;
        $cid = (int)$sr->courseid;
        if (!isset($coursesbyplan[$pid])) {
            $coursesbyplan[$pid] = array();
        }
        $coursesbyplan[$pid][$cid] = true;
    }

    $soldaduraplanid = 0;
    foreach ($plansmap as $pid => $pname) {
        $normname = adgds_norm((string)$pname);
        if (strpos($normname, 'SOLDADURA') !== false || strpos($normname, 'SUBACUATICA') !== false) {
            $soldaduraplanid = (int)$pid;
            break;
        }
    }

    if ($soldaduraplanid > 0 && isset($coursesbyplan[$soldaduraplanid])) {
        foreach ($coursesbyplan[$soldaduraplanid] as $cid => $unused) {
            $shared = false;
            foreach ($coursesbyplan as $pid => $courses) {
                if ((int)$pid === $soldaduraplanid) {
                    continue;
                }
                if (!empty($courses[(int)$cid])) {
                    $shared = true;
                    break;
                }
            }
            if (!$shared) {
                $soldaduraexclusivecourseids[(int)$cid] = true;
            }
        }
    }
} catch (Exception $e) {
    // Keep defaults.
}

// 4) Resolve target user.
$targetuser = null;
$matches = array();
if ($userid > 0) {
    $targetuser = $DB->get_record(
        'user',
        array('id' => $userid),
        'id,firstname,lastname,email,username,idnumber,deleted,suspended',
        IGNORE_MISSING
    );
} else {
    $qtrim = trim($q);
    if ($qtrim !== '') {
        $qlike = '%' . $DB->sql_like_escape($qtrim) . '%';
        $params = array(
            'qexact1' => $qtrim,
            'qexact2' => $qtrim,
            'qexact3' => $qtrim,
            'qlike1' => $qlike,
            'qlike2' => $qlike,
            'qlike3' => $qlike,
        );

        $matches = array_values($DB->get_records_sql(
            "SELECT id, firstname, lastname, email, username, idnumber, deleted, suspended
               FROM {user}
              WHERE deleted = 0
                AND (
                    idnumber = :qexact1
                    OR username = :qexact2
                    OR email = :qexact3
                    OR " . $DB->sql_like('firstname', ':qlike1', false) . "
                    OR " . $DB->sql_like('lastname', ':qlike2', false) . "
                    OR " . $DB->sql_like($DB->sql_concat_join("' '", array('firstname', 'lastname')), ':qlike3', false) . "
                )
           ORDER BY lastname ASC, firstname ASC
              LIMIT 30",
            $params
        ));

        if (count($matches) === 1) {
            $targetuser = $matches[0];
        }
    }
}

echo $OUTPUT->header();
?>
<style>
.adgds-wrap{max-width:1460px;margin:0 auto;padding:14px;font-family:system-ui,sans-serif}
.adgds-card{background:#fff;border:1px solid #dee2e6;border-radius:8px;padding:12px;margin:10px 0}
.adgds-title{font-size:16px;font-weight:700;margin-bottom:8px}
.adgds-form{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end}
.adgds-form label{font-size:12px;font-weight:600;display:block;margin-bottom:3px}
.adgds-form input,.adgds-form select{padding:6px 10px;border:1px solid #ced4da;border-radius:4px;font-size:13px}
.adgds-table{width:100%;border-collapse:collapse;font-size:12px}
.adgds-table th{background:#212529;color:#fff;text-align:left;padding:8px;border:1px solid #495057}
.adgds-table td{padding:8px;border:1px solid #dee2e6;vertical-align:top}
.adgds-table tr:nth-child(even) td{background:#f8f9fa}
.adgds-muted{font-size:11px;color:#6c757d}
.adgds-err{background:#fff3cd;border:1px solid #ffecb5;border-radius:6px;padding:10px}
.adgds-ok{background:#d1e7dd;border:1px solid #badbcc;border-radius:6px;padding:10px}
</style>

<div class="adgds-wrap">
    <h2 style="margin:0 0 6px 0">Debug: academic_demand_gaps (single student)</h2>
    <div class="adgds-muted">This checks tab gaps with the same filters and exclusion rules.</div>

    <div class="adgds-card">
        <form method="get" class="adgds-form">
            <div>
                <label>Student query (idnumber, username, email, name)</label>
                <input type="text" name="q" value="<?php echo adgds_h($q); ?>" style="width:320px">
            </div>
            <div>
                <label>Search filter (same as page: firstname/lastname/email/idnumber/username)</label>
                <input type="text" name="search" value="<?php echo adgds_h($filtersearch); ?>" style="width:320px">
            </div>
            <div>
                <label>Plan filter</label>
                <select name="planid">
                    <option value="0">All</option>
                    <?php foreach ($plansmap as $pid => $pname): ?>
                        <option value="<?php echo (int)$pid; ?>" <?php echo ((int)$pid === (int)$filterplanid ? 'selected' : ''); ?>>
                            <?php echo adgds_h($pname); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Shift filter</label>
                <select name="shift">
                    <option value="" <?php echo ($filtershift === '' ? 'selected' : ''); ?>>All</option>
                    <?php foreach ($allshifts as $shiftopt): ?>
                        <?php $shiftopt = trim((string)$shiftopt); ?>
                        <?php if ($shiftopt === '') { continue; } ?>
                        <option value="<?php echo adgds_h($shiftopt); ?>" <?php echo ($filtershift === $shiftopt ? 'selected' : ''); ?>>
                            <?php echo adgds_h($shiftopt); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Min filter (HAVING)</label>
                <select name="min">
                    <option value="both" <?php echo ($filtermin === 'both' ? 'selected' : ''); ?>>both (<=1)</option>
                    <option value="zero" <?php echo ($filtermin === 'zero' ? 'selected' : ''); ?>>zero (=0)</option>
                    <option value="one" <?php echo ($filtermin === 'one' ? 'selected' : ''); ?>>one (=1)</option>
                </select>
            </div>
            <div>
                <button type="submit" class="btn btn-primary">Diagnose</button>
            </div>
        </form>
    </div>

<?php
if (!$targetuser && !empty($matches)) {
    echo '<div class="adgds-card"><div class="adgds-title">Multiple matches</div>';
    echo '<table class="adgds-table"><thead><tr><th>User ID</th><th>Name</th><th>ID Number</th><th>Username</th><th>Email</th><th>Action</th></tr></thead><tbody>';
    foreach ($matches as $m) {
        $u = new moodle_url('/local/grupomakro_core/pages/debug_academic_demand_gaps_student.php', array(
            'userid' => (int)$m->id,
            'q' => $q,
            'search' => $filtersearch,
            'planid' => $filterplanid,
            'shift' => $filtershift,
            'min' => $filtermin,
        ));
        echo '<tr>';
        echo '<td>' . (int)$m->id . '</td>';
        echo '<td>' . adgds_h(trim((string)$m->firstname . ' ' . (string)$m->lastname)) . '</td>';
        echo '<td>' . adgds_h($m->idnumber) . '</td>';
        echo '<td>' . adgds_h($m->username) . '</td>';
        echo '<td>' . adgds_h($m->email) . '</td>';
        echo '<td><a class="btn btn-secondary btn-sm" href="' . $u->out(false) . '">Use</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
} else if (!$targetuser) {
    echo '<div class="adgds-card adgds-err"><strong>No student resolved.</strong> Search by idnumber, username, email or name.</div>';
} else {
    $uid = (int)$targetuser->id;
    $fullname = trim((string)$targetuser->firstname . ' ' . (string)$targetuser->lastname);
    $shiftvalue = '';
    if ($jornadafieldid > 0) {
        $shiftvalue = (string)$DB->get_field(
            'user_info_data',
            'data',
            array('userid' => $uid, 'fieldid' => $jornadafieldid),
            IGNORE_MISSING
        );
    }

    // local_learning_users rows.
    $llurows = array_values($DB->get_records_sql(
        "SELECT llu.id, llu.userid, llu.learningplanid, llu.currentperiodid, llu.status, llu.userrolename,
                lp.name AS planname, per.name AS periodname
           FROM {local_learning_users} llu
      LEFT JOIN {local_learning_plans} lp ON lp.id = llu.learningplanid
      LEFT JOIN {local_learning_periods} per ON per.id = llu.currentperiodid
          WHERE llu.userid = :uid
       ORDER BY llu.id ASC",
        array('uid' => $uid)
    ));

    $activestudentrows = array_values(array_filter($llurows, function($r) {
        return ((string)$r->userrolename === 'student' && (string)$r->status === 'activo');
    }));

    $planpass = true;
    if ($filterplanid > 0) {
        $planpass = false;
        foreach ($activestudentrows as $ar) {
            if ((int)$ar->learningplanid === (int)$filterplanid) {
                $planpass = true;
                break;
            }
        }
    }

    // In-progress rows (status=2), same counting basis as page.
    $inprogressrows = array_values($DB->get_records_sql(
        "SELECT cp.id, cp.courseid, cp.status, cp.classid, cp.groupid, cp.learningplanid, cp.timemodified, c.fullname
           FROM {gmk_course_progre} cp
           JOIN {course} c ON c.id = cp.courseid
          WHERE cp.userid = :uid AND cp.status = 2
       ORDER BY cp.id DESC",
        array('uid' => $uid)
    ));
    $inprogresscount = count($inprogressrows);

    // Pending rows (status 0/1), then exclusions.
    $pendingraw = array_values($DB->get_records_sql(
        "SELECT cp.id, cp.courseid, cp.status, cp.classid, cp.groupid, cp.learningplanid, cp.timemodified, c.fullname
           FROM {gmk_course_progre} cp
           JOIN {course} c ON c.id = cp.courseid
          WHERE cp.userid = :uid
            AND cp.status IN (0,1)
       ORDER BY cp.courseid ASC, cp.id ASC",
        array('uid' => $uid)
    ));

    $pendingbycourse = array();
    foreach ($pendingraw as $pr) {
        $cid = (int)$pr->courseid;
        if (!isset($pendingbycourse[$cid])) {
            $pendingbycourse[$cid] = array(
                'courseid' => $cid,
                'fullname' => (string)$pr->fullname,
                'cpstatus' => (int)$pr->status,
                'rows' => array(),
            );
        }
        $pendingbycourse[$cid]['cpstatus'] = max((int)$pendingbycourse[$cid]['cpstatus'], (int)$pr->status);
        $pendingbycourse[$cid]['rows'][] = $pr;
    }

    $pendingincluded = array();
    $pendingexcluded = array();
    foreach ($pendingbycourse as $cid => $pc) {
        $excludedbyname = adgds_is_excluded_course((string)$pc['fullname']);
        $excludedbysoldadura = !empty($soldaduraexclusivecourseids[(int)$cid]);
        if ($excludedbyname || $excludedbysoldadura) {
            $reason = array();
            if ($excludedbyname) {
                $reason[] = 'excluded_course_name';
            }
            if ($excludedbysoldadura) {
                $reason[] = 'soldadura_exclusive';
            }
            $pc['exclude_reason'] = implode(', ', $reason);
            $pendingexcluded[$cid] = $pc;
        } else {
            $pendingincluded[$cid] = $pc;
        }
    }
    $pendingincludedcount = count($pendingincluded);

    // Search behavior in real page: firstname, lastname, email, idnumber, username.
    $searchpass = true;
    if (trim($filtersearch) !== '') {
        $needle = trim($filtersearch);
        $searchpass =
            adgds_like_match($needle, (string)$targetuser->firstname) ||
            adgds_like_match($needle, (string)$targetuser->lastname) ||
            adgds_like_match($needle, (string)$targetuser->email) ||
            adgds_like_match($needle, (string)$targetuser->idnumber) ||
            adgds_like_match($needle, (string)$targetuser->username);
    }
    $qwouldmatchpagesearch = false;
    if (trim($q) !== '') {
        $qneedle = trim($q);
        $qwouldmatchpagesearch =
            adgds_like_match($qneedle, (string)$targetuser->firstname) ||
            adgds_like_match($qneedle, (string)$targetuser->lastname) ||
            adgds_like_match($qneedle, (string)$targetuser->email) ||
            adgds_like_match($qneedle, (string)$targetuser->idnumber) ||
            adgds_like_match($qneedle, (string)$targetuser->username);
    }

    // HAVING logic.
    if ($filtermin === 'zero') {
        $havingpass = ($inprogresscount === 0);
        $havingclause = 'HAVING COUNT(cp_active.id) = 0';
    } else if ($filtermin === 'one') {
        $havingpass = ($inprogresscount === 1);
        $havingclause = 'HAVING COUNT(cp_active.id) = 1';
    } else {
        $havingpass = ($inprogresscount <= 1);
        $havingclause = 'HAVING COUNT(cp_active.id) <= 1';
    }

    $userstatepass = ((int)$targetuser->deleted === 0 && (int)$targetuser->suspended === 0);
    $hasactivellu = !empty($activestudentrows);
    $shiftpass = ($filtershift === '' || ($jornadafieldid > 0 && trim((string)$shiftvalue) === trim((string)$filtershift)));

    // Run equivalent SQL from page (targeted to this user only).
    $sqlrows = array();
    $sqlerror = '';
    try {
        if ($userstatepass && $hasactivellu && $planpass && $searchpass && $shiftpass && $havingpass) {
            if ($jornadafieldid > 0) {
                $jornadajoin = "LEFT JOIN {user_info_data} uid_j
                                      ON uid_j.userid = u.id AND uid_j.fieldid = :jfid";
                $jornadaselect = ', uid_j.data AS shift';
                $jornadagroup = ', uid_j.data';
            } else {
                $jornadajoin = '';
                $jornadaselect = ", '' AS shift";
                $jornadagroup = '';
            }

            $extrawhere = '';
            $searchwhere = '';
            $params = array('uid' => $uid);
            if ($jornadafieldid > 0) {
                $params['jfid'] = $jornadafieldid;
            }
            if ($filterplanid > 0) {
                $extrawhere .= ' AND lp.id = :planid';
                $params['planid'] = $filterplanid;
            }
            if ($filtershift !== '' && $jornadafieldid > 0) {
                $extrawhere .= ' AND uid_j.data = :shift';
                $params['shift'] = $filtershift;
            }
            if (trim($filtersearch) !== '') {
                $like = '%' . $DB->sql_like_escape(trim($filtersearch)) . '%';
                $searchwhere = " AND (" . $DB->sql_like('u.firstname', ':s1', false) .
                               " OR " . $DB->sql_like('u.lastname', ':s2', false) .
                               " OR " . $DB->sql_like('u.email', ':s3', false) .
                               " OR " . $DB->sql_like('u.idnumber', ':s4', false) .
                               " OR " . $DB->sql_like('u.username', ':s5', false) . ")";
                $params['s1'] = $like;
                $params['s2'] = $like;
                $params['s3'] = $like;
                $params['s4'] = $like;
                $params['s5'] = $like;
            }

            $gapsql = "
                SELECT llu.id AS subid,
                       u.id AS userid,
                       u.firstname, u.lastname, u.email, u.idnumber,
                       lp.id AS planid, lp.name AS planname,
                       lp_per.id AS periodid, lp_per.name AS periodname,
                       COUNT(cp_active.id) AS inprogress_count
                       {$jornadaselect}
                  FROM {user} u
                  JOIN {local_learning_users} llu
                       ON llu.userid = u.id AND llu.userrolename = 'student'
                  JOIN {local_learning_plans} lp ON lp.id = llu.learningplanid
                  LEFT JOIN {local_learning_periods} lp_per ON lp_per.id = llu.currentperiodid
                  {$jornadajoin}
                  LEFT JOIN {gmk_course_progre} cp_active
                       ON cp_active.userid = u.id AND cp_active.status = 2
                 WHERE u.id = :uid
                   AND u.deleted = 0
                   AND u.suspended = 0
                   AND llu.status = 'activo'
                   {$extrawhere}
                   {$searchwhere}
              GROUP BY llu.id, u.id, u.firstname, u.lastname, u.email, u.idnumber,
                       lp.id, lp.name, lp_per.id, lp_per.name
                       {$jornadagroup}
              {$havingclause}";

            $sqlrows = array_values($DB->get_records_sql($gapsql, $params));
        }
    } catch (Exception $e) {
        $sqlerror = $e->getMessage();
    }

    $finalappears = (!empty($sqlrows) && $pendingincludedcount > 0);
    $reasons = array();
    if (!$userstatepass) {
        $reasons[] = 'user_deleted_or_suspended';
    }
    if (!$hasactivellu) {
        $reasons[] = 'no_local_learning_users_active_student';
    }
    if (!$planpass) {
        $reasons[] = 'plan_filter_not_matching';
    }
    if (!$searchpass) {
        $reasons[] = 'search_filter_not_matching_supported_fields';
    }
    if (!$shiftpass) {
        if ($filtershift !== '' && $jornadafieldid <= 0) {
            $reasons[] = 'shift_filter_requested_but_gmkjourney_field_not_found';
        } else {
            $reasons[] = 'shift_filter_not_matching';
        }
    }
    if (!$havingpass) {
        $reasons[] = 'fails_having_inprogress_filter';
    }
    if (!empty($sqlerror)) {
        $reasons[] = 'gap_students_sql_error';
    } else if (empty($sqlrows)) {
        $reasons[] = 'not_returned_by_gap_students_sql';
    }
    if ($pendingincludedcount <= 0) {
        if (!empty($pendingbycourse)) {
            $reasons[] = 'all_pending_courses_excluded_by_rules';
        } else {
            $reasons[] = 'no_pending_status_0_or_1';
        }
    }

    echo '<div class="adgds-card">';
    echo '<div class="adgds-title">Selected student</div>';
    echo '<div><strong>' . adgds_h($fullname) . '</strong></div>';
    echo '<div class="adgds-muted">uid=' . (int)$uid .
        ' | idnumber=' . adgds_h($targetuser->idnumber) .
        ' | username=' . adgds_h($targetuser->username) .
        ' | email=' . adgds_h($targetuser->email) . '</div>';
    echo '<div class="adgds-muted">Filter search="' . adgds_h($filtersearch) . '" | q would match page search? ' .
        ($qwouldmatchpagesearch ? 'YES' : 'NO') . '</div>';
    echo '<div style="margin-top:8px">' .
        ($finalappears ? adgds_badge('SHOULD APPEAR', 'ok') : adgds_badge('DOES NOT APPEAR', 'no')) .
        '</div>';
    echo '</div>';

    if (!empty($sqlerror)) {
        echo '<div class="adgds-card adgds-err"><strong>SQL error while reproducing gap query:</strong> ' .
            adgds_h($sqlerror) . '</div>';
    }

    echo '<div class="adgds-card"><div class="adgds-title">Gate checks</div><table class="adgds-table"><thead><tr><th>Check</th><th>Result</th><th>Detail</th></tr></thead><tbody>';
    echo '<tr><td>User state</td><td>' . ($userstatepass ? adgds_badge('PASS', 'ok') : adgds_badge('FAIL', 'no')) .
        '</td><td>deleted=' . (int)$targetuser->deleted . ', suspended=' . (int)$targetuser->suspended . '</td></tr>';
    echo '<tr><td>Has active local_learning_users (student)</td><td>' . ($hasactivellu ? adgds_badge('PASS', 'ok') : adgds_badge('FAIL', 'no')) .
        '</td><td>rows=' . count($activestudentrows) . '</td></tr>';
    echo '<tr><td>Plan filter</td><td>' . ($planpass ? adgds_badge('PASS', 'ok') : adgds_badge('FAIL', 'no')) .
        '</td><td>planid=' . (int)$filterplanid . '</td></tr>';
    echo '<tr><td>Search filter (firstname/lastname/email/idnumber/username)</td><td>' . ($searchpass ? adgds_badge('PASS', 'ok') : adgds_badge('FAIL', 'no')) .
        '</td><td>search="' . adgds_h($filtersearch) . '"</td></tr>';
    echo '<tr><td>Shift filter</td><td>' . ($shiftpass ? adgds_badge('PASS', 'ok') : adgds_badge('FAIL', 'no')) .
        '</td><td>filter="' . adgds_h($filtershift) . '" / student_shift="' . adgds_h($shiftvalue) . '" / fieldid=' . (int)$jornadafieldid . '</td></tr>';
    echo '<tr><td>In-progress HAVING</td><td>' . ($havingpass ? adgds_badge('PASS', 'ok') : adgds_badge('FAIL', 'no')) .
        '</td><td>inprogress_count=' . (int)$inprogresscount . ', min_filter=' . adgds_h($filtermin) . '</td></tr>';
    echo '<tr><td>Rows from gap_students_sql</td><td>' . (!empty($sqlrows) ? adgds_badge('PASS', 'ok') : adgds_badge('FAIL', 'no')) .
        '</td><td>rows=' . count($sqlrows) . '</td></tr>';
    echo '<tr><td>Pending subjects after exclusions</td><td>' . ($pendingincludedcount > 0 ? adgds_badge('PASS', 'ok') : adgds_badge('FAIL', 'no')) .
        '</td><td>included=' . $pendingincludedcount . ', excluded=' . count($pendingexcluded) . '</td></tr>';
    echo '</tbody></table></div>';

    echo '<div class="adgds-card"><div class="adgds-title">Why missing?</div>';
    if ($finalappears) {
        echo '<div class="adgds-ok">Student passes all checks and should appear in academic_demand_gaps.php tab gaps.</div>';
    } else {
        echo '<div class="adgds-err"><strong>Detected reasons:</strong><ul>';
        foreach ($reasons as $reason) {
            echo '<li>' . adgds_h($reason) . '</li>';
        }
        echo '</ul></div>';
    }
    echo '</div>';

    echo '<div class="adgds-card"><div class="adgds-title">Rows returned by gap SQL</div>';
    if (empty($sqlrows)) {
        echo '<div class="adgds-muted">No rows.</div>';
    } else {
        echo '<table class="adgds-table"><thead><tr><th>subid</th><th>plan</th><th>period</th><th>inprogress_count</th><th>shift</th></tr></thead><tbody>';
        foreach ($sqlrows as $sr) {
            echo '<tr>';
            echo '<td>' . (int)$sr->subid . '</td>';
            echo '<td>' . adgds_h((int)$sr->planid . ' - ' . $sr->planname) . '</td>';
            echo '<td>' . adgds_h((int)$sr->periodid . ' - ' . ($sr->periodname ?: '-')) . '</td>';
            echo '<td>' . (int)$sr->inprogress_count . '</td>';
            echo '<td>' . adgds_h(isset($sr->shift) ? (string)$sr->shift : '') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';

    echo '<div class="adgds-card"><div class="adgds-title">local_learning_users rows</div>';
    if (empty($llurows)) {
        echo '<div class="adgds-muted">No rows.</div>';
    } else {
        echo '<table class="adgds-table"><thead><tr><th>llu.id</th><th>plan</th><th>current period</th><th>status</th><th>role</th></tr></thead><tbody>';
        foreach ($llurows as $lr) {
            echo '<tr>';
            echo '<td>' . (int)$lr->id . '</td>';
            echo '<td>' . adgds_h((int)$lr->learningplanid . ' - ' . ($lr->planname ?: '-')) . '</td>';
            echo '<td>' . adgds_h((int)$lr->currentperiodid . ' - ' . ($lr->periodname ?: '-')) . '</td>';
            echo '<td>' . adgds_h($lr->status) . '</td>';
            echo '<td>' . adgds_h($lr->userrolename) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';

    echo '<div class="adgds-card"><div class="adgds-title">In-progress rows (status=2)</div>';
    if (empty($inprogressrows)) {
        echo '<div class="adgds-muted">No rows.</div>';
    } else {
        echo '<table class="adgds-table"><thead><tr><th>cp.id</th><th>courseid</th><th>course</th><th>learningplanid</th><th>classid</th><th>groupid</th></tr></thead><tbody>';
        foreach ($inprogressrows as $r) {
            echo '<tr>';
            echo '<td>' . (int)$r->id . '</td>';
            echo '<td>' . (int)$r->courseid . '</td>';
            echo '<td>' . adgds_h($r->fullname) . '</td>';
            echo '<td>' . (int)$r->learningplanid . '</td>';
            echo '<td>' . (int)$r->classid . '</td>';
            echo '<td>' . (int)$r->groupid . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';

    echo '<div class="adgds-card"><div class="adgds-title">Pending rows (status 0/1) grouped by course</div>';
    if (empty($pendingbycourse)) {
        echo '<div class="adgds-muted">No rows.</div>';
    } else {
        echo '<table class="adgds-table"><thead><tr><th>courseid</th><th>course</th><th>max cp.status</th><th>Included?</th><th>Reason</th></tr></thead><tbody>';
        foreach ($pendingbycourse as $cid => $pc) {
            $included = empty($pendingexcluded[(int)$cid]);
            echo '<tr>';
            echo '<td>' . (int)$cid . '</td>';
            echo '<td>' . adgds_h($pc['fullname']) . '</td>';
            echo '<td>' . (int)$pc['cpstatus'] . '</td>';
            echo '<td>' . ($included ? adgds_badge('YES', 'ok') : adgds_badge('NO', 'no')) . '</td>';
            $reason = isset($pendingexcluded[(int)$cid]['exclude_reason']) ? $pendingexcluded[(int)$cid]['exclude_reason'] : '-';
            echo '<td>' . adgds_h($reason) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';

    $linktopage = new moodle_url('/local/grupomakro_core/pages/academic_demand_gaps.php', array(
        'tab' => 'gaps',
        'search' => $filtersearch,
        'planid' => $filterplanid,
        'shift' => $filtershift,
        'min' => $filtermin,
    ));
    $linkbyfullname = new moodle_url('/local/grupomakro_core/pages/academic_demand_gaps.php', array(
        'tab' => 'gaps',
        'search' => $fullname,
        'planid' => $filterplanid,
        'shift' => $filtershift,
        'min' => $filtermin,
    ));

    echo '<div class="adgds-card">';
    echo '<a class="btn btn-primary" href="' . $linktopage->out(false) . '">Open academic_demand_gaps with current filters</a> ';
    echo '<a class="btn btn-secondary" href="' . $linkbyfullname->out(false) . '">Open page using student full name in search</a>';
    echo '</div>';
}
?>
</div>
<?php
echo $OUTPUT->footer();
