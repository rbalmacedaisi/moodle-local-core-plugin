<?php
namespace local_grupomakro_core\external\student;

use context_course;
use context_module;
use context_user;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use moodle_url;
use stdClass;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/assign/locallib.php');

class assign_activity extends external_api {

    private static function resolve_assign_context(int $courseid, int $moduleid): array {
        global $DB, $USER;

        $course = get_course($courseid);
        $cm = get_coursemodule_from_id('', $moduleid, $course->id, false, MUST_EXIST);

        if ((string)$cm->modname !== 'assign') {
            throw new Exception('The selected activity is not an assignment.');
        }

        $assignrecord = $DB->get_record(
            'assign',
            ['id' => (int)$cm->instance, 'course' => (int)$course->id],
            '*',
            MUST_EXIST
        );

        $coursecontext = context_course::instance((int)$course->id);
        $cmcontext = context_module::instance((int)$cm->id);

        if (!is_enrolled($coursecontext, $USER, '', true) && !is_siteadmin()) {
            throw new Exception('You are not enrolled in this course.');
        }

        if (!has_capability('mod/assign:view', $cmcontext) && !is_siteadmin()) {
            throw new Exception('You do not have permission to view this assignment.');
        }

        $assign = new \assign($cmcontext, $cm, $course);

        return [$course, $cm, $assignrecord, $assign, $coursecontext, $cmcontext];
    }

    private static function get_submission_plugins_state(\assign $assign): array {
        $state = [
            'file' => false,
            'onlinetext' => false,
        ];

        foreach ($assign->get_submission_plugins() as $plugin) {
            if (!$plugin->is_enabled() || !$plugin->is_visible()) {
                continue;
            }
            $type = (string)$plugin->get_type();
            if (array_key_exists($type, $state)) {
                $state[$type] = true;
            }
        }

        return $state;
    }

    private static function build_file_row(\stored_file $file): array {
        $url = moodle_url::make_pluginfile_url(
            (int)$file->get_contextid(),
            (string)$file->get_component(),
            (string)$file->get_filearea(),
            (int)$file->get_itemid(),
            (string)$file->get_filepath(),
            (string)$file->get_filename()
        );

        return [
            'filename' => (string)$file->get_filename(),
            'filepath' => (string)$file->get_filepath(),
            'filesize' => (int)$file->get_filesize(),
            'mimetype' => (string)$file->get_mimetype(),
            'timemodified' => (int)$file->get_timemodified(),
            'url' => $url->out(false),
        ];
    }

    private static function get_area_files_as_rows(
        int $contextid,
        string $component,
        string $filearea,
        int $itemid
    ): array {
        $fs = get_file_storage();
        $files = $fs->get_area_files($contextid, $component, $filearea, $itemid, 'timemodified DESC, id DESC', false);
        $rows = [];
        foreach ($files as $file) {
            if ($file->is_directory()) {
                continue;
            }
            $rows[] = self::build_file_row($file);
        }
        return $rows;
    }

    private static function count_user_draft_files(int $userid, int $draftitemid): int {
        if ($draftitemid <= 0) {
            return 0;
        }
        $fs = get_file_storage();
        $usercontextid = context_user::instance($userid)->id;
        $files = $fs->get_area_files($usercontextid, 'user', 'draft', $draftitemid, 'id', false);
        return count($files);
    }

    public static function get_activity_data_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseId' => new external_value(PARAM_INT, 'Course id', VALUE_REQUIRED),
            'moduleId' => new external_value(PARAM_INT, 'Assignment module id', VALUE_REQUIRED),
        ]);
    }

    public static function get_activity_data($courseId, $moduleId) {
        global $USER, $DB, $CFG;

        $params = self::validate_parameters(self::get_activity_data_parameters(), [
            'courseId' => $courseId,
            'moduleId' => $moduleId,
        ]);

        try {
            list($course, $cm, $assignrecord, $assign, $coursecontext, $cmcontext) =
                self::resolve_assign_context((int)$params['courseId'], (int)$params['moduleId']);

            $plugins = self::get_submission_plugins_state($assign);
            $submission = $assign->get_user_submission((int)$USER->id, false);

            $submissionid = $submission ? (int)$submission->id : 0;
            $submissionstatus = $submission ? (string)$submission->status : 'new';
            $submissiontimemodified = $submission ? (int)$submission->timemodified : 0;

            $submissiontext = '';
            $submissiontextformatted = '';
            $submissiontextplain = '';
            if ($submissionid > 0 && $plugins['onlinetext']) {
                $onlinetext = $DB->get_record(
                    'assignsubmission_onlinetext',
                    ['assignment' => (int)$assignrecord->id, 'submission' => $submissionid],
                    'id,onlinetext,onlineformat',
                    IGNORE_MISSING
                );
                if ($onlinetext && (string)$onlinetext->onlinetext !== '') {
                    $submissiontext = (string)$onlinetext->onlinetext;
                    $submissiontextformatted = format_text(
                        $submissiontext,
                        (int)$onlinetext->onlineformat,
                        ['context' => $cmcontext]
                    );
                    $submissiontextplain = trim(strip_tags($submissiontextformatted));
                }
            }

            $maxbytes = get_user_max_upload_file_size(
                $cmcontext,
                (int)$course->maxbytes,
                (int)$assignrecord->maxbytes
            );

            $maxfiles = (int)$assignrecord->maxfilesubmissions;
            if ($maxfiles <= 0) {
                $maxfiles = -1;
            }

            $draftitemid = file_get_unused_draft_itemid();
            if ($plugins['file']) {
                file_prepare_draft_area(
                    $draftitemid,
                    (int)$cmcontext->id,
                    'assignsubmission_file',
                    'submission_files',
                    $submissionid,
                    [
                        'subdirs' => 0,
                        'maxfiles' => $maxfiles,
                        'maxbytes' => $maxbytes,
                    ]
                );
            }

            $teacherattachments = self::get_area_files_as_rows(
                (int)$cmcontext->id,
                'mod_assign',
                'introattachment',
                0
            );

            $submissionfiles = [];
            if ($submissionid > 0 && $plugins['file']) {
                $submissionfiles = self::get_area_files_as_rows(
                    (int)$cmcontext->id,
                    'assignsubmission_file',
                    'submission_files',
                    $submissionid
                );
            }

            $cansubmit = (has_capability('mod/assign:submit', $cmcontext) || is_siteadmin()) ? 1 : 0;
            $submissionsopen = ($cansubmit && $assign->submissions_open((int)$USER->id)) ? 1 : 0;

            $payload = [
                'courseid' => (int)$course->id,
                'moduleid' => (int)$cm->id,
                'assignmentid' => (int)$assignrecord->id,
                'name' => (string)$cm->name,
                'intro' => !empty($assignrecord->intro)
                    ? format_text($assignrecord->intro, (int)$assignrecord->introformat, ['context' => $cmcontext])
                    : '',
                'allowsubmissionsfromdate' => (int)$assignrecord->allowsubmissionsfromdate,
                'duedate' => (int)$assignrecord->duedate,
                'cutoffdate' => (int)$assignrecord->cutoffdate,
                'submissiondrafts' => (int)$assignrecord->submissiondrafts,
                'plugins' => [
                    'fileEnabled' => $plugins['file'] ? 1 : 0,
                    'onlineTextEnabled' => $plugins['onlinetext'] ? 1 : 0,
                ],
                'limits' => [
                    'maxBytes' => (int)$maxbytes,
                    'maxFiles' => (int)$maxfiles,
                ],
                'permissions' => [
                    'canSubmit' => $cansubmit,
                    'submissionsOpen' => $submissionsopen,
                ],
                'teacherAttachments' => $teacherattachments,
                'submission' => [
                    'id' => $submissionid,
                    'status' => $submissionstatus,
                    'timemodified' => $submissiontimemodified,
                    'textRaw' => $submissiontext,
                    'textFormatted' => $submissiontextformatted,
                    'textPlain' => $submissiontextplain,
                    'files' => $submissionfiles,
                ],
                'upload' => [
                    'url' => rtrim((string)$CFG->wwwroot, '/') . '/webservice/upload.php',
                    'contextid' => (int)context_user::instance((int)$USER->id)->id,
                    'component' => 'user',
                    'filearea' => 'draft',
                    'itemid' => (int)$draftitemid,
                ],
            ];

            return [
                'status' => 1,
                'message' => 'ok',
                'errorCode' => '',
                'assignData' => json_encode($payload),
            ];
        } catch (Exception $e) {
            return [
                'status' => -1,
                'message' => $e->getMessage(),
                'errorCode' => 'assign_load_failed',
                'assignData' => json_encode([]),
            ];
        }
    }

    public static function get_activity_data_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_INT, '1 ok, -1 error', VALUE_DEFAULT, 1),
            'message' => new external_value(PARAM_TEXT, 'Result message', VALUE_DEFAULT, 'ok'),
            'errorCode' => new external_value(PARAM_ALPHANUMEXT, 'Machine error code', VALUE_DEFAULT, ''),
            'assignData' => new external_value(PARAM_RAW, 'JSON payload', VALUE_DEFAULT, '{}'),
        ]);
    }

    public static function submit_activity_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseId' => new external_value(PARAM_INT, 'Course id', VALUE_REQUIRED),
            'moduleId' => new external_value(PARAM_INT, 'Assignment module id', VALUE_REQUIRED),
            'comment' => new external_value(PARAM_RAW, 'Online text/comment', VALUE_DEFAULT, ''),
            'draftItemId' => new external_value(PARAM_INT, 'User draft item id with uploaded files', VALUE_DEFAULT, 0),
        ]);
    }

    public static function submit_activity($courseId, $moduleId, $comment = '', $draftItemId = 0) {
        global $USER;

        $params = self::validate_parameters(self::submit_activity_parameters(), [
            'courseId' => $courseId,
            'moduleId' => $moduleId,
            'comment' => $comment,
            'draftItemId' => $draftItemId,
        ]);

        try {
            list($course, $cm, $assignrecord, $assign, $coursecontext, $cmcontext) =
                self::resolve_assign_context((int)$params['courseId'], (int)$params['moduleId']);

            if (!has_capability('mod/assign:submit', $cmcontext) && !is_siteadmin()) {
                return [
                    'status' => -1,
                    'message' => 'You do not have permission to submit this assignment.',
                    'errorCode' => 'no_submit_permission',
                    'submissionData' => json_encode([]),
                ];
            }

            if (!$assign->submissions_open((int)$USER->id)) {
                return [
                    'status' => -1,
                    'message' => 'Submissions are closed for this assignment.',
                    'errorCode' => 'submissions_closed',
                    'submissionData' => json_encode([]),
                ];
            }

            $plugins = self::get_submission_plugins_state($assign);
            $comment = trim((string)$params['comment']);
            $draftitemid = (int)$params['draftItemId'];

            if ($plugins['file'] && $draftitemid <= 0) {
                $draftitemid = file_get_unused_draft_itemid();
            }

            $draftfilescount = 0;
            if ($plugins['file']) {
                $draftfilescount = self::count_user_draft_files((int)$USER->id, $draftitemid);
            }

            $hastext = $plugins['onlinetext'] && ($comment !== '');
            $hasfiles = $plugins['file'] && ($draftfilescount > 0);

            if (!$hastext && !$hasfiles) {
                return [
                    'status' => -1,
                    'message' => 'Add a comment or upload at least one file before submitting.',
                    'errorCode' => 'empty_submission',
                    'submissionData' => json_encode([]),
                ];
            }

            $data = new stdClass();
            $data->userid = (int)$USER->id;
            $data->submissionstatement = 1;

            if ($plugins['file']) {
                $data->files_filemanager = $draftitemid;
            }

            if ($plugins['onlinetext']) {
                $data->onlinetext_editor = [
                    'text' => $comment,
                    'format' => FORMAT_HTML,
                    'itemid' => 0,
                ];
            }

            $notices = [];
            $saved = $assign->save_submission($data, $notices);
            if (!$saved) {
                return [
                    'status' => -1,
                    'message' => !empty($notices) ? implode(' | ', $notices) : 'Could not save submission.',
                    'errorCode' => 'save_submission_failed',
                    'submissionData' => json_encode([]),
                ];
            }

            if ((int)$assignrecord->submissiondrafts === 1) {
                $submitdata = new stdClass();
                $submitdata->userid = (int)$USER->id;
                $submitdata->submissionstatement = 1;
                $submitnotices = [];
                $submitted = $assign->submit_for_grading($submitdata, $submitnotices);
                if (!$submitted) {
                    return [
                        'status' => -1,
                        'message' => !empty($submitnotices)
                            ? implode(' | ', $submitnotices)
                            : 'Submission was saved as draft but could not be sent for grading.',
                        'errorCode' => 'submit_for_grading_failed',
                        'submissionData' => json_encode([]),
                    ];
                }
            }

            $updatedsubmission = $assign->get_user_submission((int)$USER->id, false);
            $updatedsubmissionid = $updatedsubmission ? (int)$updatedsubmission->id : 0;
            $updatedfiles = [];
            if ($updatedsubmissionid > 0 && $plugins['file']) {
                $updatedfiles = self::get_area_files_as_rows(
                    (int)$cmcontext->id,
                    'assignsubmission_file',
                    'submission_files',
                    $updatedsubmissionid
                );
            }

            return [
                'status' => 1,
                'message' => 'Submission sent successfully.',
                'errorCode' => '',
                'submissionData' => json_encode([
                    'submissionid' => $updatedsubmissionid,
                    'status' => $updatedsubmission ? (string)$updatedsubmission->status : 'new',
                    'timemodified' => $updatedsubmission ? (int)$updatedsubmission->timemodified : 0,
                    'files' => $updatedfiles,
                ]),
            ];
        } catch (Exception $e) {
            return [
                'status' => -1,
                'message' => $e->getMessage(),
                'errorCode' => 'assign_submit_failed',
                'submissionData' => json_encode([]),
            ];
        }
    }

    public static function submit_activity_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_INT, '1 ok, -1 error', VALUE_DEFAULT, 1),
            'message' => new external_value(PARAM_TEXT, 'Result message', VALUE_DEFAULT, 'ok'),
            'errorCode' => new external_value(PARAM_ALPHANUMEXT, 'Machine error code', VALUE_DEFAULT, ''),
            'submissionData' => new external_value(PARAM_RAW, 'JSON payload', VALUE_DEFAULT, '{}'),
        ]);
    }
}
