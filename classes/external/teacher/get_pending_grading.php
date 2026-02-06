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
                'classid' => new external_value(PARAM_INT, 'Optional Class ID to filter', VALUE_DEFAULT, 0)
            )
        );
    }

    public static function execute($userid, $classid = 0) {
        global $DB, $PAGE;

        $params = self::validate_parameters(self::execute_parameters(), array('userid' => $userid, 'classid' => $classid));
        
        $context = \context_system::instance();
        self::validate_context($context);

        // Use helper from locallib.php
        $submissions = gmk_get_pending_grading_items($params['userid'], $params['classid']);
        
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
            
            if ($sub->modname === 'assign') {
                $cm = get_coursemodule_from_instance('assign', $sub->itemid);
                if ($cm) {
                    $item->cmid = $cm->id;
                    $context_mod = \context_module::instance($cm->id);
                    $files = $fs->get_area_files($context_mod->id, 'assignsubmission_file', 'submission_files', $sub->submissionid, 'sortorder', false);
                    
                    foreach ($files as $file) {
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
                    'files' => new external_multiple_structure(
                        new external_single_structure(
                            array(
                                'filename' => new external_value(PARAM_TEXT, 'File Name'),
                                'fileurl' => new external_value(PARAM_URL, 'Download URL'),
                                'mimetype' => new external_value(PARAM_TEXT, 'Mime Type', VALUE_OPTIONAL)
                            )
                        )
                    )
                )
            )
        );
    }
}
