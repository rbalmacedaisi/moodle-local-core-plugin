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
 * Class definition for the local_grupomakro_list_classes external function.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external;

use context_system;
use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_value;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once $CFG->libdir . '/externallib.php';
require_once($CFG->libdir . '/filelib.php');

/**
 * External function 'local_grupomakro_list_classes' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_classes extends external_api {

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'id' => new external_value(PARAM_TEXT, 'Id of the class.',VALUE_OPTIONAL,null,NULL_ALLOWED),
                'name' => new external_value(PARAM_TEXT, 'Name of the class.',VALUE_OPTIONAL,null,NULL_ALLOWED),
                'type' => new external_value(PARAM_INT, 'Type of the class (virtual(1) or inplace(0)).',VALUE_OPTIONAL,null,NULL_ALLOWED),
                'instance' => new external_value(PARAM_INT, 'Id of the instance.',VALUE_OPTIONAL,null,NULL_ALLOWED),
                'learningPlanId' => new external_value(PARAM_INT, 'Id of the learning plan attached.',VALUE_OPTIONAL,null,NULL_ALLOWED),
                'periodId' => new external_value(PARAM_INT, 'Id of the period when the class is going to be dictated defined in the leaerning pland and ',VALUE_OPTIONAL,null,NULL_ALLOWED),
                'courseId' => new external_value(PARAM_INT, 'Course id for the class',VALUE_OPTIONAL,null,NULL_ALLOWED),
                'instructorId' => new external_value(PARAM_INT, 'Id of the class instructor',VALUE_OPTIONAL,null,NULL_ALLOWED),
                'initTime' => new external_value(PARAM_TEXT, 'Init hour for the class',VALUE_OPTIONAL,null,NULL_ALLOWED),
                'endTime' => new external_value(PARAM_TEXT, 'End hour of the class',VALUE_OPTIONAL,null,NULL_ALLOWED),
                'classDays' => new external_value(PARAM_TEXT, 'The days when tha class will be dictated, the format is l/m/m/j/v/s/d and every letter can contain 0 or 1 depending if the day is active',VALUE_OPTIONAL,null,NULL_ALLOWED)
            ]
        );
    }

    /**
     * TODO describe what the function actually does.
     *
     * @param int $userid
     * @return mixed TODO document
     */
    public static function execute(
        string $id = null,
        string $name = null,
        int $type= null,
        int $instance= null,
        int $learningPlanId= null,
        int $periodId= null,
        int $courseId= null,
        int $instructorId= null,
        string $initTime= null,
        string $endTime= null,
        string $classDays= null
        ) {
        global $DB;
        
        $filters = [];
        
        $id &&$id !== "" ? $filters['id']=$id : null;
        $name && $name !== "" ? $filters['name']=$name : null;
        $type && $type !== "" ? $filters['type']=$type : null;
        $instance && $instance !== "" ? $filters['instance']=$instance : null;
        $learningPlanId && $learningPlanId !== "" ? $filters['learningplanid']=$learningPlanId : null;
        $periodId && $periodId !== "" ? $filters['periodid']=$periodId : null;
        $courseId && $courseId !== "" ? $filters['courseid']=$courseId : null;
        $instructorId && $instructorId !== "" ? $filters['instructorid']=$instructorId : null;
        $initTime && $initTime !== "" ? $filters['inittime']=$initTime : null;
        $endTime && $endTime !== "" ? $filters['endtime']=$endTime : null;
        $classDays && $classDays !== "" ? $filters['classdays']=$classDays : null;

        $classes = $DB->get_records('gmk_class',$filters);
        foreach($classes as $class){
            
            //get the class instructor name
            $teacherId = $class->instructorid;
            $teacherCoreId = $DB->get_record('local_learning_users',['id'=>$teacherId])->userid;
            $userInfo = $DB->get_record('user',['id'=>$teacherCoreId]);
            $class->instructorName = $userInfo->firstname.' '. $userInfo->lastname;
            //
            
            //set the type Label
            $class->typeLabel = $class->type === '1'? 'Virtual':'Presencial';
            //
            
            //set the formatted hour in the format am/pm
            $initHour = intval(substr($class->inittime,0,2));
            $initMinutes = substr($class->inittime,3,2);
            $endHour = intval(substr($class->endtime,0,2));
            $endMinutes = substr($class->endtime,3,2);
            $class->initHourFormatted = $initHour>12? strval($initHour-12).':'.$initMinutes.' pm': ($initHour===12? $initHour.':'.$initMinutes.' pm' : $initHour.':'.$initMinutes.' am');
            $class->endHourFormatted = $endHour>12? strval($endHour-12).':'.$endMinutes.' pm': ($endHour===12? $endHour.':'.$endMinutes.' pm' : $endHour.':'.$endMinutes.' am');
            //
            
            //set the list of choosen days
            $daysES = ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'];
            $daysEN = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
            $daysString = $class->classdays;
            $selectedDaysES = [];
            $selectedDaysEN = [];
            foreach($daysES as $index=>$day){
                $includedDay= intval(substr($daysString,0,1))===1;
                $includedDay ? array_push($selectedDaysES,$day) :null;
                $includedDay ? array_push($selectedDaysEN,$daysEN[$index]) :null;
                $daysString = substr($daysString,2);
            }
            $class->selectedDaysES =$selectedDaysES;
            $class->selectedDaysEN =$selectedDaysEN;
            //
            
            //set the company label and code
            $companies = ['Isi Panamá','Grupo Makro Colombia','Grupo Makro México'];
            $companyCodes = ['isi-pa','gk-col','gk-mex'];
            $class->companyName =$companies[$class->instance];
            $class->companyCode =$companyCodes[$class->instance];
            //
            
            $class->startDate = '01/30/2023';
            
            
        }
        return ['classes' => json_encode(array_values($classes))];

    }

    /**
     * Describes the return value of the {@see self::execute()} method.
     *
     * @return external_description
     */
    public static function execute_returns(): external_description {
        return new external_single_structure(
            array(
                'classes' => new external_value(PARAM_RAW, 'The list of the classes')
            )
        );
    }
}
