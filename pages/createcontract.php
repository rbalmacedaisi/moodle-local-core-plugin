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
require_once($CFG->dirroot . '/local/grupomakro_core/createcontract_form.php');

$plugin_name = 'local_grupomakro_core';

require_login();

// Requiere the grupomakro_core:seeallorders capability.
require_capability('local/grupomakro_core:seeallorders', context_system::instance());

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/contractmanagement.php');

$context = context_system::instance();
$PAGE->set_context($context);

$PAGE->set_title(get_string('create_contract', $plugin_name));
$PAGE->set_heading(get_string('create_contract', $plugin_name));
$PAGE->set_pagelayout('base');

echo $OUTPUT->header();
$formimagepicker = new createcontract_form();
$maintemplatedata = [
    'formimagpicker' => $formimagepicker->render(),
];

echo $OUTPUT->render_from_template('local_grupomakro_core/create_contract', $maintemplatedata);
echo $OUTPUT->footer();