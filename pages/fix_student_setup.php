<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

global $DB;

// Get parameters BEFORE any page setup
$action = optional_param('action', '', PARAM_ALPHA);
$userid = optional_param('userid', 0, PARAM_INT);
$planid = optional_param('planid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);

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

if ($action === 'ajax_fix') {
    header('Content-Type: application/json');
    try {
        $userid = optional_param('userid', 0, PARAM_INT);
        $username = optional_param('username', '', PARAM_RAW);
        $planid = required_param('planid', PARAM_INT);
        $new_idnumber = optional_param('idnumber', '', PARAM_RAW);

        if ($userid > 0) {
            $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
        } elseif (!empty($username)) {
            $user = $DB->get_record('user', ['username' => trim($username), 'deleted' => 0]);
        } else {
            throw new Exception("Identificador de usuario no proporcionado.");
        }

        if (!$user) throw new Exception("Usuario no encontrado.");
        $userid = $user->id;

        $plan = $DB->get_record('local_learning_plans', ['id' => $planid]);
        if (!$plan) throw new Exception("Plan no encontrado.");

        $transaction = $DB->start_delegated_transaction();

        // 1. Update ID Number if provided
        if (!empty($new_idnumber) && $user->idnumber !== $new_idnumber) {
            $user->idnumber = $new_idnumber;
            $DB->update_record('user', $user);
        }

        // 2. Assign Student Role
        $student_role = $DB->get_record('role', ['shortname' => 'student']);
        if ($student_role) {
            $context = context_system::instance();
            $has_role = $DB->record_exists('role_assignments', ['userid' => $userid, 'roleid' => $student_role->id]);
            if (!$has_role) {
                role_assign($student_role->id, $userid, $context->id);
            }
        }

        // 3. Create/Update local_learning_users
        $llu = $DB->get_record('local_learning_users', ['userid' => $userid]);
        
        // Find first period for this plan
        $first_period = $DB->get_record_sql("SELECT id FROM {local_learning_periods} WHERE learningplanid = ? ORDER BY id ASC", [$planid], IGNORE_MULTIPLE);
        $current_period_id = $first_period ? $first_period->id : 1;

        // Find current academic period (status = 1)
        $academic_period = $DB->get_record('gmk_academic_periods', ['status' => 1], 'id', IGNORE_MULTIPLE);
        $academic_period_id = $academic_period ? $academic_period->id : 0;

        if (!$llu) {
            $record = new stdClass();
            $record->userid = $userid;
            $record->learningplanid = $planid;
            $record->currentperiodid = $current_period_id;
            $record->academicperiodid = $academic_period_id;
            $record->userrolename = 'student';
            $record->status = 'activo';
            $record->timecreated = time();
            $record->timemodified = time();
            $record->usermodified = $USER->id;
            $DB->insert_record('local_learning_users', $record);
        } else {
            // Update existing if needed
            $llu->learningplanid = $planid;
            $llu->userrolename = 'student';
            $llu->status = 'activo';
            if (empty($llu->academicperiodid)) $llu->academicperiodid = $academic_period_id;
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
                    <i data-lucide="download" class="w-4 h-4"></i> Descargar Plantilla
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
                        <div v-if="!plans.length" class="text-slate-400 text-xs italic">Cargando planes...</div>
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
                            <li class="flex gap-2"><span>1.</span><span>Descarga la plantilla con los usuarios detectados.</span></li>
                            <li class="flex gap-2"><span>2.</span><span>Completa la columna <b>"Plan de Aprendizaje"</b>.</span></li>
                            <li class="flex gap-2"><span>3.</span><span>Sube el archivo aquí para procesar masivamente.</span></li>
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
                                    <tr class="text-[10px] uppercase tracking-widest text-slate-400 font-black border-b border-slate-100">
                                        <th class="px-6 py-4">Username</th>
                                        <th class="px-6 py-4">Nombre</th>
                                        <th class="px-6 py-4">Plan Detectado</th>
                                        <th class="px-6 py-4">ID Number</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(row, idx) in rows" :key="idx" class="border-b border-slate-50 hover:bg-slate-50/50 transition-colors">
                                        <td class="px-6 py-3 font-mono text-xs text-slate-600">{{ row.username }}</td>
                                        <td class="px-6 py-3 font-bold text-slate-700">{{ row.fullname }}</td>
                                        <td class="px-6 py-3">
                                            <span v-if="row.plan_id" class="inline-flex items-center gap-1.5 text-blue-700 bg-blue-50 px-2 py-0.5 rounded-full font-bold text-[10px]">
                                                <i data-lucide="check" class="w-3 h-3"></i> {{ row.plan_name }}
                                            </span>
                                            <span v-else class="text-red-500 font-bold text-[10px] flex items-center gap-1">
                                                <i data-lucide="alert-circle" class="w-3 h-3"></i> NO ENCONTRADO ({{ row.plan_name || 'Vacío' }})
                                            </span>
                                        </td>
                                        <td class="px-6 py-3 text-slate-400 text-xs">{{ row.idnumber || '--' }}</td>
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
            currentXhr: null
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
            try {
                const res = await axios.get(window.location.href + '?action=get_plans');
                if (res.data.status === 'success') {
                    this.plans = res.data.plans;
                }
            } catch (e) {
                console.error("Error fetching plans", e);
            }
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

                this.rows = rawRows
                    .filter(r => r[0] && String(r[0]).trim() !== 'INSTRUCCIONES:')
                    .map(r => {
                        const planName = r[4] ? String(r[4]).trim() : '';
                        const normalizedInput = normalize(planName);
                        
                        const plan = this.plans.find(p => normalize(p.name) === normalizedInput);
                        
                        return {
                            username: r[0],
                            fullname: r[1],
                            idnumber: r[3],
                            plan_name: planName,
                            plan_id: plan ? plan.id : null,
                            found: false,
                            status: 'pending'
                        };
                    });
                
                this.state = 'preview';
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
                    
                    const res = await axios.post(window.location.href + '?action=ajax_fix', null, {
                        params: {
                            username: row.username,
                            planid: row.plan_id,
                            idnumber: row.idnumber
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
