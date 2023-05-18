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
 * This page is responsible of managing everything related to the orders.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <dev@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

$plugin_name = 'local_grupomakro_core';

require_login();

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/createcontractinstitutional.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_title(get_string('create_contract', $plugin_name));
$PAGE->set_heading(get_string('create_contract', $plugin_name));
$PAGE->set_pagelayout('base');
$PAGE->add_body_class('limitedwidth');
$institutionId = required_param('id', PARAM_TEXT);

if (is_siteadmin()) {
    $PAGE->navbar->add(get_string('institutionmanagement', $plugin_name), new moodle_url('/local/grupomakro_core/pages/institutionmanagement.php'));
    $PAGE->navbar->add(get_string('institutional_contracts', $plugin_name), new moodle_url('/local/grupomakro_core/pages/institutionalcontracts.php'));
}
$PAGE->navbar->add(
    get_string('create_contract', $plugin_name),
    new moodle_url('/local/grupomakro_core/pages/createcontractinstitutional.php')
);


echo $OUTPUT->header();

$templatedata = [
    'cancelurl' => $CFG->wwwroot.'/local/grupomakro_core/pages/institutionalcontracts.php',
];

echo $OUTPUT->render_from_template('local_grupomakro_core/create_contract_institutional', $templatedata);
$PAGE->requires->js_call_amd('local_grupomakro_core/create_institution_contract', 'init', [$institutionId]);
echo $OUTPUT->footer();