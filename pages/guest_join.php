<?php
// Custom Guest Join Page for BigBlueButton
// Replaces the missing guest_login.php in older plugins

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$id = required_param('id', PARAM_INT); // Course Module ID
$action = optional_param('action', '', PARAM_ALPHA);
$username = optional_param('username', '', PARAM_TEXT);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/guest_join.php', array('id' => $id)));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Unirse a Sesión Virtual');
$PAGE->set_heading('Unirse a la Reunión');
$PAGE->set_pagelayout('standard');

echo $OUTPUT->header();

// 1. Get Session Details
$cm = get_coursemodule_from_id('bigbluebuttonbn', $id, 0, false, MUST_EXIST);
$bbb = $DB->get_record('bigbluebuttonbn', array('id' => $cm->instance), '*', MUST_EXIST);
// Note: We cannot rely on 'guest' column. We assume if they have the link, they can try to join.
// Security: In a stricter system, checking a secret token or specific course context would be better.

// 2. Handle Form Submission
if ($action === 'join' && !empty($username)) {
    // Construct BBB API Join URL
    $meetingID = $bbb->meetingid; // Usually defined in table
    // If meetingID is empty (new session not yet opened), it might be generated from id + courseid
    if (empty($meetingID)) {
        // Fallback pattern used by plugin: [md5(secret) + instanceID + courseID]... usually simpler:
        // Let's try to find if there's a cached meeting info or just use standard pattern
        // Pattern: (course_id)-(module_id) usually? or checking logs.
        // Actually, most reliable is:
        $meetingID = $bbb->meetingid; 
    }
    
    // We need the viewer password. It's stored in the DB usually.
    $password = $bbb->viewerpass; // 'moderatorpass' for moderators
    
    // BBB Server Config
    $bbb_url = trim(\mod_bigbluebuttonbn\locallib\config::get('server_url'));
    $bbb_secret = trim(\mod_bigbluebuttonbn\locallib\config::get('shared_secret'));
    
    // If locallib class structure is different in old version, fallbacks:
    if (empty($bbb_url)) $bbb_url = trim(get_config('bigbluebuttonbn', 'server_url'));
    if (empty($bbb_secret)) $bbb_secret = trim(get_config('bigbluebuttonbn', 'shared_secret'));
    
    // Build Query
    if (!str_ends_with($bbb_url, '/')) $bbb_url .= '/';
    $api_call = 'join';
    $params = [
        'meetingID' => $meetingID,
        'fullName' => $username,
        'password' => $password,
        'redirect' => 'true'
    ];
    
    // Ensure meetingID is set. If not, maybe session didn't start? 
    // We'll proceed. The server will reject if invalid.
    
    // Checksum
    $query = http_build_query($params);
    $checksum = sha1($api_call . $query . $bbb_secret);
    
    $join_url = $bbb_url . 'api/' . $api_call . '?' . $query . '&checksum=' . $checksum;
    
    // Redirect
    redirect($join_url);
    exit;
}

// 3. Render Form
echo $OUTPUT->box_start('generalbox', 'guest-login-box');
?>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card">
            <div class="card-body">
                <h3 class="card-title text-center"><?php echo format_string($bbb->name); ?></h3>
                <p class="card-text text-center"><?php echo format_text($bbb->intro, $bbb->introformat); ?></p>
                <hr>
                <form action="guest_join.php" method="post">
                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                    <input type="hidden" name="action" value="join">
                    
                    <div class="form-group">
                        <label for="username">Su Nombre Completo:</label>
                        <input type="text" class="form-control" id="username" name="username" required placeholder="Ej: Juan Pérez">
                    </div>
                    
                    <div class="text-center mt-4">
                        <button type="submit" class="btn btn-primary btn-lg">Unirse a la Sesión</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
echo $OUTPUT->box_end();
echo $OUTPUT->footer();
