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
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), array('userid' => $userid, 'classid' => $classid));
        
        $context = \context_system::instance();
        self::validate_context($context);

        // Logic to fetch pending submissions
        // We need to find submissions where:
        // 1. Status is 'submitted'
        // 2. No grade exists or grade is -1 (not graded)
        // 3. User is enrolled in the course/class

        $sql = "SELECT s.id as submissionid, 
                       s.userid, 
                       s.assignment, 
                       s.timecreated as submissiontime,
                       a.name as assignmentname,
                       a.course as courseid,
                       a.duedate,
                       c.fullname as coursename,
                       u.firstname,
                       u.lastname,
                       u.email,
                       u.picture,
                       u.imagealt
                FROM {assign_submission} s
                JOIN {assign} a ON a.id = s.assignment
                JOIN {course} c ON c.id = a.course
                JOIN {user} u ON u.id = s.userid
                LEFT JOIN {assign_grades} g ON g.assignment = a.id AND g.userid = s.userid
                WHERE s.status = 'submitted' 
                  AND s.latest = 1
                  AND (g.grade IS NULL OR g.grade < 0)
                ";
        
        $query_params = [];
        
        // If class filter is applied (assuming class maps to a course or group)
        if ($classid > 0) {
            // Fetch class to get course/group info
            $class = $DB->get_record('gmk_class', ['id' => $classid]);
            if ($class) {
                // If the class is bound to a specific group, we must filter students by that group
                // But for now, let's just filter by course ID which is safer
                $sql .= " AND a.course = :courseid";
                $query_params['courseid'] = $class->courseid;
                
                // If we need group filtering:
                if (!empty($class->groupid)) {
                     $sql .= " AND EXISTS (SELECT 1 FROM {groups_members} gm WHERE gm.groupid = :groupid AND gm.userid = s.userid)";
                     $query_params['groupid'] = $class->groupid;
                }
            }
        } else {
             // If no class filter, we should ideally restrict to courses where $userid is a teacher
             // For simplicity/performance in this MVP, we assume the frontend sends requests for valid contexts.
             // OR improve query to join with context/role_assignments if needed.
             // For now, let's filter by the courses the instructor is assigned to in gmk_class
             $sql .= " AND EXISTS (SELECT 1 FROM {gmk_class} cls WHERE cls.courseid = a.course AND cls.instructorid = :instructorid)";
             $query_params['instructorid'] = $userid;
        }
        
        $sql .= " ORDER BY s.timecreated ASC";

        $submissions = $DB->get_records_sql($sql, $query_params);
        
        $result = [];
        $fs = get_file_storage();

        foreach ($submissions as $sub) {
            $item = new stdClass();
            $item->id = $sub->submissionid; // Submission ID
            $item->assignmentid = $sub->assignment;
            $item->assignmentname = $sub->assignmentname;
            $item->studentid = $sub->userid;
            $item->studentname = fullname($sub);
            $item->studentemail = $sub->email;
            $item->studentavatar = ''; // Could generate URL
            
            // Avatar logic
            $userobj = new stdClass();
            $userobj->id = $sub->userid;
            $userobj->picture = $sub->picture;
            $userobj->firstname = $sub->firstname;
            $userobj->lastname = $sub->lastname;
            $userobj->imagealt = $sub->imagealt;
            $userobj->email = $sub->email;
            global $PAGE;
            $user_picture = new \user_picture($userobj);
            $user_picture->size = 1; // f1 size
            $item->studentavatar = $user_picture->get_url($PAGE)->out(false);

            $item->submissiontime = $sub->submissiontime;
            $item->duedate = $sub->duedate;
            $item->courseid = $sub->courseid;
            $item->coursename = $sub->coursename;
            
            // Files
            $item->files = [];
            
            // Get files from the submission
            // We need the context of the module to get the files
            $cm = get_coursemodule_from_instance('assign', $sub->assignment);
            if ($cm) {
                $context = \context_module::instance($cm->id);
                $files = $fs->get_area_files($context->id, 'assignsubmission_file', 'submission_files', $sub->submissionid, 'sortorder', false);
                
                foreach ($files as $file) {
                     $f = new stdClass();
                     $f->filename = $file->get_filename();
                     // Generate a URL to download
                     // moodle/pluginfile.php/CONTEXTID/COMPONENT/FILEAREA/ITEMID/FILENAME
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

            $result[] = $item;
        }

        return $result;
    }

    public static function execute_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                array(
                    'id' => new external_value(PARAM_INT, 'Submission ID'),
                    'assignmentid' => new external_value(PARAM_INT, 'Assignment ID'),
                    'assignmentname' => new external_value(PARAM_TEXT, 'Assignment Name'),
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
