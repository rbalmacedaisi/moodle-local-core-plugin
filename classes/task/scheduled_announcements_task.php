<?php
namespace local_grupomakro_core\task;

defined('MOODLE_INTERNAL') || die();

class scheduled_announcements_task extends \core\task\scheduled_task {
    public function get_name() {
        return "Procesar Anuncios Programados (Grupo Makro)";
    }

    public function execute() {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/forum/lib.php');

        $now = time();
        $announcements = $DB->get_records_select('gmk_scheduled_announcements', 
            'status = :status AND publishdate <= :now', 
            ['status' => 'pending', 'now' => $now]);

        foreach ($announcements as $announcement) {
            try {
                // Find news forum for the course
                $forum = forum_get_course_forum($announcement->courseid, 'news');
                if (!$forum) continue;

                $discussion = new \stdClass();
                $discussion->course = $announcement->courseid;
                $discussion->forum = $forum->id;
                $discussion->name = $announcement->subject;
                $discussion->intro = $announcement->message;
                $discussion->timemodified = $now;
                $discussion->userid = $announcement->usermodified;
                $discussion->assumedaudit = true;

                $post = new \stdClass();
                $post->userid = $announcement->usermodified;
                $post->subject = $announcement->subject;
                $post->message = $announcement->message;
                $post->messageformat = FORMAT_HTML;
                $post->messagetrust = 0;

                forum_add_discussion($discussion, $post);

                // Update status
                $announcement->status = 'published';
                $DB->update_record('gmk_scheduled_announcements', $announcement);
                
                mtrace("Publicado anuncio ID {$announcement->id} en curso {$announcement->courseid}");
            } catch (\Exception $e) {
                mtrace("Error publicando anuncio ID {$announcement->id}: " . $e->getMessage());
            }
        }
    }
}
