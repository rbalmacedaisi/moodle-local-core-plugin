<?php
/**
 * Debug page: verifies what happens with llu.status during fix_student_setup.
 * Access: /local/grupomakro_core/pages/debug_status_fix.php
 */
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

global $DB;

admin_externalpage_setup('grupomakro_core_manage_courses');

// ---- AJAX: simulate what ajax_fix does with $status ----
$action = optional_param('action', '', PARAM_ALPHANUMEXT);

if ($action === 'test_param') {
    header('Content-Type: application/json');

    $raw_value     = $_GET['status'] ?? $_POST['status'] ?? '(not sent)';

    // Simulate PARAM_ALPHA (old behavior)
    $param_alpha   = clean_param($raw_value, PARAM_ALPHA);

    // Simulate PARAM_TEXT (new behavior)
    $param_text    = clean_param($raw_value, PARAM_TEXT);

    // Simulate the strtolower+trim applied after
    $after_alpha   = strtolower(trim($param_alpha));
    $after_text    = strtolower(trim($param_text));

    // Valid list (current in fix_student_setup.php)
    $valid = ['activo', 'inactivo', 'aplazado', 'retirado', 'suspendido', 'desertor', 'graduado', 'egresado'];

    echo json_encode([
        'raw_value'          => $raw_value,
        'param_alpha_result' => $param_alpha,
        'param_text_result'  => $param_text,
        'after_alpha_lower'  => $after_alpha,
        'after_text_lower'   => $after_text,
        'alpha_in_valid'     => in_array($after_alpha, $valid),
        'text_in_valid'      => in_array($after_text, $valid),
        'alpha_would_save'   => in_array($after_alpha, $valid) ? $after_alpha : 'activo (fallback)',
        'text_would_save'    => in_array($after_text, $valid) ? $after_text : 'activo (fallback)',
    ]);
    exit;
}

if ($action === 'check_user') {
    header('Content-Type: application/json');
    $username = optional_param('username', '', PARAM_RAW);
    if (empty($username)) {
        echo json_encode(['error' => 'username requerido']);
        exit;
    }

    $user = $DB->get_record('user', ['username' => trim($username), 'deleted' => 0]);
    if (!$user) {
        echo json_encode(['error' => 'Usuario no encontrado']);
        exit;
    }

    // Get all llu records for this user
    $llus = $DB->get_records_sql(
        "SELECT llu.*, lp.name as plan_name
         FROM {local_learning_users} llu
         LEFT JOIN {local_learning_plans} lp ON lp.id = llu.learningplanid
         WHERE llu.userid = :uid",
        ['uid' => $user->id]
    );

    // Get studentstatus custom field
    $field = $DB->get_record('user_info_field', ['shortname' => 'studentstatus']);
    $studentstatus = '';
    if ($field) {
        $data = $DB->get_record('user_info_data', ['userid' => $user->id, 'fieldid' => $field->id]);
        $studentstatus = $data ? $data->data : '(sin valor)';
    }

    echo json_encode([
        'userid'        => $user->id,
        'username'      => $user->username,
        'fullname'      => fullname($user),
        'studentstatus' => $studentstatus,
        'llu_records'   => array_values($llus),
        'llu_count'     => count($llus),
    ]);
    exit;
}

echo $OUTPUT->header();
?>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
<script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>

<div id="app" class="max-w-4xl mx-auto p-6 font-sans space-y-8">

    <h1 class="text-2xl font-black text-slate-800">Debug: fix_student_setup → campo <code class="bg-slate-100 px-2 py-0.5 rounded text-red-600">status</code></h1>
    <p class="text-slate-500 text-sm">Verifica qué valor llega al backend y cómo lo procesa según PARAM_ALPHA vs PARAM_TEXT.</p>

    <!-- TEST 1: PARAM_ALPHA vs PARAM_TEXT -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 space-y-4">
        <h2 class="font-bold text-slate-700 text-lg">1. Simulación de clean_param</h2>
        <p class="text-xs text-slate-500">Escribe el valor que tendrías en la columna "Estado Académico" del Excel y observa cómo lo procesa PHP.</p>

        <div class="flex gap-3 items-end">
            <div class="flex-1">
                <label class="block text-xs font-bold text-slate-500 mb-1">Valor del Excel (raw)</label>
                <input v-model="testValue" type="text" placeholder="ej: activo, aplazado, Egresado, retirado ..."
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none" />
            </div>
            <button @click="testParam" :disabled="loading1"
                    class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-bold rounded-lg disabled:opacity-50">
                Probar
            </button>
        </div>

        <div v-if="paramResult" class="mt-4 overflow-x-auto">
            <table class="w-full text-sm border-collapse">
                <thead>
                    <tr class="bg-slate-50 text-xs font-bold text-slate-500 uppercase">
                        <th class="p-3 text-left border border-slate-200">Paso</th>
                        <th class="p-3 text-left border border-slate-200">Resultado</th>
                        <th class="p-3 text-left border border-slate-200">¿Válido?</th>
                        <th class="p-3 text-left border border-slate-200">Se guardaría como</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="border border-slate-200">
                        <td class="p-3 font-mono text-xs">Valor recibido (raw)</td>
                        <td class="p-3 font-mono text-blue-700 font-bold">{{ paramResult.raw_value }}</td>
                        <td class="p-3">—</td>
                        <td class="p-3">—</td>
                    </tr>
                    <tr :class="paramResult.alpha_in_valid ? 'bg-green-50' : 'bg-red-50'" class="border border-slate-200">
                        <td class="p-3 font-mono text-xs">PARAM_ALPHA → strtolower<br><span class="text-slate-400">(comportamiento ANTERIOR)</span></td>
                        <td class="p-3 font-mono font-bold">{{ paramResult.after_alpha_lower || '(vacío)' }}</td>
                        <td class="p-3">
                            <span :class="paramResult.alpha_in_valid ? 'text-green-700 bg-green-100' : 'text-red-700 bg-red-100'"
                                  class="px-2 py-0.5 rounded-full text-xs font-bold">
                                {{ paramResult.alpha_in_valid ? '✓ En lista' : '✗ FUERA de lista → fallback' }}
                            </span>
                        </td>
                        <td class="p-3 font-mono font-bold" :class="paramResult.alpha_in_valid ? 'text-green-700' : 'text-red-600'">
                            {{ paramResult.alpha_would_save }}
                        </td>
                    </tr>
                    <tr :class="paramResult.text_in_valid ? 'bg-green-50' : 'bg-amber-50'" class="border border-slate-200">
                        <td class="p-3 font-mono text-xs">PARAM_TEXT → strtolower<br><span class="text-slate-400">(comportamiento NUEVO)</span></td>
                        <td class="p-3 font-mono font-bold">{{ paramResult.after_text_lower || '(vacío)' }}</td>
                        <td class="p-3">
                            <span :class="paramResult.text_in_valid ? 'text-green-700 bg-green-100' : 'text-amber-700 bg-amber-100'"
                                  class="px-2 py-0.5 rounded-full text-xs font-bold">
                                {{ paramResult.text_in_valid ? '✓ En lista' : '⚠ Fuera de lista → fallback' }}
                            </span>
                        </td>
                        <td class="p-3 font-mono font-bold" :class="paramResult.text_in_valid ? 'text-green-700' : 'text-amber-600'">
                            {{ paramResult.text_would_save }}
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Diagnosis -->
            <div class="mt-4 p-4 rounded-xl text-sm font-medium"
                 :class="diagnosis.type === 'bug' ? 'bg-red-50 border border-red-200 text-red-700' :
                         diagnosis.type === 'ok'  ? 'bg-green-50 border border-green-200 text-green-700' :
                                                    'bg-amber-50 border border-amber-200 text-amber-700'">
                <span class="font-black">{{ diagnosis.label }}</span> {{ diagnosis.msg }}
            </div>
        </div>
    </div>

    <!-- TEST 2: Estado actual de un usuario en BD -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 space-y-4">
        <h2 class="font-bold text-slate-700 text-lg">2. Estado actual en BD por usuario</h2>
        <p class="text-xs text-slate-500">Consulta los registros reales de <code>local_learning_users</code> y <code>user_info_data.studentstatus</code>.</p>

        <div class="flex gap-3 items-end">
            <div class="flex-1">
                <label class="block text-xs font-bold text-slate-500 mb-1">Username</label>
                <input v-model="checkUsername" type="text" placeholder="ej: 8-888-888"
                       class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 outline-none"
                       @keyup.enter="checkUser" />
            </div>
            <button @click="checkUser" :disabled="loading2"
                    class="px-5 py-2 bg-slate-800 hover:bg-slate-700 text-white text-sm font-bold rounded-lg disabled:opacity-50">
                Consultar
            </button>
        </div>

        <div v-if="userResult" class="space-y-4">
            <div class="p-3 bg-slate-50 rounded-lg text-sm">
                <span class="font-bold text-slate-700">{{ userResult.fullname }}</span>
                <span class="ml-2 text-slate-400 font-mono text-xs">(ID: {{ userResult.userid }})</span>
                <div class="mt-1 text-xs">
                    <span class="font-bold text-slate-500">Estado Estudiante (studentstatus):</span>
                    <span class="ml-1 font-mono font-bold text-purple-700">{{ userResult.studentstatus }}</span>
                </div>
            </div>

            <div v-if="userResult.llu_count > 1" class="p-3 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-700 font-bold">
                ⚠ Este usuario tiene {{ userResult.llu_count }} registros en local_learning_users (múltiples planes). El fix_student_setup busca por userid+planid, pero get_current_state puede devolver el último registro sin filtrar por plan.
            </div>

            <table class="w-full text-xs border-collapse">
                <thead>
                    <tr class="bg-slate-50 text-[10px] font-bold text-slate-500 uppercase">
                        <th class="p-2 border border-slate-200 text-left">Plan</th>
                        <th class="p-2 border border-slate-200 text-center">llu.status<br><span class="font-normal text-slate-400">(Estado Académico)</span></th>
                        <th class="p-2 border border-slate-200 text-center">userrolename</th>
                        <th class="p-2 border border-slate-200 text-center">periodid</th>
                        <th class="p-2 border border-slate-200 text-center">academicperiodid</th>
                        <th class="p-2 border border-slate-200 text-center">groupname</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="llu in userResult.llu_records" :key="llu.id"
                        class="border border-slate-200 hover:bg-slate-50">
                        <td class="p-2 font-medium text-blue-700">{{ llu.plan_name || '(sin plan)' }}</td>
                        <td class="p-2 text-center">
                            <span :class="llu.status === 'activo' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'"
                                  class="px-2 py-0.5 rounded-full font-bold text-[10px] uppercase">
                                {{ llu.status || '(vacío)' }}
                            </span>
                        </td>
                        <td class="p-2 text-center font-mono">{{ llu.userrolename }}</td>
                        <td class="p-2 text-center font-mono">{{ llu.currentperiodid }}</td>
                        <td class="p-2 text-center font-mono">{{ llu.academicperiodid }}</td>
                        <td class="p-2 text-center font-mono">{{ llu.groupname || '-' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="userError" class="p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700 font-bold">
            {{ userError }}
        </div>
    </div>

</div>

<script>
const { createApp } = Vue;
createApp({
    data() {
        return {
            testValue: 'aplazado',
            paramResult: null,
            loading1: false,
            checkUsername: '',
            userResult: null,
            userError: null,
            loading2: false,
        };
    },
    computed: {
        diagnosis() {
            if (!this.paramResult) return {};
            const r = this.paramResult;
            if (!r.alpha_in_valid && r.text_in_valid) {
                return {
                    type: 'bug',
                    label: '🐛 BUG CONFIRMADO:',
                    msg: `Con PARAM_ALPHA el valor "${r.raw_value}" se convierte a "${r.after_alpha_lower}" y cae al fallback "activo". Con PARAM_TEXT llega correctamente como "${r.after_text_lower}". El cambio de PARAM_ALPHA → PARAM_TEXT soluciona el problema.`
                };
            }
            if (r.alpha_in_valid && r.text_in_valid) {
                return {
                    type: 'ok',
                    label: '✓ Sin diferencia:',
                    msg: `Ambos métodos procesan "${r.raw_value}" correctamente como "${r.after_text_lower}". El problema puede estar en otro lugar (¿el valor real del Excel tiene caracteres invisibles?).`
                };
            }
            if (!r.text_in_valid) {
                return {
                    type: 'warn',
                    label: '⚠ Valor fuera de lista:',
                    msg: `"${r.raw_value}" no está en la lista de valores válidos con ninguno de los dos métodos. Ambos caerían al fallback "activo". Verifica si el valor del Excel es correcto.`
                };
            }
            return { type: 'ok', label: '—', msg: '' };
        }
    },
    methods: {
        async testParam() {
            this.loading1 = true;
            this.paramResult = null;
            try {
                const url = window.location.pathname;
                const res = await axios.get(url, { params: { action: 'test_param', status: this.testValue } });
                this.paramResult = res.data;
            } catch (e) {
                alert('Error: ' + e.message);
            }
            this.loading1 = false;
        },
        async checkUser() {
            if (!this.checkUsername.trim()) return;
            this.loading2 = true;
            this.userResult = null;
            this.userError = null;
            try {
                const url = window.location.pathname;
                const res = await axios.get(url, { params: { action: 'check_user', username: this.checkUsername.trim() } });
                if (res.data.error) {
                    this.userError = res.data.error;
                } else {
                    this.userResult = res.data;
                }
            } catch (e) {
                this.userError = 'Error: ' + e.message;
            }
            this.loading2 = false;
        }
    }
}).mount('#app');
</script>

<?php echo $OUTPUT->footer(); ?>
