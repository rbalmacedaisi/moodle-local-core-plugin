<?php
// =============================================================================
// IMPORTACIÓN MASIVA DE NOTAS - MIGRACIÓN DE DATOS
// =============================================================================
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/vendor/autoload.php');

use PhpOffice\PhpSpreadsheet\IOFactory;

admin_externalpage_setup('grupomakro_core_import_grades');
require_login();
require_capability('moodle/site:config', context_system::instance());

global $DB, $USER;

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

/**
 * Normalize text for fuzzy matching (remove accents, lowercase, trim).
 */
function php_normalize_field($str) {
    if (empty($str)) return '';
    $str = trim($str);
    $unwanted = ['Š'=>'S','š'=>'s','Ž'=>'Z','ž'=>'z','À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','Å'=>'A','Æ'=>'A','Ç'=>'C','È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E','Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I','Ñ'=>'N','Ò'=>'O','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','Ø'=>'O','Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U','Ý'=>'Y','Þ'=>'B','ß'=>'Ss','à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','æ'=>'a','ç'=>'c','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ì'=>'i','í'=>'i','î'=>'i','ï'=>'i','ð'=>'o','ñ'=>'n','ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o','ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ý'=>'y','þ'=>'b','ÿ'=>'y'];
    $str = strtr($str, $unwanted);
    return strtolower($str);
}

/**
 * Map Estado Curso text to gmk_course_progre status codes.
 */
function map_course_status_to_code($statusText) {
    $normalized = php_normalize_field($statusText);

    $map = [
        'no disponible' => 0,
        'disponible' => 1,
        'cursando' => 2,           // En Curso
        'completado' => 3,
        'aprobada' => 4,           // Aprobado/Aprobada
        'reprobada' => 5,          // Reprobado/Reprobada
        'migracion pendiente' => 99  // NEW STATUS - for migration process
    ];

    foreach ($map as $key => $value) {
        if (strpos($normalized, $key) !== false) {
            return $value;
        }
    }

    return null; // Unknown status
}

// =============================================================================
// AJAX HANDLERS
// =============================================================================

$action = optional_param('action', '', PARAM_TEXT);

// Get current course progress records for diff highlighting
if ($action === 'get_current_state') {
    header('Content-Type: application/json');

    $userid = optional_param('userid', 0, PARAM_INT);
    $courseid = optional_param('courseid', 0, PARAM_INT);

    $record = $DB->get_record('gmk_course_progre', [
        'userid' => $userid,
        'courseid' => $courseid
    ]);

    if ($record) {
        echo json_encode([
            'status' => 'success',
            'data' => [
                'grade' => $record->grade,
                'status' => $record->status,
                'periodid' => $record->periodid,
                'classid' => $record->classid
            ]
        ]);
    } else {
        echo json_encode(['status' => 'not_found']);
    }
    exit;
}

// Process individual grade import
if ($action === 'ajax_import_grade') {
    header('Content-Type: application/json');

    try {
        $data = json_decode(file_get_contents('php://input'), true);

        // Extract fields from request
        $identificacion = $data['identificacion'] ?? '';
        $curso_shortname = $data['curso_shortname'] ?? '';
        $nota = $data['nota'] ?? null;
        $estado_curso = $data['estado_curso'] ?? '';
        $carrera = $data['carrera'] ?? '';
        $cuatrimestre = $data['cuatrimestre'] ?? '';
        $feedback = $data['feedback'] ?? '';

        // Find user by username (identificacion/cedula)
        $user = $DB->get_record('user', ['username' => $identificacion, 'deleted' => 0]);
        if (!$user) {
            echo json_encode([
                'status' => 'error',
                'message' => "Usuario no encontrado con identificación: $identificacion"
            ]);
            exit;
        }

        // Find course by shortname or fullname
        $course = $DB->get_record('course', ['shortname' => $curso_shortname]);
        if (!$course) {
            $course = $DB->get_record('course', ['fullname' => $curso_shortname]);
        }

        // Fallback: Normalized search
        if (!$course) {
            $normalized_search = php_normalize_field($curso_shortname);
            $courses = $DB->get_records('course', null, '', 'id, shortname, fullname');
            foreach ($courses as $c) {
                if (php_normalize_field($c->fullname) === $normalized_search || 
                    php_normalize_field($c->shortname) === $normalized_search) {
                    $course = $c;
                    break;
                }
            }
        }

        if (!$course) {
            echo json_encode([
                'status' => 'error',
                'message' => "Curso no encontrado con shortname o nombre: $curso_shortname"
            ]);
            exit;
        }

        // Find learning plan by name
        $plan = null;
        if (!empty($carrera)) {
            $plans = $DB->get_records('local_learning_plans', null, '', 'id, name');
            foreach ($plans as $p) {
                if (php_normalize_field($p->name) === php_normalize_field($carrera)) {
                    $plan = $p;
                    break;
                }
            }
        }

        if (!$plan) {
            echo json_encode([
                'status' => 'error',
                'message' => "Plan de aprendizaje no encontrado: $carrera"
            ]);
            exit;
        }

        // Map status
        $status_code = map_course_status_to_code($estado_curso);
        if ($status_code === null) {
            echo json_encode([
                'status' => 'error',
                'message' => "Estado de curso desconocido: $estado_curso"
            ]);
            exit;
        }

        // Find period by name within the learning plan
        $period = null;
        if (!empty($cuatrimestre)) {
            $periods = $DB->get_records('local_learning_periods', ['learningplanid' => $plan->id], '', 'id, name');
            foreach ($periods as $per) {
                if (php_normalize_field($per->name) === php_normalize_field($cuatrimestre)) {
                    $period = $per;
                    break;
                }
            }
        }

        // Fallback: get first period if not found
        if (!$period) {
            $period = $DB->get_record_sql(
                "SELECT id FROM {local_learning_periods} WHERE learningplanid = ? ORDER BY id ASC LIMIT 1",
                [$plan->id]
            );
        }

        // Check if record exists for this specific user + course + learning plan
        $existing = $DB->get_record('gmk_course_progre', [
            'userid' => $user->id,
            'courseid' => $course->id,
            'learningplanid' => $plan->id
        ]);

        $transaction = $DB->start_delegated_transaction();

        if ($existing) {
            // Update existing record
            $existing->grade = $nota;
            $existing->status = $status_code;
            if ($period) {
                $existing->periodid = $period->id;
            }
            $existing->timemodified = time();
            $existing->usermodified = $USER->id;

            $DB->update_record('gmk_course_progre', $existing);
            $action_taken = 'updated';
        } else {
            // Create new record with all required fields
            $record = new stdClass();
            $record->userid = $user->id;
            $record->courseid = $course->id;
            $record->learningplanid = $plan->id;
            $record->grade = $nota;
            $record->status = $status_code;
            $record->periodid = $period ? $period->id : 0;
            $record->periodname = $period ? mb_substr($period->name, 0, 64, 'UTF-8') : 'Unnamed Period';
            $record->classid = 0; // Migration doesn't have classid
            $record->groupid = 0;
            $record->progress = 0;
            $record->coursename = mb_substr($course->fullname, 0, 255, 'UTF-8');
            $record->prerequisites = '[]';
            $record->credits = 0;
            $record->tc = 0;
            $record->practicalhours = 0;
            $record->teoricalhours = 0;
            $record->timecreated = time();
            $record->timemodified = time();
            $record->usermodified = $USER->id;

            $DB->insert_record('gmk_course_progre', $record);
            $action_taken = 'created';
        }

        // Save feedback to Moodle gradebook if provided
        if (!empty($feedback) || !empty($nota)) {
            require_once($CFG->libdir . '/gradelib.php');

            // Get the course grade item (final grade)
            $grade_item = grade_item::fetch([
                'courseid' => $course->id,
                'itemtype' => 'course'
            ]);

            if ($grade_item) {
                // FIRST: Try to fetch existing grade_grade record
                $grade_grade = grade_grade::fetch([
                    'itemid' => $grade_item->id,
                    'userid' => $user->id
                ]);

                if ($grade_grade) {
                    // UPDATE existing record
                    if (!empty($feedback)) {
                        $grade_grade->feedback = $feedback;
                        $grade_grade->feedbackformat = FORMAT_PLAIN;
                    }
                    if (!empty($nota)) {
                        $grade_grade->finalgrade = $nota;
                        $grade_grade->rawgrade = $nota;
                    }
                    $grade_grade->update('import');
                } else {
                    // CREATE new record
                    $grade_grade = new grade_grade();
                    $grade_grade->itemid = $grade_item->id;
                    $grade_grade->userid = $user->id;
                    $grade_grade->rawgrademax = $grade_item->grademax;
                    $grade_grade->rawgrademin = $grade_item->grademin;
                    if (!empty($nota)) {
                        $grade_grade->finalgrade = $nota;
                        $grade_grade->rawgrade = $nota;
                    }
                    if (!empty($feedback)) {
                        $grade_grade->feedback = $feedback;
                        $grade_grade->feedbackformat = FORMAT_PLAIN;
                    }
                    $grade_grade->insert('import');
                }
            }
        }

        $transaction->allow_commit();

        echo json_encode([
            'status' => 'success',
            'action' => $action_taken,
            'message' => "Registro $action_taken correctamente",
            'details' => [
                'user' => $user->username,
                'course' => $course->shortname,
                'plan' => $plan->name,
                'grade' => $nota,
                'status' => $status_code,
                'feedback' => $feedback
            ]
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
    exit;
}

// =============================================================================
// HTML OUTPUT
// =============================================================================

$PAGE->set_url('/local/grupomakro_core/pages/import_grades.php');
$PAGE->set_title('Importación Masiva de Notas');
$PAGE->set_heading('Migración de Notas - Sistema Académico');

echo $OUTPUT->header();
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <script src="https://unpkg.com/vue@3.3.4/dist/vue.global.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://unpkg.com/lucide@latest"></script>

    <style>
        [v-cloak] { display: none; }
        .diff-highlight { background-color: #fef3c7; font-weight: bold; }
        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        .status-success { background-color: #d4edda; color: #155724; }
        .status-error { background-color: #f8d7da; color: #721c24; }
        .status-warning { background-color: #fff3cd; color: #856404; }
        .status-info { background-color: #d1ecf1; color: #0c5460; }
    </style>
</head>

<body class="bg-gray-50">
    <div id="app" v-cloak class="container mx-auto px-4 py-6 max-w-7xl">
        <!-- Header -->
        <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800 mb-2">📊 Importación Masiva de Notas</h1>
                    <p class="text-gray-600">Migración de datos históricos del sistema Q10 a Moodle</p>
                </div>
                <div>
                    <a href="<?php echo $CFG->wwwroot; ?>/local/grupomakro_core/pages/grade_report.php"
                       class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                        <i data-lucide="list" class="w-4 h-4 mr-2"></i>
                        Ver Reporte
                    </a>
                </div>
            </div>
        </div>

        <!-- Instructions -->
        <div v-if="state === 'idle'" class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-6 rounded">
            <div class="flex items-start">
                <i data-lucide="info" class="w-5 h-5 text-blue-600 mr-3 mt-0.5"></i>
                <div>
                    <h3 class="font-semibold text-blue-800 mb-2">Formato del Archivo Excel (.xlsx)</h3>
                    <p class="text-blue-700 text-sm mb-2">El archivo debe contener las siguientes columnas:</p>
                    <ul class="text-blue-700 text-sm list-disc ml-5 space-y-1">
                        <li><strong>ID Moodle:</strong> ID interno del usuario (opcional)</li>
                        <li><strong>Nombre Completo:</strong> Nombre del estudiante</li>
                        <li><strong>Email:</strong> Correo electrónico</li>
                        <li><strong>Identificación:</strong> Cédula del estudiante (username) <span class="text-red-600">*REQUERIDO</span></li>
                        <li><strong>Carrera:</strong> Nombre del plan de aprendizaje</li>
                        <li><strong>Cuatrimestre:</strong> Nivel académico</li>
                        <li><strong>Curso:</strong> Shortname del curso <span class="text-red-600">*REQUERIDO</span></li>
                        <li><strong>Nota:</strong> Calificación numérica</li>
                        <li><strong>Estado Estudiante:</strong> Estado del estudiante (activo, suspendido, etc.)</li>
                        <li><strong>Estado Financiero:</strong> Estado de pago</li>
                        <li><strong>Estado Curso:</strong> No Disponible, Disponible, Cursando, Completado, Aprobada, Reprobada, <strong>Migración Pendiente</strong></li>
                        <li><strong>Feedback:</strong> Observación/comentario sobre la calificación (opcional)</li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Upload Section -->
        <div v-if="state === 'idle'" class="bg-white rounded-lg shadow-sm p-6 mb-6">
            <h2 class="text-lg font-semibold mb-4 text-gray-800">📤 Cargar Archivo Excel</h2>

            <div class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center">
                <input
                    type="file"
                    ref="fileInput"
                    @change="handleFileUpload"
                    accept=".xlsx,.xls"
                    class="hidden"
                >

                <div v-if="!fileName">
                    <i data-lucide="upload-cloud" class="w-16 h-16 text-gray-400 mx-auto mb-4"></i>
                    <p class="text-gray-600 mb-2">Arrastra un archivo o haz clic para seleccionar</p>
                    <button
                        @click="$refs.fileInput.click()"
                        class="px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition"
                    >
                        Seleccionar Archivo Excel
                    </button>
                </div>

                <div v-else>
                    <i data-lucide="file-spreadsheet" class="w-16 h-16 text-green-600 mx-auto mb-4"></i>
                    <p class="font-semibold text-gray-800">{{ fileName }}</p>
                    <p class="text-sm text-gray-600 mt-2">{{ rows.length }} registros encontrados</p>
                    <div class="mt-4 space-x-3">
                        <button
                            @click="state = 'preview'"
                            class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition"
                        >
                            Vista Previa
                        </button>
                        <button
                            @click="resetUpload"
                            class="px-6 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition"
                        >
                            Cancelar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Preview Section -->
        <div v-if="state === 'preview'" class="bg-white rounded-lg shadow-sm p-6 mb-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-semibold text-gray-800">👀 Vista Previa: {{ rows.length }} Registros</h2>
                <div class="space-x-3">
                    <button
                        @click="startProcessing"
                        class="px-6 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition"
                    >
                        <i data-lucide="play" class="w-4 h-4 inline mr-2"></i>
                        Procesar Importación
                    </button>
                    <button
                        @click="state = 'idle'"
                        class="px-6 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400 transition"
                    >
                        Volver
                    </button>
                </div>
            </div>

            <div class="overflow-x-auto max-h-96 overflow-y-auto border rounded">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 sticky top-0">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Identificación</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Nombre</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Carrera</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Curso</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Nota</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Estado</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Feedback</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <tr v-for="(row, idx) in rows" :key="idx" class="hover:bg-gray-50">
                            <td class="px-3 py-2 text-gray-500">{{ idx + 1 }}</td>
                            <td class="px-3 py-2 font-mono text-sm">{{ row.identificacion }}</td>
                            <td class="px-3 py-2">{{ row.nombre_completo }}</td>
                            <td class="px-3 py-2 text-sm">{{ row.carrera }}</td>
                            <td class="px-3 py-2 font-mono text-sm">{{ row.curso_shortname }}</td>
                            <td class="px-3 py-2 text-center font-semibold">{{ row.nota }}</td>
                            <td class="px-3 py-2">
                                <span :class="getStatusClass(row.estado_curso)">
                                    {{ row.estado_curso }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-sm text-gray-600">{{ row.feedback || '-' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Processing Section -->
        <div v-if="state === 'processing'" class="bg-white rounded-lg shadow-sm p-6 mb-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-lg font-semibold text-gray-800">⚙️ Procesando Importación...</h2>
                <button
                    v-if="!isFinished && !isCancelled"
                    @click="cancelProcessing"
                    class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition"
                >
                    <i data-lucide="x-circle" class="w-4 h-4 inline mr-2"></i>
                    Cancelar
                </button>
                <button
                    v-if="isFinished || isCancelled"
                    @click="resetToIdle"
                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition"
                >
                    <i data-lucide="refresh-cw" class="w-4 h-4 inline mr-2"></i>
                    Nueva Importación
                </button>
            </div>

            <!-- Progress Bar -->
            <div class="mb-6">
                <div class="flex justify-between mb-2">
                    <span class="text-sm font-medium text-gray-700">Progreso: {{ processedCount }} / {{ rows.length }}</span>
                    <span class="text-sm font-medium text-gray-700">{{ progressPercentage }}%</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-4">
                    <div
                        class="h-4 rounded-full transition-all duration-300"
                        :class="isFinished ? 'bg-green-600' : isCancelled ? 'bg-red-600' : 'bg-blue-600'"
                        :style="{ width: progressPercentage + '%' }"
                    ></div>
                </div>
            </div>

            <!-- Status Message -->
            <div v-if="isFinished" class="bg-green-50 border-l-4 border-green-500 p-4 mb-4">
                <div class="flex items-center">
                    <i data-lucide="check-circle" class="w-5 h-5 text-green-600 mr-3"></i>
                    <p class="text-green-800 font-semibold">✅ Importación Completada</p>
                </div>
            </div>

            <div v-if="isCancelled" class="bg-red-50 border-l-4 border-red-500 p-4 mb-4">
                <div class="flex items-center">
                    <i data-lucide="alert-circle" class="w-5 h-5 text-red-600 mr-3"></i>
                    <p class="text-red-800 font-semibold">⛔ Proceso Cancelado</p>
                </div>
            </div>

            <!-- Logs -->
            <div class="bg-gray-50 rounded border p-4 max-h-64 overflow-y-auto">
                <h3 class="font-semibold text-gray-700 mb-2">📋 Registro de Actividad</h3>
                <div class="space-y-1 text-sm font-mono">
                    <div v-for="(log, idx) in logs" :key="idx" :class="getLogClass(log)">
                        {{ log.message }}
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
                fileName: '',
                rows: [],
                processedCount: 0,
                logs: [],
                isFinished: false,
                isCancelled: false,
                originalData: {}
            };
        },
        computed: {
            progressPercentage() {
                if (this.rows.length === 0) return 0;
                return Math.round((this.processedCount / this.rows.length) * 100);
            }
        },
        methods: {
            handleFileUpload(event) {
                const file = event.target.files[0];
                if (!file) return;

                this.fileName = file.name;
                const reader = new FileReader();

                reader.onload = (e) => {
                    try {
                        const data = new Uint8Array(e.target.result);
                        const workbook = XLSX.read(data, { type: 'array' });
                        const firstSheet = workbook.Sheets[workbook.SheetNames[0]];
                        const jsonData = XLSX.utils.sheet_to_json(firstSheet, { header: 1 });

                        // Parse rows (skip header)
                        this.rows = [];
                        for (let i = 1; i < jsonData.length; i++) {
                            const row = jsonData[i];
                            if (!row[3] || !row[6]) continue; // Skip if no identificacion or curso

                            this.rows.push({
                                id_moodle: row[0] || '',
                                nombre_completo: row[1] || '',
                                email: row[2] || '',
                                identificacion: String(row[3] || '').trim(),
                                carrera: row[4] || '',
                                cuatrimestre: row[5] || '',
                                curso_shortname: String(row[6] || '').trim(),
                                nota: row[7] || '',
                                estado_estudiante: row[8] || '',
                                estado_financiero: row[9] || '',
                                estado_curso: row[10] || '',
                                feedback: row[11] || ''
                            });
                        }

                        if (this.rows.length === 0) {
                            alert('No se encontraron registros válidos en el archivo');
                            this.resetUpload();
                        }

                    } catch (err) {
                        alert('Error al leer el archivo: ' + err.message);
                        this.resetUpload();
                    }
                };

                reader.readAsArrayBuffer(file);
            },

            resetUpload() {
                this.fileName = '';
                this.rows = [];
                this.$refs.fileInput.value = '';
            },

            resetToIdle() {
                this.state = 'idle';
                this.fileName = '';
                this.rows = [];
                this.processedCount = 0;
                this.logs = [];
                this.isFinished = false;
                this.isCancelled = false;
                this.$refs.fileInput.value = '';
            },

            startProcessing() {
                this.state = 'processing';
                this.processedCount = 0;
                this.logs = [];
                this.isFinished = false;
                this.isCancelled = false;

                this.addLog('info', '🚀 Iniciando importación de ' + this.rows.length + ' registros...');
                this.processNextRow(0);
            },

            async processNextRow(index) {
                if (this.isCancelled) {
                    this.addLog('warning', '⛔ Proceso cancelado por el usuario');
                    return;
                }

                if (index >= this.rows.length) {
                    this.isFinished = true;
                    this.addLog('success', '✅ Importación finalizada: ' + this.processedCount + ' registros procesados');
                    return;
                }

                const row = this.rows[index];

                try {
                    const response = await axios.post('<?php echo $CFG->wwwroot; ?>/local/grupomakro_core/pages/import_grades.php?action=ajax_import_grade', row, {
                        headers: { 'Content-Type': 'application/json' }
                    });

                    if (response.data.status === 'success') {
                        this.addLog('success', `✅ [${index + 1}] ${row.identificacion} - ${row.curso_shortname}: ${response.data.message}`);
                    } else {
                        this.addLog('error', `❌ [${index + 1}] ${row.identificacion} - ${row.curso_shortname}: ${response.data.message}`);
                    }

                } catch (err) {
                    this.addLog('error', `❌ [${index + 1}] Error: ${err.message}`);
                }

                this.processedCount++;

                // Continue with next row
                setTimeout(() => this.processNextRow(index + 1), 100);
            },

            cancelProcessing() {
                this.isCancelled = true;
            },

            addLog(type, message) {
                this.logs.push({ type, message });
                // Auto-scroll to bottom
                this.$nextTick(() => {
                    const logContainer = document.querySelector('.overflow-y-auto');
                    if (logContainer) {
                        logContainer.scrollTop = logContainer.scrollHeight;
                    }
                });
            },

            getLogClass(log) {
                const baseClass = 'p-1 rounded';
                if (log.type === 'success') return baseClass + ' text-green-700';
                if (log.type === 'error') return baseClass + ' text-red-700 bg-red-50';
                if (log.type === 'warning') return baseClass + ' text-orange-700';
                return baseClass + ' text-gray-700';
            },

            getStatusClass(status) {
                const normalized = (status || '').toLowerCase();
                if (normalized.includes('aprobada') || normalized.includes('aprobado')) return 'status-badge status-success';
                if (normalized.includes('reprobada') || normalized.includes('reprobado')) return 'status-badge status-error';
                if (normalized.includes('cursando') || normalized.includes('en curso')) return 'status-badge status-info';
                if (normalized.includes('completado')) return 'status-badge bg-blue-100 text-blue-700';
                if (normalized.includes('migracion') || normalized.includes('pendiente')) return 'status-badge status-warning';
                if (normalized.includes('disponible')) return 'status-badge bg-green-100 text-green-700';
                if (normalized.includes('no disponible')) return 'status-badge bg-gray-300 text-gray-700';
                return 'status-badge bg-gray-200 text-gray-700';
            }
        },
        mounted() {
            // Initialize Lucide icons
            lucide.createIcons();
        },
        updated() {
            // Re-initialize icons after DOM updates
            lucide.createIcons();
        }
    }).mount('#app');
    </script>
</body>
</html>

<?php
echo $OUTPUT->footer();
