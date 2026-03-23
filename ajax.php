<?php

define('AJAX_SCRIPT', true);

// Try multiple levels up to find config.php
$config_path = __DIR__ . '/../../config.php';
if (!file_exists($config_path)) {
    $config_path = __DIR__ . '/../../../config.php';
}

require_once($config_path);
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

use local_grupomakro_core\external\teacher\create_express_activity;
use local_grupomakro_core\external\teacher\get_pending_grading;
use local_grupomakro_core\external\teacher\save_grade;
use local_grupomakro_core\external\student\get_student_info;
use local_grupomakro_core\external\student\update_status;
use local_grupomakro_core\external\student\sync_progress;
use local_grupomakro_core\external\teacher\get_dashboard_data;
use PhpOffice\PhpSpreadsheet\IOFactory;


if (!function_exists('gmk_log')) {
    // Defined in locallib.php
}

$action = optional_param('action', '', PARAM_ALPHANUMEXT);
header('Content-Type: application/json'); // Enforce JSON for this AJAX script

// JSON Request Handling (for Axios)
if (empty($action)) {
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $jsonData = json_decode($rawInput, true);
        if ($jsonData && isset($jsonData['action'])) {
            $action = clean_param($jsonData['action'], PARAM_ALPHANUMEXT);
            
            // Extract core fields
            // Extract all root fields for compatibility with required_param/optional_param
            foreach ($jsonData as $key => $value) {
                $_POST[$key] = $_REQUEST[$key] = $value;
            }

            // Flatten 'args' for compatibility with required_param/optional_param
            if (isset($jsonData['args']) && is_array($jsonData['args'])) {
                foreach ($jsonData['args'] as $key => $value) {
                    $_POST[$key] = $_REQUEST[$key] = $value;
                }
            }
        }
    }
}

require_login();
$context = context_system::instance();
$PAGE->set_context($context);

$response = [
    'status' => 'error',
    'message' => 'Invalid action.'
];

if (!function_exists('gmk_forum_manage_context')) {
    /**
     * Resolves and validates forum context for teacher-side forum management.
     *
     * @param int $classid
     * @param int $cmid
     * @return array [$class, $course, $cm, $forum, $coursecontext, $cmcontext]
     * @throws Exception
     */
    function gmk_forum_manage_context(int $classid, int $cmid): array {
        global $DB, $USER;

        $class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);
        $course = get_course((int)$class->corecourseid);
        $cm = get_coursemodule_from_id('forum', $cmid, $course->id, false, MUST_EXIST);
        $forum = $DB->get_record('forum', ['id' => (int)$cm->instance, 'course' => $course->id], '*', MUST_EXIST);

        if (!empty($class->coursesectionid) && (int)$cm->section !== (int)$class->coursesectionid) {
            throw new Exception('El foro no pertenece a la seccion de esta clase.');
        }

        $coursecontext = context_course::instance($course->id);
        $cmcontext = context_module::instance($cm->id);
        $canmanage = is_siteadmin()
            || ((int)$class->instructorid === (int)$USER->id)
            || has_capability('moodle/course:manageactivities', $coursecontext);

        if (!$canmanage) {
            throw new Exception('No tienes permiso para administrar este foro.');
        }

        return [$class, $course, $cm, $forum, $coursecontext, $cmcontext];
    }
}

if (!function_exists('gmk_ajax_normalize_lesson_tag')) {
    /**
     * Normalize lesson/tag values sent from teacher dashboard forms.
     * Converts numeric values like "8" into "Leccion 8".
     */
    function gmk_ajax_normalize_lesson_tag($raw): string {
        $value = trim((string)$raw);
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/\s+/', ' ', $value);

        if (preg_match('/^(\d{1,3})$/', $value, $m)) {
            return 'Leccion ' . $m[1];
        }
        if (preg_match('/^lecci(?:o|\x{00F3})n?\s*[-:]*\s*(\d{1,3})$/iu', $value, $m)) {
            return 'Leccion ' . $m[1];
        }
        return $value;
    }
}

if (!function_exists('gmk_ajax_extract_tags_from_request')) {
    /**
     * Extract tag values from string/array/object payloads and return unique normalized tags.
     */
    function gmk_ajax_extract_tags_from_request($raw): array {
        $queue = [$raw];
        $normalized = [];

        while (!empty($queue)) {
            $current = array_shift($queue);

            if (is_array($current)) {
                foreach ($current as $item) {
                    $queue[] = $item;
                }
                continue;
            }

            if (is_object($current)) {
                $obj = (array)$current;
                if (isset($obj['value'])) {
                    $queue[] = $obj['value'];
                } else if (isset($obj['text'])) {
                    $queue[] = $obj['text'];
                } else if (isset($obj['title'])) {
                    $queue[] = $obj['title'];
                } else if (isset($obj['name'])) {
                    $queue[] = $obj['name'];
                }
                continue;
            }

            if (is_string($current)) {
                $trimmed = trim($current);
                if ($trimmed !== '' && (
                    (substr($trimmed, 0, 1) === '[' && substr($trimmed, -1) === ']') ||
                    (substr($trimmed, 0, 1) === '{' && substr($trimmed, -1) === '}')
                )) {
                    $decoded = json_decode($trimmed, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $queue[] = $decoded;
                        continue;
                    }
                }
                if (strpos($current, ',') !== false) {
                    foreach (explode(',', $current) as $part) {
                        $queue[] = $part;
                    }
                    continue;
                }
            }

            if ($current === null) {
                continue;
            }

            $tag = gmk_ajax_normalize_lesson_tag($current);
            if ($tag !== '') {
                $normalized[$tag] = $tag;
            }
        }

        return array_values($normalized);
    }
}

if (!function_exists('gmk_ajax_make_unique_filename')) {
    /**
     * Ensures filename uniqueness in a target filearea by suffixing " (N)".
     */
    function gmk_ajax_make_unique_filename($fs, int $contextid, string $component, string $filearea, int $itemid, string $filepath, string $filename): string {
        $clean = clean_filename($filename);
        if ($clean === '' || $clean === '.') {
            $clean = 'archivo';
        }

        if (!$fs->file_exists($contextid, $component, $filearea, $itemid, $filepath, $clean)) {
            return $clean;
        }

        $dot = strrpos($clean, '.');
        $base = ($dot === false) ? $clean : substr($clean, 0, $dot);
        $ext = ($dot === false) ? '' : substr($clean, $dot);

        for ($i = 1; $i < 1000; $i++) {
            $candidate = $base . ' (' . $i . ')' . $ext;
            if (!$fs->file_exists($contextid, $component, $filearea, $itemid, $filepath, $candidate)) {
                return $candidate;
            }
        }

        return $base . ' (' . time() . ')' . $ext;
    }
}

// Ensure we don't have any output before header
ob_start();

try {
    switch ($action) {
        case 'local_grupomakro_sync_progress':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/student/sync_progress.php');
            $phase = optional_param('phase', 'init', PARAM_ALPHA);
            $offset = optional_param('offset', 0, PARAM_INT);
            $limit = optional_param('limit', 50, PARAM_INT);
            $response = \local_grupomakro_core\external\student\sync_progress::execute($phase, $offset, $limit);
            break;
        
        case 'local_grupomakro_update_student_status':
            $userid = required_param('userid', PARAM_INT);
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/student/update_status.php');
            $result = \local_grupomakro_core\external\student\update_status::execute($userid);
            $response = [
                'status' => 'success',
                'data' => $result
            ];
            break;

        case 'local_grupomakro_sync_financial_bulk':
            raise_memory_limit(MEMORY_HUGE);
            core_php_time_limit::raise(300);
            
            // This function already handles batching (default 50) and prioritization
            $result = local_grupomakro_sync_financial_status([]); 
            
            $response = [
                'status' => 'success',
                'data' => $result
            ];
            break;

        case 'local_grupomakro_get_pending_grading':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/teacher/get_pending_grading.php');
            $classid = optional_param('classid', 0, PARAM_INT);
            $status = optional_param('status', 'pending', PARAM_ALPHA);
            $result = \local_grupomakro_core\external\teacher\get_pending_grading::execute($USER->id, $classid, $status);
            $response = ['status' => 'success', 'tasks' => $result];
            break;

        case 'local_grupomakro_get_assign_submission_details':
            require_once($CFG->dirroot . '/mod/assign/locallib.php');
            require_once($CFG->dirroot . '/group/lib.php');

            $assignmentid = required_param('assignmentid', PARAM_INT);
            $studentid = required_param('studentid', PARAM_INT);
            $courseid = optional_param('courseid', 0, PARAM_INT);
            $submissionid = optional_param('submissionid', 0, PARAM_INT);

            $assignrecord = $DB->get_record('assign', ['id' => $assignmentid], '*', MUST_EXIST);
            $course = $DB->get_record('course', ['id' => (int)$assignrecord->course], '*', MUST_EXIST);
            $assignmoduleid = (int)$DB->get_field('modules', 'id', ['name' => 'assign'], MUST_EXIST);
            $cmrecords = $DB->get_records(
                'course_modules',
                ['module' => $assignmoduleid, 'instance' => (int)$assignmentid, 'course' => (int)$course->id],
                'id ASC',
                'id,course,section'
            );
            if (empty($cmrecords)) {
                throw new Exception('No se encontro course_module para esta tarea.');
            }

            $cmcontexts = [];
            $usablecmid = 0;
            $usablecontext = null;
            foreach ($cmrecords as $cmrow) {
                $ctx = context_module::instance((int)$cmrow->id, IGNORE_MISSING);
                if (!$ctx) {
                    continue;
                }
                $cmcontexts[] = $ctx;
                if ($usablecmid <= 0 && has_capability('mod/assign:grade', $ctx)) {
                    $usablecmid = (int)$cmrow->id;
                    $usablecontext = $ctx;
                }
            }
            if ($usablecmid <= 0 || !$usablecontext) {
                throw new Exception('No tienes permiso para calificar esta tarea.');
            }

            $cm = get_coursemodule_from_id('assign', $usablecmid, (int)$course->id, false, MUST_EXIST);
            $assign = new \assign($usablecontext, $cm, $course);
            $flatgroupids = [];
            $usergroups = groups_get_user_groups((int)$course->id, (int)$studentid);
            if (is_array($usergroups)) {
                foreach ($usergroups as $groupbucket) {
                    if (!is_array($groupbucket)) {
                        continue;
                    }
                    foreach ($groupbucket as $gid) {
                        $gid = (int)$gid;
                        if ($gid > 0 && !in_array($gid, $flatgroupids, true)) {
                            $flatgroupids[] = $gid;
                        }
                    }
                }
            }

            $requestedsubmission = null;
            $requestedsubmissionvalid = false;
            if ((int)$submissionid > 0) {
                $requestedsubmission = $DB->get_record(
                    'assign_submission',
                    ['id' => (int)$submissionid, 'assignment' => (int)$assignmentid],
                    'id,assignment,userid,groupid,attemptnumber,status,latest,timemodified,timecreated',
                    IGNORE_MISSING
                );
                if ($requestedsubmission) {
                    $requesteduid = (int)$requestedsubmission->userid;
                    $requestedgid = (int)$requestedsubmission->groupid;
                    $requestedsubmissionvalid = (
                        ($requesteduid > 0 && $requesteduid === (int)$studentid) ||
                        ($requestedgid > 0 && in_array($requestedgid, $flatgroupids, true))
                    );
                }
            }

            $usersubmission = null;
            $selectionstrategy = 'none';

            if ($requestedsubmission && $requestedsubmissionvalid) {
                $usersubmission = $requestedsubmission;
                $selectionstrategy = 'requested_submissionid';
            }

            $assignusersubmission = $assign->get_user_submission((int)$studentid, false);
            if (!$usersubmission && $assignusersubmission) {
                $usersubmission = $assignusersubmission;
                $selectionstrategy = 'assign_get_user_submission';
            }

            if (!$usersubmission && !empty($flatgroupids)) {
                list($ginsql, $ginparams) = $DB->get_in_or_equal($flatgroupids, SQL_PARAMS_NAMED, 'gid');
                $groupsub = $DB->get_record_sql(
                    "SELECT id, assignment, userid, groupid, attemptnumber, status, latest, timemodified, timecreated
                       FROM {assign_submission}
                      WHERE assignment = :assignmentid
                        AND groupid {$ginsql}
                   ORDER BY latest DESC, timemodified DESC, id DESC",
                    ['assignmentid' => (int)$assignmentid] + $ginparams,
                    IGNORE_MULTIPLE
                );
                if ($groupsub) {
                    $usersubmission = $groupsub;
                    $selectionstrategy = 'group_submission_fallback';
                }
            }

            $candidateids = [];
            if ($requestedsubmission) {
                $candidateids[] = (int)$requestedsubmission->id;
            }
            if ($assignusersubmission) {
                $candidateids[] = (int)$assignusersubmission->id;
            }
            if ($usersubmission) {
                $candidateids[] = (int)$usersubmission->id;
            }

            $allsubparams = [
                'assignmentid' => (int)$assignmentid,
                'userid' => (int)$studentid,
            ];
            $allsubwhere = 's.assignment = :assignmentid AND s.userid = :userid';
            if (!empty($flatgroupids)) {
                list($allsubgsql, $allsubgparams) = $DB->get_in_or_equal($flatgroupids, SQL_PARAMS_NAMED, 'allgid');
                $allsubwhere = "s.assignment = :assignmentid AND (s.userid = :userid OR s.groupid {$allsubgsql})";
                $allsubparams += $allsubgparams;
            }
            $allsubmissions = $DB->get_records_sql(
                "SELECT s.id
                   FROM {assign_submission} s
                  WHERE {$allsubwhere}
               ORDER BY s.latest DESC, s.timemodified DESC, s.id DESC",
                $allsubparams,
                0,
                30
            );
            foreach ($allsubmissions as $allsub) {
                $candidateids[] = (int)$allsub->id;
            }

            $candidateids = array_values(array_unique(array_filter(array_map('intval', $candidateids))));
            $fs = get_file_storage();

            $collectsubmissionpayload = function(int $subid) use ($DB, $fs, $cmcontexts, $assignmentid): array {
                $row = $DB->get_record(
                    'assign_submission',
                    ['id' => $subid, 'assignment' => (int)$assignmentid],
                    'id,assignment,userid,groupid,attemptnumber,status,latest,timemodified,timecreated',
                    IGNORE_MISSING
                );
                if (!$row) {
                    return [
                        'submissionid' => (int)$subid,
                        'status' => 'missing',
                        'timemodified' => 0,
                        'timecreated' => 0,
                        'submissiontext' => '',
                        'submissiontexthtml' => '',
                        'submissiontextplain' => '',
                        'files' => [],
                        'hascontent' => false,
                        'onlinetextlen' => 0,
                    ];
                }

                $rawtext = '';
                $formattedtext = '';
                $plaintext = '';
                $onlinetextlen = 0;
                $hascontent = false;
                $usedcontextid = !empty($cmcontexts) ? (int)$cmcontexts[0]->id : 0;
                $seenhash = [];
                $files = [];
                $resolvedinlineitemid = (int)$row->id;
                $resolvedinlinefilearea = 'submissions_onlinetext';
                // Moodle core uses "submissions_onlinetext"; keep legacy "onlinetext" fallback.
                $onlinetextfileareas = ['submissions_onlinetext', 'onlinetext'];

                $onlinetextrows = $DB->get_records(
                    'assignsubmission_onlinetext',
                    ['assignment' => (int)$assignmentid, 'submission' => (int)$row->id],
                    'id DESC',
                    'id,onlinetext,onlineformat'
                );
                $onlinetext = !empty($onlinetextrows) ? reset($onlinetextrows) : null;
                if (!empty($onlinetextrows)) {
                    foreach ($onlinetextrows as $candidateot) {
                        if (trim((string)$candidateot->onlinetext) !== '') {
                            $onlinetext = $candidateot;
                            break;
                        }
                    }
                }
                $onlinetextfileitemids = [(int)$row->id];
                $tokenfilenames = [];
                if (!empty($onlinetextrows)) {
                    foreach ($onlinetextrows as $otrow) {
                        if ((int)$otrow->id > 0) {
                            $onlinetextfileitemids[] = (int)$otrow->id;
                        }
                    }
                } else if ($onlinetext && (int)$onlinetext->id > 0) {
                    $onlinetextfileitemids[] = (int)$onlinetext->id;
                }
                $onlinetextfileitemids = array_values(array_unique(array_filter(array_map('intval', $onlinetextfileitemids))));
                if ($onlinetext && trim((string)$onlinetext->onlinetext) !== '') {
                    $tokenmatches = [];
                    if (preg_match_all('/@@PLUGINFILE@@\/([^"\'\s>]+)/', (string)$onlinetext->onlinetext, $tokenmatches)) {
                        foreach ((array)($tokenmatches[1] ?? []) as $tokpath) {
                            $tokpath = trim((string)$tokpath);
                            if ($tokpath === '') {
                                continue;
                            }
                            $basename = basename(rawurldecode($tokpath));
                            if ($basename !== '' && $basename !== '.') {
                                $tokenfilenames[$basename] = $basename;
                            }
                        }
                    }
                }
                $tokenfilenames = array_values($tokenfilenames);

                $addfileentry = function(\stored_file $file, string $source) use (&$files, &$seenhash): void {
                    $hash = (string)$file->get_pathnamehash();
                    if ($hash !== '' && isset($seenhash[$hash])) {
                        return;
                    }
                    if ($hash !== '') {
                        $seenhash[$hash] = true;
                    }
                    $url = moodle_url::make_pluginfile_url(
                        $file->get_contextid(),
                        $file->get_component(),
                        $file->get_filearea(),
                        $file->get_itemid(),
                        $file->get_filepath(),
                        $file->get_filename()
                    );
                    $files[] = [
                        'filename' => $file->get_filename(),
                        'fileurl' => $url->out(false),
                        'mimetype' => $file->get_mimetype(),
                        'filesize' => (int)$file->get_filesize(),
                        'source' => $source,
                    ];
                };

                $resolvedinlinecontextid = $usedcontextid;
                $foundinlinefiles = false;
                foreach ($cmcontexts as $ctxcandidate) {
                    foreach ($onlinetextfileitemids as $candidateitemid) {
                        foreach ($onlinetextfileareas as $candidatefilearea) {
                            $candidatefiles = $fs->get_area_files(
                                (int)$ctxcandidate->id,
                                'assignsubmission_onlinetext',
                                $candidatefilearea,
                                (int)$candidateitemid,
                                'sortorder',
                                false
                            );
                            if (!empty($candidatefiles)) {
                                $foundinlinefiles = true;
                                $resolvedinlineitemid = (int)$candidateitemid;
                                $resolvedinlinecontextid = (int)$ctxcandidate->id;
                                $resolvedinlinefilearea = $candidatefilearea;
                                foreach ($candidatefiles as $candidatefile) {
                                    $addfileentry($candidatefile, 'onlinetext');
                                }
                            }
                        }
                    }
                }

                // Fallback for corrupted references: search files globally by known itemids.
                if (!$foundinlinefiles && !empty($onlinetextfileitemids)) {
                    list($inlineinsql, $inlineinparams) = $DB->get_in_or_equal(
                        $onlinetextfileitemids,
                        SQL_PARAMS_NAMED,
                        'oi'
                    );
                    $globalinline = $DB->get_records_sql(
                        "SELECT f.id, f.contextid, f.itemid
                           FROM {files} f
                          WHERE f.component = 'assignsubmission_onlinetext'
                            AND f.filearea IN ('submissions_onlinetext','onlinetext')
                            AND f.filename <> '.'
                            AND f.itemid {$inlineinsql}
                       ORDER BY f.timemodified DESC, f.id DESC",
                        $inlineinparams,
                        0,
                        100
                    );
                    foreach ($globalinline as $inlinefilerow) {
                        $stored = $fs->get_file_by_id((int)$inlinefilerow->id);
                        if (!$stored) {
                            continue;
                        }
                        $resolvedinlinecontextid = (int)$stored->get_contextid();
                        $resolvedinlineitemid = (int)$stored->get_itemid();
                        $resolvedinlinefilearea = (string)$stored->get_filearea();
                        $addfileentry($stored, 'onlinetext');
                        $foundinlinefiles = true;
                    }
                }

                // Secondary fallback: resolve by token filename when itemid references are broken.
                if (!$foundinlinefiles && !empty($tokenfilenames)) {
                    $timefrom = max(0, ((int)$row->timecreated > 0 ? (int)$row->timecreated : time()) - (60 * DAYSECS));
                    $timeto = ((int)$row->timemodified > 0 ? (int)$row->timemodified : time()) + (60 * DAYSECS);
                    list($nameinsql, $nameinparams) = $DB->get_in_or_equal($tokenfilenames, SQL_PARAMS_NAMED, 'tfn');
                    $filenameparams = [
                        'fuserid' => (int)$row->userid,
                        'timefrom' => (int)$timefrom,
                        'timeto' => (int)$timeto,
                    ] + $nameinparams;
                    $filenamefallbackrows = $DB->get_records_sql(
                        "SELECT f.id
                           FROM {files} f
                          WHERE f.component = 'assignsubmission_onlinetext'
                            AND f.filearea IN ('submissions_onlinetext','onlinetext')
                            AND f.filename {$nameinsql}
                            AND f.filename <> '.'
                            AND f.userid = :fuserid
                            AND f.timemodified BETWEEN :timefrom AND :timeto
                       ORDER BY f.timemodified DESC, f.id DESC",
                        $filenameparams,
                        0,
                        100
                    );
                    foreach ($filenamefallbackrows as $ffrow) {
                        $stored = $fs->get_file_by_id((int)$ffrow->id);
                        if (!$stored) {
                            continue;
                        }
                        $resolvedinlinecontextid = (int)$stored->get_contextid();
                        $resolvedinlineitemid = (int)$stored->get_itemid();
                        $resolvedinlinefilearea = (string)$stored->get_filearea();
                        $addfileentry($stored, 'onlinetext');
                        $foundinlinefiles = true;
                    }
                }

                if ($onlinetext && trim((string)$onlinetext->onlinetext) !== '') {
                    $rawtext = (string)$onlinetext->onlinetext;
                    $onlinetextlen = core_text::strlen(trim(strip_tags($rawtext)));
                    $rewriteitemid = (int)$resolvedinlineitemid;
                    $rewritecontextid = (int)$resolvedinlinecontextid;
                    if ($rewritecontextid <= 0 && !empty($cmcontexts)) {
                        $rewritecontextid = (int)$cmcontexts[0]->id;
                    }
                    $usedcontextid = (int)$rewritecontextid;
                    $rewrittentext = file_rewrite_pluginfile_urls(
                        $rawtext,
                        'pluginfile.php',
                        (int)$rewritecontextid,
                        'assignsubmission_onlinetext',
                        (string)$resolvedinlinefilearea,
                        $rewriteitemid
                    );
                    $formatcontext = context::instance_by_id((int)$rewritecontextid, IGNORE_MISSING);
                    if (!$formatcontext) {
                        $formatcontext = context_system::instance();
                    }
                    $formattedtext = format_text(
                        $rewrittentext,
                        (int)$onlinetext->onlineformat,
                        [
                            'context' => $formatcontext,
                            'overflowdiv' => true,
                            'para' => false,
                        ]
                    );
                    $plaintext = trim(strip_tags($formattedtext));
                    if ($plaintext !== '' || strpos($formattedtext, '<img') !== false) {
                        $hascontent = true;
                    }
                }

                foreach ($cmcontexts as $ctxcandidate) {
                    $submissionfiles = $fs->get_area_files(
                        (int)$ctxcandidate->id,
                        'assignsubmission_file',
                        'submission_files',
                        (int)$row->id,
                        'sortorder',
                        false
                    );
                    foreach ($submissionfiles as $file) {
                        $addfileentry($file, 'submission_file');
                    }
                }

                // Fallback for corrupted submission_file refs outside expected CM context.
                if (empty($files)) {
                    $globalsubfiles = $DB->get_records_sql(
                        "SELECT f.id
                           FROM {files} f
                          WHERE f.component = 'assignsubmission_file'
                            AND f.filearea = 'submission_files'
                            AND f.filename <> '.'
                            AND f.itemid = :itemid
                       ORDER BY f.timemodified DESC, f.id DESC",
                        ['itemid' => (int)$row->id],
                        0,
                        100
                    );
                    foreach ($globalsubfiles as $globalsubfile) {
                        $stored = $fs->get_file_by_id((int)$globalsubfile->id);
                        if (!$stored) {
                            continue;
                        }
                        $addfileentry($stored, 'submission_file');
                    }
                }

                if (!empty($files)) {
                    $hascontent = true;
                }

                return [
                    'submissionid' => (int)$row->id,
                    'status' => (string)$row->status,
                    'timemodified' => (int)$row->timemodified,
                    'timecreated' => (int)$row->timecreated,
                    'submissiontext' => (string)$rawtext,
                    'submissiontexthtml' => (string)$formattedtext,
                    'submissiontextplain' => (string)$plaintext,
                    'files' => $files,
                    'hascontent' => $hascontent,
                    'onlinetextlen' => (int)$onlinetextlen,
                    'contextid' => (int)$usedcontextid,
                    'tokenfilenames' => $tokenfilenames,
                ];
            };

            $payloads = [];
            foreach ($candidateids as $subid) {
                $payloads[(int)$subid] = $collectsubmissionpayload((int)$subid);
            }

            $effectivesubmissionid = $usersubmission ? (int)$usersubmission->id : 0;
            $selectedpayload = null;
            if ($effectivesubmissionid > 0 && isset($payloads[$effectivesubmissionid])) {
                $selectedpayload = $payloads[$effectivesubmissionid];
            }
            if (!$selectedpayload && !empty($candidateids)) {
                $firstcandidateid = (int)$candidateids[0];
                $selectedpayload = $payloads[$firstcandidateid] ?? null;
                if ($selectedpayload && $selectionstrategy === 'none') {
                    $selectionstrategy = 'first_candidate_fallback';
                }
            }
            if ($selectedpayload && !$selectedpayload['hascontent']) {
                foreach ($candidateids as $subid) {
                    $subid = (int)$subid;
                    if (!empty($payloads[$subid]['hascontent'])) {
                        $selectedpayload = $payloads[$subid];
                        $selectionstrategy .= '+content_fallback';
                        break;
                    }
                }
            }
            if (!$selectedpayload) {
                $selectedpayload = [
                    'submissionid' => 0,
                    'status' => 'new',
                    'timemodified' => 0,
                    'timecreated' => 0,
                    'submissiontext' => '',
                    'submissiontexthtml' => '',
                    'submissiontextplain' => '',
                    'files' => [],
                    'hascontent' => false,
                    'onlinetextlen' => 0,
                ];
            }

            $response = [
                'status' => 'success',
                'data' => [
                    'submissionid' => (int)$selectedpayload['submissionid'],
                    'status' => (string)$selectedpayload['status'],
                    'timemodified' => (int)$selectedpayload['timemodified'],
                    'timecreated' => (int)$selectedpayload['timecreated'],
                    'submissiontext' => (string)$selectedpayload['submissiontext'],
                    'submissiontexthtml' => (string)$selectedpayload['submissiontexthtml'],
                    'submissiontextplain' => (string)$selectedpayload['submissiontextplain'],
                    'files' => (array)$selectedpayload['files'],
                    'debug' => [
                        'selectionstrategy' => (string)$selectionstrategy,
                        'requestedsubmissionid' => (int)$submissionid,
                        'requestedsubmissionvalid' => $requestedsubmissionvalid ? 1 : 0,
                        'assignusersubmissionid' => $assignusersubmission ? (int)$assignusersubmission->id : 0,
                        'candidateids' => $candidateids,
                        'cmidschecked' => array_values(array_map('intval', array_keys($cmrecords))),
                        'primarycmid' => (int)$cm->id,
                        'primarycontextid' => (int)$usablecontext->id,
                        'selectedcontextid' => (int)($selectedpayload['contextid'] ?? 0),
                        'tokenfilenames' => (array)($selectedpayload['tokenfilenames'] ?? []),
                    ],
                ],
            ];
            break;

        case 'local_grupomakro_save_grade':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/teacher/save_grade.php');
            $args = required_param('args', PARAM_RAW);
            $data = json_decode($args, true);
            
            if (!$data) {
                throw new moodle_exception('invalidjson');
            }
            
            $result = \local_grupomakro_core\external\teacher\save_grade::execute(
                $data['assignmentid'], 
                $data['studentid'], 
                $data['grade'], 
                isset($data['feedback']) ? $data['feedback'] : ''
            );
            $response = [
                'status' => (isset($result['status']) && $result['status'] === 'error') ? 'error' : 'success',
                'message' => isset($result['message']) ? $result['message'] : '',
                'data' => $result
            ];
            break;

        case 'local_grupomakro_get_quiz_attempt_data':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/teacher/get_quiz_attempt_data.php');
            $attemptid = required_param('attemptid', PARAM_INT);
            $result = \local_grupomakro_core\external\teacher\get_quiz_attempt_data::execute($attemptid);
            $response = ['status' => 'success', 'data' => $result];
            break;

        case 'local_grupomakro_save_quiz_grading':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/teacher/save_quiz_grading.php');
            $args = required_param('args', PARAM_RAW);
            $data = json_decode($args, true);
            if (!$data) throw new moodle_exception('invalidjson');

            $result = \local_grupomakro_core\external\teacher\save_quiz_grading::execute(
                $data['attemptid'],
                $data['slot'],
                $data['mark'],
                isset($data['comment']) ? $data['comment'] : ''
            );
            $response = [
                'status' => (isset($result['status']) && $result['status'] === 'error') ? 'error' : 'success',
                'message' => isset($result['message']) ? $result['message'] : '',
                'data' => $result
            ];
            break;

        case 'local_grupomakro_update_period':
            $userid = required_param('userid', PARAM_INT);
            $planid = required_param('planid', PARAM_INT);
            $periodid = required_param('periodid', PARAM_INT);
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');
            $success = \local_grupomakro_progress_manager::update_student_period($userid, $planid, $periodid);
            if ($success) {
                $response = ['status' => 'success', 'message' => 'Periodo actualizado correctamente.'];
            } else {
                $response = ['status' => 'error', 'message' => 'No se pudo actualizar el periodo.'];
            }
            break;

        case 'local_grupomakro_update_academic_period':
            $userid = required_param('userid', PARAM_INT);
            $planid = required_param('planid', PARAM_INT);
            $academicperiodid = required_param('academicperiodid', PARAM_INT);
            
            $lpUser = $DB->get_record('local_learning_users', ['userid' => $userid, 'learningplanid' => $planid]);
            if ($lpUser) {
                $lpUser->academicperiodid = $academicperiodid;
                $lpUser->timemodified = time();
                if ($DB->update_record('local_learning_users', $lpUser)) {
                    $response = ['status' => 'success', 'message' => 'Periodo Lectivo actualizado correctamente.'];
                } else {
                    $response = ['status' => 'error', 'message' => 'Error al actualizar base de datos.'];
                }
            } else {
                $response = ['status' => 'error', 'message' => 'InscripciÃƒÂ³n no encontrada.'];
            }
            break;

        case 'local_grupomakro_get_all_academic_periods':
            $periods = $DB->get_records('gmk_academic_periods', [], 'startdate DESC', 'id, name, status, startdate, enddate');
            // Format dates for UI
            $data = [];
            foreach ($periods as $p) {
                $p->formatted_start = userdate($p->startdate, get_string('strftimedate', 'langconfig'));
                $p->formatted_end = userdate($p->enddate, get_string('strftimedate', 'langconfig'));
                $data[] = $p;
            }
            $response = ['status' => 'success', 'data' => array_values($data)];
            break;

        case 'local_grupomakro_bulk_update_periods_json':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');
            $data = required_param('data', PARAM_RAW);
            $items = json_decode($data, true);
            
            if (!$items) {
                $response = ['status' => 'error', 'message' => 'Invalid JSON data'];
                break;
            }

            $log = [];
            $successCount = 0;
            $failCount = 0;
            
            // Cache period names to IDs map
            $allPeriods = $DB->get_records('local_learning_periods');
            $periodMap = []; // Name -> ID
            foreach ($allPeriods as $p) {
                $periodMap[strtoupper(trim($p->name))] = $p;
            }
            
            foreach ($items as $row) {
                $idnumber = trim($row['idnumber']);
                $periodName = strtoupper(trim($row['period']));
                
                if (empty($idnumber) || empty($periodName)) continue;
                
                // Find User
                $user = $DB->get_record('user', ['idnumber' => $idnumber, 'deleted' => 0], 'id, firstname, lastname');
                if (!$user) {
                    $log[] = "Error: Usuario con ID $idnumber no encontrado.";
                    $failCount++;
                    continue;
                }
                
                // Find Period
                if (!isset($periodMap[$periodName])) {
                     $log[] = "Error: Periodo '$periodName' no existe.";
                     $failCount++;
                     continue;
                }
                $targetPeriod = $periodMap[$periodName];
                
                // Find Learning Plan for User (Assuming active student)
                $lpUser = $DB->get_record('local_learning_users', ['userid' => $user->id, 'userrolename' => 'student']);
                if (!$lpUser) {
                    $log[] = "Error: Usuario $idnumber no estÃƒÂ¡ inscrito en plan de estudio.";
                    $failCount++;
                    continue;
                }
                
                // Check if period belongs to plan? (Optional safety check)
                if ($targetPeriod->learningplanid != $lpUser->learningplanid) {
                     $log[] = "Error: Periodo '$periodName' no pertenece al plan del usuario $idnumber.";
                     $failCount++;
                     continue;
                }
                
                // Update
                if (\local_grupomakro_progress_manager::update_student_period($user->id, $lpUser->learningplanid, $targetPeriod->id)) {
                    $successCount++;
                } else {
                    $log[] = "Aviso: No se requiriÃƒÂ³ cambio para $idnumber.";
                    $successCount++; // Count as handled
                }
            }
            
            $response = [
                'status' => 'success',
                'message' => "Proceso finalizado. Actualizados/Verificados: $successCount. Errores: $failCount.",
                'log' => implode("\n", $log)
            ];
            break;

        case 'local_grupomakro_bulk_update_periods_excel':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');
            
            // Check file upload
            if (empty($_FILES['import_file'])) {
                $response = ['status' => 'error', 'message' => 'No se recibiÃƒÂ³ ningÃƒÂºn archivo.'];
                break;
            }
            
            $file = $_FILES['import_file'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $response = ['status' => 'error', 'message' => 'Error al subir el archivo.'];
                break;
            }

            $tmpFilePath = $file['tmp_name'];
            
            try {
                $spreadsheet = \local_grupomakro_core\local\importer_helper::load_spreadsheet($tmpFilePath);
                $sheet = $spreadsheet->getSheet(0);
                $rows = $sheet->toArray();
                
                if (count($rows) < 2) {
                    $response = ['status' => 'error', 'message' => 'El archivo parece estar vacÃƒÂ­o (o solo tiene cabecera).'];
                    break;
                }
                
                $headers = array_map('trim', array_map('strtolower', $rows[0]));
                $idIdx = -1;
                $bloqueIdx = -1;
                
                // Flexible header search
                foreach ($headers as $idx => $h) {
                    if (strpos($h, 'id number') !== false || strpos($h, 'identificaciÃƒÂ³n') !== false || $h === 'idnumber') $idIdx = $idx;
                    // Look for Bloque, Bimestre, Subperiodo
                    if (strpos($h, 'bloque') !== false || strpos($h, 'bimestre') !== false || strpos($h, 'subperiod') !== false) $bloqueIdx = $idx;
                }
                
                if ($idIdx === -1 || $bloqueIdx === -1) {
                    $response = ['status' => 'error', 'message' => 'No se encontraron las columnas necesarias (ID Number, Bloque).'];
                    break;
                }
                
                $log = [];
                $successCount = 0;
                $failCount = 0;
                
                // Cache Subperiods Map: [PlanID][NormalizedName] => SubperiodObject
                // This is efficient.
                // Join Periods to get PlanID
                $sql = "SELECT sp.id, sp.name, sp.periodid, p.learningplanid
                        FROM {local_learning_subperiods} sp
                        JOIN {local_learning_periods} p ON p.id = sp.periodid";
                $allSubperiods = $DB->get_records_sql($sql);
                
                $subperiodMap = []; // [planid][UPPER(name)] = sp
                foreach ($allSubperiods as $sp) {
                    $nameKey = strtoupper(trim($sp->name));
                    $subperiodMap[$sp->learningplanid][$nameKey] = $sp;
                }
                
                // Start from row 1 (second row)
                for ($i = 1; $i < count($rows); $i++) {
                    $row = $rows[$i];
                    $idnumber = trim($row[$idIdx]);
                    $bloqueName = strtoupper(trim($row[$bloqueIdx] ?? ''));
                    
                    if (empty($idnumber)) continue;
                    if (empty($bloqueName)) {
                         // Maybe clearing bloque? For now skip
                         continue;
                    }
                    
                     // Find User
                    $user = $DB->get_record('user', ['idnumber' => $idnumber, 'deleted' => 0], 'id, firstname, lastname');
                    
                    // Fallback: Check documentnumber profile field
                    if (!$user) {
                        $sql = "SELECT u.id, u.firstname, u.lastname 
                                FROM {user} u
                                JOIN {user_info_data} uid ON uid.userid = u.id
                                JOIN {user_info_field} uif ON uif.id = uid.fieldid
                                WHERE uif.shortname = 'documentnumber' 
                                AND uid.data = :docnum 
                                AND u.deleted = 0";
                        $user = $DB->get_record_sql($sql, ['docnum' => $idnumber]);
                    }

                    if (!$user) {
                        $log[] = "Fila " . ($i+1) . ": Usuario con ID/CÃƒÂ©dula $idnumber no encontrado.";
                        $failCount++;
                        continue;
                    }
                    
                    // Find Learning Plan for User (Assuming active student)
                    $lpUser = $DB->get_record('local_learning_users', ['userid' => $user->id, 'userrolename' => 'student']);
                    if (!$lpUser) {
                        $log[] = "Fila " . ($i+1) . ": Usuario $idnumber no estÃƒÂ¡ inscrito en plan de estudio.";
                        $failCount++;
                        continue;
                    }
                    
                    $planid = $lpUser->learningplanid;
                    
                    // Find Target Subperiod
                    if (!isset($subperiodMap[$planid][$bloqueName])) {
                         $log[] = "Fila " . ($i+1) . ": Bloque '$bloqueName' no existe para el plan del usuario.";
                         $failCount++;
                         continue;
                    }
                    
                    $targetSubperiod = $subperiodMap[$planid][$bloqueName];
                    
                    // Update Subperiod (and Period)
                    // Use new helper method
                    if (\local_grupomakro_progress_manager::update_student_subperiod($user->id, $planid, $targetSubperiod->id)) {
                        $successCount++;
                    } else {
                        // Could be no change or error, assume success/no-op
                        $successCount++;
                    }
                }
                
                $response = [
                    'status' => 'success',
                    'message' => "Proceso finalizado. Filas procesadas: $successCount. Errores: $failCount.",
                    'log' => implode("\n", $log)
                ];

            } catch (Exception $e) {
                $response = ['status' => 'error', 'message' => 'ExcepciÃƒÂ³n procesando archivo: ' . $e->getMessage()];
            }
            break;

        case 'local_grupomakro_get_planning_data':
            $periodid = required_param('periodid', PARAM_INT);
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/planning_manager.php');
            $data = \local_grupomakro_core\local\planning_manager::get_planning_data($periodid);
            $response = ['status' => 'success', 'data' => $data];
            break;

        case 'local_grupomakro_save_planning':
            $academicperiodid = required_param('academicperiodid', PARAM_INT);
            $selections = required_param('selections', PARAM_RAW);
            $deferredGroups = optional_param('deferredGroups', '{}', PARAM_RAW);
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/admin/planning.php');
            $res = \local_grupomakro_core\external\admin\planning::save_planning($academicperiodid, $selections, $deferredGroups);
            $response = ['status' => 'success', 'data' => $res];
            break;

        case 'local_grupomakro_save_period_mappings':
            $baseperiodid = required_param('baseperiodid', PARAM_INT);
            $mappings = required_param('mappings', PARAM_RAW);
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/admin/planning.php');
            $res = \local_grupomakro_core\external\admin\planning::save_period_mappings($baseperiodid, $mappings);
            $response = ['status' => 'success', 'data' => $res];
            break;

        case 'local_grupomakro_get_academic_periods':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/admin/planning.php');
            $periods = \local_grupomakro_core\external\admin\planning::get_periods();
            $response = ['status' => 'success', 'data' => $periods];
            break;

        case 'local_grupomakro_save_academic_period':
            $id = optional_param('id', 0, PARAM_INT);
            $name = required_param('name', PARAM_TEXT);
            $startdate = required_param('startdate', PARAM_INT);
            $enddate = required_param('enddate', PARAM_INT);
            $status = optional_param('status', 1, PARAM_INT);
            $learningplans = optional_param('learningplans', '', PARAM_RAW); // Expecting JSON array string
            $detailsParam = optional_param('details', '', PARAM_RAW); // Expecting JSON object
            
            $lpArray = json_decode($learningplans, true) ?: [];
            $detailsArray = json_decode($detailsParam, true) ?: [];
            
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/admin/planning.php');
            $resId = \local_grupomakro_core\external\admin\planning::save_period($id, $name, $startdate, $enddate, $status, $lpArray, $detailsArray);
            $response = ['status' => 'success', 'data' => ['id' => $resId]];
            break;

        case 'local_grupomakro_delete_academic_period':
            $id = required_param('id', PARAM_INT);
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/admin/planning.php');
            \local_grupomakro_core\external\admin\planning::delete_period($id);
            $response = ['status' => 'success', 'data' => true];
            break;

        case 'local_grupomakro_get_periods':
            $planid = optional_param('planid', 0, PARAM_INT);
            if ($planid > 0) {
                $periods = $DB->get_records('local_learning_periods', ['learningplanid' => $planid], 'id ASC', 'id, name');
            } else {
                $periods = $DB->get_records('local_learning_periods', [], 'name ASC', 'id, name');
            }
            $response = ['status' => 'success', 'periods' => array_values($periods)];
            break;

        case 'local_grupomakro_odoo_status_sync':
            $userIdOrVat = optional_param('userid', null, PARAM_INT);
            if (!$userIdOrVat) {
                $userIdOrVat = required_param('document_number', PARAM_RAW);
            }
            $action = required_param('action', PARAM_ALPHA);
            $reason = optional_param('reason', '', PARAM_TEXT);
            $targetPeriodId = optional_param('target_period_id', null, PARAM_INT);

            require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');
            $res = \local_grupomakro_progress_manager::update_external_status($userIdOrVat, $action, $reason, $targetPeriodId);
            $response = $res;
            break;

        case 'local_grupomakro_get_all_learning_plans':
            $plans = $DB->get_records('local_learning_plans', [], 'name ASC', 'id, name');
            $response = ['status' => 'success', 'data' => array_values($plans)];
            break;

        case 'local_grupomakro_get_plan_subperiods':
            $planid = required_param('planid', PARAM_INT);
            $sql = "SELECT sp.id, sp.name, sp.periodid, p.name as periodname
                    FROM {local_learning_subperiods} sp
                    JOIN {local_learning_periods} p ON p.id = sp.periodid
                    WHERE p.learningplanid = :planid
                    ORDER BY p.id ASC, sp.id ASC";
            $subperiods = $DB->get_records_sql($sql, ['planid' => $planid]);
            $response = ['status' => 'success', 'subperiods' => array_values($subperiods)];
            break;

        case 'local_grupomakro_update_subperiod':
            $userid = required_param('userid', PARAM_INT);
            $planid = required_param('planid', PARAM_INT);
            $subperiodid = required_param('subperiodid', PARAM_INT);
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');
            
            $errorMsg = '';
            $success = \local_grupomakro_progress_manager::update_student_subperiod($userid, $planid, $subperiodid, null, $errorMsg);
            if ($success) {
                $response = ['status' => 'success', 'message' => 'Bloque actualizado correctamente.'];
            } else {
                $response = ['status' => 'error', 'message' => 'No se pudo actualizar el bloque: ' . $errorMsg];
            }
            break;

        case 'local_grupomakro_get_plans':
            $plans = $DB->get_records('local_learning_plans', [], 'name ASC', 'id, name');
            $response = ['status' => 'success', 'plans' => array_values($plans)];
            break;
        
        case 'local_grupomakro_import_grade_chunk':
            require_once($CFG->libdir . '/gradelib.php');
            raise_memory_limit(MEMORY_HUGE);
            set_time_limit(300);

            $tmpfilename = required_param('filename', PARAM_FILE);
            $offset = required_param('offset', PARAM_INT);
            $limit = required_param('limit', PARAM_INT);
            
            $filepath = make_temp_directory('grupomakro_imports') . '/' . $tmpfilename;
            if (!file_exists($filepath)) {
                throw new Exception("Archivo temporal no encontrado ($tmpfilename).");
            }
            
            $jsonfilepath = $filepath . '.json';
            $dataRows = [];
            
            if (!file_exists($jsonfilepath)) {
                // First time: Load Excel and cache as JSON for performance
                $spreadsheet = \local_grupomakro_core\local\importer_helper::load_spreadsheet($filepath);
                $sheet = $spreadsheet->getSheet(0);
                $highestRow = $sheet->getHighestDataRow();
                
                for ($row = 2; $row <= $highestRow; $row++) {
                    $rowData = [
                        'row'      => $row,
                        'username' => strtolower(trim($sheet->getCellByColumnAndRow(1, $row)->getValue())),
                        'planName' => trim($sheet->getCellByColumnAndRow(2, $row)->getValue()),
                        'course'   => trim($sheet->getCellByColumnAndRow(3, $row)->getValue()),
                        'grade'    => floatval($sheet->getCellByColumnAndRow(4, $row)->getValue()),
                        'feedback' => trim($sheet->getCellByColumnAndRow(5, $row)->getValue())
                    ];
                    if (!empty($rowData['username']) && !empty($rowData['planName'])) {
                        $dataRows[] = $rowData;
                    }
                }
                file_put_contents($jsonfilepath, json_encode($dataRows));
            } else {
                // Subsequent calls: Read from faster JSON cache
                $dataRows = json_decode(file_get_contents($jsonfilepath), true);
            }

            $totalCount = count($dataRows);
            $chunk = array_slice($dataRows, $offset, $limit);
            
            $results = [];
            $toSyncPeriods = [];
            
            $rowLogFile = make_temp_directory('grupomakro_imports') . '/last_import_rows.log';
            file_put_contents($rowLogFile, "--- Procesando Chunk: Offset $offset, Limit $limit ---\n", FILE_APPEND);

            foreach ($chunk as $rowItem) {
                 $username      = $rowItem['username'];
                 $planName      = $rowItem['planName'];
                 $courseShort   = $rowItem['course'];
                 $gradeVal      = $rowItem['grade'];
                 $feedback      = $rowItem['feedback'];
                 $rowIndex      = $rowItem['row'];

                 if (empty($username) || empty($planName)) continue;

                 file_put_contents($rowLogFile, "[ROW $rowIndex] User: $username, Plan: $planName, Course: $courseShort\n", FILE_APPEND);

                 $res = [
                     'row' => $rowIndex,
                     'username' => $username,
                     'course' => $courseShort,
                     'status' => 'OK',
                     'error' => ''
                 ];

                 try {
                    // 1. Enroll
                    $enrollResult = \local_grupomakro_core\external\odoo\enroll_student::execute($planName, $username);
                    
                    // 2. Resolve Course
                    $acc_course = $DB->get_record('course', ['shortname' => $courseShort]);
                    if (!$acc_course) throw new Exception("Curso '$courseShort' no existe");

                    if (empty($feedback)) $feedback = 'Nota migrada de Q10';

                    // 3. Update Grade
                    $grade_item = \grade_item::fetch(array('courseid' => $acc_course->id, 'itemtype' => 'manual', 'itemname' => 'Nota Final Integrada'));
                    if (!$grade_item) {
                         $grade_item = new \grade_item(array('courseid' => $acc_course->id, 'itemtype' => 'manual', 'itemname' => 'Nota Final Integrada', 'grademin'=>0, 'grademax'=>100));
                         $grade_item->insert('manual');
                    }

                    $lookupUsername = \core_text::strtolower($username);
                    $user = $DB->get_record('user', ['username' => $lookupUsername, 'deleted' => 0], 'id');
                    if (!$user) throw new Exception("Usuario '$username' (mapeado a $lookupUsername) no encontrado");
                    
                    $grade_item->update_final_grade($user->id, $gradeVal, 'import', $feedback, FORMAT_HTML);
                    
                    // 4. Update Progress
                    \local_grupomakro_progress_manager::update_course_progress($acc_course->id, $user->id);

                    // 5. Track for period sync
                    $userPlanKey = $user->id . '_' . $enrollResult['plan_id'];
                    $toSyncPeriods[$userPlanKey] = ['userid' => $user->id, 'planid' => $enrollResult['plan_id']];

                 } catch (\Throwable $e) {
                     $res['status'] = 'ERROR';
                     $res['error'] = get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
                     file_put_contents($rowLogFile, "[ERROR ROW $rowIndex] " . $res['error'] . "\n", FILE_APPEND);
                 }
                 $results[] = $res;
            }
            
            // Sync periods for this chunk
            foreach ($toSyncPeriods as $syncData) {
                try {
                    \local_grupomakro_progress_manager::sync_student_period($syncData['userid'], $syncData['planid']);
                } catch (\Throwable $e) {
                     file_put_contents($rowLogFile, "[ERROR SYNC User " . $syncData['userid'] . "] " . $e->getMessage() . "\n", FILE_APPEND);
                }
            }
            
            $response = [
                'status' => 'success',
                'results' => $results,
                'progress' => [
                    'offset' => $offset,
                    'processed' => count($results),
                    'total' => $totalCount,
                    'finished' => ($offset + count($results) >= $totalCount)
                ]
            ];
            break;

        case 'local_grupomakro_import_grade_cleanup':
            $tmpfilename = required_param('filename', PARAM_FILE);
            $filepath = make_temp_directory('grupomakro_imports') . '/' . $tmpfilename;
            if (file_exists($filepath)) {
                @unlink($filepath);
            }
            $jsonfilepath = $filepath . '.json';
            if (file_exists($jsonfilepath)) {
                @unlink($jsonfilepath);
            }
            $response = ['status' => 'success'];
            break;

        case 'local_grupomakro_get_teacher_dashboard_data':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/teacher/get_dashboard_data.php');
            $userid = optional_param('userid', $USER->id, PARAM_INT);
            $response = [
                'status' => 'success',
                'data' => \local_grupomakro_core\external\teacher\get_dashboard_data::execute($userid)
            ];
            break;

        case 'local_grupomakro_get_student_learning_plan_pensum':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/student/get_student_learning_plan_pensum.php');
            $userid = required_param('userId', PARAM_INT);
            $learningplanid = required_param('learningPlanId', PARAM_INT);
            
            $result = \local_grupomakro_core\external\student\get_student_learning_plan_pensum::execute($userid, $learningplanid);
            
            $response = [
                'status' => 'success',
                'data' => $result
            ];
            break;

        case 'local_grupomakro_get_active_classes_for_course':
            require_capability('moodle/site:config', $context);
            $userid = required_param('userId', PARAM_INT);
            $corecourseid = required_param('coreCourseId', PARAM_INT);
            $learningcourseid = optional_param('learningCourseId', 0, PARAM_INT);
            $learningplanid = optional_param('learningPlanId', 0, PARAM_INT);

            $now = time();
            $baseWhere = "c.approved = 1 AND c.closed = 0 AND c.enddate >= :now";
            $baseParams = ['now' => $now];

            $buildClasses = function($whereSql, $params) use ($DB, $userid) {
                $sql = "SELECT
                            c.id,
                            c.name,
                            c.type,
                            c.typelabel,
                            c.classdays,
                            c.inithourformatted,
                            c.endhourformatted,
                            c.classroomcapacity,
                            c.groupid,
                            c.instructorid,
                            c.initdate,
                            c.enddate,
                            c.corecourseid,
                            c.learningplanid,
                            c.courseid,
                            CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, '')) AS instructorname
                        FROM {gmk_class} c
                        LEFT JOIN {user} u ON u.id = c.instructorid
                        WHERE $whereSql
                        ORDER BY c.initdate ASC, c.inittimets ASC, c.id ASC";
                $rows = $DB->get_records_sql($sql, $params);
                $result = [];
                foreach ($rows as $row) {
                    $enrolled = 0;
                    $alreadyenrolled = false;
                    if (!empty($row->groupid)) {
                        $enrolled = (int)$DB->count_records_select(
                            'groups_members',
                            'groupid = :gid AND userid <> :instructorid',
                            ['gid' => (int)$row->groupid, 'instructorid' => (int)$row->instructorid]
                        );
                        $alreadyenrolled = groups_is_member((int)$row->groupid, $userid);
                    } else {
                        $enrolled = (int)$DB->count_records_select(
                            'gmk_course_progre',
                            'classid = :classid AND userid <> :instructorid',
                            ['classid' => (int)$row->id, 'instructorid' => (int)$row->instructorid]
                        );
                        $alreadyenrolled = $DB->record_exists('gmk_course_progre', ['classid' => (int)$row->id, 'userid' => $userid]);
                    }

                    $result[] = [
                        'id' => (int)$row->id,
                        'name' => $row->name,
                        'type' => (int)$row->type,
                        'typelabel' => !empty($row->typelabel) ? $row->typelabel : ((string)$row->type === '2' ? 'Mixta' : ((string)$row->type === '1' ? 'Virtual' : 'Presencial')),
                        'classdays' => $row->classdays,
                        'inithourformatted' => $row->inithourformatted,
                        'endhourformatted' => $row->endhourformatted,
                        'classroomcapacity' => (int)$row->classroomcapacity,
                        'enrolled' => $enrolled,
                        'alreadyenrolled' => $alreadyenrolled,
                        'instructorname' => trim($row->instructorname),
                        'initdate' => (int)$row->initdate,
                        'enddate' => (int)$row->enddate,
                        'initdateformatted' => !empty($row->initdate) ? userdate($row->initdate, get_string('strftimedate', 'langconfig')) : '',
                        'enddateformatted' => !empty($row->enddate) ? userdate($row->enddate, get_string('strftimedate', 'langconfig')) : '',
                    ];
                }
                return $result;
            };

            // Return all active classes for the subject, regardless of plan.
            // We merge multiple strategies and dedupe by class id.
            $activeclasses = [];
            $activeclassids = [];
            $mergeclasses = function(array $rows) use (&$activeclasses, &$activeclassids) {
                foreach ($rows as $row) {
                    $cid = (int)($row['id'] ?? 0);
                    if ($cid <= 0 || isset($activeclassids[$cid])) {
                        continue;
                    }
                    $activeclassids[$cid] = true;
                    $activeclasses[] = $row;
                }
            };

            // Strategy 1: exact learning-course map (most precise, plan-linked id).
            if ($learningcourseid > 0) {
                $where = $baseWhere . " AND c.courseid = :learningcourseid";
                $params = $baseParams + ['learningcourseid' => $learningcourseid];
                $mergeclasses($buildClasses($where, $params));
            }

            // Strategy 2: any active class for the same core subject (no plan restriction).
            // This is required so the Academic Director modal can show classes across plans.
            $where = $baseWhere . " AND c.corecourseid = :corecourseid";
            $params = $baseParams + ['corecourseid' => $corecourseid];
            $mergeclasses($buildClasses($where, $params));

            // Strategy 3: student's current period in selected plan (kept for legacy data).
            if ($learningplanid > 0) {
                $currentperiodid = (int)$DB->get_field_sql(
                    "SELECT MAX(lu.currentperiodid)
                       FROM {local_learning_users} lu
                      WHERE lu.userid = :userid
                        AND lu.learningplanid = :learningplanid
                        AND (lu.userroleid = :studentrole OR lu.userrolename = :studentrolename)",
                    [
                        'userid' => $userid,
                        'learningplanid' => $learningplanid,
                        'studentrole' => 5,
                        'studentrolename' => 'student',
                    ]
                );
                if ($currentperiodid > 0) {
                    $where = $baseWhere . " AND c.corecourseid = :corecourseid AND c.periodid = :periodid";
                    $params = $baseParams + [
                        'corecourseid' => $corecourseid,
                        'periodid' => $currentperiodid,
                    ];
                    $mergeclasses($buildClasses($where, $params));
                }
            }

            usort($activeclasses, function($a, $b) {
                $ai = (int)($a['initdate'] ?? 0);
                $bi = (int)($b['initdate'] ?? 0);
                if ($ai === $bi) {
                    return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
                }
                return $ai <=> $bi;
            });

            $response = [
                'status' => 'success',
                'classes' => $activeclasses,
            ];
            break;

        case 'local_grupomakro_manual_enroll':
            require_sesskey();
            require_capability('moodle/site:config', $context);
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/schedule/manual_enroll.php');
            $classid = required_param('classId', PARAM_INT);
            $userid = required_param('userId', PARAM_INT);
            $learningplanid = optional_param('learningPlanId', 0, PARAM_INT);
            $result = \local_grupomakro_core\external\schedule\manual_enroll::execute($classid, $userid, $learningplanid);
            $response = [
                'status' => 'success',
                'data' => $result,
            ];
            break;

        case 'local_grupomakro_withdraw_student':
            require_sesskey();
            require_capability('moodle/site:config', $context);
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/schedule/withdraw_student.php');
            $classid = required_param('classId', PARAM_INT);
            $userid  = required_param('userId', PARAM_INT);
            $learningplanid = optional_param('learningPlanId', 0, PARAM_INT);
            $result  = \local_grupomakro_core\external\schedule\withdraw_student::execute($classid, $userid, $learningplanid);
            $response = [
                'status' => 'success',
                'data'   => $result,
            ];
            break;

        case 'local_grupomakro_get_student_course_pensum_activities':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/student/get_student_course_pensum_activities.php');
            $userid = required_param('userId', PARAM_INT);
            $classid = required_param('classId', PARAM_INT);
            
            // We need courseId from classId
            $courseid = $DB->get_field('gmk_class', 'courseid', ['id' => $classid]);
            
            if (!$courseid) {
                $response = ['status' => 'error', 'message' => 'Class not found'];
                break;
            }

            $result = \local_grupomakro_core\external\student\get_student_course_pensum_activities::execute($userid, $courseid);
            
            $response = [
                'status' => 'success',
                'data' => $result
            ];
            break;

        case 'local_grupomakro_get_student_schedule_pdf_data':
            require_sesskey();
            require_capability('moodle/site:config', $context);
            $userid = required_param('userId', PARAM_INT);
            $periodfilter = optional_param('periodId', 0, PARAM_INT);

            $student = $DB->get_record(
                'user',
                ['id' => $userid, 'deleted' => 0],
                'id,firstname,lastname,email,idnumber',
                MUST_EXIST
            );

            $periodtable = '';
            foreach (['gmk_academic_periods', 'gmk_periods', 'local_learning_periods'] as $candidateperiodtable) {
                try {
                    $cols = $DB->get_columns($candidateperiodtable);
                    if (is_array($cols) && isset($cols['id']) && isset($cols['name'])) {
                        $periodtable = $candidateperiodtable;
                        break;
                    }
                } catch (Throwable $periodtableerror) {
                    // Continue with fallback tables.
                }
            }

            $periodnames = [];
            if ($periodtable !== '') {
                $periodrows = $DB->get_records_sql("SELECT id, name FROM {" . $periodtable . "}");
                foreach ($periodrows as $periodrow) {
                    $periodnames[(int)$periodrow->id] = (string)$periodrow->name;
                }
            }

            $params = [
                'userid_group' => $userid,
                'userid_progre' => $userid,
                'userid_queue' => $userid,
                'userid_prereg' => $userid,
            ];
            $whereperiod = '';
            if ($periodfilter > 0) {
                $whereperiod = ' AND c.periodid = :periodfilter';
                $params['periodfilter'] = $periodfilter;
            }

            $classsql = "SELECT
                            c.id,
                            c.name,
                            c.periodid,
                            c.shift,
                            c.type,
                            c.typelabel,
                            c.initdate,
                            c.enddate,
                            c.inithourformatted,
                            c.endhourformatted,
                            c.classdays,
                            c.groupid,
                            c.learningplanid,
                            c.corecourseid,
                            c.classroomid,
                            COALESCE(room.name, 'Sin aula') AS classroomname,
                            CONCAT(COALESCE(tu.firstname, ''), ' ', COALESCE(tu.lastname, '')) AS instructorname,
                            COALESCE(core.fullname, c.name) AS subjectname,
                            COALESCE(lp.name, '') AS learningplanname,
                            MAX(CASE WHEN gm.id IS NOT NULL THEN 1 ELSE 0 END) AS in_group,
                            MAX(CASE WHEN gcp.id IS NOT NULL AND gcp.status = 2 THEN 1 ELSE 0 END) AS in_progre,
                            MAX(CASE WHEN cq.id IS NOT NULL THEN 1 ELSE 0 END) AS in_queue,
                            MAX(CASE WHEN cpr.id IS NOT NULL THEN 1 ELSE 0 END) AS in_prereg
                        FROM {gmk_class} c
                        LEFT JOIN {groups_members} gm ON gm.groupid = c.groupid AND gm.userid = :userid_group
                        LEFT JOIN {gmk_course_progre} gcp ON gcp.classid = c.id AND gcp.userid = :userid_progre
                        LEFT JOIN {gmk_class_queue} cq ON cq.classid = c.id AND cq.userid = :userid_queue
                        LEFT JOIN {gmk_class_pre_registration} cpr ON cpr.classid = c.id AND cpr.userid = :userid_prereg
                        LEFT JOIN {user} tu ON tu.id = c.instructorid
                        LEFT JOIN {gmk_classrooms} room ON room.id = c.classroomid
                        LEFT JOIN {course} core ON core.id = c.corecourseid
                        LEFT JOIN {local_learning_plans} lp ON lp.id = c.learningplanid
                        WHERE c.closed = 0
                          AND (
                            gm.id IS NOT NULL
                            OR (gcp.id IS NOT NULL AND gcp.status = 2)
                            OR cq.id IS NOT NULL
                            OR cpr.id IS NOT NULL
                          )
                          {$whereperiod}
                        GROUP BY
                            c.id, c.name, c.periodid, c.shift, c.type, c.typelabel, c.initdate, c.enddate,
                            c.inithourformatted, c.endhourformatted, c.classdays, c.groupid, c.learningplanid,
                            c.corecourseid, c.classroomid, room.name, tu.firstname, tu.lastname, core.fullname, lp.name
                        ORDER BY c.periodid DESC, c.initdate ASC, c.inittimets ASC, c.id ASC";

            $classrows = $DB->get_records_sql($classsql, $params);
            $classids = array_map(static function($row) {
                return (int)$row->id;
            }, array_values($classrows));

            $schedulemap = [];
            $schedulestructmap = [];
            if (!empty($classids)) {
                list($classinsql, $classinparams) = $DB->get_in_or_equal($classids, SQL_PARAMS_NAMED, 'cid');
                $schedulerows = $DB->get_records_sql(
                    "SELECT id, classid, day, start_time, end_time
                       FROM {gmk_class_schedules}
                      WHERE classid {$classinsql}
                   ORDER BY classid ASC, id ASC",
                    $classinparams
                );

                $daymeta = [
                    'lunes' => ['label' => 'Lunes', 'index' => 1],
                    'monday' => ['label' => 'Lunes', 'index' => 1],
                    'martes' => ['label' => 'Martes', 'index' => 2],
                    'tuesday' => ['label' => 'Martes', 'index' => 2],
                    'miercoles' => ['label' => 'Miercoles', 'index' => 3],
                    'wednesday' => ['label' => 'Miercoles', 'index' => 3],
                    'jueves' => ['label' => 'Jueves', 'index' => 4],
                    'thursday' => ['label' => 'Jueves', 'index' => 4],
                    'viernes' => ['label' => 'Viernes', 'index' => 5],
                    'friday' => ['label' => 'Viernes', 'index' => 5],
                    'sabado' => ['label' => 'Sabado', 'index' => 6],
                    'saturday' => ['label' => 'Sabado', 'index' => 6],
                    'domingo' => ['label' => 'Domingo', 'index' => 7],
                    'sunday' => ['label' => 'Domingo', 'index' => 7],
                ];

                $normalizetime = static function($value) {
                    $value = trim((string)$value);
                    if ($value === '') {
                        return '--';
                    }
                    if (preg_match('/^(\d{1,2}):(\d{2})/', $value, $matches)) {
                        return sprintf('%02d:%02d', (int)$matches[1], (int)$matches[2]);
                    }
                    return $value;
                };

                foreach ($schedulerows as $schedulerow) {
                    $classidkey = (int)$schedulerow->classid;
                    if (!isset($schedulemap[$classidkey])) {
                        $schedulemap[$classidkey] = [];
                    }
                    if (!isset($schedulestructmap[$classidkey])) {
                        $schedulestructmap[$classidkey] = [];
                    }
                    $daytoken = cleanString((string)$schedulerow->day);
                    $meta = $daymeta[$daytoken] ?? ['label' => ((string)$schedulerow->day ?: 'Dia'), 'index' => 0];
                    $daylabel = (string)$meta['label'];
                    $dayindex = (int)$meta['index'];
                    $start = $normalizetime($schedulerow->start_time);
                    $end = $normalizetime($schedulerow->end_time);
                    $piece = trim($daylabel . ' ' . $start . '-' . $end);
                    if ($piece !== '' && !in_array($piece, $schedulemap[$classidkey], true)) {
                        $schedulemap[$classidkey][] = $piece;
                    }
                    $alreadyexists = false;
                    foreach ($schedulestructmap[$classidkey] as $existingentry) {
                        if (
                            ((int)$existingentry['dayindex']) === $dayindex &&
                            ((string)$existingentry['start']) === $start &&
                            ((string)$existingentry['end']) === $end
                        ) {
                            $alreadyexists = true;
                            break;
                        }
                    }
                    if (!$alreadyexists) {
                        $schedulestructmap[$classidkey][] = [
                            'day' => $daylabel,
                            'dayindex' => $dayindex,
                            'start' => $start,
                            'end' => $end,
                        ];
                    }
                }
            }

            $bitdaylabels = ['Dom', 'Lun', 'Mar', 'Mie', 'Jue', 'Vie', 'Sab'];
            $types = ['Presencial', 'Virtual', 'Mixta'];
            $outputclasses = [];

            foreach ($classrows as $classrow) {
                $cid = (int)$classrow->id;
                $schedulepieces = $schedulemap[$cid] ?? [];
                $schedulestruct = $schedulestructmap[$cid] ?? [];
                if (empty($schedulepieces) && !empty($classrow->classdays)) {
                    $parts = explode('/', (string)$classrow->classdays);
                    foreach ($parts as $idx => $flag) {
                        if ((string)$flag === '1' && isset($bitdaylabels[$idx])) {
                            $startfallback = $classrow->inithourformatted ?: '--';
                            $endfallback = $classrow->endhourformatted ?: '--';
                            $schedulepieces[] = $bitdaylabels[$idx] . ' ' . $startfallback . '-' . $endfallback;
                            $schedulestruct[] = [
                                'day' => $bitdaylabels[$idx],
                                'dayindex' => ($idx === 0 ? 7 : $idx),
                                'start' => $startfallback,
                                'end' => $endfallback,
                            ];
                        }
                    }
                }
                if (empty($schedulepieces) && (!empty($classrow->inithourformatted) || !empty($classrow->endhourformatted))) {
                    $schedulepieces[] = ($classrow->inithourformatted ?: '--') . '-' . ($classrow->endhourformatted ?: '--');
                }

                $statuslabel = 'Relacionado';
                if (!empty($classrow->in_group) || !empty($classrow->in_progre)) {
                    $statuslabel = 'Inscrito';
                } else if (!empty($classrow->in_queue)) {
                    $statuslabel = 'Pendiente';
                } else if (!empty($classrow->in_prereg)) {
                    $statuslabel = 'Pre-registrado';
                }

                $typelabel = trim((string)$classrow->typelabel);
                if ($typelabel === '') {
                    $typeidx = (int)$classrow->type;
                    $typelabel = $types[$typeidx] ?? 'Presencial';
                }

                $outputclasses[] = [
                    'id' => $cid,
                    'name' => (string)$classrow->name,
                    'subjectname' => (string)$classrow->subjectname,
                    'periodid' => (int)$classrow->periodid,
                    'periodname' => (string)($periodnames[(int)$classrow->periodid] ?? ''),
                    'shift' => (string)$classrow->shift,
                    'typelabel' => $typelabel,
                    'classroomname' => (string)$classrow->classroomname,
                    'instructorname' => trim((string)$classrow->instructorname),
                    'learningplanname' => (string)$classrow->learningplanname,
                    'initdate' => (int)$classrow->initdate,
                    'enddate' => (int)$classrow->enddate,
                    'initdateformatted' => !empty($classrow->initdate) ? userdate((int)$classrow->initdate, get_string('strftimedate', 'langconfig')) : '',
                    'enddateformatted' => !empty($classrow->enddate) ? userdate((int)$classrow->enddate, get_string('strftimedate', 'langconfig')) : '',
                    'schedulepieces' => array_values($schedulepieces),
                    'schedules' => array_values($schedulestruct),
                    'schedulelabel' => implode(' | ', $schedulepieces),
                    'enrollmentstatus' => $statuslabel,
                ];
            }

            $response = [
                'status' => 'success',
                'student' => [
                    'id' => (int)$student->id,
                    'name' => trim((string)$student->firstname . ' ' . (string)$student->lastname),
                    'email' => (string)$student->email,
                    'idnumber' => (string)$student->idnumber,
                ],
                'classes' => $outputclasses,
                'generatedat' => userdate(time(), '%Y-%m-%d %H:%M'),
            ];
            break;

        case 'local_grupomakro_get_student_attendance_details':
            $userid = required_param('userId', PARAM_INT);
            $classid = required_param('classId', PARAM_INT);

            // New robust path: reuse attendance_manager resolver.
            // This avoids false "no sessions" when class has multiple/legacy attendance mappings.
            $DB->get_record('gmk_class', ['id' => $classid], 'id', MUST_EXIST);
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/teacher/attendance_manager.php');
            $sessionspayload = \local_grupomakro_core\external\teacher\attendance_manager::get_sessions($classid);

            if (($sessionspayload['status'] ?? 'error') !== 'success') {
                $response = [
                    'status' => 'error',
                    'message' => (string)($sessionspayload['message'] ?? 'No se encontro actividad de asistencia para la clase.')
                ];
                break;
            }

            $attendanceid = (int)($sessionspayload['attendance_id'] ?? 0);
            $sessionrows = $sessionspayload['sessions'] ?? [];
            if (!is_array($sessionrows) || empty($sessionrows)) {
                $response = ['status' => 'success', 'details' => []];
                break;
            }

            $sessionids = [];
            foreach ($sessionrows as $sessionrow) {
                $sid = (int)(is_array($sessionrow) ? ($sessionrow['id'] ?? 0) : ($sessionrow->id ?? 0));
                if ($sid > 0) {
                    $sessionids[$sid] = $sid;
                }
            }
            $sessionids = array_values($sessionids);

            if ($attendanceid <= 0 && !empty($sessionids)) {
                list($sessinsqlatt, $sessparamsatt) = $DB->get_in_or_equal($sessionids, SQL_PARAMS_NAMED, 'sidatt');
                $attendanceid = (int)$DB->get_field_sql(
                    "SELECT attendanceid
                       FROM {attendance_sessions}
                      WHERE id $sessinsqlatt
                   ORDER BY id ASC",
                    $sessparamsatt,
                    IGNORE_MULTIPLE
                );
            }

            $statuses = [];
            if ($attendanceid > 0) {
                $statuses = $DB->get_records(
                    'attendance_statuses',
                    ['attendanceid' => $attendanceid],
                    '',
                    'id, acronym, description, grade'
                );
            }

            $logsbysession = [];
            if (!empty($sessionids)) {
                list($sessinsql, $sessparams) = $DB->get_in_or_equal($sessionids, SQL_PARAMS_NAMED, 'sid');
                $logsql = "SELECT l.id, l.sessionid, l.statusid
                             FROM {attendance_log} l
                            WHERE l.studentid = :studentid
                              AND l.sessionid $sessinsql
                         ORDER BY l.sessionid ASC, l.id DESC";
                $logparams = array_merge(['studentid' => $userid], $sessparams);
                $recordset = $DB->get_recordset_sql($logsql, $logparams);
                foreach ($recordset as $logrow) {
                    $sessid = (int)$logrow->sessionid;
                    if ($sessid > 0 && !isset($logsbysession[$sessid])) {
                        $logsbysession[$sessid] = $logrow;
                    }
                }
                $recordset->close();
            }

            $details = [];
            foreach ($sessionrows as $sessionrow) {
                $sid = (int)(is_array($sessionrow) ? ($sessionrow['id'] ?? 0) : ($sessionrow->id ?? 0));
                if ($sid <= 0) {
                    continue;
                }

                $sessdate = (int)(is_array($sessionrow) ? ($sessionrow['sessdate'] ?? 0) : ($sessionrow->sessdate ?? 0));
                $description = (string)(is_array($sessionrow)
                    ? ($sessionrow['description'] ?? '')
                    : ($sessionrow->description ?? ''));
                $datevalue = (string)(is_array($sessionrow)
                    ? ($sessionrow['date'] ?? '')
                    : ($sessionrow->date ?? ''));
                $timevalue = (string)(is_array($sessionrow)
                    ? ($sessionrow['time'] ?? '')
                    : ($sessionrow->time ?? ''));

                if ($datevalue === '') {
                    $datevalue = userdate($sessdate, get_string('strftimedatefullshort', 'langconfig'));
                }
                if ($timevalue === '') {
                    $timevalue = userdate($sessdate, '%H:%M');
                }

                $statusid = isset($logsbysession[$sid]) ? (int)$logsbysession[$sid]->statusid : 0;
                $statusobj = ($statusid > 0 && isset($statuses[$statusid])) ? $statuses[$statusid] : null;

                $details[] = [
                    'id' => $sid,
                    'date' => $datevalue,
                    'time' => $timevalue,
                    'description' => $description,
                    'status' => $statusobj ? (string)$statusobj->description : 'Sin registrar',
                    'acronym' => $statusobj ? (string)$statusobj->acronym : '-',
                    'grade' => $statusobj ? (float)$statusobj->grade : null,
                    'is_absence' => $statusobj ? ((float)$statusobj->grade <= 0.0) : ($sessdate > 0 && $sessdate < time())
                ];
            }

            $response = ['status' => 'success', 'details' => array_values($details)];
            break;
            

        case 'local_grupomakro_get_student_info':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/student/get_student_info.php');
            
            // Map params from request
            $page = optional_param('page', 0, PARAM_INT);
            $resultsperpage = optional_param('resultsperpage', 15, PARAM_INT);
            $search = optional_param('search', '', PARAM_RAW);
            $planid = optional_param('planid', '', PARAM_RAW);
            $periodid = optional_param('periodid', '', PARAM_RAW);
            $status = optional_param('status', '', PARAM_TEXT);
            $financial_status = optional_param('financial_status', '', PARAM_TEXT);
            $classid = optional_param('classid', 0, PARAM_INT);

            // Execute
            $result = \local_grupomakro_core\external\student\get_student_info::execute(
                $page, $resultsperpage, $search, $planid, $periodid, $status, $classid, $financial_status
            );
            
            // Retrieve actual values from external_value structure if needed, or if array is returned directly
            // Moodle external functions return arrays/stdClasses.
            
            $response = [
                'status' => 'success',
                'data' => $result
            ];
            break;

        case 'local_grupomakro_get_class_details':
            $classid = required_param('classid', PARAM_INT);
            $class = $DB->get_record('gmk_class', ['id' => $classid]);
            if (!$class) throw new Exception("Clase no encontrada.");

            // Prefer strict event scope using the class activity instances.
            // This avoids leaking stale events from previous republishes that reused the same group.
            $classAttendanceInstance = 0;
            if (!empty($class->attendancemoduleid)) {
                $attcm = $DB->get_record('course_modules', ['id' => (int)$class->attendancemoduleid], 'id,course,instance', IGNORE_MISSING);
                if ($attcm && (int)$attcm->course === (int)$class->corecourseid) {
                    $classAttendanceInstance = (int)$attcm->instance;
                }
            }

            $classBbbInstanceIds = [];
            if (!empty($class->bbbmoduleids)) {
                foreach (explode(',', (string)$class->bbbmoduleids) as $bbbcmidraw) {
                    $bbbcmid = (int)trim((string)$bbbcmidraw);
                    if ($bbbcmid <= 0) continue;
                    $bbbinstance = $DB->get_field_sql(
                        "SELECT cm.instance
                           FROM {course_modules} cm
                           JOIN {modules} m ON m.id = cm.module
                          WHERE cm.id = :cmid
                            AND m.name = 'bigbluebuttonbn'",
                        ['cmid' => $bbbcmid]
                    );
                    if (!empty($bbbinstance)) {
                        $classBbbInstanceIds[(int)$bbbinstance] = (int)$bbbinstance;
                    }
                }
            }
            // Relation fallback (covers cases where bbbmoduleids is stale/empty).
            $relBbbIds = $DB->get_fieldset_select('gmk_bbb_attendance_relation', 'bbbid', 'classid = :cid AND bbbid > 0', ['cid' => (int)$classid]);
            foreach ($relBbbIds as $rid) {
                $rid = (int)$rid;
                if ($rid > 0) $classBbbInstanceIds[$rid] = $rid;
            }

            $events = [];
            $eventParts = [];
            $eventParams = ['courseid' => (int)$class->corecourseid];
            if ($classAttendanceInstance > 0) {
                $eventParts[] = "(e.modulename = 'attendance' AND e.instance = :attinstance)";
                $eventParams['attinstance'] = $classAttendanceInstance;
            }
            if (!empty($classBbbInstanceIds)) {
                list($bbbInSql, $bbbInParams) = $DB->get_in_or_equal(array_values($classBbbInstanceIds), SQL_PARAMS_NAMED, 'bbinst');
                $eventParts[] = "(e.modulename = 'bigbluebuttonbn' AND e.instance {$bbbInSql})";
                $eventParams = array_merge($eventParams, $bbbInParams);
            }

            if (!empty($eventParts)) {
                $sql = "SELECT e.*
                          FROM {event} e
                         WHERE e.courseid = :courseid
                           AND (" . implode(' OR ', $eventParts) . ")
                      ORDER BY e.timestart ASC";
                $events = $DB->get_records_sql($sql, $eventParams);
            } else {
                // Fallback for legacy rows with missing linkage fields.
                $sql = "SELECT e.*
                          FROM {event} e
                         WHERE e.courseid = :courseid
                           AND e.groupid = :groupid
                           AND e.modulename IN ('attendance','bigbluebuttonbn')
                      ORDER BY e.timestart ASC";
                $events = $DB->get_records_sql($sql, [
                    'courseid' => $class->corecourseid,
                    'groupid'  => $class->groupid,
                ]);
            }

            // Pre-scan: attendance timestamps for de-duplication and BBB instance IDs for batched lookup.
            $attendanceTimes = [];
            $attendanceEventIds = [];
            $bbbInstanceIds = [];
            foreach ($events as $e) {
                if ($e->modulename === 'attendance') {
                    $attendanceTimes[] = (int)$e->timestart;
                    $attendanceEventIds[(int)$e->id] = (int)$e->id;
                    continue;
                }
                if ($e->modulename === 'bigbluebuttonbn' && !empty($e->instance)) {
                    $bbbInstanceIds[(int)$e->instance] = (int)$e->instance;
                }
            }

            // Map attendance calendar event -> linked BBB instance in one query.
            $attendanceEventToBbb = [];
            if (!empty($attendanceEventIds)) {
                list($eventInSql, $eventParams) = $DB->get_in_or_equal(array_values($attendanceEventIds), SQL_PARAMS_NAMED, 'ev');
                $relSql = "SELECT sess.caleventid AS eventid, rel.bbbid
                             FROM {attendance_sessions} sess
                             JOIN {gmk_bbb_attendance_relation} rel ON rel.attendancesessionid = sess.id
                            WHERE rel.classid = :classid
                              AND rel.bbbid > 0
                              AND sess.caleventid $eventInSql";
                $relRows = $DB->get_records_sql($relSql, array_merge(['classid' => $classid], $eventParams));
                foreach ($relRows as $rel) {
                    $eventid = (int)$rel->eventid;
                    $bbbid = (int)$rel->bbbid;
                    if ($eventid > 0 && $bbbid > 0) {
                        $attendanceEventToBbb[$eventid] = $bbbid;
                        $bbbInstanceIds[$bbbid] = $bbbid;
                    }
                }
            }

            // Preload BBB metadata in one query.
            $bbbcols = $DB->get_columns('bigbluebuttonbn');
            $hasbbbguest = isset($bbbcols['guest']);
            $bbbguestselect = $hasbbbguest ? 'COALESCE(b.guest, 0)' : '0';
            $hasguestlogin = file_exists($CFG->dirroot . '/mod/bigbluebuttonbn/guest_login.php');
            $bbbMetaByInstance = [];
            if (!empty($bbbInstanceIds)) {
                list($bbbInSql, $bbbParams) = $DB->get_in_or_equal(array_values($bbbInstanceIds), SQL_PARAMS_NAMED, 'bb');
                $bbbSql = "SELECT cm.instance AS instanceid, cm.id AS cmid, {$bbbguestselect} AS guest
                             FROM {course_modules} cm
                             JOIN {modules} m ON m.id = cm.module AND m.name = 'bigbluebuttonbn'
                        LEFT JOIN {bigbluebuttonbn} b ON b.id = cm.instance
                            WHERE cm.course = :courseid
                              AND cm.instance $bbbInSql";
                $bbbRows = $DB->get_records_sql($bbbSql, array_merge(['courseid' => $class->corecourseid], $bbbParams));
                foreach ($bbbRows as $bbbRow) {
                    $instanceid = (int)$bbbRow->instanceid;
                    if ($instanceid <= 0 || isset($bbbMetaByInstance[$instanceid])) {
                        continue;
                    }
                    $bbbMetaByInstance[$instanceid] = [
                        'cmid' => (int)$bbbRow->cmid,
                        'guest' => !empty($bbbRow->guest),
                    ];
                }
            }

            $formatted_sessions = [];
            foreach ($events as $e) {
                try {
                    // Deduplicate standalone BBB events that overlap attendance by ~10 minutes.
                    if ($e->modulename === 'bigbluebuttonbn') {
                        $isDuplicate = false;
                        foreach ($attendanceTimes as $attTime) {
                            if (abs($attTime - (int)$e->timestart) <= 601) {
                                $isDuplicate = true;
                                break;
                            }
                        }
                        if ($isDuplicate) {
                            continue;
                        }
                    }

                    $session_data = new stdClass();
                    $session_data->id = (int)$e->id;
                    $session_data->startdate = (int)$e->timestart;
                    $session_data->enddate = (int)$e->timestart + (int)$e->timeduration;
                    $session_data->name = $e->name;
                    $session_data->type = ((int)$class->type === 1 ? 'virtual' : 'physical');
                    $session_data->join_url = '';

                    $linkedBbbId = 0;
                    if ($e->modulename === 'attendance') {
                        $linkedBbbId = (int)($attendanceEventToBbb[(int)$e->id] ?? 0);
                        if ($linkedBbbId > 0) {
                            $session_data->type = 'virtual';
                        }
                    } else if ($e->modulename === 'bigbluebuttonbn') {
                        $session_data->type = 'virtual';
                        $linkedBbbId = (int)$e->instance;
                    }

                    $session_data->bbb_cmid = 0;
                    if ($linkedBbbId > 0 && isset($bbbMetaByInstance[$linkedBbbId])) {
                        $cmid = (int)$bbbMetaByInstance[$linkedBbbId]['cmid'];
                        if ($cmid > 0) {
                            $session_data->bbb_cmid = $cmid;
                            $session_data->join_url = $CFG->wwwroot . '/mod/bigbluebuttonbn/view.php?id=' . $cmid;
                            if ($hasguestlogin && !empty($bbbMetaByInstance[$linkedBbbId]['guest'])) {
                                $session_data->guest_url = $CFG->wwwroot . '/mod/bigbluebuttonbn/guest_login.php?id=' . $cmid;
                            }
                        }
                    }

                    $formatted_sessions[] = $session_data;
                } catch (\Throwable $t) {
                    // Keep response resilient: skip malformed event rows.
                }
            }
            
            // Sort by start date ASC
            usort($formatted_sessions, function($a, $b) {
                return $a->startdate - $b->startdate;
            });

            $response = [
                'status' => 'success',
                'data' => [
                    'class' => $class,
                    'sessions' => array_values($formatted_sessions) // Send as 'sessions' like ManageClass.js expects
                ]
            ];
            break;

        case 'local_grupomakro_get_class_grades':
            $classid = required_param('classid', PARAM_INT);
            $class = $DB->get_record('gmk_class', ['id' => $classid]);
            if (!$class) throw new Exception("Clase no encontrada.");
            
            $courseid = $class->corecourseid;
            $groupid = $class->groupid;

            // Build class scope for grade items:
            // - activities that belong to this class section
            // - items that belong to this class grade category
            $classmodkeys = [];
            if (!empty($class->coursesectionid)) {
                $cmscope = $DB->get_records_sql(
                    "SELECT m.name AS modname, cm.instance
                       FROM {course_modules} cm
                       JOIN {modules} m ON m.id = cm.module
                      WHERE cm.course = :courseid
                        AND cm.section = :sectionid",
                    ['courseid' => (int)$courseid, 'sectionid' => (int)$class->coursesectionid]
                );
                foreach ($cmscope as $cmrow) {
                    if (!empty($cmrow->modname) && !empty($cmrow->instance)) {
                        $classmodkeys[$cmrow->modname . ':' . (int)$cmrow->instance] = true;
                    }
                }
            }

            // Hard include class attendance module if set (robust against section inconsistencies).
            if (!empty($class->attendancemoduleid)) {
                $attcm = $DB->get_record_sql(
                    "SELECT cm.instance, m.name AS modname
                       FROM {course_modules} cm
                       JOIN {modules} m ON m.id = cm.module
                      WHERE cm.id = :cmid",
                    ['cmid' => (int)$class->attendancemoduleid]
                );
                if ($attcm && !empty($attcm->modname) && !empty($attcm->instance)) {
                    $classmodkeys[$attcm->modname . ':' . (int)$attcm->instance] = true;
                }
            }

            // Fallbacks via relation table when class.attendancemoduleid is missing.
            $relattid = (int)$DB->get_field('gmk_bbb_attendance_relation', 'attendanceid', ['classid' => (int)$classid]);
            if ($relattid > 0) {
                $classmodkeys['attendance:' . $relattid] = true;
            }
            $relattcmid = (int)$DB->get_field('gmk_bbb_attendance_relation', 'attendancemoduleid', ['classid' => (int)$classid]);
            if ($relattcmid > 0) {
                $relattcm = $DB->get_record_sql(
                    "SELECT cm.instance, m.name AS modname
                       FROM {course_modules} cm
                       JOIN {modules} m ON m.id = cm.module
                      WHERE cm.id = :cmid",
                    ['cmid' => $relattcmid]
                );
                if ($relattcm && !empty($relattcm->modname) && !empty($relattcm->instance)) {
                    $classmodkeys[$relattcm->modname . ':' . (int)$relattcm->instance] = true;
                }
            }

            $classcategoryid = !empty($class->gradecategoryid) ? (int)$class->gradecategoryid : 0;

            // 1. Fetch Students (Rows)
            // Primary source: same service used by TeacherStudentTable, to keep parity across tabs.
            $students = [];
            try {
                require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/student/get_student_info.php');
                $studentinfo = \local_grupomakro_core\external\student\get_student_info::execute(
                    0, 5000, '', '', '', '', (int)$classid, ''
                );
                $datausers = $studentinfo['dataUsers'] ?? [];
                if (is_string($datausers)) {
                    $decoded = json_decode($datausers, true);
                    $datausers = is_array($decoded) ? $decoded : [];
                }
                if (is_array($datausers)) {
                    foreach ($datausers as $du) {
                        $uid = (int)($du['userid'] ?? 0);
                        if ($uid <= 0) {
                            continue;
                        }
                        $fullname = trim((string)($du['nameuser'] ?? ''));
                        $firstname = $fullname;
                        $lastname = '';
                        if ($fullname !== '') {
                            $parts = preg_split('/\s+/', $fullname, 2);
                            $firstname = (string)($parts[0] ?? $fullname);
                            $lastname = (string)($parts[1] ?? '');
                        }
                        $u = new stdClass();
                        $u->id = $uid;
                        $u->firstname = $firstname;
                        $u->lastname = $lastname;
                        $u->email = (string)($du['email'] ?? '');
                        $u->idnumber = (string)($du['documentnumber'] ?? '');
                        $u->fullname = $fullname;
                        $students[$uid] = $u;
                    }
                }
            } catch (\Throwable $studentex) {
                // Silent fallback to legacy query path below.
            }

            // Fallback source if external student service returns no rows.
            if (empty($students)) {
                // Keep parity with Student tab behavior:
                // - Use Moodle group roster when available.
                // - If group is empty/missing, fallback to class roster in gmk_course_progre.
                $studentids = [];
                if (!empty($groupid)) {
                    $studentids = $DB->get_fieldset_select('groups_members', 'userid', 'groupid = :gid', ['gid' => (int)$groupid]);
                    $studentids = array_values(array_map('intval', $studentids));
                }

                if (empty($studentids)) {
                    $studentids = $DB->get_fieldset_select('gmk_course_progre', 'userid', 'classid = :cid', ['cid' => (int)$classid]);
                    $studentids = array_values(array_map('intval', $studentids));
                }

                if (!empty($class->instructorid)) {
                    $studentids = array_values(array_filter($studentids, function($uid) use ($class) {
                        return (int)$uid !== (int)$class->instructorid;
                    }));
                }

                if (!empty($studentids)) {
                    list($stuinsql, $stuparams) = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'stu');
                    $students = $DB->get_records_sql("
                        SELECT u.id, u.firstname, u.lastname, u.email, u.idnumber
                          FROM {user} u
                         WHERE u.id $stuinsql
                      ORDER BY u.lastname, u.firstname
                    ", $stuparams);
                }
            }

            $userids = array_keys($students);

            // 2. Fetch Grade Items (Columns)
            require_once($CFG->libdir . '/gradelib.php');
            $grade_items = \grade_item::fetch_all(['courseid' => $courseid]);
            
            $columns = [];
            $item_ids = [];

            // Sort items so total is at the end
            usort($grade_items, function($a, $b) {
                if ($a->itemtype === 'course') return 1;
                if ($b->itemtype === 'course') return -1;
                return $a->sortorder - $b->sortorder;
            });

            foreach ($grade_items as $gi) {
                // Class-scoped totals:
                // - exclude course total (shared across many classes in the same course shell)
                // - include only the class category total (if available)
                $is_total = false;
                $includeitem = false;
                if ($gi->itemtype === 'course') {
                    $includeitem = false;
                } else if ($gi->itemtype === 'category') {
                    if ($classcategoryid > 0 && (int)$gi->iteminstance === $classcategoryid) {
                        $includeitem = true;
                        $is_total = true;
                    }
                }

                // Include manual items only if they belong to this class category.
                if (!$includeitem && $gi->itemtype === 'manual') {
                    if ($classcategoryid > 0 && (int)$gi->categoryid === $classcategoryid) {
                        $includeitem = true;
                    }
                }

                // Include module items if they belong to class section scope or class category.
                if (!$includeitem && $gi->itemtype === 'mod') {
                    $modkey = ($gi->itemmodule ?: '') . ':' . (int)$gi->iteminstance;
                    $inclasssection = isset($classmodkeys[$modkey]);
                    $inclasscategory = ($classcategoryid > 0 && (int)$gi->categoryid === $classcategoryid);
                    $insuffix = (!empty($gi->itemname) && preg_match('/-' . preg_quote((string)$classid, '/') . '$/', trim($gi->itemname)));
                    if ($inclasssection || $inclasscategory || $insuffix) {
                        $includeitem = true;
                    }
                }

                if (!$includeitem) {
                    continue;
                }
                
                $columns[] = [
                    'id' => $gi->id,
                    'title' => $gi->itemname ?: ($gi->itemtype === 'course' ? "Total del Curso" : $gi->itemtype),
                    'max_grade' => (float)$gi->grademax % 1 === 0 ? (int)$gi->grademax : round($gi->grademax, 1),
                    'weight' => $gi->aggregationcoef,
                    'is_total' => $is_total,
                    'itemtype' => $gi->itemtype
                ];
                $item_ids[] = (int)$gi->id;
            }

            if (empty($item_ids)) {
                $grades_data = [];
                foreach ($students as $student) {
                    $grades_data[] = [
                        'id' => $student->id,
                        'fullname' => $student->firstname . ' ' . $student->lastname,
                        'email' => $student->email,
                        'grades' => []
                    ];
                }
                $response = [
                    'status' => 'success',
                    'data' => [
                        'columns' => $columns,
                        'students' => $grades_data
                    ]
                ];
                break;
            }

            $grades_map = [];
            // 3. Fetch Grades (Cells) - BULK QUERY for performance
            if (!empty($userids) && !empty($item_ids)) {
                list($userinsql, $userparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
                list($iteminsql, $itemparams) = $DB->get_in_or_equal($item_ids, SQL_PARAMS_NAMED, 'i');
                
                $sql = "SELECT id, itemid, userid, finalgrade 
                        FROM {grade_grades} 
                        WHERE userid $userinsql AND itemid $iteminsql";
                
                $all_grades = $DB->get_records_sql($sql, array_merge($userparams, $itemparams));
                
                // Map grades to [userid][itemid]
                foreach ($all_grades as $g) {
                    $grades_map[$g->userid][$g->itemid] = $g->finalgrade;
                }
            }

            $grades_data = [];
            foreach ($students as $student) {
                $studentfullname = trim(($student->fullname ?? '') !== '' ? $student->fullname : (($student->firstname ?? '') . ' ' . ($student->lastname ?? '')));
                $student_row = [
                    'id' => $student->id,
                    'fullname' => $studentfullname,
                    'email' => $student->email,
                    'grades' => []
                ];

                foreach ($item_ids as $iid) {
                    $val = isset($grades_map[$student->id][$iid]) ? $grades_map[$student->id][$iid] : '-';
                    $student_row['grades'][$iid] = $val;
                }
                $grades_data[] = $student_row;
            }

            $response = [
                'status' => 'success',
                'data' => [
                    'columns' => $columns,
                    'students' => $grades_data
                ]
            ];
            break;

        case 'local_grupomakro_get_gradebook_structure':
            $classid = required_param('classid', PARAM_INT);
            $class = $DB->get_record('gmk_class', ['id' => $classid]);
            if (!$class) throw new Exception("Clase no encontrada.");
            
            $courseid = $class->corecourseid;
            $classcategoryid = !empty($class->gradecategoryid) ? (int)$class->gradecategoryid : 0;
            
            require_once($CFG->libdir . '/gradelib.php');
            
            // Get course category to determine aggregation method
            $target_cat = \grade_category::fetch_course_category($courseid);
            // If class has a specific category, use it for aggregation context
            if (!empty($class->gradecategoryid)) {
                $class_cat = \grade_category::fetch(['id' => $class->gradecategoryid]);
                if ($class_cat) $target_cat = $class_cat;
            }
            $aggregation = $target_cat->aggregation; 

            // Build class scope (section modules + class attendance module fallback).
            $classmodkeys = [];
            if (!empty($class->coursesectionid)) {
                $cmscope = $DB->get_records_sql(
                    "SELECT m.name AS modname, cm.instance
                       FROM {course_modules} cm
                       JOIN {modules} m ON m.id = cm.module
                      WHERE cm.course = :courseid
                        AND cm.section = :sectionid",
                    ['courseid' => (int)$courseid, 'sectionid' => (int)$class->coursesectionid]
                );
                foreach ($cmscope as $cmrow) {
                    if (!empty($cmrow->modname) && !empty($cmrow->instance)) {
                        $classmodkeys[$cmrow->modname . ':' . (int)$cmrow->instance] = true;
                    }
                }
            }
            if (!empty($class->attendancemoduleid)) {
                $attcm = $DB->get_record_sql(
                    "SELECT cm.instance, m.name AS modname
                       FROM {course_modules} cm
                       JOIN {modules} m ON m.id = cm.module
                      WHERE cm.id = :cmid",
                    ['cmid' => (int)$class->attendancemoduleid]
                );
                if ($attcm && !empty($attcm->modname) && !empty($attcm->instance)) {
                    $classmodkeys[$attcm->modname . ':' . (int)$attcm->instance] = true;
                }
            }

            // Fallbacks via relation table when class.attendancemoduleid is missing.
            $relattid = (int)$DB->get_field('gmk_bbb_attendance_relation', 'attendanceid', ['classid' => (int)$classid]);
            if ($relattid > 0) {
                $classmodkeys['attendance:' . $relattid] = true;
            }
            $relattcmid = (int)$DB->get_field('gmk_bbb_attendance_relation', 'attendancemoduleid', ['classid' => (int)$classid]);
            if ($relattcmid > 0) {
                $relattcm = $DB->get_record_sql(
                    "SELECT cm.instance, m.name AS modname
                       FROM {course_modules} cm
                       JOIN {modules} m ON m.id = cm.module
                      WHERE cm.id = :cmid",
                    ['cmid' => $relattcmid]
                );
                if ($relattcm && !empty($relattcm->modname) && !empty($relattcm->instance)) {
                    $classmodkeys[$relattcm->modname . ':' . (int)$relattcm->instance] = true;
                }
            }

            // Fetch items in course and filter to class scope.
            $grade_items = \grade_item::fetch_all(['courseid' => $courseid]);
            $categoryaggmap = [];
            $categoryrows = $DB->get_records('grade_categories', ['courseid' => $courseid], '', 'id,aggregation');
            foreach ($categoryrows as $crow) {
                $categoryaggmap[(int)$crow->id] = (int)$crow->aggregation;
            }
            
            $items = [];
            $total_weight = 0;
            $items_for_calc = [];

            foreach ($grade_items as $gi) {
                if ($gi->itemtype == 'course' || $gi->itemtype == 'category') continue;

                $inclasscategory = ($classcategoryid > 0 && (int)$gi->categoryid === $classcategoryid);
                $includeitem = false;

                if ($gi->itemtype === 'manual') {
                    // Manual items must belong to this class category.
                    $includeitem = $inclasscategory;
                } else if ($gi->itemtype === 'mod') {
                    // Module items must be in this class section scope OR class category.
                    $modkey = ($gi->itemmodule ?: '') . ':' . (int)$gi->iteminstance;
                    $inclasssection = isset($classmodkeys[$modkey]);
                    $insuffix = (!empty($gi->itemname) && preg_match('/-' . preg_quote((string)$classid, '/') . '$/', trim($gi->itemname)));
                    $includeitem = ($inclasssection || $inclasscategory || $insuffix);
                }

                if (!$includeitem) continue;
                
                // HIDE "Nota Final Integrada" from the UI list as requested.
                // It will be managed automatically in the backend.
                if ($gi->itemname && strpos($gi->itemname, 'Nota Final Integrada') !== false) {
                    continue;
                }

                $weight = 0;
                $parentagg = $categoryaggmap[(int)$gi->categoryid] ?? (int)$aggregation;
                $is_natural = ($parentagg === 13);
                
                if ($is_natural) {
                   $weight = (float)$gi->aggregationcoef2;
                } else {
                   $weight = (float)$gi->aggregationcoef;
                }

                $items[] = [
                    'id' => $gi->id,
                    'itemname' => $gi->itemname ?: ($gi->itemtype . ' ' . $gi->itemmodule),
                    'itemtype' => $gi->itemtype,
                    'itemmodule' => $gi->itemmodule,
                    'weight' => $weight,
                    'grademax' => (float)$gi->grademax,
                    'locked' => $gi->locked,
                    'hidden' => (int)$gi->hidden,
                    'aggregationcoef2' => (float)$gi->aggregationcoef2, // For debugging/reference
                    'is_natural' => $is_natural
                ];
            }

            // If Natural and total weight is 0 (all auto), or mixed, we might want to 
            // return the calculated weights?
            // Actually, frontend calculates total. If it returns 0s, frontend shows 0s.
            // If the user wants to EDIT, they set a value.
            // But user says "Moodle shows 19.231".
            // That means Moodle is calculating it.
            // We should try to provide that calculated value for reference or init.
            
            // Calculate referential percentage for initial display
            $sum_max = 0;
            $sum_weights = 0;
            foreach ($items as $it) {
                $sum_max += $it['grademax'];
                $sum_weights += $it['weight'];
            }

            foreach ($items as &$it) {
                if ($sum_weights > 0) {
                    $it['percentage'] = ($it['weight'] / $sum_weights) * 100;
                } else if ($sum_max > 0) {
                    // Estimate if all weights are zero
                    $it['percentage'] = ($it['grademax'] / $sum_max) * 100;
                } else {
                    $it['percentage'] = 0;
                }
            }

            $response = [
                'status' => 'success',
                'items' => $items,
                'total_weight' => ($sum_weights > 0) ? 100 : 0, // Total refers to percentage sum
                'aggregation' => $aggregation
            ];
            break;

        case 'local_grupomakro_update_grade_weights':
            $classid = required_param('classid', PARAM_INT);
            $weights_json = required_param('weights', PARAM_RAW);
            $weights = json_decode($weights_json, true);
            
            // New: Optional sort order
            $sort_order_json = optional_param('sortorder', '', PARAM_RAW);
            $sort_order = !empty($sort_order_json) ? json_decode($sort_order_json, true) : null;

            if (!is_array($weights)) throw new Exception("Datos invÃƒÂ¡lidos.");

            $class = $DB->get_record('gmk_class', ['id' => $classid]);
            if (!$class) throw new Exception("Clase no encontrada.");

            require_once($CFG->libdir . '/gradelib.php');

            // Build class scope to avoid editing unrelated grade items.
            $classcategoryid = !empty($class->gradecategoryid) ? (int)$class->gradecategoryid : 0;
            $classmodkeys = [];
            if (!empty($class->coursesectionid)) {
                $cmscope = $DB->get_records_sql(
                    "SELECT m.name AS modname, cm.instance
                       FROM {course_modules} cm
                       JOIN {modules} m ON m.id = cm.module
                      WHERE cm.course = :courseid
                        AND cm.section = :sectionid",
                    ['courseid' => (int)$class->corecourseid, 'sectionid' => (int)$class->coursesectionid]
                );
                foreach ($cmscope as $cmrow) {
                    if (!empty($cmrow->modname) && !empty($cmrow->instance)) {
                        $classmodkeys[$cmrow->modname . ':' . (int)$cmrow->instance] = true;
                    }
                }
            }
            if (!empty($class->attendancemoduleid)) {
                $attcm = $DB->get_record_sql(
                    "SELECT cm.instance, m.name AS modname
                       FROM {course_modules} cm
                       JOIN {modules} m ON m.id = cm.module
                      WHERE cm.id = :cmid",
                    ['cmid' => (int)$class->attendancemoduleid]
                );
                if ($attcm && !empty($attcm->modname) && !empty($attcm->instance)) {
                    $classmodkeys[$attcm->modname . ':' . (int)$attcm->instance] = true;
                }
            }

            // Fallbacks via relation table when class.attendancemoduleid is missing.
            $relattid = (int)$DB->get_field('gmk_bbb_attendance_relation', 'attendanceid', ['classid' => (int)$classid]);
            if ($relattid > 0) {
                $classmodkeys['attendance:' . $relattid] = true;
            }
            $relattcmid = (int)$DB->get_field('gmk_bbb_attendance_relation', 'attendancemoduleid', ['classid' => (int)$classid]);
            if ($relattcmid > 0) {
                $relattcm = $DB->get_record_sql(
                    "SELECT cm.instance, m.name AS modname
                       FROM {course_modules} cm
                       JOIN {modules} m ON m.id = cm.module
                      WHERE cm.id = :cmid",
                    ['cmid' => $relattcmid]
                );
                if ($relattcm && !empty($relattcm->modname) && !empty($relattcm->instance)) {
                    $classmodkeys[$relattcm->modname . ':' . (int)$relattcm->instance] = true;
                }
            }

            // Determine aggregation method using same logic as fetch
            $target_cat = \grade_category::fetch_course_category($class->corecourseid);
            if (!empty($class->gradecategoryid)) {
                $class_cat = \grade_category::fetch(['id' => $class->gradecategoryid]);
                if ($class_cat) $target_cat = $class_cat;
            }
            $aggregation = $target_cat->aggregation; 
            $is_natural = ($aggregation == 13);
            $categoryaggmap = [];
            $categoryrows = $DB->get_records('grade_categories', ['courseid' => $class->corecourseid], '', 'id,aggregation');
            foreach ($categoryrows as $crow) {
                $categoryaggmap[(int)$crow->id] = (int)$crow->aggregation;
            }

            $tx = $DB->start_delegated_transaction();
            $needsregrade = false;
            try {
                // 1. Update Weights and Visibility
                foreach ($weights as $w) {
                    $gi = \grade_item::fetch(['id' => $w['id'], 'courseid' => $class->corecourseid]);
                    if ($gi) {
                        $inclasscategory = ($classcategoryid > 0 && (int)$gi->categoryid === $classcategoryid);
                        $inclasssection = false;
                        if ($gi->itemtype === 'mod') {
                            $modkey = ($gi->itemmodule ?: '') . ':' . (int)$gi->iteminstance;
                            $inclasssection = isset($classmodkeys[$modkey]);
                        }
                        $insuffix = (!empty($gi->itemname) && preg_match('/-' . preg_quote((string)$classid, '/') . '$/', trim($gi->itemname)));
                        // Allow updates only for class-scoped manual/mod/category total items.
                        $allowed = false;
                        if ($gi->itemtype === 'manual') {
                            $allowed = $inclasscategory;
                        } else if ($gi->itemtype === 'mod') {
                            $allowed = ($inclasssection || $inclasscategory || $insuffix);
                        } else if ($gi->itemtype === 'category') {
                            $allowed = ($classcategoryid > 0 && (int)$gi->iteminstance === $classcategoryid);
                        }
                        if (!$allowed) {
                            continue;
                        }

                        // Update weight using cached parent category aggregation (avoid repeated grade tree lookups).
                        $parentagg = $categoryaggmap[(int)$gi->categoryid] ?? (int)$aggregation;
                        $is_natural = ($parentagg === 13);

                        $newweight = (float)$w['weight'];
                        if ($is_natural) {
                            $currentcoef2 = (float)$gi->aggregationcoef2;
                            $currentoverride = (int)$gi->weightoverride;
                            if (abs($currentcoef2 - $newweight) > 0.00001 || $currentoverride !== 1) {
                                $gi->aggregationcoef2 = $newweight;
                                $gi->weightoverride = 1; 
                                $gi->update('aggregationcoef2');
                                $gi->update('weightoverride');
                                $needsregrade = true;
                            }
                        } else {
                            $currentcoef = (float)$gi->aggregationcoef;
                            if (abs($currentcoef - $newweight) > 0.00001) {
                                $gi->aggregationcoef = $newweight;
                                $gi->update('aggregationcoef');
                                $needsregrade = true;
                            }
                        }

                        // Update Visibility if provided
                        if (isset($w['hidden'])) {
                            $gi->set_hidden($w['hidden'] ? 1 : 0);
                        }

                        // ENFORCE Grademax = 100 (Except for "Nota Final Integrada" which is migrated data)
                        $is_migrated = ($gi->itemname && strpos($gi->itemname, 'Nota Final Integrada') !== false);
                        if (!$is_migrated && $gi->grademax != 100) {
                            $gi->grademax = 100;
                            $gi->update('grademax');
                            $needsregrade = true;
                        }
                    }
                }

                // 2. AUTOMATIC AGGREGATION FOR MIGRATED GRADES
                // If "Nota Final Integrada" exists, enforce "Highest Grade" (8) at the root
                // so migrated grades act as a competitor/alternative to the current category.
                $all_gi = \grade_item::fetch_all(['courseid' => $class->corecourseid]);
                foreach ($all_gi as $gi) {
                    if ($gi->itemname && strpos($gi->itemname, 'Nota Final Integrada') !== false) {
                        // Ensure it has a weight so it's not and-ed out if we were in Weighted Mean
                        if ($gi->aggregationcoef <= 0) {
                            $gi->aggregationcoef = 1.0;
                            $gi->update('aggregationcoef');
                        }
                        if ($gi->aggregationcoef2 <= 0) {
                            $gi->aggregationcoef2 = 1.0;
                            $gi->update('aggregationcoef2');
                        }
                        
                        // Set Root to Highest Grade
                        $root_cat = \grade_category::fetch_course_category($class->corecourseid);
                        if ($root_cat && $root_cat->aggregation != 8) {
                            $root_cat->aggregation = 8;
                            $root_cat->update();
                            $needsregrade = true;
                        }
                        break;
                    }
                }

                // 3. Update Sort Order if provided
                if (is_array($sort_order)) {
                    $anchor_sortorder = 0; // Move to beginning of course sequence
                    foreach ($sort_order as $itemid) {
                        $gi = \grade_item::fetch(['id' => $itemid, 'courseid' => $class->corecourseid]);
                        if ($gi) {
                            $inclasscategory = ($classcategoryid > 0 && (int)$gi->categoryid === $classcategoryid);
                            $inclasssection = false;
                            if ($gi->itemtype === 'mod') {
                                $modkey = ($gi->itemmodule ?: '') . ':' . (int)$gi->iteminstance;
                                $inclasssection = isset($classmodkeys[$modkey]);
                            }
                            $insuffix = (!empty($gi->itemname) && preg_match('/-' . preg_quote((string)$classid, '/') . '$/', trim($gi->itemname)));
                            $allowed = false;
                            if ($gi->itemtype === 'manual') {
                                $allowed = $inclasscategory;
                            } else if ($gi->itemtype === 'mod') {
                                $allowed = ($inclasssection || $inclasscategory || $insuffix);
                            } else if ($gi->itemtype === 'category') {
                                $allowed = ($classcategoryid > 0 && (int)$gi->iteminstance === $classcategoryid);
                            }
                            if (!$allowed) {
                                continue;
                            }

                            $gi->move_after_sortorder($anchor_sortorder);
                            // After move, the item's sortorder is updated. Use it as next anchor.
                            $anchor_sortorder = $gi->sortorder;
                        }
                    }
                }

                $tx->allow_commit();
                
                // Performance: avoid synchronous full regrade on each save.
                // Mark as needs regrading so Moodle recalculates lazily when required.
                if ($needsregrade) {
                    if (function_exists('grade_force_full_regrading')) {
                        \grade_force_full_regrading($class->corecourseid);
                    } else {
                        \grade_regrade_final_grades($class->corecourseid);
                    }
                }

                $response = ['status' => 'success', 'message' => 'ConfiguraciÃƒÂ³n actualizada.'];
            } catch (Exception $e) {
                $tx->rollback($e);
                throw $e;
            }
            break;

        case 'local_grupomakro_create_manual_grade_item':
            $classid = required_param('classid', PARAM_INT);
            $name = required_param('name', PARAM_TEXT);
            $maxmark = optional_param('maxmark', 100, PARAM_INT); // Default to 100 as requested

            $class = $DB->get_record('gmk_class', ['id' => $classid]);
            if (!$class) throw new Exception("Clase no encontrada.");

            require_once($CFG->libdir . '/gradelib.php');

            // Find the class grade category to put this item in
            // We usually put it in the main course category or a specific one?
            // The logic in create_class creates a category for the class (gradecategoryid)
            // Let's use that if available to keep things organized.
            
            $parent_category_id = null;
            if (!empty($class->gradecategoryid)) {
                $parent_category_id = $class->gradecategoryid;
            } else {
                // Fallback to course default
                 $course_cat = \grade_category::fetch_course_category($class->corecourseid);
                 $parent_category_id = $course_cat->id;
            }

            $grade_item = new \grade_item();
            $grade_item->courseid = $class->corecourseid;
            $grade_item->categoryid = $parent_category_id;
            $grade_item->itemname = $name;
            $grade_item->itemtype = 'manual';
            $grade_item->grademax = $maxmark;
            $grade_item->grademin = 0;
            $grade_item->aggregationcoef = 0; // Default 0 weight
            $grade_item->insert();

            $response = ['status' => 'success', 'message' => 'Columna creada.', 'id' => $grade_item->id];
            break;

        case 'local_grupomakro_delete_grade_item':
            $itemid = required_param('itemid', PARAM_INT);
            require_once($CFG->libdir . '/gradelib.php');

            $gi = \grade_item::fetch(['id' => $itemid]);
            if (!$gi) throw new Exception("ÃƒÂtem no encontrado.");
            
            // Security check: Only manual items? Or allow deleting activities?
            // Safer to allow only manual for now, deleting activities deletes the module which is dangerous here.
            if ($gi->itemtype !== 'manual') {
                throw new Exception("Solo se pueden eliminar ÃƒÂ­tems manuales desde aquÃƒÂ­.");
            }

            $gi->delete();
            $response = ['status' => 'success', 'message' => 'ÃƒÂtem eliminado.'];
            break;

        case 'local_grupomakro_get_all_activities':
            $classid = required_param('classid', PARAM_INT);
            $class = $DB->get_record('gmk_class', ['id' => $classid]);
            if (!$class) throw new Exception("Clase no encontrada.");
            
            // Set context for get_icon_url and other core functions
            $context = context_course::instance($class->corecourseid);
            $PAGE->set_context($context);
            
            require_once($CFG->libdir . '/modinfolib.php');
            $modinfo = get_fast_modinfo($class->corecourseid);

            // Only show activities belonging to this class's course section
            $cms = [];
            if (!empty($class->coursesectionid)) {
                $section_info = $modinfo->get_section_info_by_id($class->coursesectionid);
                if ($section_info) {
                    $section_num = $section_info->__get('section');
                    $all_sections = $modinfo->get_sections();
                    if (isset($all_sections[$section_num])) {
                        foreach ($all_sections[$section_num] as $cmid) {
                            $cms[] = $modinfo->get_cm($cmid);
                        }
                    }
                }
            }
            // If no section found, $cms stays empty Ã¢â‚¬â€ avoids leaking other classes' activities

            $activities = [];

            foreach ($cms as $cm) {
                if (!$cm->uservisible) continue;
                // Exclude label
                if ($cm->modname === 'label') continue;

                // Attendance and BBB are "default" activities Ã¢â‚¬â€ always placed in General (no tags)
                $is_general = ($cm->modname === 'attendance' || $cm->modname === 'bigbluebuttonbn');

                if ($is_general) {
                    $tagNames = [];
                } else {
                    $tags = \core_tag_tag::get_item_tags('core', 'course_modules', $cm->id);
                    $tagNames = array_map(function($t) { return $t->rawname; }, $tags);
                }

                $activities[] = [
                    'id' => $cm->id,
                    'name' => $cm->name,
                    'modname' => $cm->modname,
                    'modicon' => $cm->get_icon_url()->out(),
                    'url' => $cm->url ? $cm->url->out(false) : '',
                    'tags' => array_values($tagNames),
                    'is_general' => $is_general
                ];
            }
            
            $response = ['status' => 'success', 'activities' => $activities];
            break;

        case 'local_grupomakro_get_available_modules':
            $modules = $DB->get_records('modules', ['visible' => 1], 'name ASC');
            $available = [];
            $exclude = ['label', 'forum', 'quiz']; // These are already handled or special? 
            // Actually user wants "Others" to show the rest. If we show all, we duplicate.
            // But having a full list is safer for "Generic" selector. 
            // Let's just return all and let frontend decide or just show all in the dropdown.
            
            foreach ($modules as $m) {
                try {
                    $label = get_string('modulename', $m->name);
                } catch (Exception $e) {
                    $label = $m->name;
                }
                $available[] = [
                    'name' => $m->name,
                    'label' => $label
                ];
            }
            
            usort($available, function($a, $b) {
                return strcmp($a['label'], $b['label']);
            });
            
            $response = ['status' => 'success', 'modules' => $available];
            break;

        case 'local_grupomakro_get_question_details':
            $questionid = required_param('questionid', PARAM_INT);
            try {
                require_once($CFG->libdir . '/questionlib.php');
                $qdata = question_bank::load_question_data($questionid);
                if (!$qdata) throw new Exception("Pregunta no encontrada.");

                // Map to frontend structure
                $details = [
                    'id' => $qdata->id,
                    'type' => $qdata->qtype,
                    'name' => $qdata->name,
                    'questiontext' => $qdata->questiontext,
                    'defaultmark' => (float)$qdata->defaultmark,
                    'answers' => [],
                    'subquestions' => [],
                    'draggables' => [],
                    'drops' => [],
                    'dataset' => []
                ];

                // Type Specific Mapping
                $raw_answers = [];
                if (($qdata->qtype === 'ddwtos' || $qdata->qtype === 'gapselect') && isset($qdata->options->choices)) {
                    $raw_answers = $qdata->options->choices;
                } elseif (isset($qdata->answers) && !empty($qdata->answers)) {
                    $raw_answers = $qdata->answers;
                } elseif (isset($qdata->options->answers)) {
                    $raw_answers = $qdata->options->answers;
                } elseif (isset($qdata->options->choices)) {
                    $raw_answers = $qdata->options->choices;
                }

                foreach ($raw_answers as $ans) {
                    $group = 1;
                    $infinite = 0;
                    
                    // Try standard fields first
                    if (isset($ans->draggroup)) $group = (int)$ans->draggroup;
                    elseif (isset($ans->choicegroup)) $group = (int)$ans->choicegroup;
                    elseif (isset($ans->selectgroup)) $group = (int)$ans->selectgroup;
                    elseif (isset($ans->group)) $group = (int)$ans->group;
                    
                    // Fallback for objects returned by load_question_data
                    if ($group === 1) {
                         if (isset($ans->options) && isset($ans->options->selectgroup)) $group = (int)$ans->options->selectgroup;
                         elseif (isset($ans->selectgroup)) $group = (int)$ans->selectgroup;
                         elseif (isset($ans->choicegroup)) $group = (int)$ans->choicegroup;
                         elseif (isset($ans->draggroup)) $group = (int)$ans->draggroup;
                    }

                    // Fallback: Check for serialized data in feedback
                    if (isset($ans->feedback) && ($qdata->qtype === 'ddwtos' || $qdata->qtype === 'gapselect')) {
                         $fb_text = is_string($ans->feedback) ? $ans->feedback : ($ans->feedback->text ?? '');
                         if (!empty($fb_text)) {
                              if (($settings = @unserialize($fb_text)) !== false) {
                                   if (isset($settings->draggroup)) $group = (int)$settings->draggroup;
                                   if (isset($settings->selectgroup)) $group = (int)$settings->selectgroup;
                                   if (isset($settings->choicegroup)) $group = (int)$settings->choicegroup;
                                   if (isset($settings->infinite)) $infinite = (int)$settings->infinite;
                              } elseif (is_numeric($fb_text)) {
                                   // GapSelect usually stores the group number directly in feedback text in some cases
                                   $group = (int)$fb_text;
                              }
                         }
                    }

                    $ans_text = (string)($ans->answer ?? ($ans->text ?? ''));

                    $details['answers'][] = [
                        'id' => $ans->id,
                        'text' => $ans_text,
                        'fraction' => (float)($ans->fraction ?? 0),
                        'tolerance' => isset($ans->tolerance) ? (float)$ans->tolerance : 0,
                        'feedback' => '', 
                        'group' => $group,
                        'infinite' => $infinite
                    ];
                }

                if ($qdata->qtype === 'match' && isset($qdata->options->subquestions)) {
                    foreach ($qdata->options->subquestions as $sub) {
                        $details['subquestions'][] = [
                            'text' => $sub->questiontext,
                            'answer' => $sub->answertext
                        ];
                    }
                }

                if ($qdata->qtype === 'ddimageortext' || $qdata->qtype === 'ddmarker') {
                    // Background Image URL
                    $fs = get_file_storage();
                    $filearea = 'bgimage';
                    $component = 'qtype_' . $qdata->qtype;
                    $files = $fs->get_area_files($qdata->contextid, $component, $filearea, $qdata->id, 'itemid, filepath, filename', false);
                    if (!empty($files)) {
                        $file = reset($files);
                        $content = $file->get_content();
                        $mimetype = $file->get_mimetype();
                        $details['ddbase64'] = 'data:' . $mimetype . ';base64,' . base64_encode($content);
                    }

                    if (isset($qdata->options->drags)) {
                        foreach ($qdata->options->drags as $drag) {
                            $details['draggables'][] = [
                                'type' => $drag->dragitemtype ?? 'text',
                                'text' => $drag->label ?? '',
                                'group' => (int)($drag->draggroup ?? 1),
                                'infinite' => isset($drag->noofdrags) ? ((int)$drag->noofdrags === 0) : (bool)($drag->infinite ?? true)
                            ];
                        }
                    }
                    if (isset($qdata->options->drops)) {
                        foreach ($qdata->options->drops as $drop) {
                            $x = 0; $y = 0;
                            if ($qdata->qtype === 'ddmarker' && !empty($drop->coords)) {
                                $parts = explode(';', $drop->coords);
                                $coords = explode(',', $parts[0]);
                                $x = (int)$coords[0];
                                $y = (int)$coords[1];
                            } else {
                                $x = (int)($drop->xleft ?? $drop->x ?? 0);
                                $y = (int)($drop->ytop ?? $drop->y ?? 0);
                            }
                            $details['drops'][] = [
                                'choice' => (int)$drop->choice,
                                'x' => $x,
                                'y' => $y
                            ];
                        }
                    }
                }

                // Reconstruct Cloze text if applicable (Moodle stores markers like {#1})
                if ($qdata->qtype === 'multianswer' && isset($qdata->options->questions)) {
                    $text = $qdata->questiontext;
                    foreach ($qdata->options->questions as $seq => $subq) {
                        $text = str_replace('{#'.$seq.'}', $subq->questiontext, $text);
                    }
                    $details['questiontext'] = $text;
                }

                // Detect if question belongs to a course-level category
                $cat_context = context::instance_by_id($qdata->contextid);
                $details['save_to_course'] = ($cat_context->contextlevel == CONTEXT_COURSE);

                $response = ['status' => 'success', 'question' => $details];
            } catch (Exception $e) {
                $response = ['status' => 'error', 'message' => $e->getMessage()];
            }
            break;

        case 'local_grupomakro_get_quiz_questions':
            $cmid = required_param('cmid', PARAM_INT);
            $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
            $quiz = $DB->get_record('quiz', array('id' => $cm->instance), '*', MUST_EXIST);
            
            // Validate context (teacher)
            $context = context_module::instance($cmid);
            
            // Permission Logic with Fallback
            if (!has_capability('mod/quiz:manage', $context)) {
                $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
                $is_gmk_instructor = $DB->record_exists('gmk_class', ['corecourseid' => $course->id, 'instructorid' => $USER->id]);
                if (!$is_gmk_instructor) {
                    require_capability('mod/quiz:manage', $context);
                }
            }
            
            // Moodle 4.0+ Compatible Query (quiz_slots -> references -> versions -> question)
            $sql = "SELECT q.id, q.name, q.questiontext, q.qtype, s.slot, s.maxmark
                    FROM {quiz_slots} s
                    JOIN {question_references} qr ON qr.itemid = s.id
                    JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                    JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                    JOIN {question} q ON q.id = qv.questionid
                    WHERE s.quizid = :quizid
                    AND qr.component = 'mod_quiz'
                    AND qr.questionarea = 'slot'
                    AND qv.version = (
                        SELECT MAX(v.version)
                        FROM {question_versions} v
                        WHERE v.questionbankentryid = qbe.id
                    )
                    ORDER BY s.slot";
            
            $questions = $DB->get_records_sql($sql, ['quizid' => $quiz->id]);
            
            // Clean up content
            $clean_questions = [];
            foreach ($questions as $q) {
                $clean_questions[] = [
                    'id' => $q->id,
                    'name' => $q->name,
                    'questiontext' => strip_tags($q->questiontext), // Plain text preview
                    'qtype' => $q->qtype,
                    'slot' => $q->slot,
                    'maxmark' => (float)$q->maxmark
                ];
            }
            
            $response = ['status' => 'success', 'questions' => $clean_questions];
            break;

        case 'local_grupomakro_add_question':
            try {
                global $USER, $CFG; // Ensure $CFG is available
                require_once($CFG->libdir . '/questionlib.php');
                require_once($CFG->dirroot . '/mod/quiz/locallib.php');
                // FIXED: Missing includes for question editing functions
                require_once($CFG->dirroot . '/question/editlib.php'); 
                require_once($CFG->dirroot . '/question/lib.php');

                $cmid = required_param('cmid', PARAM_INT);
                $qjson = required_param('question_data', PARAM_RAW);
                $data = json_decode($qjson);

                if (!$data) {
                    error_log("GMK_QUIZ_ERROR: Invalid JSON received: " . $qjson);
                    throw new Exception('Invalid JSON data');
                }
                
                // DEBUG: Log the type and indices for GapSelect investigation
                if ($data->type === 'gapselect' || $data->type === 'ddwtos') {
                    error_log("GMK_QUIZ_DEBUG: Saving {$data->type}. Question text: " . ($data->questiontext ?? 'EMPTY'));
                }

                $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
                $quiz = $DB->get_record('quiz', array('id' => $cm->instance), '*', MUST_EXIST);
                $context = context_module::instance($cmid);
                
                // Permission Logic with Fallback
                if (!has_capability('mod/quiz:manage', $context)) {
                    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
                    // FIXED: Removed closed => 0 constraint to allow editing in closed classes if needed by instructor
                    $is_gmk_instructor = $DB->record_exists('gmk_class', ['corecourseid' => $course->id, 'instructorid' => $USER->id]);
                    if (!$is_gmk_instructor) {
                        require_capability('mod/quiz:manage', $context);
                    }
                }
                
                // Get target category based on 'save_to_course' flag
                $course_context = context_course::instance($cm->course);
                $save_to_course = isset($data->save_to_course) && $data->save_to_course;
                
                if ($save_to_course) {
                    $cat = question_get_default_category($course_context->id);
                    if (!$cat) {
                        $qecontexts = new question_edit_contexts($course_context);
                        question_make_default_categories($qecontexts->all());
                        $cat = question_get_default_category($course_context->id);
                    }
                } else {
                    $cat = question_get_default_category($context->id);
                    if (!$cat) {
                        $cat = question_get_default_category($course_context->id);
                    }
                    if (!$cat) {
                        // Categories not yet initialized — create them now
                        $qecontexts = new question_edit_contexts($context);
                        question_make_default_categories($qecontexts->all());
                        $cat = question_get_default_category($context->id);
                        if (!$cat) {
                            $cat = question_get_default_category($course_context->id);
                        }
                    }
                }

                if (!$cat) throw new Exception('No question category found for the selected context.');

                // Prepare Question Object
                $question = new stdClass();
                if (!empty($data->id)) {
                    $question->id = (int)$data->id;
                    $old_question = question_bank::load_question_data($question->id);
                    $question->stamp = $old_question->stamp;
                    $question->version = $old_question->version;
                } else {
                    $question->stamp = make_unique_id_code();
                    $question->version = make_unique_id_code();
                }

                $question->qtype = $data->type;
                $question->name = $data->name;
                $question->questiontext = ['text' => isset($data->questiontext) ? $data->questiontext : (isset($data->text) ? $data->text : ''), 'format' => FORMAT_HTML];
                $question->defaultmark = isset($data->defaultmark) ? $data->defaultmark : 1;
                $question->category = $cat->id;
                $question->timecreated = time();
                $question->timemodified = time();
                $question->contextid = $cat->contextid;
                $question->context = context::instance_by_id($cat->contextid);
                $question->createdby = $USER->id;
                $question->modifiedby = $USER->id;

                $form_data = $question;

                // Type Specific Handling
                if ($data->type === 'truefalse') {
                    $question->correctanswer = $data->correctAnswer; 
                    $question->feedbacktrue = ['text' => '', 'format' => FORMAT_HTML];
                    $question->feedbackfalse = ['text' => '', 'format' => FORMAT_HTML];
                } 
                elseif ($data->type === 'multichoice') {
                    $question->single = isset($data->single) && $data->single ? 1 : 0;
                    $question->shuffleanswers = 1;
                    $question->answernumbering = 'abc';
                    
                    $question->answer = [];
                    $question->fraction = [];
                    $question->feedback = [];
                    
                    foreach ($data->answers as $ans) {
                        $question->answer[] = ['text' => $ans->text, 'format' => FORMAT_HTML];
                        $question->fraction[] = $ans->fraction;
                        $question->feedback[] = ['text' => '', 'format' => FORMAT_HTML];
                    }

                    // Combined Feedback Defaults
                    $question->correctfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->partiallycorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->incorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->shownumcorrect = 1;
                }
                elseif ($data->type === 'shortanswer') {
                     $question->usecase = 0; // Case insensitive
                     $question->answer = [];
                     $question->fraction = [];
                     $question->feedback = [];
 
                     foreach ($data->answers as $ans) {
                         $question->answer[] = $ans->text; // Plain string for shortanswer
                         $question->fraction[] = $ans->fraction;
                         $question->feedback[] = ['text' => '', 'format' => FORMAT_HTML];
                     }
                }
                elseif ($data->type === 'essay') {
                    $question->responseformat = 'editor';
                    $question->responsefieldlines = 15;
                    $question->attachments = 0;
                    $question->responserequired = 0; // Optional by default
                    $question->attachmentsrequired = 0;
                    $question->graderinfo = ['text' => '', 'format' => FORMAT_HTML];
                    $question->responsetemplate = ['text' => '', 'format' => FORMAT_HTML];
                }
                elseif ($data->type === 'numerical') {
                    $question->answer = [];
                    $question->fraction = [];
                    $question->tolerance = [];
                    $question->feedback = [];

                    foreach ($data->answers as $ans) {
                        $question->answer[] = $ans->text; // Numerical value
                        $question->fraction[] = $ans->fraction;
                        $question->tolerance[] = isset($ans->tolerance) ? $ans->tolerance : 0;
                        $question->feedback[] = ['text' => '', 'format' => FORMAT_HTML];
                    }
                    if (!empty($data->unit)) {
                        $question->unit = [$data->unit]; // Basic unit support
                        $question->multiplier = [1.0];
                    }
                }
                elseif ($data->type === 'match') {
                    $question->shuffleanswers = isset($data->shuffleanswers) && $data->shuffleanswers ? 1 : 0;
                    $question->subquestions = [];
                    $question->subanswers = [];
                    
                    foreach ($data->subquestions as $sub) {
                        if (!empty($sub->text) && !empty($sub->answer)) {
                            $question->subquestions[] = ['text' => $sub->text, 'format' => FORMAT_HTML];
                            $question->subanswers[] = $sub->answer;
                        }
                    }

                    // Combined Feedback Defaults (Required for Match)
                    $question->correctfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->partiallycorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->incorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->shownumcorrect = 1;
                }
                elseif ($data->type === 'gapselect' || $data->type === 'ddwtos') {
                    $question->shuffleanswers = isset($data->shuffleanswers) && $data->shuffleanswers ? 1 : 0;
                    
                    // Create $form_data following Moodle's internal form structure
                    $form_data = clone $question;
                    $form_data->choices = [];
                    // Flattened arrays for compatibility with internal save_question transformations
                    $form_data->draglabel = [];
                    $form_data->draggroup = [];
                    $form_data->infinite = [];

                    if (isset($data->answers) && is_array($data->answers)) {
                        $question->answer = [];
                        $question->feedback = [];
                        $question->fraction = [];
                        $question->selectgroup = []; // Added for 0-indexed gapselect groups
                        $question->draggroup = [];   // Added for 0-indexed ddwtos groups

                        foreach ($data->answers as $idx => $ans) {
                            $no = $idx + 1; // Moodle forms usually use 1-based indexing
                            $text = is_string($ans->text) ? $ans->text : ($ans->text->text ?? '');
                            $group = isset($ans->group) ? (int)$ans->group : 1;

                            $choice_entry = [
                                'answer' => $text,
                                'choicegroup' => $group,
                                'selectgroup' => $group,
                                'draggroup' => $group,
                                'infinite' => 0,
                                'choiceno' => $no
                            ];
                            if (!empty($ans->id)) $choice_entry['id'] = $ans->id;

                            // Debug log choice
                            error_log("GMK_QUIZ_DEBUG: Choice #$no (Group $group): $text");

                            // Extensive mapping for all qtype variations
                            // CRITICAL FIX: Use 0-based indexing for form_data arrays to match question->answer
                            // This prevents Moodle from corrupting [[n]] markers during save_question validation.
                            $form_data->choices[$idx] = $choice_entry; // Was $no
                            $form_data->choice[$idx] = $choice_entry;
                            $form_data->drags[$idx] = [
                                'label' => $text,
                                'draggroup' => $group,
                                'infinite' => 0
                            ];

                            $form_data->selectgroup[$idx] = $group; // Was $no
                            $form_data->draggroup[$idx] = $group;   // Was $no
                            $form_data->choicegroup[$idx] = $group; // Was $no
                            $form_data->draglabel[$idx] = $text;    // Was $no

                            if ($data->type === 'gapselect') {
                                $question->answer[] = $text;
                                $question->feedback[] = $group; // GapSelect expects the group number directly
                                $question->selectgroup[] = $group; // 0-indexed for Moodle's loop
                            } else {
                                $extra_settings = new stdClass();
                                $extra_settings->draggroup = $group;
                                $extra_settings->selectgroup = $group;
                                $extra_settings->infinite = 0;
                                $question->answer[] = $text;
                                $question->feedback[] = serialize($extra_settings);
                                $question->draggroup[] = $group; // 0-indexed
                            }
                            $question->fraction[] = 0.0;
                        }
                    }
                    
                    // Specific fields to ensure save_question sees EVERYTHING
                    $question->choices = $form_data->choices;
                    $question->shuffleanswers = $form_data->shuffleanswers;
                    
                    // Combined Feedback Defaults
                    $question->correctfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->partiallycorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->incorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->shownumcorrect = 1;

                    $form_data->correctfeedback = $question->correctfeedback;
                    $form_data->partiallycorrectfeedback = $question->partiallycorrectfeedback;
                    $form_data->incorrectfeedback = $question->incorrectfeedback;
                    $form_data->shownumcorrect = 1;
                }
                elseif ($data->type === 'multianswer') {
                     // No specific extra fields, code is in questiontext
                }
                elseif ($data->type === 'randomsamatch') {
                    $question->choose = isset($data->choose) ? $data->choose : 2;
                    $question->subcats = isset($data->subcats) && $data->subcats ? 1 : 0;
                    $question->shuffleanswers = 1;
                    
                    // Combined Feedback Defaults (Required to avoid NULL errors in DB)
                    $question->correctfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->partiallycorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->incorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->shownumcorrect = 1;
                }
                elseif (strpos($data->type, 'calculated') === 0) {
                     // Basic support for Calculated types
                     $question->answers = [];
                     $question->fraction = [];
                     $question->tolerance = [];
                     $question->tolerancetype = [];
                     $question->correctanswerlength = [];
                     $question->correctanswerformat = [];
                     $question->feedback = [];
                     
                     if (isset($data->answers) && is_array($data->answers)) {
                        foreach ($data->answers as $ans) {
                            $question->answer[] = $ans->text; // Formula
                            $question->fraction[] = isset($ans->fraction) ? (float)$ans->fraction : 1.0;
                            $question->tolerance[] = isset($ans->tolerance) ? $ans->tolerance : 0.01;
                            $question->tolerancetype[] = 1; // Relative
                            $question->correctanswerlength[] = 2; 
                            $question->correctanswerformat[] = 1; // Decimals
                            $question->feedback[] = ['text' => isset($ans->feedback) ? $ans->feedback : '', 'format' => FORMAT_HTML];
                        }
                     }
                     // Unit support
                     $question->unit = [isset($data->unit) ? $data->unit : ''];
                     $question->multiplier = [1.0];
                     
                     // Dataset Mapping (FIX: prevent corruption/missing items)
                     // Always initialize dataset array to avoid "undefined" behavior in save_question
                     $form_data->dataset = [];
                     
                     // 1. Auto-detect wildcards in formulas AND question text ALWAYS
                     // We merge this with any existing data to ensure nothing is lost during edits.
                     $detected_wildcards = [];
                     
                     // Scan Answer Formulas
                     foreach ($question->answer as $formula) {
                         if (preg_match_all('~\{([a-zA-Z0-9_]+)\}~', $formula, $matches)) {
                             foreach ($matches[1] as $wildcard) {
                                 $detected_wildcards[$wildcard] = true;
                             }
                         }
                     }
                     // Scan Question Text (Crucial so students can see the variables)
                     if (isset($question->questiontext['text'])) {
                         if (preg_match_all('~\{([a-zA-Z0-9_]+)\}~', $question->questiontext['text'], $matches)) {
                             foreach ($matches[1] as $wildcard) {
                                 $detected_wildcards[$wildcard] = true;
                             }
                         }
                     }
                     
                     // Merge detected wildcards into $data->dataset
                     if (!isset($data->dataset) || !is_array($data->dataset)) {
                         $data->dataset = [];
                     }
                     
                     // Map existing dataset names to avoid duplicates
                     $existing_names = [];
                     foreach ($data->dataset as $ds) {
                         if (isset($ds->name)) $existing_names[$ds->name] = true;
                     }
                     
                     // Add any newly detected wildcards that aren't in the dataset list
                     foreach (array_keys($detected_wildcards) as $wc) {
                         if (!isset($existing_names[$wc])) {
                             $ds = new stdClass();
                             $ds->name = $wc;
                             $ds->min = 1;
                             $ds->max = 10;
                             $ds->items = []; 
                             $data->dataset[] = $ds;
                             $existing_names[$wc] = true; // Mark as added
                         }
                     }

                     // Now process the dataset array to populate form_data
                     if (isset($data->dataset) && is_array($data->dataset)) {
                         foreach ($data->dataset as $ds) {
                              $name = $ds->name; // e.g. {x}
                              $form_data->dataset[] = $name;
                              
                              // We simulate the form data properties Moodle expects for each wildcard
                              $form_data->{"dataset_$name"} = '0'; // 0 = Reuse existing
                              $form_data->{"number_$name"} = (isset($ds->items) && !empty($ds->items)) ? count($ds->items) : 10;
                              $form_data->{"options_$name"} = 'uniform'; 
                              $form_data->{"calcmin_$name"} = isset($ds->min) ? $ds->min : 1;
                              $form_data->{"calcmax_$name"} = isset($ds->max) ? $ds->max : 10;
                              $form_data->{"calclength_$name"} = 1;
                              $form_data->{"calcdistribution_$name"} = isset($ds->distribution) ? $ds->distribution : 'uniform';
                         }
                     }
                     
                     // Options fields (FIXED: Required by qtype_calculated_options table)
                     $question->synchronize = 0;
                     $question->single = ($data->type === 'calculatedmulti') ? (isset($data->single) ? ($data->single ? 1 : 0) : 1) : 1;
                     $question->answernumbering = 'abc';
                     $question->shuffleanswers = isset($data->shuffleanswers) ? ($data->shuffleanswers ? 1 : 0) : 0;
                     
                     // Combined Feedback (Required fields for calculated_options)
                     $question->correctfeedback = ['text' => '', 'format' => FORMAT_HTML];
                     $question->partiallycorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
                     $question->incorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
                     $question->shownumcorrect = 1;
                }
                elseif ($data->type === 'ddimageortext' || $data->type === 'ddmarker') {
                    $question->shuffleanswers = 1;
                    
                    // Create $form_data following Moodle's internal form structure
                    $form_data = clone $question;
                    $form_data->drags = [];
                    $form_data->drops = [];
                    $form_data->draglabel = []; // Specific for ddimageortext
                    $form_data->dragitem = [];  // Specific for ddimageortext

                    // Ensure context compatibility
                    if (!empty($data->id)) {
                        $question->contextid = $old_question->contextid;
                        $form_data->contextid = $old_question->contextid;
                    }

                    // Background Image
                    if (!empty($_FILES['bgimage'])) {
                        $draftitemid = file_get_unused_draft_itemid();
                        $usercontext = context_user::instance($USER->id);
                        $fs = get_file_storage();
                        $filerecord = array(
                            'contextid' => $usercontext->id, 'component' => 'user', 'filearea' => 'draft',
                            'itemid' => $draftitemid, 'filepath' => '/', 'filename' => $_FILES['bgimage']['name']
                        );
                        $fs->create_file_from_pathname($filerecord, $_FILES['bgimage']['tmp_name']);
                        $question->bgimage = $draftitemid;
                        $form_data->bgimage = $draftitemid;
                    } elseif (!empty($data->id)) {
                        // Keep existing image if editing
                        $fs = get_file_storage();
                        $old_files = $fs->get_area_files($old_question->contextid, 'qtype_'.$data->type, 'bgimage', $old_question->id, 'id, itemid, filepath, filename', false);
                        if (!empty($old_files)) {
                            $old_file = reset($old_files);
                            $draftitemid = file_get_unused_draft_itemid();
                            $usercontext = context_user::instance($USER->id);
                            $filerecord = array(
                                'contextid' => $usercontext->id, 'component' => 'user', 'filearea' => 'draft',
                                'itemid' => $draftitemid, 'filepath' => '/', 'filename' => $old_file->get_filename()
                            );
                            $fs->create_file_from_storedfile($filerecord, $old_file);
                            $question->bgimage = $draftitemid;
                            $form_data->bgimage = $draftitemid;
                        }
                    }

                    // Process Draggables
                    if (isset($data->draggables) && is_array($data->draggables)) {
                        foreach ($data->draggables as $idx => $drag) {
                            $no = $idx;
                            $label = !empty($drag->text) ? (string)$drag->text : ' ';
                            
                            if ($data->type === 'ddimageortext') {
                                $form_data->draglabel[$no] = $label;
                                $form_data->dragitem[$no] = 0;
                                $form_data->drags[$no] = [
                                    'draggroup' => isset($drag->group) ? (int)$drag->group : 1,
                                    'infinite' => !empty($drag->infinite) ? 1 : 0,
                                    'dragitemtype' => 'text'
                                ];
                            } else {
                                $form_data->drags[$no] = [
                                    'label' => $label,
                                    'noofdrags' => !empty($drag->infinite) ? 0 : 1
                                ];
                            }
                        }
                    }

                    // Process Drops
                    if (isset($data->drops) && is_array($data->drops)) {
                        foreach ($data->drops as $idx => $d) {
                            $no = $idx;
                            if ($data->type === 'ddimageortext') {
                                $form_data->drops[$no] = [
                                    'choice' => (int)$d->choice,
                                    'xleft' => (int)$d->x,
                                    'ytop' => (int)$d->y,
                                    'droplabel' => 'drop' . ($no + 1)
                                ];
                            } else {
                                $form_data->drops[$no] = [
                                    'choice' => (int)$d->choice,
                                    'shape' => 'circle',
                                    'coords' => sprintf('%d,%d;15', (int)$d->x, (int)$d->y)
                                ];
                            }
                        }
                    }
                    
                    $question->correctfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->partiallycorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->incorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
                    $question->shownumcorrect = 1;

                    $form_data->correctfeedback = $question->correctfeedback;
                    $form_data->partiallycorrectfeedback = $question->partiallycorrectfeedback;
                    $form_data->incorrectfeedback = $question->incorrectfeedback;
                    $form_data->shownumcorrect = 1;
                    
                    if ($data->type === 'ddmarker') {
                        $form_data->showmisplaced = 1;
                    }
                }
                // ADD LOGGING: Final state of questiontext before saving
                error_log("GMK_QUIZ_DEBUG: Final Question Text to DB: " . (isset($question->questiontext['text']) ? $question->questiontext['text'] : 'NOT SET'));

                // SAVE or USE EXISTING (Duplicate detection)
                $newq = null;
                if (empty($data->id)) {
                    // Search for identical question in the target category (Duplicate detection)
                    $existing = $DB->get_record_sql("
                        SELECT q.id 
                        FROM {question} q
                        JOIN {question_versions} qv ON qv.questionid = q.id
                        JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                        WHERE qbe.questioncategoryid = :categoryid
                        AND q.qtype = :qtype
                        AND q.questiontext = :questiontext
                        AND q.parent = 0
                        ORDER BY qv.version DESC
                        LIMIT 1", [
                            'categoryid' => $question->category,
                            'qtype' => $question->qtype,
                            'questiontext' => $question->questiontext['text']
                        ]);

                    if ($existing) {
                        $newq = $existing;
                    }
                }

                if (!$newq) {
                    gmk_log("Calling save_question for type: " . $question->qtype);
                    $qtypeobj = question_bank::get_qtype($question->qtype);
                    $newq = $qtypeobj->save_question($question, $form_data);
                    gmk_log("save_question returned ID: " . ($newq ? $newq->id : 'NULL'));
                }

                // Post-Save: Generate Items for Calculated Questions
                // SELF-HEALING LOGIC: Detects what SHOULD be there and ensures it exists in DB.
                // Relaxed condition: Check question object type OR input data type
                $is_calculated = (isset($question->qtype) && strpos($question->qtype, 'calculated') === 0) 
                              || (isset($data->type) && strpos($data->type, 'calculated') === 0);
                
                gmk_log("Checking Self-Healing Condition: IsCalculated=" . ($is_calculated ? 'YES' : 'NO') . ", NewQ=" . ($newq ? 'YES' : 'NO'));

                if ($newq && $is_calculated) {
                    gmk_log("GMK_QUIZ_DEBUG: Entering Calculated Self-Healing for QID " . $newq->id);
                    
                    // STRATEGY: Find ALL versions of this question (siblings) and fix them ALL.
                    // This handles cases where Moodle creates a new version (ID+1) that we missed.
                    $all_versions = [];
                    $all_versions[] = $newq->id; // Always include the current one
                    
                    try {
                        // Find Question Bank Entry ID AND Category (Moodle 4.x)
                        $entry_data = $DB->get_record_sql("
                            SELECT qv.questionbankentryid, qbe.questioncategoryid 
                            FROM {question_versions} qv 
                            JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                            WHERE qv.questionid = :qid", ['qid' => $newq->id]);
                            
                        if ($entry_data) {
                            $entry_id = $entry_data->questionbankentryid;
                            $master_category = $entry_data->questioncategoryid;
                            
                            $siblings = $DB->get_records_sql("
                                SELECT questionid 
                                FROM {question_versions} 
                                WHERE questionbankentryid = :entryid
                                ORDER BY version DESC
                                LIMIT 5", ['entryid' => $entry_id]); 
                                
                            foreach ($siblings as $sib) {
                                $all_versions[] = $sib->questionid;
                            }
                        } else {
                            $master_category = $newq->category; // Fallback
                        }
                    } catch (Exception $e) {
                        gmk_log("Error fetching siblings/category: " . $e->getMessage());
                        $master_category = $newq->category; // Fallback
                    }
                    
                    $all_versions = array_unique($all_versions);
                    gmk_log("Targeting Versions for Repair: " . implode(', ', $all_versions) . " (Category: $master_category)");

                    // LOOP THROUGH ALL VERSIONS
                    foreach ($all_versions as $target_qid) {
                        gmk_log("--- Repairing QID: $target_qid ---");
                        
                        // DIRECT DB QUERY STRATEGY
                        $expected_wildcards = [];
                        
                        // 1. Scan Answers directly from DB (Answer + Feedback)
                        $db_answers = $DB->get_records('question_answers', ['question' => $target_qid]);
                        
                        if ($db_answers) {
                            foreach ($db_answers as $ans) {
                                 // Check Answer Formula
                                 if (preg_match_all('~\{([a-zA-Z0-9_]+)\}~', $ans->answer, $matches)) {
                                     foreach ($matches[1] as $wc) $expected_wildcards[$wc] = true;
                                 }
                                 // Check Feedback (Crucial for Calculated Multi)
                                 if (isset($ans->feedback) && preg_match_all('~\{([a-zA-Z0-9_]+)\}~', $ans->feedback, $matches)) {
                                     foreach ($matches[1] as $wc) $expected_wildcards[$wc] = true;
                                 }
                            }
                        }
                        
                        // 2. Scan Question Text + General Feedback directly from DB
                        // corrected: 'category' is NOT in {question} table in Moodle 4+
                        $db_q = $DB->get_record('question', ['id' => $target_qid], 'questiontext, generalfeedback'); 
                        if ($db_q) {
                             if (isset($db_q->questiontext) && preg_match_all('~\{([a-zA-Z0-9_]+)\}~', $db_q->questiontext, $matches)) {
                                 foreach ($matches[1] as $wc) $expected_wildcards[$wc] = true;
                             }
                             if (isset($db_q->generalfeedback) && preg_match_all('~\{([a-zA-Z0-9_]+)\}~', $db_q->generalfeedback, $matches)) {
                                 foreach ($matches[1] as $wc) $expected_wildcards[$wc] = true;
                             }
                        }
                        
                        // Log detection for debugging
                        gmk_log("Detected wildcards for QID {$target_qid}: " . implode(', ', array_keys($expected_wildcards)));
    
                        foreach (array_keys($expected_wildcards) as $name) {
                             // 1. Find Definition
                             $def = $DB->get_record_sql("
                                SELECT * FROM {question_dataset_definitions} 
                                WHERE name = ? AND (category = 0 OR category = ?)
                                ORDER BY id DESC LIMIT 1
                             ", [$name, $master_category]);
                             
                             // 2. Create Definition if missing
                             if (!$def) {
                                 $def = new stdClass();
                                 $def->xmlid = '';
                                 $def->category = 0; 
                                 $def->name = $name;
                                 $def->type = 1; 
                                 $def->options = 'uniform'; 
                                 $def->itemcount = 0;
                                 $def->id = $DB->insert_record('question_dataset_definitions', $def);
                                 gmk_log("Created NEW Definition '{$name}' (ID: {$def->id})");
                             }
                             
                             // 3. Ensure Link exists
                             if (!$DB->record_exists('question_datasets', ['question' => $target_qid, 'datasetdefinition' => $def->id])) {
                                $link = new stdClass();
                                $link->question = $target_qid;
                                $link->datasetdefinition = $def->id;
                                $DB->insert_record('question_datasets', $link);
                                gmk_log("Restored Link Q {$target_qid} -> Def {$def->id}");
                             }
                             
                             // 4. Ensure Items exist
                             if ($DB->count_records('question_dataset_items', ['definition' => $def->id]) == 0) {
                                 // ... (Generation logic) ...
                                 // Simplified generation for siblings (using defaults)
                                 for ($i = 1; $i <= 10; $i++) {
                                     $val = 1.0 + (9.0 * (mt_rand() / mt_getrandmax()));
                                     $val = round($val, 1);
                                     $item = new stdClass();
                                     $item->definition = $def->id;
                                     $item->itemnumber = $i;
                                     $item->value = $val;
                                     $DB->insert_record('question_dataset_items', $item);
                                 }
                                 $DB->set_field('question_dataset_definitions', 'itemcount', 10, ['id' => $def->id]);
                                 gmk_log("Generated items for '{$name}'");
                             }
                        }
                    } // End foreach version
                    
                    // Force refresh main question instance
                    $newq = question_bank::load_question($newq->id);
                }

                // If editing (id is present), ensure the question_bank_entry is moved if category changed
                if (!empty($data->id)) {
                    // In Moodle 4.0+, the category is in question_bank_entries
                    $entryid = $DB->get_field_sql("
                        SELECT qv.questionbankentryid 
                        FROM {question_versions} qv 
                        WHERE qv.questionid = :questionid", ['questionid' => $newq->id]);
                    
                    if ($entryid) {
                        $DB->set_field('question_bank_entries', 'questioncategoryid', $question->category, ['id' => $entryid]);
                    }
                }
                
                // ADD TO QUIZ (Only if new to the quiz)
                if (empty($data->id)) {
                    // Moodle 4.0+: Check if question is already in the quiz
                    $already_in_quiz = $DB->record_exists_sql("
                        SELECT s.id 
                        FROM {quiz_slots} s
                        JOIN {question_references} qr ON qr.itemid = s.id
                        JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                        JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                        WHERE s.quizid = :quizid 
                        AND qv.questionid = :questionid
                        AND qr.component = 'mod_quiz'
                        AND qr.questionarea = 'slot'
                    ", ['quizid' => $quiz->id, 'questionid' => $newq->id]);

                    if (!$already_in_quiz) {
                        quiz_add_quiz_question($newq->id, $quiz, 0, $question->defaultmark);
                        
                        // Force update sumgrades
                        quiz_update_sumgrades($quiz);
                    }
                }

                $response = ['status' => 'success', 'id' => $newq->id];

            } catch (Throwable $e) {
                // Return clear error message
                $response = ['status' => 'error', 'message' => 'Error al guardar: ' . $e->getMessage()];
                if (debugging()) {
                    $response['debug'] = $e->getTraceAsString();
                }
            }
            break;

        case 'local_grupomakro_sync_quiz_grades':
            $cmid = required_param('cmid', PARAM_INT);
            try {
                require_once($CFG->dirroot . '/mod/quiz/locallib.php');
                $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
                $quiz = $DB->get_record('quiz', array('id' => $cm->instance), '*', MUST_EXIST);
                
                // Get all slots and their questions
                $sql = "SELECT s.id as slotid, q.defaultmark
                        FROM {quiz_slots} s
                        JOIN {question_references} qr ON qr.itemid = s.id
                        JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
                        JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                        JOIN {question} q ON q.id = qv.questionid
                        WHERE s.quizid = :quizid
                        AND qr.component = 'mod_quiz'
                        AND qr.questionarea = 'slot'
                        AND qv.version = (SELECT MAX(v.version) FROM {question_versions} v WHERE v.questionbankentryid = qbe.id)";
                
                $slots = $DB->get_records_sql($sql, ['quizid' => $quiz->id]);
                foreach ($slots as $s) {
                    $DB->set_field('quiz_slots', 'maxmark', $s->defaultmark, ['id' => $s->slotid]);
                }
                
                quiz_update_sumgrades($quiz);
                
                $response = ['status' => 'success'];
            } catch (Exception $e) {
                $response = ['status' => 'error', 'message' => $e->getMessage()];
            }
            break;

        case 'local_grupomakro_get_categories':
            try {
                $cmid = required_param('cmid', PARAM_INT);
                $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
                $course_context = context_course::instance($cm->course);
                $system_context = context_system::instance();
                
                // Fetch categories from course and system contexts
                $sql = "SELECT id, name, parent FROM {question_categories} 
                        WHERE contextid IN (:coursecontext, :systemcontext)
                        ORDER BY name ASC";
                $categories = $DB->get_records_sql($sql, [
                    'coursecontext' => $course_context->id,
                    'systemcontext' => $system_context->id
                ]);
                
                // Add question count for each category
                foreach ($categories as $cat) {
                    $cat->questioncount = $DB->count_records('question_bank_entries', ['questioncategoryid' => $cat->id]);
                }
                
                $response = ['status' => 'success', 'categories' => array_values($categories)];
            } catch (Exception $e) {
                $response = ['status' => 'error', 'message' => $e->getMessage()];
            }
            break;

        case 'local_grupomakro_get_bank_questions':
            try {
                $categoryid = required_param('categoryid', PARAM_INT);
                
                // Moodle 4.0 Query for questions in category
                $sql = "SELECT q.id, q.name, q.questiontext, q.qtype
                        FROM {question} q
                        JOIN {question_versions} qv ON qv.questionid = q.id
                        JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                        WHERE qbe.questioncategoryid = :categoryid
                        AND qv.version = (
                            SELECT MAX(v.version)
                            FROM {question_versions} v
                            WHERE v.questionbankentryid = qbe.id
                        )
                        AND q.parent = 0
                        ORDER BY q.name ASC";
                
                $questions = $DB->get_records_sql($sql, ['categoryid' => $categoryid]);
                
                $clean_questions = [];
                foreach ($questions as $q) {
                    $clean_questions[] = [
                        'id' => $q->id,
                        'name' => $q->name,
                        'questiontext' => strip_tags($q->questiontext),
                        'qtype' => $q->qtype
                    ];
                }
                
                $response = ['status' => 'success', 'questions' => $clean_questions];
            } catch (Exception $e) {
                $response = ['status' => 'error', 'message' => $e->getMessage()];
            }
            break;

        case 'local_grupomakro_add_bank_question':
            try {
                require_once($CFG->dirroot . '/mod/quiz/locallib.php');
                $cmid = required_param('cmid', PARAM_INT);
                $questionid = required_param('questionid', PARAM_INT);
                
                $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
                $quiz = $DB->get_record('quiz', array('id' => $cm->instance), '*', MUST_EXIST);
                
                // Add to quiz using standard API
                quiz_add_quiz_question($questionid, $quiz);
                
                quiz_update_sumgrades($quiz);
                
                $response = ['status' => 'success'];
            } catch (Exception $e) {
                $response = ['status' => 'error', 'message' => $e->getMessage()];
            }
            break;

        case 'local_grupomakro_remove_quiz_question':
            $cmid = required_param('cmid', PARAM_INT);
            $slot = required_param('slot', PARAM_INT);
            try {
                require_once($CFG->dirroot . '/mod/quiz/locallib.php');
                $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
                $quiz = $DB->get_record('quiz', array('id' => $cm->instance), '*', MUST_EXIST);
                $course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
                
                // Moodle 4.0+ Compatible Slot Removal: Requires a 'quiz' object wrapper
                $quizobj = new quiz($quiz, $cm, $course);
                $structure = \mod_quiz\structure::create_for_quiz($quizobj);
                $structure->remove_slot($slot);
                
                $response = ['status' => 'success'];
            } catch (Exception $e) {
                $response = ['status' => 'error', 'message' => $e->getMessage()];
            }
            break;


        case 'local_grupomakro_create_express_activity':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/teacher/create_express_activity.php');
            $classid = required_param('classid', PARAM_INT);
            $type = required_param('type', PARAM_ALPHA);
            $name = required_param('name', PARAM_TEXT);
            $intro = optional_param('intro', '', PARAM_RAW);
            $duedate = optional_param('duedate', 0, PARAM_INT);
            $save_as_template = optional_param('save_as_template', false, PARAM_BOOL);
            $gradecat = optional_param('gradecat', 0, PARAM_INT);
            $guest = optional_param('guest', false, PARAM_BOOL);
            $timeopen = optional_param('timeopen', 0, PARAM_INT);
            $timeclose = optional_param('timeclose', 0, PARAM_INT);
            $timelimit = optional_param('timelimit', 0, PARAM_INT);
            $attempts = optional_param('attempts', 1, PARAM_INT);
            $grademethod = optional_param('grademethod', 1, PARAM_INT);
            $forumtopic = optional_param('forumtopic', '', PARAM_TEXT);
            $forummessage = optional_param('forummessage', '', PARAM_RAW);
            $forumcreateinitial = optional_param('forumcreateinitial', true, PARAM_BOOL);

            // Normalize tags Ã¢â‚¬â€ may arrive as string (FormData/JSON) or array (JSON flattened)
            $raw_tags = isset($_POST['tags']) ? $_POST['tags'] : '';
            $tagList = gmk_ajax_extract_tags_from_request($raw_tags);
            if (!empty($tagList)) {
                $tagList = [reset($tagList)];
            }

            try {
                $result = \local_grupomakro_core\external\teacher\create_express_activity::execute(
                    $classid,
                    $type,
                    $name,
                    $intro,
                    $duedate,
                    $save_as_template,
                    [],
                    $gradecat,
                    $guest,
                    $timeopen,
                    $timeclose,
                    $timelimit,
                    $attempts,
                    $grademethod,
                    $forumtopic,
                    $forummessage,
                    $forumcreateinitial
                );

                // Propagate nested backend errors instead of reporting false success.
                if (!is_array($result) || ($result['status'] ?? 'error') !== 'success' || empty($result['cmid'])) {
                    $response = [
                        'status' => 'error',
                        'message' => is_array($result) ? ($result['message'] ?? 'No se pudo crear la actividad.') : 'Respuesta invalida al crear actividad.',
                        'data' => $result
                    ];
                    break;
                }

                // Apply tags from ajax.php directly (avoids validate_parameters type issues)
                if (!empty($result['cmid']) && !empty($tagList)) {
                    $new_cm_tags = get_coursemodule_from_id('', (int)$result['cmid'], 0, false, MUST_EXIST);
                    $new_ctx_tags = context_module::instance($new_cm_tags->id);
                    gmk_safe_set_item_tags($new_cm_tags->id, $new_ctx_tags, $tagList);
                }

                // Handle file attachments for supported module types
                $modname_create = ($type === 'assignment') ? 'assign' : $type;
                $fileinfo_new = gmk_get_module_fileinfo($modname_create);
                if ($fileinfo_new && !empty($result['cmid'])) {
                    $new_cmid = (int)$result['cmid'];
                    $new_cm = get_coursemodule_from_id('', $new_cmid, 0, false, MUST_EXIST);
                    $new_ctx = context_module::instance($new_cm->id);
                    $fs_new = get_file_storage();
                    $fi_comp = $fileinfo_new['component'];
                    $fi_area = $fileinfo_new['filearea'];
                    $fi_item = $fileinfo_new['itemid'];

                    // Draft-based uploads (new flow via local_grupomakro_upload_draft_file)
                    $raw_drafts = isset($_POST['draftitemids']) ? $_POST['draftitemids'] : '';
                    $draftitemids_create = [];
                    if (is_array($raw_drafts)) {
                        $draftitemids_create = array_map('intval', $raw_drafts);
                    } elseif (!empty($raw_drafts)) {
                        $draftitemids_create = array_map('intval', explode(',', (string)$raw_drafts));
                    }
                    if (!empty($draftitemids_create)) {
                        $usercontext_create = context_user::instance($USER->id);
                        foreach ($draftitemids_create as $draftid) {
                            if (!$draftid) continue;
                            $draft_files = $fs_new->get_area_files($usercontext_create->id, 'user', 'draft', $draftid, 'id', false);
                            foreach ($draft_files as $draft_file) {
                                $fname = gmk_ajax_make_unique_filename(
                                    $fs_new,
                                    (int)$new_ctx->id,
                                    (string)$fi_comp,
                                    (string)$fi_area,
                                    (int)$fi_item,
                                    '/',
                                    (string)$draft_file->get_filename()
                                );
                                $fs_new->create_file_from_storedfile([
                                    'contextid' => $new_ctx->id,
                                    'component' => $fi_comp,
                                    'filearea'  => $fi_area,
                                    'itemid'    => $fi_item,
                                    'filepath'  => '/',
                                    'filename'  => $fname,
                                    'userid'    => $USER->id,
                                ], $draft_file);
                                $draft_file->delete();
                            }
                        }
                    }

                    // Fallback: direct $_FILES upload (legacy/compatibility)
                    foreach ($_FILES as $fkey => $finfo) {
                        if (strpos($fkey, 'resource_file_') !== 0) continue;
                        if ($finfo['error'] !== UPLOAD_ERR_OK) continue;
                        $fname = gmk_ajax_make_unique_filename(
                            $fs_new,
                            (int)$new_ctx->id,
                            (string)$fi_comp,
                            (string)$fi_area,
                            (int)$fi_item,
                            '/',
                            (string)$finfo['name']
                        );
                        $fs_new->create_file_from_pathname([
                            'contextid' => $new_ctx->id,
                            'component' => $fi_comp,
                            'filearea'  => $fi_area,
                            'itemid'    => $fi_item,
                            'filepath'  => '/',
                            'filename'  => $fname,
                            'userid'    => $USER->id,
                        ], $finfo['tmp_name']);
                    }

                    $cols_new = $DB->get_columns($new_cm->modname);
                    if (isset($cols_new['revision'])) {
                        $DB->set_field($new_cm->modname, 'revision', time(), ['id' => $new_cm->instance]);
                    }
                }

                $response = ['status' => 'success', 'data' => $result];
            } catch (\Throwable $e) {
                 $response = ['status' => 'error', 'message' => $e->getMessage()];
            }
            break;

        case 'local_grupomakro_get_attendance_sessions':
             require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/teacher/attendance_manager.php');
             $classid = required_param('classid', PARAM_INT);
             try {
                $response = \local_grupomakro_core\external\teacher\attendance_manager::get_sessions($classid);
             } catch (Exception $e) {
                $response = ['status' => 'error', 'message' => $e->getMessage()];
             }
             break;

        case 'local_grupomakro_get_session_qr':
             require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/teacher/attendance_manager.php');
             $sessionid = required_param('sessionid', PARAM_INT);
             try {
                $response = \local_grupomakro_core\external\teacher\attendance_manager::get_qr($sessionid);
             } catch (Exception $e) {
                $response = ['status' => 'error', 'message' => $e->getMessage()];
             }
             break;

        case 'local_grupomakro_get_bbb_join_url':
            $cmid = optional_param('cmid', 0, PARAM_INT);
            $classid = optional_param('classid', 0, PARAM_INT);
            $sessionid = optional_param('sessionid', 0, PARAM_INT);
            $debug = [];
            $resolvedrelationid = 0;
            $resolvedrelationbbbid = 0;
            $resolvedrelationbbbcmid = 0;
            try {
                // Fallback resolver when frontend has no bbb_cmid for the session.
                if ($cmid <= 0 && $classid > 0 && $sessionid > 0) {
                    $debug[] = 'resolve_start classid=' . (int)$classid . ' sessionid=' . (int)$sessionid;
                    $rel = $DB->get_record_sql(
                        "SELECT id, bbbmoduleid, bbbid
                           FROM {gmk_bbb_attendance_relation}
                          WHERE classid = :classid
                            AND attendancesessionid = :sessionid
                       ORDER BY id DESC",
                        ['classid' => (int)$classid, 'sessionid' => (int)$sessionid],
                        IGNORE_MULTIPLE
                    );

                    if ($rel) {
                        $resolvedrelationid = (int)$rel->id;
                        $resolvedrelationbbbid = (int)($rel->bbbid ?? 0);
                        $resolvedrelationbbbcmid = (int)($rel->bbbmoduleid ?? 0);
                        $debug[] = 'relation_id=' . (int)$rel->id;
                        if (!empty($rel->bbbmoduleid)) {
                            $cand = (int)$rel->bbbmoduleid;
                            $cmrow = $DB->get_record_sql(
                                "SELECT cm.id, m.name AS modulename
                                   FROM {course_modules} cm
                                   JOIN {modules} m ON m.id = cm.module
                                  WHERE cm.id = :cmid",
                                ['cmid' => $cand],
                                IGNORE_MISSING
                            );
                            if ($cmrow && $cmrow->modulename === 'bigbluebuttonbn') {
                                $cmid = $cand;
                                $debug[] = 'resolved_by_relation_bbbmoduleid=' . $cmid;
                            } else {
                                $debug[] = 'relation_bbbmoduleid_invalid=' . $cand;
                            }
                        }
                        if ($cmid <= 0 && !empty($rel->bbbid)) {
                            $bbbid = (int)$rel->bbbid;
                            $cmrow = $DB->get_record_sql(
                                "SELECT cm.id
                                   FROM {course_modules} cm
                                   JOIN {modules} m ON m.id = cm.module
                                  WHERE m.name = 'bigbluebuttonbn'
                                    AND cm.instance = :instanceid
                               ORDER BY cm.id DESC",
                                ['instanceid' => $bbbid],
                                IGNORE_MULTIPLE
                            );
                            if ($cmrow) {
                                $cmid = (int)$cmrow->id;
                                $debug[] = 'resolved_by_relation_bbbid=' . $cmid;
                            } else {
                                $debug[] = 'relation_bbbid_not_found=' . $bbbid;
                            }
                        }
                    } else {
                        $debug[] = 'relation_not_found';
                    }

                    // Last fallback: use class.bbbmoduleids and closest event time.
                    if ($cmid <= 0) {
                        $classrow = $DB->get_record('gmk_class', ['id' => (int)$classid], 'id, corecourseid, bbbmoduleids', IGNORE_MISSING);
                        if ($classrow && !empty($classrow->bbbmoduleids)) {
                            $candcmids = [];
                            foreach (explode(',', (string)$classrow->bbbmoduleids) as $raw) {
                                $id = (int)trim((string)$raw);
                                if ($id > 0) {
                                    $candcmids[$id] = $id;
                                }
                            }
                            if (!empty($candcmids)) {
                                list($cminsql, $cmparams) = $DB->get_in_or_equal(array_values($candcmids), SQL_PARAMS_NAMED, 'cmidres');
                                $cmrows = $DB->get_records_sql(
                                    "SELECT cm.id, cm.instance, m.name AS modulename
                                       FROM {course_modules} cm
                                       JOIN {modules} m ON m.id = cm.module
                                      WHERE cm.id $cminsql",
                                    $cmparams
                                );
                                $valid = [];
                                foreach ($cmrows as $row) {
                                    if ($row->modulename === 'bigbluebuttonbn') {
                                        $valid[(int)$row->id] = (int)$row->instance;
                                    }
                                }
                                if (count($valid) === 1) {
                                    $cmid = (int)array_key_first($valid);
                                    $debug[] = 'resolved_by_class_single=' . $cmid;
                                } else if (count($valid) > 1) {
                                    $session = $DB->get_record('attendance_sessions', ['id' => (int)$sessionid], 'id, sessdate', IGNORE_MISSING);
                                    $target = (int)($session->sessdate ?? 0);
                                    if ($target > 0) {
                                        $instmap = [];
                                        foreach ($valid as $vcmid => $instanceid) {
                                            if ($instanceid > 0) {
                                                $instmap[(int)$instanceid] = (int)$vcmid;
                                            }
                                        }
                                        if (!empty($instmap)) {
                                            list($insql, $inparams) = $DB->get_in_or_equal(array_keys($instmap), SQL_PARAMS_NAMED, 'instres');
                                            $evrows = $DB->get_records_sql(
                                                "SELECT id, instance, timestart
                                                   FROM {event}
                                                  WHERE modulename = 'bigbluebuttonbn'
                                                    AND instance $insql
                                               ORDER BY timestart ASC",
                                                $inparams
                                            );
                                            $bestcmid = 0;
                                            $bestdiff = PHP_INT_MAX;
                                            foreach ($evrows as $ev) {
                                                $instanceid = (int)$ev->instance;
                                                if (!isset($instmap[$instanceid])) {
                                                    continue;
                                                }
                                                $diff = abs((int)$ev->timestart - $target);
                                                if ($diff < $bestdiff) {
                                                    $bestdiff = $diff;
                                                    $bestcmid = (int)$instmap[$instanceid];
                                                }
                                            }
                                            if ($bestcmid > 0) {
                                                $cmid = $bestcmid;
                                                $debug[] = 'resolved_by_class_event_match=' . $cmid . ' diff=' . $bestdiff;
                                            }
                                        }
                                    }
                                    if ($cmid <= 0) {
                                        $cmid = (int)array_key_first($valid);
                                        $debug[] = 'resolved_by_class_first=' . $cmid;
                                    }
                                }
                            }
                        }
                    }
                }

                if ($cmid <= 0) {
                    $response = [
                        'status' => 'error',
                        'message' => 'No BBB module linked to this session.',
                        'debug' => $debug
                    ];
                    break;
                }

                $cmcolumns = $DB->get_columns('course_modules');
                $hasdeletion = isset($cmcolumns['deletioninprogress']);
                $deletionselect = $hasdeletion ? ', cm.deletioninprogress AS deletioninprogress' : ', 0 AS deletioninprogress';
                $cm = $DB->get_record_sql(
                    "SELECT cm.id, cm.instance, m.name AS modulename{$deletionselect}
                       FROM {course_modules} cm
                       JOIN {modules} m ON m.id = cm.module
                      WHERE cm.id = :cmid",
                    ['cmid' => (int)$cmid],
                    IGNORE_MISSING
                );
                if (!$cm || $cm->modulename !== 'bigbluebuttonbn') {
                    $response = [
                        'status' => 'error',
                        'message' => 'Invalid BBB module reference.',
                        'debug' => array_merge($debug, ['cmid=' . (int)$cmid])
                    ];
                    break;
                }
                if (!empty($cm->deletioninprogress)) {
                    $response = [
                        'status' => 'error',
                        'message' => 'BBB module is being deleted.',
                        'debug' => array_merge($debug, ['cmid=' . (int)$cmid, 'deletioninprogress=1'])
                    ];
                    break;
                }

                // Persist repaired relation mapping when we resolved by session context.
                if ($resolvedrelationid > 0 && $sessionid > 0) {
                    $needsupdate = ((int)$resolvedrelationbbbcmid !== (int)$cmid);
                    if (!$needsupdate || $resolvedrelationbbbid <= 0) {
                        $instancerow = $DB->get_record('course_modules', ['id' => (int)$cmid], 'instance', IGNORE_MISSING);
                        $resolvedinstance = $instancerow ? (int)$instancerow->instance : 0;
                        if ($resolvedrelationbbbid <= 0 && $resolvedinstance > 0) {
                            $needsupdate = true;
                        }
                    }
                    if ($needsupdate) {
                        $instancerow = $DB->get_record('course_modules', ['id' => (int)$cmid], 'instance', IGNORE_MISSING);
                        $resolvedinstance = $instancerow ? (int)$instancerow->instance : 0;
                        $row = new stdClass();
                        $row->id = (int)$resolvedrelationid;
                        $row->bbbmoduleid = (int)$cmid;
                        if ($resolvedinstance > 0) {
                            $row->bbbid = (int)$resolvedinstance;
                        }
                        $row->timemodified = time();
                        $DB->update_record('gmk_bbb_attendance_relation', $row);
                        $debug[] = 'relation_updated id=' . (int)$resolvedrelationid . ' bbbmoduleid=' . (int)$cmid
                            . ($resolvedinstance > 0 ? (' bbbid=' . (int)$resolvedinstance) : '');
                    }
                } else if ($classid > 0 && $sessionid > 0) {
                    $existsrelation = $DB->record_exists('gmk_bbb_attendance_relation', [
                        'classid' => (int)$classid,
                        'attendancesessionid' => (int)$sessionid
                    ]);
                    if (!$existsrelation) {
                        $classforrel = $DB->get_record('gmk_class', ['id' => (int)$classid], 'id, attendancemoduleid, coursesectionid', IGNORE_MISSING);
                        $sessionforrel = $DB->get_record('attendance_sessions', ['id' => (int)$sessionid], 'id, attendanceid', IGNORE_MISSING);
                        $instancerow = $DB->get_record('course_modules', ['id' => (int)$cmid], 'instance', IGNORE_MISSING);
                        $newrel = new stdClass();
                        $newrel->classid = (int)$classid;
                        $newrel->attendancesessionid = (int)$sessionid;
                        $newrel->bbbmoduleid = (int)$cmid;
                        $newrel->bbbid = $instancerow ? (int)$instancerow->instance : 0;
                        $newrel->attendanceid = $sessionforrel ? (int)$sessionforrel->attendanceid : 0;
                        $newrel->attendancemoduleid = $classforrel ? (int)$classforrel->attendancemoduleid : 0;
                        $newrel->sectionid = $classforrel ? (int)$classforrel->coursesectionid : 0;
                        $newrel->usermodified = (int)$USER->id;
                        $newrel->timecreated = time();
                        $newrel->timemodified = time();
                        $newid = $DB->insert_record('gmk_bbb_attendance_relation', $newrel);
                        $debug[] = 'relation_inserted id=' . (int)$newid . ' bbbmoduleid=' . (int)$cmid;
                    }
                }

                try {
                    $result = \mod_bigbluebuttonbn\external\get_join_url::execute((int)$cmid);
                    $joinurl = trim((string)($result['join_url'] ?? ''));
                    $warnings = (isset($result['warnings']) && is_array($result['warnings'])) ? $result['warnings'] : [];
                    if ($joinurl !== '') {
                        $response = [
                            'status' => 'success',
                            'join_url' => $joinurl,
                            'source' => 'ws_join_url',
                            'debug' => $debug
                        ];
                    } else {
                        $warningtext = '';
                        if (!empty($warnings[0]['message'])) {
                            $warningtext = (string)$warnings[0]['message'];
                        } else if (!empty($warnings[0]['warningcode'])) {
                            $warningtext = (string)$warnings[0]['warningcode'];
                        }
                        $message = 'Join URL empty from BBB service.';
                        if ($warningtext !== '') {
                            $message .= ' ' . $warningtext;
                        }
                        $response = [
                            'status' => 'error',
                            'source' => 'ws_join_url_empty',
                            'message' => $message,
                            'warnings' => $warnings,
                            'debug' => $debug
                        ];
                    }
                } catch (\Throwable $t) {
                    $response = [
                        'status' => 'error',
                        'source' => 'ws_join_url_exception',
                        'message' => 'BBB join service failed. ' . $t->getMessage(),
                        'debug' => $debug
                    ];
                }
            } catch (\Throwable $e) {
                $response = ['status' => 'error', 'message' => $e->getMessage(), 'debug' => $debug];
            }
            break;

        case 'get_sync_log':
            $logFile = make_temp_directory('grupomakro') . '/sync_progress.log';
            if (file_exists($logFile)) {
                $response['status'] = 'success';
                $response['log'] = file_get_contents($logFile);
                $response['message'] = 'Log retrieved.';
            } else {
                $response['status'] = 'success';
                $response['log'] = 'No hay logs disponibles.';
            }
            break;

        case 'local_grupomakro_get_course_grade_categories':
            $classid = required_param('classid', PARAM_INT);
            $class = $DB->get_record('gmk_class', ['id' => $classid]);
            if (!$class) throw new Exception("Clase no encontrada.");
            
            require_once($CFG->libdir . '/gradelib.php');
            $categories = \grade_category::fetch_all(['courseid' => $class->corecourseid]);
            
            $formatted_cats = [];
            foreach ($categories as $cat) {
                 // Skip course total category itself if desired, or keep all
                 $formatted_cats[] = [
                     'id' => $cat->id,
                     'name' => $cat->get_name(),
                     'fullname' => $cat->get_formatted_name()
                 ];
            }
            // Sort by name
            usort($formatted_cats, function($a, $b) {
                return strcmp($a['fullname'], $b['fullname']);
            });

            $response = [
                'status' => 'success',
                'categories' => $formatted_cats
            ];
            break;

        case 'local_grupomakro_get_course_tags':
            $classid = required_param('classid', PARAM_INT);
            $class = $DB->get_record('gmk_class', ['id' => $classid]);
            if (!$class) throw new Exception("Clase no encontrada.");
            
            $tags = gmk_get_course_tags($class->corecourseid);
            $response = [
                'status' => 'success',
                'tags' => $tags
            ];
            break;

        case 'local_grupomakro_get_activity_details':
            $cmid = required_param('cmid', PARAM_INT);
            $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
            
            // Set context
            $context = context_module::instance($cm->id);
            $PAGE->set_context($context);

            $module_instance = $DB->get_record($cm->modname, ['id' => $cm->instance], '*', MUST_EXIST);
            
            $tags = core_tag_tag::get_item_tags('core', 'course_modules', $cm->id);
            $tagNames = array_map(function($t) { return $t->rawname; }, $tags);

            // Determine intro/description field (usually 'intro')
            $intro = isset($module_instance->intro) ? $module_instance->intro : '';
            
            $activity_data = [
                'id' => $cm->id,
                'name' => $cm->name,
                'modname' => $cm->modname,
                'intro' => $intro,
                'visible' => (bool)$cm->visible,
                'tags' => array_values($tagNames),
                'duedate' => isset($module_instance->duedate) ? (int)$module_instance->duedate : 0,
                'timeopen' => isset($module_instance->timeopen) ? (int)$module_instance->timeopen : 0,
                'timeclose' => isset($module_instance->timeclose) ? (int)$module_instance->timeclose : 0,
                'attempts' => isset($module_instance->attempts) ? (int)$module_instance->attempts : 0,
                'files' => [],
            ];

            // Include attached files for supported module types
            $fileinfo_detail = gmk_get_module_fileinfo($cm->modname);
            if ($fileinfo_detail) {
                $fs_detail = get_file_storage();
                $res_files = $fs_detail->get_area_files(
                    $context->id,
                    $fileinfo_detail['component'],
                    $fileinfo_detail['filearea'],
                    $fileinfo_detail['itemid'],
                    'filename',
                    false
                );
                foreach ($res_files as $rf) {
                    $activity_data['files'][] = [
                        'filename' => $rf->get_filename(),
                        'url'      => moodle_url::make_pluginfile_url(
                            $context->id,
                            $fileinfo_detail['component'],
                            $fileinfo_detail['filearea'],
                            $fileinfo_detail['itemid'],
                            '/',
                            $rf->get_filename()
                        )->out(false),
                        'filesize' => $rf->get_filesize(),
                        'mimetype' => $rf->get_mimetype(),
                    ];
                }
            }

            $response = ['status' => 'success', 'activity' => $activity_data];
            break;

        case 'local_grupomakro_get_guest_meetings':
            require_capability('moodle/site:config', context_system::instance());
            
            // Get all BBB activities with guest=1
            // We assume they are in site context (course 1) usually, but we can list all.
            // Joining course_modules to ensure they exist and get cmid
            // Get all BBB activities in Site Course (ID 1) as proxy for "Guest Meetings"
            // Since guest column is missing, we assume Admin created meetings are on Front Page
            $sql = "SELECT b.id, b.name, b.intro, b.timecreated, cm.id as cmid
                    FROM {bigbluebuttonbn} b
                    JOIN {course_modules} cm ON cm.instance = b.id
                    JOIN {modules} m ON m.id = cm.module
                    WHERE m.name = 'bigbluebuttonbn'
                    AND b.course = 1
                    ORDER BY b.timecreated DESC";
            
            $meetings = $DB->get_records_sql($sql);
            $result = [];
            foreach ($meetings as $m) {
                // Construct guest link using standard BBB plugin logic if guestlink is empty or just standard pattern
                // The pattern is usually /mod/bigbluebuttonbn/guest_login.php?id=[cmid]
                // OR checking if secret is used. But internal guest=1 usually enables the route.
                // CORRECTION: Plugin version is old and lacks guest_login.php. We typically redirect to our custom handler.
                $guest_url = $CFG->wwwroot . '/local/grupomakro_core/pages/guest_join.php?id=' . $m->cmid;
                
                $result[] = [
                    'id' => $m->id,
                    'cmid' => $m->cmid,
                    'name' => $m->name,
                    'timecreated' => $m->timecreated,
                    'guest_url' => $guest_url
                ];
            }
            $response = ['status' => 'success', 'meetings' => $result];
            break;

        case 'local_grupomakro_delete_guest_meeting':
            require_capability('moodle/site:config', context_system::instance());
            $cmid = required_param('cmid', PARAM_INT);
            course_delete_module($cmid);
            $response = ['status' => 'success'];
            break;

        case 'local_grupomakro_delete_activity':
            $cmid = required_param('cmid', PARAM_INT);
            $classid = required_param('classid', PARAM_INT);

            $class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);
            $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);

            if ((int)$cm->course !== (int)$class->corecourseid) {
                throw new Exception('La actividad no pertenece al curso de la clase.');
            }
            if ((int)$cm->section !== (int)$class->coursesectionid) {
                throw new Exception('La actividad no pertenece a la secciÃ³n de esta clase.');
            }

            if ($cm->modname === 'attendance' || $cm->modname === 'bigbluebuttonbn') {
                $response = [
                    'status' => 'error',
                    'message' => 'No se permite eliminar actividades de asistencia o BigBlueButton desde esta vista.'
                ];
                break;
            }

            $coursecontext = context_course::instance($class->corecourseid);
            require_capability('moodle/course:manageactivities', $coursecontext);

            course_delete_module($cmid);
            $response = ['status' => 'success', 'message' => 'Actividad eliminada correctamente.'];
            break;

        case 'local_grupomakro_update_activity':
            $cmid = required_param('cmid', PARAM_INT);
            $name = required_param('name', PARAM_TEXT);
            $intro = optional_param('intro', '', PARAM_RAW);
            // Normalize tags Ã¢â‚¬â€ may arrive as string (FormData/JSON) or array (JSON flattened)
            $raw_tags_upd = isset($_POST['tags']) ? $_POST['tags'] : '';
            $tags = gmk_ajax_extract_tags_from_request($raw_tags_upd);
            if (!empty($tags)) {
                $tags = [reset($tags)];
            }
            $visible = required_param('visible', PARAM_BOOL);
            
            // New optional params
            $duedate = optional_param('duedate', null, PARAM_INT);
            $timeopen = optional_param('timeopen', null, PARAM_INT);
            $timeclose = optional_param('timeclose', null, PARAM_INT);
            $attempts = optional_param('attempts', null, PARAM_INT);

            $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
            $context = context_module::instance($cm->id);
            $PAGE->set_context($context);

            // Update specific module table (name, intro)
            $module_record = new stdClass();
            $module_record->id = $cm->instance;
            $module_record->name = $name;

            // Check columns to avoid errors
            $columns = $DB->get_columns($cm->modname);
            if (isset($columns['intro'])) {
                $module_record->intro = $intro;
            }
            if (isset($columns['timemodified'])) {
                $module_record->timemodified = time();
            }

            // Specific fields
            if ($cm->modname === 'assign' && $duedate !== null) {
                $module_record->duedate = $duedate;
            }
            if ($cm->modname === 'quiz') {
                if ($timeopen !== null) $module_record->timeopen = $timeopen;
                if ($timeclose !== null) $module_record->timeclose = $timeclose;
                if ($attempts !== null) $module_record->attempts = $attempts;
            }

            $DB->update_record($cm->modname, $module_record);

            // Update visibility
            set_coursemodule_visible($cmid, $visible ? 1 : 0);

            // Update Tags (already normalized to array above)
            gmk_safe_set_item_tags($cm->id, $context, $tags);

            // Rebuild cache
            rebuild_course_cache($cm->course);

            // Handle file attachments
            $fileinfo_upd = gmk_get_module_fileinfo($cm->modname);
            if ($fileinfo_upd) {
                $fs_upd = get_file_storage();
                $fi_comp = $fileinfo_upd['component'];
                $fi_area = $fileinfo_upd['filearea'];
                $fi_item = $fileinfo_upd['itemid'];

                // Delete files marked for removal
                $delete_files = isset($_POST['delete_files']) ? (array)$_POST['delete_files'] : [];
                foreach ($delete_files as $fname) {
                    $fname = clean_filename(trim($fname));
                    if ($fname === '') continue;
                    $existing_file = $fs_upd->get_file($context->id, $fi_comp, $fi_area, $fi_item, '/', $fname);
                    if ($existing_file) {
                        $existing_file->delete();
                    }
                }

                // Draft-based uploads (new flow via local_grupomakro_upload_draft_file)
                $raw_drafts_upd = isset($_POST['draftitemids']) ? $_POST['draftitemids'] : '';
                $draftitemids_upd = [];
                if (is_array($raw_drafts_upd)) {
                    $draftitemids_upd = array_map('intval', $raw_drafts_upd);
                } elseif (!empty($raw_drafts_upd)) {
                    $draftitemids_upd = array_map('intval', explode(',', (string)$raw_drafts_upd));
                }
                if (!empty($draftitemids_upd)) {
                    $usercontext_upd = context_user::instance($USER->id);
                    foreach ($draftitemids_upd as $draftid) {
                        if (!$draftid) continue;
                        $draft_files_upd = $fs_upd->get_area_files($usercontext_upd->id, 'user', 'draft', $draftid, 'id', false);
                        foreach ($draft_files_upd as $draft_file) {
                            $fname = gmk_ajax_make_unique_filename(
                                $fs_upd,
                                (int)$context->id,
                                (string)$fi_comp,
                                (string)$fi_area,
                                (int)$fi_item,
                                '/',
                                (string)$draft_file->get_filename()
                            );
                            $fs_upd->create_file_from_storedfile([
                                'contextid' => $context->id,
                                'component' => $fi_comp,
                                'filearea'  => $fi_area,
                                'itemid'    => $fi_item,
                                'filepath'  => '/',
                                'filename'  => $fname,
                                'userid'    => $USER->id,
                            ], $draft_file);
                            $draft_file->delete();
                        }
                    }
                }

                // Fallback: direct $_FILES upload (legacy/compatibility)
                foreach ($_FILES as $fkey => $finfo) {
                    if (strpos($fkey, 'resource_file_') !== 0) continue;
                    if ($finfo['error'] !== UPLOAD_ERR_OK) continue;
                    $fname = gmk_ajax_make_unique_filename(
                        $fs_upd,
                        (int)$context->id,
                        (string)$fi_comp,
                        (string)$fi_area,
                        (int)$fi_item,
                        '/',
                        (string)$finfo['name']
                    );
                    $fs_upd->create_file_from_pathname([
                        'contextid' => $context->id,
                        'component' => $fi_comp,
                        'filearea'  => $fi_area,
                        'itemid'    => $fi_item,
                        'filepath'  => '/',
                        'filename'  => $fname,
                        'userid'    => $USER->id,
                    ], $finfo['tmp_name']);
                }

                // Bump revision if supported
                $columns_rev = $DB->get_columns($cm->modname);
                if (isset($columns_rev['revision'])) {
                    $DB->set_field($cm->modname, 'revision', time(), ['id' => $cm->instance]);
                }
            }

            $response = ['status' => 'success'];
            break;

        case 'local_grupomakro_get_learning_plan_list':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/learningPlan/get_learning_plan_list.php');
            $learningplanid = optional_param('learningPlanId', 0, PARAM_INT);
            $result = \local_grupomakro_core\external\learningPlan\get_learning_plan_list::execute($learningplanid);
            if (isset($result['learningPlans'])) {
                $response = ['status' => 'success', 'data' => json_decode($result['learningPlans'], true)];
            } else {
                $response = ['status' => 'error', 'message' => isset($result['message']) ? $result['message'] : 'Error loading plans'];
            }
            break;

        case 'local_grupomakro_get_planning_data':
            $periodid = required_param('periodid', PARAM_INT);
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/planning_manager.php');
            $data = \local_grupomakro_core\local\planning_manager::get_planning_data($periodid);
            $response = ['data' => $data, 'error' => false];
            break;

        case 'local_grupomakro_get_student_documents':
            $usernames = required_param('usernames', PARAM_RAW);
            $usernameList = array_filter(array_map('trim', explode(',', $usernames)));
            
            $results = [];
            if (!empty($usernameList)) {
                $fieldDoc = $DB->get_record('user_info_field', ['shortname' => 'documentnumber']);
                if ($fieldDoc) {
                    foreach ($usernameList as $uname) {
                        $user = $DB->get_record('user', ['username' => strtolower($uname), 'deleted' => 0], 'id, username');
                        if ($user) {
                            $docData = $DB->get_record('user_info_data', ['userid' => $user->id, 'fieldid' => $fieldDoc->id], 'data');
                            $results[$uname] = $docData ? $docData->data : '';
                        }
                    }
                }
            }
            $response = ['status' => 'success', 'data' => $results];
            break;

        case 'local_grupomakro_get_scheduler_context':
            $periodid = required_param('periodid', PARAM_INT);
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/planning_manager.php');
            $data = \local_grupomakro_core\local\planning_manager::get_scheduler_context($periodid);
            $response = ['status' => 'success', 'data' => $data];
            break;

        case 'local_grupomakro_get_teachers_disponibility':
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/disponibility/get_teachers_disponibility.php');
            $instructorId = optional_param('instructorId', null, PARAM_TEXT);
            $initTime = optional_param('initTime', null, PARAM_TEXT);
            $endTime = optional_param('endTime', null, PARAM_TEXT);
            
            $result = \local_grupomakro_core\external\disponibility\get_teachers_disponibility::execute($instructorId, $initTime, $endTime);
            if (isset($result['status']) && $result['status'] == 1) {
                // Return decoded json array
                $data = json_decode($result['teacherAvailabilityRecords'], true);
                $response = ['status' => 'success', 'data' => $data];
            } else {
                $response = ['status' => 'error', 'message' => isset($result['message']) ? $result['message'] : 'Error loading disponibility'];
            }
            break;

        case 'local_grupomakro_save_scheduler_config':
            $periodid = required_param('periodid', PARAM_INT);
            $holidays = isset($_POST['holidays']) && is_array($_POST['holidays']) ? $_POST['holidays'] : [];
            $loads = isset($_POST['loads']) && is_array($_POST['loads']) ? $_POST['loads'] : [];
            $configsettings = optional_param('configsettings', '', PARAM_RAW);
            
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/admin/scheduler.php');
            $result = \local_grupomakro_core\external\admin\scheduler::save_scheduler_config($periodid, $holidays, $loads, $configsettings);
            
            if ($result) {
                $response = ['status' => 'success'];
            } else {
                $response = ['status' => 'error', 'message' => 'Error al guardar la configuraciÃƒÂ³n'];
            }
            break;

        case 'local_grupomakro_get_demand_data':
            $periodid = required_param('periodid', PARAM_INT);
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/planning_manager.php');
            $data = \local_grupomakro_core\local\planning_manager::get_demand_data($periodid);
            $response = ['status' => 'success', 'data' => $data];
            break;

        case 'local_grupomakro_save_projections':
            $periodid = required_param('periodid', PARAM_INT);
            $projections_json = required_param('projections', PARAM_RAW);
            $projections = json_decode($projections_json, true);
            if (!is_array($projections)) $projections = [];

            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/admin/scheduler.php');
            $result = \local_grupomakro_core\external\admin\scheduler::save_projections($periodid, $projections);
            if ($result) {
                $response = ['status' => 'success'];
            } else {
                $response = ['status' => 'error', 'message' => 'Error al guardar proyecciones'];
            }
            break;

        case 'local_grupomakro_get_generated_schedules':
            $periodid = required_param('periodid', PARAM_INT);
            $includeoverlaps = optional_param('includeoverlaps', 0, PARAM_INT);
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/admin/scheduler.php');
            $data = \local_grupomakro_core\external\admin\scheduler::get_generated_schedules($periodid, $includeoverlaps);
            $response = ['status' => 'success', 'data' => $data];
            break;

        case 'local_grupomakro_get_course_students_by_class_schedule':
            $classid = required_param('classid', PARAM_INT);
            $periodid = optional_param('periodid', 0, PARAM_INT);
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/schedule/get_course_students_by_class_schedule.php');
            $data = \local_grupomakro_core\external\schedule\get_course_students_by_class_schedule::execute($classid, $periodid);
            $response = ['status' => 'success', 'data' => $data];
            break;

        case 'local_grupomakro_get_planned_students':
            // Returns planned students for a class (for bulk enrollment UI).
            // Sources: gmk_class_pre_registration + gmk_class_queue + gmk_course_progre (already enrolled).
            // This ensures students always appear even after their queue records were cleared.
            $classid = required_param('classid', PARAM_INT);
            $class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);
            $instructorId = (int)($class->instructorid ?? 0);
            $preReg = $DB->get_records('gmk_class_pre_registration', ['classid' => $classid]);
            $queued = $DB->get_records('gmk_class_queue', ['classid' => $classid]);
            // Also include students already enrolled via gmk_course_progre (classid link).
            // This ensures they reappear in the dialog even after queue records were cleared.
            $progreStudents = $DB->get_records('gmk_course_progre', ['classid' => $classid]);
            // Build a userid-keyed map for quick source lookup
            $preRegByUser  = array_column((array)$preReg,  null, 'userid');
            $queueByUser   = array_column((array)$queued,  null, 'userid');
            $allStudents = array_merge(array_values($preReg), array_values($queued), array_values($progreStudents));
            // Resolve user info, excluding instructor and deduplicating
            $result = [];
            $seen = [];
            foreach ($allStudents as $s) {
                if (isset($seen[$s->userid])) continue;
                if ($instructorId && $s->userid == $instructorId) continue;
                $seen[$s->userid] = true;
                $u = $DB->get_record('user', ['id' => $s->userid, 'deleted' => 0], 'id, firstname, lastname, email, idnumber');
                if (!$u) continue;
                $src = isset($preRegByUser[$s->userid]) ? 'prereg' : (isset($queueByUser[$s->userid]) ? 'queue' : 'enrolled');
                $result[] = [
                    'userid'    => (int)$u->id,
                    'fullname'  => fullname($u),
                    'email'     => $u->email,
                    'idnumber'  => $u->idnumber,
                    'source'    => $src,
                ];
            }
            // Already enrolled: group members for classes with a Moodle group.
            // For classes without a group, all students in the list are candidates (re-enrollable).
            if (!empty($class->groupid)) {
                $alreadyEnrolled = $DB->get_fieldset_select('groups_members', 'userid', 'groupid = :gid', ['gid' => $class->groupid]);
            } else {
                $alreadyEnrolled = [];
            }
            $response = [
                'status'           => 'success',
                'students'         => $result,
                'already_enrolled' => array_map('intval', $alreadyEnrolled),
                'quota'            => (int)$class->classroomcapacity,
            ];
            break;

        case 'local_grupomakro_bulk_enroll_students':
            // Enrolls a selection of planned students into their class group and Moodle course.
            // Accepts: classid, userids[] (array of user IDs), force_over_quota (0|1)
            $classid        = required_param('classid', PARAM_INT);
            $userids_raw    = required_param('userids', PARAM_RAW);   // JSON array
            $force_over     = optional_param('force_over_quota', 0, PARAM_INT);
            $userids        = json_decode($userids_raw, true);
            if (!is_array($userids) || empty($userids)) {
                $response = ['status' => 'error', 'message' => 'No se proporcionaron estudiantes.'];
                break;
            }
            $userids = array_map('intval', $userids);

            $class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);

            // Count current enrolled: group members if class has a Moodle group.
            // For classes without a group there is no authoritative "enrolled" count to check against quota.
            if (!empty($class->groupid)) {
                $currentCount = $DB->count_records('groups_members', ['groupid' => $class->groupid]);
            } else {
                $currentCount = 0;
            }
            $quota    = (int)$class->classroomcapacity;
            $newTotal = $currentCount + count($userids);

            // Only block on quota if quota is actually set (>0)
            if ($quota > 0 && $newTotal > $quota && !$force_over) {
                $response = [
                    'status'       => 'quota_exceeded',
                    'current'      => $currentCount,
                    'quota'        => $quota,
                    'requested'    => count($userids),
                    'new_total'    => $newTotal,
                    'message'      => "Al inscribir {$newTotal} estudiantes se superara el cupo de {$quota}. Desea aumentar el cupo automaticamente?",
                ];
                break;
            }

            // If force_over_quota, expand classroomcapacity to fit
            if ($force_over && $quota > 0 && $newTotal > $quota) {
                $class->classroomcapacity = $newTotal;
                $DB->update_record('gmk_class', $class);
            }

            // Build student objects compatible with enrolApprovedScheduleStudents()
            $studentsToEnrol = [];
            foreach ($userids as $uid) {
                $obj = new stdClass();
                $obj->userid = $uid;
                $studentsToEnrol[] = $obj;
            }

            $results = enrolApprovedScheduleStudents($studentsToEnrol, $class);

            $enrolled = count(array_filter($results));

            // Ensure progress is synced even if a student was already in the Moodle group.
            // In that case groups_add_member can return false, but we still need status "cursando".
            $progresssynced = 0;
            $progresssyncerrors = [];
            foreach ($userids as $uid) {
                $uid = (int)$uid;
                $hadEnrollSuccess = !empty($results[$uid]);
                if ($hadEnrollSuccess) {
                    // enrolApprovedScheduleStudents() already synced progress in this path.
                    $progresssynced++;
                    continue;
                }

                $alreadyInGroup = (!empty($class->groupid) && groups_is_member((int)$class->groupid, $uid));
                $shouldSyncProgress = $alreadyInGroup || empty($class->groupid);
                if (!$shouldSyncProgress) {
                    continue;
                }

                try {
                    local_grupomakro_progress_manager::assign_class_to_course_progress($uid, $class, true);
                    $progresssynced++;
                } catch (\Throwable $t) {
                    $progresssyncerrors[] = "userid {$uid}: " . $t->getMessage();
                    gmk_log('bulk_enroll_students progress sync error: userid=' . $uid . ' classid=' . (int)$class->id . ' error=' . $t->getMessage());
                }
            }

            // NOTE: We intentionally do NOT delete queue/pre_reg records after enrolment.
            // Those records represent the academic planning ("who is assigned to this class")
            // and must persist so the student list reappears correctly if the dialog is reopened.

            // Mark class as approved if not already
            if (!$class->approved) {
                $DB->set_field('gmk_class', 'approved', 1, ['id' => $class->id]);
            }

            $response = [
                'status'   => 'success',
                'enrolled' => $enrolled,
                'total'    => count($userids),
                'message'  => "Se inscribieron {$enrolled} de " . count($userids) . " estudiantes.",
                'new_quota'=> (int)$class->classroomcapacity,
                'progress_synced' => $progresssynced,
                'progress_sync_errors' => $progresssyncerrors,
            ];
            break;

        case 'local_grupomakro_bulk_approve_period':
            raise_memory_limit(MEMORY_HUGE);
            core_php_time_limit::raise(600);

            $periodid = required_param('periodid', PARAM_INT);
            $force = optional_param('force', 0, PARAM_INT);
            $quorumlimit = optional_param('quorumlimit', 40, PARAM_INT);
            if ($quorumlimit <= 0) {
                $quorumlimit = 40;
            }

            // Note: gmk_class has no 'active' field - filter only by periodid and approved=0.
            $classes = $DB->get_records('gmk_class', ['periodid' => $periodid, 'approved' => 0]);

            $preparedclasses = [];
            $overquotaclasses = [];

            foreach ($classes as $class) {
                // Merge queue + pre_registration, deduplicate by userid, exclude instructor.
                $instructorid = (int)($class->instructorid ?? 0);
                $prereg = $DB->get_records('gmk_class_pre_registration', ['classid' => $class->id]);
                $queued = $DB->get_records('gmk_class_queue', ['classid' => $class->id]);
                $allstudents = [];

                foreach (array_merge(array_values($prereg), array_values($queued)) as $student) {
                    $userid = (int)($student->userid ?? 0);
                    if (!$userid) {
                        continue;
                    }
                    if ($instructorid && $userid === $instructorid) {
                        continue;
                    }

                    $entry = new stdClass();
                    $entry->userid = $userid;
                    $allstudents[$userid] = $entry;
                }

                $candidatecount = count($allstudents);
                if ($candidatecount > $quorumlimit) {
                    $overquotaclasses[] = [
                        'classid' => (int)$class->id,
                        'name' => (string)$class->name,
                        'candidates' => $candidatecount,
                        'quorumlimit' => $quorumlimit,
                        'overflow' => ($candidatecount - $quorumlimit),
                        'classroomcapacity' => (int)($class->classroomcapacity ?? 0),
                    ];
                }

                $preparedclasses[] = [
                    'class' => $class,
                    'students' => $allstudents,
                ];
            }

            // Dry-run warning mode: do not approve/enrol until user confirms.
            if (!$force && !empty($overquotaclasses)) {
                $response = [
                    'status' => 'warning',
                    'message' => 'Se detectaron clases que superan el quorum.',
                    'data' => [
                        'quorum_limit' => $quorumlimit,
                        'total_classes' => count($preparedclasses),
                        'over_quota_count' => count($overquotaclasses),
                        'over_quota_classes' => $overquotaclasses,
                    ],
                ];
                break;
            }

            $results = [
                'approved' => 0,
                'skipped' => 0,
                'errors' => [],
                'enrolled_total' => 0,
                'quorum_limit' => $quorumlimit,
                'over_quota_count' => count($overquotaclasses),
                'over_quota_classes' => $overquotaclasses,
            ];

            foreach ($preparedclasses as $entry) {
                $class = $entry['class'];
                $allstudents = $entry['students'];

                try {
                    if (!empty($allstudents)) {
                        $enrolresults = enrolApprovedScheduleStudents(array_values($allstudents), $class);
                        $results['enrolled_total'] += count(array_filter($enrolresults));
                        // NOTE: queue/pre_reg records are intentionally preserved - they represent
                        // the academic plan (who is assigned to this class) and must persist.
                    } else {
                        $results['skipped']++;
                    }

                    $DB->set_field('gmk_class', 'approved', 1, ['id' => $class->id]);
                    $results['approved']++;
                } catch (Exception $e) {
                    $results['errors'][] = [
                        'classid' => (int)$class->id,
                        'name' => (string)$class->name,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            $response = ['status' => 'success', 'data' => $results];
            break;

        case 'local_grupomakro_save_generation_result':
            $periodid       = required_param('periodid', PARAM_INT);
            $schedules_json = required_param('schedules', PARAM_RAW);
            $phase1only     = optional_param('phase1only', 0, PARAM_INT); // 1 = skip Moodle structures
            $preserveexisting = optional_param('preserveexisting', 0, PARAM_INT); // 1 = do not delete other classes in period
            $schedules = json_decode($schedules_json, true);
            if (!is_array($schedules)) $schedules = [];

            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/admin/scheduler.php');
            $result = \local_grupomakro_core\external\admin\scheduler::save_generation_result(
                $periodid,
                $schedules,
                (bool)$phase1only,
                (bool)$preserveexisting
            );
            if ($result === true) {
                // Return the list of classids created/updated so the frontend can drive phase 2.
                $classids = $DB->get_fieldset_select('gmk_class', 'id', 'periodid = :pid', ['pid' => $periodid]);
                $response = ['status' => 'success', 'classids' => array_values(array_map('intval', $classids))];
            } else {
                $err = is_string($result) ? $result : 'Error al guardar estructura matricial';
                $response = ['status' => 'error', 'message' => $err];
            }
            break;

        // Phase 2: create Moodle structures (group + section + activities) for a single class.
        // Called once per class by the frontend so each call is short and there is no global timeout.
        case 'local_grupomakro_create_class_moodle_structures':
            $classid = required_param('classid', PARAM_INT);
            $forcerebuilddates = optional_param('forcerebuilddates', 0, PARAM_INT); // 1 = ignore assigned_dates and rebuild from day/range
            require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
            core_php_time_limit::raise(120);
            $class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);
            $log   = [];

            if (empty($class->corecourseid)) {
                $response = ['status' => 'error', 'message' => 'Sin corecourseid', 'log' => []];
                break;
            }

            // Group
            $groupReason = '';
            if (!gmk_is_valid_class_group($class, $groupReason)) {
                if (!empty($class->groupid)) {
                    $log[] = "Grupo invalido ({$groupReason}), recreando...";
                }
                try {
                    $gid = create_class_group($class);
                    $DB->set_field('gmk_class', 'groupid', $gid, ['id' => $classid]);
                    $class->groupid = $gid;
                    $log[] = "Grupo creado: id=$gid";
                } catch (Throwable $e) {
                    $response = ['status' => 'error', 'message' => 'Error creando grupo: ' . $e->getMessage(), 'log' => $log];
                    break;
                }
            } else {
                $log[] = "Grupo ya existe: id={$class->groupid}";
            }

            // Section
            $sectionReason = '';
            if (!gmk_is_valid_class_section($class, $sectionReason)) {
                if (!empty($class->coursesectionid)) {
                    $log[] = "Seccion invalida ({$sectionReason}), recreando...";
                }
                try {
                    $sid = create_class_section($class);
                    $DB->set_field('gmk_class', 'coursesectionid', $sid, ['id' => $classid]);
                    $class->coursesectionid = $sid;
                    $log[] = "SecciÃƒÂ³n creada: id=$sid";
                } catch (Throwable $e) {
                    $log[] = "WARN secciÃƒÂ³n: " . $e->getMessage();
                    // non-fatal Ã¢â‚¬â€ continue to activities
                }
            } else {
                $log[] = "SecciÃƒÂ³n ya existe: id={$class->coursesectionid}";
            }

            // Activities
            $attReason = '';
            $hasActivities = gmk_is_valid_class_attendance_module($class, $attReason);
            $crossPersistOk = true;
            $crossPersistMsg = '';
            if (!$hasActivities && !empty($class->attendancemoduleid)) {
                $log[] = "Attendance invalido ({$attReason}), recreando actividades...";
            }
            if (!empty($forcerebuilddates)) {
                $log[] = "Modo fechas: recalc forzado desde day/range (assigned_dates ignorado).";
            }
            try {
                create_class_activities($class, $hasActivities, !empty($forcerebuilddates));
                $commitok = gmk_best_effort_db_commit("ajax_create_class_moodle_structures_class_{$classid}");
                $log[] = "COMMIT best-effort " . ($commitok ? "OK" : "WARN");
                $class = $DB->get_record('gmk_class', ['id' => $classid]);
                $log[] = ($hasActivities ? "Actividades recreadas" : "Actividades creadas")
                       . ": attendanceid={$class->attendancemoduleid}";

                $attcmid = (int)($class->attendancemoduleid ?? 0);
                $extcheck = gmk_secondary_db_activity_check(
                    (int)$classid,
                    (int)$class->corecourseid,
                    (int)$class->coursesectionid,
                    $attcmid
                );
                if (!empty($extcheck['enabled'])) {
                    $log[] = "EXTCHECK class.attendancemoduleid={$extcheck['class_attendancemoduleid']}"
                        . " cm_exists={$extcheck['cm_exists']}"
                        . " section_modules(attendance|bbb)={$extcheck['section_modules_att_bbb']}"
                        . " ok=" . (($extcheck['ok'] ?? false) ? '1' : '0');
                    if (empty($extcheck['ok'])) {
                        // One extra commit + short wait for defensive convergence.
                        usleep(700000);
                        $commitok2 = gmk_best_effort_db_commit("ajax_create_class_moodle_structures_retry_class_{$classid}");
                        $log[] = "COMMIT retry " . ($commitok2 ? "OK" : "WARN");
                        $extcheck2 = gmk_secondary_db_activity_check(
                            (int)$classid,
                            (int)$class->corecourseid,
                            (int)$class->coursesectionid,
                            $attcmid
                        );
                        $log[] = "EXTCHECK retry class.attendancemoduleid={$extcheck2['class_attendancemoduleid']}"
                            . " cm_exists={$extcheck2['cm_exists']}"
                            . " section_modules(attendance|bbb)={$extcheck2['section_modules_att_bbb']}"
                            . " ok=" . (($extcheck2['ok'] ?? false) ? '1' : '0');
                        if (empty($extcheck2['ok'])) {
                            $crossPersistOk = false;
                            $crossPersistMsg = 'Persistencia cruzada fallida: los cambios no son visibles desde una segunda conexion DB.';
                        }
                    }
                }
            } catch (Throwable $e) {
                $log[] = "WARN actividades: " . $e->getMessage();
            }

            if (!$crossPersistOk) {
                $response = ['status' => 'error', 'classid' => $classid, 'log' => $log,
                    'message' => $crossPersistMsg,
                    'groupid' => $class->groupid, 'attendancemoduleid' => $class->attendancemoduleid];
                break;
            }

            $finalAttReason = '';
            if (!gmk_is_valid_class_attendance_module($class, $finalAttReason)) {
                $response = ['status' => 'error', 'classid' => $classid, 'log' => $log,
                    'message' => "La clase no quedo con attendance valido: {$finalAttReason}",
                    'groupid' => $class->groupid, 'attendancemoduleid' => $class->attendancemoduleid];
                break;
            }

            $response = ['status' => 'success', 'classid' => $classid, 'log' => $log,
                         'groupid' => $class->groupid, 'attendancemoduleid' => $class->attendancemoduleid];
            break;

        case 'local_grupomakro_get_classrooms':
            $classrooms = $DB->get_records('gmk_classrooms', [], 'name ASC');
            $response = ['status' => 'success', 'data' => array_values($classrooms)];
            break;

        case 'local_grupomakro_save_classroom':
            $id = optional_param('id', 0, PARAM_INT);
            $record = new stdClass();
            $record->name = required_param('name', PARAM_TEXT);
            $record->capacity = required_param('capacity', PARAM_INT);
            $record->type = optional_param('type', 'general', PARAM_TEXT);
            $record->active = optional_param('active', 1, PARAM_INT);
            $record->usermodified = $USER->id;
            $record->timemodified = time();

            if ($id > 0) {
                $record->id = $id;
                $DB->update_record('gmk_classrooms', $record);
            } else {
                $record->timecreated = time();
                $id = $DB->insert_record('gmk_classrooms', $record);
            }
            $response = ['status' => 'success', 'data' => ['id' => $id]];
            break;

        case 'local_grupomakro_delete_classroom':
            $id = required_param('id', PARAM_INT);
            $DB->delete_records('gmk_classrooms', ['id' => $id]);
            $response = ['status' => 'success'];
            break;

        case 'local_grupomakro_get_scheduler_context':
            $periodid = required_param('periodid', PARAM_INT);
            $ctxData = \local_grupomakro_core\external\admin\scheduler::get_scheduler_context($periodid);
            // Format holidays with formatted_date for calendar
            if (!empty($ctxData['holidays'])) {
                foreach ($ctxData['holidays'] as &$h) {
                    if (isset($h->date) && !isset($h->formatted_date)) {
                        $h->formatted_date = date('Y-m-d', $h->date);
                    }
                }
                unset($h);
            }
            $response = ['status' => 'success', 'data' => $ctxData];
            break;

        case 'local_grupomakro_get_holidays':
            $periodid = required_param('academicperiodid', PARAM_INT);
            $holidays = $DB->get_records('gmk_holidays', ['academicperiodid' => $periodid], 'date ASC');
            $data = [];
            foreach ($holidays as $h) {
                $h->formatted_date = date('Y-m-d', $h->date);
                $data[] = $h;
            }
            $response = ['status' => 'success', 'data' => $data];
            break;

        case 'local_grupomakro_save_holiday':
            $id = optional_param('id', 0, PARAM_INT);
            $record = new stdClass();
            $record->academicperiodid = required_param('academicperiodid', PARAM_INT);
            $record->date = required_param('date', PARAM_INT); // Expecting timestamp
            $record->name = optional_param('name', '', PARAM_TEXT);
            $record->type = optional_param('type', 'feriado', PARAM_TEXT);
            $record->usermodified = $USER->id;
            $record->timemodified = time();

            if ($id > 0) {
                $record->id = $id;
                $DB->update_record('gmk_holidays', $record);
            } else {
                $record->timecreated = time();
                $id = $DB->insert_record('gmk_holidays', $record);
            }
            $response = ['status' => 'success', 'data' => ['id' => $id]];
            break;

        case 'local_grupomakro_delete_holiday':
            $id = required_param('id', PARAM_INT);
            $DB->delete_records('gmk_holidays', ['id' => $id]);
            $response = ['status' => 'success'];
            break;

        case 'local_grupomakro_save_subject_loads':
            $periodid = required_param('academicperiodid', PARAM_INT);
            $loadsJson = required_param('loads', PARAM_RAW);
            $loads = json_decode($loadsJson, true);
            
            if (!is_array($loads)) {
                throw new Exception('Formato de cargas invÃƒÂ¡lido.');
            }
            
            // Wipe existing loads for this period and insert new ones
            $DB->delete_records('gmk_subject_loads', ['academicperiodid' => $periodid]);
            $count = 0;
            foreach ($loads as $l) {
                $rec = new stdClass();
                $rec->academicperiodid = $periodid;
                $rec->subjectname = trim($l['subjectName'] ?? '');
                $rec->total_hours = floatval($l['totalHours'] ?? 0);
                $rec->intensity = floatval($l['intensity'] ?? 0);
                $rec->usermodified = $USER->id;
                $rec->timecreated = time();
                $rec->timemodified = time();
                if (!empty($rec->subjectname)) {
                    $DB->insert_record('gmk_subject_loads', $rec);
                    $count++;
                }
            }
            $response = ['status' => 'success', 'data' => ['saved' => $count]];
            break;

        case 'local_grupomakro_save_draft':
            raise_memory_limit(MEMORY_HUGE);
            core_php_time_limit::raise(300);

            $periodid = required_param('periodid', PARAM_INT);
            $schedulesJson = isset($_POST['schedules']) ? $_POST['schedules'] : null;
            $source = 'POST';

            if ($schedulesJson === null && !empty($rawInput)) {
                $decoded = json_decode($rawInput, true);
                $schedulesJson = $decoded['schedules'] ?? null;
                $source = 'RAW_INPUT_VAR';
            }

            // When Content-Type is application/json, schedules may already be decoded as array Ã¢â‚¬â€ re-encode it
            if (is_array($schedulesJson)) {
                $schedulesJson = json_encode($schedulesJson);
                $source .= '_REENCODED';
            }

            $len = $schedulesJson ? strlen($schedulesJson) : 0;
            error_log("DEBUG: Save draft for period $periodid. Source: $source. Length: $len chars.");

            if (!$schedulesJson || $len < 2) {
                $response = [
                    'status' => 'error', 
                    'message' => 'No schedules data received', 
                    'source' => $source,
                    'input_len' => strlen($rawInput ?? '')
                ];
                break;
            }

            if (!$DB->record_exists('gmk_academic_periods', ['id' => $periodid])) {
                $response = ['status' => 'error', 'message' => 'Period not found'];
                break;
            }

            try {
                $DB->set_field('gmk_academic_periods', 'draft_schedules', $schedulesJson, ['id' => $periodid]);
                $storedLen = $DB->get_field_sql("SELECT LENGTH(draft_schedules) FROM {gmk_academic_periods} WHERE id = ?", [$periodid]);
                
                $response = [
                    'status' => 'success',
                    'data' => [
                        'received_length' => $len,
                        'stored_length' => (int)$storedLen,
                        'source' => $source
                    ]
                ];
                error_log("DEBUG: Update result for $periodid. Stored length: $storedLen");
            } catch (Exception $e) {
                $response = ['status' => 'error', 'message' => $e->getMessage()];
            }
            break;

        case 'local_grupomakro_load_draft':
            $periodid = required_param('periodid', PARAM_INT);
            $draft = $DB->get_field('gmk_academic_periods', 'draft_schedules', ['id' => $periodid]);
            
            $data = [];
            if ($draft) {
                $decoded = json_decode($draft, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                } else {
                    error_log("DEBUG: JSON DECODE FAILED for period $periodid. Error: " . json_last_error_msg());
                }
            }

            $response = [
                'status' => 'success',
                'data' => $data
            ];
            break;

        case 'local_grupomakro_upload_holidays_excel':
            $periodid = required_param('academicperiodid', PARAM_INT);
            
            if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('No se recibiÃƒÂ³ un archivo vÃƒÂ¡lido.');
            }
            
            $tmpPath = $_FILES['file']['tmp_name'];
            $spreadsheet = IOFactory::load($tmpPath);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, true);
            
            // Detect header row and column mapping
            $headerRow = array_shift($rows);
            $colMap = [];
            foreach ($headerRow as $col => $val) {
                $normalized = mb_strtolower(trim($val ?? ''));
                if (strpos($normalized, 'fecha') !== false) $colMap['date'] = $col;
                if (strpos($normalized, 'festividad') !== false || strpos($normalized, 'nombre') !== false) $colMap['name'] = $col;
                if (strpos($normalized, 'tipo') !== false) $colMap['type'] = $col;
            }
            
            if (empty($colMap['date'])) {
                throw new Exception('No se encontrÃƒÂ³ la columna "Fecha" en el Excel.');
            }
            
            // Get existing dates to skip duplicates
            $existingHolidays = $DB->get_records('gmk_holidays', ['academicperiodid' => $periodid], '', 'id, date');
            $existingDates = [];
            foreach ($existingHolidays as $eh) {
                $existingDates[] = date('Y-m-d', $eh->date);
            }
            
            $imported = 0;
            $skipped = 0;
            
            foreach ($rows as $row) {
                $rawDate = trim($row[$colMap['date']] ?? '');
                if (empty($rawDate)) continue;
                
                // Try multiple date formats
                $ts = 0;
                if (is_numeric($rawDate)) {
                    // Excel serial date
                    $ts = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp($rawDate);
                } else {
                    // Try common date string formats
                    $parsed = strtotime($rawDate);
                    if ($parsed !== false) {
                        $ts = $parsed;
                    } else {
                        // Try MM/DD/YYYY format
                        $parts = preg_split('/[\/\-]/', $rawDate);
                        if (count($parts) === 3) {
                            $ts = mktime(12, 0, 0, intval($parts[0]), intval($parts[1]), intval($parts[2]));
                        }
                    }
                }
                
                if ($ts <= 0) continue;
                
                // Normalize to noon to avoid timezone issues
                $dateStr = date('Y-m-d', $ts);
                $ts = strtotime($dateStr . ' 12:00:00');
                
                // Skip duplicates
                if (in_array($dateStr, $existingDates)) {
                    $skipped++;
                    continue;
                }
                
                $record = new stdClass();
                $record->academicperiodid = $periodid;
                $record->date = $ts;
                $record->name = trim($row[$colMap['name'] ?? ''] ?? 'Feriado');
                
                // Map type from Excel to short code
                $rawType = mb_strtolower(trim($row[$colMap['type'] ?? ''] ?? ''));
                if (strpos($rawType, 'nacional') !== false || strpos($rawType, 'feriado') !== false) {
                    $record->type = 'feriado';
                } else if (strpos($rawType, 'institucional') !== false) {
                    $record->type = 'institucional';
                } else if (strpos($rawType, 'duelo') !== false) {
                    $record->type = 'feriado';
                } else {
                    $record->type = 'otro';
                }
                
                $record->usermodified = $USER->id;
                $record->timecreated = time();
                $record->timemodified = time();
                
                $DB->insert_record('gmk_holidays', $record);
                $existingDates[] = $dateStr;
                $imported++;
            }
            
            $response = ['status' => 'success', 'data' => ['imported' => $imported, 'skipped' => $skipped]];
            break;

        case 'local_grupomakro_get_forum_posts':
            $classid = required_param('classid', PARAM_INT);
            $class = $DB->get_record('gmk_class', ['id' => $classid]);
            if (!$class) throw new Exception("Clase no encontrada.");

            require_once($CFG->dirroot . '/mod/forum/lib.php');

            // Try 'news' type first (Moodle announcements forum), then fallback to forum named "Avisos"
            $forum = forum_get_course_forum($class->corecourseid, 'news');
            if (!$forum) {
                $forum = $DB->get_record_select('forum',
                    "course = :courseid AND " . $DB->sql_like('name', ':name', false),
                    ['courseid' => $class->corecourseid, 'name' => 'Avisos%']
                );
            }

            if (!$forum) {
                $response = ['status' => 'success', 'posts' => [], 'forum_found' => false];
                break;
            }

            $discussions = [];
            if (!empty($class->groupid)) {
                $discussions = $DB->get_records_sql(
                    "SELECT id, name, userid, timemodified, groupid
                       FROM {forum_discussions}
                      WHERE forum = :forumid
                        AND (groupid = -1 OR groupid = 0 OR groupid = :classgroupid)
                   ORDER BY timemodified DESC",
                    ['forumid' => (int)$forum->id, 'classgroupid' => (int)$class->groupid],
                    0,
                    20
                );
            } else {
                $discussions = $DB->get_records_sql(
                    "SELECT id, name, userid, timemodified, groupid
                       FROM {forum_discussions}
                      WHERE forum = :forumid
                        AND (groupid = -1 OR groupid = 0)
                   ORDER BY timemodified DESC",
                    ['forumid' => (int)$forum->id],
                    0,
                    20
                );
            }

            $cm_forum = get_coursemodule_from_instance('forum', $forum->id, $class->corecourseid, false, MUST_EXIST);
            $forum_context = context_module::instance($cm_forum->id);
            $fs_read = get_file_storage();

            $forum_posts = [];
            foreach ($discussions as $disc) {
                $first_post = $DB->get_record('forum_posts',
                    ['discussion' => $disc->id, 'parent' => 0],
                    'id, message, messageformat, attachment'
                );
                $author = $DB->get_record('user', ['id' => $disc->userid], 'firstname, lastname');

                // Resolve attachments
                $attachments = [];
                if ($first_post && $first_post->attachment) {
                    $files = $fs_read->get_area_files(
                        $forum_context->id, 'mod_forum', 'attachment',
                        $first_post->id, 'filename', false
                    );
                    foreach ($files as $f) {
                        $attachments[] = [
                            'filename' => $f->get_filename(),
                            'url'      => moodle_url::make_pluginfile_url(
                                $forum_context->id, 'mod_forum', 'attachment',
                                $first_post->id, '/', $f->get_filename()
                            )->out(false),
                            'mimetype' => $f->get_mimetype(),
                            'filesize' => $f->get_filesize(),
                        ];
                    }
                }

                $forum_posts[] = [
                    'id'           => (int)$disc->id,
                    'subject'      => $disc->name,
                    'message'      => $first_post ? format_text($first_post->message, $first_post->messageformat) : '',
                    'author'       => $author ? fullname($author) : 'Desconocido',
                    'timemodified' => (int)$disc->timemodified,
                    'groupid'      => (int)($disc->groupid ?? 0),
                    'attachments'  => $attachments,
                ];
            }

            $response = ['status' => 'success', 'posts' => $forum_posts, 'forum_found' => true];
            break;

        case 'local_grupomakro_post_forum_announcement':
            $classid = required_param('classid', PARAM_INT);
            $subject = required_param('subject', PARAM_TEXT);
            $message = required_param('message', PARAM_RAW);

            $class = $DB->get_record('gmk_class', ['id' => $classid]);
            if (!$class) throw new Exception("Clase no encontrada.");

            require_once($CFG->dirroot . '/mod/forum/lib.php');

            $forum = forum_get_course_forum($class->corecourseid, 'news');
            if (!$forum) {
                $forum = $DB->get_record_select('forum',
                    "course = :courseid AND " . $DB->sql_like('name', ':name', false),
                    ['courseid' => $class->corecourseid, 'name' => 'Avisos%']
                );
            }
            if (!$forum) throw new Exception("No se encontrÃƒÂ³ el foro de avisos del curso.");

            $now = time();

            $cm = get_coursemodule_from_instance('forum', $forum->id, $class->corecourseid, false, MUST_EXIST);
            $context = context_module::instance($cm->id);

            // Insert the first post directly Ã¢â‚¬â€ forum_add_discussion() ignores $post->mailnow in Moodle 4.x
            $post_record = new stdClass();
            $post_record->discussion    = 0; // Will update after discussion is created
            $post_record->parent        = 0;
            $post_record->privatereplyto = 0;
            $post_record->userid        = $USER->id;
            $post_record->created       = $now;
            $post_record->modified      = $now;
            $post_record->mailed        = 0;
            $post_record->subject       = $subject;
            $post_record->message       = $message;
            $post_record->messageformat = FORMAT_HTML;
            $post_record->messagetrust  = 0;
            $post_record->attachment    = 0;
            $post_record->mailnow       = 0;
            $post_record->wordcount     = str_word_count(strip_tags($message));
            $post_record->charcount     = mb_strlen(strip_tags($message));

            $postid = $DB->insert_record('forum_posts', $post_record);
            if (!$postid) throw new Exception("No se pudo crear el post del aviso.");

            // Insert the discussion referencing the post
            $disc_record = new stdClass();
            $disc_record->course       = $class->corecourseid;
            $disc_record->forum        = $forum->id;
            $disc_record->name         = $subject;
            $disc_record->firstpost    = $postid;
            $disc_record->userid       = $USER->id;
            $disc_record->groupid      = !empty($class->groupid) ? (int)$class->groupid : -1;
            $disc_record->assessed     = 0;
            $disc_record->timemodified = $now;
            $disc_record->usermodified = $USER->id;
            $disc_record->timestart    = 0;
            $disc_record->timeend      = 0;
            $disc_record->pinned       = 0;
            $disc_record->timelocked   = 0;

            $discussionid = $DB->insert_record('forum_discussions', $disc_record);
            if (!$discussionid) throw new Exception("No se pudo crear la discusiÃƒÂ³n del aviso.");

            // Link post back to discussion
            $DB->set_field('forum_posts', 'discussion', $discussionid, ['id' => $postid]);

            // Update forum last post timestamp
            $DB->set_field('forum', 'timemodified', $now, ['id' => $forum->id]);

            // Handle file attachments (uploaded via multipart/form-data)
            if (!empty($_FILES)) {
                $fs = get_file_storage();
                $attachments_saved = 0;
                foreach ($_FILES as $file_info) {
                    if ($file_info['error'] !== UPLOAD_ERR_OK) continue;
                    $filename = clean_filename($file_info['name']);
                    $filerecord = [
                        'contextid' => $context->id,
                        'component' => 'mod_forum',
                        'filearea'  => 'attachment',
                        'itemid'    => $postid,
                        'filepath'  => '/',
                        'filename'  => $filename,
                        'userid'    => $USER->id,
                    ];
                    $fs->create_file_from_pathname($filerecord, $file_info['tmp_name']);
                    $attachments_saved++;
                }
                if ($attachments_saved > 0) {
                    $DB->set_field('forum_posts', 'attachment', 1, ['id' => $postid]);
                }
            }

            $response = ['status' => 'success', 'discussionid' => (int)$discussionid];
            break;

        case 'local_grupomakro_delete_forum_discussion':
            $discussionid = required_param('discussionid', PARAM_INT);
            $disc = $DB->get_record('forum_discussions', ['id' => $discussionid]);
            if (!$disc) throw new Exception("DiscusiÃƒÂ³n no encontrada.");

            // Verify the current user is the instructor of that course or site admin
            $class_del = $DB->get_record('gmk_class', ['corecourseid' => $disc->course, 'instructorid' => $USER->id]);
            if (!$class_del && !is_siteadmin()) {
                throw new Exception("No tienes permiso para eliminar este aviso.");
            }

            // Delete files attached to all posts in the discussion
            $cm_del  = get_coursemodule_from_instance('forum', $disc->forum, $disc->course, false, MUST_EXIST);
            $ctx_del = context_module::instance($cm_del->id);
            $fs_del  = get_file_storage();
            $posts_del = $DB->get_records('forum_posts', ['discussion' => $discussionid]);
            foreach ($posts_del as $p_del) {
                $fs_del->delete_area_files($ctx_del->id, 'mod_forum', 'post',       $p_del->id);
                $fs_del->delete_area_files($ctx_del->id, 'mod_forum', 'attachment', $p_del->id);
            }

            $DB->delete_records('forum_posts',       ['discussion' => $discussionid]);
            $DB->delete_records('forum_discussions', ['id'         => $discussionid]);

            $response = ['status' => 'success'];
            break;

        case 'local_grupomakro_get_forum_activity_data':
            $classid = required_param('classid', PARAM_INT);
            $cmid = required_param('cmid', PARAM_INT);
            list($class, $course, $cm, $forum, $coursecontext, $cmcontext) = gmk_forum_manage_context($classid, $cmid);

            $discussions = $DB->get_records(
                'forum_discussions',
                ['forum' => (int)$forum->id],
                'timemodified DESC, id DESC',
                'id, forum, name, firstpost, userid, timemodified',
                0,
                100
            );

            $firstpostids = [];
            $authors = [];
            foreach ($discussions as $d) {
                if (!empty($d->firstpost)) {
                    $firstpostids[] = (int)$d->firstpost;
                }
                if (!empty($d->userid)) {
                    $authors[] = (int)$d->userid;
                }
            }
            $firstpostids = array_values(array_unique($firstpostids));
            $authors = array_values(array_unique($authors));

            $firstposts = [];
            if (!empty($firstpostids)) {
                $firstposts = $DB->get_records_list(
                    'forum_posts',
                    'id',
                    $firstpostids,
                    '',
                    'id, discussion, userid, subject, message, messageformat, created'
                );
                foreach ($firstposts as $fp) {
                    if (!empty($fp->userid)) {
                        $authors[] = (int)$fp->userid;
                    }
                }
            }

            $authors = array_values(array_unique($authors));
            $users = [];
            if (!empty($authors)) {
                $users = $DB->get_records_list('user', 'id', $authors, '', 'id,firstname,lastname');
            }

            $rows = [];
            foreach ($discussions as $d) {
                $first = (!empty($d->firstpost) && isset($firstposts[(int)$d->firstpost])) ? $firstposts[(int)$d->firstpost] : null;
                $authorname = (!empty($d->userid) && isset($users[(int)$d->userid])) ? fullname($users[(int)$d->userid]) : 'Desconocido';
                $preview = '';
                if ($first) {
                    $plain = trim(strip_tags(format_text($first->message, $first->messageformat, ['context' => $cmcontext])));
                    if ($plain !== '') {
                        $preview = core_text::substr($plain, 0, 220);
                    }
                }
                $replies = max(0, (int)$DB->count_records('forum_posts', ['discussion' => (int)$d->id]) - 1);
                $rows[] = [
                    'id' => (int)$d->id,
                    'subject' => (string)$d->name,
                    'author' => (string)$authorname,
                    'timemodified' => (int)$d->timemodified,
                    'replies' => (int)$replies,
                    'preview' => (string)$preview
                ];
            }

            $response = [
                'status' => 'success',
                'forum' => [
                    'cmid' => (int)$cm->id,
                    'forumid' => (int)$forum->id,
                    'name' => (string)$cm->name,
                    'intro' => !empty($forum->intro) ? format_text($forum->intro, $forum->introformat, ['context' => $cmcontext]) : ''
                ],
                'discussions' => $rows
            ];
            break;

        case 'local_grupomakro_get_forum_discussion_posts':
            $classid = required_param('classid', PARAM_INT);
            $cmid = required_param('cmid', PARAM_INT);
            $discussionid = required_param('discussionid', PARAM_INT);
            list($class, $course, $cm, $forum, $coursecontext, $cmcontext) = gmk_forum_manage_context($classid, $cmid);

            $discussion = $DB->get_record(
                'forum_discussions',
                ['id' => (int)$discussionid, 'forum' => (int)$forum->id],
                'id, forum, name, firstpost, userid, timemodified',
                MUST_EXIST
            );

            $posts = $DB->get_records(
                'forum_posts',
                ['discussion' => (int)$discussion->id],
                'created ASC, id ASC',
                'id, discussion, parent, userid, subject, message, messageformat, created, modified'
            );

            $userids = [];
            foreach ($posts as $p) {
                if (!empty($p->userid)) {
                    $userids[] = (int)$p->userid;
                }
            }
            $userids = array_values(array_unique($userids));
            $users = [];
            if (!empty($userids)) {
                $users = $DB->get_records_list('user', 'id', $userids, '', 'id,firstname,lastname');
            }

            $rows = [];
            foreach ($posts as $p) {
                $author = (!empty($p->userid) && isset($users[(int)$p->userid])) ? fullname($users[(int)$p->userid]) : 'Desconocido';
                $rows[] = [
                    'id' => (int)$p->id,
                    'parent' => (int)$p->parent,
                    'userid' => (int)$p->userid,
                    'author' => (string)$author,
                    'subject' => (string)$p->subject,
                    'message' => format_text($p->message, $p->messageformat, ['context' => $cmcontext]),
                    'created' => (int)$p->created,
                    'modified' => (int)$p->modified,
                    'ismine' => ((int)$p->userid === (int)$USER->id)
                ];
            }

            $response = [
                'status' => 'success',
                'discussion' => [
                    'id' => (int)$discussion->id,
                    'subject' => (string)$discussion->name,
                    'timemodified' => (int)$discussion->timemodified
                ],
                'posts' => $rows
            ];
            break;

        case 'local_grupomakro_create_forum_discussion':
            require_sesskey();
            $classid = required_param('classid', PARAM_INT);
            $cmid = required_param('cmid', PARAM_INT);
            $subject = trim(required_param('subject', PARAM_TEXT));
            $message = trim(required_param('message', PARAM_RAW));
            list($class, $course, $cm, $forum, $coursecontext, $cmcontext) = gmk_forum_manage_context($classid, $cmid);

            if ($subject === '') {
                throw new Exception('El titulo del tema es obligatorio.');
            }
            if ($message === '') {
                throw new Exception('El mensaje del tema es obligatorio.');
            }
            if (!has_capability('mod/forum:startdiscussion', $cmcontext)) {
                throw new Exception('No tienes permiso para crear temas en este foro.');
            }

            $now = time();
            $postrecord = new stdClass();
            $postrecord->discussion = 0;
            $postrecord->parent = 0;
            $postrecord->privatereplyto = 0;
            $postrecord->userid = $USER->id;
            $postrecord->created = $now;
            $postrecord->modified = $now;
            $postrecord->mailed = 0;
            $postrecord->subject = $subject;
            $postrecord->message = $message;
            $postrecord->messageformat = FORMAT_HTML;
            $postrecord->messagetrust = 0;
            $postrecord->attachment = 0;
            $postrecord->mailnow = 0;
            $postrecord->wordcount = str_word_count(strip_tags($message));
            $postrecord->charcount = core_text::strlen(strip_tags($message));

            $postid = $DB->insert_record('forum_posts', $postrecord);
            if (!$postid) {
                throw new Exception('No se pudo crear el post inicial del tema.');
            }

            $discrecord = new stdClass();
            $discrecord->course = (int)$course->id;
            $discrecord->forum = (int)$forum->id;
            $discrecord->name = $subject;
            $discrecord->firstpost = (int)$postid;
            $discrecord->userid = (int)$USER->id;
            $discrecord->groupid = -1;
            $discrecord->assessed = 0;
            $discrecord->timemodified = $now;
            $discrecord->usermodified = (int)$USER->id;
            $discrecord->timestart = 0;
            $discrecord->timeend = 0;
            $discrecord->pinned = 0;
            $discrecord->timelocked = 0;

            $discussionid = $DB->insert_record('forum_discussions', $discrecord);
            if (!$discussionid) {
                throw new Exception('No se pudo crear la discusion del tema.');
            }

            $DB->set_field('forum_posts', 'discussion', (int)$discussionid, ['id' => (int)$postid]);
            $DB->set_field('forum', 'timemodified', $now, ['id' => (int)$forum->id]);

            $response = ['status' => 'success', 'discussionid' => (int)$discussionid];
            break;

        case 'local_grupomakro_create_forum_reply':
            require_sesskey();
            $classid = required_param('classid', PARAM_INT);
            $cmid = required_param('cmid', PARAM_INT);
            $discussionid = required_param('discussionid', PARAM_INT);
            $message = trim(required_param('message', PARAM_RAW));
            list($class, $course, $cm, $forum, $coursecontext, $cmcontext) = gmk_forum_manage_context($classid, $cmid);

            if ($message === '') {
                throw new Exception('El comentario es obligatorio.');
            }
            if (!has_capability('mod/forum:replypost', $cmcontext)) {
                throw new Exception('No tienes permiso para comentar en este foro.');
            }

            $discussion = $DB->get_record(
                'forum_discussions',
                ['id' => (int)$discussionid, 'forum' => (int)$forum->id],
                'id, forum, name, firstpost',
                MUST_EXIST
            );

            $now = time();
            $subjectbase = trim((string)$discussion->name);
            if ($subjectbase === '') {
                $subjectbase = 'Tema';
            }
            $subject = 'Re: ' . $subjectbase;

            $reply = new stdClass();
            $reply->discussion = (int)$discussion->id;
            $reply->parent = (int)$discussion->firstpost;
            $reply->privatereplyto = 0;
            $reply->userid = (int)$USER->id;
            $reply->created = $now;
            $reply->modified = $now;
            $reply->mailed = 0;
            $reply->subject = $subject;
            $reply->message = $message;
            $reply->messageformat = FORMAT_HTML;
            $reply->messagetrust = 0;
            $reply->attachment = 0;
            $reply->mailnow = 0;
            $reply->wordcount = str_word_count(strip_tags($message));
            $reply->charcount = core_text::strlen(strip_tags($message));

            $postid = $DB->insert_record('forum_posts', $reply);
            if (!$postid) {
                throw new Exception('No se pudo crear el comentario.');
            }

            $DB->set_field('forum_discussions', 'timemodified', $now, ['id' => (int)$discussion->id]);
            $DB->set_field('forum_discussions', 'usermodified', (int)$USER->id, ['id' => (int)$discussion->id]);
            $DB->set_field('forum', 'timemodified', $now, ['id' => (int)$forum->id]);

            $response = ['status' => 'success', 'postid' => (int)$postid];
            break;

        case 'local_grupomakro_check_grace_period':
            // Server-to-server endpoint: Express queries Moodle to check grace period.
            // Accepts a shared token; falls back to requiring a valid Moodle session.
            $token = optional_param('token', '', PARAM_TEXT);
            $expected_token = get_config('local_grupomakro_core', 'grace_period_token') ?: 'gmk_grace_check_2026';
            if ($token !== $expected_token) {
                require_login();
            }

            if (!get_config('local_grupomakro_core', 'grace_period_enabled')) {
                $response = ['status' => 'success', 'inGrace' => false];
                break;
            }

            $documentnumber = required_param('documentnumber', PARAM_TEXT);
            $now = time();
            $grace = $DB->get_record_select(
                'gmk_grace_period',
                'documentnumber = :doc AND graceuntil >= :now',
                ['doc' => $documentnumber, 'now' => $now],
                'id, graceuntil',
                IGNORE_MISSING
            );
            $response = [
                'status'     => 'success',
                'inGrace'    => !empty($grace),
                'graceuntil' => $grace ? (int)$grace->graceuntil : null,
            ];
            break;

        case 'debug_inspect_post':
            // TEMPORARY DEBUG Ã¢â‚¬â€ remove after diagnosis
            require_capability('moodle/site:config', context_system::instance());
            $response = [
                'status' => 'success',
                'action_received' => $action,
                'post_keys' => array_keys($_POST),
                'post_action' => $_POST['action'] ?? '(not set)',
                'content_type' => $_SERVER['CONTENT_TYPE'] ?? '(not set)',
                'request_method' => $_SERVER['REQUEST_METHOD'] ?? '(not set)',
                'post_max_size' => ini_get('post_max_size'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'php_input_available' => !empty(file_get_contents('php://input')) ? 'yes (json path)' : 'no',
            ];
            break;

        case 'local_grupomakro_upload_draft_file':
            // Sube un archivo al draft area del usuario (paso previo a crear/editar actividad)
            if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                $upload_error = !empty($_FILES['file']) ? $_FILES['file']['error'] : 'no file received';
                $response = ['status' => 'error', 'message' => 'No se recibiÃƒÂ³ ningÃƒÂºn archivo o hubo un error al subirlo. Error: ' . $upload_error];
                break;
            }
            $draftitemid = optional_param('draftitemid', 0, PARAM_INT);
            if (!$draftitemid) {
                $draftitemid = file_get_unused_draft_itemid();
            }
            $usercontext = context_user::instance($USER->id);
            $fs = get_file_storage();
            $fname = gmk_ajax_make_unique_filename(
                $fs,
                (int)$usercontext->id,
                'user',
                'draft',
                (int)$draftitemid,
                '/',
                (string)$_FILES['file']['name']
            );
            $fs->create_file_from_pathname([
                'contextid' => $usercontext->id,
                'component' => 'user',
                'filearea'  => 'draft',
                'itemid'    => $draftitemid,
                'filepath'  => '/',
                'filename'  => $fname,
                'userid'    => $USER->id,
            ], $_FILES['file']['tmp_name']);
            $response = [
                'status'      => 'success',
                'draftitemid' => $draftitemid,
                'filename'    => $fname,
            ];
            break;

        default:
            $response['message'] = 'Action not found: ' . $action;
            break;
    }
} catch (Throwable $e) {
    $response['status'] = 'error';
    $response['message'] = $e->getMessage();
    if (function_exists('gmk_log')) {
        gmk_log("AJAX ERROR [{$action}]: " . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }
}

$output = ob_get_clean();
// If there was some unexpected output, we might want to log it or ignore it.
// For now, prioritize returning clean JSON.

header('Content-Type: application/json');
echo json_encode($response);
die();


