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
use local_sc_learningplans\local\credit_resolver;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/local/sc_learningplans/classes/local/credit_resolver.php');

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
                    // Authoritative session date for the client: openingtime is the source of truth
                    // (the timestamp encoded in the module name can drift if a reschedule updates
                    // openingtime in place without renaming the module). Session start = openingtime + 600.
                    $bbbinstid = (int)($module->instance ?? 0);
                    if ($bbbinstid > 0) {
                        $bbbot = $DB->get_field('bigbluebuttonbn', 'openingtime', ['id' => $bbbinstid]);
                        if (!empty($bbbot)) {
                            $module->bbbSessionTs = (int)$bbbot + 600;
                        }
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
     * Filters the course teachers to only the instructor(s) of the student's OWN class for this
     * course. The base service returns every teacher enrolled in the (shared) core course — i.e.
     * all teachers of the learning plan. Here we keep only the teacher of the class the student is
     * actually enrolled in (gmk_course_progre.classid -> gmk_class.instructorid), matched by email
     * (the teacher payload has no userid). If it cannot be resolved, the list is left unchanged.
     *
     * @param object $courseData
     * @param int $courseid
     * @param int $userid
     * @return void
     */
    private static function normalize_teachers_payload(object &$courseData, int $courseid, int $userid): void {
        global $DB;
        if (empty($courseData->teachers) || !is_array($courseData->teachers)) {
            return;
        }
        $instructorids = $DB->get_fieldset_sql(
            "SELECT DISTINCT c.instructorid
               FROM {gmk_course_progre} p
               JOIN {gmk_class} c ON c.id = p.classid
              WHERE p.userid = :u AND p.courseid = :cc AND c.instructorid > 0",
            ['u' => $userid, 'cc' => $courseid]
        );
        if (empty($instructorids)) {
            return; // student has no class here — leave the teacher list unchanged
        }
        list($insql, $inparams) = $DB->get_in_or_equal($instructorids, SQL_PARAMS_NAMED, 'ins');
        $emails = $DB->get_fieldset_sql("SELECT email FROM {user} WHERE id $insql", $inparams);
        $emailset = [];
        foreach ($emails as $em) {
            $emailset[strtolower(trim((string)$em))] = true;
        }
        $filtered = [];
        foreach ($courseData->teachers as $t) {
            $em = isset($t->email) ? strtolower(trim((string)$t->email)) : '';
            if ($em !== '' && isset($emailset[$em])) {
                $filtered[] = $t;
            }
        }
        // Only apply the filter if it matched at least one teacher, to avoid hiding the teacher
        // entirely if emails don't line up for some reason.
        if (!empty($filtered)) {
            $courseData->teachers = array_values($filtered);
        }
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
            self::normalize_teachers_payload($courseData, (int)$params['courseid'], (int)$params['userid']);

            $courseProgre = $DB->get_record('gmk_course_progre', ['courseid' => $params['courseid'], 'userid' => $params['userid']], 'progress,credits,learningplanid');
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

                // Resolve credits from the canonical store, falling back to the
                // legacy snapshot and the legacy junction.
                $resolved = credit_resolver::resolve((int)($courseProgre->learningplanid ?? 0), (int)$params['courseid']);
                if ($resolved <= 0 && !empty($courseProgre->credits)) {
                    $resolved = (int)$courseProgre->credits;
                }
                if ($resolved <= 0) {
                    $resolved = (int)$DB->get_field(
                        'local_learning_courses',
                        'credits',
                        ['courseid' => $params['courseid']],
                        IGNORE_MULTIPLE
                    );
                }
                $courseData->credits = $resolved;
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
