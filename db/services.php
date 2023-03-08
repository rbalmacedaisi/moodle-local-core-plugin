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
 * Grupo Makro Core is a plugin used by the various components developed for the Grupo Makro platform.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = array(
    'local_grupomakro_create_user' => array(
        'classname' => 'local_grupomakro_core\external\create_user',
        'methodname' => 'execute',
        'description' => 'Ths method creates a new user in the Moodle platform.',
        'type' => 'write',
        "ajax" => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
    'local_grupomakro_generate_order' => array(
        'classname' => 'local_grupomakro_core\external\generate_order',
        'methodname' => 'execute',
        'description' => 'Generates a new order for the userid and the items provided.',
        'type' => 'write',
        "ajax" => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
    'local_grupomakro_get_user_status' => array(
        'classname' => 'local_grupomakro_core\external\get_user_status',
        'methodname' => 'execute',
        'description' => 'Returns the status of the user in the Moodle platform.',
        'type' => 'read',
        "ajax" => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
    'local_grupomakro_create_class' => array(
        'classname'     => 'local_grupomakro_core\external\create_class',
        'methodname'    => 'execute',
        'description'   => 'Create new class',
        'type'          => 'write',
        'ajax'          => true,
        'services' => array(MOODLE_OFFICIAL_MOBILE_SERVICE),
    ),
);
