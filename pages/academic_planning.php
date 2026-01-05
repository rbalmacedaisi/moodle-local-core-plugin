<?php
require_once(__DIR__ . '/../../../config.php');
global $DB, $PAGE, $OUTPUT, $CFG;

$context = context_system::instance();
require_login();
require_capability('moodle/site:config', $context);

$PAGE->set_url('/local/grupomakro_core/pages/academic_planning.php');
$PAGE->set_context($context);
$PAGE->set_title('Planificador Académico');
$PAGE->set_heading('Planificación de Oferta Académica');
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();
?>

<!-- Tailwind CSS -->
<script src="https://cdn.tailwindcss.com"></script>
<!-- Vue 3 -->
<script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
<!-- Axios -->
<script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
<!-- Lucide Icons -->
<script src="https://unpkg.com/lucide@latest"></script>

<style>
    .fade-enter-active, .fade-leave-active { transition: opacity 0.3s ease; }
    .fade-enter-from, .fade-leave-to { opacity: 0; }
    .slide-enter-active, .slide-leave-active { transition: transform 0.3s ease; }
    .slide-enter-from, .slide-leave-to { transform: translateY(10px); opacity: 0; }
    
    /* Scrollbar */
    ::-webkit-scrollbar { width: 8px; height: 8px; }
    ::-webkit-scrollbar-track { background: #f1f5f9; }
    ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
    ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
</style>

<div id="app" class="bg-slate-50 min-h-screen p-4 font-sans text-slate-800">
    
    <!-- HEADER -->
    <header class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900 flex items-center gap-2">
                <i data-lucide="layers" class="text-blue-600"></i>
                Planificador de Oferta Académica
            </h1>
            <p class="text-slate-500 text-sm">
                 Proyección de Olas & Análisis de Impacto (Regla &ge; 12)
            </p>
        </div>
        <div class="flex gap-2">
            <button @click="reloadData" class="flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 rounded-lg transition-colors text-sm font-medium shadow-sm">
                <i data-lucide="refresh-cw" class="w-4 h-4"></i> Recargar
            </button>
        </div>
    </header>

    <!-- LOADING -->
    <div v-if="loading" class="fixed inset-0 bg-white/80 backdrop-blur-sm z-50 flex flex-col items-center justify-center">
        <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-600 mb-4"></div>
        <p class="text-slate-600 font-medium">Procesando Motor de Proyección...</p>
    </div>

    <!-- MAIN CONTENT -->
    <div v-else-if="analysis">
        
        <!-- GLOBAL CONTEXT -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-6 sticky top-2 z-20 border-t-4 border-t-blue-500">
            <div class="flex flex-col md:flex-row gap-4 items-end md:items-center">
                <div class="flex items-center gap-2 text-slate-700 font-bold mr-2">
                    <i data-lucide="filter" class="w-4 h-4"></i> Contexto:
                </div>
                
                <div class="flex flex-col flex-1">
                     <label class="text-xs text-slate-500 font-bold mb-1">Periodo Actual (Base)</label>
                    <select v-model="selectedPeriodId" class="bg-slate-100 border-none rounded-lg px-3 py-2 text-sm font-medium focus:ring-2 focus:ring-blue-500 w-full md:w-64">
                        <option v-for="p in uniquePeriods" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                </div>

                <div class="flex flex-col flex-1">
                     <label class="text-xs text-slate-500 font-bold mb-1">Carrera / Plan</label>
                    <select v-model="selectedCareer" class="bg-slate-100 border-none rounded-lg px-3 py-2 text-sm font-medium focus:ring-2 focus:ring-blue-500 w-full">
                        <option value="Todas">Todas las Carreras</option>
                        <option v-for="c in careers" :key="c" :value="c">{{ c }}</option>
                    </select>
                </div>

                <div class="flex flex-col">
                     <label class="text-xs text-slate-500 font-bold mb-1">Jornada</label>
                    <select v-model="selectedShift" class="bg-slate-100 border-none rounded-lg px-3 py-2 text-sm font-medium focus:ring-2 focus:ring-blue-500 w-40">
                        <option value="Todas">Todas</option>
                        <option v-for="s in shifts" :key="s" :value="s">{{ s }}</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- TABS -->
        <div class="flex gap-2 mb-6 border-b border-slate-200 overflow-x-auto">
            <button @click="activeTab = 'planning'" :class="['px-4 py-3 text-sm font-bold flex items-center gap-2 border-b-2 whitespace-nowrap transition-colors', activeTab === 'planning' ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-700']">
                <i data-lucide="trending-up" class="w-4 h-4"></i> Proyección de Apertura
            </button>
            <button @click="activeTab = 'groups'" :class="['px-4 py-3 text-sm font-bold flex items-center gap-2 border-b-2 whitespace-nowrap transition-colors', activeTab === 'groups' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:text-slate-700']">
                <i data-lucide="group" class="w-4 h-4"></i> Visual por Grupos
            </button>
            <button @click="activeTab = 'students'" :class="['px-4 py-3 text-sm font-bold flex items-center gap-2 border-b-2 whitespace-nowrap transition-colors', activeTab === 'students' ? 'border-purple-600 text-purple-700' : 'border-transparent text-slate-500 hover:text-slate-700']">
                <i data-lucide="graduation-cap" class="w-4 h-4"></i> Impacto & Graduandos
            </button>
        </div>

         <!-- TAB 1: PLANNING -->
         <div v-if="activeTab === 'planning'" class="space-y-6">
            <!-- KPI ROW -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 border-l-4 border-l-blue-500">
                    <p class="text-slate-500 text-xs font-bold uppercase">Asignaturas Totales</p>
                    <h3 class="text-2xl font-bold text-slate-800">{{ analysis.subjectList.length }}</h3>
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 border-l-4 border-l-green-500">
                    <p class="text-slate-500 text-xs font-bold uppercase">Apertura Inmediata (P-I)</p>
                    <h3 class="text-2xl font-bold text-slate-800">{{ analysis.subjectList.filter(s => s.isOpen).length }}</h3>
                    <p class="text-xs text-green-600">Cumplen quórum &ge; 12</p>
                </div>
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 border-l-4 border-l-teal-500">
                    <p class="text-slate-500 text-xs font-bold uppercase">Pico en Futuro (P-II)</p>
                    <h3 class="text-2xl font-bold text-slate-800">
                        {{ analysis.subjectList.filter(s => s.suggestion.includes('P-II')).length }}
                    </h3>
                    <p class="text-xs text-teal-600">Materias que crecerán próx. periodo</p>
                </div>
            </div>

            <!-- MAIN TABLE -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                <div class="p-4 border-b border-gray-100 bg-slate-50 flex justify-between items-center">
                    <h3 class="font-bold text-slate-700 flex items-center gap-2">
                        <i data-lucide="calendar" class="w-4 h-4 text-blue-500"></i> Matriz de Proyección
                    </h3>
                     <div class="flex items-center gap-2 text-xs">
                        <span class="px-2 py-1 bg-yellow-50 text-yellow-700 rounded border border-yellow-200">Editable: Nuevos Ingresos</span>
                    </div>
                </div>
                <!-- TABLE SCROLL -->
                <div class="overflow-x-auto max-h-[600px]">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-slate-500 uppercase bg-slate-50 sticky top-0 z-10 shadow-sm">
                            <tr>
                                <th class="px-4 py-3 bg-slate-50 min-w-[200px]">Asignatura</th>
                                <th class="px-2 py-3 bg-slate-50 text-center">Nivel</th>
                                <th class="px-2 py-3 bg-slate-50 text-center w-20">Nuevos<br/>(Man)</th>
                                <th class="px-2 py-3 bg-blue-50 text-blue-900 text-center border-l border-blue-100">
                                    P-I (Próximo)<br/><span class="text-[10px] font-normal">(Planificación)</span>
                                </th>
                                <th v-for="i in 5" :key="i" class="px-2 py-3 text-slate-400 text-center border-l border-slate-100">
                                    {{ getPeriodLabel(i) }}
                                </th>
                                <th class="px-2 py-3 bg-slate-50 text-center">Sugerencia</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="subj in analysis.subjectList" :key="subj.id" :class="['transition-colors', subj.isOpen ? 'bg-white' : 'bg-slate-50/50 hover:bg-slate-50']">
                                <td class="px-4 py-3 font-medium text-slate-700 relative">
                                    {{ subj.name }}
                                    <span v-if="subj.countP1 === 0" class="ml-2 text-[10px] text-slate-400 font-normal italic block">(Sin demanda interna)</span>
                                </td>
                                <td class="px-2 py-3 text-center text-xs">
                                    <span class="bg-gray-100 text-gray-800 px-2 py-0.5 rounded-full">{{ toRoman(subj.semesterNum) }}</span>
                                </td>
                                <td class="px-2 py-2 text-center">
                                    <input type="number" min="0" class="w-12 p-1 text-center text-sm border border-slate-300 rounded focus:ring-2 focus:ring-blue-500 outline-none bg-white font-mono"
                                           v-model.number="manualProjections[subj.name]" placeholder="0">
                                </td>
                                <td class="px-2 py-3 text-center border-l border-blue-100 font-bold text-base">
                                     <button @click="openPopover(subj, 0, $event)" :class="['px-3 py-1 rounded transition-all border border-transparent hover:scale-105', subj.isOpen ? 'text-blue-700 bg-blue-50 hover:bg-blue-100 border-blue-200' : 'text-slate-400 hover:text-slate-600 hover:bg-slate-200']">
                                        {{ subj.totalP1 }}
                                    </button>
                                </td>
                                <td v-for="i in 5" :key="i" class="px-2 py-3 text-center border-l border-slate-100 text-slate-600 text-xs">
                                    <button @click="openPopover(subj, i, $event)" class="hover:bg-slate-200 px-2 py-1 rounded transition-colors" v-if="subj['countP'+(i+1)] > 0">
                                        {{ subj['countP'+(i+1)] }}
                                    </button>
                                    <span v-else>-</span>
                                </td>
                                <td class="px-2 py-3 text-center">
                                    <span :class="getSuggestionBadgeClass(subj.suggestion)">{{ subj.suggestion }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
         </div>

         <!-- TAB 2: COHORTS -->
         <div v-if="activeTab === 'groups'" class="space-y-6">
             <div class="flex justify-between items-center mb-4">
                  <h3 class="text-xl font-bold text-slate-800">Visual por Grupos (Cohortes)</h3>
                  <span class="bg-blue-100 text-blue-800 px-3 py-1 rounded-full text-xs font-bold">{{ analysis.cohortViewList.length }} Grupos</span>
             </div>
             
             <div class="space-y-8">
                 <div v-for="cohort in analysis.cohortViewList" :key="cohort.key" class="bg-white rounded-xl shadow-sm border border-t-4 border-t-indigo-500 overflow-hidden">
                     <div class="p-4 bg-slate-50 border-b border-slate-100 flex justify-between items-start">
                         <div>
                             <h4 class="font-bold text-slate-800 text-sm uppercase tracking-wide">{{ cohort.semester }}</h4>
                             <p class="text-xs text-slate-500 font-medium mt-1">{{ cohort.career }}</p>
                             <p class="text-xs text-slate-400">{{ cohort.shift }} - {{ cohort.bimestreLabel }}</p>
                         </div>
                         <div class="text-center bg-white px-3 py-1 rounded border border-slate-200">
                             <span class="block text-xl font-bold text-slate-700">{{ cohort.studentCount }}</span>
                             <span className="text-[10px] text-slate-400 uppercase">Est.</span>
                         </div>
                     </div>
                     
                     <!-- Timeline -->
                     <div class="p-4 grid grid-cols-1 md:grid-cols-6 gap-4 bg-white overflow-x-auto">
                        <div v-for="(periodIdx, idx) in [0,1,2,3,4,5]" :key="idx" 
                             class="bg-slate-50 rounded p-2 border border-slate-100 flex flex-col min-h-[100px] min-w-[120px]"
                             @dragover.prevent @drop="handleDrop($event, cohort.key, periodIdx)">
                            
                             <h6 :class="['text-[10px] font-bold uppercase mb-2 pb-1 border-b', idx===0 ? 'text-green-700 border-green-200' : 'text-slate-400 border-slate-200']">
                                 {{ getPeriodLabel(idx) }}
                             </h6>
                             
                             <div class="space-y-2 flex-1">
                                 <div v-for="subj in getSubjectsForCohortPeriod(cohort, periodIdx)" :key="subj" 
                                      draggable="true" @dragstart="handleDragStart($event, subj, cohort.key, periodIdx)"
                                      class="bg-white border border-slate-200 rounded p-1.5 shadow-sm hover:border-blue-300 cursor-grab active:cursor-grabbing group relative">
                                      
                                      <div class="flex justify-between items-start mb-1">
                                          <p class="text-[10px] font-medium text-slate-700 leading-tight truncate" :title="subj">{{ subj }}</p>
                                          <!-- Count Badge -->
                                          <span v-if="getSubjectCount(subj, periodIdx, cohort.key) > 0" class="bg-blue-100 text-blue-800 text-[9px] px-1 rounded font-bold shrink-0 ml-1">
                                            {{ getSubjectCount(subj, periodIdx, cohort.key) }}
                                          </span>
                                      </div>
                                      
                                      <!-- Move Select -->
                                      <select v-model="deferredGroups[subj + '_' + cohort.key]" 
                                             class="w-full text-[9px] border rounded p-0.5 bg-slate-50 text-slate-500 focus:ring-1 focus:ring-blue-200 outline-none mt-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                          <option :value="undefined">Default</option>
                                          <option :value="0">P-I</option>
                                          <option :value="1">P-II</option>
                                          <option :value="2">P-III</option>
                                          <option :value="3">P-IV</option>
                                      </select>
                                 </div>
                             </div>
                        </div>
                     </div>
                 </div>
             </div>
         </div>

         <!-- TAB 3: STUDENTS -->
         <div v-if="activeTab === 'students'" class="space-y-6">
              <!-- ALERTS -->
             <div v-if="analysis.studentList.filter(s => s.isGradRisk).length > 0" class="bg-red-50 border-l-4 border-red-500 p-4 rounded-r-lg flex items-start gap-3">
                 <i data-lucide="alert-triangle" class="text-red-600 mt-1 w-5 h-5"></i>
                 <div>
                     <h4 class="font-bold text-red-800">Alerta de Graduación</h4>
                     <p class="text-sm text-red-700 mt-1">
                         Se detectaron <strong>{{ analysis.studentList.filter(s => s.isGradRisk).length }} estudiantes</strong> en riesgo de grado por falta de oferta en asignaturas críticas.
                     </p>
                 </div>
             </div>

             <!-- FILTERS -->
             <div class="flex flex-wrap gap-2 mb-4">
                <button @click="studentStatusFilter = 'Todos'" :class="['px-4 py-2 rounded-full text-sm font-bold border transition-all', studentStatusFilter === 'Todos' ? 'bg-slate-800 text-white border-slate-800' : 'bg-white border-slate-200 text-slate-600']">Todos</button>
                <button @click="studentStatusFilter = 'GradRisk'" :class="['px-4 py-2 rounded-full text-sm font-bold border transition-all', studentStatusFilter === 'GradRisk' ? 'bg-red-600 text-white border-red-600' : 'bg-white border-slate-200 text-red-600']">Riesgo Grado</button>
                <button @click="studentStatusFilter = 'Critical'" :class="['px-4 py-2 rounded-full text-sm font-bold border transition-all', studentStatusFilter === 'Critical' ? 'bg-red-500 text-white border-red-500' : 'bg-white border-slate-200 text-red-500']">Sin Asignación</button>
                
                <div class="ml-auto relative w-full md:w-64">
                    <i data-lucide="search" class="absolute left-3 top-2.5 text-slate-400 w-4 h-4"></i>
                    <input type="text" v-model="searchTerm" placeholder="Buscar..." class="w-full pl-9 pr-3 py-2 bg-white border border-slate-200 rounded-full text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
                </div>
             </div>

             <!-- LIST -->
             <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-slate-100 text-slate-600 text-xs uppercase tracking-wider">
                                <th class="p-4 font-bold border-b">Estudiante</th>
                                <th class="p-4 font-bold border-b text-center">Estado</th>
                                <th class="p-4 font-bold border-b w-1/3">Se Abren (Proyección)</th>
                                <th class="p-4 font-bold border-b w-1/3 bg-red-50 text-red-800 border-l border-red-100">Bloqueadas</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-sm">
                            <tr v-for="student in filteredStudents" :key="student.id" :class="['hover:bg-slate-50 transition-colors', student.isGradRisk ? 'bg-red-50/20' : '']">
                                <td class="p-4 align-top">
                                    <div class="font-bold text-slate-800">{{ student.name }}</div>
                                    <div class="text-xs text-slate-500 font-mono mb-1">{{ student.id }}</div>
                                    <div class="flex flex-wrap gap-1">
                                        <span class="bg-blue-100 text-blue-800 px-2 py-0.5 rounded-full text-xs font-bold">Nivel {{ student.currentSemConfig }}</span>
                                        <span v-if="student.isGradRisk" class="bg-red-600 text-white px-2 py-0.5 rounded-full text-xs font-bold">RIESGO</span>
                                    </div>
                                </td>
                                <td class="p-4 align-top text-center">
                                    <span v-if="student.status === 'critical'" class="bg-red-100 text-red-800 px-2 py-0.5 rounded-full text-xs font-bold">0 Materias</span>
                                    <span v-else-if="student.status === 'low'" class="bg-yellow-100 text-yellow-800 px-2 py-0.5 rounded-full text-xs font-bold">1 Materia</span>
                                    <span v-else class="bg-green-100 text-green-800 px-2 py-0.5 rounded-full text-xs font-bold">OK ({{ student.loadCount }})</span>
                                </td>
                                <td class="p-4 align-top">
                                    <ul v-if="student.projectedSubjects.length > 0" class="space-y-1">
                                        <li v-for="s in student.projectedSubjects" :key="s.name" class="flex items-start gap-1.5 text-xs text-slate-700">
                                            <i data-lucide="check-circle" class="w-3 h-3 text-green-500 mt-0.5 shrink-0"></i>
                                            <span>{{ s.name }}</span>
                                        </li>
                                    </ul>
                                    <span v-else class="text-xs text-slate-400 italic">Nada para ver</span>
                                </td>
                                <td class="p-4 align-top bg-red-50/30 border-l border-red-50">
                                    <ul v-if="student.missingSubjects.length > 0" class="space-y-1">
                                        <li v-for="s in student.missingSubjects" :key="s.name" class="flex items-start gap-1.5 text-xs text-red-700/80">
                                            <i data-lucide="lock" class="w-3 h-3 text-red-400 mt-0.5 shrink-0"></i>
                                            <span>{{ s.name }}</span>
                                        </li>
                                    </ul>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
             </div>
         </div>
    </div>

    <!-- POPOVER MODAL (Simplification of Popover) -->
    <div v-if="activePopover" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/20 backdrop-blur-sm" @click.self="activePopover = null">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-md overflow-hidden animate-in zoom-in-95 duration-200">
             <div class="p-3 border-b border-slate-200 bg-slate-50 flex justify-between items-center">
                 <h4 class="font-bold text-slate-800">{{ activePopover.subject.name }}</h4>
                 <button @click="activePopover = null"><i data-lucide="x" class="w-4 h-4 text-slate-400"></i></button>
             </div>
             <div class="p-4 max-h-[60vh] overflow-y-auto">
                 <p class="text-xs font-bold text-slate-500 uppercase mb-2">Desglose por Cohortes ({{ getPeriodLabel(activePopover.periodIndex) }})</p>
                 <div class="space-y-2">
                     <div v-for="(groupData, groupKey) in activePopover.data" :key="groupKey" class="bg-slate-50 p-2 rounded border border-slate-100 text-sm">
                         <details class="group">
                             <summary class="flex justify-between items-center cursor-pointer list-none select-none">
                                 <span class="font-medium text-slate-700">{{ groupKey }}</span>
                                 <span class="font-bold text-blue-700 bg-blue-100 px-2 rounded">{{ groupData.count }}</span>
                             </summary>
                             <div class="mt-2 pl-2 border-l-2 border-slate-200">
                                 <ul class="list-disc list-inside text-xs text-slate-500">
                                     <li v-for="stuName in groupData.students" :key="stuName">{{ stuName }}</li>
                                 </ul>
                             </div>
                         </details>
                     </div>
                     <div v-if="!activePopover.data || Object.keys(activePopover.data).length === 0" class="text-slate-400 italic text-center text-xs">
                          Sin estudiantes asignados a este periodo.
                     </div>
                 </div>
             </div>
        </div>
    </div>

</div>

<script>
const { createApp, ref, computed, onMounted, nextTick, watch, reactive } = Vue;

createApp({
    setup() {
        const loading = ref(true);
        const rawData = ref([]); 
        const selectedPeriodId = ref(0);
        const periods = ref([]);
        const activeTab = ref('planning');
        
        // State for filters
        const selectedCareer = ref('Todas');
        const selectedShift = ref('Todas');
        
        // Manual Adjustments
        const manualProjections = reactive({}); // { SubjectName: Count }
        const deferredGroups = reactive({}); // { SubjectName_CohortKey: PeriodIndex (0-5) }
        
        // Popover
        const activePopover = ref(null);
        
        // Student Filter
        const studentStatusFilter = ref('Todos');
        const searchTerm = ref('');
        
        // Moodle Call
        const callMoodle = async (method, args) => {
            const wwwroot = '<?php echo $CFG->wwwroot; ?>';
            const sesskey = '<?php echo sesskey(); ?>';
            try {
                // Determine URL: Custom ajax.php for this plugin
                const url = `${wwwroot}/local/grupomakro_core/ajax.php`;
                
                // Payload
                const payload = {
                    action: method,
                    sesskey: sesskey,
                    args: args // ajax.php handles 'args' by flattening them into $_POST
                };


                const response = await axios.post(url, payload);
                console.log("Raw AJAX Response:", response); // DEBUG

                if (!response || !response.data) {
                    throw new Error("Respuesta vacía del servidor (sin datos).");
                }

                // Debug response
                // standard 'data' wrapper
                if (response.data.data !== undefined) { 
                     return response.data.data;
                }
                // 'periods' specific wrapper
                else if (response.data.periods) {
                     return response.data.periods;
                }
                // Generic success with no data field? (e.g. update)
                else if (response.data.status === 'success' && !response.data.error) {
                     return response.data; // Return whole object
                }
                else if (response.data.error === false) {
                    // Fallback if data is missing but error is false?
                    return response.data;
                }
                else {
                     throw new Error(response.data.message || "Error desconocido o estructura inesperada.");
                }
            } catch (err) {
                console.error("AJAX Error:", err);
                alert("Error: " + err.message);
                return [];
            }
        };

        const loadInitial = async () => {
             let p = await callMoodle('local_grupomakro_get_periods', {});
             periods.value = p || [];
             if(p.length > 0 && selectedPeriodId.value === 0) selectedPeriodId.value = p[0].id;
             fetchData();
        };

        const reloadData = () => fetchData();

        const fetchData = async () => {
             loading.value = true;
             // Call new Backend Logic
             rawData.value = await callMoodle('local_grupomakro_get_planning_data', { periodid: selectedPeriodId.value });
             loading.value = false;
             nextTick(() => lucide.createIcons());
        };

        // --- CORE LOGIC (Ported from React) ---
        const analysis = computed(() => {
            if (rawData.value.length === 0) return { subjectList: [], cohortViewList: [], studentList: [] };

            let filtered = rawData.value;
            // Filter Source Data
            if (selectedCareer.value !== 'Todas') filtered = filtered.filter(s => s.career === selectedCareer.value);
            if (selectedShift.value !== 'Todas') filtered = filtered.filter(s => s.shift === selectedShift.value);

            const subjectsMap = {}; 
            const studentsMap = {}; // Renamed from students to avoid conflict
            const cohorts = {};
            const studentsInSem = {}; // Map Level -> Cohort -> Data
            
            // 1. Initialize Cohorts & Students
            filtered.forEach(stu => {
                 // Determine Level/Bimestre from Config or Props
                 // Parse 'Nivel X' or check pending?
                 // Let's use the explicit 'currentSemConfig' sent from backend first, or fallback.
                 // Assuming format "Periodo X" or "Nivel X". 
                 // Backend sends periodname, subperiodname.
                 // We need to parse Number.
                 
                 // Normalize Level
                 let levelConfig = stu.currentSemConfig; // e.g., "Periodo IV"
                 let subConfig = stu.currentSubperiodConfig; // e.g., "Bimestre II"
                 
                 // Helper to extract number
                 let levelNum = 0;
                 if (typeof levelConfig === 'string') {
                     let match = levelConfig.match(/\d+/);
                     if (match) levelNum = parseInt(match[0]);
                     // Roman support?
                     if (!match) {
                         if (levelConfig.includes('I')) levelNum = 1; // Simplified Roman fallback if needed
                     }
                 }
                 
                 let isBimestre2 = subConfig && subConfig.includes('II');
                 
                 // Logic from React: calculate 'Planning Level'
                 let planningLevel = levelNum;
                 let planningBimestre = 'II';
                 
                 if (isBimestre2) {
                     planningLevel = levelNum + 1; // Finishing II -> Goes to Next Level I
                     planningBimestre = 'I';
                 }
                 
                 const cohortKey = `${stu.career} - ${stu.shift} - Nivel ${planningLevel} - Bimestre ${planningBimestre}`;
                 
                 // Init Student Object
                 // Init Student Object
                 studentsMap[stu.id] = {
                     ...stu,
                     planningLevel,
                     planningBimestre,
                     cohortKey,
                     isGradRisk: false // to be calc
                 };
                 
                 // Init Cohort
                 if (!cohorts[cohortKey]) {
                     cohorts[cohortKey] = {
                         key: cohortKey,
                         career: stu.career,
                         shift: stu.shift,
                         semester: `Nivel ${planningLevel}`,
                         bimestreLabel: `Bimestre ${planningBimestre}`,
                         levelNum: planningLevel,
                         studentCount: 0,
                         subjectsByPeriod: { 0: [], 1: [], 2: [], 3: [], 4: [], 5: [] }
                     };
                 }
                 
                 // Init Semester Map
                 if (!studentsInSem[planningLevel]) studentsInSem[planningLevel] = {};
                 if (!studentsInSem[planningLevel][cohortKey]) studentsInSem[planningLevel][cohortKey] = { count: 0, students: [] };
                 
                 studentsMap[stu.id].cohortKey = cohortKey;
                 // studentsMap already set above
                 cohorts[cohortKey].studentCount++;
                 studentsInSem[planningLevel][cohortKey].count++;
                 // Use name/ID string for aggregation? Or object?
                 // Wait, in Step 5311 I changed this to `${stu.name} (${stu.id})` but in wave process logic.
                 // Here (Line 543) it pushes `studentsMap[stu.id]`.
                 // Let's keep object here for Wave Logic which reads properties from it.
                 studentsInSem[planningLevel][cohortKey].students.push(studentsMap[stu.id]);
            });

            // 1. Initialize Subjects from Backend Master List (to show 0 demand items)
            // Backend now returns { students: [], all_subjects: [] }
            // Check if rawData has this structure, or backward compatible
            
            let students = studentsMap; // Use the processed studentsMap
            
            // Extract all_subjects if available
            let allSubjectsList = [];
            if (rawData.value.all_subjects && Array.isArray(rawData.value.all_subjects)) {
                allSubjectsList = rawData.value.all_subjects;
            } else if (Array.isArray(rawData.value)) {
                // Fallback: old format, empty list? or extract from students? 
                // We'll rely on pending subjects loop.
                allSubjectsList = [];
            }
            
            // Initialize Subjects Map
            // subjectsMap already declared above
            allSubjectsList.forEach(subj => {
                 subjectsMap[subj.name] = {
                     id: subj.id,
                     name: subj.name,
                     semesterNum: parseInt(subj.semester_num) || 0,
                     countP1: 0, countP2: 0, countP3: 0, countP4: 0, countP5: 0, countP6: 0,
                     groupsP1: {}, groupsP2: {}, groupsP3: {}, groupsP4: {}, groupsP5: {}, groupsP6: {}
                 };
            });
            
            // If allSubjectsList was empty (fallback), we build subjects dynamically from pending (like before)
            // But usually we prefer to have the list.

            // 2. Process Demand (P-I) from Pending Subjects
            Object.values(students).forEach(stu => {
                // ... Apply Filters ... (These filters are already applied to 'filtered' array above)
                // if (searchTerm.value && !stu.name.toLowerCase().includes(searchTerm.value.toLowerCase())) return;
                // if (selectedCareer.value !== 'Todas' && stu.career !== selectedCareer.value) return;
                // Note: Filter filteredStudents for Table, but for Aggregate Demand usually we want GLOBAL or FILTERED?
                // React app: "Global Demand" filters apply to the matrix.
                // So applied filters logic IS correct here.
                // if (selectedShift.value !== 'Todas' && stu.shift !== selectedShift.value) return;
                
                stu.pendingSubjects.forEach(subj => {
                    // Only count if Priority (Prereqs Met)?
                    // "La Ola" usually counts next immediate need.
                     // "La Ola" usually counts next immediate need.
                    if (subj.isPriority) {
                         if (!subjectsMap[subj.name]) {
                             // Initialize if not in master list
                             subjectsMap[subj.name] = {
                                 id: subj.id,
                                 name: subj.name,
                                 semesterNum: parseInt(subj.semester) || 0,
                                 countP1: 0, countP2: 0, countP3: 0, countP4: 0, countP5: 0, countP6: 0,
                                 groupsP1: {}, groupsP2: {}, groupsP3: {}, groupsP4: {}, groupsP5: {}, groupsP6: {}
                             };
                         }
                         
                         // Check Deferral of Cohort
                         let deferKey = `${subj.name}_${stu.cohortKey}`;
                         let deferral = deferredGroups[deferKey] || 0; // 0 = P-I
                         
                         let pKey = 'countP' + (deferral + 1);
                         let gKey = 'groupsP' + (deferral + 1);
                         
                         if (subjectsMap[subj.name][pKey] !== undefined) {
                             subjectsMap[subj.name][pKey]++;
                         }
                         
                         if (!subjectsMap[subj.name][gKey][stu.cohortKey]) subjectsMap[subj.name][gKey][stu.cohortKey] = { count: 0, students: [] };
                         subjectsMap[subj.name][gKey][stu.cohortKey].count++;
                         // Store Name and ID for display
                         subjectsMap[subj.name][gKey][stu.cohortKey].students.push(`${stu.name} (${stu.id})`);
                         
                         // Add to Cohort View
                         if (cohorts[stu.cohortKey] && !cohorts[stu.cohortKey].subjectsByPeriod[deferral].includes(subj.name)) {
                             cohorts[stu.cohortKey].subjectsByPeriod[deferral].push(subj.name);
                         }
                    }
                });
            });

            // 3. Process Wave (Future Demand)
            // Recursively check lower levels flowing up?
            // "If you are in level X, you will need level X+1 in P-II"
            const processWave = (targetLevel, subject, periodIdx, groupProp) => {
                 if (studentsInSem[targetLevel]) {
                     Object.entries(studentsInSem[targetLevel]).forEach(([cKey, data]) => {
                         // Check Deferral
                         let deferKey = `${subject.name}_${cKey}`;
                         let actualPeriod = deferredGroups[deferKey] !== undefined ? deferredGroups[deferKey] : periodIdx;
                         
                         // Add Count
                         let pKey = 'countP' + (actualPeriod + 1);
                         if (subject[pKey] !== undefined) subject[pKey] += data.count;
                         
                         let gKey = 'groupsP' + (actualPeriod + 1);
                         if (!subject[gKey][cKey]) subject[gKey][cKey] = { count: 0, students: [] };
                         subject[gKey][cKey].count += data.count;
                         // In wave, we don't have individual student objects readily available in 'data.students' unless we stored them in studentsInSem
                         if (data.students) {
                              subject[gKey][cKey].students.push(...data.students.map(s => `${s.name} (${s.id})`));
                         }

                         // Add to Cohort View
                         if (cohorts[cKey]) {
                             if (!cohorts[cKey].subjectsByPeriod[actualPeriod].includes(subject.name)) {
                                 cohorts[cKey].subjectsByPeriod[actualPeriod].push(subject.name);
                             }
                         }
                     });
                 }
            };
            
            Object.values(subjectsMap).forEach(subj => {
                const lvl = subj.semesterNum;
                if (lvl > 0) {
                     // Wave Logic:
                     // Subject Level L is needed by Cohort at L-1 in Period 1 (Normal flow P-II) -> Wait.
                     // The logic in React:
                     // processWave(lvl - 1, subj, 1, ...); // People currently in L-1 need it in P-II (Index 1)
                     processWave(lvl - 1, subj, 1, 'groupsP2');
                     processWave(lvl - 2, subj, 2, 'groupsP3');
                     processWave(lvl - 3, subj, 3, 'groupsP4');
                     processWave(lvl - 4, subj, 4, 'groupsP5');
                     processWave(lvl - 5, subj, 5, 'groupsP6');
                }
            });

            // 4. Finalize Subjects List
            let subjectsArray = Object.values(subjectsMap).map(s => {
                const manual = manualProjections[s.name] || 0;
                const totalP1 = s.countP1 + manual;
                const isOpen = totalP1 >= 12;
                
                let suggestion = "Abrir P-I";
                let maxDemand = totalP1;
                
                if (s.countP2 > maxDemand) { maxDemand = s.countP2; suggestion = "Esperar P-II"; }
                
                if (totalP1 < 12 && maxDemand < 12) suggestion = "Baja Demanda";
                if (isOpen && !suggestion.includes("Esperar")) suggestion = "ABRIR AHORA";
                
                return { ...s, totalP1, isOpen, suggestion, manual };
            });

            // 5. Build Student Status Lists
            const openSubjectsSet = new Set(subjectsArray.filter(s => s.isOpen).map(s => s.name));
            
            const studentAnalysisList = Object.values(students).map(stu => {
                const priority = stu.pendingSubjects.filter(s => s.isPriority);
                
                // Check if projected to open in P-I (Index 0)
                const projected = priority.filter(s => {
                     // Check if open globally AND not deferred for this cohort
                     let deferKey = `${s.name}_${stu.cohortKey}`;
                     let pIndex = deferredGroups[deferKey] || 0;
                     // If pIndex is 0 and Subject is Open (TotalP1 >= 12)
                     return openSubjectsSet.has(s.name) && pIndex === 0;
                });
                
                const missing = priority.filter(s => !openSubjectsSet.has(s.name)); // Simplified missing logic
                
                let status = 'normal';
                if (projected.length === 0) status = 'critical';
                else if (projected.length === 1) status = 'low';
                else if (projected.length > 3) status = 'overload';
                
                return { ...stu, projectedSubjects: projected, missingSubjects: missing, status, loadCount: projected.length };
            });

            return {
                subjectList: subjectsArray.sort((a,b) => b.totalP1 - a.totalP1),
                cohortViewList: Object.values(cohorts).sort((a,b) => b.studentCount - a.studentCount),
                studentList: studentAnalysisList
            };
        });

        // -- Computed Properties based on Analysis --
        // Deduplicate Periods by Name for the dropdown
        const uniquePeriods = computed(() => {
            const seen = new Set();
            return periods.value.filter(p => {
                const isDuplicate = seen.has(p.name);
                seen.add(p.name);
                return !isDuplicate;
            });
        });

        const careers = computed(() => ['Todas', ...new Set(rawData.value.map(s => s.career))].sort());
        const shifts = computed(() => ['Todas', ...new Set(rawData.value.map(s => s.shift))].sort());
        
        const filteredStudents = computed(() => {
             return analysis.value.studentList.filter(s => {
                 if (searchTerm.value && !s.name.toLowerCase().includes(searchTerm.value.toLowerCase())) return false;
                 if (studentStatusFilter.value === 'Critical' && s.status !== 'critical') return false;
                 if (studentStatusFilter.value === 'GradRisk' && !s.isGradRisk) return false;
                 return true;
             });
        });

        // -- Helpers --
        const toRoman = (num) => {
             const map = {1:'I', 2:'II', 3:'III', 4:'IV', 5:'V', 6:'VI', 7:'VII', 8:'VIII', 9:'IX'};
             return map[num] || num;
        };
        const getPeriodLabel = (idx) => idx === 0 ? 'P-I' : `P-${toRoman(idx+1)}`;
        
        const getSuggestionBadgeClass = (sug) => {
             if (sug.includes('ABRIR')) return 'bg-green-100 text-green-800 px-2 py-1 rounded font-bold text-xs';
             if (sug.includes('Baja')) return 'bg-red-100 text-red-800 px-2 py-1 rounded font-bold text-xs';
             return 'bg-teal-100 text-teal-800 px-2 py-1 rounded font-bold text-xs';
        };
        
        const getSubjectsForCohortPeriod = (cohort, pIdx) => {
            return cohort.subjectsByPeriod[pIdx] || [];
        };
        
        const getSubjectCount = (subjName, pIdx, cohortKey) => {
            // Find count in analysis structure logic... tricky to verify without full map exposure.
            // But we have analysis computed.
            // Simplified:
            // We need to access 'analysis.value.subjectList' find subject -> find groupP(X) -> count.
            // Ideally we shouldn't scan loops in render.
            // BUT for cohort view we already have the list.
            // Let's pass 'count' via data attribute? No.
            // We can infer it if we had the Subject Map exposed.
            // For now, return 0 or implement optimization if slow.
            return 0; 
        };

        // -- Drag Drop --
        const handleDragStart = (e, subj, cKey, pIdx) => {
            e.dataTransfer.setData('auth', JSON.stringify({subj, cKey, pIdx}));
        };
        const handleDrop = (e, targetCKey, targetPIdx) => {
            const data = JSON.parse(e.dataTransfer.getData('auth'));
            if (data.cKey === targetCKey && data.pIdx !== targetPIdx) {
                 // Update Deferral
                 let key = `${data.subj}_${targetCKey}`;
                 deferredGroups[key] = targetPIdx;
            }
        };

        // -- Popover --
        const openPopover = (subj, idx, e) => {
            // Build data slice
            let pKey = 'groupsP' + (idx+1);
            activePopover.value = {
                subject: subj,
                periodIndex: idx,
                data: subj[pKey] || {}
            };
        };

        onMounted(() => {
            loadInitial();
        });

        return {
            loading, selectedPeriodId, periods, uniquePeriods, reloadData, analysis,
            // Filters
            selectedCareer, selectedShift, careers, shifts,
            activeTab,
            // Tables
            manualProjections, filteredStudents, studentStatusFilter, searchTerm,
            // UI Helpers
            toRoman, getPeriodLabel, getSuggestionBadgeClass, 
            getSubjectsForCohortPeriod, getSubjectCount,
            // Drag
            handleDragStart, handleDrop, deferredGroups,
            // Popover
            openPopover, activePopover
        };
    }
}).mount('#app');
</script>

<?php
echo $OUTPUT->footer();
