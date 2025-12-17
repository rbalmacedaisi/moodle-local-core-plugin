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
 * Events
 *
 * @package     local_grupomakro_core
 * @copyright   2022 Solutto <d.arango@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = array(

    array(
        'eventname' => 'core\event\course_completed',
        'callback' => 'local_grupomakro_core_observer::course_completed',
    ),
    array(
        'eventname' => 'core\event\group_member_added',
        'callback' => 'local_grupomakro_core_observer::group_member_added',
    ),
    array(
        'eventname' => 'local_sc_learningplans\event\learningplanuser_added',
        'callback' => 'local_grupomakro_core_observer::learningplanuser_added',
    ),
    array(
        'eventname' => 'local_sc_learningplans\event\learningplanuser_removed',
        'callback' => 'local_grupomakro_core_observer::learningplanuser_removed',
    ),
    array(
        'eventname' => 'local_sc_learningplans\event\learningplancourse_added',
        'callback' => 'local_grupomakro_core_observer::learningplancourse_added',
    ),
    array(
        'eventname' => 'core\event\course_module_completion_updated',
        'callback' => 'local_grupomakro_core_observer::course_module_completion_updated',
    ),
    array(
        'eventname' => 'core\event\course_created',
        'callback' => 'local_grupomakro_core_observer::course_created',
    ),
    array(
        'eventname' => 'core\event\course_updated',
        'callback' => 'local_grupomakro_core_observer::course_updated',
    ),
    array(
        'eventname' => 'core\event\course_deleted',
        'callback' => 'local_grupomakro_core_observer::course_deleted',
    ),
    array(
        'eventname' => 'mod_attendance\event\attendance_taken_by_student',
        'callback' => 'local_grupomakro_core_observer::attendance_taken_by_student',
    ),
    array(
        'eventname' => 'mod_attendance\event\attendance_taken',
        'callback' => 'local_grupomakro_core_observer::attendance_taken',
    ),
    array(
        'eventname' => 'core\event\course_module_created',
        'callback' => 'local_grupomakro_core_observer::course_module_created',
    ),
    array(
        'eventname' => 'core\event\course_module_deleted',
        'callback' => 'local_grupomakro_core_observer::course_module_deleted',
    )
);
