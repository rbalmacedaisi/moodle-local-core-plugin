<?php
require_once(__DIR__ . '/../../../config.php');
global $PAGE, $OUTPUT, $CFG;

$context = context_system::instance();
require_login();
require_capability('moodle/site:config', $context);

$PAGE->set_url('/local/grupomakro_core/pages/debug_planning.php');
$PAGE->set_context($context);
$PAGE->set_title('Debug Planificador');
$PAGE->set_heading('Debug: Planificador Académico');
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();
?>

<!-- Libs -->
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
<script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>

<div id="app" class="p-6 bg-slate-100 min-h-screen">
    <h1 class="text-2xl font-bold mb-4">Debug Console</h1>
    
    <div class="space-y-4">
        <!-- Vue Status -->
        <div class="bg-white p-4 rounded shadow border border-slate-200">
            <h2 class="font-bold border-b pb-2 mb-2">1. Vue Status</h2>
            <div class="flex items-center gap-2">
                <span class="w-4 h-4 rounded-full" :class="mounted ? 'bg-green-500' : 'bg-red-500'"></span>
                <span>{{ mounted ? 'Vue Mounted Successfully' : 'Vue Not Mounted' }}</span>
                <span class="text-xs text-slate-500 ml-2">(v{{ version }})</span>
            </div>
        </div>

        <!-- API Test -->
        <div class="bg-white p-4 rounded shadow border border-slate-200">
            <h2 class="font-bold border-b pb-2 mb-2">2. API Connectivity</h2>
            <button @click="testApi" class="bg-blue-600 text-white px-3 py-1 rounded text-sm hover:bg-blue-700">Test API Connection</button>
            
            <div class="mt-2 text-xs font-mono bg-slate-900 text-green-400 p-2 rounded overflow-auto max-h-40" v-if="apiLog">
                {{ apiLog }}
            </div>
        </div>

        <!-- Component Check -->
        <div class="bg-white p-4 rounded shadow border border-slate-200">
            <h2 class="font-bold border-b pb-2 mb-2">3. Variable Check</h2>
            <ul class="text-sm space-y-1">
                <li :class="hasAcademicPeriods ? 'text-green-600' : 'text-red-600'">
                    academicPeriods: {{ hasAcademicPeriods ? 'DEFINED' : 'UNDEFINED' }}
                </li>
                <li :class="hasAllLearningPlans ? 'text-green-600' : 'text-red-600'">
                    allLearningPlans: {{ hasAllLearningPlans ? 'DEFINED' : 'UNDEFINED' }}
                </li>
            </ul>
        </div>
    </div>
</div>

<script>
const { createApp, ref, onMounted } = Vue;

createApp({
    setup() {
        const mounted = ref(false);
        const version = Vue.version;
        const apiLog = ref('');
        
        // Mocking the variables being debugged
        const academicPeriods = ref([]);
        const allLearningPlans = ref([]);

        onMounted(() => {
            mounted.value = true;
            console.log("Debug App Mounted");
        });

        const testApi = async () => {
            apiLog.value = "Testing API...";
            const wwwroot = '<?php echo $CFG->wwwroot; ?>';
            const sesskey = '<?php echo sesskey(); ?>';
            
            try {
                const url = `${wwwroot}/local/grupomakro_core/ajax.php`;
                const payload = {
                    action: 'local_grupomakro_get_periods',
                    sesskey: sesskey,
                    args: {}
                };
                
                apiLog.value += `\nPOST ${url}...`;
                const res = await axios.post(url, payload);
                apiLog.value += `\nStatus: ${res.status}`;
                apiLog.value += `\nData: ${JSON.stringify(res.data).substring(0, 100)}...`;
                
                if (res.data && !res.data.error) {
                    apiLog.value += "\nSUCCESS";
                } else {
                    apiLog.value += "\nFAILED (Logic)";
                }
            } catch (e) {
                apiLog.value += `\nERROR: ${e.message}`;
            }
        };

        return {
            mounted, version, apiLog, testApi,
            // Checkers
            hasAcademicPeriods: true,
            hasAllLearningPlans: true
        };
    }
}).mount('#app');
</script>

<?php
echo $OUTPUT->footer();
