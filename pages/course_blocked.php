<?php
/**
 * Course Blocked page.
 * Displayed when a student tries to access a class whose access has been
 * revoked by the staged absence alert system. Redirects here from the
 * navigation guard in lib.php.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/absence_helpers.php');

require_login();

$classid = optional_param('classid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/course_blocked.php', ['classid' => $classid, 'courseid' => $courseid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('course_blocked_title', 'local_grupomakro_core'));
$PAGE->set_heading(get_string('course_blocked_title', 'local_grupomakro_core'));
$PAGE->set_pagelayout('standard');
$PAGE->add_body_class('gmk-full-frame');

$coursename = '';
$threshold = absd_get_block_threshold();
if ($classid > 0) {
    $coursename = absd_get_class_display_name($classid);
} else if ($courseid > 0) {
    $course = $DB->get_record('course', ['id' => $courseid], 'fullname', IGNORE_MISSING);
    $coursename = $course ? $course->fullname : '';
}

$homeurl = new moodle_url('/');

echo $OUTPUT->header();
?>
<style>
    .blocked-container {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-height: 60vh;
        text-align: center;
        padding: 2rem;
        background: #fafafa;
        border-radius: 12px;
        box-shadow: 0 4px 10px rgba(0,0,0,0.06);
        max-width: 720px;
        margin: 2rem auto;
    }
    .blocked-icon {
        font-size: 5rem;
        color: #9e9e9e;
        margin-bottom: 1rem;
    }
    .blocked-title {
        font-size: 1.6rem;
        font-weight: 600;
        color: #424242;
        margin-bottom: 0.5rem;
    }
    .blocked-message {
        font-size: 1.05rem;
        color: #616161;
        margin-bottom: 1.5rem;
        max-width: 560px;
        line-height: 1.5;
    }
    .blocked-action {
        display: inline-block;
        padding: 0.7rem 1.4rem;
        background: #13527f;
        color: #fff;
        border-radius: 6px;
        text-decoration: none;
        font-weight: 600;
    }
    .blocked-action:hover { background: #0e3f5e; }
</style>

<div class="blocked-container">
    <div class="blocked-icon">
        <span class="mdi mdi-lock-outline" aria-hidden="true"></span>
    </div>
    <div class="blocked-title"><?php echo get_string('course_blocked_title', 'local_grupomakro_core'); ?></div>
    <div class="blocked-message">
        <?php echo get_string('course_blocked_intro', 'local_grupomakro_core', s($coursename)); ?><br><br>
        <?php echo get_string('course_blocked_reason', 'local_grupomakro_core', $threshold); ?><br><br>
        <?php echo get_string('course_blocked_action', 'local_grupomakro_core'); ?>
    </div>
    <a class="blocked-action" href="<?php echo $homeurl->out(); ?>">
        <?php echo get_string('course_blocked_back', 'local_grupomakro_core'); ?>
    </a>
</div>

<?php
echo $OUTPUT->footer();
