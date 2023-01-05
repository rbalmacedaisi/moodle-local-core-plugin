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
 * This is the main lib file for the plugin.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <dev@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * This function is the extend_navigation function for the plugin.
 * 
 * We will add a new item to the main navigation menu.
 * 
 * @param global_navigation $navigation
 */
function local_grupomakro_core_extend_navigation(global_navigation $navigation) {
    
    global $CFG, $PAGE;

    // If the current user is a site admin, we will add a new item to the main navigation menu.
    if (is_siteadmin()) {
        $CFG->custommenuitems = get_string('pluginname', 'local_grupomakro_core');
        
        // If the current user has grupomakro_core:seeallorders capability, we will add a new item to the main navigation menu.
        if (has_capability('local/grupomakro_core:seeallorders', $PAGE->context)) {
            $CFG->custommenuitems .= PHP_EOL . '-' . get_string('orders', 'local_grupomakro_core') . 
            '|/local/grupomakro_core/pages/orders.php';
        }
    }
}
