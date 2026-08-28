<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * PUBLIC carnet verifier (RF-07.3).
 *
 * No login required. The QR encodes {userid, qr_token}; the page resolves
 * the carnet by userid, compares the token with hash_equals (constant
 * time), checks the validity window, and renders one of:
 *   - "Carnet vigente" (with fullname + programme)
 *   - "Carnet vencido" (with valid_until date)
 *   - "Carnet suspendido" or "Egresado"
 *   - Generic "Carnet inválido" (no userid match, token mismatch, malformed
 *     URL, etc.) — deliberately collapsed into a single message to avoid
 *     enumeration.
 *
 * No PII (no email, no document number) is shown to the public.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wellness_carnet_manager.php');

$userid = optional_param('u', 0, PARAM_INT);
$token  = optional_param('t', '', PARAM_RAW);

// No login required: do NOT call require_login().
// F-22: $SITE->shortname (not $CFG->shortname) — the shortname lives on
// the SITE course row, not on the CFG global.
global $SITE;
$now = time();
$result = null;
if ($userid > 0 && $token !== '') {
    $result = \local_grupomakro_core\local\wellness_carnet_manager::verify_public($userid, $token);
}

$instname = format_string($SITE->shortname);
$page_title = get_string('carnet:verify:title', 'local_grupomakro_core', $instname);

// Decide the outcome and the message key once so the template stays simple.
$verdict = 'invalid';
$verdict_data = null;
if ($result !== null) {
    $verdict = $result['status']; // active | expired | suspended | egresado
    $verdict_data = [
        'fullname'    => $result['fullname'],
        'plan'        => $result['plan'],
        'valid_until' => $result['valid_until'],
    ];
}
$verdict_message = get_string("carnet:verify:result:{$verdict}", 'local_grupomakro_core');

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= s($page_title) ?></title>
<style>
:root{
  --bg:#f3f4f6;
  --card:#fff;
  --ink:#111827;
  --muted:#6b7280;
  --border:#e5e7eb;
  --ok:#16a34a;
  --warn:#f59e0b;
  --bad:#dc2626;
  --neutral:#374151;
}
*{box-sizing:border-box}
body{
  margin:0;background:var(--bg);
  font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
  color:var(--ink);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;
}
.card{
  background:var(--card);border:1px solid var(--border);border-radius:14px;
  padding:32px;max-width:480px;width:100%;box-shadow:0 6px 24px rgba(0,0,0,.05);
  text-align:center;
}
.brand{font-size:14px;color:var(--muted);letter-spacing:.04em;text-transform:uppercase;margin-bottom:18px}
.badge{
  display:inline-flex;align-items:center;gap:8px;
  font-weight:600;font-size:15px;padding:10px 18px;border-radius:999px;margin-bottom:20px;
}
.badge .dot{width:10px;height:10px;border-radius:50%;background:currentColor}
.badge--ok{background:#dcfce7;color:#166534}
.badge--warn{background:#fef3c7;color:#92400e}
.badge--bad{background:#fee2e2;color:#991b1b}
.badge--neutral{background:#e5e7eb;color:var(--neutral)}
.title{font-size:22px;font-weight:700;margin:6px 0 14px}
.details{font-size:14px;color:var(--ink);margin:8px 0}
.details .label{color:var(--muted);display:block;font-size:12px;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px}
.footer{margin-top:24px;font-size:12px;color:var(--muted)}
</style>
</head>
<body>
  <div class="card">
    <div class="brand"><?= s(get_string('carnet:verify:brand', 'local_grupomakro_core', $instname)) ?></div>
    <?php if ($verdict === 'active'): ?>
      <div class="badge badge--ok"><span class="dot"></span> <?= s($verdict_message) ?></div>
      <div class="title"><?= s($verdict_data['fullname']) ?></div>
      <div class="details">
        <span class="label"><?= s(get_string('carnet:verify:plan', 'local_grupomakro_core')) ?></span>
        <?= s($verdict_data['plan']) ?>
      </div>
      <div class="details">
        <span class="label"><?= s(get_string('carnet:verify:valid_until', 'local_grupomakro_core')) ?></span>
        <?= s(userdate($verdict_data['valid_until'], get_string('strftimedatefullshort'))) ?>
      </div>
    <?php elseif ($verdict === 'expired'): ?>
      <div class="badge badge--warn"><span class="dot"></span> <?= s($verdict_message) ?></div>
      <div class="title"><?= s($verdict_data['fullname']) ?></div>
      <div class="details">
        <span class="label"><?= s(get_string('carnet:verify:was_valid_until', 'local_grupomakro_core')) ?></span>
        <?= s(userdate($verdict_data['valid_until'], get_string('strftimedatefullshort'))) ?>
      </div>
    <?php elseif ($verdict === 'suspended'): ?>
      <div class="badge badge--bad"><span class="dot"></span> <?= s($verdict_message) ?></div>
      <div class="title"><?= s($verdict_data['fullname']) ?></div>
    <?php elseif ($verdict === 'egresado'): ?>
      <div class="badge badge--neutral"><span class="dot"></span> <?= s($verdict_message) ?></div>
      <div class="title"><?= s($verdict_data['fullname']) ?></div>
    <?php else: ?>
      <div class="badge badge--bad"><span class="dot"></span> <?= s($verdict_message) ?></div>
      <div class="title"><?= s(get_string('carnet:verify:invalid_title', 'local_grupomakro_core')) ?></div>
      <div class="details"><?= s(get_string('carnet:verify:invalid_help', 'local_grupomakro_core')) ?></div>
    <?php endif; ?>
    <div class="footer"><?= s(get_string('carnet:verify:footer', 'local_grupomakro_core', $instname)) ?></div>
  </div>
</body>
</html>
<?php
die;