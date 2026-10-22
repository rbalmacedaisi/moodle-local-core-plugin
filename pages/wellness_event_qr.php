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
 * Printable attendance QR of a wellness event (opened from the Bienestar dashboard).
 *
 * Standalone page (no Moodle chrome) so it can be projected or printed as is.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wellness_event_checkin.php');

use local_grupomakro_core\local\wellness_event_checkin;

$eventid = required_param('id', PARAM_INT);

require_login();
$context = context_system::instance();
require_capability('local/grupomakro_core:manage_wellness', $context);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/wellness_event_qr.php', ['id' => $eventid]));

$event = $DB->get_record('gmk_wellness_event', ['id' => $eventid], '*', MUST_EXIST);
$url = wellness_event_checkin::url($eventid)->out(false);
[$opens, $closes] = wellness_event_checkin::window($event);

$qr = new core_qrcode($url);
$svg = $qr->getBarcodeSVGcode(12, 12, 'black');
// Drop the XML prolog/doctype so the SVG can be inlined.
$svg = preg_replace('/^.*?(<svg)/s', '$1', $svg);

$e = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$fmt = function ($ts) {
    return $ts > 0 ? userdate($ts, '%d/%m/%Y %H:%M') : '';
};
$when = $fmt((int)$event->startdate) . ((int)$event->enddate > 0 ? ' – ' . $fmt((int)$event->enddate) : '');

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>QR de asistencia - <?php echo $e($event->title); ?></title>
<style>
  body { font-family: Arial, Helvetica, sans-serif; margin: 0; color: #1a1a1a; background: #fff; }
  .page { max-width: 760px; margin: 0 auto; padding: 32px 24px; text-align: center; }
  h1 { font-size: 30px; margin: 8px 0 4px; }
  .meta { color: #555; font-size: 16px; margin-bottom: 4px; }
  .qr svg { width: 460px; max-width: 90vw; height: auto; margin: 24px auto 8px; display: block; }
  .steps { font-size: 18px; margin: 16px auto; max-width: 560px; line-height: 1.5; }
  .note { color: #666; font-size: 13px; margin-top: 12px; }
  .url { word-break: break-all; color: #888; font-size: 11px; margin-top: 18px; }
  .actions { margin-top: 20px; }
  .actions button { font-size: 15px; padding: 8px 18px; cursor: pointer; }
  @media print { .actions { display: none; } .page { padding-top: 10px; } }
</style>
</head>
<body>
<div class="page">
  <div class="meta">Bienestar Estudiantil · Registro de asistencia</div>
  <h1><?php echo $e($event->title); ?></h1>
  <div class="meta"><?php echo $e($when); ?></div>
  <?php if (!empty($event->location)): ?><div class="meta"><?php echo $e($event->location); ?></div><?php endif; ?>
  <div class="qr"><?php echo $svg; ?></div>
  <div class="steps">Escanea el código con tu celular e ingresa con tu usuario del portal de estudiantes para registrar tu asistencia.</div>
  <div class="note">
    Disponible del <?php echo $e($fmt($opens)); ?> al <?php echo $e($fmt($closes)); ?>.
    Los estudiantes con pagos pendientes no pueden registrar asistencia.
  </div>
  <div class="url"><?php echo $e($url); ?></div>
  <div class="actions"><button onclick="window.print()">Imprimir</button></div>
</div>
</body>
</html>
