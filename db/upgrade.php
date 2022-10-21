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
 * Plugin upgrade steps are defined here.
 *
 * @package     local_grupomakro_core
 * @category    upgrade
 * @copyright   2022 Gilson Ricnón <gilson.rincon@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Require the upgradelib.php file.
require_once($CFG->dirroot . '/local/grupomakro_core/db/upgradelib.php');

/**
 * Execute local_soluttolms_core upgrade from the given old version.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_grupomakro_core_upgrade($oldversion) {
    
    // Create the new roles.
    create_roles();

    // Creating the new custom user fields.
    create_custom_user_fields();

    return true;
}
