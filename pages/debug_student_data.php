<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$action = optional_param('action', '', PARAM_ALPHANUMEXT);

// --- AJAX ACTION HANDLERS (AT THE TOP) ---
if ($action === 'get_gaps') {
    header('Content-Type: application/json');
    try {
        // Students missing Periodo de Ingreso
        $piField = $DB->get_record('user_info_field', ['shortname' => 'periodo_ingreso']);
        $piId = $piField ? $piField->id : 0;
        
        // Diagnostic counts
        $totalStudents = $DB->count_records('local_learning_users', ['userrolename' => 'student']);

        // Query to find students without Entry Period OR without Academic Period
        $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.idnumber, 
                       uid_pi.data as entry_period,
                       ap.name as academic_period_name
                FROM {user} u
                JOIN {local_learning_users} llu ON llu.userid = u.id AND llu.userrolename = 'student'
                LEFT JOIN {user_info_data} uid_pi ON uid_pi.userid = u.id AND uid_pi.fieldid = $piId
                LEFT JOIN {gmk_academic_periods} ap ON ap.id = llu.academicperiodid
                WHERE u.deleted = 0 
                AND (uid_pi.data IS NULL OR uid_pi.data = '' OR llu.academicperiodid IS NULL OR llu.academicperiodid = 0)
                LIMIT 200";
                
        $studentsRaw = $DB->get_records_sql($sql);

        // Counts for the summary cards
        $piCount = $DB->count_records_sql("
            SELECT COUNT(DISTINCT u.id)
            FROM {user} u
            JOIN {local_learning_users} llu ON llu.userid = u.id AND llu.userrolename = 'student'
            LEFT JOIN {user_info_data} uid_pi ON uid_pi.userid = u.id AND uid_pi.fieldid = $piId
            WHERE u.deleted = 0 AND (uid_pi.data IS NULL OR uid_pi.data = '')
        ");

        $apCount = $DB->count_records_sql("
            SELECT COUNT(DISTINCT u.id)
            FROM {user} u
            JOIN {local_learning_users} llu ON llu.userid = u.id AND llu.userrolename = 'student'
            WHERE u.deleted = 0 AND (llu.academicperiodid IS NULL OR llu.academicperiodid = 0)
        ");

        echo json_encode([
            'status' => 'success',
            'students' => array_values($studentsRaw),
            'piCount' => (int)$piCount,
            'apCount' => (int)$apCount,
            'debug' => [
                'piField_id' => $piId,
                'total_students_found' => $totalStudents
            ]
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

if ($action === 'update_student_data') {
    header('Content-Type: application/json');
    try {
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        if (!$data || !isset($data['students'])) {
            throw new Exception("Datos inválidos.");
        }

        $log = []; $updated = 0;
        $periods = $DB->get_records('gmk_academic_periods', [], '', 'id, name');
        $periodMap = [];
        foreach ($periods as $p) { $periodMap[strtoupper(trim($p->name))] = $p->id; }

        $piField = $DB->get_record('user_info_field', ['shortname' => 'periodo_ingreso']);

        foreach ($data['students'] as $s) {
            $idnumber = trim($s['idnumber']);
            if (empty($idnumber)) continue;
            $user = $DB->get_record('user', ['idnumber' => $idnumber, 'deleted' => 0], 'id');
            if (!$user) { $log[] = "Error: Alumno con ID $idnumber no encontrado."; continue; }

            if (!empty($s['entry_period']) && $piField) {
                $existingPi = $DB->get_record('user_info_data', ['userid' => $user->id, 'fieldid' => $piField->id]);
                if ($existingPi) {
                    $existingPi->data = trim($s['entry_period']);
                    $DB->update_record('user_info_data', $existingPi);
                } else {
                    $newPi = new stdClass();
                    $newPi->userid = $user->id;
                    $newPi->fieldid = $piField->id;
                    $newPi->data = trim($s['entry_period']);
                    $newPi->dataformat = 0;
                    $DB->insert_record('user_info_data', $newPi);
                }
            }

            if (!empty($s['academic_period']) && isset($periodMap[strtoupper(trim($s['academic_period']))])) {
                $apId = $periodMap[strtoupper(trim($s['academic_period']))];
                $subscriptions = $DB->get_records('local_learning_users', ['userid' => $user->id, 'userrolename' => 'student'], 'id DESC');
                foreach ($subscriptions as $sub) {
                    if ($sub->academicperiodid != $apId) {
                        $sub->academicperiodid = $apId;
                        $sub->timemodified = time();
                        $DB->update_record('local_learning_users', $sub);
                    }
                }
            }
            $updated++;
        }
        echo json_encode(['status' => 'success', 'updated' => $updated, 'log' => $log]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// --- STANDARD PAGE LOAD ---
$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/debug_student_data.php');
$PAGE->set_context($context);
$PAGE->set_title("Limpieza de Datos");
$PAGE->set_heading("Limpieza de Datos de Estudiantes");
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();
?>

<!-- Tailwind & Vue -->
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
<script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<div id="app" class="p-6 bg-slate-50 min-h-screen font-sans">
    <div class="max-w-6xl mx-auto space-y-6">
        
        <div class="flex justify-between items-end">
            <div>
                <h1 class="text-2xl font-bold text-slate-800">Limpieza de Datos de Estudiantes</h1>
                <p class="text-slate-500 text-sm italic">Filtro aplicado: Solo usuarios con rol 'student'.</p>
            </div>
            <div class="flex gap-2">
                 <button @click="exportGaps" :disabled="loading || !gapStudents.length" class="bg-white border border-slate-300 hover:bg-slate-50 text-slate-600 px-4 py-2 rounded-lg text-sm font-bold flex items-center transition-all disabled:opacity-50">
                    Exportar Gaps (Excel)
                </button>
                 <button @click="loadGaps" :disabled="loading" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-bold flex items-center transition-all disabled:opacity-50">
                    {{ loading ? 'Cargando...' : 'Actualizar Lista' }}
                </button>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 border-l-4 border-l-amber-500">
                <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1">Sin Periodo de Ingreso</p>
                <div class="text-4xl font-black text-slate-800">{{ missingPiCount }}</div>
            </div>
            <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 border-l-4 border-l-red-500">
                <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1">Sin Periodo Lectivo</p>
                <div class="text-4xl font-black text-slate-800">{{ missingApCount }}</div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="bg-slate-50 px-6 py-4 border-b border-slate-200 space-y-1">
                <h2 class="font-bold text-slate-700 text-lg">Carga de Correcciones (Excel)</h2>
                <p class="text-xs text-slate-500 font-medium">Columnas necesarias: <b class="text-slate-800 underline">idnumber, entry_period, academic_period</b></p>
            </div>
            <div class="p-8 flex flex-col items-center justify-center border-2 border-dashed border-slate-200 m-8 rounded-2xl bg-slate-50/50">
                 <input type="file" ref="fileInput" @change="handleFileUpload" accept=".xlsx, .xls" class="hidden">
                 <div v-if="!xlsxData.length" class="text-center">
                     <button @click="$refs.fileInput.click()" class="bg-white border border-slate-300 px-8 py-4 rounded-xl shadow-md hover:bg-slate-50 transition-all font-bold text-slate-600 flex items-center gap-2">
                         <span>Subir Archivo Excel</span>
                     </button>
                 </div>
                 <div v-else class="w-full space-y-4">
                     <div class="flex justify-between items-center bg-white p-4 rounded-xl shadow-sm border border-slate-100">
                         <span class="text-sm font-bold text-green-600 flex items-center gap-3">
                             <span class="w-3 h-3 bg-green-500 rounded-full animate-ping"></span>
                             {{ xlsxData.length }} registros listos para actualizar
                         </span>
                         <div class="flex gap-3">
                             <button @click="processUpdate" :disabled="syncing" class="bg-green-600 hover:bg-green-700 text-white px-8 py-2 rounded-xl font-bold text-sm shadow-sm transition-all disabled:opacity-50">
                                 {{ syncing ? 'Ejecutando...' : 'Confirmar y Actualizar' }}
                             </button>
                             <button @click="xlsxData = []" class="bg-white border border-slate-200 text-slate-400 px-4 py-2 rounded-xl text-sm hover:text-slate-600 transition-all">Cancelar</button>
                         </div>
                     </div>
                     <div v-if="resultsLog.length" class="bg-slate-800 text-slate-300 p-6 rounded-xl text-xs font-mono max-h-60 overflow-y-auto leading-relaxed border border-slate-900 shadow-inner">
                         <div v-for="log in resultsLog" :key="log" class="mb-1">
                            <span class="text-slate-500">[{{ (new Date()).toLocaleTimeString() }}]</span> {{ log }}
                         </div>
                     </div>
                 </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-200 bg-slate-50 flex justify-between items-center">
                <h2 class="font-bold text-slate-700">Listado de Gaps Detectados (Muestra)</h2>
                <span class="text-[10px] text-slate-400 font-mono" v-if="debugInfo">Total Student Subs: {{ debugInfo.total_students_found }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-xs text-slate-500 uppercase bg-slate-50/50 border-b border-slate-100">
                        <tr>
                            <th class="px-6 py-4">Nombre Alumno</th>
                            <th class="px-6 py-4">ID Number</th>
                            <th class="px-6 py-4 text-center">P. Ingreso Actual</th>
                            <th class="px-6 py-4 text-center">P. Lectivo Actual</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <tr v-for="stu in gapStudents" :key="stu.id" class="hover:bg-slate-50/50 transition-colors">
                            <td class="px-6 py-4 font-semibold text-slate-700">{{ stu.firstname }} {{ stu.lastname }}</td>
                            <td class="px-6 py-4 text-slate-500 font-mono tracking-tighter">{{ stu.idnumber }}</td>
                            <td class="px-6 py-4 text-center">
                                <span v-if="!stu.entry_period" class="bg-amber-100 text-amber-700 px-2 py-0.5 rounded text-[10px] font-bold">VACÍO</span>
                                <span v-else class="text-slate-600">{{ stu.entry_period }}</span>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <span v-if="!stu.academic_period_name" class="bg-red-100 text-red-700 px-2 py-0.5 rounded text-[10px] font-bold">SIN ASIGNAR</span>
                                <span v-else class="text-slate-600">{{ stu.academic_period_name }}</span>
                            </td>
                        </tr>
                        <tr v-if="!gapStudents.length && !loading">
                            <td colspan="4" class="px-6 py-16 text-center text-slate-400 font-medium italic">
                                Genial. No se encontraron brechas de datos en los alumnos registrados.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
const { createApp } = Vue;
createApp({
    data() {
        return {
            loading: false,
            syncing: false,
            gapStudents: [],
            missingPiCount: 0,
            missingApCount: 0,
            xlsxData: [],
            resultsLog: [],
            debugInfo: null
        }
    },
    mounted() {
        this.loadGaps();
    },
    methods: {
        async loadGaps() {
            this.loading = true;
            try {
                const response = await axios.get(window.location.href + '?action=get_gaps');
                console.log("AJAX Load Gaps:", response.data);
                if (response.data.status === 'success') {
                    this.gapStudents = response.data.students || [];
                    this.missingPiCount = response.data.piCount || 0;
                    this.missingApCount = response.data.apCount || 0;
                    this.debugInfo = response.data.debug;
                } else {
                    alert("Error servidor: " + response.data.message);
                }
            } catch (e) {
                console.error("AJAX Error:", e);
                alert("Error técnico al cargar lista.");
            } finally {
                this.loading = false;
            }
        },
        exportGaps() {
            if (!this.gapStudents.length) return;
            const data = this.gapStudents.map(s => ({
                idnumber: s.idnumber,
                entry_period: s.entry_period || '',
                academic_period: s.academic_period_name || ''
            }));
            const ws = XLSX.utils.json_to_sheet(data);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Gaps");
            XLSX.writeFile(wb, `gaps_${new Date().getTime()}.xlsx`);
        },
        handleFileUpload(e) {
            const file = e.target.files[0];
            const reader = new FileReader();
            reader.onload = (evt) => {
                const bstr = evt.target.result;
                const wb = XLSX.read(bstr, { type: 'binary' });
                const ws = wb.Sheets[wb.SheetNames[0]];
                this.xlsxData = XLSX.utils.sheet_to_json(ws);
                console.log("Excel Parsed:", this.xlsxData);
            };
            reader.readAsBinaryString(file);
        },
        async processUpdate() {
            this.syncing = true;
            this.resultsLog = ["Iniciando actualización masiva..."];
            try {
                const response = await axios.post(window.location.href + '?action=update_student_data', {
                    students: this.xlsxData
                });
                if (response.data.status === 'success') {
                    this.resultsLog.push(`✓ Completado: ${response.data.updated} registros actualizados.`);
                    if(response.data.log.length) this.resultsLog.push(...response.data.log);
                    this.xlsxData = [];
                    setTimeout(() => this.loadGaps(), 2000);
                } else {
                    this.resultsLog.push("❌ Error: " + response.data.message);
                }
            } catch (e) {
                this.resultsLog.push("❌ Error fatal: " + e.message);
            } finally {
                this.syncing = false;
            }
        }
    }
}).mount('#app');
</script>

<?php
echo $OUTPUT->footer();
