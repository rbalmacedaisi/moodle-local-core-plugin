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
 * External calendar API
 *
 * @package    core_calendar
 * @category   external
 * @copyright  2012 Ankit Agarwal
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since Moodle 2.5
 */
 namespace local_grupomakro_core\external;
 
use stdClass;
use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_value;


defined('MOODLE_INTERNAL') || die;

require_once("$CFG->libdir/externallib.php");
require_once($CFG->dirroot . '/calendar/lib.php');

class calendar_external extends external_api {
    
    public static function execute_parameters(): external_function_parameters{
         return new external_function_parameters(
            [
                'userId' => new external_value(PARAM_INT, 'Id of the user',  VALUE_DEFAULT, null, NULL_ALLOWED),
            ]
        );
    }
    
    /**
     * Get data for the monthly calendar view.
     *
     * @param int $year The year to be shown
     * @return  array
     */
    public static function execute($userId) {
        global $DB, $USER, $PAGE;

        // Parameter validation.
        $params = self::validate_parameters(self::execute_parameters(), [
            'userId' => $userId,
        ]);
        
        $fetchedClasses = array();
        $fetchedCourses = array();
        $eventDaysFiltered = [];
        
        $events = calendar_get_events(1680325200,1690779599,true,true,true,false,false);
        
        $moduleIds = ["bigbluebuttonbn"=>$DB->get_record('modules',['name'=>'bigbluebuttonbn'])->id,"attendance"=>$DB->get_record('modules',['name'=>'attendance'])->id];
        foreach($events as $event){
            
            if(!array_key_exists($event->modulename, $moduleIds) || !$event->instance){
                continue;
            }
            
            $moduleSectionId = $DB->get_record('course_modules', ['instance'=>$event->instance, 'module'=>$moduleIds[$event->modulename]])->section;
            
            //Save the fetched classes to minimize db queries
            if(array_key_exists($moduleSectionId,$fetchedClasses)){
                $gmkClass = $fetchedClasses[$moduleSectionId];
            }else {
                $class = $DB->get_record('gmk_class', ['coursesectionid'=>$moduleSectionId]);
                if(!$class){continue;}
                $gmkClass = json_decode(\local_grupomakro_core\external\gmkclass\list_classes::execute($class->id)['classes'])[0];
                $fetchedClasses[$moduleSectionId] = $gmkClass;

            }
            
            //Set the class information for the event
            
            $event->instructorName = $gmkClass->instructorName;
            $event->timeRange = $gmkClass->initHourFormatted.' - '. $gmkClass->endHourFormatted;
            $event->classDaysES = $gmkClass->selectedDaysES;
            $event->classDaysEN = $gmkClass->selectedDaysEN;
            $event->typeLabel = $gmkClass->typeLabel;
            $event->className = $gmkClass->name;
            
            
            // The big blue button event doesn't come with the timeduration, so we calculate it and added to the event object
            // Asign the event color for both cases
            if($event->modulename === 'bigbluebuttonbn'){
                $event->timeduration = $DB->get_record('bigbluebuttonbn', ['id'=>$event->instance])->closingtime - $event->timestart;
                $event->color = '#2196f3';
            }else{
                $event->color = '#00bcd4';
            }
            //Set the initial date and the end date of the event
            $event->initDate = date('Y-m-d H:i:s',$event->timestart);
            $event->endDate = date('Y-m-d H:i:s',$event->timestart + $event->timeduration);
            
            //Get the coursename, save the fetched coursenames for minimize db queries
            if(array_key_exists($event->courseid,$fetchedCourses)){
                $event->coursename = $fetchedCourses[$event->courseid];
            }else {
                $event->coursename = $DB->get_record('course', ['id'=>$event->courseid])->fullname;
                $fetchedCourses[$event->courseid] = $event->coursename;
            }

            //push the filtered event to the arrays of events
            array_push($eventDaysFiltered,$event);
        }
        // print_object($eventDaysFiltered);
        // die;
        return [
            'events' => json_encode(array_values($eventDaysFiltered))
        ];
    }

    /**
     * Describes the return value of the {@see self::execute()} method.
     *
     * @return external_description
     */
    public static function execute_returns(): external_description {
        //return \core_calendar\external\month_exporter::get_read_structure();
        return new external_single_structure(
            array(
                'events' => new external_value(PARAM_RAW, 'Events for the month')
            )
        );
    }
}