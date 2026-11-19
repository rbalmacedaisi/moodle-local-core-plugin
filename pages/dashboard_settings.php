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
 * Dashboard administrativo: configuracion (20261001129). Interruptores (inicio,
 * alertas, resumen), horario administrativo, ANS y supervisores por tipo y
 * fuentes activas. Exige dashboard_manage (gerencia y administradores).
 *
 * Formulario HTML simple con sesskey; los valores se validan en
 * local\dashboard\config (umbrales incoherentes se rechazan).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use local_grupomakro_core\local\dashboard\config;
use local_grupomakro_core\local\dashboard\registry;
use local_grupomakro_core\local\support\business_hours;

require_login();
$context = context_system::instance();
require_capability('local/grupomakro_core:dashboard_manage', $context);

$url = new moodle_url('/local/grupomakro_core/pages/dashboard_settings.php');
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_title(get_string('admindash_settings_title', 'local_grupomakro_core'));
$PAGE->set_heading(get_string('admindash_settings_title', 'local_grupomakro_core'));
$PAGE->set_pagelayout('admin');

$errors = [];
if (data_submitted() && confirm_sesskey()) {
    try {
        $values = [];
        foreach (['dash_home_enabled', 'dash_alerts_enabled', 'dash_digest_enabled'] as $name) {
            $values[$name] = optional_param($name, 0, PARAM_BOOL) ? 1 : 0;
        }
        $hour = optional_param('dash_digest_hour', 7, PARAM_INT);
        if ($hour < 0 || $hour > 23) {
            throw new invalid_parameter_exception('La hora del resumen va de 0 a 23.');
        }
        $values['dash_digest_hour'] = $hour;
        $hours = trim(optional_param('dash_hours', '', PARAM_RAW));
        if ($hours === '' || !business_hours::parse($hours)) {
            throw new invalid_parameter_exception('El horario no es válido (formato {"1":[["08:00","17:00"]], ...}).');
        }
        $values['dash_hours'] = business_hours::normalise($hours);
        $on = optional_param_array('source_on', [], PARAM_BOOL);
        $off = [];
        foreach (registry::sources(true) as $key => $s) {
            if (empty($on[$key])) {
                $off[] = $key;
            }
        }
        $values['dash_sources_off'] = implode(',', $off);
        $sla = [];
        $warn = optional_param_array('warn', [], PARAM_INT);
        $due = optional_param_array('due', [], PARAM_INT);
        $crit = optional_param_array('crit', [], PARAM_INT);
        $sup = optional_param_array('sup', [], PARAM_RAW_TRIMMED);
        $slaon = optional_param_array('sla_on', [], PARAM_BOOL);
        foreach (array_keys(config::SLA_DEFAULTS) as $type) {
            $k = str_replace('.', '__', $type);
            $sla[$type] = ['warn' => $warn[$k] ?? null, 'due' => $due[$k] ?? null, 'crit' => $crit[$k] ?? null,
                'sup' => (string)($sup[$k] ?? ''), 'on' => !empty($slaon[$k])];
        }
        $values['dash_sla'] = $sla;
        // Validate everything first: nothing is saved if one value is wrong.
        foreach ($values as $name => $value) {
            config::validate($name, $value);
        }
        // Switching the alerts on again re-arms the silent first pass: what piled up while they
        // were off is recorded without notifying anyone.
        if ($values['dash_alerts_enabled'] && !config::get_int('dash_alerts_enabled')) {
            config::set('dash_alerts_primed', 0);
        }
        foreach ($values as $name => $value) {
            config::set($name, $value);
        }
        redirect($url, 'Configuración guardada.', null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (moodle_exception $e) {
        // invalid_parameter_exception keeps the detail in debuginfo.
        $errors[] = !empty($e->debuginfo) ? $e->debuginfo : $e->getMessage();
    }
}

config::reset_cache();
$sla = json_decode((string)config::get('dash_sla'), true);
$checked = function(string $name): string {
    return config::get_int($name) ? ' checked' : '';
};

echo $OUTPUT->header();
foreach ($errors as $err) {
    echo $OUTPUT->notification(s($err), 'error');
}
?>
<style>
  .gmk-ds { max-width: 980px; overflow-x: hidden; }
  .gmk-ds fieldset { border: 1px solid rgba(0,0,0,.12); border-radius: 8px; padding: 12px; margin-bottom: 14px; min-width: 0; }
  .gmk-ds legend { font-size: 1rem; font-weight: 600; width: auto; padding: 0 6px; margin: 0; }
  .gmk-ds label { display: flex; gap: 8px; align-items: center; min-height: 44px; margin: 0; overflow-wrap: anywhere; }
  .gmk-ds .row-sla { border-top: 1px solid rgba(0,0,0,.08); padding: 10px 0; display: flex; flex-wrap: wrap; gap: 8px 12px; align-items: flex-end; }
  .gmk-ds .row-sla .t { flex: 1 1 100%; font-weight: 500; }
  .gmk-ds .row-sla .f { display: flex; flex-direction: column; flex: 1 1 80px; min-width: 0; }
  .gmk-ds .row-sla .f.sup { flex: 3 1 220px; }
  .gmk-ds input[type=number], .gmk-ds input[type=text], .gmk-ds textarea { font-size: 16px; width: 100%; max-width: 100%; min-height: 40px; }
  .gmk-ds small { color: rgba(0,0,0,.6); }
  .gmk-ds .btn { min-height: 44px; }
</style>
<form method="post" class="gmk-ds">
  <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
  <p><a href="<?php echo (new moodle_url('/local/grupomakro_core/pages/admin_dashboard.php'))->out(); ?>">← Volver a Mis pendientes</a></p>

  <fieldset>
    <legend>Interruptores</legend>
    <label><input type="checkbox" name="dash_home_enabled" value="1"<?php echo $checked('dash_home_enabled'); ?>>
      Mis pendientes es la página de inicio de los administrativos (administradores del sitio, estudiantes y docentes no cambian)</label>
    <label><input type="checkbox" name="dash_alerts_enabled" value="1"<?php echo $checked('dash_alerts_enabled'); ?>>
      Alertas de ANS activas (la primera pasada registra lo que ya está vencido sin avisar)</label>
    <label><input type="checkbox" name="dash_digest_enabled" value="1"<?php echo $checked('dash_digest_enabled'); ?>>
      Resumen diario a cada administrativo con pendientes</label>
    <label>Hora del resumen (0-23, solo días hábiles)
      <input type="number" name="dash_digest_hour" min="0" max="23" style="max-width:90px"
             value="<?php echo config::get_int('dash_digest_hour'); ?>"></label>
    <small>Primera pasada en silencio: <?php echo config::get_int('dash_alerts_primed') ? 'ya hecha' : 'pendiente'; ?>.
      Tickets y cartas tienen su propio ANS y sus propias alertas; aquí solo se muestran.</small>
  </fieldset>

  <fieldset>
    <legend>Horario administrativo</legend>
    <small><?php echo s(business_hours::describe((object)['hours' => (string)config::get('dash_hours')])); ?>.
      Los feriados salen de gmk_holidays.</small>
    <textarea name="dash_hours" rows="3"><?php echo s((string)config::get('dash_hours')); ?></textarea>
    <small>Formato: {"1":[["08:00","17:00"]], ..., "6":[["08:00","12:00"]]} (1 = lunes, 7 = domingo).</small>
  </fieldset>

  <fieldset>
    <legend>Fuentes</legend>
    <?php foreach (registry::sources(true) as $key => $s) { ?>
      <label><input type="checkbox" name="source_on[<?php echo s($key); ?>]" value="1"<?php
          echo config::source_enabled($key) ? ' checked' : ''; ?>> <?php echo s($s->label()); ?></label>
    <?php } ?>
  </fieldset>

  <fieldset>
    <legend>ANS por tipo de pendiente</legend>
    <small>Horas o días hábiles desde que el pendiente empezó a esperar; "antes" = días hábiles antes de la fecha límite.
      Crítico avisa a gerencia (-1 = sin crítico). Supervisores: roles (gmk_director_academico),
      wellness:bienestar_jefe o user:123, separados por coma.</small>
    <?php foreach ($sla as $type => $row) {
        $k = str_replace('.', '__', $type);
        $unit = ['h' => 'horas hábiles', 'd' => 'días hábiles', 'before' => 'días hábiles antes'][$row['kind']] ?? ''; ?>
      <div class="row-sla">
        <div class="t"><label style="min-height:auto"><input type="checkbox" name="sla_on[<?php echo $k; ?>]" value="1"<?php
            echo !empty($row['on']) ? ' checked' : ''; ?>> <?php echo s($row['label']); ?> <small>(<?php echo $unit; ?>)</small></label></div>
        <div class="f"><small>Por vencer</small><input type="number" name="warn[<?php echo $k; ?>]" value="<?php echo (int)$row['warn']; ?>"></div>
        <div class="f"><small>Vence</small><input type="number" name="due[<?php echo $k; ?>]" value="<?php echo (int)$row['due']; ?>"></div>
        <div class="f"><small>Crítico</small><input type="number" name="crit[<?php echo $k; ?>]" value="<?php echo (int)$row['crit']; ?>"></div>
        <div class="f sup"><small>Supervisores</small><input type="text" name="sup[<?php echo $k; ?>]" value="<?php echo s($row['sup']); ?>"></div>
      </div>
    <?php } ?>
  </fieldset>

  <button type="submit" class="btn btn-primary">Guardar</button>
</form>
<?php
echo $OUTPUT->footer();
