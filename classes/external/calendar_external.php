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
use \core_calendar\local\api as local_api;
use \core_calendar\local\event\container as event_container;
use \core_calendar\local\event\forms\create as create_event_form;
use \core_calendar\local\event\forms\update as update_event_form;
use \core_calendar\local\event\mappers\create_update_form_mapper;
use \core_calendar\external\event_exporter;
use \core_calendar\external\events_exporter;
use \core_calendar\external\events_grouped_by_course_exporter;
use \core_calendar\external\events_related_objects_cache;

defined('MOODLE_INTERNAL') || die;

require_once("$CFG->libdir/externallib.php");
require_once($CFG->dirroot . '/calendar/lib.php');




class calendar_external extends external_api {
    
    public static function execute_parameters(): external_function_parameters{
         return new external_function_parameters(
            [
                'year' => new external_value(PARAM_INT, 'Year to be viewed', VALUE_REQUIRED),
                'month' => new external_value(PARAM_INT, 'Month to be viewed', VALUE_REQUIRED),
                'courseid' => new external_value(PARAM_INT, 'Course being viewed', VALUE_DEFAULT, SITEID, NULL_ALLOWED),
                'categoryid' => new external_value(PARAM_INT, 'Category being viewed', VALUE_DEFAULT, null, NULL_ALLOWED),
                'includenavigation' => new external_value(
                    PARAM_BOOL,
                    'Whether to show course navigation',
                    VALUE_DEFAULT,
                    true,
                    NULL_ALLOWED
                ),
                'mini' => new external_value(
                    PARAM_BOOL,
                    'Whether to return the mini month view or not',
                    VALUE_DEFAULT,
                    false,
                    NULL_ALLOWED
                ),
                'day' => new external_value(PARAM_INT, 'Day to be viewed', VALUE_DEFAULT, 1),
                'view' => new external_value(PARAM_ALPHA, 'The view mode of the calendar', VALUE_DEFAULT, 'month', NULL_ALLOWED),
            ]
        );
    }
    
    
    /**
     * Get data for the monthly calendar view.
     *
     * @param int $year The year to be shown
     * @param int $month The month to be shown
     * @param int $courseid The course to be included
     * @param int $categoryid The category to be included
     * @param bool $includenavigation Whether to include navigation
     * @param bool $mini Whether to return the mini month view or not
     * @param int $day The day we want to keep as the current day
     * @param string|null $view The view mode for the calendar.
     * @return  array
     */
    public static function execute($year, $month, $courseid, $categoryid, $includenavigation, $mini, $day,
            ?string $view = null) {
        global $DB, $USER, $PAGE;

        // Parameter validation.
        $params = self::validate_parameters(self::execute_parameters(), [
            'year' => $year,
            'month' => $month,
            'courseid' => $courseid,
            'categoryid' => $categoryid,
            'includenavigation' => $includenavigation,
            'mini' => $mini,
            'day' => $day,
            'view' => $view,
        ]);

        $context = \context_user::instance($USER->id);
        self::validate_context($context);
        $PAGE->set_url('/calendar/');

        $type = \core_calendar\type_factory::get_calendar_instance();

        $time = $type->convert_to_timestamp($params['year'], $params['month'], $params['day']);
        
        $calendar = \calendar_information::create($time, $params['courseid'], $params['categoryid']);
        self::validate_context($calendar->context);

        $view = $params['view'] ?? ($params['mini'] ? 'mini' : 'month');
        
        list($data, $template) = calendar_get_view($calendar, $view, $params['includenavigation']);

        $days = [];
        $eventDays = [];

        foreach($data->weeks as $week){
            foreach($week->days as $day){
                $eventDays = array_merge($eventDays, $day->events);
            }
        }

        foreach($eventDays as $event){
            
            if($event->modulename === 'bigbluebuttonbn'){
                $bbbInstanceId = $DB->get_record('course_modules', ['id'=>$event->instance])->instance;
                $event->timeduration = $DB->get_record('bigbluebuttonbn', ['id'=>$bbbInstanceId])->closingtime - $event->timestart;
                $event->color = '#2196f3';
            }else{
                $event->color = '#00bcd4';
            }
            
            $event->initDate = date('Y-m-d H:i:s',$event->timestart);
            $event->endDate = date('Y-m-d H:i:s',$event->timestart + $event->timeduration);
            if($event->course->fullname){
                $event->coursename = $event->course->fullname;
            }else{
                $event->coursename = $event->name;
            }
            
            if($event->instance){
                $moduleSectionId = $DB->get_record('course_modules', ['id'=>$event->instance])->section;
                $class = $DB->get_record('gmk_class', ['coursesectionid'=>$moduleSectionId]);
                $gmkClass = json_decode(\local_grupomakro_core\external\list_classes::execute($class->id)['classes'])[0];
                $event->instructorName = $gmkClass->instructorName;
                $event->timeRange = $gmkClass->initHourFormatted.' - '. $gmkClass->endHourFormatted;
                $event->classDaysES = $gmkClass->selectedDaysES;
                $event->classDaysEN = $gmkClass->selectedDaysEN;
                $event->typeLabel = $gmkClass->typeLabel;
                    
            }
            
            
        }

        
        //return $data;
        return [
            'events' => json_encode(array_values($eventDays))
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