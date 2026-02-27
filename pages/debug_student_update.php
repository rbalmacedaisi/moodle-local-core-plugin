<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

global $DB, $USER;

// Get parameters
$action = optional_param('action', '', PARAM_ALPHANUMEXT);
$username = optional_param('username', '', PARAM_RAW);

// Helper function
function php_normalize_field($str) {
    if (!$str) return '';
    $str = mb_strtolower($str, 'UTF-8');
    if (class_exists('Normalizer')) {
        $str = normalizer_normalize($str, Normalizer::FORM_D);
        $str = preg_replace('/\p{Mn}/u', '', $str);
    } else {
        $str = str_replace(['á','é','í','ó','ú','ñ','Á','É','Í','Ó','Ú','Ñ'], ['a','e','i','o','u','n','a','e','i','o','u','n'], $str);
    }
    $str = preg_replace('/[^a-z0-9]/', ' ', $str);
    $str = preg_replace('/\s+/', ' ', $str);
    return trim($str);
}

// ========== AJAX HANDLER: DEBUG STUDENT DATA ==========
if ($action === 'debug_student') {
    header('Content-Type: application/json');
    try {
        if (empty($username)) {
            echo json_encode(['status' => 'error', 'message' => 'Username requerido']);
            exit;
        }

        $user = $DB->get_record('user', ['username' => trim($username), 'deleted' => 0]);
        if (!$user) {
            echo json_encode(['status' => 'error', 'message' => 'Usuario no encontrado']);
            exit;
        }

        $debug_info = [
            'user_basic' => [
                'id' => $user->id,
                'username' => $user->username,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'email' => $user->email,
                'idnumber' => $user->idnumber,
            ],
            'local_learning_users' => null,
            'plan_info' => null,
            'period_info' => null,
            'subperiod_info' => null,
            'academic_period_info' => null,
            'custom_fields' => []
        ];

        // Get local_learning_users record
        $llu = $DB->get_record('local_learning_users', ['userid' => $user->id]);
        if ($llu) {
            $debug_info['local_learning_users'] = [
                'id' => $llu->id,
                'learningplanid' => $llu->learningplanid,
                'currentperiodid' => $llu->currentperiodid,
                'currentsubperiodid' => $llu->currentsubperiodid,
                'academicperiodid' => $llu->academicperiodid,
                'groupname' => $llu->groupname,
                'status' => $llu->status,
                'userrolename' => $llu->userrolename,
            ];

            // Get plan info
            if ($llu->learningplanid) {
                $plan = $DB->get_record('local_learning_plans', ['id' => $llu->learningplanid]);
                if ($plan) {
                    $debug_info['plan_info'] = [
                        'id' => $plan->id,
                        'name' => $plan->name,
                        'normalized' => php_normalize_field($plan->name)
                    ];
                }
            }

            // Get period info
            if ($llu->currentperiodid) {
                $period = $DB->get_record('local_learning_periods', ['id' => $llu->currentperiodid]);
                if ($period) {
                    $debug_info['period_info'] = [
                        'id' => $period->id,
                        'name' => $period->name,
                        'learningplanid' => $period->learningplanid,
                        'normalized' => php_normalize_field($period->name)
                    ];
                }
            }

            // Get subperiod info
            if ($llu->currentsubperiodid) {
                $subperiod = $DB->get_record('local_learning_subperiods', ['id' => $llu->currentsubperiodid]);
                if ($subperiod) {
                    $debug_info['subperiod_info'] = [
                        'id' => $subperiod->id,
                        'name' => $subperiod->name,
                        'periodid' => $subperiod->periodid,
                        'learningplanid' => $subperiod->learningplanid,
                        'normalized' => php_normalize_field($subperiod->name)
                    ];
                }
            }

            // Get academic period info
            if ($llu->academicperiodid) {
                $academic = $DB->get_record('gmk_academic_periods', ['id' => $llu->academicperiodid]);
                if ($academic) {
                    $debug_info['academic_period_info'] = [
                        'id' => $academic->id,
                        'name' => $academic->name,
                        'status' => $academic->status,
                        'normalized' => php_normalize_field($academic->name)
                    ];
                }
            }
        }

        // Get custom profile fields
        $custom_sql = "SELECT d.id, f.shortname, f.name as fieldname, d.data
                       FROM {user_info_data} d
                       JOIN {user_info_field} f ON d.fieldid = f.id
                       WHERE d.userid = ?";
        $custom_records = $DB->get_records_sql($custom_sql, [$user->id]);
        foreach ($custom_records as $cr) {
            $debug_info['custom_fields'][$cr->shortname] = [
                'name' => $cr->fieldname,
                'value' => $cr->data
            ];
        }

        echo json_encode(['status' => 'success', 'data' => $debug_info], JSON_PRETTY_PRINT);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// ========== AJAX HANDLER: TEST PARAMETER RESOLUTION ==========
if ($action === 'test_resolution') {
    header('Content-Type: application/json');
    try {
        $plan_name = optional_param('plan_name', '', PARAM_RAW);
        $level_name = optional_param('level_name', '', PARAM_RAW);
        $subperiod_name = optional_param('subperiod_name', '', PARAM_RAW);
        $academic_name = optional_param('academic_name', '', PARAM_RAW);
        $status = optional_param('status', '', PARAM_ALPHA);
        $studentstatus = optional_param('studentstatus', '', PARAM_ALPHA);

        $resolution = [
            'input' => [
                'plan_name' => $plan_name,
                'level_name' => $level_name,
                'subperiod_name' => $subperiod_name,
                'academic_name' => $academic_name,
                'status' => $status,
                'studentstatus' => $studentstatus,
            ],
            'normalized' => [
                'plan_name' => php_normalize_field($plan_name),
                'level_name' => php_normalize_field($level_name),
                'subperiod_name' => php_normalize_field($subperiod_name),
                'academic_name' => php_normalize_field($academic_name),
            ],
            'resolved_ids' => [],
            'all_options' => []
        ];

        // Resolve Plan ID
        $planid = 0;
        if (!empty($plan_name)) {
            $normalized_pname = php_normalize_field($plan_name);
            $all_plans = $DB->get_records('local_learning_plans', null, '', 'id, name');
            $resolution['all_options']['plans'] = [];
            foreach ($all_plans as $p) {
                $p_normalized = php_normalize_field($p->name);
                $resolution['all_options']['plans'][] = [
                    'id' => $p->id,
                    'name' => $p->name,
                    'normalized' => $p_normalized,
                    'matches' => ($p_normalized === $normalized_pname)
                ];
                if ($p_normalized === $normalized_pname) {
                    $planid = $p->id;
                }
            }
        }
        $resolution['resolved_ids']['planid'] = $planid;

        // Resolve Level (Period) ID
        $current_period_id = 0;
        if ($planid > 0 && !empty($level_name)) {
            $normalized_lname = php_normalize_field($level_name);
            $plan_periods = $DB->get_records('local_learning_periods', ['learningplanid' => $planid], 'id ASC', 'id, name');
            $resolution['all_options']['periods'] = [];
            foreach ($plan_periods as $pp) {
                $pp_normalized = php_normalize_field($pp->name);
                $resolution['all_options']['periods'][] = [
                    'id' => $pp->id,
                    'name' => $pp->name,
                    'normalized' => $pp_normalized,
                    'matches' => ($pp_normalized === $normalized_lname)
                ];
                if ($pp_normalized === $normalized_lname) {
                    $current_period_id = $pp->id;
                }
            }
        }
        $resolution['resolved_ids']['current_period_id'] = $current_period_id;

        // Resolve Subperiod ID
        $current_subperiod_id = 0;
        if ($planid > 0 && $current_period_id > 0 && !empty($subperiod_name)) {
            $normalized_sname = php_normalize_field($subperiod_name);
            $subperiods = $DB->get_records('local_learning_subperiods',
                ['learningplanid' => $planid, 'periodid' => $current_period_id],
                'id ASC', 'id, name');
            $resolution['all_options']['subperiods'] = [];
            foreach ($subperiods as $sp) {
                $sp_normalized = php_normalize_field($sp->name);
                $resolution['all_options']['subperiods'][] = [
                    'id' => $sp->id,
                    'name' => $sp->name,
                    'normalized' => $sp_normalized,
                    'matches' => ($sp_normalized === $normalized_sname)
                ];
                if ($sp_normalized === $normalized_sname) {
                    $current_subperiod_id = $sp->id;
                }
            }
        }
        $resolution['resolved_ids']['current_subperiod_id'] = $current_subperiod_id;

        // Resolve Academic Period ID
        $academic_period_id = 0;
        if (!empty($academic_name)) {
            $normalized_aname = php_normalize_field($academic_name);
            $all_aps = $DB->get_records('gmk_academic_periods', null, '', 'id, name, status');
            $resolution['all_options']['academic_periods'] = [];
            foreach ($all_aps as $ap) {
                $ap_normalized = php_normalize_field($ap->name);
                $resolution['all_options']['academic_periods'][] = [
                    'id' => $ap->id,
                    'name' => $ap->name,
                    'status' => $ap->status,
                    'normalized' => $ap_normalized,
                    'matches' => ($ap_normalized === $normalized_aname)
                ];
                if ($ap_normalized === $normalized_aname) {
                    $academic_period_id = $ap->id;
                }
            }
        }
        $resolution['resolved_ids']['academic_period_id'] = $academic_period_id;

        echo json_encode(['status' => 'success', 'data' => $resolution], JSON_PRETTY_PRINT);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// ========== AJAX HANDLER: GET ALL STUDENTS SUMMARY ==========
if ($action === 'get_all_students') {
    header('Content-Type: application/json');
    try {
        $sql = "SELECT
                    u.id as userid,
                    u.username,
                    u.firstname,
                    u.lastname,
                    llu.id as llu_id,
                    lp.name as plan_name,
                    per.name as level_name,
                    sub.name as subperiod_name,
                    ap.name as academic_name,
                    llu.groupname,
                    llu.status as academic_status
                FROM {user} u
                LEFT JOIN {local_learning_users} llu ON llu.userid = u.id AND llu.userrolename = 'student'
                LEFT JOIN {local_learning_plans} lp ON llu.learningplanid = lp.id
                LEFT JOIN {local_learning_periods} per ON llu.currentperiodid = per.id
                LEFT JOIN {local_learning_subperiods} sub ON llu.currentsubperiodid = sub.id
                LEFT JOIN {gmk_academic_periods} ap ON llu.academicperiodid = ap.id
                WHERE u.deleted = 0
                ORDER BY u.username ASC
                LIMIT 50";

        $students = $DB->get_records_sql($sql);
        $result = array_values($students);

        echo json_encode(['status' => 'success', 'data' => $result, 'count' => count($result)], JSON_PRETTY_PRINT);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// ========== AJAX HANDLER: GET INITIAL DATA ==========
if ($action === 'get_initial_data') {
    header('Content-Type: application/json');
    try {
        $data = [
            'sample_students' => [],
            'plans' => [],
            'academic_periods' => [],
            'unique_values' => []
        ];

        // Get 10 random students with complete data
        $sql = "SELECT
                    llu.id as record_id,
                    u.id as userid,
                    u.username,
                    u.firstname,
                    u.lastname,
                    llu.id as llu_id,
                    llu.learningplanid,
                    llu.currentperiodid,
                    llu.currentsubperiodid,
                    llu.academicperiodid,
                    lp.name as plan_name,
                    per.name as level_name,
                    sub.name as subperiod_name,
                    ap.name as academic_name,
                    llu.groupname,
                    llu.status as academic_status
                FROM {user} u
                JOIN {local_learning_users} llu ON llu.userid = u.id AND llu.userrolename = 'student'
                LEFT JOIN {local_learning_plans} lp ON llu.learningplanid = lp.id
                LEFT JOIN {local_learning_periods} per ON llu.currentperiodid = per.id
                LEFT JOIN {local_learning_subperiods} sub ON llu.currentsubperiodid = sub.id
                LEFT JOIN {gmk_academic_periods} ap ON llu.academicperiodid = ap.id
                WHERE u.deleted = 0 AND llu.id IS NOT NULL
                ORDER BY u.id ASC
                LIMIT 10";

        $students = $DB->get_records_sql($sql);

        if (empty($students)) {
            // If no students with llu, get any students
            $sql_fallback = "SELECT
                        u.id as record_id,
                        u.id as userid,
                        u.username,
                        u.firstname,
                        u.lastname,
                        0 as llu_id,
                        0 as learningplanid,
                        0 as currentperiodid,
                        0 as currentsubperiodid,
                        0 as academicperiodid,
                        '' as plan_name,
                        '' as level_name,
                        '' as subperiod_name,
                        '' as academic_name,
                        '' as groupname,
                        '' as academic_status
                    FROM {user} u
                    WHERE u.deleted = 0 AND u.id > 1
                    ORDER BY u.id ASC
                    LIMIT 10";
            $students = $DB->get_records_sql($sql_fallback);
        }

        $data['sample_students'] = array_values($students);

        // Get all plans
        $plans = $DB->get_records('local_learning_plans', null, 'name ASC', 'id, name');
        foreach ($plans as $p) {
            $data['plans'][] = [
                'id' => $p->id,
                'name' => $p->name,
                'normalized' => php_normalize_field($p->name)
            ];
        }

        // Get all academic periods
        $academic = $DB->get_records('gmk_academic_periods', null, 'name ASC', 'id, name, status');
        foreach ($academic as $ap) {
            $data['academic_periods'][] = [
                'id' => $ap->id,
                'name' => $ap->name,
                'status' => $ap->status,
                'normalized' => php_normalize_field($ap->name)
            ];
        }

        // Get unique values from students - using get_recordset_sql to avoid duplicate key errors
        $unique_sets = [
            'plans' => [],
            'levels' => [],
            'subperiods' => [],
            'academic_periods' => [],
            'statuses' => []
        ];

        // Get unique plans
        $plans_sql = "SELECT DISTINCT lp.id, lp.name
                      FROM {local_learning_users} llu
                      JOIN {local_learning_plans} lp ON llu.learningplanid = lp.id
                      WHERE llu.userrolename = 'student' AND lp.name IS NOT NULL";
        $plans_rs = $DB->get_recordset_sql($plans_sql);
        foreach ($plans_rs as $p) {
            if (!in_array($p->name, $unique_sets['plans'])) {
                $unique_sets['plans'][] = $p->name;
            }
        }
        $plans_rs->close();

        // Get unique levels
        $levels_sql = "SELECT DISTINCT per.id, per.name
                       FROM {local_learning_users} llu
                       JOIN {local_learning_periods} per ON llu.currentperiodid = per.id
                       WHERE llu.userrolename = 'student' AND per.name IS NOT NULL";
        $levels_rs = $DB->get_recordset_sql($levels_sql);
        foreach ($levels_rs as $l) {
            if (!in_array($l->name, $unique_sets['levels'])) {
                $unique_sets['levels'][] = $l->name;
            }
        }
        $levels_rs->close();

        // Get unique subperiods
        $sub_sql = "SELECT DISTINCT sub.id, sub.name
                    FROM {local_learning_users} llu
                    JOIN {local_learning_subperiods} sub ON llu.currentsubperiodid = sub.id
                    WHERE llu.userrolename = 'student' AND sub.name IS NOT NULL";
        $sub_rs = $DB->get_recordset_sql($sub_sql);
        foreach ($sub_rs as $s) {
            if (!in_array($s->name, $unique_sets['subperiods'])) {
                $unique_sets['subperiods'][] = $s->name;
            }
        }
        $sub_rs->close();

        // Get unique academic periods
        $ap_sql = "SELECT DISTINCT ap.id, ap.name
                   FROM {local_learning_users} llu
                   JOIN {gmk_academic_periods} ap ON llu.academicperiodid = ap.id
                   WHERE llu.userrolename = 'student' AND ap.name IS NOT NULL";
        $ap_rs = $DB->get_recordset_sql($ap_sql);
        foreach ($ap_rs as $a) {
            if (!in_array($a->name, $unique_sets['academic_periods'])) {
                $unique_sets['academic_periods'][] = $a->name;
            }
        }
        $ap_rs->close();

        // Get unique statuses
        $status_sql = "SELECT DISTINCT llu.status
                       FROM {local_learning_users} llu
                       WHERE llu.userrolename = 'student' AND llu.status IS NOT NULL AND llu.status != ''";
        $status_rs = $DB->get_recordset_sql($status_sql);
        foreach ($status_rs as $st) {
            if (!in_array($st->status, $unique_sets['statuses'])) {
                $unique_sets['statuses'][] = $st->status;
            }
        }
        $status_rs->close();

        $data['unique_values'] = $unique_sets;

        echo json_encode(['status' => 'success', 'data' => $data], JSON_PRETTY_PRINT);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// ========== PAGE SETUP ==========
admin_externalpage_setup('grupomakro_core_manage_courses');

echo $OUTPUT->header();
?>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
<script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>

<style>
    [v-cloak] { display: none; }
    .code-block {
        background: #1e293b;
        color: #e2e8f0;
        padding: 1rem;
        border-radius: 0.5rem;
        overflow-x: auto;
        font-family: 'Courier New', monospace;
        font-size: 12px;
        white-space: pre-wrap;
        word-wrap: break-word;
    }
    .match-true { background-color: #10b981; color: white; }
    .match-false { background-color: #ef4444; color: white; }
</style>

<div id="debug-app" v-cloak class="bg-slate-50 min-h-screen p-6">
    <div class="max-w-7xl mx-auto space-y-6">

        <!-- Header -->
        <header class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
            <h1 class="text-3xl font-bold text-slate-900 mb-2">🔍 Debug: Actualización de Estudiantes</h1>
            <p class="text-slate-600">Herramienta de diagnóstico para analizar el flujo de datos de actualización masiva</p>

            <!-- Stats -->
            <div v-if="initialData" class="mt-4 flex gap-4">
                <div class="bg-blue-50 px-4 py-2 rounded-lg">
                    <span class="text-xs font-bold text-blue-600">PLANES:</span>
                    <span class="ml-2 text-lg font-black text-blue-900">{{ initialData.plans.length }}</span>
                </div>
                <div class="bg-green-50 px-4 py-2 rounded-lg">
                    <span class="text-xs font-bold text-green-600">PERIODOS ACAD:</span>
                    <span class="ml-2 text-lg font-black text-green-900">{{ initialData.academic_periods.length }}</span>
                </div>
                <div class="bg-purple-50 px-4 py-2 rounded-lg">
                    <span class="text-xs font-bold text-purple-600">ESTUDIANTES MUESTRA:</span>
                    <span class="ml-2 text-lg font-black text-purple-900">{{ initialData.sample_students.length }}</span>
                </div>
            </div>
        </header>

        <!-- Loading Indicator -->
        <div v-if="loading" class="bg-white p-12 rounded-2xl shadow-sm border border-slate-200 text-center">
            <div class="animate-spin w-12 h-12 border-4 border-blue-600 border-t-transparent rounded-full mx-auto mb-4"></div>
            <p class="text-slate-600 font-bold">Cargando y analizando datos automáticamente...</p>
        </div>

        <!-- Error Display -->
        <div v-if="globalError" class="bg-red-50 border-2 border-red-500 p-6 rounded-2xl">
            <h2 class="text-xl font-bold text-red-900 mb-2">❌ Error al Cargar</h2>
            <div class="bg-white p-4 rounded-lg font-mono text-sm text-red-800">{{ globalError }}</div>
        </div>

        <!-- Main Content - All Sections Visible -->
        <div v-if="!loading" class="space-y-6">

            <!-- SECTION 1: Debug Estudiante Individual -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                <h2 class="text-xl font-bold text-slate-800 mb-4">🎯 Debug Estudiante Individual (Primer estudiante analizado automáticamente)</h2>
                <p class="text-sm text-slate-600 mb-6">Análisis completo del primer estudiante del sistema</p>

                <div v-if="studentDebugData" class="space-y-4">
                    <!-- Summary Cards -->
                    <div class="grid grid-cols-3 gap-4 mb-4">
                        <div class="bg-blue-50 border border-blue-200 p-4 rounded-xl">
                            <div class="text-xs font-bold text-blue-600 mb-1">USUARIO</div>
                            <div class="text-lg font-black text-blue-900">{{ studentDebugData.user_basic.username }}</div>
                            <div class="text-sm text-blue-700">{{ studentDebugData.user_basic.firstname }} {{ studentDebugData.user_basic.lastname }}</div>
                        </div>
                        <div class="bg-green-50 border border-green-200 p-4 rounded-xl">
                            <div class="text-xs font-bold text-green-600 mb-1">PLAN</div>
                            <div class="text-sm font-bold text-green-900">{{ studentDebugData.plan_info ? studentDebugData.plan_info.name : '(sin plan)' }}</div>
                        </div>
                        <div class="bg-amber-50 border border-amber-200 p-4 rounded-xl">
                            <div class="text-xs font-bold text-amber-600 mb-1">ESTADO ACADÉMICO</div>
                            <div class="text-lg font-black text-amber-900">{{ studentDebugData.local_learning_users ? studentDebugData.local_learning_users.status : '-' }}</div>
                        </div>
                    </div>

                    <!-- Full JSON -->
                    <div class="bg-slate-900 p-4 rounded-xl">
                        <h3 class="text-white font-bold mb-2">📋 Datos Completos (JSON)</h3>
                        <div class="code-block">{{ JSON.stringify(studentDebugData, null, 2) }}</div>
                    </div>
                </div>

                <div v-if="studentDebugError" class="bg-red-50 border border-red-200 text-red-700 p-4 rounded-xl">
                    ❌ {{ studentDebugError }}
                </div>
            </div>

            <!-- SECTION 2: Test Resolución de Parámetros -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                <h2 class="text-xl font-bold text-slate-800 mb-4">🔬 Test Resolución de Parámetros (Ejecutado automáticamente)</h2>
                <p class="text-sm text-slate-600 mb-6">Muestra cómo el sistema resuelve los nombres a IDs usando datos del primer estudiante</p>

                <!-- Show the params being tested -->
                <div v-if="testParams.plan_name" class="mb-6 bg-purple-50 border border-purple-200 p-4 rounded-xl">
                    <h3 class="text-sm font-bold text-purple-800 mb-3">📋 Parámetros de Prueba (del primer estudiante)</h3>
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div><span class="font-bold">Plan:</span> {{ testParams.plan_name || '-' }}</div>
                        <div><span class="font-bold">Nivel:</span> {{ testParams.level_name || '-' }}</div>
                        <div><span class="font-bold">Subperiodo:</span> {{ testParams.subperiod_name || '-' }}</div>
                        <div><span class="font-bold">Periodo Acad:</span> {{ testParams.academic_name || '-' }}</div>
                        <div><span class="font-bold">Estado Acad:</span> {{ testParams.status || '-' }}</div>
                        <div><span class="font-bold">Estado Est:</span> {{ testParams.studentstatus || '-' }}</div>
                    </div>
                </div>

                <div v-if="resolutionData" class="space-y-4">
                    <!-- Input Normalization -->
                    <div class="bg-white border border-slate-200 p-4 rounded-xl">
                        <h3 class="font-bold text-slate-800 mb-3">📥 Input & Normalización</h3>
                        <div class="grid grid-cols-2 gap-3 text-sm">
                            <div v-for="(value, key) in resolutionData.input" :key="'input-'+key" class="bg-slate-50 p-2 rounded">
                                <span class="font-bold text-slate-600">{{ key }}:</span>
                                <span class="text-slate-800">{{ value || '(vacío)' }}</span>
                                <div class="text-xs text-slate-500 mt-1">
                                    Normalizado: {{ resolutionData.normalized[key] || '(vacío)' }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Resolved IDs -->
                    <div class="bg-blue-50 border border-blue-200 p-4 rounded-xl">
                        <h3 class="font-bold text-blue-900 mb-3">🎯 IDs Resueltos</h3>
                        <div class="grid grid-cols-2 gap-3 text-sm">
                            <div v-for="(value, key) in resolutionData.resolved_ids" :key="'id-'+key" class="bg-white p-3 rounded-lg">
                                <span class="font-bold text-blue-700">{{ key }}:</span>
                                <span class="text-2xl font-black" :class="value > 0 ? 'text-green-600' : 'text-red-600'">
                                    {{ value }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- All Options with Matching -->
                    <div class="bg-slate-900 p-4 rounded-xl">
                        <h3 class="text-white font-bold mb-3">🔍 Opciones Disponibles & Matching</h3>
                        <div class="space-y-3">
                            <div v-for="(options, category) in resolutionData.all_options" :key="category">
                                <h4 class="text-amber-400 font-bold text-sm mb-2">{{ category.toUpperCase() }}</h4>
                                <table class="w-full text-xs">
                                    <thead class="bg-slate-800 text-slate-300">
                                        <tr>
                                            <th class="px-2 py-1 text-left">ID</th>
                                            <th class="px-2 py-1 text-left">Nombre</th>
                                            <th class="px-2 py-1 text-left">Normalizado</th>
                                            <th class="px-2 py-1 text-center">Match?</th>
                                        </tr>
                                    </thead>
                                    <tbody class="text-slate-200">
                                        <tr v-for="opt in options" :key="opt.id" class="border-t border-slate-700">
                                            <td class="px-2 py-1">{{ opt.id }}</td>
                                            <td class="px-2 py-1">{{ opt.name }}</td>
                                            <td class="px-2 py-1 font-mono text-xs">{{ opt.normalized }}</td>
                                            <td class="px-2 py-1 text-center">
                                                <span
                                                    :class="opt.matches ? 'match-true' : 'match-false'"
                                                    class="px-2 py-0.5 rounded text-xs font-bold"
                                                >
                                                    {{ opt.matches ? '✓ SÍ' : '✗ NO' }}
                                                </span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="resolutionError" class="bg-red-50 border border-red-200 text-red-700 p-4 rounded-xl">
                    ❌ {{ resolutionError }}
                </div>
            </div>

            <!-- SECTION 3: Vista Estudiantes -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-6">
                <h2 class="text-xl font-bold text-slate-800 mb-4">👥 Estudiantes en Sistema (Primeros 50)</h2>
                <p class="text-sm text-slate-600 mb-6">Tabla completa de estudiantes cargada automáticamente</p>

                <div v-if="allStudents.length > 0" class="overflow-x-auto">
                    <table class="w-full text-sm border-collapse">
                        <thead class="bg-slate-800 text-white">
                            <tr>
                                <th class="px-3 py-2 text-left">Username</th>
                                <th class="px-3 py-2 text-left">Nombre</th>
                                <th class="px-3 py-2 text-left">Plan</th>
                                <th class="px-3 py-2 text-left">Nivel</th>
                                <th class="px-3 py-2 text-left">Subperiodo</th>
                                <th class="px-3 py-2 text-left">Periodo Acad.</th>
                                <th class="px-3 py-2 text-left">Grupo</th>
                                <th class="px-3 py-2 text-left">Estado Acad.</th>
                                <th class="px-3 py-2 text-center">Debug</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white">
                            <tr v-for="s in allStudents" :key="s.userid" class="border-b border-slate-100 hover:bg-slate-50">
                                <td class="px-3 py-2 font-mono text-xs">{{ s.username }}</td>
                                <td class="px-3 py-2">{{ s.firstname }} {{ s.lastname }}</td>
                                <td class="px-3 py-2">{{ s.plan_name || '-' }}</td>
                                <td class="px-3 py-2">{{ s.level_name || '-' }}</td>
                                <td class="px-3 py-2">{{ s.subperiod_name || '-' }}</td>
                                <td class="px-3 py-2">{{ s.academic_name || '-' }}</td>
                                <td class="px-3 py-2">{{ s.groupname || '-' }}</td>
                                <td class="px-3 py-2">
                                    <span :class="[
                                        'px-2 py-1 rounded text-xs font-bold',
                                        s.academic_status === 'activo' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700'
                                    ]">
                                        {{ s.academic_status || '-' }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <button
                                        @click="studentUsername = s.username; debugStudent(); scrollToTop()"
                                        class="px-2 py-1 bg-blue-100 hover:bg-blue-200 text-blue-700 text-xs rounded font-bold"
                                    >
                                        🔍
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="studentsError" class="bg-red-50 border border-red-200 text-red-700 p-4 rounded-xl">
                    ❌ {{ studentsError }}
                </div>
            </div>

        </div>
    </div>
</div>

<script>
const { createApp } = Vue;

createApp({
    data() {
        return {
            studentUsername: '',
            studentDebugData: null,
            studentDebugError: null,
            testParams: {
                plan_name: '',
                level_name: '',
                subperiod_name: '',
                academic_name: '',
                status: '',
                studentstatus: ''
            },
            resolutionData: null,
            resolutionError: null,
            allStudents: [],
            studentsError: null,
            initialData: null,
            loading: true,
            globalError: null
        }
    },
    mounted() {
        this.loadInitialData();
    },
    methods: {
        async loadInitialData() {
            this.loading = true;
            this.globalError = null;
            try {
                const url = window.location.pathname;
                console.log('Fetching initial data from:', url);

                const res = await axios.get(url, {
                    params: { action: 'get_initial_data' }
                });

                console.log('Response:', res.data);

                if (res.data.status === 'success') {
                    this.initialData = res.data.data;
                    console.log('Initial data loaded:', this.initialData);

                    // Auto-select first student and debug
                    if (this.initialData.sample_students && this.initialData.sample_students.length > 0) {
                        this.studentUsername = this.initialData.sample_students[0].username;
                        console.log('Auto-debugging student:', this.studentUsername);
                        await this.debugStudent();
                    } else {
                        this.globalError = 'No hay estudiantes en el sistema';
                    }

                    // Auto-fill test params with first student data
                    if (this.initialData.sample_students && this.initialData.sample_students.length > 0) {
                        const first = this.initialData.sample_students[0];
                        this.testParams.plan_name = first.plan_name || '';
                        this.testParams.level_name = first.level_name || '';
                        this.testParams.subperiod_name = first.subperiod_name || '';
                        this.testParams.academic_name = first.academic_name || '';
                        this.testParams.status = first.academic_status || '';

                        console.log('Auto-executing test resolution');
                        // Auto-execute test resolution
                        await this.testResolution();
                    }

                    // Auto-load all students
                    console.log('Loading all students');
                    await this.loadAllStudents();
                } else {
                    this.globalError = 'Error del servidor: ' + (res.data.message || 'Respuesta inválida');
                }
            } catch (e) {
                console.error('Error loading initial data:', e);
                this.globalError = 'Error de red: ' + e.message + '\n\nStack: ' + e.stack;
            } finally {
                this.loading = false;
            }
        },
        selectStudent(username) {
            this.studentUsername = username;
            this.debugStudent();
        },
        fillFromSample(student) {
            this.testParams.plan_name = student.plan_name || '';
            this.testParams.level_name = student.level_name || '';
            this.testParams.subperiod_name = student.subperiod_name || '';
            this.testParams.academic_name = student.academic_name || '';
            this.testParams.status = student.academic_status || '';
            this.testResolution();
        },
        async debugStudent() {
            this.studentDebugData = null;
            this.studentDebugError = null;

            if (!this.studentUsername.trim()) {
                this.studentDebugError = 'Debes ingresar un username';
                return;
            }

            try {
                const url = window.location.pathname;
                const res = await axios.get(url, {
                    params: {
                        action: 'debug_student',
                        username: this.studentUsername.trim()
                    }
                });

                if (res.data.status === 'success') {
                    this.studentDebugData = res.data.data;
                } else {
                    this.studentDebugError = res.data.message;
                }
            } catch (e) {
                this.studentDebugError = e.message;
            }
        },
        async testResolution() {
            this.resolutionData = null;
            this.resolutionError = null;

            try {
                const url = window.location.pathname;
                const res = await axios.get(url, {
                    params: {
                        action: 'test_resolution',
                        ...this.testParams
                    }
                });

                if (res.data.status === 'success') {
                    this.resolutionData = res.data.data;
                } else {
                    this.resolutionError = res.data.message;
                }
            } catch (e) {
                this.resolutionError = e.message;
            }
        },
        async loadAllStudents() {
            this.allStudents = [];
            this.studentsError = null;

            try {
                const url = window.location.pathname;
                const res = await axios.get(url, {
                    params: { action: 'get_all_students' }
                });

                if (res.data.status === 'success') {
                    this.allStudents = res.data.data;
                } else {
                    this.studentsError = res.data.message;
                }
            } catch (e) {
                this.studentsError = e.message;
            }
        },
        scrollToTop() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    }
}).mount('#debug-app');
</script>

<?php
echo $OUTPUT->footer();
