<?php
namespace local_grupomakro_core\external\student;

use context_course;
use context_module;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use stdClass;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/forum/lib.php');

class forum_activity extends external_api {

    private static function resolve_forum_context(int $courseid, int $moduleid): array {
        global $DB, $USER;

        $course = get_course($courseid);
        $cm = get_coursemodule_from_id('', $moduleid, $course->id, false, MUST_EXIST);

        if ((string)$cm->modname !== 'forum') {
            throw new Exception('El modulo no es un foro.');
        }

        $forum = $DB->get_record(
            'forum',
            ['id' => (int)$cm->instance, 'course' => (int)$course->id],
            '*',
            MUST_EXIST
        );

        $coursecontext = context_course::instance((int)$course->id);
        $cmcontext = context_module::instance((int)$cm->id);

        if (!is_enrolled($coursecontext, $USER, '', true) && !is_siteadmin()) {
            throw new Exception('No estas matriculado en este curso.');
        }

        if (!has_capability('mod/forum:viewdiscussion', $cmcontext) && !has_capability('mod/forum:viewforum', $cmcontext) && !is_siteadmin()) {
            throw new Exception('No tienes permiso para ver este foro.');
        }

        return [$course, $cm, $forum, $coursecontext, $cmcontext];
    }

    private static function get_user_fullname(int $userid): string {
        global $DB;
        $user = $DB->get_record('user', ['id' => $userid], 'id,firstname,lastname', IGNORE_MISSING);
        if (!$user) {
            return 'Desconocido';
        }
        return fullname($user);
    }

    public static function get_activity_data_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseId' => new external_value(PARAM_INT, 'Course id', VALUE_REQUIRED),
            'moduleId' => new external_value(PARAM_INT, 'Forum course module id', VALUE_REQUIRED),
        ]);
    }

    public static function get_activity_data($courseId, $moduleId) {
        global $DB;
        $params = self::validate_parameters(self::get_activity_data_parameters(), [
            'courseId' => $courseId,
            'moduleId' => $moduleId,
        ]);

        try {
            list($course, $cm, $forum, $coursecontext, $cmcontext) = self::resolve_forum_context((int)$params['courseId'], (int)$params['moduleId']);

            $canstartdiscussion = has_capability('mod/forum:startdiscussion', $cmcontext) || is_siteadmin();
            $canreply = has_capability('mod/forum:replypost', $cmcontext) || is_siteadmin();

            $discussions = $DB->get_records(
                'forum_discussions',
                ['forum' => (int)$forum->id],
                'timemodified DESC, id DESC',
                'id,forum,name,firstpost,userid,timemodified',
                0,
                100
            );

            $firstpostids = [];
            foreach ($discussions as $d) {
                if (!empty($d->firstpost)) {
                    $firstpostids[] = (int)$d->firstpost;
                }
            }
            $firstpostids = array_values(array_unique($firstpostids));

            $firstposts = [];
            if (!empty($firstpostids)) {
                $firstposts = $DB->get_records_list(
                    'forum_posts',
                    'id',
                    $firstpostids,
                    '',
                    'id,discussion,userid,subject,message,messageformat,created'
                );
            }

            $rows = [];
            foreach ($discussions as $d) {
                $first = (!empty($d->firstpost) && isset($firstposts[(int)$d->firstpost])) ? $firstposts[(int)$d->firstpost] : null;
                $plain = '';
                if ($first) {
                    $plain = trim(strip_tags(format_text($first->message, $first->messageformat, ['context' => $cmcontext])));
                }
                $rows[] = [
                    'id' => (int)$d->id,
                    'subject' => (string)$d->name,
                    'author' => self::get_user_fullname((int)$d->userid),
                    'timemodified' => (int)$d->timemodified,
                    'replies' => max(0, (int)$DB->count_records('forum_posts', ['discussion' => (int)$d->id]) - 1),
                    'preview' => $plain !== '' ? core_text::substr($plain, 0, 220) : ''
                ];
            }

            $result = [
                'courseid' => (int)$course->id,
                'moduleid' => (int)$cm->id,
                'forumid' => (int)$forum->id,
                'name' => (string)$cm->name,
                'intro' => !empty($forum->intro) ? format_text($forum->intro, $forum->introformat, ['context' => $cmcontext]) : '',
                'canStartDiscussion' => $canstartdiscussion ? 1 : 0,
                'canReply' => $canreply ? 1 : 0,
                'discussions' => $rows,
            ];

            return [
                'status' => 1,
                'message' => 'ok',
                'forumData' => json_encode($result)
            ];
        } catch (Exception $e) {
            return [
                'status' => -1,
                'message' => $e->getMessage(),
                'forumData' => json_encode(['discussions' => []])
            ];
        }
    }

    public static function get_activity_data_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_INT, '1 ok, -1 error', VALUE_DEFAULT, 1),
            'message' => new external_value(PARAM_TEXT, 'Result message', VALUE_DEFAULT, 'ok'),
            'forumData' => new external_value(PARAM_RAW, 'JSON object with forum data', VALUE_DEFAULT, '{}'),
        ]);
    }

    public static function get_discussion_posts_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseId' => new external_value(PARAM_INT, 'Course id', VALUE_REQUIRED),
            'moduleId' => new external_value(PARAM_INT, 'Forum course module id', VALUE_REQUIRED),
            'discussionId' => new external_value(PARAM_INT, 'Discussion id', VALUE_REQUIRED),
        ]);
    }

    public static function get_discussion_posts($courseId, $moduleId, $discussionId) {
        global $DB, $USER;
        $params = self::validate_parameters(self::get_discussion_posts_parameters(), [
            'courseId' => $courseId,
            'moduleId' => $moduleId,
            'discussionId' => $discussionId,
        ]);

        try {
            list($course, $cm, $forum, $coursecontext, $cmcontext) = self::resolve_forum_context((int)$params['courseId'], (int)$params['moduleId']);
            $discussion = $DB->get_record(
                'forum_discussions',
                ['id' => (int)$params['discussionId'], 'forum' => (int)$forum->id],
                'id,forum,name,firstpost,userid,timemodified',
                MUST_EXIST
            );

            $posts = $DB->get_records(
                'forum_posts',
                ['discussion' => (int)$discussion->id],
                'created ASC, id ASC',
                'id,discussion,parent,userid,subject,message,messageformat,created,modified'
            );

            $rows = [];
            foreach ($posts as $post) {
                $rows[] = [
                    'id' => (int)$post->id,
                    'parent' => (int)$post->parent,
                    'userid' => (int)$post->userid,
                    'author' => self::get_user_fullname((int)$post->userid),
                    'subject' => (string)$post->subject,
                    'message' => format_text($post->message, $post->messageformat, ['context' => $cmcontext]),
                    'created' => (int)$post->created,
                    'modified' => (int)$post->modified,
                    'isMine' => ((int)$post->userid === (int)$USER->id) ? 1 : 0,
                ];
            }

            return [
                'status' => 1,
                'message' => 'ok',
                'postsData' => json_encode([
                    'discussion' => [
                        'id' => (int)$discussion->id,
                        'subject' => (string)$discussion->name,
                        'timemodified' => (int)$discussion->timemodified
                    ],
                    'posts' => $rows
                ])
            ];
        } catch (Exception $e) {
            return [
                'status' => -1,
                'message' => $e->getMessage(),
                'postsData' => json_encode(['posts' => []])
            ];
        }
    }

    public static function get_discussion_posts_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_INT, '1 ok, -1 error', VALUE_DEFAULT, 1),
            'message' => new external_value(PARAM_TEXT, 'Result message', VALUE_DEFAULT, 'ok'),
            'postsData' => new external_value(PARAM_RAW, 'JSON object with discussion posts', VALUE_DEFAULT, '{}'),
        ]);
    }

    public static function create_discussion_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseId' => new external_value(PARAM_INT, 'Course id', VALUE_REQUIRED),
            'moduleId' => new external_value(PARAM_INT, 'Forum course module id', VALUE_REQUIRED),
            'subject' => new external_value(PARAM_TEXT, 'Discussion subject', VALUE_REQUIRED),
            'message' => new external_value(PARAM_RAW, 'Discussion message', VALUE_REQUIRED),
        ]);
    }

    public static function create_discussion($courseId, $moduleId, $subject, $message) {
        $params = self::validate_parameters(self::create_discussion_parameters(), [
            'courseId' => $courseId,
            'moduleId' => $moduleId,
            'subject' => $subject,
            'message' => $message,
        ]);

        try {
            list($course, $cm, $forum, $coursecontext, $cmcontext) = self::resolve_forum_context((int)$params['courseId'], (int)$params['moduleId']);
            $subject = trim((string)$params['subject']);
            $message = trim((string)$params['message']);

            if ($subject === '') {
                throw new Exception('El titulo del tema es obligatorio.');
            }
            if ($message === '') {
                throw new Exception('El mensaje del tema es obligatorio.');
            }
            if (!has_capability('mod/forum:startdiscussion', $cmcontext) && !is_siteadmin()) {
                throw new Exception('No tienes permiso para crear temas en este foro.');
            }

            $discussion = new stdClass();
            $discussion->course = (int)$course->id;
            $discussion->forum = (int)$forum->id;
            $discussion->name = $subject;
            $discussion->message = $message;
            $discussion->messageformat = FORMAT_HTML;
            $discussion->messagetrust = 0;
            $discussion->mailnow = 0;
            $discussion->groupid = -1;
            $discussion->timestart = 0;
            $discussion->timeend = 0;
            $discussion->pinned = 0;

            $discussionid = (int)forum_add_discussion($discussion);

            return [
                'status' => 1,
                'message' => 'ok',
                'id' => $discussionid
            ];
        } catch (Exception $e) {
            return [
                'status' => -1,
                'message' => $e->getMessage(),
                'id' => 0
            ];
        }
    }

    public static function create_discussion_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_INT, '1 ok, -1 error', VALUE_DEFAULT, 1),
            'message' => new external_value(PARAM_TEXT, 'Result message', VALUE_DEFAULT, 'ok'),
            'id' => new external_value(PARAM_INT, 'Created discussion id', VALUE_DEFAULT, 0),
        ]);
    }

    public static function create_reply_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseId' => new external_value(PARAM_INT, 'Course id', VALUE_REQUIRED),
            'moduleId' => new external_value(PARAM_INT, 'Forum course module id', VALUE_REQUIRED),
            'discussionId' => new external_value(PARAM_INT, 'Discussion id', VALUE_REQUIRED),
            'message' => new external_value(PARAM_RAW, 'Reply message', VALUE_REQUIRED),
        ]);
    }

    public static function create_reply($courseId, $moduleId, $discussionId, $message) {
        global $DB;
        $params = self::validate_parameters(self::create_reply_parameters(), [
            'courseId' => $courseId,
            'moduleId' => $moduleId,
            'discussionId' => $discussionId,
            'message' => $message,
        ]);

        try {
            list($course, $cm, $forum, $coursecontext, $cmcontext) = self::resolve_forum_context((int)$params['courseId'], (int)$params['moduleId']);
            $message = trim((string)$params['message']);
            if ($message === '') {
                throw new Exception('El comentario es obligatorio.');
            }
            if (!has_capability('mod/forum:replypost', $cmcontext) && !is_siteadmin()) {
                throw new Exception('No tienes permiso para comentar en este foro.');
            }

            $discussion = $DB->get_record(
                'forum_discussions',
                ['id' => (int)$params['discussionId'], 'forum' => (int)$forum->id],
                'id,forum,name,firstpost',
                MUST_EXIST
            );

            $post = new stdClass();
            $post->discussion = (int)$discussion->id;
            $post->parent = (int)$discussion->firstpost;
            $post->subject = 'Re: ' . trim((string)$discussion->name);
            $post->message = $message;
            $post->messageformat = FORMAT_HTML;
            $post->messagetrust = 0;
            $post->itemid = 0;

            $postid = (int)forum_add_new_post($post, null);

            return [
                'status' => 1,
                'message' => 'ok',
                'id' => $postid
            ];
        } catch (Exception $e) {
            return [
                'status' => -1,
                'message' => $e->getMessage(),
                'id' => 0
            ];
        }
    }

    public static function create_reply_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_INT, '1 ok, -1 error', VALUE_DEFAULT, 1),
            'message' => new external_value(PARAM_TEXT, 'Result message', VALUE_DEFAULT, 'ok'),
            'id' => new external_value(PARAM_INT, 'Created post id', VALUE_DEFAULT, 0),
        ]);
    }
}

