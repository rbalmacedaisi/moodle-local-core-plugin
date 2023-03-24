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
 * Local Lib - Common function for users
 *
 * @package     local_sc_learningplans
 * @copyright   2022 Solutto < G>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Get the active learning plans for the class creation
 *
 * @return array
 */
function grupomakro_core_get_active_learningplans() {
    global $DB;
        // Check if LP Exist.
    $learningplans = $DB->get_records('local_learning_plans');
    
    $careername_options = [];
    
    $availablecareers = [];
    foreach($learningplans as $learningplan){

        $handler = local_sc_learningplans\customfield\learningplan_handler::create();
        $learningplan_customfields = $handler->get_custom_fields_for_learning_plan($learningplan->id);
        $careerinfo = $learningplan_customfields['Informacion_carrera']['fields'];
        
        $career_name = null;
        $career_formattedinfo = [];
        foreach($careerinfo as $careerfield){
            if($careerfield['shortname'] === 'careername'){
                $selected_careername_id = $careerfield['value'];
                if(empty($careername_options)){
                    $options = $careerfield['options'];
                    foreach($options as $option){
                        $careername_options[$option['id']]=$option['value'];
                    }
                }
                $career_name = $careername_options[$selected_careername_id];
                continue;
            }
            $career_formattedinfo[$careerfield['shortname']] = $careerfield['value'];
        }
        $career_formattedinfo['lpid'] = $learningplan->id;
        $career_formattedinfo['timecreated'] = $learningplan->timecreated;
        if(!array_key_exists($career_name,$availablecareers)){
            $availablecareers[$career_name]= $career_formattedinfo;
            continue;
        }
        $already_added_lp_timecreated = $availablecareers[$career_name]['timecreated'];
        if($already_added_lp_timecreated > $career_formattedinfo['timecreated']){
            continue;
        }
        $availablecareers[$career_name]= $career_formattedinfo;
        
    }
    return [
        'availablecareers' => json_encode($availablecareers)
    ];
}