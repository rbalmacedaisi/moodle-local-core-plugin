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
 * Class definition for the local_grupomakro_check_reschedule_conflicts external function.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\activity;

use context_system;
use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_value;
use mod_bigbluebuttonbn\recording;
use stdClass;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once $CFG->dirroot. '/local/grupomakro_core/locallib.php';

/**
 * External function 'local_grupomakro_check_reschedule_conflicts' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_bbb_module_url extends external_api {

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [   
                'moduleId'=> new external_value(PARAM_TEXT, 'Id of the bbb module.',VALUE_REQUIRED),
                'courseId'=> new external_value(PARAM_TEXT, 'Id of the course.',VALUE_REQUIRED),
            ]
        );
    }

    /**
     * TODO describe what the function actually does.
     *
     * @param int $userid
     * @return mixed TODO document
     */
    public static function execute($moduleId,$courseId){
        
        $params = self::validate_parameters(self::execute_parameters(), [
            'moduleId'=>$moduleId,
            'courseId'=>$courseId,
        ]);

        try{
            
            global $DB;
            
            $courseModuleInfo = get_fast_modinfo($courseId);
            $moduleInfo = $courseModuleInfo->get_cm($moduleId)->get_course_module_record();
            $BBBMeetingInfo = \mod_bigbluebuttonbn\external\meeting_info::execute($moduleInfo->instance,0);
            // print_object('aqui estoy');
            // die;
            $BBBRecordings = \mod_bigbluebuttonbn\external\get_recordings::execute($moduleInfo->instance);
            
            $recordingId = $DB->get_field('bigbluebuttonbn_recordings','recordingid',['bigbluebuttonbnid'=>$moduleInfo->instance]);

            $meetingInfo = new stdClass();
            $meetingInfo->opened = $BBBMeetingInfo['statusopen'];
            $meetingInfo->closed = $recordingId ? true : $BBBMeetingInfo['statusclosed'];
            $meetingInfo->running = $BBBMeetingInfo['statusrunning'];
            $meetingInfo->message = $BBBMeetingInfo['statusmessage'];
            $meetingInfo->joinUrl = $BBBMeetingInfo['canjoin']? \mod_bigbluebuttonbn\external\get_join_url::execute($params['moduleId'])['join_url']:null;
            $meetingInfo->recordingUrl = $recordingId?  "https://bbb.isi.edu.pa/playback/presentation/2.3/".$recordingId:null;
            return ['BBBInfo'=>json_encode($meetingInfo)];
            
        }
        catch (Exception $e) {
            return ['status' => -1, 'message' => $e->getMessage()];
        }
    }

    /**
     * Describes the return value of the {@see self::execute()} method.
     *
     * @return external_description
     */
    public static function execute_returns(): external_description {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_INT, 'The url of the BBB session or recording',VALUE_DEFAULT,1),
                'BBBInfo' => new external_value(PARAM_RAW, 'The url of the BBB session or recording',VALUE_DEFAULT,null),
                'message' => new external_value(PARAM_TEXT, 'The error message or Ok.',VALUE_DEFAULT,'ok'),
            )
        );
    }
}
