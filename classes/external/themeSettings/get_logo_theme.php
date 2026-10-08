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
 * Class definition for the local_grupomakro_get_learning_plan_list external function.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\themeSettings;

use context_system;
use external_api;
use core_component;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_value;
use stdClass;
use Exception;
use theme_config;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

/**
 * External function 'local_grupomakro_get_logo_theme' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_logo_theme extends external_api {

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'themename' => new external_value(PARAM_TEXT, 'Name of theme in use in site.',VALUE_REQUIRED,VALUE_DEFAULT,null),
        ]);
    }

    /**
     * TODO describe what the function actually does.
     *
     * @param string id
     * @return mixed TODO document
     */
    public static function execute($themename) {
        global $CFG;

        // Re-validate parameter.
        [
            'themename' => $themename,
        ] = self::validate_parameters(self::execute_parameters(), [
            'themename' => $themename,
        ]);
        
        if($CFG->theme == $themename){
            $themeInUse = $themename;
        }else{
            $themeInUse = $CFG->theme;
        }
     
        $theme = theme_config::load($themeInUse);
        $themeobj = $theme->settings;
        
        $themeobj->logo = basename($themeobj->logo);
        $logoUrl = $themeobj->logodefaulturl = $CFG->wwwroot . '/theme/' . $themeInUse . '/pix/static/' . rawurlencode($themeobj->logo);

        return ['LogoUrl' => json_encode($logoUrl)];
            
    }


    /**
     * Describes the return value of the {@see self::execute()} method.
     *
     * @return external_description
     */
    public static function execute_returns(): external_description {
        return new external_single_structure(
            array(
                'LogoUrl' => new external_value(PARAM_TEXT, ''),
            )
        );
    }
}
