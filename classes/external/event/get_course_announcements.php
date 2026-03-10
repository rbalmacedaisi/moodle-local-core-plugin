<?php
namespace local_grupomakro_core\external\event;

use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;
use Exception;

defined('MOODLE_INTERNAL') || die;

class get_course_announcements extends external_api
{
    public static function execute_parameters(): external_function_parameters
    {
        return new external_function_parameters([
            'courseId' => new external_value(PARAM_INT, 'Moodle course id', VALUE_REQUIRED),
        ]);
    }

    public static function execute($courseId)
    {
        global $DB, $CFG;

        $params = self::validate_parameters(self::execute_parameters(), ['courseId' => $courseId]);
        $courseId = $params['courseId'];

        try {
            require_once($CFG->dirroot . '/mod/forum/lib.php');

            // Try the 'news' type forum (Moodle announcements), fallback to a forum named "Avisos"
            $forum = forum_get_course_forum($courseId, 'news');
            if (!$forum) {
                $forum = $DB->get_record_select(
                    'forum',
                    "course = :courseid AND " . $DB->sql_like('name', ':name', false),
                    ['courseid' => $courseId, 'name' => 'Avisos%']
                );
            }

            if (!$forum) {
                return ['status' => 1, 'posts' => json_encode([])];
            }

            $discussions = $DB->get_records(
                'forum_discussions',
                ['forum' => $forum->id],
                'timemodified DESC',
                'id, name, userid, timemodified',
                0, 10
            );

            $cm_forum     = get_coursemodule_from_instance('forum', $forum->id, $courseId, false, MUST_EXIST);
            $forum_context = context_module::instance($cm_forum->id);
            $fs            = get_file_storage();

            $posts = [];
            foreach ($discussions as $disc) {
                $first_post = $DB->get_record(
                    'forum_posts',
                    ['discussion' => $disc->id, 'parent' => 0],
                    'id, message, messageformat, attachment'
                );
                $author = $DB->get_record('user', ['id' => $disc->userid], 'firstname, lastname');

                $attachments = [];
                if ($first_post && $first_post->attachment) {
                    $files = $fs->get_area_files(
                        $forum_context->id, 'mod_forum', 'attachment',
                        $first_post->id, 'filename', false
                    );
                    foreach ($files as $f) {
                        $attachments[] = [
                            'filename' => $f->get_filename(),
                            'url'      => \moodle_url::make_pluginfile_url(
                                $forum_context->id, 'mod_forum', 'attachment',
                                $first_post->id, '/', $f->get_filename()
                            )->out(false),
                            'mimetype' => $f->get_mimetype(),
                            'filesize' => (int) $f->get_filesize(),
                        ];
                    }
                }

                $posts[] = [
                    'id'           => (int) $disc->id,
                    'subject'      => $disc->name,
                    'message'      => $first_post ? format_text($first_post->message, $first_post->messageformat) : '',
                    'author'       => $author ? fullname($author) : 'Desconocido',
                    'timemodified' => (int) $disc->timemodified,
                    'attachments'  => $attachments,
                ];
            }

            return ['status' => 1, 'posts' => json_encode($posts)];
        } catch (Exception $e) {
            return ['status' => -1, 'posts' => json_encode([])];
        }
    }

    public static function execute_returns(): external_description
    {
        return new external_single_structure([
            'status' => new external_value(PARAM_INT, '1 ok, -1 error', VALUE_DEFAULT, 1),
            'posts'  => new external_value(PARAM_RAW, 'JSON array of announcement posts', VALUE_DEFAULT, '[]'),
        ]);
    }
}
