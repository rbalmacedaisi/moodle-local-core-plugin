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
 * Class definition for the local_grupomakro_get_data_by_courses external function.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\student;

use external_api;
use external_description;
use external_function_parameters;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

/**
 * External function 'local_grupomakro_get_data_by_courses' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_data_by_courses extends external_api
{
    /**
     * Normalizes module payload for student UI.
     * - Keep BBB visible when configured as visible on course page.
     * - Keep BBB untagged for virtual session grouping.
     * - Ensure resource intro is available as description in activity header.
     *
     * @param object $coursedata
     * @param int $courseid
     * @return void
     */
    private static function normalize_activities_payload(object &$coursedata, int $courseid): void {
        global $DB;

        if (empty($coursedata->activities) || !is_array($coursedata->activities)) {
            return;
        }

        $modinfo = get_fast_modinfo($courseid);

        foreach ($coursedata->activities as &$section) {
            if (empty($section->modules) || !is_array($section->modules)) {
                continue;
            }
            foreach ($section->modules as &$module) {
                if (empty($module->modname)) {
                    continue;
                }

                if ($module->modname === 'bigbluebuttonbn') {
                    if (
                        isset($module->uservisible, $module->visibleoncoursepage) &&
                        !$module->uservisible &&
                        (int)$module->visibleoncoursepage === 1
                    ) {
                        $module->uservisible = true;
                    }
                    if (isset($module->tags)) {
                        unset($module->tags);
                    }
                    continue;
                }

                // Module intro is needed in the right panel even when "showdescription" is disabled.
                // Assign and forum have dedicated panels that fetch descriptions independently.
                $types_with_own_description_panel = ['assign', 'forum'];
                if (!in_array($module->modname, $types_with_own_description_panel) && empty(trim(strip_tags((string)($module->description ?? ''))))) {
                    $cmid = (int)($module->id ?? 0);
                    if ($cmid > 0 && !empty($modinfo->cms[$cmid])) {
                        $cm = $modinfo->cms[$cmid];
                        $rawdescription = '';
                        $rawdescriptionformat = FORMAT_HTML;

                        if (!empty(trim(strip_tags((string)($cm->content ?? ''))))) {
                            $rawdescription = (string)$cm->content;
                        }

                        // Fallback for activities where cm_info->content is empty but intro is stored
                        // in the module instance table (resource/page/url/label/etc).
                        if (empty($rawdescription)) {
                            $instanceid = (int)($module->instance ?? 0);
                            if ($instanceid > 0) {
                                $instancerecord = null;
                                try {
                                    $instancerecord = $DB->get_record((string)$module->modname, ['id' => $instanceid], '*', IGNORE_MISSING);
                                } catch (\Throwable $e) {
                                    $instancerecord = null;
                                }

                                if ($instancerecord) {
                                    foreach (['intro', 'description', 'content'] as $fieldname) {
                                        $value = isset($instancerecord->{$fieldname}) ? (string)$instancerecord->{$fieldname} : '';
                                        if (!empty(trim(strip_tags($value)))) {
                                            $rawdescription = $value;
                                            $formatfield = $fieldname . 'format';
                                            if (!empty($instancerecord->{$formatfield})) {
                                                $rawdescriptionformat = (int)$instancerecord->{$formatfield};
                                            }
                                            break;
                                        }
                                    }
                                }
                            }
                        }

                        if (!empty($rawdescription)) {
                            $options = ['noclean' => true];
                            list($description,) = external_format_text(
                                $rawdescription,
                                $rawdescriptionformat,
                                \context_module::instance($cmid)->id,
                                $cm->modname,
                                'intro',
                                $cmid,
                                $options
                            );
                            if (!empty(trim(strip_tags((string)$description)))) {
                                $module->description = $description;
                            }
                        }
                    }
                }
            }
            unset($module);
        }
        unset($section);
    }

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters
    {
        return \local_soluttolms_core\external\get_data_by_courses::execute_parameters();
    }

    /**
     * TODO describe what the function actually does.
     *
     * @param string id
     * @return mixed TODO document
     */
    public static function execute(
        int $courseid,
        int $userid
    ) {
        // Validate the parameters passed to the function.
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'userid' => $userid,
        ]);
        global $DB;
        try {
            $courseData = \local_soluttolms_core\external\get_data_by_courses::execute($params['courseid'], $params['userid']);
            $courseData = json_decode($courseData['coursedata']);
            if (!$courseData) {
                throw new Exception('No se pudo decodificar la data del curso.');
            }
            self::normalize_activities_payload($courseData, (int)$params['courseid']);

            $courseProgre = $DB->get_record('gmk_course_progre', ['courseid' => $params['courseid'], 'userid' => $params['userid']], 'progress,credits');
            if ($courseProgre) {
                $progress = $courseProgre->progress;

                // [VIRTUAL FALLBACK] Fast direct grade check (no grade tree traversal).
                if ($progress < 100) {
                    $passedmap = gmk_get_user_passed_course_map_fast((int)$params['userid'], [(int)$params['courseid']], 70.0);
                    if (!empty($passedmap[(int)$params['courseid']])) {
                        $progress = 100;
                    }
                }

                $courseData->progress = (float)$progress;
                $courseData->credits = (int)$courseProgre->credits;
            } else {
                // Fallback attempt for credits if no progress record exists yet.
                $courseData->progress = 0;
                $courseData->credits = (int)$DB->get_field('local_learning_courses', 'credits', ['courseid' => $params['courseid']], IGNORE_MULTIPLE);
            }
            return ['coursedata' => json_encode($courseData)];
        } catch (Exception $e) {
            return ['status' => -1, 'error' => true, 'message' => $e->getMessage()];
        }
    }
    /**
     * Describes the return value of the {@see self::execute()} method.
     *
     * @return external_description
     */
    public static function execute_returns(): external_description
    {
        return \local_soluttolms_core\external\get_data_by_courses::execute_returns();
    }
}
