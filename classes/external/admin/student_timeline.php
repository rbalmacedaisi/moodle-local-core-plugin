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
}
