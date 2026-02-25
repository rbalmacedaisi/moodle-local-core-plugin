<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

global $DB;

// Get parameters BEFORE any page setup
$action = optional_param('action', '', PARAM_ALPHANUMEXT);
$userid = optional_param('userid', 0, PARAM_INT);
$planid = optional_param('planid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);

// ========== HELPER FUNCTIONS ==========
function php_normalize_field($str) {
    if (!$str) return '';
    $str = mb_strtolower($str, 'UTF-8');
    if (class_exists('Normalizer')) {
        $str = normalizer_normalize($str, Normalizer::FORM_D);
        $str = preg_replace('/\p{Mn}/u', '', $str);
    } else {
        // Fallback for character replacement if intl not available
        $str = str_replace(['á','é','í','ó','ú','ñ','Á','É','Í','Ó','Ú','Ñ'], ['a','e','i','o','u','n','a','e','i','o','u','n'], $str);
    }
    $str = preg_replace('/[^a-z0-9]/', ' ', $str);
    $str = preg_replace('/\s+/', ' ', $str);
    return trim($str);
}

function php_parse_date($date_str) {
    if (empty($date_str)) return 0;
    
    // Check if it's an Excel serial date (numeric > 20000 to avoid small numbers)
    if (is_numeric($date_str) && $date_str > 20000) {
        // Excel serial date to Unix timestamp (Excel base date is 1900-01-01)
        return ($date_str - 25569) * 86400;
    }
    
    // Try standard formats
    $ts = strtotime($date_str);
    if ($ts !== false) return $ts;
    
    return 0;
}

// ========== AJAX HANDLERS ==========
if ($action === 'get_plans') {
    header('Content-Type: application/json');
    try {
        $plans = $DB->get_records('local_learning_plans', null, 'name ASC', 'id, name');
        echo json_encode(['status' => 'success', 'plans' => array_values($plans)]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

if ($action === 'get_current_state') {
    header('Content-Type: application/json');
    try {
        $usernames = optional_param_array('usernames', [], PARAM_RAW);
        if (empty($usernames)) {
            echo json_encode(['status' => 'success', 'data' => (object)[]]);
            exit;
        }

        list($insql, $inparams) = $DB->get_in_or_equal($usernames);
        
        $sql = "SELECT 
                    u.id as userid,
                    u.username, u.firstname, u.lastname, u.email, u.idnumber,
                    u.institution, u.department, u.phone1, u.phone2, u.city,
                    llu.id as recordid,
                    lp.name as plan_name_db,
                    per.name as level_name_db,
                    sub.name as subperiod_name_db,
                    ap.name as academic_name_db,
                    llu.groupname as groupname_db,
                    llu.status as academic_status_db
                FROM {user} u
                LEFT JOIN {local_learning_users} llu ON llu.userid = u.id AND llu.userrolename = 'student'
                LEFT JOIN {local_learning_plans} lp ON llu.learningplanid = lp.id
                LEFT JOIN {local_learning_periods} per ON llu.currentperiodid = per.id
                LEFT JOIN {local_learning_subperiods} sub ON llu.currentsubperiodid = sub.id
                LEFT JOIN {gmk_academic_periods} ap ON llu.academicperiodid = ap.id
                WHERE u.username $insql AND u.deleted = 0";

        $records = $DB->get_records_sql($sql, $inparams);
        
        $user_ids = [];
        foreach ($records as $r) { $user_ids[$r->userid] = $r->userid; }
        
        $custom_data = [];
        if (!empty($user_ids)) {
            list($uinsql, $uinparams) = $DB->get_in_or_equal(array_values($user_ids));
            $custom_sql = "SELECT d.userid, f.shortname, d.data
                           FROM {user_info_data} d
                           JOIN {user_info_field} f ON d.fieldid = f.id
                           WHERE d.userid $uinsql";
            $c_records = $DB->get_records_sql($custom_sql, $uinparams);
            foreach ($c_records as $cr) {
                $val = $cr->data;
                if ($cr->shortname === 'birthdate' && is_numeric($val) && $val > 0) {
                    $val = date('Y-m-d', $val);
                }
                $custom_data[$cr->userid][$cr->shortname] = (string)$val;
            }
        }

        $result = [];
        foreach ($records as $r) {
            $user_key = $r->username;
            $user_info = [
                'firstname' => (string)$r->firstname,
                'lastname' => (string)$r->lastname,
                'email' => (string)$r->email,
                'idnumber' => (string)$r->idnumber,
                'institution' => (string)$r->institution,
                'department' => (string)$r->department,
                'phone1' => (string)$r->phone1,
                'phone2' => (string)$r->phone2,
                'city' => (string)$r->city,
                'plan_name' => (string)$r->plan_name_db,
                'level_name' => (string)$r->level_name_db,
                'subperiod_name' => (string)$r->subperiod_name_db,
                'academic_name' => (string)$r->academic_name_db,
                'groupname' => (string)$r->groupname_db,
                'status' => (string)$r->academic_status_db,
            ];
            
            if (isset($custom_data[$r->userid])) {
                foreach ($custom_data[$r->userid] as $k => $v) {
                    $user_info[$k] = $v;
                }
            }
            
            $result[$user_key] = $user_info;
        }

        echo json_encode(['status' => 'success', 'data' => $result]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

if ($action === 'ajax_fix') {
    header('Content-Type: application/json');
    try {
        $userid = optional_param('userid', 0, PARAM_INT);
        $username = optional_param('username', '', PARAM_RAW);
        $planid = optional_param('planid', 0, PARAM_INT);
        $plan_name = optional_param('plan_name', '', PARAM_RAW);
        $level_name = optional_param('level_name', '', PARAM_RAW);
        $subperiod_name = optional_param('subperiod_name', '', PARAM_RAW);
        $academic_name = optional_param('academic_name', '', PARAM_RAW);
        $groupname = optional_param('groupname', '', PARAM_RAW);
        $status = optional_param('status', '', PARAM_ALPHA);
        $new_idnumber = optional_param('idnumber', '', PARAM_RAW);
        
        // Identity Fields
        $firstname = optional_param('firstname', '', PARAM_TEXT);
        $lastname = optional_param('lastname', '', PARAM_TEXT);
        $email = optional_param('email', '', PARAM_RAW);
        $phone1 = optional_param('phone1', '', PARAM_RAW);
        $phone2 = optional_param('phone2', '', PARAM_RAW);
        $institution = optional_param('institution', '', PARAM_TEXT);
        $department = optional_param('department', '', PARAM_TEXT);
        $city = optional_param('city', '', PARAM_TEXT);
        $country = optional_param('country', '', PARAM_ALPHA);

        // Custom Profile Fields from Screenshot
        $usertype = optional_param('usertype', '', PARAM_TEXT);
        $accountmanager = optional_param('accountmanager', '', PARAM_RAW);
        $birthdate = optional_param('birthdate', '', PARAM_RAW);
        $documenttype = optional_param('documenttype', '', PARAM_TEXT);
        $documentnumber = optional_param('documentnumber', '', PARAM_RAW);
        $needfirsttuition = optional_param('needfirsttuition', '', PARAM_ALPHA);
        $personalemail = optional_param('personalemail', '', PARAM_RAW);
        $studentstatus = optional_param('studentstatus', '', PARAM_ALPHA);
        $gmkgenre = optional_param('gmkgenre', '', PARAM_ALPHA);
        $gmkjourney = optional_param('gmkjourney', '', PARAM_ALPHA);
        $custom_phone = optional_param('custom_phone', '', PARAM_RAW);
        $periodo_ingreso = optional_param('periodo_ingreso', '', PARAM_RAW);

        // 1. Resolve User
        if ($userid > 0) {
            $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
        } elseif (!empty($username)) {
            $user = $DB->get_record('user', ['username' => trim($username), 'deleted' => 0]);
        } else {
            throw new Exception("Identificador de usuario no proporcionado.");
        }
        if (!$user) throw new Exception("Usuario no encontrado.");
        $userid = $user->id;

        // 2. Resolve Plan
        if ($planid <= 0 && !empty($plan_name)) {
            $normalized_pname = php_normalize_field($plan_name);
            $all_plans = $DB->get_records('local_learning_plans', null, '', 'id, name');
            foreach ($all_plans as $p) {
                if (php_normalize_field($p->name) === $normalized_pname) {
                    $planid = $p->id;
                    break;
                }
            }
        }
        if ($planid <= 0) throw new Exception("Plan no proporcionado o no encontrado: '$plan_name'");

        // 3. Resolve Level (Period)
        $current_period_id = 0;
        if (!empty($level_name)) {
            $normalized_lname = php_normalize_field($level_name);
            $plan_periods = $DB->get_records('local_learning_periods', ['learningplanid' => $planid], 'id ASC', 'id, name');
            foreach ($plan_periods as $pp) {
                if (php_normalize_field($pp->name) === $normalized_lname) {
                    $current_period_id = $pp->id;
                    break;
                }
            }
        }
        if ($current_period_id <= 0) {
            // Default to first period of the plan if none specified or found
            $first_period = $DB->get_record_sql("SELECT id FROM {local_learning_periods} WHERE learningplanid = ? ORDER BY id ASC", [$planid], IGNORE_MULTIPLE);
            $current_period_id = $first_period ? $first_period->id : 1;
        }

        // 4. Resolve Subperiod
        $current_subperiod_id = 0;
        if (!empty($subperiod_name)) {
            $normalized_sname = php_normalize_field($subperiod_name);
            $subperiods = $DB->get_records('local_learning_subperiods', ['learningplanid' => $planid, 'periodid' => $current_period_id], 'id ASC', 'id, name');
            foreach ($subperiods as $sp) {
                if (php_normalize_field($sp->name) === $normalized_sname) {
                    $current_subperiod_id = $sp->id;
                    break;
                }
            }
        }

        // 5. Resolve Academic Period
        $academic_period_id = 0;
        if (!empty($academic_name)) {
            $normalized_aname = php_normalize_field($academic_name);
            $all_aps = $DB->get_records('gmk_academic_periods', null, '', 'id, name');
            foreach ($all_aps as $ap) {
                if (php_normalize_field($ap->name) === $normalized_aname) {
                    $academic_period_id = $ap->id;
                    break;
                }
            }
        }
        if ($academic_period_id <= 0) {
            $academic_period = $DB->get_record('gmk_academic_periods', ['status' => 1], 'id', IGNORE_MULTIPLE);
            $academic_period_id = $academic_period ? $academic_period->id : 0;
        }

        $transaction = $DB->start_delegated_transaction();

        // Update Identity Fields if provided
        $update_user = false;
        if (!empty($firstname) && $user->firstname !== $firstname) { $user->firstname = $firstname; $update_user = true; }
        if (!empty($lastname) && $user->lastname !== $lastname) { $user->lastname = $lastname; $update_user = true; }
        if (!empty($email) && $user->email !== $email) { $user->email = $email; $update_user = true; }
        if (!empty($new_idnumber) && $user->idnumber !== $new_idnumber) { $user->idnumber = $new_idnumber; $update_user = true; }
        if (!empty($phone1)) { $user->phone1 = $phone1; $update_user = true; }
        if (!empty($phone2)) { $user->phone2 = $phone2; $update_user = true; }
        if (!empty($institution)) { $user->institution = $institution; $update_user = true; }
        if (!empty($department)) { $user->department = $department; $update_user = true; }
        if (!empty($city)) { $user->city = $city; $update_user = true; }
        if (!empty($country)) { $user->country = $country; $update_user = true; }

        if ($update_user) {
            $DB->update_record('user', $user);
        }

        // Update Custom Profile Fields
        $custom_fields = [];
        if (!empty($usertype)) $custom_fields['usertype'] = $usertype;
        if (!empty($accountmanager)) $custom_fields['accountmanager'] = $accountmanager;
        
        $bdate_ts = php_parse_date($birthdate);
        if ($bdate_ts > 0) $custom_fields['birthdate'] = $bdate_ts;
        if (!empty($documenttype)) $custom_fields['documenttype'] = $documenttype;
        if (!empty($documentnumber)) $custom_fields['documentnumber'] = $documentnumber;
        if (!empty($needfirsttuition)) $custom_fields['needfirsttuition'] = $needfirsttuition;
        if (!empty($personalemail)) $custom_fields['personalemail'] = $personalemail;
        if (!empty($studentstatus)) $custom_fields['studentstatus'] = $studentstatus;
        if (!empty($gmkgenre)) $custom_fields['gmkgenre'] = $gmkgenre;
        if (!empty($gmkjourney)) $custom_fields['gmkjourney'] = $gmkjourney;
        if (!empty($custom_phone)) $custom_fields['custom_phone'] = $custom_phone;
        if (!empty($periodo_ingreso)) $custom_fields['periodo_ingreso'] = $periodo_ingreso;
        
        if (!empty($custom_fields)) {
            require_once($CFG->dirroot . '/user/profile/lib.php');
            profile_save_custom_fields($userid, $custom_fields);
        }

        // Assign Student Role
        $student_role = $DB->get_record('role', ['shortname' => 'student']);
        if ($student_role) {
            $context = context_system::instance();
            $has_role = $DB->record_exists('role_assignments', ['userid' => $userid, 'roleid' => $student_role->id]);
            if (!$has_role) {
                role_assign($student_role->id, $userid, $context->id);
            }
        }

        // Create/Update local_learning_users
        $llu = $DB->get_record('local_learning_users', ['userid' => $userid]);
        $status = !empty($status) ? strtolower(trim($status)) : 'activo';

        if (!$llu) {
            $record = new stdClass();
            $record->userid = $userid;
            $record->learningplanid = $planid;
            $record->currentperiodid = $current_period_id;
            $record->currentsubperiodid = $current_subperiod_id;
            $record->academicperiodid = $academic_period_id;
            $record->groupname = !empty($groupname) ? trim($groupname) : '';
            $record->userrolename = 'student';
            $record->status = $status;
            $record->timecreated = time();
            $record->timemodified = time();
            $record->usermodified = $USER->id;
            $DB->insert_record('local_learning_users', $record);
        } else {
            $llu->learningplanid = $planid;
            $llu->currentperiodid = $current_period_id;
            if ($current_subperiod_id > 0) $llu->currentsubperiodid = $current_subperiod_id;
            $llu->academicperiodid = $academic_period_id;
            if (!empty($groupname)) $llu->groupname = trim($groupname);
            $llu->userrolename = 'student';
            $llu->status = $status;
            $llu->timemodified = time();
            $llu->usermodified = $USER->id;
            $DB->update_record('local_learning_users', $llu);
        }

        $transaction->allow_commit();
        echo json_encode(['status' => 'success']);
        exit;
    } catch (Exception $e) {
        if (isset($transaction)) $transaction->rollback($e);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// ========== INITIAL STATS ==========
// Find users without roles
$sql_roles = "SELECT COUNT(u.id)
        FROM {user} u
        LEFT JOIN {role_assignments} ra ON ra.userid = u.id
        WHERE u.deleted = 0
        AND ra.id IS NULL";
$users_no_roles_count = $DB->count_records_sql($sql_roles);

// Find users without local_learning_users
$sql_llu = "SELECT COUNT(u.id)
        FROM {user} u
        LEFT JOIN {local_learning_users} llu ON llu.userid = u.id
        WHERE u.deleted = 0
        AND u.id != 1
        AND llu.id IS NULL";
$users_no_llu_count = $DB->count_records_sql($sql_llu);

// ========== NOW SETUP PAGE (AFTER FILE OPERATIONS) ==========
admin_externalpage_setup('grupomakro_core_manage_courses');

echo $OUTPUT->header();
?>

<!-- Tailwind, Vue, Axios, Lucide, XLSX -->
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
<script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
<script src="https://unpkg.com/lucide@latest"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<style>
    [v-cloak] { display: none; }
    .progress-bar { transition: width 0.3s ease; }
    .log-enter-active, .log-leave-active { transition: all 0.3s ease; }
    .log-enter-from { opacity: 0; transform: translateY(-10px); }
    .diff-new {
        @apply bg-amber-50 text-amber-700 font-bold px-1 rounded ring-1 ring-amber-100;
    }
    .diff-match {
        @apply opacity-60;
    }
</style>

<div id="fix-setup-app" v-cloak class="bg-slate-50 min-h-screen p-6 font-sans text-slate-800">
    <div class="max-w-6xl mx-auto space-y-8">
        
        <!-- Header -->
        <header class="flex justify-between items-end">
            <div>
                <h1 class="text-3xl font-bold text-slate-900 tracking-tight flex items-center gap-3">
                    <span class="p-2 bg-blue-100 rounded-xl text-blue-600"><i data-lucide="wrench" class="w-6 h-6"></i></span>
                    Reparar Configuración de Estudiantes
                </h1>
                <p class="text-slate-500 mt-2">Módulo de solución masiva para asignación de planes y sincronización de roles.</p>
            </div>
            <div class="flex gap-3">
                <a href="download_fix_template.php" class="bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 px-5 py-2.5 rounded-xl text-sm font-bold shadow-sm transition-all flex items-center gap-2">
                    <i data-lucide="download" class="w-4 h-4"></i> Plantilla Reparación
                </a>
                <a href="download_all_students.php" class="bg-slate-900 border border-slate-800 hover:bg-slate-800 text-white px-5 py-2.5 rounded-xl text-sm font-bold shadow-lg transition-all flex items-center gap-2">
                    <i data-lucide="file-spreadsheet" class="w-4 h-4 text-emerald-400"></i> Exportación Maestra
                </a>
            </div>
        </header>

        <!-- Main Cards -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <!-- Left: Stats & problematic users -->
            <div class="lg:col-span-1 space-y-6">
                <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-100">
                    <h3 class="font-bold text-slate-700 mb-4 flex items-center gap-2">
                        <i data-lucide="activity" class="text-blue-500 w-4 h-4"></i> Diagnóstico Actual
                    </h3>
                    <div class="grid grid-cols-1 gap-4">
                        <div class="bg-slate-50 p-4 rounded-xl border border-slate-100 border-l-4 border-l-red-500">
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Sin Roles</p>
                            <div class="text-3xl font-black text-slate-800"><?php echo $users_no_roles_count; ?></div>
                        </div>
                        <div class="bg-slate-50 p-4 rounded-xl border border-slate-100 border-l-4 border-l-amber-500">
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Sin Plan (llu)</p>
                            <div class="text-3xl font-black text-slate-800"><?php echo $users_no_llu_count; ?></div>
                        </div>
                    </div>
                    <p class="text-[11px] text-slate-400 mt-4 leading-relaxed italic">
                        * Estos datos corresponden a la carga inicial de la página.
                    </p>
                </div>

                <!-- Plan Debug Section -->
                <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-100">
                    <h3 class="font-bold text-slate-700 mb-4 flex items-center gap-2">
                        <i data-lucide="list" class="text-emerald-500 w-4 h-4"></i> Planes Disponibles
                    </h3>
                    <div class="max-h-60 overflow-y-auto space-y-2 pr-2 custom-scrollbar">
                        <div v-for="plan in plans" :key="plan.id" class="p-2 bg-slate-50 rounded-lg text-[10px] border border-slate-100 flex justify-between items-center group">
                            <div class="flex flex-col">
                                <span class="font-bold text-slate-700">{{ plan.name }}</span>
                                <span class="text-slate-400 font-mono">ID: {{ plan.id }}</span>
                            </div>
                            <button @click="copyToClipboard(plan.name)" class="opacity-0 group-hover:opacity-100 p-1.5 hover:bg-white rounded-md text-slate-400 hover:text-blue-500 transition-all shadow-sm border border-transparent hover:border-slate-100" title="Copiar nombre">
                                <i data-lucide="copy" class="w-3 h-3"></i>
                            </button>
                        </div>
                        <div v-if="!plans.length && !hasError" class="text-slate-400 text-xs italic">Cargando planes...</div>
                        <div v-if="hasError" class="text-red-500 text-[10px] font-bold bg-red-50 p-2 rounded-lg border border-red-100 flex flex-col gap-1">
                            <span>Error al obtener planes</span>
                            <button @click="fetchPlans" class="text-red-700 underline text-left">Reintentar</button>
                        </div>
                    </div>
                    <div class="mt-4 pt-4 border-t border-slate-50">
                        <button @click="fetchPlans" class="w-full py-2 bg-slate-50 hover:bg-slate-100 text-slate-500 text-[10px] font-bold rounded-lg transition-colors flex items-center justify-center gap-2">
                            <i data-lucide="rotate-cw" class="w-3 h-3"></i> Actualizar Listado
                        </button>
                    </div>
                </div>

                <div class="bg-blue-600 p-6 rounded-2xl shadow-xl text-white relative overflow-hidden">
                    <div class="relative z-10">
                        <h4 class="font-bold text-lg mb-2">Instrucciones</h4>
                        <ul class="text-xs space-y-2 opacity-90 font-medium">
                            <li class="flex gap-2"><span>1.</span><span>Descarga la <b>Exportación Maestra</b> para ver a todos los alumnos.</span></li>
                            <li class="flex gap-2"><span>2.</span><span>Modifica cualquier campo: Plan, Nivel, Bloque o Estado.</span></li>
                            <li class="flex gap-2"><span>3.</span><span>Sube el archivo aquí para aplicar los cambios masivamente.</span></li>
                        </ul>
                    </div>
                    <i data-lucide="info" class="absolute -bottom-4 -right-4 w-24 h-24 opacity-10 rotate-12"></i>
                </div>
            </div>

            <!-- Middle/Right: Processor -->
            <div class="lg:col-span-2 space-y-6">
                
                <!-- File Upload / Progress Card -->
                <div class="bg-white rounded-3xl shadow-sm border border-slate-200 overflow-hidden">
                    
                    <!-- Upload State -->
                    <div v-if="state === 'idle'" class="p-12 flex flex-col items-center justify-center text-center">
                        <div class="w-16 h-16 bg-blue-50 text-blue-500 rounded-full flex items-center justify-center mb-6">
                            <i data-lucide="upload-cloud" class="w-8 h-8"></i>
                        </div>
                        <h2 class="text-xl font-bold text-slate-800 mb-2">Subir Archivo de Reparación</h2>
                        <p class="text-slate-500 text-sm max-w-sm mb-8">Arrastre su archivo Excel (.xlsx) o CSV aquí para iniciar el proceso de validación.</p>
                        
                        <input type="file" ref="fileInput" class="hidden" accept=".xlsx,.csv" @change="handleFile">
                        <button @click="$refs.fileInput.click()" class="bg-blue-600 hover:bg-blue-700 text-white px-10 py-4 rounded-2xl font-bold shadow-lg shadow-blue-200 transition-all flex items-center gap-3 active:scale-95">
                            Seleccionar Archivo
                        </button>
                    </div>

                    <!-- Preview State -->
                    <div v-if="state === 'preview'" class="p-0 flex flex-col">
                        <div class="p-6 bg-slate-50 border-b border-slate-200 flex justify-between items-center">
                            <div>
                                <h3 class="font-bold text-slate-800">Previsualización de Carga</h3>
                                <p class="text-xs text-slate-500">{{ rows.length }} registros detectados</p>
                            </div>
                            <div class="flex gap-2">
                                <button @click="reset" class="px-4 py-2 border border-slate-200 rounded-xl text-sm font-bold text-slate-600 hover:bg-slate-100 transition-all">Cancelar</button>
                                <button @click="startProcess" class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white rounded-xl text-sm font-bold shadow-md transition-all">Confirmar y Procesar</button>
                            </div>
                        </div>
                        <div class="max-h-[500px] overflow-y-auto w-full">
                            <table class="w-full text-sm text-left border-collapse">
                                <thead class="bg-slate-50/50 sticky top-0 backdrop-blur-md">
                                    <tr class="text-[10px] uppercase tracking-widest text-slate-400 font-black border-b border-slate-100 whitespace-nowrap">
                                        <th class="px-6 py-4 bg-slate-100/50">Identidad Básica</th>
                                        <th class="px-6 py-4">Configuración Académica</th>
                                        <th class="px-6 py-4 bg-slate-100/50">Información de Contacto</th>
                                        <th class="px-6 py-4">Perfil & Otros Datos</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(row, idx) in rows" :key="idx" class="border-b border-slate-50 hover:bg-slate-50/50 transition-colors whitespace-nowrap">
                                        <!-- Column 1: Identidad -->
                                        <td class="px-6 py-4 bg-slate-50/30">
                                            <div class="font-bold text-slate-700 leading-tight text-xs" :class="getDiffClass(row, 'fullname')">{{ row.fullname }}</div>
                                            <div class="font-mono text-[10px] text-slate-400 italic">User: {{ row.username }}</div>
                                            <div class="mt-2 text-[11px] font-bold text-slate-600" :class="getDiffClass(row, 'idnumber')">ID: {{ row.idnumber }}</div>
                                            <div class="text-[9px] text-slate-400">
                                                <span :class="getDiffClass(row, 'documenttype')">{{ row.documenttype }}</span>: 
                                                <span :class="getDiffClass(row, 'documentnumber')">{{ row.documentnumber }}</span>
                                            </div>
                                            <div class="mt-1 text-[9px]">
                                                <span class="font-bold text-slate-500">Género:</span> <span :class="getDiffClass(row, 'gmkgenre')">{{ row.gmkgenre || '-' }}</span> | 
                                                <span class="font-bold text-slate-500">Nac:</span> <span :class="getDiffClass(row, 'birthdate')">{{ row.birthdate || '-' }}</span>
                                            </div>
                                        </td>
                                        
                                        <!-- Column 2: Académico -->
                                        <td class="px-6 py-4">
                                            <div class="text-[11px] font-bold" :class="row.plan_id ? (isDiff(row, 'plan_name') ? 'diff-new' : 'text-blue-700') : 'text-red-500'">
                                                {{ row.plan_name }}
                                                <i v-if="!row.plan_id" data-lucide="alert-circle" class="w-3 h-3 inline ml-1"></i>
                                            </div>
                                            <div class="text-[10px] text-slate-600 mt-0.5">
                                                <span class="font-bold text-slate-400">Niv/Per:</span> 
                                                <span :class="getDiffClass(row, 'level_name')">{{ row.level_name || '-' }}</span> / 
                                                <span :class="getDiffClass(row, 'academic_name')">{{ row.academic_name || '-' }}</span>
                                            </div>
                                            <div class="text-[10px] text-slate-600">
                                                <span class="font-bold text-slate-400">Sub/Bloq:</span> 
                                                <span :class="getDiffClass(row, 'subperiod_name')">{{ row.subperiod_name || '-' }}</span> / 
                                                <span :class="getDiffClass(row, 'groupname')">{{ row.groupname || '-' }}</span>
                                            </div>
                                            <div class="mt-2">
                                                <span :class="[
                                                    'px-2 py-0.5 rounded-full text-[9px] font-bold uppercase tracking-wider',
                                                    row.status === 'activo' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700',
                                                    isDiff(row, 'status') ? 'ring-2 ring-blue-400 ring-offset-1' : ''
                                                ]">
                                                    {{ row.status || 'activo' }}
                                                </span>
                                            </div>
                                        </td>

                                        <!-- Column 3: Contacto -->
                                        <td class="px-6 py-4 bg-slate-50/30">
                                            <div class="text-[11px] text-blue-600 font-medium" :class="getDiffClass(row, 'email')">{{ row.email || '-' }}</div>
                                            <div class="text-[10px] text-slate-500 italic" :class="getDiffClass(row, 'personalemail')">{{ row.personalemail || '-' }}</div>
                                            <div class="mt-2 text-[10px] flex flex-col gap-0.5">
                                                <div><span class="font-bold text-slate-400 text-[9px]">TEL 1:</span> <span :class="getDiffClass(row, 'phone1')">{{ row.phone1 || '-' }}</span></div>
                                                <div><span class="font-bold text-slate-400 text-[9px]">TEL 2:</span> <span :class="getDiffClass(row, 'phone2')">{{ row.phone2 || '-' }}</span></div>
                                                <div><span class="font-bold text-slate-400 text-[9px]">MÓVIL:</span> <span :class="getDiffClass(row, 'custom_phone')">{{ row.custom_phone || '-' }}</span></div>
                                            </div>
                                            <div class="mt-1 text-[10px] font-bold text-slate-600">Ciudad: <span :class="getDiffClass(row, 'city')">{{ row.city || '-' }}</span></div>
                                        </td>

                                        <!-- Column 4: Institucional & Otros -->
                                        <td class="px-6 py-4">
                                            <div class="text-[11px] font-bold text-slate-700" :class="getDiffClass(row, 'usertype')">{{ row.usertype || 'Estudiante' }}</div>
                                            <div class="text-[10px] font-medium text-slate-500 mb-2" :class="getDiffClass(row, 'gmkjourney')">{{ row.gmkjourney || '-' }}</div>
                                            
                                            <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-[9px]">
                                                <div><span class="font-bold text-slate-400">Asesor:</span> <span :class="getDiffClass(row, 'accountmanager')">{{ row.accountmanager || '-' }}</span></div>
                                                <div><span class="font-bold text-slate-400">Ingreso:</span> <span :class="getDiffClass(row, 'periodo_ingreso')">{{ row.periodo_ingreso || '-' }}</span></div>
                                                <div><span class="font-bold text-slate-400">Estado Est:</span> <span :class="getDiffClass(row, 'studentstatus')">{{ row.studentstatus || '-' }}</span></div>
                                                <div><span class="font-bold text-slate-400">Paga:</span> <span :class="getDiffClass(row, 'needfirsttuition')">{{ row.needfirsttuition || '-' }}</span></div>
                                            </div>
                                            
                                            <div class="mt-2 pt-2 border-t border-slate-100">
                                                <div class="text-[9px] font-bold text-slate-400 uppercase">Empresa / Depto</div>
                                                <div class="text-[10px] text-slate-600">
                                                    <span :class="getDiffClass(row, 'institution')">{{ row.institution || '-' }}</span> / 
                                                    <span :class="getDiffClass(row, 'department')">{{ row.department || '-' }}</span>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Processing State -->
                    <div v-if="state === 'processing'" class="p-12 space-y-10">
                        <div class="text-center">
                            <div class="inline-flex items-center justify-center p-3 bg-blue-50 text-blue-600 rounded-2xl mb-4 animate-bounce">
                                <i data-lucide="refresh-cw" class="w-8 h-8"></i>
                            </div>
                            <h2 class="text-2xl font-black text-slate-800">Procesando Reparación...</h2>
                            <p class="text-slate-500 text-sm mt-1">Sincronizando registros y roles en segundo plano.</p>
                        </div>

                        <!-- Progress Section -->
                        <div class="space-y-4">
                            <div class="flex justify-between items-end">
                                <span class="text-sm font-black text-slate-800">{{ progress }}% Completado</span>
                                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">{{ processedCount }} / {{ rows.length }} registros</span>
                            </div>
                            <div class="h-4 bg-slate-100 rounded-full overflow-hidden border border-slate-200">
                                <div class="h-full bg-blue-600 progress-bar" :style="{ width: progress + '%' }"></div>
                            </div>
                            <div class="flex justify-center">
                                <button v-if="!isFinished && !isCancelled" @click="cancel" class="bg-red-50 text-red-600 px-6 py-2 rounded-xl text-xs font-black border border-red-100 hover:bg-red-100 transition-all flex items-center gap-2">
                                    <i data-lucide="x-circle" class="w-3.5 h-3.5"></i> Cancelar Proceso
                                </button>
                                <button v-if="isFinished" @click="reset" class="bg-green-600 text-white px-8 py-3 rounded-xl text-sm font-bold shadow-lg shadow-green-200 transition-all active:scale-95">
                                    Finalizar y Ver Estadísticas
                                </button>
                            </div>
                        </div>

                        <!-- Mini Logs -->
                        <div class="bg-slate-900 rounded-2xl p-6 shadow-2xl">
                             <div class="flex items-center gap-2 mb-4 text-white/50 text-[10px] font-bold uppercase tracking-widest">
                                <i data-lucide="terminal" class="w-3 h-3"></i> Actividad del Servidor
                             </div>
                             <div class="space-y-1.5 max-h-40 overflow-y-auto font-mono text-[10px] scrollbar-hide">
                                 <div v-for="(log, lidx) in logs" :key="lidx" :class="log.type === 'error' ? 'text-red-400' : (log.type === 'warning' ? 'text-amber-400' : 'text-emerald-400')" class="flex gap-2">
                                     <span class="opacity-30">[{{ log.time }}]</span>
                                     <span class="font-bold">[{{ log.type.toUpperCase() }}]</span>
                                     <span>{{ log.msg }}</span>
                                 </div>
                                 <div v-if="!logs.length" class="text-white/20 italic italic">Esperando transacciones...</div>
                             </div>
                        </div>
                    </div>
                </div>

                <div v-if="state === 'idle'" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-white p-6 rounded-2xl border border-slate-200 flex gap-4 items-start">
                        <div class="p-3 bg-amber-50 text-amber-600 rounded-xl"><i data-lucide="alert-triangle" class="w-5 h-5"></i></div>
                        <div>
                            <h5 class="font-bold text-slate-800 text-sm">Validación de Rol</h5>
                            <p class="text-xs text-slate-500 mt-1">El sistema verificará si el usuario ya tiene el rol asignado para evitar duplicados.</p>
                        </div>
                    </div>
                    <div class="bg-white p-6 rounded-2xl border border-slate-200 flex gap-4 items-start">
                        <div class="p-3 bg-emerald-50 text-emerald-600 rounded-xl"><i data-lucide="database" class="w-5 h-5"></i></div>
                        <div>
                            <h5 class="font-bold text-slate-800 text-sm">Tracking Académico</h5>
                            <p class="text-xs text-slate-500 mt-1">Se creará el registro en local_learning_users necesario para el motor de proyecciones.</p>
                        </div>
                    </div>
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
            state: 'idle', // idle, preview, processing
            plans: [],
            rows: [],
            processedCount: 0,
            logs: [],
            isFinished: false,
            isCancelled: false,
            currentXhr: null,
            hasError: false,
            originalData: {}
        }
    },
    computed: {
        progress() {
            if (!this.rows.length) return 0;
            return Math.round((this.processedCount / this.rows.length) * 100);
        }
    },
    mounted() {
        this.fetchPlans();
        // Init Lucide
        setTimeout(() => lucide.createIcons(), 100);
    },
    methods: {
        async fetchPlans() {
            this.hasError = false;
            try {
                const url = window.location.pathname;
                const res = await axios.get(url, { params: { action: 'get_plans' } });
                if (res.data.status === 'success') {
                    this.plans = res.data.plans;
                } else {
                    this.hasError = true;
                    console.error("Backend error fetching plans:", res.data.message);
                }
            } catch (e) {
                this.hasError = true;
                console.error("Network/Server error fetching plans:", e);
                this.addLog('error', 'Error al cargar listado de planes de capacitación.');
            }
        },
        async fetchCurrentState() {
            if (!this.rows.length) return;
            const usernames = this.rows.map(r => r.username);
            try {
                const url = window.location.pathname;
                const res = await axios.post(url + '?action=get_current_state', 
                    new URLSearchParams({ 'usernames[]': usernames })
                );
                if (res.data.status === 'success') {
                    this.originalData = res.data.data;
                }
            } catch (e) {
                console.error("Error fetching current state:", e);
            }
        },
        isDiff(row, field) {
            if (!this.originalData || !this.originalData[row.username]) return false;
            const current = this.originalData[row.username];
            let newVal = String(row[field] || '').trim().toLowerCase();
            let oldVal = String(current[field] || '').trim().toLowerCase();
            
            // Normalize special cases
            if (field === 'fullname') {
                oldVal = (String(current.firstname || '') + ' ' + String(current.lastname || '')).trim().toLowerCase();
            }
            
            return newVal !== oldVal;
        },
        getDiffClass(row, field) {
            return this.isDiff(row, field) ? 'diff-new' : 'diff-match';
        },
        handleFile(e) {
            const file = e.target.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = (evt) => {
                const bstr = evt.target.result;
                const wb = XLSX.read(bstr, { type: 'binary' });
                const wsname = wb.SheetNames[0];
                const ws = wb.Sheets[wsname];
                
                // Convert to JSON
                const rawRows = XLSX.utils.sheet_to_json(ws, { header: 1 });
                
                // Remove header
                rawRows.shift();
                
                // Function to normalize strings for matching (remove accents, lowercase, trim)
                const normalize = (str) => {
                    if (!str) return '';
                    return String(str)
                        .normalize("NFD")
                        .replace(/[\u0300-\u036f]/g, "") // Remove accents
                        .toLowerCase()
                        .replace(/[^a-z0-9]/g, " ") // Replace non-alphanumeric with spaces
                        .replace(/\s+/g, " ") // Collapse spaces
                        .trim();
                };

                // Find the header row (the one containing 'Username')
                const headerIndex = rawRows.findIndex(r => r[0] && String(r[0]).trim() === 'Username');
                if (headerIndex === -1) {
                    alert('No se encontró la fila de encabezados (Username)');
                    this.state = 'idle';
                    return;
                }

                this.rows = rawRows
                    .slice(headerIndex + 1) // Skip headers and everything above
                    .filter(r => r[0] && String(r[0]).trim() !== '') // Skip empty rows
                    .map(r => {
                        const isMaster = r.length > 5;
                        
                        // Mapping based on column index
                        const username = String(r[0]).trim();
                        
                        // Logic for Master vs Repair Template
                        let docType, docNum, firstPay, personalMail, sStatus, genre, journey, cPhone, pIngreso, manager, uType, bDate;
                        let firstname, lastname, email, idnumber, inst, dept, ph1, ph2, city, planName, levelName, subName, academicName, groupName, statusField;

                        if (isMaster) {
                            // Column Mapping (0-27)
                            firstname = r[1] ? String(r[1]).trim() : '';
                            lastname = r[2] ? String(r[2]).trim() : '';
                            email = r[3] ? String(r[3]).trim() : '';
                            idnumber = r[4] ? String(r[4]).trim() : '';
                            inst = r[5] ? String(r[5]).trim() : '';
                            dept = r[6] ? String(r[6]).trim() : '';
                            ph1 = r[7] ? String(r[7]).trim() : '';
                            ph2 = r[8] ? String(r[8]).trim() : '';
                            city = r[9] ? String(r[9]).trim() : '';
                            planName = r[10] ? String(r[10]).trim() : '';
                            levelName = r[11] ? String(r[11]).trim() : '';
                            subName = r[12] ? String(r[12]).trim() : '';
                            academicName = r[13] ? String(r[13]).trim() : '';
                            groupName = r[14] ? String(r[14]).trim() : '';
                            statusField = r[15] ? String(r[15]).trim() : 'activo';
                            
                            // 12 Custom Fields
                            uType = r[16] ? String(r[16]).trim() : '';
                            manager = r[17] ? String(r[17]).trim() : '';
                            bDate = r[18] ? String(r[18]).trim() : '';
                            docType = r[19] ? String(r[19]).trim() : '';
                            docNum = r[20] ? String(r[20]).trim() : '';
                            firstPay = r[21] ? String(r[21]).trim() : '';
                            personalMail = r[22] ? String(r[22]).trim() : '';
                            sStatus = r[23] ? String(r[23]).trim() : '';
                            genre = r[24] ? String(r[24]).trim() : '';
                            journey = r[25] ? String(r[25]).trim() : '';
                            cPhone = r[26] ? String(r[26]).trim() : '';
                            pIngreso = r[27] ? String(r[27]).trim() : '';
                        } else {
                            // Repair Template Mapping: Username, FullName, Email, IDNumber, Plan
                            const parts = String(r[1]).trim().split(' ');
                            firstname = parts[0] || '';
                            lastname = parts.slice(1).join(' ') || '';
                            email = r[2] ? String(r[2]).trim() : '';
                            idnumber = r[3] ? String(r[3]).trim() : '';
                            planName = r[4] ? String(r[4]).trim() : '';
                            
                            inst = dept = ph1 = ph2 = city = levelName = subName = academicName = groupName = '';
                            statusField = 'activo';
                            uType = manager = bDate = docType = docNum = firstPay = personalMail = sStatus = genre = journey = cPhone = pIngreso = '';
                        }
                        
                        const normalizedInput = normalize(planName);
                        const plan = this.plans.find(p => normalize(p.name) === normalizedInput);
                        
                        return {
                            username, firstname, lastname, 
                            fullname: firstname + ' ' + lastname,
                            email, idnumber,
                            institution: inst, department: dept, phone1: ph1, phone2: ph2, city,
                            plan_name: planName, plan_id: plan ? plan.id : null,
                            level_name: levelName, subperiod_name: subName,
                            academic_name: academicName, groupname: groupName,
                            status: statusField,
                            
                            usertype: uType, accountmanager: manager, birthdate: bDate,
                            documenttype: docType, documentnumber: docNum,
                            needfirsttuition: firstPay, personalemail: personalMail,
                            studentstatus: sStatus, gmkgenre: genre, gmkjourney: journey,
                            custom_phone: cPhone, periodo_ingreso: pIngreso,
                            
                            status_ui: 'pending'
                        };
                    });
                
                this.state = 'preview';
                this.fetchCurrentState();
            };
            reader.readAsBinaryString(file);
        },
        async startProcess() {
            this.state = 'processing';
            this.processedCount = 0;
            this.logs = [];
            this.isFinished = false;
            this.isCancelled = false;

            for (let i = 0; i < this.rows.length; i++) {
                if (this.isCancelled) {
                    this.addLog('warning', 'Proceso cancelado por el usuario.');
                    break;
                }

                const row = this.rows[i];
                if (!row.plan_id) {
                    this.addLog('error', `${row.username}: Saltado - Plan no encontrado.`);
                    this.processedCount++;
                    continue;
                }

                try {
                    // Start individual fix via AJAX
                    const formData = new FormData();
                    formData.append('userid_search', row.username); // We'll search by username on backend if possible, or we need to find ID first
                    
                    // Actually, let's look for user ID first to make handled cleaner
                    // For simplicity, let's assume we can pass username to ajax_fix and it resolves it
                    // I will update the backend to handle userid_str (username) if possible, 
                    // or I should have fetched IDs during preview.
                    
                    // Let's assume we match by username. I'll tweak the backend now to handle 'username' param too.
                    
                    const url = window.location.pathname;
                    const res = await axios.post(url, null, {
                        params: {
                            action: 'ajax_fix',
                            username: row.username,
                            firstname: row.firstname,
                            lastname: row.lastname,
                            email: row.email,
                            idnumber: row.idnumber,
                            institution: row.institution,
                            department: row.department,
                            phone1: row.phone1,
                            phone2: row.phone2,
                            city: row.city,
                            plan_name: row.plan_name,
                            level_name: row.level_name,
                            subperiod_name: row.subperiod_name,
                            academic_name: row.academic_name,
                            groupname: row.groupname,
                            status: row.status,
                            
                            usertype: row.usertype,
                            accountmanager: row.accountmanager,
                            birthdate: row.birthdate,
                            documenttype: row.documenttype,
                            documentnumber: row.documentnumber,
                            needfirsttuition: row.needfirsttuition,
                            personalemail: row.personalemail,
                            studentstatus: row.studentstatus,
                            gmkgenre: row.gmkgenre,
                            gmkjourney: row.gmkjourney,
                            custom_phone: row.custom_phone,
                            periodo_ingreso: row.periodo_ingreso
                        }
                    });

                    if (res.data.status === 'success') {
                        this.addLog('success', `${row.username}: Reparado exitosamente.`);
                    } else {
                        this.addLog('error', `${row.username}: Error - ${res.data.message}`);
                    }
                } catch (e) {
                    this.addLog('error', `${row.username}: Error de red o servidor.`);
                }
                
                this.processedCount++;
            }

            this.isFinished = true;
            this.addLog('success', 'PROCESO FINALIZADO.');
        },
        addLog(type, msg) {
            this.logs.unshift({
                time: new Date().toLocaleTimeString(),
                type,
                msg
            });
            // Auto update icons if needed
            setTimeout(() => lucide.createIcons(), 50);
        },
        copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                alert('Nombre copiado: ' + text);
            });
        },
        cancel() {
            this.isCancelled = true;
        },
        reset() {
            this.state = 'idle';
            this.rows = [];
            this.processedCount = 0;
            this.logs = [];
            this.isFinished = false;
            this.isCancelled = false;
            if (this.$refs.fileInput) this.$refs.fileInput.value = '';
            setTimeout(() => lucide.createIcons(), 100);
        }
    }
}).mount('#fix-setup-app');
</script>

<?php
echo $OUTPUT->footer();
