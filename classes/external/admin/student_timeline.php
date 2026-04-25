<?php
namespace local_grupomakro_core\external\admin;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;

class student_timeline extends external_api {

    // --- Careers List ---

    public static function get_careers_list_parameters() {
        return new external_function_parameters([]);
    }

    public static function get_careers_list() {
        global $DB;
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $sql = "SELECT lp.id, lp.name, lp.shortname,
                       COALESCE(lp.coursecount, 0) as coursecount,
                       COALESCE(lp.periodcount, 0) as periodcount,
                       COUNT(CASE WHEN lu.status = 'activo' THEN 1 END) as active_count,
                       COUNT(DISTINCT lu.userid) as total_enrolled
                FROM {local_learning_plans} lp
                LEFT JOIN {local_learning_users} lu ON lp.id = lu.learningplanid
                GROUP BY lp.id, lp.name, lp.shortname, lp.coursecount, lp.periodcount
                ORDER BY lp.name ASC";

        $records = $DB->get_records_sql($sql);
        $careers = [];
        foreach ($records as $r) {
            $careers[] = [
                'id'            => (int)$r->id,
                'name'          => $r->name,
                'shortname'     => $r->shortname ?? '',
                'coursecount'   => (int)$r->coursecount,
                'periodcount'   => (int)$r->periodcount,
                'active_count'  => (int)$r->active_count,
                'total_enrolled'=> (int)$r->total_enrolled,
            ];
        }

        return ['careers' => $careers];
    }

    public static function get_careers_list_returns() {
        return new external_single_structure([
            'careers' => new external_multiple_structure(
                new external_single_structure([
                    'id'            => new external_value(PARAM_INT, 'ID del plan de aprendizaje'),
                    'name'          => new external_value(PARAM_TEXT, 'Nombre de la carrera'),
                    'shortname'     => new external_value(PARAM_TEXT, 'Nombre corto'),
                    'coursecount'   => new external_value(PARAM_INT, 'Total de cursos'),
                    'periodcount'   => new external_value(PARAM_INT, 'Cantidad de cuatrimestres'),
                    'active_count'  => new external_value(PARAM_INT, 'Estudiantes activos'),
                    'total_enrolled'=> new external_value(PARAM_INT, 'Total matriculados'),
                ])
            ),
        ]);
    }

    // --- Career Timeline ---

    public static function get_career_timeline_parameters() {
        return new external_function_parameters([
            'learningplanid' => new external_value(PARAM_INT, 'ID del plan de aprendizaje'),
        ]);
    }

    public static function get_career_timeline($learningplanid) {
        global $DB, $CFG;
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        // 1. Career info
        $career = $DB->get_record('local_learning_plans', ['id' => $learningplanid], 'id, name, shortname, periodcount');
        if (!$career) {
            return [
                'career'         => ['id' => 0, 'name' => '', 'shortname' => ''],
                'curriculum'     => [],
                'intake_periods' => [],
            ];
        }

        // 2. Curriculum structure (cuatrimestres + bimestres)
        // local_learning_periods has no 'position' column — order by id (creation order = curriculum order)
        // local_learning_subperiods does have 'position'
        $sql_curriculum = "SELECT lper.id, lper.name,
                                  sp.id as sp_id, sp.name as sp_name, sp.position as sp_pos
                           FROM {local_learning_periods} lper
                           LEFT JOIN {local_learning_subperiods} sp
                             ON sp.periodid = lper.id AND sp.learningplanid = lper.learningplanid
                           WHERE lper.learningplanid = :lp_id
                           ORDER BY lper.id ASC, sp.position ASC";
        $curr_rows = $DB->get_records_sql($sql_curriculum, ['lp_id' => $learningplanid]);

        $curriculum_map = [];
        foreach ($curr_rows as $row) {
            $pid = (int)$row->id;
            if (!isset($curriculum_map[$pid])) {
                $curriculum_map[$pid] = [
                    'id'         => $pid,
                    'name'       => $row->name,
                    'position'   => 0, // assigned below after sorting
                    'subperiods' => [],
                ];
            }
            if (!empty($row->sp_id)) {
                $curriculum_map[$pid]['subperiods'][] = [
                    'sp_id'   => (int)$row->sp_id,
                    'sp_name' => $row->sp_name,
                    'sp_pos'  => (int)($row->sp_pos ?? 0),
                ];
            }
        }
        // Assign sequential positions (1-based) by id order
        $curriculum = array_values($curriculum_map);
        foreach ($curriculum as $i => &$cp) {
            $cp['position'] = $i + 1;
        }
        unset($cp);

        // Build quick-lookup: period_id -> position
        $period_positions = [];
        foreach ($curriculum as $cp) {
            $period_positions[$cp['id']] = $cp['position'];
        }
        $max_period_pos = count($curriculum);

        // Build subperiod lookup: sp_id -> [period_id, period_position, sp_position]
        // This is needed for cumulative subperiod counting
        $subperiod_positions = [];
        foreach ($curriculum as $cp) {
            if (!empty($cp['subperiods'])) {
                foreach ($cp['subperiods'] as $sp) {
                    $subperiod_positions[$sp['sp_id']] = [
                        'period_id'        => $cp['id'],
                        'period_position'  => $cp['position'],
                        'sp_position'     => $sp['sp_pos'],
                    ];
                }
            }
        }

        // 3. Intake periods available for this career
        $sql_periods = "SELECT DISTINCT uid.data as intake_period
                        FROM {user_info_data} uid
                        JOIN {user_info_field} uif ON uif.id = uid.fieldid AND uif.shortname = 'periodo_ingreso'
                        JOIN {local_learning_users} lu ON lu.userid = uid.userid AND lu.learningplanid = :lp_id
                        WHERE uid.data IS NOT NULL AND uid.data != ''
                        ORDER BY uid.data DESC";
        $intake_rows = $DB->get_records_sql($sql_periods, ['lp_id' => $learningplanid]);
        $intake_periods_list = array_map(fn($r) => $r->intake_period, array_values($intake_rows));

        // 4. All students for this career with their period of ingreso + current level
        $sql_students = "SELECT lu.userid, lu.status, lu.currentperiodid, lu.currentsubperiodid,
                                uid.data as intake_period
                         FROM {local_learning_users} lu
                         JOIN {user_info_data} uid ON uid.userid = lu.userid
                         JOIN {user_info_field} uif ON uif.id = uid.fieldid AND uif.shortname = 'periodo_ingreso'
                         WHERE lu.learningplanid = :lp_id
                           AND uid.data IS NOT NULL AND uid.data != ''";
        $student_rows = $DB->get_records_sql($sql_students, ['lp_id' => $learningplanid]);

        // Group students by intake_period
        $students_by_period = [];
        foreach ($student_rows as $s) {
            $p = $s->intake_period;
            if (!isset($students_by_period[$p])) {
                $students_by_period[$p] = [];
            }
            $students_by_period[$p][] = $s;
        }

        // 5. Fetch Odoo counts per intake period via Express proxy
        $odoo_counts_by_period = [];
        foreach ($intake_periods_list as $ip) {
            $odoo_counts_by_period[$ip] = self::fetch_odoo_career_funnel($career->name, $ip);
        }

        // 6. Build intake_periods result
        $intake_periods_result = [];
        foreach ($intake_periods_list as $ip) {
            $students = $students_by_period[$ip] ?? [];

            $lxp_count  = count($students);
            $lxp_active = 0;
            // levels[period_id] = {active, inactive}
            $levels     = [];
            // sublevel_counts[subperiod_id] = {active, inactive}
            $sublevel_counts = [];

            foreach ($students as $s) {
                $is_active = ($s->status === 'activo');
                if ($is_active) {
                    $lxp_active++;
                }

                $cpid = (int)($s->currentperiodid ?? 0);
                if ($cpid > 0) {
                    // Count in all cuatrimestres up to and including current position
                    $cur_pos = $period_positions[$cpid] ?? 0;
                    foreach ($curriculum as $cp) {
                        if ($cp['position'] <= $cur_pos) {
                            if (!isset($levels[$cp['id']])) {
                                $levels[$cp['id']] = ['active' => 0, 'inactive' => 0];
                            }
                            if ($is_active) {
                                $levels[$cp['id']]['active']++;
                            } else {
                                $levels[$cp['id']]['inactive']++;
                            }
                        }
                    }
                }

                $cspid = (int)($s->currentsubperiodid ?? 0);
                // FIXED: Cumulative subperiod counting
                // If student is in subperiod X, they count as active/inactive in ALL
                // subperiods up to and including X within their current period
                if ($cspid > 0 && isset($subperiod_positions[$cspid])) {
                    $sp_info = $subperiod_positions[$cspid];
                    $student_period_pos = $sp_info['period_position'];
                    $student_sp_pos = $sp_info['sp_position'];

                    // Count in all subperiods of ALL periods up to student's current period
                    // AND all subperiods within the current period up to student's current subperiod
                    foreach ($curriculum as $cp) {
                        if ($cp['position'] > $student_period_pos) {
                            continue; // Student hasn't reached this period yet
                        }
                        if (!empty($cp['subperiods'])) {
                            foreach ($cp['subperiods'] as $sp) {
                                // Within current period: only count up to student's subperiod
                                // Within earlier periods: count all subperiods
                                $count_this_sp = false;
                                if ($cp['position'] < $student_period_pos) {
                                    $count_this_sp = true; // Earlier period, count all
                                } else if ($cp['position'] === $student_period_pos) {
                                    $count_this_sp = ($sp['sp_pos'] <= $student_sp_pos);
                                }

                                if ($count_this_sp) {
                                    if (!isset($sublevel_counts[$sp['sp_id']])) {
                                        $sublevel_counts[$sp['sp_id']] = ['active' => 0, 'inactive' => 0];
                                    }
                                    if ($is_active) {
                                        $sublevel_counts[$sp['sp_id']]['active']++;
                                    } else {
                                        $sublevel_counts[$sp['sp_id']]['inactive']++;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            $odoo = $odoo_counts_by_period[$ip];
            $odoo_count  = $odoo['odoo_count'];  // null if unavailable
            $odoo_active = $odoo['odoo_active'];

            // Use odoo count as CRM count (HubSpot integration pending)
            $crm_count  = $odoo_count;
            $crm_active = $odoo_active;

            // Dropout rate (CRM → LXP active)
            $dropout_rate = null;
            if (!is_null($crm_count) && $crm_count > 0) {
                $dropout_rate = round((($crm_count - $lxp_active) / $crm_count) * 100, 1);
            }

            // Serialize levels map
            $levels_arr = [];
            foreach ($levels as $pid => $cnt) {
                $levels_arr[] = [
                    'period_id' => $pid,
                    'active'    => $cnt['active'],
                    'inactive'  => $cnt['inactive'],
                ];
            }
            $sublevel_arr = [];
            foreach ($sublevel_counts as $spid => $cnt) {
                $sublevel_arr[] = [
                    'subperiod_id' => $spid,
                    'active'       => $cnt['active'],
                    'inactive'     => $cnt['inactive'],
                ];
            }

            $intake_periods_result[] = [
                'period'       => $ip,
                'crm_count'    => $crm_count,
                'crm_active'   => $crm_active,
                'odoo_count'   => $odoo_count,
                'odoo_active'  => $odoo_active,
                'lxp_count'    => $lxp_count,
                'lxp_active'   => $lxp_active,
                'dropout_rate' => $dropout_rate,
                'levels'       => $levels_arr,
                'sublevel_counts' => $sublevel_arr,
            ];
        }

        return [
            'career'         => [
                'id'        => (int)$career->id,
                'name'      => $career->name,
                'shortname' => $career->shortname ?? '',
            ],
            'curriculum'     => $curriculum,
            'intake_periods' => $intake_periods_result,
        ];
    }

    public static function get_career_timeline_returns() {
        $subperiod_struct = new external_single_structure([
            'sp_id'   => new external_value(PARAM_INT, 'ID subperiodo'),
            'sp_name' => new external_value(PARAM_TEXT, 'Nombre bimestre'),
            'sp_pos'  => new external_value(PARAM_INT, 'Posición'),
        ]);

        $curriculum_struct = new external_single_structure([
            'id'         => new external_value(PARAM_INT, 'ID cuatrimestre'),
            'name'       => new external_value(PARAM_TEXT, 'Nombre cuatrimestre'),
            'position'   => new external_value(PARAM_INT, 'Posición'),
            'subperiods' => new external_multiple_structure($subperiod_struct, 'Bimestres', VALUE_OPTIONAL),
        ]);

        $level_struct = new external_single_structure([
            'period_id' => new external_value(PARAM_INT, 'ID periodo'),
            'active'    => new external_value(PARAM_INT, 'Activos'),
            'inactive'  => new external_value(PARAM_INT, 'Inactivos'),
        ]);

        $sublevel_struct = new external_single_structure([
            'subperiod_id' => new external_value(PARAM_INT, 'ID subperiodo'),
            'active'       => new external_value(PARAM_INT, 'Activos'),
            'inactive'     => new external_value(PARAM_INT, 'Inactivos'),
        ]);

        $intake_struct = new external_single_structure([
            'period'       => new external_value(PARAM_TEXT, 'Periodo de ingreso'),
            'crm_count'    => new external_value(PARAM_INT, 'CRM total', VALUE_OPTIONAL),
            'crm_active'   => new external_value(PARAM_INT, 'CRM activos', VALUE_OPTIONAL),
            'odoo_count'   => new external_value(PARAM_INT, 'Odoo total', VALUE_OPTIONAL),
            'odoo_active'  => new external_value(PARAM_INT, 'Odoo activos', VALUE_OPTIONAL),
            'lxp_count'    => new external_value(PARAM_INT, 'LXP total matriculados'),
            'lxp_active'   => new external_value(PARAM_INT, 'LXP activos'),
            'dropout_rate' => new external_value(PARAM_FLOAT, 'Tasa deserción %', VALUE_OPTIONAL),
            'levels'       => new external_multiple_structure($level_struct, 'Conteo por cuatrimestre'),
            'sublevel_counts' => new external_multiple_structure($sublevel_struct, 'Conteo por bimestre'),
        ]);

        return new external_single_structure([
            'career' => new external_single_structure([
                'id'        => new external_value(PARAM_INT, 'ID carrera'),
                'name'      => new external_value(PARAM_TEXT, 'Nombre carrera'),
                'shortname' => new external_value(PARAM_TEXT, 'Nombre corto'),
            ]),
            'curriculum'     => new external_multiple_structure($curriculum_struct, 'Estructura curricular'),
            'intake_periods' => new external_multiple_structure($intake_struct, 'Periodos de ingreso'),
        ]);
    }

    // --- Get Students by Subperiod ---

    public static function get_students_by_subperiod_parameters() {
        return new external_function_parameters([
            'learningplanid' => new external_value(PARAM_INT, 'ID del plan de aprendizaje'),
            'subperiodid' => new external_value(PARAM_INT, 'ID del subperíodo (bimestre)'),
            'intake_period' => new external_value(PARAM_TEXT, 'Periodo de ingreso (cohorte)'),
        ]);
    }

    public static function get_students_by_subperiod($learningplanid, $subperiodid, $intake_period) {
        global $DB;
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        // Get the subperiod info (position)
        $subperiod = $DB->get_record('local_learning_subperiods', ['id' => $subperiodid], 'id, periodid, position');
        if (!$subperiod) {
            return ['students' => [], 'available_periods' => []];
        }

        // Get students from this intake_period who are at this subperiod position or beyond
        // A student is "in" a subperiod if their currentsubperiodid >= that subperiod's position
        // within the same period structure
        $sql = "SELECT u.id as userid,
                       u.username,
                       u.firstname,
                       u.lastname,
                       u.email,
                       p.data as phone,
                       lu.status,
                       lu.currentperiodid,
                       lu.currentsubperiodid,
                       uid_periodo.data as intake_period
                FROM {local_learning_users} lu
                JOIN {user} u ON u.id = lu.userid
                JOIN {user_info_data} uid_periodo ON uid_periodo.userid = lu.userid
                JOIN {user_info_field} uif_periodo ON uif_periodo.id = uid_periodo.fieldid
                   AND uif_periodo.shortname = 'periodo_ingreso'
                LEFT JOIN {user_info_data} p ON p.userid = u.id
                LEFT JOIN {user_info_field} pf ON pf.id = p.fieldid AND pf.shortname = 'phone'
                WHERE lu.learningplanid = :lp_id
                  AND uid_periodo.data = :intake_period
                  AND lu.currentsubperiodid = :subperiodid
                ORDER BY u.lastname, u.firstname";

        $students = $DB->get_records_sql($sql, [
            'lp_id' => $learningplanid,
            'intake_period' => $intake_period,
            'subperiodid' => $subperiodid,
        ]);

        $result = [];
        foreach ($students as $s) {
            $result[] = [
                'userid' => (int)$s->userid,
                'username' => $s->username ?? '',
                'firstname' => $s->firstname,
                'lastname' => $s->lastname,
                'fullname' => fullname($s),
                'email' => $s->email ?? '',
                'phone' => $s->phone ?? '',
                'status' => $s->status,
                'intake_period' => $s->intake_period,
            ];
        }

        // Get available intake periods for reassignment dropdown
        $available_periods = $DB->get_fieldset_sql(
            "SELECT DISTINCT uid.data as periodo
             FROM {user_info_data} uid
             JOIN {user_info_field} uif ON uif.id = uid.fieldid AND uif.shortname = 'periodo_ingreso'
             WHERE uid.data IS NOT NULL AND uid.data != ''
             ORDER BY uid.data DESC"
        );

        return [
            'students' => $result,
            'available_periods' => $available_periods,
        ];
    }

    public static function get_students_by_subperiod_returns() {
        return new external_single_structure([
            'students' => new external_multiple_structure(
                new external_single_structure([
                    'userid' => new external_value(PARAM_INT, 'ID usuario'),
                    'username' => new external_value(PARAM_TEXT, 'Nombre de usuario'),
                    'firstname' => new external_value(PARAM_TEXT, 'Nombre'),
                    'lastname' => new external_value(PARAM_TEXT, 'Apellido'),
                    'fullname' => new external_value(PARAM_TEXT, 'Nombre completo'),
                    'email' => new external_value(PARAM_TEXT, 'Email'),
                    'phone' => new external_value(PARAM_TEXT, 'Teléfono'),
                    'status' => new external_value(PARAM_TEXT, 'Estado'),
                    'intake_period' => new external_value(PARAM_TEXT, 'Periodo de ingreso actual'),
                ])
            ),
            'available_periods' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Periodo de ingreso')
            ),
        ]);
    }

    // --- Reassign Student Intake Period ---

    public static function reassign_student_intake_period_parameters() {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'ID del usuario'),
            'new_intake_period' => new external_value(PARAM_TEXT, 'Nuevo periodo de ingreso'),
        ]);
    }

    public static function reassign_student_intake_period($userid, $new_intake_period) {
        global $DB;
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        // Get the field ID for 'periodo_ingreso'
        $field = $DB->get_record('user_info_field', ['shortname' => 'periodo_ingreso']);
        if (!$field) {
            throw new \moodle_exception('Campo periodo_ingreso no encontrado');
        }

        // Update or insert the user_info_data record
        $existing = $DB->get_record('user_info_data', [
            'userid' => $userid,
            'fieldid' => $field->id
        ]);

        if ($existing) {
            $existing->data = $new_intake_period;
            $existing->datatype = $field->datatype;
            $DB->update_record('user_info_data', $existing);
        } else {
            $newrecord = new \stdClass();
            $newrecord->userid = $userid;
            $newrecord->fieldid = $field->id;
            $newrecord->data = $new_intake_period;
            $newrecord->datatype = $field->datatype;
            $DB->insert_record('user_info_data', $newrecord);
        }

        return ['success' => true, 'userid' => $userid, 'new_intake_period' => $new_intake_period];
    }

    public static function reassign_student_intake_period_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Éxito'),
            'userid' => new external_value(PARAM_INT, 'ID usuario'),
            'new_intake_period' => new external_value(PARAM_TEXT, 'Nuevo periodo'),
        ]);
    }

    // --- Get Courses by Learning Plan with pending student counts ---

    public static function get_courses_by_learning_plan_parameters() {
        return new external_function_parameters([
            'learningplanid' => new external_value(PARAM_INT, 'ID del plan de aprendizaje'),
        ]);
    }

    public static function get_courses_by_learning_plan($learningplanid) {
        global $DB;
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        // Get all courses for this learning plan
        $sql = "SELECT lc.id, lc.courseid, lc.isrequired, lc.position, lc.credits,
                       lc.periodid, lc.subperiodid,
                       c.fullname, c.shortname,
                       sp.name as subperiod_name, sp.position as subperiod_position,
                       lper.name as period_name
                FROM {local_learning_courses} lc
                JOIN {course} c ON c.id = lc.courseid
                LEFT JOIN {local_learning_subperiods} sp ON sp.id = lc.subperiodid
                LEFT JOIN {local_learning_periods} lper ON lper.id = lc.periodid
                WHERE lc.learningplanid = :lp_id
                ORDER BY lc.position ASC";

        $courses = $DB->get_records_sql($sql, ['lp_id' => $learningplanid]);

        // Get student counts per course (enrolled in groups)
        $result = [];
        foreach ($courses as $c) {
            // Count students enrolled in this course's groups
            $enrolled_count = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT gm.userid)
                 FROM {groups_members} gm
                 JOIN {groups} g ON g.id = gm.groupid
                 WHERE g.courseid = ?",
                [$c->courseid]
            );

            // Count students in this LP who should be in this course but aren't enrolled
            // A student needs this course if: their currentsubperiodid >= this course's subperiodid
            $pending_count = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT lu.userid)
                 FROM {local_learning_users} lu
                 WHERE lu.learningplanid = :lp_id
                   AND lu.currentsubperiodid < :sp_id",
                ['lp_id' => $learningplanid, 'sp_id' => $c->subperiodid]
            );

            $result[] = [
                'id' => (int)$c->id,
                'courseid' => (int)$c->courseid,
                'fullname' => $c->fullname ?? '',
                'shortname' => $c->shortname ?? '',
                'isrequired' => (bool)$c->isrequired,
                'position' => (int)$c->position,
                'credits' => (int)$c->credits,
                'periodid' => (int)$c->periodid,
                'period_name' => $c->period_name ?? '',
                'subperiodid' => (int)$c->subperiodid,
                'subperiod_name' => $c->subperiod_name ?? '',
                'subperiod_position' => (int)($c->subperiod_position ?? 0),
                'enrolled_count' => $enrolled_count,
                'pending_count' => $pending_count,
            ];
        }

        return ['courses' => $result];
    }

    public static function get_courses_by_learning_plan_returns() {
        return new external_single_structure([
            'courses' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'ID local_learning_courses'),
                    'courseid' => new external_value(PARAM_INT, 'ID del curso Moodle'),
                    'fullname' => new external_value(PARAM_TEXT, 'Nombre completo'),
                    'shortname' => new external_value(PARAM_TEXT, 'Nombre corto'),
                    'isrequired' => new external_value(PARAM_BOOL, 'Es requerido'),
                    'position' => new external_value(PARAM_INT, 'Posición'),
                    'credits' => new external_value(PARAM_INT, 'Créditos'),
                    'periodid' => new external_value(PARAM_INT, 'ID periodo'),
                    'period_name' => new external_value(PARAM_TEXT, 'Nombre del periodo'),
                    'period_position' => new external_value(PARAM_INT, 'Posición del periodo'),
                    'subperiodid' => new external_value(PARAM_INT, 'ID subperiodo'),
                    'subperiod_name' => new external_value(PARAM_TEXT, 'Nombre del bimestre'),
                    'subperiod_position' => new external_value(PARAM_INT, 'Posición del bimestre'),
                    'enrolled_count' => new external_value(PARAM_INT, 'Estudiantes inscritos'),
                    'pending_count' => new external_value(PARAM_INT, 'Estudiantes pendientes'),
                ])
            ),
        ]);
    }

    /**
     * Calls Express proxy to get student funnel counts from Odoo.
     * Returns ['odoo_count' => int|null, 'odoo_active' => int|null].
     */
    private static function fetch_odoo_career_funnel(string $career_name, string $intake_period): array {
        $baseurl = get_config('local_grupomakro_core', 'odoo_proxy_url');
        if (empty($baseurl)) {
            $baseurl = 'https://lms.isi.edu.pa:4000';
        }
        $params = http_build_query(['lp_name' => $career_name, 'intake_period' => $intake_period]);
        $url = rtrim($baseurl, '/') . '/api/odoo/students/career-funnel?' . $params;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $raw = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $httpcode < 200 || $httpcode >= 300) {
            return ['odoo_count' => null, 'odoo_active' => null];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['odoo_count' => null, 'odoo_active' => null];
        }
        return [
            'odoo_count'  => isset($data['odoo_count'])  ? (int)$data['odoo_count']  : null,
            'odoo_active' => isset($data['odoo_active']) ? (int)$data['odoo_active'] : null,
        ];
    }

    /**
     * Get courses with projections and semaphore status per cohort/jornada.
     * Returns courses that can be dragged to timeline blocks.
     *
     * @param int $learningplanid
     * @param int $cohort (intake period year, e.g. 2020)
     * @param string $jornada ('Diurna', 'Nocturna', 'Sabatina', or 'ALL')
     * @return array courses with status
     */
    public static function get_courses_with_projections($learningplanid, $cohort, $jornada = 'ALL') {
        global $DB;
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        // Get all courses for this learning plan
        $sql = "SELECT lc.id, lc.courseid, lc.isrequired, lc.position, lc.credits,
                       lc.periodid, lc.subperiodid,
                       c.fullname, c.shortname,
                       sp.name as subperiod_name, sp.position as subperiod_position,
                       lper.name as period_name
                FROM {local_learning_courses} lc
                JOIN {course} c ON c.id = lc.courseid
                LEFT JOIN {local_learning_subperiods} sp ON sp.id = lc.subperiodid
                LEFT JOIN {local_learning_periods} lper ON lper.id = lc.periodid
                WHERE lc.learningplanid = :lp_id
                ORDER BY lc.position ASC";

        $courses = $DB->get_records_sql($sql, ['lp_id' => $learningplanid]);

        // Get jornada filter for students
        $jornada_filter = ($jornada != 'ALL') ? "AND uid_jornada.data = :jornada" : "";
        $jornada_param = ($jornada != 'ALL') ? ['jornada' => $jornada] : [];

        // Get intake period string pattern
        // If cohort is just a year (e.g., "2026"), use LIKE for partial match
        // If cohort includes suffix (e.g., "2026-I"), use exact match
        if (preg_match('/^\d{4}$/', $cohort)) {
            $intake_pattern = $cohort . '%';
            $intake_operator = 'LIKE';
        } else {
            $intake_pattern = $cohort;
            $intake_operator = '=';
        }

        $result = [];
        foreach ($courses as $c) {
            // Count students by status for this specific course
            // A student is "approved" if they have a grade >= 7 in grade_grades for this course
            // A student is "failed" if they have a grade < 7
            // A student is "pending" if they are enrolled in the course but have no grade yet
            // Students from the cohort who are NOT enrolled in this course are NOT counted

            $approved_count = 0;
            $failed_count = 0;
            $pending_count = 0;
            $total_students = 0;

            // Get all grade items for this course
            $grade_items = $DB->get_records('grade_items', ['courseid' => $c->courseid]);
            $item_ids = array_keys($grade_items);

            if (!empty($item_ids)) {
                $item_placeholders = implode(',', array_fill(0, count($item_ids), '?'));

                // Get students with grades in this course who match cohort/jornada
                // This ensures we only count students actually enrolled in this course
                $graded_students_sql = "
                    SELECT gg.userid, AVG(COALESCE(gg.finalgrade, gg.rawgrade)) as avg_grade
                    FROM {grade_grades} gg
                    JOIN {local_learning_users} llu ON llu.userid = gg.userid
                    JOIN {user_info_data} uid_intake ON uid_intake.userid = gg.userid
                        AND uid_intake.fieldid = (SELECT id FROM {user_info_field} WHERE shortname = 'periodo_ingreso' LIMIT 1)
                    LEFT JOIN {user_info_data} uid_jornada ON uid_jornada.userid = gg.userid
                        AND uid_jornada.fieldid = (SELECT id FROM {user_info_field} WHERE shortname = 'gmkjourney' LIMIT 1)
                    WHERE gg.itemid IN ($item_placeholders)
                      AND llu.learningplanid = ?
                      AND uid_intake.data $intake_operator ?
                      $jornada_filter
                    GROUP BY gg.userid
                ";

                $sql_params = array_merge($item_ids, [$learningplanid, $intake_pattern], $jornada_param);
                $graded_students = $DB->get_records_sql($graded_students_sql, $sql_params);

                foreach ($graded_students as $gs) {
                    $total_students++;
                    if ($gs->avg_grade >= 7) {
                        $approved_count++;
                    } else {
                        $failed_count++;
                    }
                }

                // Get students enrolled in this course via user_enrolments but WITHOUT grades (pending)
                $enrolled_no_grade_sql = "
                    SELECT COUNT(DISTINCT ue.userid) as cnt
                    FROM {user_enrolments} ue
                    JOIN {enrol} e ON e.id = ue.enrolid
                    JOIN {local_learning_users} llu ON llu.userid = ue.userid
                    JOIN {user_info_data} uid_intake ON uid_intake.userid = ue.userid
                        AND uid_intake.fieldid = (SELECT id FROM {user_info_field} WHERE shortname = 'periodo_ingreso' LIMIT 1)
                    LEFT JOIN {user_info_data} uid_jornada ON uid_jornada.userid = ue.userid
                        AND uid_jornada.fieldid = (SELECT id FROM {user_info_field} WHERE shortname = 'gmkjourney' LIMIT 1)
                    WHERE e.courseid = ?
                      AND llu.learningplanid = ?
                      AND uid_intake.data $intake_operator ?
                      $jornada_filter
                      AND ue.userid NOT IN (
                          SELECT DISTINCT gg2.userid 
                          FROM {grade_grades} gg2 
                          WHERE gg2.itemid IN ($item_placeholders)
                      )
                ";

                $enroll_params = array_merge([$c->courseid, $learningplanid, $intake_pattern], $jornada_param, $item_ids);
                $pending_result = $DB->get_record_sql($enrolled_no_grade_sql, $enroll_params);
                $pending_count = $pending_result ? (int)$pending_result->cnt : 0;
                $total_students += $pending_count;
            }

            // Determine semaphore color
            if ($total_students == 0) {
                $semaphore = 'blue'; // No students yet
            } elseif ($approved_count > 0 && $failed_count == 0 && $pending_count == 0) {
                $semaphore = 'green'; // All approved
            } elseif ($failed_count > 0 && $pending_count == 0) {
                $semaphore = 'red'; // Has failures, no pending
            } elseif ($approved_count > 0 && $failed_count > 0) {
                $semaphore = 'orange'; // Mixed
            } else {
                $semaphore = 'blue'; // All pending or no data
            }

            // Check if this course has projections for this jornada
            $projections = $DB->get_records('gmk_course_projections', [
                'learning_courses_id' => $c->id,
                'jornada' => $jornada == 'ALL' ? null : $jornada
            ]);

            $result[] = [
                'id' => (int)$c->id,
                'courseid' => (int)$c->courseid,
                'fullname' => $c->fullname ?? '',
                'shortname' => $c->shortname ?? '',
                'isrequired' => (bool)$c->isrequired,
                'position' => (int)$c->position,
                'credits' => (int)$c->credits,
                'periodid' => (int)$c->periodid,
                'period_name' => $c->period_name ?? '',
                'subperiodid' => (int)$c->subperiodid,
                'subperiod_name' => $c->subperiod_name ?? '',
                'subperiod_position' => (int)($c->subperiod_position ?? 0),
                'status' => [
                    'semaphore' => $semaphore,
                    'approved_count' => $approved_count,
                    'pending_count' => $pending_count,
                    'failed_count' => $failed_count,
                    'total_students' => $total_students
                ],
                'projections' => array_values(array_map(function($p) {
                    return [
                        'subperiodid' => (int)$p->subperiodid,
                        'jornada' => $p->jornada,
                        'status' => (int)$p->status,
                        'projected_opening_date' => $p->projected_opening_date ? (int)$p->projected_opening_date : null
                    ];
                }, $projections))
            ];
        }

        return ['courses' => $result];
    }

    /**
     * Returns definition of get_courses_with_projections().
     */
    public static function get_courses_with_projections_parameters() {
        return new external_function_parameters([
            'learningplanid' => new external_value(PARAM_INT, 'Learning plan ID'),
            'cohort' => new external_value(PARAM_TEXT, 'Cohort period (e.g. 2026-I, 2025-II)'),
            'jornada' => new external_value(PARAM_TEXT, 'Jornada filter (Diurna/Nocturna/Sabatina/ALL)', false, 'ALL')
        ]);
    }

    /**
     * Returns definition of get_courses_with_projections() response.
     */
    public static function get_courses_with_projections_returns() {
        return new external_single_structure([
            'courses' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'ID'),
                    'courseid' => new external_value(PARAM_INT, 'Course ID'),
                    'fullname' => new external_value(PARAM_TEXT, 'Full name'),
                    'shortname' => new external_value(PARAM_TEXT, 'Short name'),
                    'isrequired' => new external_value(PARAM_BOOL, 'Is required'),
                    'position' => new external_value(PARAM_INT, 'Position'),
                    'credits' => new external_value(PARAM_INT, 'Credits'),
                    'periodid' => new external_value(PARAM_INT, 'Period ID'),
                    'period_name' => new external_value(PARAM_TEXT, 'Period name'),
                    'subperiodid' => new external_value(PARAM_INT, 'Subperiod ID'),
                    'subperiod_name' => new external_value(PARAM_TEXT, 'Subperiod name'),
                    'subperiod_position' => new external_value(PARAM_INT, 'Subperiod position'),
                    'status' => new external_single_structure([
                        'semaphore' => new external_value(PARAM_TEXT, 'green/orange/blue/red'),
                        'approved_count' => new external_value(PARAM_INT, 'Approved students'),
                        'pending_count' => new external_value(PARAM_INT, 'Pending students'),
                        'failed_count' => new external_value(PARAM_INT, 'Failed students'),
                        'total_students' => new external_value(PARAM_INT, 'Total students'),
                    ]),
                    'projections' => new external_multiple_structure(
                        new external_single_structure([
                            'subperiodid' => new external_value(PARAM_INT, 'Subperiod ID'),
                            'jornada' => new external_value(PARAM_TEXT, 'Jornada'),
                            'status' => new external_value(PARAM_INT, 'Status'),
                            'projected_opening_date' => new external_value(PARAM_INT, 'Opening date', false, null)
                        ])
                    )
                ])
            )
        ]);
    }

    /**
     * Save or update a course projection (drag-drop result).
     *
     * @param int $learning_courses_id
     * @param int $subperiodid
     * @param string $jornada
     * @param int|null $projected_opening_date
     * @param string|null $notes
     * @return array success message
     */
    public static function save_course_projection($learning_courses_id, $subperiodid, $jornada, $projected_opening_date = null, $notes = null) {
        global $DB, $USER;
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        // Check if record already exists
        $existing = $DB->get_record('gmk_course_projections', [
            'learning_courses_id' => $learning_courses_id,
            'subperiodid' => $subperiodid,
            'jornada' => $jornada
        ]);

        $now = time();

        if ($existing) {
            // Update existing
            $existing->subperiodid = $subperiodid;
            $existing->jornada = $jornada;
            if ($projected_opening_date !== null) {
                $existing->projected_opening_date = $projected_opening_date;
            }
            if ($notes !== null) {
                $existing->notes = $notes;
            }
            $existing->timemodified = $now;
            $existing->usermodified = (int)$USER->id;

            $DB->update_record('gmk_course_projections', $existing);
            $id = $existing->id;
            $action = 'updated';
        } else {
            // Create new
            $record = new \stdClass();
            $record->learning_courses_id = $learning_courses_id;
            $record->subperiodid = $subperiodid;
            $record->jornada = $jornada;
            $record->projected_opening_date = $projected_opening_date;
            $record->status = 0; // planned
            $record->notes = $notes;
            $record->usermodified = (int)$USER->id;
            $record->timecreated = $now;
            $record->timemodified = $now;

            $id = $DB->insert_record('gmk_course_projections', $record);
            $action = 'created';
        }

        return [
            'success' => true,
            'id' => $id,
            'action' => $action,
            'message' => "Proyección $action exitosamente"
        ];
    }

    /**
     * Returns definition of save_course_projection().
     */
    public static function save_course_projection_parameters() {
        return new external_function_parameters([
            'learning_courses_id' => new external_value(PARAM_INT, 'Local learning courses ID'),
            'subperiodid' => new external_value(PARAM_INT, 'Target subperiod ID'),
            'jornada' => new external_value(PARAM_TEXT, 'Jornada (Diurna/Nocturna/Sabatina)'),
            'projected_opening_date' => new external_value(PARAM_INT, 'Opening timestamp', false, null),
            'notes' => new external_value(PARAM_TEXT, 'Notes', false, null)
        ]);
    }

    /**
     * Returns definition of save_course_projection() response.
     */
    public static function save_course_projection_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success'),
            'id' => new external_value(PARAM_INT, 'Projection ID'),
            'action' => new external_value(PARAM_TEXT, 'Action taken'),
            'message' => new external_value(PARAM_TEXT, 'Message')
        ]);
    }

    /**
     * Delete a course projection.
     *
     * @param int $learning_courses_id
     * @param int $subperiodid
     * @param string $jornada
     * @return array success message
     */
    public static function delete_course_projection($learning_courses_id, $subperiodid, $jornada) {
        global $DB;
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $deleted = $DB->delete_records('gmk_course_projections', [
            'learning_courses_id' => $learning_courses_id,
            'subperiodid' => $subperiodid,
            'jornada' => $jornada
        ]);

        return [
            'success' => $deleted > 0,
            'deleted_count' => $deleted,
            'message' => $deleted > 0 ? 'Proyección eliminada' : 'No se encontró la proyección'
        ];
    }

    /**
     * Returns definition of delete_course_projection().
     */
    public static function delete_course_projection_parameters() {
        return new external_function_parameters([
            'learning_courses_id' => new external_value(PARAM_INT, 'Local learning courses ID'),
            'subperiodid' => new external_value(PARAM_INT, 'Subperiod ID'),
            'jornada' => new external_value(PARAM_TEXT, 'Jornada')
        ]);
    }

    /**
     * Returns definition of delete_course_projection() response.
     */
    public static function delete_course_projection_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success'),
            'deleted_count' => new external_value(PARAM_INT, 'Number of deleted records'),
            'message' => new external_value(PARAM_TEXT, 'Message')
        ]);
    }
}
