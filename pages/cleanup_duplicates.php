<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

global $DB;

require_login();
require_capability('moodle/site:config', context_system::instance());

$action = optional_param('action', '', PARAM_ALPHA);
$confirm = optional_param('confirm', 0, PARAM_INT);

// Log for debugging - also check $_GET directly
error_log("cleanup_duplicates.php - Action recibido: '$action'");
error_log("cleanup_duplicates.php - \$_GET: " . json_encode($_GET));
error_log("cleanup_duplicates.php - \$_REQUEST: " . json_encode($_REQUEST));

// ========== AJAX HANDLER: FIND DUPLICATES ==========
// Also try with $_GET directly in case optional_param has issues
$action_alt = isset($_GET['action']) ? $_GET['action'] : '';
error_log("Action alternativo desde \$_GET: '$action_alt'");

if ($action === 'find_duplicates' || $action_alt === 'find_duplicates') {
    error_log("Entrando al handler find_duplicates");

    // Clear any output buffers and set JSON header
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');

    try {
        // Find users with multiple local_learning_users records with userrolename='student'
        // Use MIN(id) as first column to ensure uniqueness
        $sql = "SELECT MIN(id) as min_id, userid, COUNT(*) as count
                FROM {local_learning_users}
                WHERE userrolename = 'student'
                GROUP BY userid
                HAVING COUNT(*) > 1";

        $duplicates = $DB->get_records_sql($sql);

        $result = [];
        foreach ($duplicates as $dup) {
            $user = $DB->get_record('user', ['id' => $dup->userid], 'id, username, firstname, lastname');
            if ($user) {
                $records = $DB->get_records('local_learning_users',
                    ['userid' => $dup->userid, 'userrolename' => 'student'],
                    'id ASC');

                $result[] = [
                    'userid' => $dup->userid,
                    'username' => $user->username,
                    'fullname' => $user->firstname . ' ' . $user->lastname,
                    'count' => (int)$dup->count,
                    'records' => array_values($records)
                ];
            }
        }

        error_log("Retornando " . count($result) . " duplicados");
        echo json_encode(['status' => 'success', 'duplicates' => $result]);
        die(); // Use die() instead of exit for safety
    } catch (Exception $e) {
        error_log("Error en find_duplicates: " . $e->getMessage());
        error_log("Stack trace: " . $e->getTraceAsString());
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        die();
    }
}

// ========== AJAX HANDLER: DELETE DUPLICATE ==========
if ($action === 'delete_duplicate' || $action_alt === 'delete_duplicate') {
    error_log("Entrando al handler delete_duplicate");

    // Clear any output buffers and set JSON header
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: application/json');

    try {
        $record_id = required_param('record_id', PARAM_INT);

        $record = $DB->get_record('local_learning_users', ['id' => $record_id]);
        if (!$record) {
            throw new Exception("Registro no encontrado");
        }

        $DB->delete_records('local_learning_users', ['id' => $record_id]);

        error_log("Registro $record_id eliminado exitosamente");
        echo json_encode(['status' => 'success', 'message' => 'Registro eliminado']);
        die();
    } catch (Exception $e) {
        error_log("Error en delete_duplicate: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        die();
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
</style>

<div id="app" v-cloak class="bg-slate-50 min-h-screen p-6">
    <div class="max-w-6xl mx-auto">

        <!-- Header -->
        <header class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 mb-6">
            <h1 class="text-3xl font-bold text-slate-900 mb-2">🧹 Limpiar Registros Duplicados</h1>
            <p class="text-slate-600">Encuentra y elimina registros duplicados en local_learning_users</p>
        </header>

        <!-- Loading Indicator -->
        <div v-if="loading" class="bg-white p-8 rounded-2xl shadow-sm border border-slate-200 mb-6 text-center">
            <div class="inline-block animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mb-4"></div>
            <p class="text-slate-600 font-medium">🔍 Buscando registros duplicados...</p>
        </div>

        <!-- Results -->
        <div v-if="duplicates.length > 0" class="space-y-4">
            <div class="bg-amber-50 border-2 border-amber-500 p-4 rounded-xl">
                <p class="font-bold text-amber-900">⚠️ Se encontraron {{ duplicates.length }} usuarios con registros duplicados</p>
            </div>

            <div v-for="dup in duplicates" :key="dup.userid" class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
                <h3 class="font-bold text-lg mb-2">{{ dup.fullname }} ({{ dup.username }})</h3>
                <p class="text-sm text-slate-600 mb-4">{{ dup.count }} registros encontrados</p>

                <div class="space-y-2">
                    <div
                        v-for="(record, idx) in dup.records"
                        :key="record.id"
                        class="p-4 border rounded-lg"
                        :class="idx === 0 ? 'bg-green-50 border-green-300' : 'bg-red-50 border-red-300'"
                    >
                        <div class="flex justify-between items-center">
                            <div>
                                <p class="text-sm font-bold">
                                    {{ idx === 0 ? '✅ MANTENER (Más antiguo)' : '❌ DUPLICADO' }}
                                </p>
                                <p class="text-xs text-slate-600">ID: {{ record.id }} | Plan: {{ record.learningplanid }} | Creado: {{ new Date(record.timecreated * 1000).toLocaleString() }}</p>
                            </div>
                            <button
                                v-if="idx > 0"
                                @click="deleteDuplicate(record.id, dup.userid)"
                                class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-bold rounded-lg"
                            >
                                🗑️ Eliminar
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-else-if="!loading && searched" class="bg-green-50 border-2 border-green-500 p-6 rounded-xl text-center">
            <p class="font-bold text-green-900 text-lg">✅ No se encontraron duplicados</p>
        </div>

        <!-- Error Display -->
        <div v-if="errorMessage" class="bg-red-50 border-2 border-red-500 p-6 rounded-xl">
            <h3 class="font-bold text-red-900 text-lg mb-2">❌ Error</h3>
            <div class="bg-white p-4 rounded-lg">
                <p class="text-red-800 font-mono text-sm">{{ errorMessage }}</p>
            </div>
        </div>

    </div>
</div>

<script>
const { createApp } = Vue;

createApp({
    data() {
        return {
            duplicates: [],
            loading: false,
            searched: false,
            errorMessage: null
        }
    },
    mounted() {
        // Auto-load duplicates when page loads
        this.findDuplicates();
    },
    methods: {
        async findDuplicates() {
            this.loading = true;
            this.searched = false;
            this.errorMessage = null;
            try {
                const url = window.location.pathname;
                console.log('Buscando duplicados en:', url);

                const res = await axios.get(url, {
                    params: { action: 'find_duplicates' }
                });

                console.log('Respuesta recibida:', res);

                if (res.data && res.data.status === 'success') {
                    this.duplicates = res.data.duplicates || [];
                    this.searched = true;
                    console.log('Duplicados encontrados:', this.duplicates.length);
                } else {
                    const errorMsg = (res.data && res.data.message) ? res.data.message : 'Error desconocido - sin mensaje del servidor';
                    console.error('Error del servidor:', errorMsg);
                    this.errorMessage = errorMsg;
                }
            } catch (e) {
                console.error('Error completo:', e);
                console.error('Response:', e.response);
                const errorDetail = e.response ?
                    `Status: ${e.response.status} - ${e.response.statusText}\nData: ${JSON.stringify(e.response.data)}` :
                    e.message;
                this.errorMessage = 'Error de conexión: ' + errorDetail;
            } finally {
                this.loading = false;
            }
        },
        async deleteDuplicate(recordId, userid) {
            if (!confirm('¿Estás seguro de eliminar este registro duplicado?')) {
                return;
            }

            try {
                const url = window.location.pathname;
                const res = await axios.post(url, null, {
                    params: {
                        action: 'delete_duplicate',
                        record_id: recordId
                    }
                });

                if (res.data && res.data.status === 'success') {
                    alert('Registro eliminado exitosamente');
                    // Refresh the list
                    await this.findDuplicates();
                } else {
                    const errorMsg = (res.data && res.data.message) ? res.data.message : 'Error desconocido';
                    alert('Error: ' + errorMsg);
                }
            } catch (e) {
                console.error('Error completo:', e);
                alert('Error: ' + (e.message || 'Error de conexión'));
            }
        }
    }
}).mount('#app');
</script>

<?php
echo $OUTPUT->footer();
