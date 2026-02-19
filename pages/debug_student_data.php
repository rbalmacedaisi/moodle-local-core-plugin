<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/debug_student_data.php');
$PAGE->set_context($context);
$PAGE->set_title("Debug: Limpieza de Datos de Estudiantes");
$PAGE->set_heading("Debug: Identificación y Corrección de Gaps");
$PAGE->set_pagelayout('admin');

// AJAX Handlers
if (optional_param('action', '', PARAM_ALPHANUMEXT) === 'update_student_data') {
    header('Content-Type: application/json');
    try {
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        if (!$data || !isset($data['students'])) {
            throw new Exception("Datos inválidos.");
        }

        $log = [];
        $updated = 0;
        
        // Fetch Periods for Mapping Name -> ID
        $periods = $DB->get_records('gmk_academic_periods', [], '', 'id, name');
        $periodMap = [];
        foreach ($periods as $p) {
            $periodMap[strtoupper(trim($p->name))] = $p->id;
        }

        // Fetch user_info_field for 'periodo_ingreso'
        $piField = $DB->get_record('user_info_field', ['shortname' => 'periodo_ingreso']);

        foreach ($data['students'] as $s) {
            $idnumber = trim($s['idnumber']);
            if (empty($idnumber)) continue;

            $user = $DB->get_record('user', ['idnumber' => $idnumber, 'deleted' => 0], 'id');
            if (!$user) {
                $log[] = "Error: Alumno con ID $idnumber no encontrado.";
                continue;
            }

            // 1. Update Entry Period (Custom Field)
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

            // 2. Update Academic Period (Subscription)
            if (!empty($s['academic_period']) && isset($periodMap[strtoupper(trim($s['academic_period']))])) {
                $apId = $periodMap[strtoupper(trim($s['academic_period']))];
                
                // Fetch student subscription (learning_users)
                // Assuming we update the latest or 'student' role subscription
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

echo $OUTPUT->header();
?>

<!-- Tailwind & Vue -->
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
<script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<div id="app" class="p-6 bg-slate-50 min-h-screen font-sans">
    <div class="max-w-6xl mx-auto space-y-6">
        
        <!-- Header -->
        <div class="flex justify-between items-end">
            <div>
                <h1 class="text-2xl font-bold text-slate-800">Limpieza de Datos de Estudiantes</h1>
                <p class="text-slate-500 text-sm">Identifica alumnos sin Periodo de Ingreso o Periodo Lectivo.</p>
            </div>
            <div class="flex gap-2">
                 <button @click="exportGaps" :disabled="loading || !gapStudents.length" class="bg-white border border-slate-300 hover:bg-slate-50 text-slate-600 px-4 py-2 rounded-lg text-sm font-bold flex items-center transition-all disabled:opacity-50">
                    <lucide-icon name="download" class="w-4 h-4 mr-2"></lucide-icon>
                    Exportar Gaps (Excel)
                </button>
                 <button @click="loadGaps" :disabled="loading" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-bold flex items-center transition-all disabled:opacity-50">
                    <span v-if="loading">Cargando...</span>
                    <span v-else>Actualizar Lista</span>
                </button>
            </div>
        </div>

        <!-- summary cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-white p-4 rounded-xl shadow-sm border border-slate-200 border-l-4 border-l-amber-500">
                <p class="text-xs font-bold text-slate-400 uppercase">Sin Periodo de Ingreso</p>
                <div class="text-3xl font-bold text-slate-800">{{ missingPiCount }}</div>
            </div>
            <div class="bg-white p-4 rounded-xl shadow-sm border border-slate-200 border-l-4 border-l-red-500">
                <p class="text-xs font-bold text-slate-400 uppercase">Sin Periodo Lectivo</p>
                <div class="text-3xl font-bold text-slate-800">{{ missingApCount }}</div>
            </div>
        </div>

        <!-- Bulk Tool -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="bg-slate-50 px-6 py-4 border-b border-slate-200 space-y-2">
                <h2 class="font-bold text-slate-700">Actualización Masiva (Excel)</h2>
                <p class="text-xs text-slate-500">Suba un archivo XLSX con columnas: <b class="text-slate-700 whitespace-nowrap">idnumber, entry_period, academic_period</b> (Los nombres de columna deben ser exactos).</p>
            </div>
            <div class="p-6 flex flex-col items-center justify-center border-2 border-dashed border-slate-200 m-6 rounded-xl bg-slate-50/50">
                 <input type="file" ref="fileInput" @change="handleFileUpload" accept=".xlsx, .xls" class="hidden">
                 <div v-if="!xlsxData.length" class="text-center">
                     <button @click="$refs.fileInput.click()" class="bg-white border border-slate-300 px-6 py-3 rounded-xl shadow-sm hover:bg-slate-50 transition-all font-bold text-slate-600">
                         <lucide-icon name="upload" class="inline w-5 h-5 mr-2"></lucide-icon>
                         Seleccionar Archivo Excel
                     </button>
                 </div>
                 <div v-else class="w-full space-y-4">
                     <div class="flex justify-between items-center px-2">
                         <span class="text-sm font-bold text-green-600 flex items-center gap-2">
                             <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                             {{ xlsxData.length }} registros cargados
                         </span>
                         <div class="flex gap-2">
                             <button @click="processUpdate" :disabled="syncing" class="bg-green-600 hover:bg-green-800 text-white px-6 py-2 rounded-lg font-bold text-sm transition-all disabled:opacity-50">
                                 {{ syncing ? 'Procesando...' : 'Ejecutar Actualización' }}
                             </button>
                             <button @click="xlsxData = []" class="text-slate-400 hover:text-slate-600 text-sm">Cancelar</button>
                         </div>
                     </div>
                     <div v-if="resultsLog.length" class="bg-slate-900 text-slate-200 p-4 rounded-lg text-xs font-mono max-h-40 overflow-y-auto">
                         <div v-for="log in resultsLog" :key="log">{{ log }}</div>
                     </div>
                 </div>
            </div>
        </div>

        <!-- Gaps Table -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-200 bg-slate-50">
                <h2 class="font-bold text-slate-700">Listado de Estudiantes con Gaps (Top 100)</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-xs text-slate-500 uppercase bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="px-6 py-3">Nombre</th>
                            <th class="px-6 py-3">ID Number</th>
                            <th class="px-6 py-3 text-center">P. Ingreso</th>
                            <th class="px-6 py-3 text-center">P. Lectivo</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="stu in gapStudents" :key="stu.id" class="hover:bg-slate-50 transition-colors">
                            <td class="px-6 py-3 font-medium text-slate-700">{{ stu.firstname }} {{ stu.lastname }}</td>
                            <td class="px-6 py-3 text-slate-500 font-mono">{{ stu.idnumber }}</td>
                            <td class="px-6 py-3 text-center">
                                <span v-if="!stu.entry_period" class="px-2 py-1 bg-amber-50 text-amber-700 rounded text-[10px] font-bold uppercase border border-amber-100">Sin Definir</span>
                                <span v-else class="text-slate-600">{{ stu.entry_period }}</span>
                            </td>
                            <td class="px-6 py-3 text-center">
                                <span v-if="!stu.academic_period_name" class="px-2 py-1 bg-red-50 text-red-700 rounded text-[10px] font-bold uppercase border border-red-100">Sin Definir</span>
                                <span v-else class="text-slate-600">{{ stu.academic_period_name }}</span>
                            </td>
                        </tr>
                        <tr v-if="!gapStudents.length && !loading">
                            <td colspan="4" class="px-6 py-10 text-center text-slate-400 italic">No se encontraron estudiantes con Gaps de datos.</td>
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
            resultsLog: []
        }
    },
    mounted() {
        this.loadGaps();
    },
    methods: {
        async loadGaps() {
            this.loading = true;
            try {
                // We'll fetch the gaps using a small inline query to keep it contained
                const response = await axios.get(window.location.href + '?action=get_gaps');
                if (response.data.status === 'success') {
                    this.gapStudents = response.data.students;
                    this.missingPiCount = response.data.piCount;
                    this.missingApCount = response.data.apCount;
                }
            } catch (e) {
                console.error(e);
            } finally {
                this.loading = false;
            }
        },
        exportGaps() {
            if (!this.gapStudents.length) return;
            
            // Map to the required format for Excel
            const dataToExport = this.gapStudents.map(s => ({
                idnumber: s.idnumber,
                entry_period: s.entry_period || '',
                academic_period: s.academic_period_name || '',
                fullname: s.firstname + ' ' + s.lastname // Helper column, ignored by importer
            }));
            
            const ws = XLSX.utils.json_to_sheet(dataToExport);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Estudiantes con Gaps");
            
            // Set column widths
            ws['!cols'] = [
                { wch: 15 }, // idnumber
                { wch: 15 }, // entry_period
                { wch: 20 }, // academic_period
                { wch: 30 }  // fullname
            ];

            XLSX.writeFile(wb, `gaps_estudiantes_${new Date().toISOString().slice(0,10)}.xlsx`);
        },
        handleFileUpload(e) {
            const file = e.target.files[0];
            const reader = new FileReader();
            reader.onload = (evt) => {
                const bstr = evt.target.result;
                const wb = XLSX.read(bstr, { type: 'binary' });
                const wsname = wb.SheetNames[0];
                const ws = wb.Sheets[wsname];
                const data = XLSX.utils.sheet_to_json(ws);
                this.xlsxData = data;
            };
            reader.readAsBinaryString(file);
        },
        async processUpdate() {
            if (!confirm(`¿Desea procesar la actualización de ${this.xlsxData.length} registros?`)) return;
            this.syncing = true;
            this.resultsLog = ["Enviando datos..."];
            try {
                const response = await axios.post(window.location.href + '?action=update_student_data', {
                    students: this.xlsxData
                });
                if (response.data.status === 'success') {
                    this.resultsLog = [
                        `¡Completado! Registros procesados: ${response.data.updated}`,
                        ...response.data.log
                    ];
                    this.xlsxData = [];
                    this.loadGaps();
                } else {
                    this.resultsLog.push("Error: " + response.data.message);
                }
            } catch (e) {
                this.resultsLog.push("Error fatal: " + e.message);
            } finally {
                this.syncing = false;
            }
        }
    }
}).mount('#app');
</script>

<?php
// Add the gap fetching logic at the bottom of the page to be called by AJAX
if (optional_param('action', '', PARAM_ALPHANUMEXT) === 'get_gaps') {
    ob_clean();
    header('Content-Type: application/json');
    
    try {
        // 1. Students missing Periodo de Ingreso
        $piField = $DB->get_record('user_info_field', ['shortname' => 'periodo_ingreso']);
        
        // Diagnostic: Check if we have any students at all
        $totalStudents = $DB->count_records('local_learning_users', ['userrolename' => 'student']);
        $totalActiveUsers = $DB->count_records_sql("SELECT COUNT(*) FROM {user} WHERE deleted = 0 AND suspended = 0");

        // Query to find students without Entry Period OR without Academic Period in their subscription
        // We strictly filter by userrolename = 'student' to satisfy user request to ignore teachers
        $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname, u.idnumber, 
                       uid_pi.data as entry_period,
                       ap.name as academic_period_name
                FROM {user} u
                JOIN {local_learning_users} llu ON llu.userid = u.id AND llu.userrolename = 'student'
                LEFT JOIN {user_info_data} uid_pi ON uid_pi.userid = u.id AND uid_pi.fieldid = " . ($piField ? $piField->id : 0) . "
                LEFT JOIN {gmk_academic_periods} ap ON ap.id = llu.academicperiodid
                WHERE u.deleted = 0 
                AND (uid_pi.data IS NULL OR uid_pi.data = '' OR llu.academicperiodid IS NULL OR llu.academicperiodid = 0)
                LIMIT 200";
                
        $studentsRaw = $DB->get_records_sql($sql);

        // Get counts
        $piCount = $DB->count_records_sql("
            SELECT COUNT(DISTINCT u.id)
            FROM {user} u
            JOIN {local_learning_users} llu ON llu.userid = u.id AND llu.userrolename = 'student'
            LEFT JOIN {user_info_data} uid_pi ON uid_pi.userid = u.id AND uid_pi.fieldid = " . ($piField ? $piField->id : 0) . "
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
                'piField_found' => ($piField ? true : false),
                'total_student_subscriptions' => (int)$totalStudents,
                'total_active_users' => (int)$totalActiveUsers
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

echo $OUTPUT->footer();
