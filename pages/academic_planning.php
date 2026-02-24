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

<!-- FullCalendar -->
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js"></script>

<!-- PDF Export Libs -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>

<!-- Excel Export Lib -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<!-- Scheduler Module Scripts -->
<script src="../js/utils/scheduler_algorithm.js?v=<?= time() ?>"></script>
<script src="../js/utils/pdfExport.js?v=<?= time() ?>"></script>
<script src="../js/stores/schedulerStore.js?v=<?= time() ?>"></script>

<script src="../js/components/scheduler/projections_modal.js?v=<?= time() ?>"></script>
<script src="../js/components/scheduler/demand_view.js?v=<?= time() ?>"></script>
<script src="../js/components/scheduler/planning_board.js?v=<?= time() ?>"></script>
<script src="../js/components/scheduler/period_grouped_view.js?v=<?= time() ?>"></script>
<script src="../js/components/scheduler/scheduler_view.js?v=<?= time() ?>"></script>
<script src="../js/components/scheduler/report_view.js?v=<?= time() ?>"></script>
<script src="../js/components/scheduler/classroom_manager.js?v=<?= time() ?>"></script>
<script src="../js/components/scheduler/holiday_manager.js?v=<?= time() ?>"></script>
<script src="../js/components/scheduler/general_config.js?v=<?= time() ?>"></script>
<script src="../js/components/scheduler/full_calendar_view.js?v=<?= time() ?>"></script>

<style>
    .fade-enter-active, .fade-leave-active { transition: opacity 0.3s ease; }
    .fade-enter-from, .fade-leave-to { opacity: 0; }
    .slide-enter-active, .slide-leave-active { transition: transform 0.3s ease; }
    .slide-enter-from, .slide-leave-to { transform: translateY(10px); opacity: 0; }
    
    /* v-cloak to hide uncompiled template */
    [v-cloak] { display: none !important; }

    /* Scrollbar */
    ::-webkit-scrollbar { width: 8px; height: 8px; }
    ::-webkit-scrollbar-track { background: #f1f5f9; }
    ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
    ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
</style>

<div id="academic-planning-root" v-cloak class="bg-slate-50 min-h-screen p-4 font-sans text-slate-800">
    
    <!-- HEADER -->
    <header class="mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900 flex items-center gap-2">
                <i data-lucide="layers" class="text-blue-600"></i>
                Planificador de Oferta Académica
            </h1>
            <p class="text-slate-500 text-sm">
                 Proyección de Olas & Análisis de Impacto (Regla &ge; 12) <span class="text-[10px] bg-slate-200 px-1 rounded ml-2">v1.3-Debug</span>
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

    <!-- APP CONTENT -->
    <div v-else>
        
        <!-- MAIN VIEW (Requires Analysis) -->
        <div v-if="analysis">
        
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
            <button @click="activeTab = 'population'" :class="['px-4 py-3 text-sm font-bold flex items-center gap-2 border-b-2 whitespace-nowrap transition-colors', activeTab === 'population' ? 'border-pink-600 text-pink-700' : 'border-transparent text-slate-500 hover:text-slate-700']">
                <i data-lucide="users" class="w-4 h-4"></i> Población
            </button>
            <button @click="activeTab = 'students'" :class="['px-4 py-3 text-sm font-bold flex items-center gap-2 border-b-2 whitespace-nowrap transition-colors', activeTab === 'students' ? 'border-purple-600 text-purple-700' : 'border-transparent text-slate-500 hover:text-slate-700']">
                <i data-lucide="graduation-cap" class="w-4 h-4"></i> Impacto & Graduandos
            </button>
            <button @click="activeTab = 'search'" :class="['px-4 py-3 text-sm font-bold flex items-center gap-2 border-b-2 whitespace-nowrap transition-colors', activeTab === 'search' ? 'border-sky-600 text-sky-700' : 'border-transparent text-slate-500 hover:text-slate-700']">
                <i data-lucide="search" class="w-4 h-4"></i> Búsqueda Global
            </button>
            <button @click="activeTab = 'config'" :class="['px-4 py-3 text-sm font-bold flex items-center gap-2 border-b-2 whitespace-nowrap transition-colors', activeTab === 'config' ? 'border-slate-800 text-slate-900 font-extrabold' : 'border-transparent text-slate-500 hover:text-slate-700']">
                <i data-lucide="settings" class="w-4 h-4"></i> Configuración de Periodos
            </button>
            <button @click="activeTab = 'scheduler'" :class="['px-4 py-3 text-sm font-bold flex items-center gap-2 border-b-2 whitespace-nowrap transition-colors', activeTab === 'scheduler' ? 'border-teal-600 text-teal-700' : 'border-transparent text-slate-500 hover:text-slate-700']">
                <i data-lucide="calendar-clock" class="w-4 h-4"></i> Horarios
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
                        <span><i data-lucide="calendar" class="w-4 h-4 text-blue-500"></i></span> Matriz de Proyección
                    </h3>
                    <div class="flex items-center gap-3">
                         <label class="flex items-center gap-1.5 cursor-pointer bg-white px-2 py-1 rounded border border-slate-200 shadow-sm hover:bg-slate-50 transition-colors">
                             <input type="checkbox" v-model="isOrderLocked" class="w-3.5 h-3.5 text-blue-600 rounded focus:ring-blue-500" />
                             <span class="text-[10px] font-bold text-slate-600 flex items-center gap-1 uppercase">
                                 <i data-lucide="lock" class="w-3 h-3 text-slate-400"></i> Bloquear Orden
                             </span>
                         </label>
                         <a href="debug_student_data.php" target="_blank" class="px-2 py-1 bg-amber-50 text-amber-700 rounded border border-amber-200 text-[10px] font-bold uppercase transition-all hover:bg-amber-100 flex items-center gap-1">
                            <span><i data-lucide="alert-circle" class="w-3 h-3 text-amber-500"></i></span>
                            Limpiar "Sin Definir"
                         </a>
                         <span class="px-2 py-1 bg-yellow-50 text-yellow-700 rounded border border-yellow-200 text-xs">Editable: Nuevos Ingresos</span>
                         <button @click="savePlanning" :disabled="saving" class="px-4 py-1.5 bg-blue-600 hover:bg-blue-700 disabled:bg-slate-300 text-white rounded-lg text-xs font-bold flex items-center gap-2 transition-all shadow-md">
                            <span v-if="saving" key="saving"><i data-lucide="loader-2" class="w-3.5 h-3.5 animate-spin"></i></span>
                            <span v-else key="save"><i data-lucide="save" class="w-3.5 h-3.5"></i></span>
                            Guardar Proyecciones
                        </button>
                    </div>
                </div>
                <!-- TABLE SCROLL -->
                <div class="overflow-x-auto max-h-[600px]">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-slate-500 uppercase bg-slate-50 sticky top-0 z-10 shadow-sm">
                            <tr>
                                <th class="px-4 py-3 bg-slate-50 min-w-[200px]">Asignatura</th>
                                <th class="px-2 py-3 bg-slate-50 text-center">Nivel</th>
                                <th v-for="period in analysis.sortedEntryPeriods" :key="period" class="px-1 py-3 bg-slate-100 text-[10px] text-center w-12 border-l border-slate-200">
                                    {{ period }}
                                </th>
                                <th class="px-2 py-3 bg-slate-50 text-center w-20">Nuevos<br/>(Man)</th>
                                <th class="px-2 py-3 bg-blue-50 text-blue-900 text-center border-l border-blue-100 min-w-[120px]">
                                    <div class="flex flex-col items-center gap-1">
                                        <span class="whitespace-nowrap">P-I (Próximo)</span>
                                        <select v-model.number="periodMappings[0]" class="w-full text-xs font-normal bg-white border border-blue-200 rounded px-1 py-0.5 outline-none max-w-[110px]">
                                            <option :value="undefined">Automático</option>
                                            <option v-for="p in uniquePeriods" :key="p.id" :value="p.id">{{ p.name }}</option>
                                        </select>
                                    </div>
                                </th>
                                <th v-for="i in 5" :key="i" class="px-2 py-3 text-slate-500 text-center border-l border-slate-100 min-w-[120px]">
                                    <div class="flex flex-col items-center gap-1">
                                        <span class="whitespace-nowrap">{{ getPeriodLabel(i) }}</span>
                                        <select v-model.number="periodMappings[i]" class="w-full text-[10px] font-normal bg-white border border-slate-200 rounded px-1 outline-none text-slate-600 max-w-[110px]">
                                            <option :value="undefined">Automático</option>
                                            <option v-for="p in uniquePeriods" :key="p.id" :value="p.id">{{ p.name }}</option>
                                        </select>
                                    </div>
                                </th>
                                <th class="px-2 py-3 bg-slate-50 text-center w-16">Omitir<br/>Auto</th>
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
                                <td v-for="period in analysis.sortedEntryPeriods" :key="period" :class="['px-1 py-1 text-center text-[11px] border-l border-slate-100', subj.entryPeriodCounts[period] > 0 ? 'bg-blue-50/30' : 'text-slate-300']">
                                    <button v-if="subj.entryPeriodCounts[period] > 0" 
                                            @click="openPopover(subj, 0, $event, period)" 
                                            class="w-full h-full font-bold text-blue-600 hover:bg-blue-100 rounded transition-colors">
                                        {{ subj.entryPeriodCounts[period] }}
                                    </button>
                                    <span v-else>-</span>
                                </td>
                                <td class="px-2 py-2 text-center">
                                    <input type="number" min="0" class="w-12 p-1 text-center text-sm border border-slate-300 rounded focus:ring-2 focus:ring-blue-500 outline-none bg-white font-mono"
                                           :value="manualProjections[subj.name]" 
                                           @input="updateProjection(subj.name, $event.target.value)" 
                                           placeholder="0">
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
                                    <input type="checkbox" v-model="ignoredSubjects[subj.name]" class="w-4 h-4 text-blue-600 border-slate-300 rounded focus:ring-blue-500 cursor-pointer" />
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

          <!-- TAB 1.2: POPULATION -->
          <div v-show="activeTab === 'population'" class="space-y-6">
              <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 border-l-4 border-l-pink-500">
                      <p class="text-slate-500 text-xs font-bold uppercase">Población Total</p>
                      <h3 class="text-2xl font-bold text-slate-800">{{ analysis.totalStudents }}</h3>
                      <p class="text-xs text-pink-600">Estudiantes Activos</p>
                  </div>
                  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 border-l-4 border-l-indigo-500">
                      <p class="text-slate-500 text-xs font-bold uppercase">Carreras Activas</p>
                      <h3 class="text-2xl font-bold text-slate-800">{{ Object.keys(analysis.populationTree).length }}</h3>
                  </div>
                  <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 border-l-4 border-l-purple-500">
                      <p class="text-slate-500 text-xs font-bold uppercase">Generaciones (Ingresos)</p>
                      <h3 class="text-2xl font-bold text-slate-800">{{ analysis.sortedEntryPeriods.length }}</h3>
                  </div>
              </div>

              <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                  <div class="p-4 border-b border-slate-100 bg-slate-50">
                      <h4 class="font-bold text-slate-700 text-sm flex items-center gap-2">
                          <i data-lucide="layers" class="text-pink-500"></i>
                          Desglose Jerárquico de Población
                      </h4>
                  </div>
                  <div class="overflow-x-auto">
                      <table class="w-full text-left text-sm">
                          <thead class="bg-white text-slate-500 border-b border-slate-100">
                              <tr>
                                  <th class="p-3">Nivel / Detalle</th>
                                  <th class="p-3 text-right">Cantidad</th>
                                  <th class="p-3 text-right text-xs">% Total</th>
                              </tr>
                          </thead>
                          <tbody class="divide-y divide-slate-50">
                              <template v-for="(data, career) in analysis.populationTree" :key="career">
                                  <tr class="cursor-pointer hover:bg-pink-50/30 transition-colors" :class="{'bg-pink-50/50': expandedCareer === career}" @click="toggleCareer(career)">
                                      <td class="p-3 font-bold text-slate-700 flex items-center gap-2">
                                          <span v-if="expandedCareer === career" key="exp"><i data-lucide="chevron-down" class="w-4 h-4 text-pink-500"></i></span>
                                          <span v-else key="coll"><i data-lucide="chevron-right" class="w-4 h-4 text-slate-400"></i></span>
                                          {{ career }}
                                      </td>
                                      <td class="p-3 text-right font-bold text-pink-700">{{ data.count }}</td>
                                      <td class="p-3 text-right text-xs text-slate-400">
                                          {{ ((data.count / analysis.totalStudents) * 100).toFixed(1) }}%
                                      </td>
                                  </tr>
                                  
                                  <template v-if="expandedCareer === career">
                                      <template v-for="(pData, period) in data.periods" :key="period">
                                          <tr class="bg-slate-50/50 cursor-pointer hover:bg-slate-100 transition-colors" @click.stop="togglePeriod(period)">
                                              <td class="p-2 pl-8 text-slate-600 text-xs font-medium flex items-center gap-2">
                                                  <span v-if="expandedPeriod === period" key="exp-p"><i data-lucide="chevron-down" class="w-3 h-3 text-indigo-500"></i></span>
                                                  <span v-else key="coll-p"><i data-lucide="chevron-right" class="w-3 h-3 text-slate-400"></i></span>
                                                  {{ period }}
                                              </td>
                                              <td class="p-2 text-right text-xs font-medium text-slate-600">{{ pData.count }}</td>
                                              <td class="p-2 text-right text-[10px] text-slate-400">
                                                  {{ ((pData.count / data.count) * 100).toFixed(1) }}% (Car)
                                              </td>
                                          </tr>
                                          
                                          <tr v-if="expandedPeriod === period" v-for="grp in pData.groups" :key="grp.key" class="bg-slate-100/50 hover:bg-slate-100">
                                              <td class="p-2 pl-14 text-slate-500 text-[11px] flex items-center gap-2">
                                                  <span class="bg-slate-200 text-slate-600 px-1.5 py-0.5 rounded text-[9px] font-bold uppercase">Grupo</span>
                                                  {{ grp.key }}
                                              </td>
                                              <td class="p-2 text-right text-[11px] font-mono text-slate-500">{{ grp.count }}</td>
                                              <td class="p-2 text-right text-[10px] text-slate-300">-</td>
                                          </tr>
                                      </template>
                                  </template>
                              </template>
                          </tbody>
                      </table>
                  </div>
              </div>
          </div>

          <!-- TAB 2: SEARCH -->
          <div v-show="activeTab === 'search'" class="space-y-6">
              <div class="flex flex-col items-center justify-center py-10 bg-white rounded-xl border border-slate-200 shadow-sm">
                  <div class="bg-sky-50 p-4 rounded-full mb-4"><span><i data-lucide="search" class="w-8 h-8 text-sky-500"></i></span></div>
                  <h3 class="text-lg font-bold text-slate-800 mb-2">Buscador de Estudiantes</h3>
                  <div class="flex gap-2 w-full max-w-md px-4">
                      <input
                          type="text"
                          placeholder="Ingrese Nombre o Cédula..."
                          class="flex-1 p-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-sky-500 outline-none text-sm"
                          v-model="studentSearchQuery"
                          @keydown.enter="handleStudentSearch"
                      />
                      <button @click="handleStudentSearch" class="px-4 py-2 bg-sky-600 hover:bg-sky-700 text-white rounded-lg font-bold text-sm">Buscar</button>
                  </div>
              </div>

              <div v-if="searchedStudent" class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 border-t-4 border-t-sky-500 animate-in fade-in duration-300">
                  <div class="flex flex-col md:flex-row justify-between items-start mb-6 border-b border-slate-100 pb-4 gap-4">
                      <div>
                          <h2 class="text-2xl font-bold text-slate-800">{{ searchedStudent.name }}</h2>
                          <p class="text-slate-500 font-mono text-sm">{{ searchedStudent.id || searchedStudent.dbId }}</p>
                          <div class="flex flex-wrap gap-2 mt-2">
                              <span class="bg-blue-100 text-blue-800 px-2 py-0.5 rounded-full text-xs font-bold">{{ searchedStudent.career }}</span>
                              <span class="bg-slate-100 text-slate-600 px-2 py-0.5 rounded-full text-xs font-bold">{{ searchedStudent.shift }}</span>
                              <span class="bg-purple-100 text-purple-800 px-2 py-0.5 rounded-full text-xs font-bold">Nivel {{ searchedStudent.currentSemConfig }}</span>
                              <span class="bg-indigo-100 text-indigo-800 px-2 py-0.5 rounded-full text-xs font-bold">Ingreso: {{ searchedStudent.entry_period || 'Sin Definir' }}</span>
                          </div>
                      </div>
                      <button @click="handleExportStudentSchedule" class="flex items-center gap-2 px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-bold shadow-sm transition-colors text-sm">
                          <span><i data-lucide="download" class="w-4 h-4"></i></span> Exportar Info
                      </button>
                  </div>

                  <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                      <!-- PENDING SUBJECTS -->
                      <div>
                          <h4 class="font-bold text-slate-700 text-sm mb-3 flex items-center gap-2">
                              <span><i data-lucide="book-open" class="text-sky-500 w-4 h-4"></i></span>
                              Materias Pendientes (Prioridad)
                          </h4>
                          <div class="space-y-2">
                               <div v-for="(subj, sIdx) in searchedStudent.pendingSubjects.filter(s => s.isPriority)" :key="sIdx" class="p-3 bg-slate-50 rounded border border-slate-200 flex justify-between items-center">
                                  <span class="text-sm text-slate-700 font-medium">{{ subj.name }}</span>
                                  <span class="text-[10px] bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded font-bold uppercase">Nivel {{ subj.semester }}</span>
                              </div>
                              <p v-if="searchedStudent.pendingSubjects.length === 0" class="text-sm text-slate-400 italic">No hay materias pendientes.</p>
                          </div>
                      </div>

                      <!-- CONTEXT INFO -->
                      <div class="bg-slate-50 p-4 rounded-lg border border-slate-200">
                          <h4 class="font-bold text-slate-700 text-sm mb-3">Información Académica</h4>
                          <div class="space-y-3">
                              <div class="flex justify-between border-b border-slate-200 pb-2">
                                  <span class="text-xs text-slate-500">Plan de Estudios</span>
                                  <span class="text-xs font-bold text-slate-700">{{ searchedStudent.career }}</span>
                              </div>
                              <div class="flex justify-between border-b border-slate-200 pb-2">
                                  <span class="text-xs text-slate-500">Periodo Actual</span>
                                  <span class="text-xs font-bold text-slate-700">{{ searchedStudent.currentSemConfig }}</span>
                              </div>
                              <div class="flex justify-between border-b border-slate-200 pb-2">
                                  <span class="text-xs text-slate-500">Bimestre Actual</span>
                                  <span class="text-xs font-bold text-slate-700">{{ searchedStudent.currentSubperiodConfig }}</span>
                              </div>
                              <div class="flex justify-between">
                                  <span class="text-xs text-slate-500">Estado de Carga (P-I)</span>
                                  <span :class="['text-xs font-bold', searchedStudent.loadCount > 0 ? 'text-green-600' : 'text-red-500']">
                                      {{ searchedStudent.loadCount }} Materias Asignadas
                                  </span>
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
                 <span><i data-lucide="alert-triangle" class="text-red-600 mt-1 w-5 h-5"></i></span>
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
                    <span><i data-lucide="search" class="absolute left-3 top-2.5 text-slate-400 w-4 h-4"></i></span>
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
                                            <span><i data-lucide="check-circle" class="w-3 h-3 text-green-500 mt-0.5 shrink-0"></i></span>
                                            <span>{{ s.name }}</span>
                                        </li>
                                    </ul>
                                    <span v-else class="text-xs text-slate-400 italic">Nada para ver</span>
                                </td>
                                <td class="p-4 align-top bg-red-50/30 border-l border-red-50">
                                    <ul v-if="student.missingSubjects.length > 0" class="space-y-1">
                                        <li v-for="s in student.missingSubjects" :key="s.name" class="flex items-start gap-1.5 text-xs text-red-700/80">
                                            <span><i data-lucide="lock" class="w-3 h-3 text-red-400 mt-0.5 shrink-0"></i></span>
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


          <!-- TAB 5: CONFIGURATION (CRUD) -->
          <div v-show="activeTab === 'config'" class="space-y-6">
              <!-- Sub-tabs for Config -->
              <div class="flex gap-4 border-b border-slate-200 mb-6 overflow-x-auto">
                  <button @click="configSubTab = 'periods'" :class="['px-4 py-2 text-xs font-bold border-b-2 transition-colors whitespace-nowrap', configSubTab === 'periods' ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-700']">Periodos Académicos</button>
                  <button @click="configSubTab = 'classrooms'" :class="['px-4 py-2 text-xs font-bold border-b-2 transition-colors whitespace-nowrap', configSubTab === 'classrooms' ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-700']">Gestión de Aulas</button>
                  <button @click="configSubTab = 'holidays'" :class="['px-4 py-2 text-xs font-bold border-b-2 transition-colors whitespace-nowrap', configSubTab === 'holidays' ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-700']">Festivos</button>
                  <button @click="configSubTab = 'loads'" :class="['px-4 py-2 text-xs font-bold border-b-2 transition-colors whitespace-nowrap', configSubTab === 'loads' ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-700']">Cargas (Excel)</button>
                  <button @click="configSubTab = 'general'" :class="['px-4 py-2 text-xs font-bold border-b-2 transition-colors whitespace-nowrap', configSubTab === 'general' ? 'border-orange-600 text-orange-700' : 'border-transparent text-slate-500 hover:text-slate-700']">Configuración General</button>
              </div>

              <!-- Content for Config Sub-tabs -->
              <div v-if="configSubTab === 'periods'">
                  <div class="flex justify-between items-center mb-4">
                      <h3 class="text-xl font-bold text-slate-800">Administración de Periodos Institucionales</h3>
                      <button @click="openPeriodModal()" class="flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors text-sm font-bold shadow-md">
                          <i data-lucide="plus" class="w-4 h-4"></i> Nuevo Periodo
                      </button>
                  </div>

                  <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
                      <table class="w-full text-left border-collapse">
                          <thead>
                              <tr class="bg-slate-50 text-slate-600 text-xs uppercase tracking-wider font-bold">
                                  <th class="p-4 border-b">Nombre del Periodo</th>
                                  <th class="p-4 border-b">Fecha Inicio</th>
                                  <th class="p-4 border-b">Fecha Fin</th>
                                  <th class="p-4 border-b">Planes Vinculados</th>
                                  <th class="p-4 border-b text-center">Estado</th>
                                  <th class="p-4 border-b text-right">Acciones</th>
                              </tr>
                          </thead>
                          <tbody class="divide-y divide-slate-100 text-sm">
                              <tr v-for="p in academicPeriods" :key="p.id" class="hover:bg-slate-50 transition-colors">
                                  <td class="p-4 font-bold text-slate-700">{{ p.name }}</td>
                                  <td class="p-4">{{ formatDate(p.startdate) }}</td>
                                  <td class="p-4">{{ formatDate(p.enddate) }}</td>
                                  <td class="p-4">
                                      <div class="flex flex-wrap gap-1">
                                          <span v-for="lpid in p.learningplans" :key="lpid" class="bg-slate-100 text-slate-600 px-2 py-0.5 rounded text-[10px] font-medium border border-slate-200">
                                              {{ getPlanName(lpid) }}
                                          </span>
                                          <span v-if="!p.learningplans || p.learningplans.length === 0" class="text-slate-400 italic text-xs">Sin planes vinculados</span>
                                      </div>
                                  </td>
                                  <td class="p-4 text-center">
                                      <span v-if="p.status == 1" class="bg-green-100 text-green-800 px-2 py-0.5 rounded-full text-xs font-bold">Activo</span>
                                      <span v-else class="bg-slate-100 text-slate-500 px-2 py-0.5 rounded-full text-xs font-bold">Cerrado</span>
                                  </td>
                                   <td class="p-4 text-right flex justify-end gap-2">
                                       <button @click="openPeriodModal(p)" class="p-1.5 text-blue-600 hover:bg-blue-50 rounded transition-colors" title="Editar">
                                           <i data-lucide="edit-3" class="w-4 h-4"></i>
                                       </button>
                                       <button @click="deletePeriod(p)" class="p-1.5 text-red-600 hover:bg-red-50 rounded transition-colors" title="Eliminar">
                                           <i data-lucide="trash-2" class="w-4 h-4"></i>
                                       </button>
                                   </td>
                              </tr>
                          </tbody>
                      </table>
                  </div>
              </div>

              <div v-if="configSubTab === 'classrooms'">
                  <classroom-manager></classroom-manager>
              </div>

              <div v-if="configSubTab === 'holidays'">
                  <holiday-manager :period-id="selectedPeriodId"></holiday-manager>
              </div>

              <div v-if="configSubTab === 'loads'">
                  <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6">
                      <h3 class="text-xl font-bold text-slate-800 mb-4 flex items-center gap-2">
                          <i data-lucide="upload-cloud" class="text-blue-500"></i>
                          Carga Masiva de Horas (Excel)
                      </h3>
                      <p class="text-sm text-slate-500 mb-6 font-bold">
                          Suba un archivo Excel con columnas que contengan: <span class="bg-slate-100 px-1 rounded">Asignatura</span>, <span class="bg-slate-100 px-1 rounded">Carga Horaria / Horas</span> e <span class="bg-slate-100 px-1 rounded">Intensidad</span>.
                      </p>
                      
                      <!-- Upload Result Banner -->
                      <div v-if="loadUploadMessage" class="mb-4 px-4 py-2.5 text-sm font-medium rounded-lg flex items-center justify-between" :class="loadUploadError ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200'">
                          <span>{{ loadUploadMessage }}</span>
                          <button @click="loadUploadMessage = ''" class="ml-4 opacity-60 hover:opacity-100 text-lg">&times;</button>
                      </div>

                      <div class="flex flex-col items-center justify-center border-2 border-dashed border-slate-300 rounded-xl p-10 hover:bg-slate-50 transition-colors cursor-pointer" @click="$refs.loadExcelInput.click()">
                          <i data-lucide="file-spreadsheet" class="w-12 h-12 text-slate-400 mb-4"></i>
                          <p class="text-slate-600 font-bold">Haga clic o arrastre su archivo Excel aquí</p>
                          <p class="text-xs text-slate-400 mt-2">Formatos aceptados: .xlsx, .xls</p>
                          <input type="file" ref="loadExcelInput" class="hidden" accept=".xlsx, .xls" @change="handleLoadUpload">
                      </div>

                      <div v-if="store.state.context.loads && store.state.context.loads.length > 0" class="mt-8">
                          <h4 class="font-bold text-slate-700 mb-2">Cargas Cargadas ({{ store.state.context.loads.length }})</h4>
                          <div class="max-h-60 overflow-y-auto border border-slate-100 rounded">
                              <table class="w-full text-xs text-left">
                                  <thead class="bg-slate-50 sticky top-0 font-bold uppercase text-slate-500">
                                      <tr>
                                          <th class="p-2 border-b">Asignatura</th>
                                          <th class="p-2 border-b text-center">Total Horas</th>
                                          <th class="p-2 border-b text-center">Intensidad</th>
                                      </tr>
                                  </thead>
                                  <tbody class="divide-y divide-slate-100">
                                      <tr v-for="l in store.state.context.loads" :key="l.subjectName || l.subjectname" class="hover:bg-slate-50">
                                          <td class="p-2">{{ l.subjectName || l.subjectname }}</td>
                                          <td class="p-2 text-center">{{ l.totalHours || l.total_hours || '-' }}</td>
                                          <td class="p-2 text-center">{{ l.intensity || '-' }}</td>
                                      </tr>
                                  </tbody>
                              </table>
                          </div>
                      </div>
                  </div>
              </div>

              <!-- General Configuration (Shifts, Intervals, Lunch) -->
              <div v-if="configSubTab === 'general'">
                  <div v-if="!selectedPeriodId" class="flex flex-col items-center justify-center py-20 bg-white rounded-xl border border-slate-200 text-slate-400">
                      <i data-lucide="calendar" class="w-16 h-16 mb-4 opacity-50"></i>
                      <p class="text-lg font-medium">Seleccione un periodo arriba para configurar</p>
                  </div>
                  <general-config v-else :period-id="selectedPeriodId"></general-config>
              </div>
          </div>

          <!-- TAB 6: SCHEDULER MODULE -->
          <div v-if="activeTab === 'scheduler'" class="space-y-6">
              <scheduler-view></scheduler-view>
          </div>
        </div> <!-- End of analysis wrapper -->
    </div> <!-- End of loading=false wrapper -->

    <!-- MODALS (Stay inside root, outside layout containers) -->

     <!-- PERIOD FORM MODAL -->
     <div v-if="showPeriodForm" style="display: none;" :style="{ display: showPeriodForm ? 'flex' : 'none' }" class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm" @click.self="showPeriodForm = false">
         <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden animate-in zoom-in-95 duration-200">
             <div class="p-4 border-b border-slate-200 bg-slate-50 flex justify-between items-center">
                 <h4 class="font-bold text-slate-800">{{ editingPeriod.id ? 'Editar' : 'Crear' }} Periodo Académico</h4>
                 <button @click="showPeriodForm = false" class="p-1 hover:bg-slate-200 rounded-full transition-colors"><i data-lucide="x" class="w-5 h-5 text-slate-400"></i></button>
             </div>
             
             <div class="p-6 space-y-6 max-h-[70vh] overflow-y-auto">
                 <div class="space-y-4">
                     <h5 class="text-sm font-bold text-blue-600 border-b pb-1 uppercase tracking-wider">Información Básica</h5>
                     <div>
                         <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Nombre del Periodo</label>
                         <input type="text" v-model="editingPeriod.name" placeholder="Ej: 2026-I" class="w-full px-3 py-1.5 border rounded focus:ring-2 focus:ring-blue-500 outline-none text-sm" />
                     </div>
                     <div class="grid grid-cols-2 gap-4">
                         <div>
                             <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Fecha de Inicio</label>
                             <input type="date" v-model="editingPeriod.startdate_raw" class="w-full px-3 py-1.5 border rounded focus:ring-2 focus:ring-blue-500 outline-none text-sm" />
                         </div>
                         <div>
                             <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Fecha de Fin</label>
                             <input type="date" v-model="editingPeriod.enddate_raw" class="w-full px-3 py-1.5 border rounded focus:ring-2 focus:ring-blue-500 outline-none text-sm" />
                         </div>
                     </div>
                     <div class="grid grid-cols-2 gap-4">
                         <div>
                             <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Hito: Inducción</label>
                             <input type="date" v-model="editingPeriod.induction_raw" class="w-full px-3 py-1.5 border rounded focus:ring-2 focus:ring-blue-500 outline-none text-sm" />
                         </div>
                         <div>
                             <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Hito: Graduación</label>
                             <input type="date" v-model="editingPeriod.graduation_raw" class="w-full px-3 py-1.5 border rounded focus:ring-2 focus:ring-blue-500 outline-none text-sm" />
                         </div>
                     </div>
                 </div>

                 <div class="space-y-4">
                     <h5 class="text-sm font-bold text-indigo-600 border-b pb-1 uppercase tracking-wider">Bloques Académicos (P-I & P-II)</h5>
                     <div class="grid grid-cols-2 gap-4 bg-indigo-50/50 p-2 rounded">
                         <div>
                             <label class="block text-[10px] font-bold text-indigo-500 uppercase mb-1">P-I (Inicio)</label>
                             <input type="date" v-model="editingPeriod.block1start_raw" class="w-full px-3 py-1.5 border rounded focus:ring-2 focus:ring-indigo-500 outline-none text-sm" />
                         </div>
                         <div>
                             <label class="block text-[10px] font-bold text-indigo-500 uppercase mb-1">P-I (Fin)</label>
                             <input type="date" v-model="editingPeriod.block1end_raw" class="w-full px-3 py-1.5 border rounded focus:ring-2 focus:ring-indigo-500 outline-none text-sm" />
                         </div>
                     </div>
                     <div class="grid grid-cols-2 gap-4 bg-indigo-50/50 p-2 rounded">
                         <div>
                             <label class="block text-[10px] font-bold text-indigo-500 uppercase mb-1">P-II (Inicio)</label>
                             <input type="date" v-model="editingPeriod.block2start_raw" class="w-full px-3 py-1.5 border rounded focus:ring-2 focus:ring-indigo-500 outline-none text-sm" />
                         </div>
                         <div>
                             <label class="block text-[10px] font-bold text-indigo-500 uppercase mb-1">P-II (Fin)</label>
                             <input type="date" v-model="editingPeriod.block2end_raw" class="w-full px-3 py-1.5 border rounded focus:ring-2 focus:ring-indigo-500 outline-none text-sm" />
                         </div>
                     </div>
                 </div>

                 <div class="space-y-4">
                     <h5 class="text-sm font-bold text-orange-600 border-b pb-1 uppercase tracking-wider">Exámenes & Matrículas</h5>
                     <div class="grid grid-cols-2 gap-4">
                         <div>
                             <label class="block text-[10px] font-bold text-orange-500 uppercase mb-1">Exámen Final (Desde)</label>
                             <input type="date" v-model="editingPeriod.finalexamfrom_raw" class="w-full px-3 py-1.5 border rounded focus:ring-2 focus:ring-orange-500 outline-none text-sm" />
                         </div>
                         <div>
                             <label class="block text-[10px] font-bold text-orange-500 uppercase mb-1">Exámen Final (Hasta)</label>
                             <input type="date" v-model="editingPeriod.finalexamuntil_raw" class="w-full px-3 py-1.5 border rounded focus:ring-2 focus:ring-orange-500 outline-none text-sm" />
                         </div>
                     </div>
                     <div class="grid grid-cols-2 gap-4 bg-orange-50/30 p-2 rounded">
                         <div>
                             <label class="block text-[10px] font-bold text-orange-600 uppercase mb-1">Matrículas (Desde)</label>
                             <input type="date" v-model="editingPeriod.registrationsfrom_raw" class="w-full px-3 py-1.5 border rounded focus:ring-2 focus:ring-orange-400 outline-none text-sm" />
                         </div>
                         <div>
                             <label class="block text-[10px] font-bold text-orange-600 uppercase mb-1">Matrículas (Hasta)</label>
                             <input type="date" v-model="editingPeriod.registrationsuntil_raw" class="w-full px-3 py-1.5 border rounded focus:ring-2 focus:ring-orange-400 outline-none text-sm" />
                         </div>
                     </div>
                     <div>
                         <label class="block text-[10px] font-bold text-orange-500 uppercase mb-1">Carga de Notas y Cierre</label>
                         <input type="date" v-model="editingPeriod.loadnotes_raw" class="w-full px-3 py-1.5 border rounded focus:ring-2 focus:ring-orange-500 outline-none text-sm" />
                     </div>
                 </div>

                 <div class="space-y-4">
                     <h5 class="text-sm font-bold text-emerald-600 border-b pb-1 uppercase tracking-wider">Procesos de Reválidas</h5>
                     <div class="grid grid-cols-2 gap-4">
                         <div>
                             <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Entrega Listas Rev.</label>
                             <input type="date" v-model="editingPeriod.delivlist_raw" class="w-full px-3 py-1.5 border rounded outline-none text-sm" />
                         </div>
                         <div>
                             <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Notif. Estudiantes</label>
                             <input type="date" v-model="editingPeriod.notifreval_raw" class="w-full px-3 py-1.5 border rounded outline-none text-sm" />
                         </div>
                     </div>
                     <div class="grid grid-cols-2 gap-4">
                         <div>
                             <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Plazo de Pago</label>
                             <input type="date" v-model="editingPeriod.deadlinereval_raw" class="w-full px-3 py-1.5 border rounded outline-none text-sm" />
                         </div>
                         <div>
                             <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">Proceso / Ejecución</label>
                             <input type="date" v-model="editingPeriod.revalprocess_raw" class="w-full px-3 py-1.5 border rounded outline-none text-sm" />
                         </div>
                     </div>
                 </div>

                 <div class="flex flex-col gap-4">
                     <h5 class="text-sm font-bold text-slate-700 border-b pb-1 uppercase tracking-wider">Configuración General</h5>
                     <div>
                         <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Estado del Periodo</label>
                         <div class="flex items-center gap-2 mt-2">
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" v-model="editingPeriod.status" :true-value="1" :false-value="0" class="sr-only peer">
                                <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
                                <span class="ml-3 text-sm font-medium" :class="editingPeriod.status ? 'text-green-700' : 'text-slate-500'">{{ editingPeriod.status ? 'Abierto' : 'Cerrado' }}</span>
                            </label>
                         </div>
                     </div>

                     <div>
                         <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Planes de Aprendizaje (Carreras)</label>
                         <div class="bg-slate-50 rounded-lg p-3 border border-slate-100 max-h-40 overflow-y-auto space-y-2 text-slate-900 font-bold">
                             <label v-for="plan in allLearningPlans" :key="plan.id" class="flex items-center gap-2 cursor-pointer hover:bg-slate-200 p-1 rounded transition-colors">
                                 <input type="checkbox" :value="plan.id" v-model="editingPeriod.learningplans" class="w-4 h-4 text-blue-600 rounded focus:ring-blue-500 shrink-0" />
                                 <span class="text-xs text-slate-800 leading-tight uppercase">{{ plan.name }}</span>
                             </label>
                         </div>
                     </div>
                 </div>
             </div>
             
             <div class="p-4 bg-slate-50 border-t border-slate-200 flex justify-end gap-3">
                 <button @click="showPeriodForm = false" class="px-4 py-2 text-sm font-bold text-slate-500 hover:bg-slate-200 rounded-lg transition-colors">Cancelar</button>
                 <button @click="savePeriod" :disabled="saving" class="px-6 py-2 text-sm font-bold bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-all shadow-lg flex items-center gap-2">
                     <span v-if="saving" class="animate-spin border-2 border-white/20 border-t-white rounded-full w-3 h-3"></span>
                     {{ editingPeriod.id ? 'Actualizar' : 'Crear' }} Periodo
                 </button>
             </div>
         </div>
     </div>

    <!-- BREAKDOWN POPOVER (Modern student group movement) -->
    <div v-if="showBreakdownPopover && activePopover" 
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/5 animate-in fade-in duration-200"
         @click.self="showBreakdownPopover = false">
        
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg border border-slate-200 overflow-hidden" 
             style="max-height: 85vh; display: flex; flex-direction: column;">
            
            <!-- Header -->
            <div class="p-4 bg-slate-50 border-b border-slate-200 flex justify-between items-center shrink-0">
                <div>
                    <h4 class="font-bold text-slate-800 leading-tight">{{ popoverData.subject.name }}</h4>
                    <p class="text-xs text-slate-500 font-bold uppercase tracking-wider">
                        Cohortes en {{ getPeriodLabel(popoverData.period) }}
                    </p>
                </div>
                <button @click="showBreakdownPopover = false" class="p-2 hover:bg-slate-200 rounded-full transition-colors text-slate-400">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>

            <!-- Content -->
            <div class="overflow-y-auto p-4 space-y-3 bg-slate-50/30 flex-1">
                <div v-for="(group, key) in popoverData.groups" :key="key" 
                     class="bg-white rounded-xl border border-slate-200 shadow-sm p-3 hover:border-blue-300 transition-all group">
                    
                    <div class="flex justify-between items-start mb-3">
                        <div class="flex-1">
                            <span class="text-xs font-bold text-slate-400 uppercase tracking-tighter block mb-1">Cohorte / Grupo</span>
                            <span class="text-sm font-bold text-slate-700 leading-snug">{{ key }}</span>
                        </div>
                        <button @click="onViewStudents(key, group.students)" 
                                class="flex items-center gap-1.5 px-3 py-1 bg-blue-50 text-blue-700 rounded-full text-xs font-bold hover:bg-blue-100 transition-colors">
                            <i data-lucide="users" class="w-3.5 h-3.5"></i>
                            {{ group.count }}
                        </button>
                    </div>

                    <!-- Movement Controls -->
                    <div class="border-t border-slate-100 pt-3">
                        <span class="text-[10px] font-bold text-slate-400 uppercase mb-2 block">Mover a Periodo:</span>
                        <div class="grid grid-cols-6 gap-1">
                            <button v-for="i in 6" :key="i"
                                    @click="deferredGroups[popoverData.subject.name + '_' + key] = (i-1)"
                                    :class="[
                                        'px-1 py-2 rounded text-[10px] font-bold transition-all border',
                                        (deferredGroups[popoverData.subject.name + '_' + key] !== undefined ? deferredGroups[popoverData.subject.name + '_' + key] : popoverData.period) === (i-1)
                                            ? 'bg-blue-600 text-white border-blue-600 shadow-sm scale-105' 
                                            : 'bg-white text-slate-400 border-slate-100 hover:border-blue-200 hover:text-blue-500'
                                    ]">
                                {{ getPeriodLabel(i-1) }}
                            </button>
                        </div>
                    </div>
                </div>

                <div v-if="Object.keys(popoverData.groups).length === 0" class="py-10 text-center">
                    <div class="bg-slate-100 w-12 h-12 rounded-full flex items-center justify-center mx-auto mb-3">
                        <i data-lucide="info" class="text-slate-400"></i>
                    </div>
                    <p class="text-slate-500 text-sm font-medium">No hay cohortes asignadas.</p>
                </div>
            </div>

            <!-- Footer -->
            <div class="p-3 bg-white border-t border-slate-100 flex justify-end shrink-0">
                <button @click="showBreakdownPopover = false" 
                        class="px-5 py-2 bg-slate-800 text-white rounded-lg text-sm font-bold hover:bg-slate-900 transition-all shadow-md">
                    Cerrar Detalle
                </button>
            </div>
        </div>
    </div>

    <!-- STUDENT LIST MODAL -->
    <div v-if="showStudentModal" class="fixed inset-0 z-[70] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm animate-in fade-in duration-300">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden animate-in zoom-in-95 duration-200">
            <div class="p-4 border-b border-slate-200 bg-slate-50 flex justify-between items-center">
                <h4 class="font-bold text-slate-800">{{ studentModalData.title }}</h4>
                <button @click="showStudentModal = false" class="p-1 hover:bg-slate-200 rounded-full transition-colors"><i data-lucide="x" class="w-5 h-5 text-slate-400"></i></button>
            </div>
            <div class="p-4 max-h-[60vh] overflow-y-auto">
                <div class="space-y-2">
                    <div v-for="stu in studentModalData.students" :key="stu.id" class="p-3 bg-slate-50 rounded-xl border border-slate-100 hover:border-blue-200 transition-all flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold text-xs">
                            {{ stu.name.charAt(0) }}
                        </div>
                        <div>
                            <p class="text-sm font-bold text-slate-700 leading-tight">{{ stu.name }}</p>
                            <p class="text-[10px] text-slate-400 font-mono">{{ stu.id }}</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="p-4 bg-slate-50 border-t border-slate-200 text-center">
                <button @click="showStudentModal = false" class="w-full py-2 bg-slate-200 hover:bg-slate-300 text-slate-700 rounded-lg text-sm font-bold transition-colors">Volver</button>
            </div>
        </div>
    </div>

</div>

</script>

<script>
window.onerror = function(msg, url, line, col, error) {
    console.error("VUE_CRITICAL_ERROR (Global):", msg, "at line", line);
    return false;
};
</script>

<script>
console.log("Vue Planning App: Starting main script...");
const { createApp, ref, computed, onMounted, nextTick, watch, reactive } = Vue;

const app = createApp({
    setup() {
        try {
            console.log("Vue Planning App: setup() starting...");
            const loading = ref(true);
            const store = window.schedulerStore;
            const rawData = ref([]); 
            const selectedPeriodId = ref(0);
            const periods = ref([]);
            
            // CRUD & Config State
            const academicPeriods = ref([]);
            const allLearningPlans = ref([]);
            const showPeriodForm = ref(false);
            const saving = ref(false);
            const configSubTab = ref('periods');
            const editingPeriod = ref({
                id: 0,
                name: '',
                startdate_raw: '',
                enddate_raw: '',
                status: 1,
                learningplans: [],
                induction_raw: '', block1start_raw: '', block1end_raw: '', 
                block2start_raw: '', block2end_raw: '', finalexamfrom_raw: '',
                finalexamuntil_raw: '', loadnotes_raw: '', delivlist_raw: '',
                notifreval_raw: '', deadlinereval_raw: '', revalprocess_raw: '',
                registrationsfrom_raw: '', registrationsuntil_raw: '', graduation_raw: ''
            });
        
            const urlParams = new URLSearchParams(window.location.search);
            const activeTab = ref(urlParams.get('tab') || 'planning');
        
            // State for filters
            const selectedCareer = ref('Todas');
            const selectedShift = ref('Todas');
            
            // Configuration State
            
            // Manual Adjustments
            const isOrderLocked = ref(false);
            const manualProjections = reactive({}); // { SubjectName: Count }
            const deferredGroups = reactive({}); // { SubjectName_CohortKey: PeriodIndex (0-5) }
            const ignoredSubjects = reactive({}); // { SubjectName: Boolean }
            
            // Popover
            const activePopover = ref(null);
            const showBreakdownPopover = ref(false);
            const popoverData = ref({ subject: null, period: 0, groups: {} });

            // Modals
            const showStudentModal = ref(false);
            const studentModalData = ref({ title: '', students: [] });
            
            // Student Filter
            const studentStatusFilter = ref('Todos');
            const searchTerm = ref('');
            const periodMappings = ref({});
    
            // New UI State for Tabs
            const expandedCareer = ref(null);
            const expandedPeriod = ref(null);
            const studentSearchQuery = ref('');
            const searchedStudent = ref(null);
    
            
            // Calendar State
            const calendarYear = ref(new Date().getFullYear());
            const monthsLabels = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
        
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
             console.log("Vue Planning App: loadInitial() fetching institutional periods...");
             let p = await callMoodle('local_grupomakro_get_academic_periods', {});
             periods.value = Array.isArray(p) ? p : [];
             console.log("Vue Planning App: loadInitial() periods loaded:", periods.value.length);
             
             if(periods.value.length > 0 && selectedPeriodId.value === 0) {
                 selectedPeriodId.value = periods.value[0].id;
             }
             fetchData();
        };

        const reloadData = () => fetchData();

        const updateProjection = (name, val) => {
            const num = parseInt(val, 10) || 0;
            console.log(`DEBUG updateProjection: Setting '${name}' to ${num}`);
            manualProjections[name] = num;
        };

        const savePlanning = async () => {
             if (selectedPeriodId.value === 0) return;
             
             console.log("DEBUG manualProjections proxy:", JSON.parse(JSON.stringify(manualProjections)));
             
             saving.value = true;
             try {
                 const items = [];
                 
                  analysis.value.subjectList.forEach(s => {
                      const count = manualProjections[s.name] || 0;
                      const isIgnored = ignoredSubjects[s.name] || false;
                      
                      // Check if it was previously saved in the database
                      const wasSaved = rawData.value.planning_projections && 
                                       rawData.value.planning_projections.find(pp => pp.courseid == s.id);
                      
                      // We send it if:
                      // 1. It has a manual count > 0
                      // 2. It is marked as ignored
                      // 3. It was PREVIOUSLY saved (even if now count is 0 and not ignored, we need to update/delete it)
                      
                      if (count > 0 || isIgnored || wasSaved) {
                          let targetPlanId = 0;
                          let targetPeriodId = 0;
                          
                          if (selectedCareer.value !== 'Todas') {
                              const found = s.careers.find(c => {
                                  if (typeof c === 'object' && c !== null) return c.name === selectedCareer.value;
                                  return c === selectedCareer.value;
                              });
                              if (found && typeof found === 'object') {
                                  targetPlanId = found.id;
                                  targetPeriodId = found.periodid;
                              }
                          }
                          
                          if (!targetPlanId && s.careers && s.careers.length > 0) {
                              const firstObj = s.careers.find(c => typeof c === 'object' && c !== null && c.id);
                              if (firstObj) {
                                  targetPlanId = firstObj.id;
                                  targetPeriodId = firstObj.periodid;
                              }
                          }

                          if (!targetPlanId) {
                              console.warn("Vue Planning App: Could not find targetPlanId for subject", s.name, s.careers);
                          }

                          if (targetPlanId) {
                              items.push({
                                  planid: targetPlanId,
                                  courseid: s.id,
                                  periodid: targetPeriodId, 
                                  count: count,
                                  ignored: isIgnored,
                                  checked: (count > 0 || isIgnored) // If BOTH are 0/false, it means we want to delete/disable
                              });
                          }
                      }
                  });

                 console.log("Vue Planning App: savePlanning() items payload:", JSON.stringify(items));
                 
                 // Repack deferredGroups flat object into { courseId: { cohortKey: targetIdx } } expected by backend
                 const repackedDeferrals = {};
                 const allSubjects = (rawData.value && rawData.value.all_subjects) ? rawData.value.all_subjects : analysis.value.subjectList;
                 
                 if (allSubjects) {
                     Object.entries(deferredGroups).forEach(([flatKey, targetIdx]) => {
                         let matchedSubject = null;
                         for (const s of allSubjects) {
                             if (flatKey.startsWith(s.name + '_')) {
                                 matchedSubject = s;
                                 break;
                             }
                         }
                         if (matchedSubject) {
                             const cohortKey = flatKey.substring(matchedSubject.name.length + 1);
                             if (!repackedDeferrals[matchedSubject.id]) {
                                 repackedDeferrals[matchedSubject.id] = {};
                             }
                             repackedDeferrals[matchedSubject.id][cohortKey] = targetIdx;
                         } else {
                             console.warn("Vue Planning App: Could not repack deferral, subject not found for key:", flatKey);
                         }
                     });
                 }

                 console.log("Vue Planning App: savePlanning() deferrals payload:", JSON.stringify(repackedDeferrals));
                 
                 // Save Period Mappings
                 await callMoodle('local_grupomakro_save_period_mappings', {
                     baseperiodid: selectedPeriodId.value,
                     mappings: JSON.stringify(periodMappings.value)
                 });

                 let res = await callMoodle('local_grupomakro_save_planning', {
                     academicperiodid: selectedPeriodId.value,
                     selections: JSON.stringify(items),
                     deferredGroups: JSON.stringify(repackedDeferrals)
                 });
                 
                 if (res) {
                     alert("Planificación guardada con éxito.");
                     fetchData();
                 }
             } catch (e) {
                 console.error("Vue Planning App: savePlanning() FAILED", e);
                 alert("Error al guardar: " + e.message);
             } finally {
                 saving.value = false;
             }
        };

        const fetchData = async () => {
             console.log("Vue Planning App: fetchData() starting for period", selectedPeriodId.value);
             loading.value = true;
             try {
                 let res = await callMoodle('local_grupomakro_get_planning_data', { periodid: selectedPeriodId.value });
                 console.log("Vue Planning App: fetchData() received data:", res ? "SUCCESS" : "EMPTY");
                 rawData.value = res || [];

                   if (res) {
                        // Reset current states
                        Object.keys(manualProjections).forEach(key => delete manualProjections[key]);
                        Object.keys(ignoredSubjects).forEach(key => delete ignoredSubjects[key]);
                        periodMappings.value = res.period_mappings || {};
                        
                        // Guarantee reactivity by pre-defining properties for all subjects
                        if (res.all_subjects) {
                            res.all_subjects.forEach(subj => {
                                manualProjections[subj.name] = 0;
                                ignoredSubjects[subj.name] = false;
                            });
                        }
                        
                        if (res.planning_projections) {
                            res.planning_projections.forEach(pp => {
                                const subject = res.all_subjects ? res.all_subjects.find(s => s.id == pp.courseid) : null;
                                if (subject) {
                                    if (pp.projected_students > 0) {
                                        manualProjections[subject.name] = pp.projected_students;
                                    }
                                    if (pp.status == 2) {
                                        ignoredSubjects[subject.name] = true;
                                    }
                                }
                            });
                        }
                        
                        if (res.deferrals) {
                            Object.keys(deferredGroups).forEach(key => delete deferredGroups[key]);
                            Object.entries(res.deferrals).forEach(([courseId, cohorts]) => {
                                const subject = res.all_subjects ? res.all_subjects.find(s => s.id == courseId) : null;
                                if (subject) {
                                    Object.entries(cohorts).forEach(([cohortKey, targetIdx]) => {
                                        deferredGroups[`${subject.name}_${cohortKey}`] = targetIdx;
                                    });
                                }
                            });
                        }
                   }
             } catch (e) {
                 console.error("Vue Planning App: fetchData() FAILED", e);
             } finally {
                 loading.value = false;
                 console.log("Vue Planning App: fetchData() complete, loading=false");
                 // Use setTimeout to ensure DOM is ready before Lucide runs
                 setTimeout(() => {
                     if (typeof lucide !== 'undefined') {
                         lucide.createIcons();
                     }
                 }, 50);
             }
        };


        // --- CORE LOGIC (Ported from React) ---
        const analysis = computed(() => {
            if (!rawData.value || (Array.isArray(rawData.value) && rawData.value.length === 0)) return { subjectList: [], cohortViewList: [], studentList: [] };

            let filtered = Array.isArray(rawData.value) ? rawData.value : (rawData.value.students || []);
            if (!Array.isArray(filtered)) filtered = [];

            // Filter Source Data
            if (selectedCareer.value !== 'Todas') filtered = filtered.filter(s => s.career === selectedCareer.value);
            if (selectedShift.value !== 'Todas') filtered = filtered.filter(s => s.shift === selectedShift.value);

            const subjectsMap = {}; 
            const studentsMap = {}; // Renamed from students to avoid conflict
            const cohorts = {};
            const studentsInSem = {}; // Map Level -> Cohort -> Data
            
            // New data structures for Population and Analysis
            const populationTree = {};
            const entryPeriodsSet = new Set();

            
            // 1. Initialize Cohorts & Students
            filtered.forEach(stu => {
                 // Determine Level/Bimestre from Config or Props
                 let levelConfig = stu.currentSemConfig; 
                 let subConfig = stu.currentSubperiodConfig;
                 
                 let levelNum = 0;
                 if (typeof levelConfig === 'string') {
                     let match = levelConfig.match(/\d+/);
                     if (match) levelNum = parseInt(match[0]);
                     if (!match) {
                         if (levelConfig.includes('I')) levelNum = 1;
                     }
                 }
                 
                 let isBimestre2 = (subConfig && (subConfig.toLowerCase().includes('ii') || subConfig.includes('2')));
                 
                 // Logic from React: calculate 'Planning Level'
                 let planningLevel = levelNum;
                 let planningBimestre = 'II';
                 
                 if (isBimestre2) {
                     planningLevel = levelNum + 1; // Finishing II -> Goes to Next Level I
                     planningBimestre = 'I';
                 }
                 
                 const entryP = stu.entry_period || 'Sin Definir';
                 const cohortKey = `${stu.career} - ${stu.shift} - Nivel ${planningLevel} - Bimestre ${planningBimestre} [${entryP}]`;
                 
                 // Init Student Object
                 studentsMap[stu.id] = {
                     ...stu,
                     planningLevel,
                     planningBimestre,
                     cohortKey,
                     isGradRisk: false 
                 };
                 
                 // Init Cohort
                 if (!cohorts[cohortKey]) {
                     cohorts[cohortKey] = {
                         key: cohortKey,
                         career: stu.career,
                         shift: stu.shift,
                         semester: `Nivel ${planningLevel}`,
                         bimestreLabel: `Bimestre ${planningBimestre}`,
                         entryPeriod: entryP,
                         levelNum: planningLevel,
                         studentCount: 0,
                         studentNames: [],
                         subjectsByPeriod: { 0: [], 1: [], 2: [], 3: [], 4: [], 5: [] }
                     };
                 }
                 
                 // Init Semester Map
                 if (!studentsInSem[planningLevel]) studentsInSem[planningLevel] = {};
                 if (!studentsInSem[planningLevel][cohortKey]) studentsInSem[planningLevel][cohortKey] = { count: 0, students: [] };
                 
                  studentsMap[stu.id].cohortKey = cohortKey;
                  cohorts[cohortKey].studentCount++;
                  cohorts[cohortKey].studentNames.push(`${stu.name} (${stu.id})`);
                  studentsInSem[planningLevel][cohortKey].count++;
                  studentsInSem[planningLevel][cohortKey].students.push(studentsMap[stu.id]);

                  // Population Tree Logic
                  entryPeriodsSet.add(entryP);
                  
                  if (!populationTree[stu.career]) {
                      populationTree[stu.career] = { count: 0, periods: {} };
                  }
                  populationTree[stu.career].count++;
                  
                  if (!populationTree[stu.career].periods[entryP]) {
                      populationTree[stu.career].periods[entryP] = { count: 0, groups: {} };
                  }
                  populationTree[stu.career].periods[entryP].count++;
                  
                  if (!populationTree[stu.career].periods[entryP].groups[cohortKey]) {
                      populationTree[stu.career].periods[entryP].groups[cohortKey] = {
                          key: cohortKey,
                          level: planningLevel,
                          bimestre: planningBimestre,
                          count: 0
                      };
                  }
                  populationTree[stu.career].periods[entryP].groups[cohortKey].count++;
             });


            // 1. Initialize Subjects from Backend Master List (to show 0 demand items)
            // Backend now returns { students: [], all_subjects: [] }
            
            let students = studentsMap; // Use the processed studentsMap
            
            // Extract all_subjects if available
            let allSubjectsList = [];
            if (rawData.value.all_subjects && Array.isArray(rawData.value.all_subjects)) {
                allSubjectsList = rawData.value.all_subjects;
            } else if (Array.isArray(rawData.value)) {
                allSubjectsList = [];
            }
            
            // Initialize Subjects Map
            allSubjectsList.forEach(subj => {
                  subjectsMap[subj.name] = {
                      id: subj.id,
                      name: subj.name,
                      semesterNum: parseInt(subj.semester_num) || 0,
                      countP1: 0, countP2: 0, countP3: 0, countP4: 0, countP5: 0, countP6: 0,
                      groupsP1: {}, groupsP2: {}, groupsP3: {}, groupsP4: {}, groupsP5: {}, groupsP6: {},
                      entryPeriodCounts: {},
                      careers: subj.careers || []
                  };
             });
            

            // 2. Process Demand (P-I) from Pending Subjects
            Object.values(students).forEach(stu => {
                (stu.pendingSubjects || []).forEach(subj => {
                    if (subj.isPriority) {
                         if (!subjectsMap[subj.name]) {
                              subjectsMap[subj.name] = {
                                  id: subj.id,
                                  name: subj.name,
                                  semesterNum: parseInt(subj.semester) || 0,
                                  countP1: 0, countP2: 0, countP3: 0, countP4: 0, countP5: 0, countP6: 0,
                                  groupsP1: {}, groupsP2: {}, groupsP3: {}, groupsP4: {}, groupsP5: {}, groupsP6: {},
                                  entryPeriodCounts: {},
                                  careers: [stu.career]
                              };
                         } else {
                             const hasCareer = subjectsMap[subj.name].careers && subjectsMap[subj.name].careers.some(c => {
                                 const cName = (typeof c === 'object' && c !== null) ? c.name : c;
                                 return cName === stu.career;
                             });
                             if (!hasCareer) {
                                 subjectsMap[subj.name].careers.push(stu.career);
                             }
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
                          
                          // Track counts by Entry Period for Analysis
                          const entryP = stu.entry_period || 'Sin Definir';
                          if (!subjectsMap[subj.name].entryPeriodCounts[entryP]) {
                              subjectsMap[subj.name].entryPeriodCounts[entryP] = 0;
                          }
                          subjectsMap[subj.name].entryPeriodCounts[entryP]++;

                          // Add to Cohort View
                         if (cohorts[stu.cohortKey] && !cohorts[stu.cohortKey].subjectsByPeriod[deferral].includes(subj.name)) {
                             cohorts[stu.cohortKey].subjectsByPeriod[deferral].push(subj.name);
                         }
                    }
                });
            });

            // 3. Process Wave (Future Demand) - Cohort-centric logic for parity
            Object.values(cohorts).forEach(coh => {
                let curL = coh.levelNum;
                let isB2 = coh.bimestreLabel.toLowerCase().includes('ii') || coh.bimestreLabel.includes('2');
                let curB = isB2 ? 2 : 1;

                // TRACK subjects already handled as "Pending Priority" for this cohort to avoid double counting
                const pendingForCohort = new Set();
                Object.values(studentsMap).forEach(s => {
                    if (s.cohortKey === coh.key) {
                        (s.pendingSubjects || []).forEach(ps => {
                            if (ps.isPriority) pendingForCohort.add(ps.name);
                        });
                    }
                });
                
                // We advance the state of the cohort for 5 future periods (P-II to P-VI)
                for (let pIdx = 1; pIdx <= 5; pIdx++) {
                    // Advance student state: 2 Bimestres per Level
                    if (curB === 1) {
                        curB = 2;
                    } else {
                        curB = 1;
                        curL++; // Advanced to next level after finishing Bimestre II
                    }
                    
                    const targetSubjets = allSubjectsList.filter(s => {
                        const sLevel = parseInt(s.semester_num) || 0;
                        const sBimestre = parseInt(s.bimestre) || 0;
                        const careerMatch = s.careers && s.careers.some(c => {
                            const cName = (typeof c === 'object' && c !== null) ? c.name : c;
                            return cName === coh.career;
                        });
                        // Skip if already in P-I Pending demand for this cohort
                        if (pendingForCohort.has(s.name)) return false;
                        return sLevel === curL && sBimestre === curB && careerMatch;
                    });

                    targetSubjets.forEach(s => {
                         if (!subjectsMap[s.name]) return;
                         
                         // Check for manual deferrals
                         let deferKey = `${s.name}_${coh.key}`;
                         let actualPeriod = deferredGroups[deferKey] !== undefined ? deferredGroups[deferKey] : pIdx;
                         
                         let pKey = 'countP' + (actualPeriod + 1);
                         if (subjectsMap[s.name][pKey] !== undefined) {
                             subjectsMap[s.name][pKey] += coh.studentCount;
                         }
                         
                         let gKey = 'groupsP' + (actualPeriod + 1);
                         if (!subjectsMap[s.name][gKey][coh.key]) {
                             subjectsMap[s.name][gKey][coh.key] = { count: 0, students: [] };
                         }
                         subjectsMap[s.name][gKey][coh.key].count += coh.studentCount;
                         subjectsMap[s.name][gKey][coh.key].students = [...coh.studentNames];
                         
                         // Add to Cohort View
                         if (coh.subjectsByPeriod[actualPeriod] && !coh.subjectsByPeriod[actualPeriod].includes(s.name)) {
                             coh.subjectsByPeriod[actualPeriod].push(s.name);
                         }
                    });
                }
            });

            // 4. Finalize Subjects List
            let subjectsArray = Object.values(subjectsMap).map(s => {
                const isIgnored = ignoredSubjects[s.name] || false;
                const manual = manualProjections[s.name] || 0;
                const totalP1 = s.countP1 + manual;
                const isOpen = !isIgnored && totalP1 >= 12;
                
                let suggestion = isIgnored ? "OMITIDA" : "Abrir P-I";
                let maxDemand = totalP1;
                
                if (s.countP2 > maxDemand) { maxDemand = s.countP2; suggestion = isIgnored ? "OMITIDA" : "Esperar P-II"; }
                
                if (totalP1 < 12 && maxDemand < 12) suggestion = isIgnored ? "OMITIDA" : "Baja Demanda";
                if (isOpen && !suggestion.includes("Esperar")) suggestion = "ABRIR AHORA";
                
                return { ...s, totalP1, isOpen, suggestion, manual, countP1: s.countP1 };
            });

            if (selectedCareer.value !== 'Todas') {
                subjectsArray = subjectsArray.filter(s => {
                    if (!s.careers || !Array.isArray(s.careers)) return false;
                    return s.careers.some(c => {
                        const careerName = (typeof c === 'object' && c !== null) ? c.name : c;
                        return careerName === selectedCareer.value;
                    });
                });
            }

            // 5. Build Student Status Lists
            const openSubjectsSet = new Set(subjectsArray.filter(s => s.isOpen).map(s => s.name));
            
            const studentAnalysisList = Object.values(students).map(stu => {
                const priority = (stu.pendingSubjects || []).filter(s => s.isPriority);
                const projected = priority.filter(s => {
                     let deferKey = `${s.name}_${stu.cohortKey}`;
                     let pIndex = deferredGroups[deferKey] || 0;
                     return openSubjectsSet.has(s.name) && pIndex === 0;
                });
                
                const missing = priority.filter(s => !openSubjectsSet.has(s.name));
                
                let status = 'normal';
                if (projected.length === 0) status = 'critical';
                else if (projected.length === 1) status = 'low';
                else if (projected.length > 3) status = 'overload';
                
                return { ...stu, projectedSubjects: projected, missingSubjects: missing, status, loadCount: projected.length };
            });

            try {
                return {
                    subjectList: subjectsArray.sort((a,b) => {
                        if (a.semesterNum !== b.semesterNum) return a.semesterNum - b.semesterNum;
                        if (isOrderLocked.value) {
                            return a.name.localeCompare(b.name);
                        }
                        return b.totalP1 - a.totalP1;
                    }),
                    cohortViewList: Object.values(cohorts).sort((a,b) => b.studentCount - a.studentCount),
                    studentList: studentAnalysisList,
                    populationTree,
                    totalStudents: filtered.length,
                    sortedEntryPeriods: Array.from(entryPeriodsSet).sort(),
                    students: studentsMap
                };

            } catch (err) {
                console.error("Vue Planning App: ERROR in analysis engine", err);
                return { subjectList: [], cohortViewList: [], studentList: [] };
            }
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

        const careers = computed(() => {
            const studentRaw = Array.isArray(rawData.value) ? rawData.value : (rawData.value?.students || []);
            const subjectRaw = rawData.value?.all_subjects || [];
            
            const names = new Set();
            studentRaw.forEach(s => { if(s.career) names.add(s.career); });
            subjectRaw.forEach(s => {
                if(s.careers) {
                    s.careers.forEach(c => {
                        const n = (typeof c === 'object' && c !== null) ? c.name : c;
                        if(n) names.add(n);
                    });
                }
            });
            return Array.from(names).filter(n => n && n !== 'Todas').sort();
        });
        const shifts = computed(() => {
            const raw = Array.isArray(rawData.value) ? rawData.value : (rawData.value?.students || []);
            return [...new Set(raw.map(s => s.shift))].filter(s => s && s !== 'Todas').sort();
        });
        
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
            const subj = analysis.value.subjectList.find(s => s.name === subjName);
            if (!subj) return 0;
            const groupKey = 'groupsP' + (pIdx + 1);
            if (subj[groupKey] && subj[groupKey][cohortKey]) {
                return subj[groupKey][cohortKey].count;
            }
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
        // -- Popover --
        const openPeriodModal = (period = null) => {
             console.log("Vue Planning App: openPeriodModal() called", period ? "editing" : "creating");
             if (period) {
                 const d1 = new Date(period.startdate * 1000);
                 const d2 = new Date(period.enddate * 1000);
                 
                 editingPeriod.value = {
                     id: period.id,
                     name: period.name,
                     startdate_raw: d1.toISOString().split('T')[0],
                     enddate_raw: d2.toISOString().split('T')[0],
                     status: parseInt(period.status),
                     learningplans: [...(period.learningplans || [])],
                     // Detailed fields
                     induction_raw: (period.induction && period.induction > 0) ? new Date(period.induction * 1000).toISOString().split('T')[0] : '',
                     block1start_raw: (period.block1start && period.block1start > 0) ? new Date(period.block1start * 1000).toISOString().split('T')[0] : '',
                     block1end_raw: (period.block1end && period.block1end > 0) ? new Date(period.block1end * 1000).toISOString().split('T')[0] : '',
                     block2start_raw: (period.block2start && period.block2start > 0) ? new Date(period.block2start * 1000).toISOString().split('T')[0] : '',
                     block2end_raw: (period.block2end && period.block2end > 0) ? new Date(period.block2end * 1000).toISOString().split('T')[0] : '',
                     finalexamfrom_raw: (period.finalexamfrom && period.finalexamfrom > 0) ? new Date(period.finalexamfrom * 1000).toISOString().split('T')[0] : '',
                     finalexamuntil_raw: (period.finalexamuntil && period.finalexamuntil > 0) ? new Date(period.finalexamuntil * 1000).toISOString().split('T')[0] : '',
                     loadnotes_raw: (period.loadnotesandclosesubjects && period.loadnotesandclosesubjects > 0) ? new Date(period.loadnotesandclosesubjects * 1000).toISOString().split('T')[0] : '',
                     delivlist_raw: (period.delivoflistforrevalbyteach && period.delivoflistforrevalbyteach > 0) ? new Date(period.delivoflistforrevalbyteach * 1000).toISOString().split('T')[0] : '',
                     notifreval_raw: (period.notiftostudforrevalidations && period.notiftostudforrevalidations > 0) ? new Date(period.notiftostudforrevalidations * 1000).toISOString().split('T')[0] : '',
                     deadlinereval_raw: (period.deadlforpayofrevalidations && period.deadlforpayofrevalidations > 0) ? new Date(period.deadlforpayofrevalidations * 1000).toISOString().split('T')[0] : '',
                     revalprocess_raw: (period.revalidationprocess && period.revalidationprocess > 0) ? new Date(period.revalidationprocess * 1000).toISOString().split('T')[0] : '',
                     registrationsfrom_raw: (period.registrationsfrom && period.registrationsfrom > 0) ? new Date(period.registrationsfrom * 1000).toISOString().split('T')[0] : '',
                     registrationsuntil_raw: (period.registrationsuntil && period.registrationsuntil > 0) ? new Date(period.registrationsuntil * 1000).toISOString().split('T')[0] : '',
                     graduation_raw: (period.graduationdate && period.graduationdate > 0) ? new Date(period.graduationdate * 1000).toISOString().split('T')[0] : ''
                 };
             } else {
                 editingPeriod.value = {
                     id: 0,
                     name: '',
                     startdate_raw: '',
                     enddate_raw: '',
                     status: 1,
                     learningplans: [],
                     induction_raw: '', block1start_raw: '', block1end_raw: '', 
                     block2start_raw: '', block2end_raw: '', finalexamfrom_raw: '',
                     finalexamuntil_raw: '', loadnotes_raw: '', delivlist_raw: '',
                     notifreval_raw: '', deadlinereval_raw: '', revalprocess_raw: '',
                     registrationsfrom_raw: '', registrationsuntil_raw: '', graduation_raw: ''
                 };
             }
             showPeriodForm.value = true;
             nextTick(() => lucide.createIcons());
        };

        const savePeriod = async () => {
             if (!editingPeriod.value.name || !editingPeriod.value.startdate_raw || !editingPeriod.value.enddate_raw) {
                 alert("Por favor complete los campos obligatorios.");
                 return;
             }
             
             saving.value = true;
             
             const toTs = (d, end = false) => {
                 if (!d) return 0;
                 return Math.floor(new Date(d + (end ? 'T23:59:59' : 'T00:00:00')).getTime() / 1000);
             };
             
             const startTs = toTs(editingPeriod.value.startdate_raw);
             const endTs = toTs(editingPeriod.value.enddate_raw, true);
             
             try {
                const res = await callMoodle('local_grupomakro_save_academic_period', {
                    id: editingPeriod.value.id,
                    name: editingPeriod.value.name,
                    startdate: startTs,
                    enddate: endTs,
                    status: editingPeriod.value.status,
                    learningplans: JSON.stringify(editingPeriod.value.learningplans),
                    details: JSON.stringify({
                        induction: toTs(editingPeriod.value.induction_raw),
                        block1start: toTs(editingPeriod.value.block1start_raw),
                        block1end: toTs(editingPeriod.value.block1end_raw, true),
                        block2start: toTs(editingPeriod.value.block2start_raw),
                        block2end: toTs(editingPeriod.value.block2end_raw, true),
                        finalexamfrom: toTs(editingPeriod.value.finalexamfrom_raw),
                        finalexamuntil: toTs(editingPeriod.value.finalexamuntil_raw, true),
                        loadnotesandclosesubjects: toTs(editingPeriod.value.loadnotes_raw),
                        delivoflistforrevalbyteach: toTs(editingPeriod.value.delivlist_raw),
                        notiftostudforrevalidations: toTs(editingPeriod.value.notifreval_raw),
                        deadlforpayofrevalidations: toTs(editingPeriod.value.deadlinereval_raw),
                        revalidationprocess: toTs(editingPeriod.value.revalprocess_raw),
                        registrationsfrom: toTs(editingPeriod.value.registrationsfrom_raw),
                        registrationsuntil: toTs(editingPeriod.value.registrationsuntil_raw, true),
                        graduationdate: toTs(editingPeriod.value.graduation_raw)
                    })
                });
                
                if (res) {
                    showPeriodForm.value = false;
                    await loadCalendarData();
                    await loadInitial();
                }
             } finally {
                saving.value = false;
             }
        };

        const deletePeriod = async (period) => {
            if (!confirm(`¿Está seguro de eliminar el periodo "${period.name}"? Esta acción borrará la configuración y el calendario asociado.`)) {
                return;
            }
            
            try {
                const res = await callMoodle('local_grupomakro_delete_academic_period', { id: period.id });
                if (res) {
                    await loadCalendarData();
                    await loadInitial();
                }
            } catch (e) {
                alert("Error al eliminar periodo: " + e.message);
            }
        };

        const openPopover = (subj, idx, e, entryPeriod = null) => {
            console.log("Vue Planning App: openPopover()", subj.name, idx, entryPeriod);
            const pKey = 'groupsP' + (idx + 1);
            let groups = subj[pKey] || {};
            
            if (entryPeriod) {
                // Filter groups to only show cohorts from this entry period
                const filteredGroups = {};
                Object.entries(groups).forEach(([key, val]) => {
                    if (key.endsWith(`[${entryPeriod}]`)) {
                        filteredGroups[key] = val;
                    }
                });
                groups = filteredGroups;
            }

            popoverData.value = {
                subject: subj,
                period: idx,
                groups: groups,
                entryPeriod: entryPeriod
            };
            
            // Interaction: if target isn't the button itself, find it
            let target = e.currentTarget;
            activePopover.value = target;
            showBreakdownPopover.value = true;
            
            nextTick(() => lucide.createIcons());
        };

        const onViewStudents = (cohortKey, students) => {
            console.log("Vue Planning App: onViewStudents()", cohortKey);
            studentModalData.value = {
                title: `Estudiantes: ${cohortKey}`,
                students: students.map(s => {
                    // students is array of strings: "Name (ID)"
                    const parts = s.match(/(.*) \((.*)\)/);
                    return {
                        name: parts ? parts[1] : s,
                        id: parts ? parts[2] : ''
                    };
                })
            };
            showStudentModal.value = true;
            showBreakdownPopover.value = false;
        };

        // --- CALENDAR LOGIC ---
        const loadCalendarData = async () => {
             // We need to fetch from gmk_academic_periods
             let res = await callMoodle('local_grupomakro_get_academic_periods', {});
             academicPeriods.value = res || [];
             nextTick(() => lucide.createIcons());
        };

        const loadAllPlans = async () => {
             let res = await callMoodle('local_grupomakro_get_all_learning_plans', {});
             allLearningPlans.value = res || [];
        };

        const getPlanName = (lpid) => {
             const p = allLearningPlans.value.find(x => x.id == lpid);
             return p ? p.name : `Plan ${lpid}`;
        };

        const calendarRows = computed(() => {
            // Group periods by Plan
            const map = {};
            const colors = [
                '#3b82f6', '#8b5cf6', '#ec4899', '#f97316', '#10b981', 
                '#f43f5e', '#6366f1', '#14b8a6', '#f59e0b', '#06b6d4'
            ];

            // Get unique plans from periods
            const pList = Array.isArray(academicPeriods.value) ? academicPeriods.value : [];
            const masterPeriods = Array.isArray(periods.value) ? periods.value : [];

            pList.forEach(p => {
                if (!p) return;
                const lpids = Array.isArray(p.learningplans) ? p.learningplans : [];
                lpids.forEach(lpid => {
                    const lpInfo = masterPeriods.find(lp => lp.id == lpid);
                    const lpName = lpInfo ? lpInfo.name : `Plan ${lpid}`;

                    // Filter by career if selected
                    if (selectedCareer.value !== 'Todas' && lpName !== selectedCareer.value) {
                        return;
                    }

                    if (!map[lpid]) {
                        map[lpid] = {
                            planId: lpid,
                            planName: lpName,
                            periods: [],
                            color: colors[Object.keys(map).length % colors.length]
                        };
                    }
                    map[lpid].periods.push(p);
                });
            });

            return Object.values(map);
        });

        const getPeriodsForMonth = (rowPeriods, month) => {
            const yearStart = new Date(calendarYear.value, month - 1, 1).getTime() / 1000;
            const yearEnd = new Date(calendarYear.value, month, 0, 23, 59, 59).getTime() / 1000;

            return rowPeriods.filter(p => {
                return (p.startdate <= yearEnd && p.enddate >= yearStart);
            });
        };

        const getPeriodStyle = (p, baseColor) => {
            return {
                backgroundColor: baseColor,
                borderLeft: '4px solid rgba(0,0,0,0.1)'
            };
        };

        const formatDate = (ts) => {
            const d = new Date(ts * 1000);
            return d.toLocaleDateString();
        };

        const formatDateShort = (ts) => {
            const d = new Date(ts * 1000);
            return `${d.getDate()} ${monthsLabels[d.getMonth()]}`;
        };

        const isStartOfMonth = (p, month) => {
            const d = new Date(p.startdate * 1000);
            return (d.getMonth() + 1) === month && d.getFullYear() === calendarYear.value;
        };

        const toggleCareer = (career) => {
            expandedCareer.value = expandedCareer.value === career ? null : career;
            expandedPeriod.value = null;
        };

        const togglePeriod = (period) => {
            expandedPeriod.value = expandedPeriod.value === period ? null : period;
        };

        const handleStudentSearch = () => {
            if (!analysis.value || !studentSearchQuery.value) return;
            const term = studentSearchQuery.value.toLowerCase();
            const student = analysis.value.studentList.find(s =>
                String(s.name).toLowerCase().includes(term) || String(s.dbId).toLowerCase().includes(term) || (s.id && String(s.id).toLowerCase().includes(term))
            );

            if (student) {
                // For now, we don't have generatedSchedules here in the main view readily linked,
                // but we can show basic info. If schedulerView generates them, they are in the store.
                searchedStudent.value = student;
            } else {
                alert("Estudiante no encontrado.");
                searchedStudent.value = null;
            }
        };

        const loadUploadMessage = Vue.ref('');
        const loadUploadError = Vue.ref(false);

        const handleLoadUpload = async (e) => {
            const file = e.target.files[0];
            if (!file) return;
            loadUploadMessage.value = '';
            loadUploadError.value = false;
            const result = await store.uploadSubjectLoads(file, selectedPeriodId.value);
            e.target.value = '';
            if (result && result.success) {
                loadUploadMessage.value = '✓ ' + result.count + ' asignaturas cargadas y guardadas.';
            } else {
                loadUploadError.value = true;
                loadUploadMessage.value = store.state.error || 'Error al procesar el archivo.';
            }
        };

        const handleExportStudentSchedule = () => {
            if (!searchedStudent.value || !window.XLSX) {
                alert("XLSX library not loaded or student not selected.");
                return;
            }
            // Implementation depends on availability of schedules in searchedStudent
            alert("Exportar a Excel no implementado en esta vista. Use la pestaña de Horarios para reportes avanzados.");
        };


        onMounted(() => {
            loadInitial();
            loadCalendarData();
            loadAllPlans();
        });

        // Watch activeTab for icons
        watch(activeTab, () => {
             nextTick(() => lucide.createIcons());
        });

        // Auto-load scheduler context (loads, holidays, etc.) when config sub-tabs are shown
        watch(configSubTab, (newTab) => {
            if (['loads', 'holidays', 'general'].includes(newTab) && selectedPeriodId.value) {
                store.loadContext(selectedPeriodId.value);
            }
        }, { immediate: true });

            return {
                loading, selectedPeriodId, periods, uniquePeriods, reloadData, analysis, savePlanning,
                ignoredSubjects, isOrderLocked, updateProjection, periodMappings,
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
                openPopover, activePopover, showBreakdownPopover, popoverData,
                // Modals
                showStudentModal, studentModalData, onViewStudents,
                // Calendar
                calendarYear, monthsLabels, calendarRows,
                getPeriodsForMonth, getPeriodStyle, 
                formatDate, formatDateShort, isStartOfMonth,
                // Configuration / CRUD
                academicPeriods, allLearningPlans, showPeriodForm, editingPeriod, saving,
                openPeriodModal, savePeriod, deletePeriod, getPlanName,
                // New Tabs Logic
                expandedCareer, expandedPeriod, toggleCareer, togglePeriod,
                studentSearchQuery, searchedStudent, handleStudentSearch, handleExportStudentSchedule,
                handleLoadUpload, loadUploadMessage, loadUploadError,
                configSubTab, store
            };

        } catch (setupError) {
            console.error("Vue Planning App: CRITICAL ERROR IN setup()", setupError);
            throw setupError;
        }
    }
});

// Register Scheduler Components
if (window.SchedulerComponents) {
    app.component('scheduler-view', window.SchedulerComponents.SchedulerView);
    app.component('demand-view', window.SchedulerComponents.DemandView);
    app.component('planning-board', window.SchedulerComponents.PlanningBoard);
    app.component('period-grouped-view', window.SchedulerComponents.PeriodGroupedView);
    app.component('projections-modal', window.SchedulerComponents.ProjectionsModal);
    app.component('classroom-manager', window.SchedulerComponents.ClassroomManager);
    app.component('holiday-manager', window.SchedulerComponents.HolidayManager);
    app.component('general-config', window.SchedulerComponents.GeneralConfig);
    app.component('report-view', window.SchedulerComponents.ReportView);
    app.component('full-calendar-view', window.FullCalendarView);
}

console.log("Vue Planning App: Attempting to mount...");
try {
    app.mount('#academic-planning-root');
    console.log("Vue Planning App: Mount successful");
} catch (e) {
    console.error("Vue Planning App: Mount failed!", e);
}
</script>

<?php
echo $OUTPUT->footer();
