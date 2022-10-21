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

// Requiere the grupomakro_core:seeallorders capability.
require_capability('local/grupomakro_core:seeallorders', context_system::instance());

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/orders.php');

$context = context_system::instance();
$PAGE->set_context($context);

$PAGE->set_title(get_string('orders', $plugin_name));
$PAGE->set_heading(get_string('orders', $plugin_name));
$PAGE->set_pagelayout('base');

echo $OUTPUT->header();

// Generate a table with the the records from the gm_orders table.
$table = new html_table();
$table->head = array(
    get_string('oid', $plugin_name),
    get_string('fullname', $plugin_name),
    get_string('itemtype', $plugin_name),
    get_string('itemname', $plugin_name),
    get_string('order_date', $plugin_name),
    get_string('order_dateupdated', $plugin_name),
    get_string('order_status', $plugin_name),
    get_string('order_total', $plugin_name),
    '',
);

// Get the records from the gm_orders table.
$orders = $DB->get_records('gmk_orders');

// Iterate over the records and add them to the table.
foreach ($orders as $order) {
    // Get the user fullname.
    $user = $DB->get_record('user', array('id' => $order->userid));
    $fullname = $user->firstname . ' ' . $user->lastname;

    // Get the user's picture.
    $picture = $OUTPUT->user_picture($user, array('size' => 50));

    // Fill the table with the order data.
    $table->data[] = array(
        $order->oid,
        $picture . ' ' . $fullname,
        $order->itemtype,
        $order->itemname,
        date('Y-m-d H:i:s', $order->timecreated),
        date('Y-m-d H:i:s', $order->timemodified),
        $order->status,
        $order->amount,
        html_writer::link(
            new moodle_url('/local/grupomakro_core/pages/orders-.php', array('id' => $order->id)),
            get_string('view', $plugin_name)
        ),
    );
}

// Print the table.
echo html_writer::table($table);


echo $OUTPUT->footer();
