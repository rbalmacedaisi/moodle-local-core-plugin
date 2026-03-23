<?php
namespace local_grupomakro_core\external\teacher;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use stdClass;

class get_pending_grading extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters(
            array(
                'userid' => new external_value(PARAM_INT, 'The ID of the teacher', VALUE_REQUIRED),
                'classid' => new external_value(PARAM_INT, 'Optional Class ID to filter', VALUE_DEFAULT, 0),
                'status' => new external_value(PARAM_ALPHA, 'Status (pending/history)', VALUE_DEFAULT, 'pending')
            )
        );
    }

    public static function execute($userid, $classid = 0, $status = 'pending') {
        global $DB, $PAGE;

        $params = self::validate_parameters(self::execute_parameters(), array(
            'userid' => $userid, 
            'classid' => $classid,
            'status' => $status
        ));
        
        $context = \context_system::instance();
        self::validate_context($context);

        // Use helper from locallib.php
        $submissions = gmk_get_pending_grading_items($params['userid'], $params['classid'], $params['status']);
        
        $result = [];
        $fs = get_file_storage();

        foreach ($submissions as $sub) {
            $item = new stdClass();
            $item->id = $sub->submissionid; 
            $item->assignmentid = $sub->itemid;
            $item->modname = $sub->modname;
            $item->assignmentname = $sub->itemname;
            $item->studentid = $sub->userid;
            $item->studentname = fullname($sub);
            $item->studentemail = $sub->email;
            
            // Avatar logic
            $userobj = new stdClass();
            $userobj->id = $sub->userid;
            $userobj->picture = $sub->picture;
            $userobj->firstname = $sub->firstname;
            $userobj->lastname = $sub->lastname;
            $userobj->imagealt = $sub->imagealt;
            $userobj->email = $sub->email;
            $user_picture = new \user_picture($userobj);
            $user_picture->size = 1; // f1 size
            $item->studentavatar = $user_picture->get_url($PAGE)->out(false);

            $item->submissiontime = $sub->submissiontime;
            $item->duedate = $sub->duedate;
            $item->courseid = $sub->courseid;
            $item->coursename = $sub->coursename;
            
            // Files logic (only for assign for now)
            $item->files = [];
            $item->submissiontext = '';
            $item->submissiontexthtml = '';
            $item->submissiontextplain = '';
            
            if ($sub->modname === 'assign') {
                $cm = get_coursemodule_from_instance('assign', (int)$sub->itemid, (int)$sub->courseid, false, IGNORE_MISSING);
                if ($cm) {
                    $item->cmid = $cm->id;
                    $context_mod = \context_module::instance($cm->id);

                    // Resolve the effective latest submission id for this user+assignment.
                    $effectivesubmissionid = (int)$sub->submissionid;
                    $latestusersubmission = $DB->get_record_sql(
                        "SELECT id
                           FROM {assign_submission}
                          WHERE assignment = :assignmentid
                            AND userid = :userid
                       ORDER BY latest DESC, timemodified DESC, id DESC",
                        [
                            'assignmentid' => (int)$sub->itemid,
                            'userid' => (int)$sub->userid,
                        ],
                        IGNORE_MULTIPLE
                    );
                    if ($latestusersubmission && (int)$latestusersubmission->id > 0) {
                        $effectivesubmissionid = (int)$latestusersubmission->id;
                    }

                    // Submission online text (includes inline images with @@PLUGINFILE@@ placeholders).
                    $onlinetext = $DB->get_record(
                        'assignsubmission_onlinetext',
                        ['assignment' => (int)$sub->itemid, 'submission' => $effectivesubmissionid],
                        'id,onlinetext,onlineformat',
                        IGNORE_MISSING
                    );
                    if (!$onlinetext) {
                        // Fallback: locate latest online text row by assignment + student submissions.
                        $onlinetext = $DB->get_record_sql(
                            "SELECT ot.id, ot.onlinetext, ot.onlineformat
                               FROM {assignsubmission_onlinetext} ot
                               JOIN {assign_submission} s ON s.id = ot.submission
                              WHERE ot.assignment = :assignmentid
                                AND s.assignment = :assignmentid2
                                AND s.userid = :userid
                           ORDER BY s.latest DESC, s.timemodified DESC, s.id DESC, ot.id DESC",
                            [
                                'assignmentid' => (int)$sub->itemid,
                                'assignmentid2' => (int)$sub->itemid,
                                'userid' => (int)$sub->userid,
                            ],
                            IGNORE_MULTIPLE
                        );
                    }

                    $onlinetextfileitemids = [$effectivesubmissionid];
                    if ($onlinetext && (int)$onlinetext->id > 0 && !in_array((int)$onlinetext->id, $onlinetextfileitemids, true)) {
                        $onlinetextfileitemids[] = (int)$onlinetext->id;
                    }
                    // Moodle core uses "submissions_onlinetext"; keep legacy "onlinetext" fallback.
                    $onlinetextfileareas = ['submissions_onlinetext', 'onlinetext'];

                    if ($onlinetext && trim((string)$onlinetext->onlinetext) !== '') {
                        $rawtext = (string)$onlinetext->onlinetext;
                        $rewriteitemid = $effectivesubmissionid;
                        $rewritefilearea = $onlinetextfileareas[0];
                        foreach ($onlinetextfileitemids as $candidateitemid) {
                            foreach ($onlinetextfileareas as $candidatefilearea) {
                                $candidatefiles = $fs->get_area_files(
                                    (int)$context_mod->id,
                                    'assignsubmission_onlinetext',
                                    $candidatefilearea,
                                    (int)$candidateitemid,
                                    'sortorder',
                                    false
                                );
                                if (!empty($candidatefiles)) {
                                    $rewriteitemid = (int)$candidateitemid;
                                    $rewritefilearea = $candidatefilearea;
                                    break 2;
                                }
                            }
                        }

                        $rewrittentext = file_rewrite_pluginfile_urls(
                            $rawtext,
                            'pluginfile.php',
                            (int)$context_mod->id,
                            'assignsubmission_onlinetext',
                            $rewritefilearea,
                            $rewriteitemid
                        );
                        $formattedtext = format_text(
                            $rewrittentext,
                            (int)$onlinetext->onlineformat,
                            [
                                'context' => $context_mod,
                                'overflowdiv' => true,
                                'para' => false,
                            ]
                        );
                        $item->submissiontext = $rawtext;
                        $item->submissiontexthtml = $formattedtext;
                        $item->submissiontextplain = trim(strip_tags($formattedtext));
                    }

                    $files = $fs->get_area_files(
                        (int)$context_mod->id,
                        'assignsubmission_file',
                        'submission_files',
                        $effectivesubmissionid,
                        'sortorder',
                        false
                    );
                    $inlinefiles = [];
                    foreach ($onlinetextfileitemids as $inlineitemid) {
                        foreach ($onlinetextfileareas as $inlinefilearea) {
                            $inlinechunk = $fs->get_area_files(
                                (int)$context_mod->id,
                                'assignsubmission_onlinetext',
                                $inlinefilearea,
                                (int)$inlineitemid,
                                'sortorder',
                                false
                            );
                            if (!empty($inlinechunk)) {
                                $inlinefiles = array_merge($inlinefiles, $inlinechunk);
                            }
                        }
                    }
                    $seenhash = [];
                     
                    foreach (array_merge($files, $inlinefiles) as $file) {
                         $hash = (string)$file->get_pathnamehash();
                         if ($hash !== '' && isset($seenhash[$hash])) {
                             continue;
                         }
                         if ($hash !== '') {
                             $seenhash[$hash] = true;
                         }
                         $f = new stdClass();
                         $f->filename = $file->get_filename();
                         $url = \moodle_url::make_pluginfile_url(
                            $file->get_contextid(),
                            $file->get_component(),
                            $file->get_filearea(),
                            $file->get_itemid(),
                            $file->get_filepath(),
                            $file->get_filename()
                         );
                         $f->fileurl = $url->out(false);
                         $f->mimetype = $file->get_mimetype();
                         $f->filesize = (int)$file->get_filesize();
                         $f->source = ((string)$file->get_component() === 'assignsubmission_onlinetext') ? 'onlinetext' : 'submission_file';
                         $item->files[] = $f;
                    }
                }
            } else if ($sub->modname === 'quiz') {
                $cm = get_coursemodule_from_instance('quiz', $sub->itemid);
                if ($cm) {
                    $item->cmid = $cm->id;
                }
            }

            $result[] = $item;
        }

        return $result;
    }

    public static function execute_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                array(
                    'id' => new external_value(PARAM_INT, 'Submission ID'),
                    'assignmentid' => new external_value(PARAM_INT, 'Item (Assignment/Quiz) ID'),
                    'cmid' => new external_value(PARAM_INT, 'Course Module ID', VALUE_OPTIONAL),
                    'modname' => new external_value(PARAM_TEXT, 'Module name (assign/quiz)', VALUE_OPTIONAL),
                    'assignmentname' => new external_value(PARAM_TEXT, 'Item Name'),
                    'studentid' => new external_value(PARAM_INT, 'Student User ID'),
                    'studentname' => new external_value(PARAM_TEXT, 'Student Fullname'),
                    'studentemail' => new external_value(PARAM_TEXT, 'Student Email'),
                    'studentavatar' => new external_value(PARAM_URL, 'Student Avatar URL'),
                    'submissiontime' => new external_value(PARAM_INT, 'Submission Timestamp'),
                    'duedate' => new external_value(PARAM_INT, 'Due Date Timestamp'),
                    'courseid' => new external_value(PARAM_INT, 'Course ID'),
                    'coursename' => new external_value(PARAM_TEXT, 'Course Name'),
                    'submissiontext' => new external_value(PARAM_RAW, 'Submission text (raw)', VALUE_DEFAULT, ''),
                    'submissiontexthtml' => new external_value(PARAM_RAW, 'Submission text (formatted html)', VALUE_DEFAULT, ''),
                    'submissiontextplain' => new external_value(PARAM_RAW, 'Submission text (plain)', VALUE_DEFAULT, ''),
                    'files' => new external_multiple_structure(
                        new external_single_structure(
                            array(
                                'filename' => new external_value(PARAM_TEXT, 'File Name'),
                                'fileurl' => new external_value(PARAM_URL, 'Download URL'),
                                'mimetype' => new external_value(PARAM_TEXT, 'Mime Type', VALUE_OPTIONAL),
                                'filesize' => new external_value(PARAM_INT, 'File size in bytes', VALUE_OPTIONAL),
                                'source' => new external_value(PARAM_TEXT, 'Source area (submission_file/onlinetext)', VALUE_OPTIONAL)
                            )
                        )
                    )
                )
            )
        );
    }
}
