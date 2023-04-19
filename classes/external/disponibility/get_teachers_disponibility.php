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
 * Class definition for the local_grupomakro_get_teachers_disponibility external function.
 *
 * @package    local_grupomakro_core
 * @copyright  2023 Solutto Consulting <devs@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\disponibility;

use context_system;
use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_value;
use stdClass;
use DateTime;
use DateInterval;
defined('MOODLE_INTERNAL') || die();

require_once $CFG->libdir . '/externallib.php';
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot.'/lib/moodlelib.php');
require_once($CFG->dirroot . '/calendar/lib.php');
/**
 * External function 'local_grupomakro_get_teachers_disponibility' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2023 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_teachers_disponibility extends external_api {

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'instructorId' => new external_value(PARAM_TEXT, 'ID of the teacher.', VALUE_OPTIONAL)
            ]
        );
    }

    /**
     * get teacher disponibility
     *
     * @param string|null $instructorId ID of the teacher (optional)
     *
     * @throws moodle_exception
     *
     * @external
     */
    public static function execute(
            $instructorId = null
        ) {
        
        // Validate the parameters passed to the function.
        $params = self::validate_parameters(self::execute_parameters(), [
            'instructorId' => $instructorId,
        ]);
        
        // Global variables.
        global $DB;
        
        
        try {
            $disponibilityRecords = $DB->get_records('gmk_teacher_disponibility');
        
            $weekdays = array(
                'disp_monday' => 'Lunes',
                'disp_tuesday' => 'Martes',
                'disp_wednesday' => 'Miércoles',
                'disp_thursday' => 'Jueves',
                'disp_friday' => 'Viernes',
                'disp_saturday' => 'Sábado',
                'disp_sunday' => 'Domingo'
            );
            $teachersDisponibility = array();
            
            foreach($disponibilityRecords as $disponibilityRecord){
                $teacherId = $disponibilityRecord->userid;
                $teachersDisponibility[$teacherId]= new stdClass();
                $teachersDisponibility[$teacherId]->instructorId = $teacherId;
                $teacherInfo = $DB->get_record('user',['id'=>$teacherId]);
                $teachersDisponibility[$teacherId]->instructorName = $teacherInfo->firstname.' '.$teacherInfo->lastname;
                $teachersDisponibility[$teacherId]->instructorPicture =self::my_get_user_picture_url($teacherId);
                $teachersDisponibility[$teacherId]->disponibilityRecords = array();
                foreach($weekdays as $dayColumnName => $day){
                     $timeSlots = self::convert_time_ranges($disponibilityRecord->{$dayColumnName});
                     if(empty($timeSlots)){
                         continue;
                     };
                     $teachersDisponibility[$teacherId]->disponibilityRecords[$day] = $timeSlots;
                }
            }
            
            // Return the result.
            return ['teacherAvailabilityRecords' => json_encode(array_values($teachersDisponibility)), 'message' => 'ok'];
        } catch (Exception $e) {
            return ['status' => -1, 'message' => $e->getMessage()];
        }
        
    }
    
   /**
     * Convert time ranges from input format to formatted time ranges.
     *
     * @param string $ranges_json The time ranges in JSON format.
     * @return array The time ranges as an array of formatted time ranges.
     */
    function convert_time_ranges($rangesJson) {
        // Parse the input as a JSON array
        $data = json_decode($rangesJson, true);
    
        $formattedRanges = array();
        foreach ($data as $range) {
            // Convert start and end times to DateTime objects
            $start = new DateTime('midnight');
            $start->add(new DateInterval('PT' . $range['st'] . 'S'));
    
            $end = new DateTime('midnight');
            $end->add(new DateInterval('PT' . $range['et'] . 'S'));
    
            // Format the start and end times as strings
            $startStr = $start->format('H:i');
            $endStr = $end->format('H:i');
    
            // Add the formatted time range to the result array
            $formattedRanges[] = "$startStr, $endStr";
        }
    
        // Return the result array
        return $formattedRanges;
    }

    /**
     * Get the URL for the user picture.
     *
     * @param int $userid The ID of the user.
     * @param int $size The size of the picture (in pixels).
     * @return string The URL of the user picture.
     */
    public static function my_get_user_picture_url($userid, $size = 100) {
        global $DB;
        $user = $DB->get_record('user', array('id' => $userid));
        if (!$user) {
            return '';
        }
        $context = \context_user::instance($user->id);
        $url = \moodle_url::make_pluginfile_url(
            $context->id, 'user', 'icon', null, null, null, $size
        );
        return $url->out();
    }

    /**
     * Describes the return value of the {@see self::execute()} method.
     *
     * @return external_description
     */
    public static function execute_returns(): external_description {
        return new external_single_structure(
            array(
                'teacherAvailabilityRecords' => new external_value(PARAM_RAW, 'The availability records of the teachers'),
                'message' => new external_value(PARAM_TEXT, 'The error message or Ok.'),
            )
        );
    }
}
