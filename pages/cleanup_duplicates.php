<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

global $DB;

require_login();
require_capability('moodle/site:config', context_system::instance());

$action = optional_param('action', '', PARAM_ALPHA);
$confirm = optional_param('confirm', 0, PARAM_INT);

// ========== AJAX HANDLER: FIND DUPLICATES ==========
if ($action === 'find_duplicates') {
    header('Content-Type: application/json');
    try {
        // Find users with multiple local_learning_users records with userrolename='student'
        $sql = "SELECT userid, COUNT(*) as count
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
                    'count' => $dup->count,
                    'records' => array_values($records)
                ];
            }
        }

        echo json_encode(['status' => 'success', 'duplicates' => $result]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}

// ========== AJAX HANDLER: DELETE DUPLICATE ==========
if ($action === 'delete_duplicate') {
    header('Content-Type: application/json');
    try {
        $record_id = required_param('record_id', PARAM_INT);

        $record = $DB->get_record('local_learning_users', ['id' => $record_id]);
        if (!$record) {
            throw new Exception("Registro no encontrado");
        }

        $DB->delete_records('local_learning_users', ['id' => $record_id]);

        echo json_encode(['status' => 'success', 'message' => 'Registro eliminado']);
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
</style>

<div id="app" v-cloak class="bg-slate-50 min-h-screen p-6">
    <div class="max-w-6xl mx-auto">

        <!-- Header -->
        <header class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 mb-6">
            <h1 class="text-3xl font-bold text-slate-900 mb-2">🧹 Limpiar Registros Duplicados</h1>
            <p class="text-slate-600">Encuentra y elimina registros duplicados en local_learning_users</p>
        </header>

        <!-- Actions -->
        <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 mb-6">
            <button
                @click="findDuplicates"
                :disabled="loading"
                class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-xl transition-all disabled:opacity-50"
            >
                {{ loading ? 'Buscando...' : '🔍 Buscar Duplicados' }}
            </button>
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

    </div>
</div>

<script>
const { createApp } = Vue;

createApp({
    data() {
        return {
            duplicates: [],
            loading: false,
            searched: false
        }
    },
    methods: {
        async findDuplicates() {
            this.loading = true;
            this.searched = false;
            try {
                const url = window.location.pathname;
                const res = await axios.get(url, {
                    params: { action: 'find_duplicates' }
                });

                if (res.data.status === 'success') {
                    this.duplicates = res.data.duplicates;
                    this.searched = true;
                } else {
                    alert('Error: ' + res.data.message);
                }
            } catch (e) {
                alert('Error: ' + e.message);
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

                if (res.data.status === 'success') {
                    alert('Registro eliminado exitosamente');
                    // Refresh the list
                    this.findDuplicates();
                } else {
                    alert('Error: ' + res.data.message);
                }
            } catch (e) {
                alert('Error: ' + e.message);
            }
        }
    }
}).mount('#app');
</script>

<?php
echo $OUTPUT->footer();
