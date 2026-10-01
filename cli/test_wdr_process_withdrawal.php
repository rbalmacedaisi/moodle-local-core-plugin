<?php
/**
 * test_wdr_process_withdrawal.php
 *
 * Smoke test for wdr_manager::process_withdrawal. Mocks the HTTP layer
 * (call_odoo_proxy) so we can drive the four states:
 *   - no balance, no force  -> procesada
 *   - has balance, force=true with reason -> procesada + audit
 *   - has balance, force=false, block ON -> moodle_exception wdr_pending_balance
 *   - has balance, force=true, missing reason -> moodle_exception wdr_force_reason_required
 *   - balance check curl error -> moodle_exception wdr_balance_check_failed
 *
 * Idempotent: cleans up the WDR row it creates. Run:
 *   cd /var/www/html/moodle && sudo -u www-data php \
 *     local/grupomakro_core/cli/test_wdr_process_withdrawal.php
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wdr_manager.php');

global $DB;

/**
 * In-process monkey patch of wdr_manager::call_odoo_proxy. We use a
 * static-property swap to inject a mock closure that returns the canned
 * response set by the test before each call.
 */
class wdr_proxy_mock {
    public static $queue = [];
    public static $error = null;

    public static function reset() {
        self::$queue = [];
        self::$error = null;
    }
    public static function respond($response) {
        // Single-arg form to stay compatible with PHP <5.6 variadics.
        // Call once per expected call. To queue multiple, call repeatedly
        // before invoking the method under test.
        self::$queue[] = $response;
    }
    public static function setQueue(array $responses) {
        self::$queue = $responses;
    }
    public static function fail($err) {
        self::$error = $err;
    }
}

// Reflection: bind a closure that delegates to the mock at the right moment.
$rc = new ReflectionMethod('local_grupomakro_core\\local\\wdr_manager', 'call_odoo_proxy');
// We can't easily replace a static method via reflection. Instead, set
// wdr_proxy_mock and have a derived wrapper. Simplest: use runkit/override
// is not available; rebuild via a child class is heavy. So we create a
// subclass that overrides call_odoo_proxy.

if (!class_exists('local_grupomakro_core\\local\\wdr_manager_test_proxy')) {
    eval('namespace local_grupomakro_core\\local; class wdr_manager_test_proxy extends wdr_manager {
        public static function call_odoo_proxy(string $method, string $path, array $query = array(), array $payload = array()): array {
            if (\\wdr_proxy_mock::$error !== null) {
                $err = \\wdr_proxy_mock::$error;
                \\wdr_proxy_mock::$error = null;
                return array("status" => 0, "body" => null, "error" => $err);
            }
            if (!empty(\\wdr_proxy_mock::$queue)) {
                $r = array_shift(\\wdr_proxy_mock::$queue);
                return $r;
            }
            // Default: success stub.
            return array("status" => 200, "body" => array(
                "success" => true, "action" => "retiro",
                "partner_id" => 17, "partner_name" => "Mock Student",
                "hasBalance" => false, "forced" => false,
                "invoices_updated" => 1, "subscriptions_updated" => 1,
                "moodle_updated" => true,
            ), "error" => null);
        }
    }');
}

// Bootstrap a wdr row for an existing student + admin.
// Find an admin user (Bienestar / Director Academico) and a student.
$admin = $DB->get_record_sql(
    "SELECT u.id FROM {user} u JOIN {role_assignments} ra ON ra.userid = u.id
     JOIN {role} r ON r.id = ra.roleid
     WHERE r.shortname = ? AND u.deleted = 0 LIMIT 1",
    ['gmk_director_academico']);
if (!$admin) {
    fwrite(STDERR, "No gmk_director_academico user found; cannot test.\n");
    exit(2);
}
$adminid = (int)$admin->id;

// Pick any user with a documentnumber custom field.
$student = $DB->get_record_sql(
    "SELECT u.id, u.firstname, u.lastname, d.data AS documentnumber
       FROM {user} u
       JOIN {user_info_data} d ON d.userid = u.id
       JOIN {user_info_field} f ON f.id = d.fieldid AND f.shortname = 'documentnumber'
      WHERE u.deleted = 0 AND d.data != '' LIMIT 1");
if (!$student) {
    fwrite(STDERR, "No student with documentnumber on file; cannot test.\n");
    exit(2);
}
fwrite(STDOUT, "Test student: id={$student->id} doc='{$student->documentnumber}'\n");
fwrite(STDOUT, "Test admin: id={$adminid}\n");

// Create a wdr row directly (bypass the wizard, the goal is to test the
// process_withdrawal flow, not the create flow).
$alloc = local_grupomakro_core\local\wdr_manager::generate_request_number();
$now = time();
$inserted = (object)[
    'userid' => (int)$student->id,
    'request_number' => $alloc['request_number'],
    'fullname' => fullname((object)['firstname' => $student->firstname, 'lastname' => $student->lastname]),
    'program' => 'Test',
    'status' => 'recibida_direccion_academica',
    'timecreated' => $now, 'timemodified' => $now, 'usermodified' => $adminid,
];
$inserted->id = $DB->insert_record('gmk_wdr', $inserted);
fwrite(STDOUT, "Created test WDR id={$inserted->id} request_number={$inserted->request_number}\n");

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { fwrite(STDOUT, "  OK   $name\n"); $pass++; }
    else       { fwrite(STDOUT, "  FAIL $name: $detail\n"); $fail++; }
}

try {
    // Test 1: no balance, no force -> procesada
    fwrite(STDOUT, "\n# 1. No balance, no force -> procesada\n");
    wdr_proxy_mock::reset();
    wdr_proxy_mock::respond(['status' => 200, 'body' => [
        'success' => true, 'documentNumber' => $student->documentnumber,
        'hasBalance' => false, 'total' => 0, 'currency' => 'USD',
        'invoiceCount' => 0, 'overdueCount' => 0,
    ], 'error' => null]);
    wdr_proxy_mock::respond(['status' => 200, 'body' => [
        'success' => true, 'action' => 'retiro',
        'partner_id' => 17, 'partner_name' => 'Mock',
        'hasBalance' => false, 'forced' => false,
        'invoices_updated' => 1, 'subscriptions_updated' => 1,
        'moodle_updated' => true,
    ], 'error' => null]);
    $row = local_grupomakro_core\local\wdr_manager_test_proxy::process_withdrawal(
        (int)$inserted->id, $adminid, false, '');
    check('status procesada', $row->status === 'procesada', "got {$row->status}");
    check('processed_by == admin', (int)$row->processed_by === $adminid);
    check('process_invoices_updated == 1', (int)$row->process_invoices_updated === 1);
    check('no force audit (forced_at == 0)', (int)$row->forced_at === 0);

    // Reset the row for test 2.
    $DB->set_field('gmk_wdr', 'status', 'recibida_direccion_academica', ['id' => $inserted->id]);
    $DB->set_field('gmk_wdr', 'forced_at', 0, ['id' => $inserted->id]);
    $DB->set_field('gmk_wdr', 'forced_by', 0, ['id' => $inserted->id]);
    $DB->set_field('gmk_wdr', 'forced_reason', '', ['id' => $inserted->id]);
    $DB->set_field('gmk_wdr', 'processed_at', 0, ['id' => $inserted->id]);
    $DB->set_field('gmk_wdr', 'processed_by', 0, ['id' => $inserted->id]);
    $DB->set_field('gmk_wdr', 'process_odoo_partner_id', 0, ['id' => $inserted->id]);
    $DB->set_field('gmk_wdr', 'process_balance_total', 0, ['id' => $inserted->id]);
    $DB->set_field('gmk_wdr', 'process_balance_currency', '', ['id' => $inserted->id]);
    $DB->set_field('gmk_wdr', 'process_invoices_updated', 0, ['id' => $inserted->id]);
    $DB->set_field('gmk_wdr', 'process_subs_updated', 0, ['id' => $inserted->id]);

    // Test 2: has balance + force=true with reason -> procesada + forced audit
    fwrite(STDOUT, "\n# 2. Has balance, force=true, with reason -> procesada + forced audit\n");
    wdr_proxy_mock::reset();
    wdr_proxy_mock::respond(['status' => 200, 'body' => [
        'success' => true, 'documentNumber' => $student->documentnumber,
        'hasBalance' => true, 'total' => 1234.56, 'currency' => 'USD',
        'invoiceCount' => 1, 'overdueCount' => 1,
    ], 'error' => null]);
    wdr_proxy_mock::respond(['status' => 200, 'body' => [
        'success' => true, 'action' => 'retiro',
        'partner_id' => 17, 'hasBalance' => true, 'forced' => true,
        'invoices_updated' => 1, 'subscriptions_updated' => 1,
        'moodle_updated' => true,
    ], 'error' => null]);
    $row = local_grupomakro_core\local\wdr_manager_test_proxy::process_withdrawal(
        (int)$inserted->id, $adminid, true,
        'Override aprobado por Direccion Academica el 2026-10-01.');
    check('status procesada', $row->status === 'procesada', "got {$row->status}");
    check('forced_at populated', (int)$row->forced_at > 0);
    check('forced_by == admin', (int)$row->forced_by === $adminid);
    check('forced_reason saved', trim((string)$row->forced_reason) !== '');
    check('process_balance_total == 1234.56', abs((float)$row->process_balance_total - 1234.56) < 0.01,
        "got {$row->process_balance_total}");
    check('process_balance_currency == USD', trim((string)$row->process_balance_currency) === 'USD');

    // Reset.
    $DB->set_field('gmk_wdr', 'status', 'recibida_direccion_academica', ['id' => $inserted->id]);
    $DB->set_field('gmk_wdr', 'forced_at', 0, ['id' => $inserted->id]);
    $DB->set_field('gmk_wdr', 'forced_by', 0, ['id' => $inserted->id]);
    $DB->set_field('gmk_wdr', 'forced_reason', '', ['id' => $inserted->id]);
    $DB->set_field('gmk_wdr', 'processed_at', 0, ['id' => $inserted->id]);
    $DB->set_field('gmk_wdr', 'processed_by', 0, ['id' => $inserted->id]);
    $DB->set_field('gmk_wdr', 'process_odoo_partner_id', 0, ['id' => $inserted->id]);
    $DB->set_field('gmk_wdr', 'process_balance_total', 0, ['id' => $inserted->id]);
    $DB->set_field('gmk_wdr', 'process_balance_currency', '', ['id' => $inserted->id]);
    $DB->set_field('gmk_wdr', 'process_invoices_updated', 0, ['id' => $inserted->id]);
    $DB->set_field('gmk_wdr', 'process_subs_updated', 0, ['id' => $inserted->id]);

    // Test 3: has balance + force=false + block ON -> wdr_pending_balance exception
    fwrite(STDOUT, "\n# 3. Has balance, force=false, block ON -> moodle_exception wdr_pending_balance\n");
    wdr_proxy_mock::reset();
    wdr_proxy_mock::respond(['status' => 200, 'body' => [
        'success' => true, 'documentNumber' => $student->documentnumber,
        'hasBalance' => true, 'total' => 500, 'currency' => 'USD',
        'invoiceCount' => 1, 'overdueCount' => 0,
    ], 'error' => null]);
    try {
        local_grupomakro_core\local\wdr_manager_test_proxy::process_withdrawal(
            (int)$inserted->id, $adminid, false, '');
        check('exception thrown', false, 'no exception was raised');
    } catch (\moodle_exception $e) {
        check('exception thrown', true);
        check('errorcode == wdr_pending_balance',
            $e->errorcode === 'wdr_pending_balance',
            "got '{$e->errorcode}'");
        // Row must remain in recibida_direccion_academica (NOT flipped to procesada).
        $current = $DB->get_field('gmk_wdr', 'status', ['id' => $inserted->id]);
        check('row status unchanged', $current === 'recibida_direccion_academica',
            "got '$current'");
    }

    // Test 4: has balance + force=true + no reason -> wdr_force_reason_required
    fwrite(STDOUT, "\n# 4. Has balance, force=true, missing reason -> moodle_exception wdr_force_reason_required\n");
    wdr_proxy_mock::reset();
    wdr_proxy_mock::respond(['status' => 200, 'body' => [
        'success' => true, 'hasBalance' => true, 'total' => 500,
        'currency' => 'USD', 'invoiceCount' => 1, 'overdueCount' => 0,
    ], 'error' => null]);
    try {
        local_grupomakro_core\local\wdr_manager_test_proxy::process_withdrawal(
            (int)$inserted->id, $adminid, true, '');
        check('exception thrown', false, 'no exception was raised');
    } catch (\moodle_exception $e) {
        check('errorcode == wdr_force_reason_required',
            $e->errorcode === 'wdr_force_reason_required',
            "got '{$e->errorcode}'");
    }

    // Test 5: balance check curl error -> wdr_balance_check_failed
    fwrite(STDOUT, "\n# 5. Balance check curl error -> moodle_exception wdr_balance_check_failed\n");
    wdr_proxy_mock::reset();
    wdr_proxy_mock::fail('Connection refused');
    try {
        local_grupomakro_core\local\wdr_manager_test_proxy::process_withdrawal(
            (int)$inserted->id, $adminid, false, '');
        check('exception thrown', false, 'no exception was raised');
    } catch (\moodle_exception $e) {
        check('errorcode == wdr_balance_check_failed',
            $e->errorcode === 'wdr_balance_check_failed',
            "got '{$e->errorcode}'");
    }

    // Test 6: invalid state (solicitada) -> invalidwdrstatus
    fwrite(STDOUT, "\n# 6. Wrong state (solicitada) -> moodle_exception invalidwdrstatus\n");
    $DB->set_field('gmk_wdr', 'status', 'solicitada', ['id' => $inserted->id]);
    wdr_proxy_mock::reset();
    try {
        local_grupomakro_core\local\wdr_manager_test_proxy::process_withdrawal(
            (int)$inserted->id, $adminid, false, '');
        check('exception thrown', false, 'no exception was raised');
    } catch (\moodle_exception $e) {
        check('errorcode == invalidwdrstatus',
            $e->errorcode === 'invalidwdrstatus',
            "got '{$e->errorcode}'");
    }

    fwrite(STDOUT, "\n");
    if ($fail === 0) {
        fwrite(STDOUT, "OK   All $pass checks passed.\n");
        $rc = 0;
    } else {
        fwrite(STDOUT, "FAIL $fail of $pass checks failed.\n");
        $rc = 1;
    }
} finally {
    // Always clean up.
    $DB->delete_records('gmk_wdr', ['id' => $inserted->id]);
}
exit($rc);