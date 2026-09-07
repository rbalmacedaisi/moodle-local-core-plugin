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

/**
 * Signed BigBlueButton API call.
 */
function gmk_bbb_api_call($action, $params, $url, $secret) {
    $query = http_build_query($params, '', '&');
    $checksum = sha1($action . $query . $secret);
    $request_url = $url . 'api/' . $action . '?' . $query . '&checksum=' . $checksum;

    $curl = new curl();
    $response = $curl->get($request_url);
    return simplexml_load_string($response);
}

// Backwards-compatible alias: the legacy block further down still calls this name.
function bbb_api_call($action, $params, $url, $secret) {
    return gmk_bbb_api_call($action, $params, $url, $secret);
}

/**
 * Stable per-browser token used to recognise the host when they reload or rejoin.
 * Guests are not logged in, but Moodle keeps an anonymous session for them, so
 * $SESSION survives across requests from the same browser.
 */
function gmk_guest_host_token() {
    global $SESSION;
    if (empty($SESSION->gmk_guest_host_token)) {
        $SESSION->gmk_guest_host_token = sha1(random_string(32) . microtime(true));
    }
    return $SESSION->gmk_guest_host_token;
}

/**
 * Decide whether this joiner owns the meeting (BBB moderator) or is a plain viewer.
 *
 * Business rule: THE FIRST PERSON TO CONNECT IS THE HOST. From inside the session
 * that host can promote anybody else to presenter so they can share their screen
 * ("Convertir en presentador"), or to moderator.
 *
 * The claim is stored per meeting RUN - BBB's createTime changes every time the
 * meeting is created again - so each new session starts a fresh race and the
 * first joiner of that run becomes host again.
 *
 * Concurrency: the claim is an INSERT on a UNIQUE index, so two people opening
 * the link at the same instant cannot both win; the loser hits a duplicate key
 * and falls through to viewer.
 *
 * @return bool true if this joiner must be sent in as moderator.
 */
function gmk_claim_meeting_host($cmid, $meetingid, $createtime, $hosttoken, $fullname, $moderatorcount, $running) {
    global $DB, $USER;

    // Housekeeping: claims from meetings that ended long ago are dead weight.
    $DB->delete_records_select('gmk_guest_meeting_host', 'timecreated < :cutoff',
        ['cutoff' => time() - (30 * DAYSECS)]);

    $runkey = sha1($meetingid . '|' . $createtime);
    $now = time();

    $existing = $DB->get_record('gmk_guest_meeting_host', ['runkey' => $runkey]);

    if ($existing) {
        // The host came back (reload, dropped connection, second tab).
        if ($existing->hosttoken === $hosttoken) {
            $DB->set_field('gmk_guest_meeting_host', 'timemodified', $now, ['id' => $existing->id]);
            return true;
        }

        // Rescue path: the host is gone and nobody is moderating, so the session
        // would be left with no one able to share a screen. Hand the role over.
        // The grace period matters: right after the host claims the run they are
        // not inside the meeting yet, so moderatorCount is legitimately 0 for a
        // few seconds and a second joiner must NOT steal the role in that window.
        $graceperiod = 90;
        if ($running && $moderatorcount === 0 && ($now - (int)$existing->timemodified) > $graceperiod) {
            $existing->hosttoken = $hosttoken;
            $existing->hostname = substr($fullname, 0, 255);
            $existing->userid = (int)$USER->id;
            $existing->timemodified = $now;
            $DB->update_record('gmk_guest_meeting_host', $existing);
            return true;
        }

        return false;
    }

    // Nobody has claimed this run yet: first come, first served.
    $record = (object)[
        'cmid'         => (int)$cmid,
        'meetingid'    => substr($meetingid, 0, 100),
        'createtime'   => substr((string)$createtime, 0, 32),
        'runkey'       => $runkey,
        'hosttoken'    => $hosttoken,
        'hostname'     => substr($fullname, 0, 255),
        'userid'       => (int)$USER->id,
        'timecreated'  => $now,
        'timemodified' => $now,
    ];

    try {
        $DB->insert_record('gmk_guest_meeting_host', $record);
        return true;
    } catch (Exception $e) {
        // Lost the race against a simultaneous joiner: they are the host.
        return false;
    }
}

if ($action === 'join' && !empty($username)) {
    // 1. Get Session Details (Manual DB query to avoid page setup reqs if possible, but we have config)
    // We can use standard calls since config.php is loaded
    $cm = get_coursemodule_from_id('bigbluebuttonbn', $id, 0, false, MUST_EXIST);
    $bbb = $DB->get_record('bigbluebuttonbn', array('id' => $cm->instance), '*', MUST_EXIST);

    $meetingID = $bbb->meetingid;

    // BBB Server Config
    $bbb_url = trim(get_config('core', 'bigbluebuttonbn_server_url'));
    if (empty($bbb_url)) $bbb_url = trim($CFG->bigbluebuttonbn_server_url ?? '');

    $bbb_secret = trim(get_config('core', 'bigbluebuttonbn_shared_secret'));
    if (empty($bbb_secret)) $bbb_secret = trim($CFG->bigbluebuttonbn_shared_secret ?? '');

    $bbb_secret = trim($bbb_secret);
    if (empty($bbb_url) || empty($bbb_secret)) {
        throw new moodle_exception('invalidrequest', 'error', '', null,
            'BigBlueButton no esta configurado: falta bigbluebuttonbn_server_url o bigbluebuttonbn_shared_secret.');
    }
    if (substr($bbb_url, -1) !== '/') $bbb_url .= '/';

    // 2. Make sure the meeting exists, so the link works even if no moderator has
    //    started the session yet. 'create' is idempotent in BBB: calling it on a
    //    live meeting returns SUCCESS with duplicateWarning and the ORIGINAL
    //    createTime, which is exactly the run identifier used below.
    $meeting_name = trim($bbb->name);
    if (strlen($meeting_name) < 2) {
        $meeting_name = "Sesion Virtual " . $meetingID;
    }

    $create_params = [
        'name' => $meeting_name,
        'meetingID' => $meetingID,
        'attendeePW' => $bbb->viewerpass,
        'moderatorPW' => $bbb->moderatorpass,
        'welcome' => $bbb->welcome,
        'record' => 'true',
        // Auto-start the recording and hide the manual start/stop button.
        // The manual record toggle was being double-fired by the client (two
        // RecordStatusEvent marks ~30-70ms apart for the same user), which made the
        // recording processor compute a ~0s playback even though ~100min of audio was
        // captured. Auto-start produces a single, clean record segment per meeting and
        // removes the toggle entirely, so the double-fire can no longer happen.
        'autoStartRecording' => 'true',
        'allowStartStopRecording' => 'false',
        // The host may legitimately drop out and come back; ending the meeting
        // while no moderator is connected would close the session for everybody.
        'endWhenNoModerator' => 'false',
        'moderatorOnlyMessage' => 'Usted es el anfitrion de esta sesion. Para que otro participante pueda '
            . 'proyectar, abralo en la lista de usuarios y elija "Convertir en presentador".',
    ];

    $create_xml = gmk_bbb_api_call('create', $create_params, $bbb_url, $bbb_secret);

    if ($create_xml && (string)$create_xml->returncode == 'FAILED') {
        // If creation failed, show WHY
        header('Content-Type: text/plain');
        echo "Error Creating Meeting:\n";
        print_r($create_xml);
        die();
    }

    $createtime = $create_xml ? (string)$create_xml->createTime : '';

    // 3. Read live state: is the meeting running and is anybody moderating it?
    $running = false;
    $moderatorcount = 0;
    $info = gmk_bbb_api_call('getMeetingInfo', ['meetingID' => $meetingID], $bbb_url, $bbb_secret);
    if ($info && (string)$info->returncode === 'SUCCESS') {
        $running = ((string)$info->running === 'true');
        $moderatorcount = (int)$info->moderatorCount;
        if ($createtime === '') {
            $createtime = (string)$info->createTime;
        }
    }
    if ($createtime === '') {
        // Last resort so the run key is never empty, which would collapse every
        // session into one claim. Degrades to "one run per meeting per day".
        $createtime = 'nocreatetime-' . date('Ymd');
    }

    // 4. Resolve the role.
    $hosttoken = gmk_guest_host_token();

    // Staff who can manage meetings always come in as moderator: they are the ones
    // expected to run the session, and this keeps an admin from being stuck as a
    // viewer because a guest opened the link first.
    $isstaff = isloggedin() && !isguestuser()
        && has_capability('local/grupomakro_core:manage_meetings', context_system::instance());

    // The claim runs even for staff (no short-circuit) so that a teacher joining
    // first also takes the run and the next guest does NOT become a second host.
    $claimedhost = gmk_claim_meeting_host(
        $id, $meetingID, $createtime, $hosttoken, $username, $moderatorcount, $running
    );

    $ismoderator = $isstaff || $claimedhost;

    // 5. Join.
    $params = [
        'fullName' => $username,
        'meetingID' => $meetingID,
        // 'password' is what older BBB releases understand; 'role' is what 2.4+
        // uses and takes precedence there. Sending both keeps this working across
        // the server upgrade without another code change.
        'password' => $ismoderator ? $bbb->moderatorpass : $bbb->viewerpass,
        'role' => $ismoderator ? 'MODERATOR' : 'VIEWER',
        // Stable per-browser id so BBB treats a reload as the same person instead
        // of leaving a ghost attendee behind.
        'userID' => 'gmk-' . substr($hosttoken, 0, 24),
        'redirect' => 'true'
    ];

    // Force '&' separator to avoid php.ini arg_separator.output issues (e.g. &amp;)
    $query = http_build_query($params, '', '&');
    $checksum = sha1('join' . $query . $bbb_secret);

    $join_url = $bbb_url . 'api/join?' . $query . '&checksum=' . $checksum;

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
                <div class="alert alert-info small" role="alert">
                    <strong>La primera persona en entrar sera el anfitrion</strong> de la sesion.
                    Si la sesion ya esta en curso usted entrara como participante; el anfitrion puede
                    darle permiso para proyectar desde la lista de usuarios
                    (<em>Convertir en presentador</em>).
                </div>
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
