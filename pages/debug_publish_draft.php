<?php
/**
 * Debug page to inspect and repair draft_schedules used by publish flow.
 *
 * @package local_grupomakro_core
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_publish_draft.php'));
$PAGE->set_context($context);
$PAGE->set_title('Debug Draft Publish');
$PAGE->set_heading('Debug Draft Publish');

$periodid = optional_param('periodid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHAEXT);
$limit = optional_param('limit', 200, PARAM_INT);
if ($limit < 20) {
    $limit = 20;
}
if ($limit > 2000) {
    $limit = 2000;
}

/**
 * Normalize string tokens for strict comparisons.
 *
 * @param string $value
 * @return string
 */
function gmk_dbg_pub_norm_token(string $value): string {
    $value = trim(core_text::strtolower($value));
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii !== false && is_string($ascii)) {
        $value = $ascii;
    }
    $value = preg_replace('/\s+/', ' ', $value);
    if ($value === null) {
        return '';
    }
    return trim($value);
}

/**
 * Parse a generic payload boolean.
 *
 * @param mixed $value
 * @return bool
 */
function gmk_dbg_pub_bool($value): bool {
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return ((int)$value) === 1;
    }
    if (is_string($value)) {
        $norm = gmk_dbg_pub_norm_token($value);
        return in_array($norm, ['1', 'true', 'yes', 'y', 'si', 'on'], true);
    }
    return false;
}

/**
 * Whether draft item is external to selected period.
 *
 * @param array $item
 * @param int $periodid
 * @return bool
 */
function gmk_dbg_pub_is_external(array $item, int $periodid): bool {
    if (array_key_exists('isExternal', $item) && gmk_dbg_pub_bool($item['isExternal'])) {
        return true;
    }
    $itemperiod = (int)($item['periodid'] ?? 0);
    if ($itemperiod > 0 && $itemperiod !== $periodid) {
        return true;
    }
    return false;
}

/**
 * Whether draft item is considered programmed for publish.
 *
 * @param array $item
 * @return bool
 */
function gmk_dbg_pub_is_programmed(array $item): bool {
    $isvalidday = static function($day): bool {
        $d = gmk_dbg_pub_norm_token((string)$day);
        return !in_array($d, ['', 'n/a', 'na', 'n-a', 'sin asignar', 'sinasignar'], true);
    };
    $isvalidtime = static function($time): bool {
        $t = trim((string)$time);
        if ($t === '' || $t === '00:00' || $t === '00:00:00') {
            return false;
        }
        return true;
    };

    if (!empty($item['sessions']) && is_array($item['sessions'])) {
        foreach ($item['sessions'] as $sess) {
            if (!is_array($sess)) {
                continue;
            }
            if ($isvalidday($sess['day'] ?? '') && $isvalidtime($sess['start'] ?? '') && $isvalidtime($sess['end'] ?? '')) {
                return true;
            }
        }
    }

    return $isvalidday($item['day'] ?? '') && $isvalidtime($item['start'] ?? '') && $isvalidtime($item['end'] ?? '');
}

/**
 * Return timing signature used by publish dedupe logic.
 *
 * @param array $item
 * @return string
 */
function gmk_dbg_pub_timing_key(array $item): string {
    $parts = [];
    if (!empty($item['sessions']) && is_array($item['sessions'])) {
        foreach ($item['sessions'] as $sess) {
            if (!is_array($sess)) {
                continue;
            }
            $day = gmk_dbg_pub_norm_token((string)($sess['day'] ?? ''));
            $start = trim((string)($sess['start'] ?? ''));
            $end = trim((string)($sess['end'] ?? ''));
            $room = '';
            if (array_key_exists('classroomid', $sess)) {
                $room = gmk_dbg_pub_norm_token((string)$sess['classroomid']);
            } else if (array_key_exists('room', $sess)) {
                $room = gmk_dbg_pub_norm_token((string)$sess['room']);
            }
            $parts[] = $day . '|' . $start . '|' . $end . '|' . $room;
        }
        sort($parts, SORT_STRING);
    } else {
        $day = gmk_dbg_pub_norm_token((string)($item['day'] ?? ''));
        $start = trim((string)($item['start'] ?? ''));
        $end = trim((string)($item['end'] ?? ''));
        $room = gmk_dbg_pub_norm_token((string)($item['room'] ?? ''));
        $parts[] = $day . '|' . $start . '|' . $end . '|' . $room;
    }
    return implode(';', $parts);
}

/**
 * Identity key compatible with scheduler save dedupe key.
 *
 * @param array $item
 * @return string
 */
function gmk_dbg_pub_identity_key(array $item): string {
    $core = (string)($item['corecourseid'] ?? '');
    if ($core === '' || $core === '0') {
        $core = 'subject:' . (string)($item['courseid'] ?? '');
    }

    $shift = gmk_dbg_pub_norm_token((string)($item['shift'] ?? ''));
    $learningplan = (string)($item['learningplanid'] ?? '');
    $career = gmk_dbg_pub_norm_token((string)($item['career'] ?? ''));
    $subperiod = (string)($item['subperiod'] ?? 0);
    $type = (string)($item['type'] ?? 0);
    $instructor = (string)($item['instructorid'] ?? ($item['instructorId'] ?? ''));
    $timing = gmk_dbg_pub_timing_key($item);

    return implode('||', [$core, $shift, $learningplan, $career, $subperiod, $type, $instructor, $timing]);
}

/**
 * Build schedule sessions array from DB rows.
 *
 * @param stdClass $classrec
 * @param array $sessionsbyclassid
 * @return array
 */
function gmk_dbg_pub_db_sessions($classrec, array $sessionsbyclassid): array {
    $out = [];
    $classid = (int)$classrec->id;
    if (!empty($sessionsbyclassid[$classid])) {
        foreach ($sessionsbyclassid[$classid] as $s) {
            $out[] = [
                'day' => (string)$s->day,
                'start' => (string)$s->start_time,
                'end' => (string)$s->end_time,
                'classroomid' => (string)($s->classroomid ?? '')
            ];
        }
        return $out;
    }

    // Fallback for legacy rows without gmk_class_schedules.
    $daymap = ['lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo'];
    $bits = explode('/', (string)($classrec->classdays ?? ''));
    if (!empty($classrec->inittime) && !empty($classrec->endtime)) {
        foreach ($bits as $idx => $bit) {
            if (!isset($daymap[$idx])) {
                continue;
            }
            if ((string)$bit === '1') {
                $out[] = [
                    'day' => $daymap[$idx],
                    'start' => (string)$classrec->inittime,
                    'end' => (string)$classrec->endtime,
                    'classroomid' => ''
                ];
            }
        }
    }
    return $out;
}

/**
 * Dedupe programmed internal draft items by identity key.
 *
 * @param array $items
 * @param int $periodid
 * @param array $stats
 * @return array
 */
function gmk_dbg_pub_dedupe(array $items, int $periodid, array &$stats): array {
    $stats = ['kept' => 0, 'replaced' => 0, 'skipped' => 0];
    $out = [];
    $seen = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $isexternal = gmk_dbg_pub_is_external($item, $periodid);
        $isprogrammed = gmk_dbg_pub_is_programmed($item);
        if ($isexternal || !$isprogrammed) {
            $out[] = $item;
            $stats['kept']++;
            continue;
        }

        $key = gmk_dbg_pub_identity_key($item);
        $hasnumericid = !empty($item['id']) && is_numeric($item['id']) && (int)$item['id'] > 0;
        if (!isset($seen[$key])) {
            $seen[$key] = count($out);
            $out[] = $item;
            $stats['kept']++;
            continue;
        }

        $previdx = $seen[$key];
        $prev = $out[$previdx];
        $prevhasnumericid = !empty($prev['id']) && is_numeric($prev['id']) && (int)$prev['id'] > 0;

        $replace = false;
        if ($hasnumericid && !$prevhasnumericid) {
            $replace = true;
        } else if ($hasnumericid && $prevhasnumericid) {
            $replace = ((int)$item['id']) > ((int)$prev['id']);
        }

        if ($replace) {
            $out[$previdx] = $item;
            $stats['replaced']++;
        } else {
            $stats['skipped']++;
        }
    }

    return array_values($out);
}

/**
 * Save draft array back to period.
 *
 * @param int $periodid
 * @param array $items
 * @return void
 */
function gmk_dbg_pub_save_draft(int $periodid, array $items): void {
    global $DB;
    $json = json_encode(array_values($items));
    $DB->set_field('gmk_academic_periods', 'draft_schedules', $json, ['id' => $periodid]);
}

/**
 * Safe short text.
 *
 * @param mixed $value
 * @param int $max
 * @return string
 */
function gmk_dbg_pub_short($value, int $max = 120): string {
    $txt = trim((string)$value);
    if (core_text::strlen($txt) <= $max) {
        return $txt;
    }
    return core_text::substr($txt, 0, $max) . '...';
}

$message = '';
$messageclass = '';

if ($periodid > 0 && $action !== '' && confirm_sesskey()) {
    $period = $DB->get_record('gmk_academic_periods', ['id' => $periodid], 'id,name,draft_schedules', MUST_EXIST);
    $draftitems = [];
    if (!empty($period->draft_schedules)) {
        $decoded = json_decode($period->draft_schedules, true);
        if (is_array($decoded)) {
            $draftitems = $decoded;
        }
    }

    if ($action === 'drop_external') {
        $before = count($draftitems);
        $draftitems = array_values(array_filter($draftitems, static function($item) use ($periodid) {
            return is_array($item) && !gmk_dbg_pub_is_external($item, $periodid);
        }));
        gmk_dbg_pub_save_draft($periodid, $draftitems);
        $removed = $before - count($draftitems);
        $message = "Action drop_external completed. Removed: {$removed}.";
        $messageclass = 'alert-success';
    } else if ($action === 'drop_unassigned') {
        $before = count($draftitems);
        $draftitems = array_values(array_filter($draftitems, static function($item) use ($periodid) {
            if (!is_array($item)) {
                return false;
            }
            if (gmk_dbg_pub_is_external($item, $periodid)) {
                return true;
            }
            return gmk_dbg_pub_is_programmed($item);
        }));
        gmk_dbg_pub_save_draft($periodid, $draftitems);
        $removed = $before - count($draftitems);
        $message = "Action drop_unassigned completed. Removed: {$removed}.";
        $messageclass = 'alert-success';
    } else if ($action === 'dedupe_programmed') {
        $stats = [];
        $deduped = gmk_dbg_pub_dedupe($draftitems, $periodid, $stats);
        gmk_dbg_pub_save_draft($periodid, $deduped);
        $message = "Action dedupe_programmed completed. kept={$stats['kept']} replaced={$stats['replaced']} skipped={$stats['skipped']}.";
        $messageclass = 'alert-success';
    } else if ($action === 'clear_bad_ids' || $action === 'normalize_publish') {
        $idlist = [];
        foreach ($draftitems as $it) {
            if (!is_array($it)) {
                continue;
            }
            if (!empty($it['id']) && is_numeric($it['id']) && (int)$it['id'] > 0) {
                $idlist[(int)$it['id']] = (int)$it['id'];
            }
        }

        $dbbyid = [];
        if (!empty($idlist)) {
            $dbrows = $DB->get_records_list('gmk_class', 'id', array_values($idlist), '', 'id,periodid,corecourseid,courseid,shift,subperiodid,type');
            foreach ($dbrows as $r) {
                $dbbyid[(int)$r->id] = $r;
            }
        }

        $cleared = 0;
        foreach ($draftitems as &$it) {
            if (!is_array($it)) {
                continue;
            }
            if (empty($it['id']) || !is_numeric($it['id']) || (int)$it['id'] <= 0) {
                continue;
            }
            $id = (int)$it['id'];
            $dbrow = $dbbyid[$id] ?? null;
            if (!$dbrow) {
                unset($it['id']);
                $cleared++;
                continue;
            }
            if ((int)$dbrow->periodid !== $periodid) {
                unset($it['id']);
                $cleared++;
                continue;
            }

            $draftcore = (int)($it['corecourseid'] ?? 0);
            $dbcore = (int)($dbrow->corecourseid ?? 0);
            if ($draftcore > 0 && $dbcore > 0 && $draftcore !== $dbcore) {
                unset($it['id']);
                $cleared++;
                continue;
            }

            $draftshift = gmk_dbg_pub_norm_token((string)($it['shift'] ?? ''));
            $dbshift = gmk_dbg_pub_norm_token((string)($dbrow->shift ?? ''));
            if ($draftshift !== '' && $dbshift !== '' && $draftshift !== $dbshift) {
                unset($it['id']);
                $cleared++;
                continue;
            }

            $draftsubperiod = (int)($it['subperiod'] ?? 0);
            $dbsubperiod = (int)($dbrow->subperiodid ?? 0);
            if ($draftsubperiod > 0 && $dbsubperiod > 0 && $draftsubperiod !== $dbsubperiod) {
                unset($it['id']);
                $cleared++;
                continue;
            }

            $drafttype = (string)($it['type'] ?? '');
            $dbtype = (string)($dbrow->type ?? '');
            if ($drafttype !== '' && $dbtype !== '' && $drafttype !== $dbtype) {
                unset($it['id']);
                $cleared++;
                continue;
            }
        }
        unset($it);

        if ($action === 'normalize_publish') {
            $draftitems = array_values(array_filter($draftitems, static function($item) use ($periodid) {
                return is_array($item) && !gmk_dbg_pub_is_external($item, $periodid);
            }));

            $beforeunassigned = count($draftitems);
            $draftitems = array_values(array_filter($draftitems, static function($item) {
                return is_array($item) && gmk_dbg_pub_is_programmed($item);
            }));
            $droppedunassigned = $beforeunassigned - count($draftitems);

            $dstats = [];
            $draftitems = gmk_dbg_pub_dedupe($draftitems, $periodid, $dstats);

            $coreids = [];
            foreach ($draftitems as $it2) {
                if (!is_array($it2)) {
                    continue;
                }
                $cid = (int)($it2['corecourseid'] ?? 0);
                if ($cid > 0) {
                    $coreids[$cid] = $cid;
                }
            }
            $courses = [];
            if (!empty($coreids)) {
                $courses = $DB->get_records_list('course', 'id', array_values($coreids), '', 'id,fullname');
            }
            $renamed = 0;
            foreach ($draftitems as &$it3) {
                if (!is_array($it3)) {
                    continue;
                }
                $cid = (int)($it3['corecourseid'] ?? 0);
                if ($cid > 0 && !empty($courses[$cid])) {
                    $newname = trim((string)$courses[$cid]->fullname);
                    if ($newname !== '' && (string)($it3['subjectName'] ?? '') !== $newname) {
                        $it3['subjectName'] = $newname;
                        $renamed++;
                    }
                }
            }
            unset($it3);

            gmk_dbg_pub_save_draft($periodid, $draftitems);
            $message = "Action normalize_publish completed. ids_cleared={$cleared} unassigned_dropped={$droppedunassigned} dedupe_replaced={$dstats['replaced']} dedupe_skipped={$dstats['skipped']} names_updated={$renamed}.";
            $messageclass = 'alert-success';
        } else {
            gmk_dbg_pub_save_draft($periodid, $draftitems);
            $message = "Action clear_bad_ids completed. Cleared IDs: {$cleared}.";
            $messageclass = 'alert-success';
        }
    } else if ($action === 'canonical_names') {
        $coreids = [];
        foreach ($draftitems as $it) {
            if (!is_array($it)) {
                continue;
            }
            $cid = (int)($it['corecourseid'] ?? 0);
            if ($cid > 0) {
                $coreids[$cid] = $cid;
            }
        }
        $courses = [];
        if (!empty($coreids)) {
            $courses = $DB->get_records_list('course', 'id', array_values($coreids), '', 'id,fullname');
        }
        $renamed = 0;
        foreach ($draftitems as &$it) {
            if (!is_array($it)) {
                continue;
            }
            $cid = (int)($it['corecourseid'] ?? 0);
            if ($cid > 0 && !empty($courses[$cid])) {
                $newname = trim((string)$courses[$cid]->fullname);
                if ($newname !== '' && (string)($it['subjectName'] ?? '') !== $newname) {
                    $it['subjectName'] = $newname;
                    $renamed++;
                }
            }
        }
        unset($it);
        gmk_dbg_pub_save_draft($periodid, $draftitems);
        $message = "Action canonical_names completed. Updated names: {$renamed}.";
        $messageclass = 'alert-success';
    } else {
        $message = 'Unknown action.';
        $messageclass = 'alert-danger';
    }
}

$periods = $DB->get_records('gmk_academic_periods', [], 'id DESC', 'id,name,startdate,enddate,draft_schedules');

echo $OUTPUT->header();
echo '<style>
.dbg-box { border:1px solid #d6d6d6; border-radius:6px; padding:10px 12px; margin:10px 0; background:#fafafa; }
.dbg-row { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.dbg-mono { font-family: Consolas, monospace; font-size:12px; }
.dbg-grid { width:100%; border-collapse: collapse; font-size:12px; }
.dbg-grid th, .dbg-grid td { border:1px solid #d6d6d6; padding:5px 7px; vertical-align: top; }
.dbg-grid th { background:#f1f5f9; }
.dbg-warn { color:#9a3412; font-weight:600; }
.dbg-ok { color:#166534; font-weight:600; }
.dbg-err { color:#991b1b; font-weight:600; }
.dbg-tag { display:inline-block; border-radius:3px; padding:1px 5px; font-size:11px; border:1px solid #cbd5e1; background:#f8fafc; }
</style>';

echo '<h3>Draft Publish Inspector</h3>';
echo '<div class="dbg-box">';
echo '<form method="get" class="dbg-row">';
echo '<label for="periodid"><strong>Periodo</strong></label>';
echo '<select name="periodid" id="periodid">';
echo '<option value="0">-- select --</option>';
foreach ($periods as $p) {
    $sel = ((int)$p->id === (int)$periodid) ? ' selected' : '';
    $draftlen = !empty($p->draft_schedules) ? strlen($p->draft_schedules) : 0;
    echo '<option value="' . (int)$p->id . '"' . $sel . '>' .
        s($p->name) . ' (id=' . (int)$p->id . ', draft=' . (int)$draftlen . ' chars)</option>';
}
echo '</select>';
echo '<label for="limit">Rows</label>';
echo '<input type="number" name="limit" id="limit" min="20" max="2000" value="' . (int)$limit . '">';
echo '<button type="submit" class="btn btn-primary">Analyze</button>';
echo '</form>';
echo '</div>';

if (!empty($message)) {
    echo '<div class="alert ' . s($messageclass) . '">' . s($message) . '</div>';
}

if ($periodid > 0) {
    $period = $DB->get_record('gmk_academic_periods', ['id' => $periodid], 'id,name,startdate,enddate,draft_schedules', MUST_EXIST);
    $draftjson = (string)($period->draft_schedules ?? '');
    $draftitems = [];
    $jsonok = true;
    $jsonerror = '';
    if ($draftjson !== '') {
        $decoded = json_decode($draftjson, true);
        if (is_array($decoded)) {
            $draftitems = $decoded;
        } else {
            $jsonok = false;
            $jsonerror = json_last_error_msg();
        }
    }

    $total = count($draftitems);
    $programmed = 0;
    $unassigned = 0;
    $external = 0;
    $internal = 0;
    $numericids = 0;
    $idissues = [];
    $dupemap = [];
    $subjectmismatch = [];

    $coreids = [];
    foreach ($draftitems as $idx => $item) {
        if (!is_array($item)) {
            continue;
        }
        $isext = gmk_dbg_pub_is_external($item, $periodid);
        $isprog = gmk_dbg_pub_is_programmed($item);
        if ($isext) {
            $external++;
        } else {
            $internal++;
        }
        if ($isprog) {
            $programmed++;
        } else {
            $unassigned++;
        }

        if (!empty($item['id']) && is_numeric($item['id']) && (int)$item['id'] > 0) {
            $numericids++;
        }

        $coreid = (int)($item['corecourseid'] ?? 0);
        if ($coreid > 0) {
            $coreids[$coreid] = $coreid;
        }

        if (!$isext && $isprog) {
            $key = gmk_dbg_pub_identity_key($item);
            if (!isset($dupemap[$key])) {
                $dupemap[$key] = [];
            }
            $dupemap[$key][] = $idx;
        }
    }

    $coursebyid = [];
    if (!empty($coreids)) {
        $coursebyid = $DB->get_records_list('course', 'id', array_values($coreids), '', 'id,fullname');
    }

    $draftidlist = [];
    foreach ($draftitems as $it) {
        if (is_array($it) && !empty($it['id']) && is_numeric($it['id']) && (int)$it['id'] > 0) {
            $draftidlist[(int)$it['id']] = (int)$it['id'];
        }
    }
    $classbyid = [];
    if (!empty($draftidlist)) {
        $rows = $DB->get_records_list('gmk_class', 'id', array_values($draftidlist), '', 'id,periodid,name,corecourseid,courseid,shift,subperiodid,type');
        foreach ($rows as $r) {
            $classbyid[(int)$r->id] = $r;
        }
    }

    foreach ($draftitems as $idx => $item) {
        if (!is_array($item)) {
            continue;
        }
        $cid = (int)($item['corecourseid'] ?? 0);
        if ($cid > 0 && !empty($coursebyid[$cid])) {
            $canonical = trim((string)$coursebyid[$cid]->fullname);
            $draftname = trim((string)($item['subjectName'] ?? ''));
            if ($canonical !== '' && $draftname !== '' && gmk_dbg_pub_norm_token($canonical) !== gmk_dbg_pub_norm_token($draftname)) {
                $subjectmismatch[] = [
                    'idx' => $idx,
                    'id' => (int)($item['id'] ?? 0),
                    'corecourseid' => $cid,
                    'draftname' => $draftname,
                    'canonical' => $canonical
                ];
            }
        }

        if (empty($item['id']) || !is_numeric($item['id']) || (int)$item['id'] <= 0) {
            continue;
        }
        $id = (int)$item['id'];
        $db = $classbyid[$id] ?? null;
        if (!$db) {
            $idissues[] = ['idx' => $idx, 'id' => $id, 'issue' => 'id_not_found'];
            continue;
        }
        if ((int)$db->periodid !== $periodid) {
            $idissues[] = ['idx' => $idx, 'id' => $id, 'issue' => 'id_from_other_period(' . (int)$db->periodid . ')'];
            continue;
        }
        $draftcore = (int)($item['corecourseid'] ?? 0);
        $dbcore = (int)($db->corecourseid ?? 0);
        if ($draftcore > 0 && $dbcore > 0 && $draftcore !== $dbcore) {
            $idissues[] = ['idx' => $idx, 'id' => $id, 'issue' => 'corecourseid_mismatch'];
            continue;
        }
        $draftshift = gmk_dbg_pub_norm_token((string)($item['shift'] ?? ''));
        $dbshift = gmk_dbg_pub_norm_token((string)($db->shift ?? ''));
        if ($draftshift !== '' && $dbshift !== '' && $draftshift !== $dbshift) {
            $idissues[] = ['idx' => $idx, 'id' => $id, 'issue' => 'shift_mismatch'];
            continue;
        }
    }

    $dupkeys = array_filter($dupemap, static function($idxs) {
        return count($idxs) > 1;
    });

    $dbclasses = $DB->get_records('gmk_class', ['periodid' => $periodid], 'id ASC',
        'id,periodid,name,corecourseid,courseid,learningplanid,shift,subperiodid,type,instructorid,inittime,endtime,classdays');
    $dbclassids = array_values(array_map('intval', array_keys($dbclasses)));
    $dbsessionsrows = [];
    if (!empty($dbclassids)) {
        $dbsessionsrows = $DB->get_records_list('gmk_class_schedules', 'classid', $dbclassids, 'id ASC',
            'id,classid,day,start_time,end_time,classroomid');
    }
    $dbsessionsbyclass = [];
    foreach ($dbsessionsrows as $srow) {
        $dbsessionsbyclass[(int)$srow->classid][] = $srow;
    }

    $draftkeymeta = [];
    $draftkeyset = [];
    foreach ($draftitems as $idx => $item) {
        if (!is_array($item)) {
            continue;
        }
        if (gmk_dbg_pub_is_external($item, $periodid) || !gmk_dbg_pub_is_programmed($item)) {
            continue;
        }
        $key = gmk_dbg_pub_identity_key($item);
        $draftkeyset[$key] = true;
        if (!isset($draftkeymeta[$key])) {
            $draftkeymeta[$key] = [
                'idx' => $idx,
                'subject' => (string)($item['subjectName'] ?? ''),
                'id' => (int)($item['id'] ?? 0)
            ];
        }
    }

    $dbkeymeta = [];
    $dbkeyset = [];
    foreach ($dbclasses as $dbclass) {
        $dbitem = [
            'corecourseid' => (int)($dbclass->corecourseid ?? 0),
            'courseid' => (int)($dbclass->courseid ?? 0),
            'learningplanid' => (int)($dbclass->learningplanid ?? 0),
            'career' => '',
            'shift' => (string)($dbclass->shift ?? ''),
            'subperiod' => (int)($dbclass->subperiodid ?? 0),
            'type' => (int)($dbclass->type ?? 0),
            'instructorid' => (int)($dbclass->instructorid ?? 0),
            'sessions' => gmk_dbg_pub_db_sessions($dbclass, $dbsessionsbyclass),
            'day' => '',
            'start' => '',
            'end' => '',
            'room' => ''
        ];
        $key = gmk_dbg_pub_identity_key($dbitem);
        $dbkeyset[$key] = true;
        if (!isset($dbkeymeta[$key])) {
            $dbkeymeta[$key] = [
                'id' => (int)$dbclass->id,
                'name' => (string)$dbclass->name
            ];
        }
    }

    $draftnotindb = array_diff_key($draftkeyset, $dbkeyset);
    $dbnotindraft = array_diff_key($dbkeyset, $draftkeyset);

    echo '<div class="dbg-box">';
    echo '<div><strong>Period:</strong> ' . s($period->name) . ' (id=' . (int)$period->id . ')</div>';
    echo '<div><strong>Date range:</strong> ' . userdate((int)$period->startdate) . ' - ' . userdate((int)$period->enddate) . '</div>';
    echo '<div><strong>Draft length:</strong> ' . strlen($draftjson) . ' chars</div>';
    if ($jsonok) {
        echo '<div class="dbg-ok">JSON decode: OK</div>';
    } else {
        echo '<div class="dbg-err">JSON decode: FAIL (' . s($jsonerror) . ')</div>';
    }
    echo '</div>';

    echo '<div class="dbg-box">';
    echo '<div class="dbg-row">';
    echo '<span class="dbg-tag">items=' . $total . '</span>';
    echo '<span class="dbg-tag">internal=' . $internal . '</span>';
    echo '<span class="dbg-tag">external=' . $external . '</span>';
    echo '<span class="dbg-tag">programmed=' . $programmed . '</span>';
    echo '<span class="dbg-tag">unassigned=' . $unassigned . '</span>';
    echo '<span class="dbg-tag">numeric_ids=' . $numericids . '</span>';
    echo '<span class="dbg-tag">id_issues=' . count($idissues) . '</span>';
    echo '<span class="dbg-tag">duplicate_keys=' . count($dupkeys) . '</span>';
    echo '<span class="dbg-tag">subject_mismatch=' . count($subjectmismatch) . '</span>';
    echo '</div>';
    echo '<div class="dbg-row" style="margin-top:8px;">';
    echo '<span class="dbg-tag">draft_programmed_not_in_db=' . count($draftnotindb) . '</span>';
    echo '<span class="dbg-tag">db_not_in_draft_programmed=' . count($dbnotindraft) . '</span>';
    echo '</div>';
    echo '</div>';

    echo '<div class="dbg-box">';
    echo '<strong>Repair actions</strong>';
    echo '<div class="dbg-row" style="margin-top:8px;">';
    $actions = [
        'normalize_publish' => 'Normalize for publish',
        'dedupe_programmed' => 'Dedupe programmed',
        'clear_bad_ids' => 'Clear bad ids',
        'canonical_names' => 'Canonical subject names',
        'drop_external' => 'Drop external items',
        'drop_unassigned' => 'Drop internal unassigned'
    ];
    foreach ($actions as $act => $label) {
        echo '<form method="post" style="display:inline-block;">';
        echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
        echo '<input type="hidden" name="periodid" value="' . (int)$periodid . '">';
        echo '<input type="hidden" name="limit" value="' . (int)$limit . '">';
        echo '<input type="hidden" name="action" value="' . s($act) . '">';
        echo '<button type="submit" class="btn btn-secondary">' . s($label) . '</button>';
        echo '</form>';
    }
    echo '</div>';
    echo '</div>';

    if (!empty($idissues)) {
        echo '<div class="dbg-box">';
        echo '<strong>ID issues (first ' . (int)$limit . ')</strong>';
        echo '<table class="dbg-grid"><thead><tr><th>idx</th><th>id</th><th>issue</th><th>subject</th><th>corecourseid</th><th>shift</th></tr></thead><tbody>';
        $shown = 0;
        foreach ($idissues as $row) {
            if ($shown >= $limit) {
                break;
            }
            $item = $draftitems[(int)$row['idx']] ?? [];
            echo '<tr>';
            echo '<td>' . (int)$row['idx'] . '</td>';
            echo '<td>' . (int)$row['id'] . '</td>';
            echo '<td class="dbg-err">' . s($row['issue']) . '</td>';
            echo '<td>' . s(gmk_dbg_pub_short($item['subjectName'] ?? '', 80)) . '</td>';
            echo '<td>' . (int)($item['corecourseid'] ?? 0) . '</td>';
            echo '<td>' . s((string)($item['shift'] ?? '')) . '</td>';
            echo '</tr>';
            $shown++;
        }
        echo '</tbody></table>';
        echo '</div>';
    }

    if (!empty($dupkeys)) {
        echo '<div class="dbg-box">';
        echo '<strong>Duplicate programmed keys (first ' . (int)$limit . ')</strong>';
        echo '<table class="dbg-grid"><thead><tr><th>hash</th><th>count</th><th>indexes</th><th>sample subject</th></tr></thead><tbody>';
        $shown = 0;
        foreach ($dupkeys as $key => $indexes) {
            if ($shown >= $limit) {
                break;
            }
            $sampleidx = (int)$indexes[0];
            $sample = $draftitems[$sampleidx] ?? [];
            echo '<tr>';
            echo '<td class="dbg-mono">' . s(substr(sha1($key), 0, 12)) . '</td>';
            echo '<td>' . count($indexes) . '</td>';
            echo '<td class="dbg-mono">' . s(implode(', ', $indexes)) . '</td>';
            echo '<td>' . s(gmk_dbg_pub_short($sample['subjectName'] ?? '', 90)) . '</td>';
            echo '</tr>';
            $shown++;
        }
        echo '</tbody></table>';
        echo '</div>';
    }

    if (!empty($subjectmismatch)) {
        echo '<div class="dbg-box">';
        echo '<strong>Subject name mismatches (first ' . (int)$limit . ')</strong>';
        echo '<table class="dbg-grid"><thead><tr><th>idx</th><th>id</th><th>corecourseid</th><th>draft subject</th><th>canonical subject</th></tr></thead><tbody>';
        $shown = 0;
        foreach ($subjectmismatch as $row) {
            if ($shown >= $limit) {
                break;
            }
            echo '<tr>';
            echo '<td>' . (int)$row['idx'] . '</td>';
            echo '<td>' . (int)$row['id'] . '</td>';
            echo '<td>' . (int)$row['corecourseid'] . '</td>';
            echo '<td>' . s(gmk_dbg_pub_short($row['draftname'], 100)) . '</td>';
            echo '<td class="dbg-warn">' . s(gmk_dbg_pub_short($row['canonical'], 100)) . '</td>';
            echo '</tr>';
            $shown++;
        }
        echo '</tbody></table>';
        echo '</div>';
    }

    echo '<div class="dbg-box">';
    echo '<strong>Publish diff preview</strong>';
    echo '<table class="dbg-grid"><thead><tr><th>Type</th><th>Count</th><th>Sample</th></tr></thead><tbody>';
    $samples = [];
    foreach (array_keys($draftnotindb) as $key) {
        $meta = $draftkeymeta[$key] ?? null;
        if (!$meta) {
            continue;
        }
        $samples[] = 'idx=' . (int)$meta['idx'] . ' id=' . (int)$meta['id'] . ' ' . gmk_dbg_pub_short($meta['subject'], 55);
        if (count($samples) >= 5) {
            break;
        }
    }
    echo '<tr><td class="dbg-warn">Draft programmed not in DB</td><td>' . count($draftnotindb) . '</td><td>' . s(implode(' | ', $samples)) . '</td></tr>';

    $samples = [];
    foreach (array_keys($dbnotindraft) as $key) {
        $meta = $dbkeymeta[$key] ?? null;
        if (!$meta) {
            continue;
        }
        $samples[] = 'id=' . (int)$meta['id'] . ' ' . gmk_dbg_pub_short($meta['name'], 55);
        if (count($samples) >= 5) {
            break;
        }
    }
    echo '<tr><td class="dbg-warn">DB classes not in programmed draft</td><td>' . count($dbnotindraft) . '</td><td>' . s(implode(' | ', $samples)) . '</td></tr>';
    echo '</tbody></table>';
    echo '</div>';

    echo '<div class="dbg-box">';
    echo '<strong>Draft rows (first ' . (int)$limit . ')</strong>';
    echo '<table class="dbg-grid"><thead><tr>';
    echo '<th>idx</th><th>id</th><th>subject</th><th>corecourseid</th><th>shift</th><th>day</th><th>sessions</th><th>external</th><th>programmed</th><th>keyhash</th>';
    echo '</tr></thead><tbody>';
    foreach ($draftitems as $idx => $item) {
        if ($idx >= $limit) {
            break;
        }
        if (!is_array($item)) {
            continue;
        }
        $isext = gmk_dbg_pub_is_external($item, $periodid);
        $isprog = gmk_dbg_pub_is_programmed($item);
        $keyhash = substr(sha1(gmk_dbg_pub_identity_key($item)), 0, 10);
        $sesscount = (!empty($item['sessions']) && is_array($item['sessions'])) ? count($item['sessions']) : 0;
        echo '<tr>';
        echo '<td>' . (int)$idx . '</td>';
        echo '<td>' . (int)($item['id'] ?? 0) . '</td>';
        echo '<td>' . s(gmk_dbg_pub_short($item['subjectName'] ?? '', 80)) . '</td>';
        echo '<td>' . (int)($item['corecourseid'] ?? 0) . '</td>';
        echo '<td>' . s((string)($item['shift'] ?? '')) . '</td>';
        echo '<td>' . s((string)($item['day'] ?? '')) . '</td>';
        echo '<td>' . (int)$sesscount . '</td>';
        echo '<td>' . ($isext ? '<span class="dbg-err">YES</span>' : '<span class="dbg-ok">NO</span>') . '</td>';
        echo '<td>' . ($isprog ? '<span class="dbg-ok">YES</span>' : '<span class="dbg-warn">NO</span>') . '</td>';
        echo '<td class="dbg-mono">' . s($keyhash) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</div>';

    echo '<div class="dbg-box">';
    echo '<strong>Raw draft preview</strong>';
    if ($draftjson === '') {
        echo '<div>(empty)</div>';
    } else {
        echo '<pre class="dbg-mono" style="max-height:320px; overflow:auto;">' . s(core_text::substr($draftjson, 0, 5000)) . '</pre>';
        if (core_text::strlen($draftjson) > 5000) {
            echo '<div class="dbg-mono">... truncated (showing first 5000 chars)</div>';
        }
    }
    echo '</div>';
}

echo $OUTPUT->footer();
