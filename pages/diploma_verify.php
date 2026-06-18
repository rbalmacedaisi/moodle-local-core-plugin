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
 * Public verification page for a generated diploma.
 *
 * @package    local_grupomakro_core
 * @copyright  2024 Solutto Consulting
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use local_grupomakro_core\local\diplomas\manager;

$token = trim((string)optional_param('t', '', PARAM_ALPHANUMEXT));
$urlparams = [];
if ($token !== '') {
    $urlparams['t'] = $token;
}

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/diploma_verify.php', $urlparams));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('base');
$PAGE->set_title(get_string('diploma_verify_page_title', 'local_grupomakro_core'));
$PAGE->set_heading(get_string('diploma_verify_page_heading', 'local_grupomakro_core'));

$verification = null;
$found = false;
$revoked = false;
if ($token !== '') {
    $verification = manager::get_verification_data($token);
    if ($verification !== null) {
        $found = true;
        $revoked = ((string)$verification['status'] === manager::STATUS_REVOKED);
    }
}

$sitename = format_string($SITE->fullname);
$wwwroot = $CFG->wwwroot;

echo $OUTPUT->header();
?>
<style>
    body { background: linear-gradient(135deg, #f5f7fa 0%, #e8ecf3 100%); min-height: 100vh; }
    .dplv-wrap { max-width: 720px; margin: 40px auto; padding: 0 16px; font-family: 'Roboto', sans-serif; color: #1f2937; }
    .dplv-card { background: #fff; border-radius: 18px; padding: 40px 32px; box-shadow: 0 12px 36px rgba(15, 23, 42, .08); position: relative; overflow: hidden; }
    .dplv-card::before { content: ''; position: absolute; inset: 0 0 auto 0; height: 6px; background: linear-gradient(90deg, #10b981 0%, #34d399 50%, #10b981 100%); }
    .dplv-check { width: 92px; height: 92px; border-radius: 50%; background: radial-gradient(circle at 30% 30%, #6ee7b7 0%, #10b981 60%, #047857 100%); display: flex; align-items: center; justify-content: center; margin: 0 auto 18px; box-shadow: 0 10px 24px rgba(16, 185, 129, .35); animation: dplv-pop .55s cubic-bezier(.34,1.56,.64,1) both; }
    .dplv-check svg { width: 50px; height: 50px; stroke: #fff; stroke-width: 6; stroke-linecap: round; stroke-linejoin: round; fill: none; stroke-dasharray: 60; stroke-dashoffset: 60; animation: dplv-draw .6s ease-out .35s forwards; }
    @keyframes dplv-pop { 0% { transform: scale(.4); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
    @keyframes dplv-draw { to { stroke-dashoffset: 0; } }
    .dplv-check.revoked { background: radial-gradient(circle at 30% 30%, #fca5a5 0%, #ef4444 60%, #b91c1c 100%); box-shadow: 0 10px 24px rgba(239, 68, 68, .35); }
    .dplv-check.revoked svg { stroke-dashoffset: 0; animation: none; transform: rotate(45deg); stroke: #fff; stroke-width: 7; }
    .dplv-title { text-align: center; font-size: 28px; font-weight: 700; margin: 0 0 4px; }
    .dplv-subtitle { text-align: center; color: #6b7280; font-size: 15px; margin: 0 0 24px; }
    .dplv-legend { background: #f9fafb; border-left: 4px solid #10b981; padding: 12px 16px; border-radius: 8px; color: #374151; font-size: 14px; margin: 24px 0; }
    .dplv-legend.revoked { border-left-color: #ef4444; }
    .dplv-cert { text-align: center; margin: 16px 0 24px; padding: 24px 16px; background: linear-gradient(135deg, #f0fdf4 0%, #ecfeff 100%); border-radius: 14px; border: 1px solid #d1fae5; }
    .dplv-cert .prefix { font-size: 14px; text-transform: uppercase; letter-spacing: 2px; color: #047857; font-weight: 600; }
    .dplv-cert .student { font-size: 32px; font-weight: 700; color: #111827; margin: 8px 0; font-family: 'Playfair Display', 'Times New Roman', serif; }
    .dplv-cert .career { font-size: 18px; color: #1f2937; margin-top: 6px; }
    .dplv-cert .career strong { color: #047857; }
    .dplv-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin-top: 18px; }
    .dplv-grid .item { background: #f9fafb; border-radius: 10px; padding: 12px 14px; }
    .dplv-grid .item .k { font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 1px; font-weight: 600; }
    .dplv-grid .item .v { font-size: 15px; color: #111827; font-weight: 500; margin-top: 4px; word-break: break-word; }
    .dplv-share { display: flex; gap: 8px; align-items: center; margin-top: 24px; padding: 14px; background: #f1f5f9; border-radius: 10px; }
    .dplv-share code { flex: 1; padding: 8px 10px; background: #fff; border-radius: 6px; font-size: 13px; color: #334155; border: 1px solid #e2e8f0; overflow-x: auto; }
    .dplv-share button { padding: 8px 14px; background: #10b981; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; }
    .dplv-share button:hover { background: #059669; }
    .dplv-foot { text-align: center; color: #6b7280; font-size: 13px; margin-top: 24px; }
    .dplv-foot a { color: #047857; text-decoration: none; }
    .dplv-foot a:hover { text-decoration: underline; }
    .dplv-bad { text-align: center; padding: 60px 20px; }
    .dplv-bad svg { width: 80px; height: 80px; color: #ef4444; }
</style>
<div class="dplv-wrap">
<?php if (!$found): ?>
    <div class="dplv-card">
        <div class="dplv-bad">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/></svg>
            <h2 style="margin-top: 16px; color: #b91c1c;"><?php echo get_string('diploma_verify_invalid_token', 'local_grupomakro_core'); ?></h2>
            <p style="color: #6b7280; margin-top: 12px;"><?php echo get_string('diploma_verify_legend', 'local_grupomakro_core'); ?></p>
        </div>
    </div>
<?php else:
    $row = $verification;
    $statuskey = $revoked ? 'diploma_verify_status_revoked' : 'diploma_verify_status_valid';
?>
    <div class="dplv-card">
        <div class="dplv-check<?php echo $revoked ? ' revoked' : ''; ?>">
            <?php if ($revoked): ?>
                <svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg>
            <?php else: ?>
                <svg viewBox="0 0 24 24"><path d="M5 12l5 5L20 7"/></svg>
            <?php endif; ?>
        </div>
        <h1 class="dplv-title">
            <?php echo $revoked
                ? get_string('diploma_verify_revoked', 'local_grupomakro_core')
                : get_string('diploma_verify_page_heading', 'local_grupomakro_core'); ?>
        </h1>
        <p class="dplv-subtitle"><?php echo $sitename; ?></p>

        <div class="dplv-cert">
            <div class="prefix"><?php echo get_string('diploma_certified_to', 'local_grupomakro_core'); ?></div>
            <div class="student"><?php echo s($row['studentname']); ?></div>
            <div class="career">
                <?php echo get_string('diploma_certified_career_prefix', 'local_grupomakro_core'); ?>
                <strong><?php echo s($row['careername']); ?></strong>
                <?php echo get_string('diploma_certified_career_suffix', 'local_grupomakro_core'); ?>
            </div>
        </div>

        <div class="dplv-legend<?php echo $revoked ? ' revoked' : ''; ?>">
            <?php echo get_string('diploma_verify_legend', 'local_grupomakro_core'); ?>
        </div>

        <div class="dplv-grid">
            <div class="item">
                <div class="k"><?php echo get_string('diploma_verify_diploma_number', 'local_grupomakro_core'); ?></div>
                <div class="v"><?php echo s($row['diplomanumber']); ?></div>
            </div>
            <div class="item">
                <div class="k"><?php echo get_string('diploma_verify_version', 'local_grupomakro_core'); ?></div>
                <div class="v">v<?php echo (int)$row['version']; ?></div>
            </div>
            <div class="item">
                <div class="k"><?php echo get_string('diploma_verify_issued_at', 'local_grupomakro_core'); ?></div>
                <div class="v"><?php echo userdate((int)$row['issued_at']); ?></div>
            </div>
            <div class="item">
                <div class="k"><?php echo get_string('diploma_verify_status', 'local_grupomakro_core'); ?></div>
                <div class="v"><?php echo get_string($statuskey, 'local_grupomakro_core'); ?></div>
            </div>
            <?php if (!empty($row['studentdocument'])): ?>
            <div class="item">
                <div class="k"><?php echo get_string('diploma_var_documentnumber', 'local_grupomakro_core'); ?></div>
                <div class="v"><?php echo s($row['studentdocument']); ?></div>
            </div>
            <?php endif; ?>
            <div class="item">
                <div class="k"><?php echo get_string('diploma_verify_templatename', 'local_grupomakro_core') ?: get_string('diploma_template_used', 'local_grupomakro_core'); ?></div>
                <div class="v"><?php echo s($row['templatename']); ?></div>
            </div>
        </div>

        <div class="dplv-share">
            <code id="dplv-url"><?php echo s($row['verification_url']); ?></code>
            <button onclick="navigator.clipboard.writeText(document.getElementById('dplv-url').textContent.trim()); this.textContent='✓'; setTimeout(()=>{this.textContent='<?php echo get_string('copy', 'moodle'); ?>';}, 1500);"><?php echo get_string('copy', 'moodle'); ?></button>
        </div>

        <div class="dplv-foot">
            <a href="<?php echo $wwwroot; ?>">← <?php echo get_string('diploma_verify_back_to_site', 'local_grupomakro_core'); ?></a>
        </div>
    </div>
<?php endif; ?>
</div>
<?php
echo $OUTPUT->footer();
