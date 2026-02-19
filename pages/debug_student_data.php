<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$action = optional_param('action', '', PARAM_ALPHANUMEXT);

// --- AJAX ACTION HANDLERS ---
if ($action === 'get_gaps') {
    header('Content-Type: application/json');
    try {
        $piField = $DB->get_record('user_info_field', ['shortname' => 'periodo_ingreso']);
        $piId = $piField ? $piField->id : 0;
        
        $docField = $DB->get_record('user_info_field', ['shortname' => 'documentnumber']);
        $docId = $docField ? $docField->id : 0;
        
        // Diagnostic counts
        $totalStudents = $DB->count_records('local_learning_users', ['userrolename' => 'student']);

        // Query to find students without Entry Period OR without Academic Period
        // Now including documentnumber
        $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, 
                       uid_doc.data as documentnumber,
                       uid_pi.data as entry_period,
                       ap.name as academic_period_name
                FROM {user} u
                JOIN {local_learning_users} llu ON llu.userid = u.id AND llu.userrolename = 'student'
                LEFT JOIN {user_info_data} uid_doc ON uid_doc.userid = u.id AND uid_doc.fieldid = $docId
                LEFT JOIN {user_info_data} uid_pi ON uid_pi.userid = u.id AND uid_pi.fieldid = $piId
                LEFT JOIN {gmk_academic_periods} ap ON ap.id = llu.academicperiodid
                WHERE u.deleted = 0 
                AND (uid_pi.data IS NULL OR uid_pi.data = '' OR llu.academicperiodid IS NULL OR llu.academicperiodid = 0)
                LIMIT 300";
                
        $studentsRaw = $DB->get_records_sql($sql);

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
        if (!$data || !isset($data['students'])) throw new Exception("Datos inválidos.");

        $log = []; $updated = 0;
        $periods = $DB->get_records('gmk_academic_periods', [], '', 'id, name');
        $periodMap = [];
        foreach ($periods as $p) { $periodMap[strtoupper(trim($p->name))] = $p->id; }

        $piField = $DB->get_record('user_info_field', ['shortname' => 'periodo_ingreso']);
        $docField = $DB->get_record('user_info_field', ['shortname' => 'documentnumber']);

        foreach ($data['students'] as $s) {
            $docNum = trim($s['documentnumber'] ?? '');
            if (empty($docNum)) continue;

            // Find user by documentnumber
            $userId = $DB->get_field('user_info_data', 'userid', ['fieldid' => $docField->id, 'data' => $docNum]);
            if (!$userId) {
                // Try to find if the user ID exists as documentnumber in the column if data is ID-like
                $log[] = "Aviso: Alumno con Documento $docNum no encontrado.";
                continue;
            }

            if (!empty($s['entry_period']) && $piField) {
                $existingPi = $DB->get_record('user_info_data', ['userid' => $userId, 'fieldid' => $piField->id]);
                if ($existingPi) {
                    $existingPi->data = trim($s['entry_period']);
                    $DB->update_record('user_info_data', $existingPi);
                } else {
                    $newPi = new stdClass();
                    $newPi->userid = $userId;
                    $newPi->fieldid = $piField->id;
                    $newPi->data = trim($s['entry_period']);
                    $newPi->dataformat = 0;
                    $DB->insert_record('user_info_data', $newPi);
                }
            }

            if (!empty($s['academic_period']) && isset($periodMap[strtoupper(trim($s['academic_period']))])) {
                $apId = $periodMap[strtoupper(trim($s['academic_period']))];
                $subscriptions = $DB->get_records('local_learning_users', ['userid' => $userId, 'userrolename' => 'student'], 'id DESC');
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
                <h1 class="text-2xl font-bold text-slate-800 tracking-tight">Limpieza de Datos de Estudiantes</h1>
                <p class="text-slate-500 text-sm italic">Filtro aplicado: Solo usuarios con rol 'student'.</p>
            </div>
            <div class="flex gap-2">
                 <button @click="exportGaps" :disabled="loading || !gapStudents.length" class="bg-white border border-slate-300 hover:bg-slate-50 text-slate-600 px-4 py-2 rounded-lg text-sm font-bold flex items-center transition-all disabled:opacity-50 shadow-sm">
                    Exportar Gaps (Excel)
                </button>
                 <button @click="loadGaps" :disabled="loading" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-bold flex items-center transition-all disabled:opacity-50 shadow-md">
                    {{ loading ? 'Cargando...' : 'Actualizar Lista' }}
                </button>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 border-l-4 border-l-amber-500">
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.1em] mb-1">Sin Periodo de Ingreso</p>
                <div class="text-4xl font-extrabold text-slate-800">{{ missingPiCount }}</div>
            </div>
            <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 border-l-4 border-l-red-500">
                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.1em] mb-1">Sin Periodo Lectivo</p>
                <div class="text-4xl font-extrabold text-slate-800">{{ missingApCount }}</div>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="bg-slate-50 px-8 py-5 border-b border-slate-200 space-y-1">
                <h2 class="font-bold text-slate-700 text-lg">Carga de Correcciones (Excel)</h2>
                <p class="text-xs text-slate-500 font-medium">Columnas necesarias: <b class="text-slate-800 underline">documentnumber, entry_period, academic_period</b></p>
            </div>
            <div class="p-10 flex flex-col items-center justify-center border-2 border-dashed border-slate-200 m-8 rounded-3xl bg-slate-50/50">
                 <input type="file" ref="fileInput" @change="handleFileUpload" accept=".xlsx, .xls" class="hidden">
                 <div v-if="!xlsxData.length" class="text-center">
                     <button @click="$refs.fileInput.click()" class="bg-white border border-slate-300 px-10 py-5 rounded-2xl shadow-lg hover:shadow-xl hover:bg-slate-100 transition-all font-bold text-slate-600 flex items-center gap-2 group">
                         <span class="group-hover:translate-y-[-2px] transition-transform text-xl">↑</span>
                         <span>Subir Archivo Excel</span>
                     </button>
                 </div>
                 <div v-else class="w-full space-y-4">
                     <div class="flex justify-between items-center bg-white p-5 rounded-2xl shadow-sm border border-slate-100">
                         <span class="text-sm font-bold text-green-600 flex items-center gap-3">
                             <span class="w-3 h-3 bg-green-500 rounded-full animate-ping"></span>
                             {{ xlsxData.length }} registros listos para actualizar
                         </span>
                         <div class="flex gap-3">
                             <button @click="processUpdate" :disabled="syncing" class="bg-green-600 hover:bg-green-700 text-white px-8 py-3 rounded-2xl font-bold text-sm shadow-md transition-all disabled:opacity-50">
                                 {{ syncing ? 'Ejecutando...' : 'Confirmar y Actualizar' }}
                             </button>
                             <button @click="xlsxData = []" class="bg-white border border-slate-200 text-slate-400 px-6 py-3 rounded-2xl text-sm hover:text-slate-600 transition-all">Cancelar</button>
                         </div>
                     </div>
                     <div v-if="resultsLog.length" class="bg-slate-900 text-slate-300 p-8 rounded-2xl text-[11px] font-mono max-h-64 overflow-y-auto leading-relaxed border border-slate-950 shadow-2xl">
                         <div v-for="log in resultsLog" :key="log" class="mb-1.5 pb-1 border-b border-white/5">
                            <span class="text-slate-600">[{{ (new Date()).toLocaleTimeString() }}]</span> {{ log }}
                         </div>
                     </div>
                 </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-8 py-5 border-b border-slate-200 bg-slate-50 flex justify-between items-center">
                <h2 class="font-bold text-slate-700">Listado de Gaps Detectados (Muestra)</h2>
                <span class="text-[10px] text-slate-400 font-mono tracking-widest" v-if="debugInfo">Total Student Subs: {{ debugInfo.total_students_found }}</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-[11px] text-slate-400 uppercase bg-slate-50/50 border-b border-slate-100 tracking-wider">
                        <tr>
                            <th class="px-8 py-5">Nombre Alumno</th>
                            <th class="px-8 py-5">Documento</th>
                            <th class="px-8 py-5 text-center">P. Ingreso</th>
                            <th class="px-8 py-5 text-center">P. Lectivo</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-50">
                        <tr v-for="stu in gapStudents" :key="stu.id" class="hover:bg-slate-50 transition-colors">
                            <td class="px-8 py-5 font-bold text-slate-700 italic underline decoration-slate-200">{{ stu.firstname }} {{ stu.lastname }}</td>
                            <td class="px-8 py-5 text-slate-600 font-mono text-xs">{{ stu.documentnumber || '--' }}</td>
                            <td class="px-8 py-5 text-center">
                                <span v-if="!stu.entry_period" class="bg-amber-100 text-amber-700 px-3 py-1 rounded-full text-[9px] font-black uppercase">Vacío</span>
                                <span v-else class="text-slate-600 font-medium">{{ stu.entry_period }}</span>
                            </td>
                            <td class="px-8 py-5 text-center">
                                <span v-if="!stu.academic_period_name" class="bg-red-100 text-red-700 px-3 py-1 rounded-full text-[9px] font-black uppercase">Sin Asignar</span>
                                <span v-else class="text-slate-600 font-medium">{{ stu.academic_period_name }}</span>
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
                if (response.data.status === 'success') {
                    this.gapStudents = response.data.students || [];
                    this.missingPiCount = response.data.piCount || 0;
                    this.missingApCount = response.data.apCount || 0;
                    this.debugInfo = response.data.debug;
                }
            } catch (e) { console.error(e); }
            finally { this.loading = false; }
        },
        exportGaps() {
            const data = this.gapStudents.map(s => ({
                documentnumber: s.documentnumber || '',
                entry_period: s.entry_period || '',
                academic_period: s.academic_period_name || '',
                fullname: s.firstname + ' ' + s.lastname
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
            };
            reader.readAsBinaryString(file);
        },
        async processUpdate() {
            this.syncing = true;
            this.resultsLog = ["Analizando lote de registros..."];
            try {
                const response = await axios.post(window.location.href + '?action=update_student_data', {
                    students: this.xlsxData
                });
                if (response.data.status === 'success') {
                    this.resultsLog.push(`✓ Completado: ${response.data.updated} registros procesados.`);
                    if(response.data.log.length) this.resultsLog.push(...response.data.log);
                    this.xlsxData = [];
                    setTimeout(() => this.loadGaps(), 2000);
                } else { this.resultsLog.push("❌ Error: " + response.data.message); }
            } catch (e) { this.resultsLog.push("❌ Error: " + e.message); }
            finally { this.syncing = false; }
        }
    }
}).mount('#app');
</script>

<?php echo $OUTPUT->footer(); 
