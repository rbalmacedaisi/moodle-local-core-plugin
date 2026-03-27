<?php
namespace local_grupomakro_core\external\teacher;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/mod/attendance/locallib.php');
require_once($CFG->dirroot . '/mod/attendance/lib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use stdClass;
use context_course;
use mod_attendance_structure;

class attendance_manager extends external_api {
    /** @var int Lifetime in seconds for QR bridge tokens. */
    private const QR_BRIDGE_TTL = 180;

    /**
     * Build the QR bridge URL.
     *
     * @param int $sessionid
     * @param string $password
     * @param string $token
     * @return string
     */
    private static function build_qr_bridge_url($sessionid, $password = '', $token = '') {
        $params = ['sessid' => (int)$sessionid];
        if ($token !== '') {
            $params['gmkqr'] = $token;
        }
        if ($password !== '') {
            $params['qrpass'] = $password;
        }
        return (new \moodle_url('/local/grupomakro_core/pages/attendance_qr_bridge.php', $params))->out(false);
    }

    /**
     * Render QR HTML that points to the bridge endpoint.
     *
     * @param string $targeturl
     * @param string $password
     * @return string
     */
    private static function render_qr_bridge_html($targeturl, $password = '') {
        global $CFG;

        if (!class_exists('TCPDF2DBarcode')) {
            require_once($CFG->libdir . '/tcpdf/tcpdf_barcodes_2d.php');
        }

        $barcode = new \TCPDF2DBarcode($targeturl, 'QRCODE');
        $image = $barcode->getBarcodePngData(6, 6, [0, 0, 0]);
        $imgsrc = 'data:image/png;base64,' . base64_encode($image);
        $safeurl = s($targeturl);
        $safepass = s((string)$password);

        $html = '<div class="gmk-qr-wrapper" style="text-align:center;">';
        $html .= '<img src="' . $imgsrc . '" alt="QR" style="max-width:280px;width:100%;height:auto;">';
        $html .= '<div style="margin-top:10px;font-size:12px;color:#6b7280;">Escanea este QR para registrar asistencia.</div>';
        if ($safepass !== '') {
            $html .= '<div style="margin-top:6px;font-size:12px;color:#6b7280;">Clave: <strong>' . $safepass . '</strong></div>';
        }
        $html .= '<div style="margin-top:8px;font-size:11px;"><a href="' . $safeurl . '" target="_blank" rel="noopener">Abrir enlace</a></div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Encode data using URL-safe base64 without padding.
     *
     * @param string $value
     * @return string
     */
    private static function base64url_encode($value) {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * Build the signing secret for QR bridge tokens.
     *
     * @param \stdClass $session
     * @return string
     */
    private static function get_qr_bridge_secret($session) {
        global $CFG;

        $secretbase = (string)($CFG->passwordsaltmain ?? '');
        if ($secretbase === '') {
            $secretbase = sha1((string)($CFG->wwwroot ?? ''));
        }

        return hash('sha256', implode('|', [
            $secretbase,
            (string)((int)$session->id),
            (string)((int)$session->attendanceid),
            (string)($session->rotateqrcodesecret ?? ''),
            (string)($session->studentpassword ?? ''),
        ]));
    }

    /**
     * Build a signed QR token for the bridge with a fixed lifetime.
     *
     * @param \stdClass $session
     * @return string
     */
    private static function build_qr_bridge_token($session) {
        $now = time();
        try {
            $nonce = bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            $nonce = substr(md5(uniqid('', true)), 0, 16);
        }

        $payload = [
            'sid' => (int)$session->id,
            'iat' => $now,
            'exp' => $now + self::QR_BRIDGE_TTL,
            'nonce' => $nonce,
        ];
        $encodedpayload = self::base64url_encode(json_encode($payload));
        $signature = hash_hmac('sha256', $encodedpayload, self::get_qr_bridge_secret($session));

        return $encodedpayload . '.' . $signature;
    }

    /**
     * Get attendance sessions for the current class (Group)
     */
    public static function get_sessions($classid) {
        global $DB, $CFG; // $USER not needed yet
        
        // 1. Get Course and Group ID from Class
        $class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);
        $all_atts = [];
        
        // 2. Find the Attendance Activity in this Course
        // First check the class's own local course, then fall back to the core/template course.

        // Priority 0: class.attendancemoduleid (most robust when relation table is incomplete).
        if (empty($all_atts) && !empty($class->attendancemoduleid)) {
            $attcm = $DB->get_record_sql(
                "SELECT cm.id, cm.instance, m.name AS modulename
                   FROM {course_modules} cm
                   JOIN {modules} m ON m.id = cm.module
                  WHERE cm.id = :cmid",
                ['cmid' => (int)$class->attendancemoduleid]
            );
            if ($attcm && $attcm->modulename === 'attendance') {
                $att_record = $DB->get_record('attendance', ['id' => (int)$attcm->instance]);
                if ($att_record) {
                    $all_atts = [$att_record->id => $att_record];
                }
            }
        }

        // Check gmk_bbb_attendance_relation for a direct mapping (most precise)
        if (empty($all_atts)) {
            $mapped_attid = $DB->get_field('gmk_bbb_attendance_relation', 'attendanceid', ['classid' => $classid]);
            if ($mapped_attid) {
                $att_record = $DB->get_record('attendance', ['id' => $mapped_attid]);
                $all_atts = $att_record ? [$att_record->id => $att_record] : [];
            }
        }

        // Relation fallback: resolve attendance via relation.attendancemoduleid when attendanceid is null/zero.
        if (empty($all_atts)) {
            $rel_attcmid = $DB->get_field('gmk_bbb_attendance_relation', 'attendancemoduleid', ['classid' => $classid]);
            if (!empty($rel_attcmid)) {
                $attcm = $DB->get_record_sql(
                    "SELECT cm.id, cm.instance, m.name AS modulename
                       FROM {course_modules} cm
                       JOIN {modules} m ON m.id = cm.module
                      WHERE cm.id = :cmid",
                    ['cmid' => (int)$rel_attcmid]
                );
                if ($attcm && $attcm->modulename === 'attendance') {
                    $att_record = $DB->get_record('attendance', ['id' => (int)$attcm->instance]);
                    if ($att_record) {
                        $all_atts = [$att_record->id => $att_record];
                    }
                }
            }
        }

        // Primary: attendance in the class's own Moodle course
        if (empty($all_atts) && !empty($class->courseid)) {
            $all_atts = $DB->get_records('attendance', ['course' => $class->courseid]);
        }

        // Fallback: attendance in the core/template course
        if (empty($all_atts)) {
             if (!empty($class->corecourseid) && $class->corecourseid != $class->courseid) {
                 $all_atts = $DB->get_records('attendance', ['course' => $class->corecourseid]);
             }
        }

        if (empty($all_atts)) {
             // Debugging: Check if course exists at all
             $course_exists = $DB->record_exists('course', ['id' => $class->courseid]);
             return [
                 'status' => 'error', 
                 'message' => "No se encontró actividad de asistencia en el curso (ID: {$class->courseid}) ni en el curso plantilla (ID: {$class->corecourseid}).",
                 'debug_info' => [
                     'class_id' => $classid,
                     'course_id' => $class->courseid,
                     'core_course_id' => $class->corecourseid
                 ]
             ];
        }

        // Use the first one found (either from main course or core course)
        $att = reset($all_atts);

        // $att = $DB->get_record('attendance', ['course' => $class->courseid], 'id, name, grade', IGNORE_MULTIPLE);
        
        // if (!$att) { ... } // Removed old check

        $cm = get_coursemodule_from_instance('attendance', $att->id, $class->courseid);
        
        // Init Structure
        // $att_structure = new mod_attendance_structure($att, $cm, $class->courseid, \context_module::instance($cm->id));

        // 3. Get Sessions for "Today" (or recent) filtered by Group
        // mod_attendance usually filters by group mode.
        // We want sessions specifically for this $class class (Group).
        
        // Use class/period dates to fetch all relevant sessions
        // Add buffer: 1 month before start, 2 months after end to cover exams or schedule changes
        $start_date = $class->initdate - (30 * 24 * 3600);
        $end_date = !empty($class->enddate) ? $class->enddate + (60 * 24 * 3600) : time() + (365 * 24 * 3600);

        // Using direct SQL for precision given we want specific Group
        $sql = "SELECT s.* 
                FROM {attendance_sessions} s
                WHERE s.attendanceid = :attid
                  AND s.groupid = :groupid
                  AND s.sessdate >= :start
                  AND s.sessdate <= :end
                ORDER BY s.sessdate ASC";
        
        $sessions = $DB->get_records_sql($sql, [
            'attid' => $att->id,
            'groupid' => $class->groupid,
            'start' => $start_date,
            'end' => $end_date 
        ]);

        // Fallback 1: class-scoped relation table (handles inconsistent groupid in legacy/mixed classes).
        if (empty($sessions)) {
            $relrows = $DB->get_records('gmk_bbb_attendance_relation', ['classid' => $classid], '', 'attendancesessionid');
            $sessionids = [];
            foreach ($relrows as $relrow) {
                if (!empty($relrow->attendancesessionid)) {
                    $sessionids[] = (int)$relrow->attendancesessionid;
                }
            }
            $sessionids = array_values(array_unique(array_filter($sessionids)));
            if (!empty($sessionids)) {
                list($sessinsql2, $sessparams2) = $DB->get_in_or_equal($sessionids, SQL_PARAMS_NAMED, 'sidfb');
                $sqlfb = "SELECT s.*
                            FROM {attendance_sessions} s
                           WHERE s.id $sessinsql2
                        ORDER BY s.sessdate ASC";
                $sessions = $DB->get_records_sql($sqlfb, $sessparams2);
            }
        }

        // Fallback 2: attendance sessions by date range only.
        if (empty($sessions)) {
            $sqlfb2 = "SELECT s.*
                         FROM {attendance_sessions} s
                        WHERE s.attendanceid = :attid
                          AND s.sessdate >= :start
                          AND s.sessdate <= :end
                     ORDER BY s.sessdate ASC";
            $sessions = $DB->get_records_sql($sqlfb2, [
                'attid' => $att->id,
                'start' => $start_date,
                'end' => $end_date
            ]);
        }

        // Load BBB mapping by attendance session so all modalities can use the same "Entrar" flow.
        $bbbBySessionId = [];
        $hasguestlogin = false;
        if (!empty($sessions)) {
            $sessionids = array_keys($sessions);
            list($sessinsql, $sessparams) = $DB->get_in_or_equal($sessionids, SQL_PARAMS_NAMED, 'sid');
            $relsql = "SELECT attendancesessionid, bbbmoduleid, bbbid
                         FROM {gmk_bbb_attendance_relation}
                        WHERE classid = :classid
                          AND attendancesessionid $sessinsql";
            $relations = $DB->get_records_sql($relsql, array_merge(['classid' => $classid], $sessparams));

            $cmids = [];
            $instanceids = [];
            foreach ($relations as $rel) {
                if (!empty($rel->bbbmoduleid)) {
                    $cmids[(int)$rel->bbbmoduleid] = (int)$rel->bbbmoduleid;
                }
                if (!empty($rel->bbbid)) {
                    $instanceids[(int)$rel->bbbid] = (int)$rel->bbbid;
                }
            }

            $bbbcols = $DB->get_columns('bigbluebuttonbn');
            $hasbbbguest = isset($bbbcols['guest']);
            $bbbguestselect = $hasbbbguest ? 'COALESCE(b.guest, 0)' : '0';
            $hasguestlogin = file_exists($CFG->dirroot . '/mod/bigbluebuttonbn/guest_login.php');

            $bbbmetaByCmid = [];
            if (!empty($cmids)) {
                list($cminsql, $cmparams) = $DB->get_in_or_equal(array_values($cmids), SQL_PARAMS_NAMED, 'cmid');
                $cmsql = "SELECT cm.id AS cmid, cm.instance, {$bbbguestselect} AS guest
                            FROM {course_modules} cm
                       LEFT JOIN {bigbluebuttonbn} b ON b.id = cm.instance
                           WHERE cm.id $cminsql";
                $rows = $DB->get_records_sql($cmsql, $cmparams);
                foreach ($rows as $r) {
                    $bbbmetaByCmid[(int)$r->cmid] = [
                        'cmid' => (int)$r->cmid,
                        'instance' => (int)$r->instance,
                        'guest' => !empty($r->guest),
                    ];
                }
            }

            $bbbmetaByInstance = [];
            if (!empty($instanceids)) {
                $bbbmodule = $DB->get_record('modules', ['name' => 'bigbluebuttonbn'], 'id', IGNORE_MULTIPLE);
                if ($bbbmodule) {
                    list($bbbinsql, $bbbparams) = $DB->get_in_or_equal(array_values($instanceids), SQL_PARAMS_NAMED, 'bbbid');
                    $instsql = "SELECT cm.id AS cmid, cm.instance, {$bbbguestselect} AS guest
                                  FROM {course_modules} cm
                                  JOIN {bigbluebuttonbn} b ON b.id = cm.instance
                                 WHERE cm.module = :moduleid
                                   AND cm.instance $bbbinsql";
                    $rows = $DB->get_records_sql($instsql, array_merge(['moduleid' => (int)$bbbmodule->id], $bbbparams));
                    foreach ($rows as $r) {
                        $iid = (int)$r->instance;
                        if ($iid > 0 && !isset($bbbmetaByInstance[$iid])) {
                            $bbbmetaByInstance[$iid] = [
                                'cmid' => (int)$r->cmid,
                                'instance' => $iid,
                                'guest' => !empty($r->guest),
                            ];
                        }
                    }
                }
            }

            foreach ($relations as $rel) {
                $sid = (int)$rel->attendancesessionid;
                $meta = null;

                if (!empty($rel->bbbmoduleid)) {
                    $meta = $bbbmetaByCmid[(int)$rel->bbbmoduleid] ?? null;
                }

                if (!$meta && !empty($rel->bbbid)) {
                    $meta = $bbbmetaByInstance[(int)$rel->bbbid] ?? null;
                }

                if ($meta) {
                    $bbbBySessionId[$sid] = $meta;
                }
            }
        }
        
        // Format for frontend
        $result = [];
        foreach ($sessions as $s) {
            $item = new stdClass();
            $item->id = $s->id;
            $item->sessdate = $s->sessdate; // Required for JS comparison
            $item->duration = (int)$s->duration;
            $item->date = userdate($s->sessdate, get_string('strftimedatefullshort', 'langconfig'));
            $item->time = userdate($s->sessdate, '%H:%M') . ' - ' . userdate($s->sessdate + $s->duration, '%H:%M');
            $item->description = $s->description;
            $item->state = ($s->sessdate < time()) ? 'Pasada' : 'Futura';
            
            // Check if passwords exist (for QR)
            $item->has_qr = !empty($s->includeqrcode);

            $item->join_url = '';
            $item->guest_url = '';
            $item->bbb_cmid = 0;
            $bbbmeta = $bbbBySessionId[(int)$s->id] ?? null;
            if ($bbbmeta && !empty($bbbmeta['cmid'])) {
                $item->bbb_cmid = (int)$bbbmeta['cmid'];
                $item->join_url = $CFG->wwwroot . '/mod/bigbluebuttonbn/view.php?id=' . (int)$bbbmeta['cmid'];
                if ($hasguestlogin && !empty($bbbmeta['guest'])) {
                    $item->guest_url = $CFG->wwwroot . '/mod/bigbluebuttonbn/guest_login.php?id=' . (int)$bbbmeta['cmid'];
                }
            }
             
            $result[] = $item;
        }

        return ['status' => 'success', 'sessions' => $result, 'attendance_id' => $att->id, 'instance_name' => $att->name];
    }

    /**
     * Generate/Render QR for a session
     */
    public static function get_qr($sessionid) {
        global $DB;

        try {
            $session = $DB->get_record('attendance_sessions', ['id' => $sessionid], '*', MUST_EXIST);
            $DB->get_record('attendance', ['id' => $session->attendanceid], '*', MUST_EXIST);

            // The custom teacher panel uses a signed bridge token with a fixed
            // 3-minute lifetime so scanning does not depend on mod_attendance's
            // internal rotating password state.
            $bridgetoken = self::build_qr_bridge_token($session);
            $bridgeurl = self::build_qr_bridge_url((int)$session->id, '', $bridgetoken);
            $html = self::render_qr_bridge_html($bridgeurl);

            return [
                'status' => 'success', 
                'html' => $html, 
                'password' => '',
                'rotate' => 1,
                'rotate_interval' => self::QR_BRIDGE_TTL
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => 'Error al generar QR: ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine()
            ];
        }
    }
}
