<?php
// Custom Guest Join Page for BigBlueButton
// Replaces the missing guest_login.php in older plugins

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/filelib.php');

$id = required_param('id', PARAM_INT); // Course Module ID
$action = optional_param('action', '', PARAM_ALPHA);
$username = optional_param('username', '', PARAM_TEXT);

// MOVED LOGIC TO TOP TO PREVENT ANY HTML/REDIRECT INTERFERENCE
if ($action === 'join' && !empty($username)) {
    // 1. Get Session Details (Manual DB query to avoid page setup reqs if possible, but we have config)
    // We can use standard calls since config.php is loaded
    $cm = get_coursemodule_from_id('bigbluebuttonbn', $id, 0, false, MUST_EXIST);
    $bbb = $DB->get_record('bigbluebuttonbn', array('id' => $cm->instance), '*', MUST_EXIST);

    $meetingID = $bbb->meetingid; 
    $password = $bbb->viewerpass;
    
    // BBB Server Config
    $bbb_url = trim(get_config('core', 'bigbluebuttonbn_server_url'));
    if (empty($bbb_url)) $bbb_url = trim($CFG->bigbluebuttonbn_server_url ?? '');

    $bbb_secret = trim(get_config('core', 'bigbluebuttonbn_shared_secret'));
    if (empty($bbb_secret)) $bbb_secret = trim($CFG->bigbluebuttonbn_shared_secret ?? '');

    $bbb_secret = trim($bbb_secret);
    // 4. Check if meeting is running, if not, CREATE it.
    // This ensures the guest link works even if no moderator has started the session yet. with correct params
    
    // Helper for API calls
    function bbb_api_call($action, $params, $url, $secret) {
        $query = http_build_query($params, '', '&');
        $checksum = sha1($action . $query . $secret);
        $request_url = $url . 'api/' . $action . '?' . $query . '&checksum=' . $checksum;
        
        $curl = new curl();
        $response = $curl->get($request_url);
        return simplexml_load_string($response);
    }
    
    // Check status
    $xml = bbb_api_call('isMeetingRunning', ['meetingID' => $meetingID], $bbb_url, $bbb_secret);
    
    // DEBUG API
    // header('Content-Type: text/plain'); print_r($xml); die(); 

    if ($xml && (string)$xml->running == 'false') {
        // Meeting not running. We must CREATE it.
        $meeting_name = trim($bbb->name);
        if (strlen($meeting_name) < 2) {
             $meeting_name = "Sesión Virtual " . $meetingID;
        }

        $create_params = [
            'name' => $meeting_name,
            'meetingID' => $meetingID,
            'attendeePW' => $bbb->viewerpass,
            'moderatorPW' => $bbb->moderatorpass,
            'welcome' => $bbb->welcome,
            'record' => 'false',
        ];
        
        // Add optional params
        if (!empty($bbb->welcome)) $create_params['welcome'] = $bbb->welcome;
        
        $create_xml = bbb_api_call('create', $create_params, $bbb_url, $bbb_secret);
        
        // DEBUG CREATE
        /*
        echo "Creating Meeting...\n";
        print_r($create_xml);
        */
        
        // Only die if it REALLY fails
        if ($create_xml && (string)$create_xml->returncode == 'FAILED') {
             // If creation failed, show WHY
             header('Content-Type: text/plain');
             echo "Error Creating Meeting:\n";
             print_r($create_xml);
             die();
        }
    }

    $api_call = 'join';
    
    $params = [
        'fullName' => $username,
        'meetingID' => $meetingID,
        // 'password' => $password, // MOVED BELOW
        'redirect' => 'true'
    ];
    
    // Decide which password to use. If we assume guest is VIEWER, use viewerpass.
    // If we want them to allow start, we'd need moderator pass, but usually not for guests.
    $params['password'] = $password;
    
    // Force '&' separator to avoid php.ini arg_separator.output issues (e.g. &amp;)
    $query = http_build_query($params, '', '&');
    $checksum = sha1($api_call . $query . $bbb_secret);
    
    $join_url = $bbb_url . 'api/' . $api_call . '?' . $query . '&checksum=' . $checksum;
    
    // Redirect
    redirect($join_url);
    exit;
}

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
    // Retrieved from global config based on DB dump
    $bbb_url = trim(get_config('core', 'bigbluebuttonbn_server_url'));
    if (empty($bbb_url)) $bbb_url = trim($CFG->bigbluebuttonbn_server_url ?? '');

    $bbb_secret = trim(get_config('core', 'bigbluebuttonbn_shared_secret'));
    if (empty($bbb_secret)) $bbb_secret = trim($CFG->bigbluebuttonbn_shared_secret ?? '');

    // Final fallback if still empty (unlikely given DB dump)
    if (empty($bbb_url) || empty($bbb_secret)) {
        print_error('noconfig', 'mod_bigbluebuttonbn');
    }
    
    // Build Query - Ensure parameters are URL encoded correctly
    // trim() prevents issues with copy-pasted config values
    $bbb_secret = trim($bbb_secret);
    
    if (substr($bbb_url, -1) !== '/') $bbb_url .= '/';
    $api_call = 'join';
    
    // http_build_query handles URL encoding. Checksum calculation MUST use the exact query string.
    $params = [
        'fullName' => $username,
        'meetingID' => $meetingID, // Order doesn't strictly matter for BBB, but convention
        'password' => $password,
        'redirect' => 'true'
    ];
    
    $query = http_build_query($params);
    $checksum = sha1($api_call . $query . $bbb_secret);
    
    $join_url = $bbb_url . 'api/' . $api_call . '?' . $query . '&checksum=' . $checksum;
    
    // DEBUG: Checksum mismatch investigation
    // Prevent redirect and show exactly what is being hashed
    $base_string = $api_call . $query . $bbb_secret;
    echo "<h3>Debugging Checksum Error</h3>";
    echo "<b>Meeting ID:</b> " . $meetingID . "<br>";
    echo "<b>Password:</b> " . $password . "<br>";
    echo "<b>Secret (Length):</b> " . strlen($bbb_secret) . "<br>";
    echo "<b>Secret (First 5):</b> " . substr($bbb_secret, 0, 5) . "...<br>";
    echo "<b>Base String for Checksum:</b> " . $api_call . $query . "[SECRET]<br>";
    echo "<b>Calculated Checksum:</b> " . $checksum . "<br>";
    echo "<b>Final URL:</b> " . $join_url . "<br>";
    echo "<hr>";
    echo "<a href='" . $join_url . "'>Click here to try link manually</a>";
    die();
    
    // Redirect
    // redirect($join_url);
    // exit;
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
