<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Plugin Page - Grupo Makro
 *
 * @package     local_grupomakro_core
 * @copyright   2022 Solutto <dev@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$plugin_name = 'local_grupomakro_core';

require_login();

$context = context_system::instance();
$PAGE->set_context($context);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/editcontractinstitutionals.php'));
$PAGE->set_title(get_string('editcontract', $plugin_name));
$PAGE->set_heading(get_string('editcontract', $plugin_name));
$PAGE->set_pagelayout('base');

if (is_siteadmin()) {
    $PAGE->navbar->add(get_string('institutionmanagement', $plugin_name), new moodle_url('/local/grupomakro_core/pages/institutionmanagement.php'));
    $PAGE->navbar->add(get_string('institutional_contracts', $plugin_name), new moodle_url('/local/grupomakro_core/pages/institutionalcontracts.php'));
}
$PAGE->navbar->add(
    get_string('editcontract', $plugin_name),
    new moodle_url('/local/grupomakro_core/pages/editcontractinstitutionals.php')
);

$contract_id = required_param('cid', PARAM_TEXT);



echo $OUTPUT->header();

$templatedata = [
    'cancelurl' => $CFG->wwwroot.'/local/grupomakro_core/pages/institutionalcontracts.php',
    'contract_id' => $contract_id,
];


echo $OUTPUT->render_from_template('local_grupomakro_core/edit_contract_institutionals', $templatedata);
echo $OUTPUT->footer();