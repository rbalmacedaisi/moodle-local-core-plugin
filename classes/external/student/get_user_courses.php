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
 * Class definition for the local_grupomakro_get_user_courses external function.
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
require_once($CFG->dirroot . '/enrol/externallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/pages/absence_helpers.php');
require_once($CFG->dirroot . '/local/sc_learningplans/classes/local/credit_resolver.php');

/**
 * External function 'local_grupomakro_get_user_courses' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_user_courses extends external_api
{

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters
    {
        return \core_enrol_external::get_users_courses_parameters();
    }

    /**
     * TODO describe what the function actually does.
     *
     * @param string id
     * @return mixed TODO document
     */
    public static function execute(
        string $userId
    ) {
        // Validate the parameters passed to the function.
        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userId,
        ]);
        global $DB;
try {
            $userCourses = \core_enrol_external::get_users_courses($params['userid'], false);
            // AUDIT FIX 2026-10-08: el codigo original llamaba
            //   $DB->get_records('gmk_course_progre', ['userid' => $id], '',
            //     'courseid,learningplanid,progress,credits')
            // lo cual (a) NO incluye `id` y por tanto Moodle intenta indexar
            // el array por `courseid` (la primera columna del SELECT), y
            // (b) si el mismo user tiene DOS filas para el mismo courseid
            // (caso real: un estudiante reprueba, se reinscribe y termina
            // con dos rows en gmk_course_progre), Moodle tira:
            //   "Did you remember to make the first column something
            //    unique in your call to get_records? Duplicate value 'X'
            //    found in column 'courseid'."
            // y devuelve un array con solo la ULTIMA fila de cada courseid,
            // perdiendo la fila historica. Ademas, la nota que el alumno
            // ve es la del intento anterior (la ultima que se grabo), no
            // la del intento actual.
            //
            // Cambio: usar get_records_sql con un MAX(id) por (userid,
            // courseid) y traer el resto de las columnas con un JOIN
            // auto-referencial. Asi siempre devolvemos exactamente una
            // fila por curso y es la del intento mas reciente.
            $rows = $DB->get_records_sql(
                "SELECT p.id, p.userid, p.courseid, p.learningplanid, p.progress, p.credits
                   FROM {gmk_course_progre} p
                   JOIN (
                       SELECT userid, courseid, MAX(id) AS maxid
                         FROM {gmk_course_progre}
                        WHERE userid = :userid
                     GROUP BY userid, courseid
                   ) latest ON latest.maxid = p.id
                  WHERE p.userid = :userid2",
                ['userid' => (int)$params['userid'], 'userid2' => (int)$params['userid']]
            );
            $courseids = array_map(static function($c) { return (int)$c['id']; }, $userCourses);
            $passedmap = gmk_get_user_passed_course_map_fast((int)$params['userid'], $courseids, 70.0);
            foreach ($userCourses as &$course) {
                $courseProgre = isset($rows[$course['id']]) ? $rows[$course['id']] : null;
                $progress = $courseProgre ? $courseProgre->progress : 0;

                // [VIRTUAL FALLBACK] Fast direct grade check (no grade tree traversal).
                if ($progress < 100 && !empty($passedmap[(int)$course['id']])) {
                    $progress = 100;
                }

                $course['progress'] = (float)$progress;

                // [FIX] Resolve credits from the canonical per-(plan, course) store.
                // Preference order:
                //   1. local_learning_credits (canonical)
                //   2. gmk_course_progre.credits snapshot (legacy)
                //   3. local_learning_courses.credits (legacy fallback)
                $planid = $courseProgre ? (int)$courseProgre->learningplanid : 0;
                $resolved = credit_resolver::resolve($planid, (int)$course['id']);
                if ($resolved <= 0 && $courseProgre && !empty($courseProgre->credits)) {
                    $resolved = (int)$courseProgre->credits;
                }
                if ($resolved <= 0) {
                    $resolved = (int)$DB->get_field(
                        'local_learning_courses',
                        'credits',
                        ['courseid' => $course['id']],
                        IGNORE_MULTIPLE
                    );
                }
                $course['credits'] = $resolved;

                // Absence alert payload (per-class, max severity).
                $absence = absd_get_course_absence_for_user((int)$params['userid'], (int)$course['id']);
                $course['absence'] = $absence === null ? [
                    'count'             => 0,
                    'level'             => 0,
                    'blocked'           => false,
                    'classid'           => 0,
                    'info_dismissed'    => false,
                    'warning_dismissed' => false,
                ] : $absence;
            }
            return $userCourses;
        } catch (Exception $e) {
            throw $e;
        }
    }
    /**
     * Describes the return value of the {@see self::execute()} method.
     *
     * @return external_description
     */
    public static function execute_returns(): external_description
    {
        return \core_enrol_external::get_users_courses_returns();
    }
}
