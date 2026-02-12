/**
 * Demand View Component (Vue 3 + Tailwind)
 * Visualizes the demand tree and allows triggering schedule generation.
 */

window.SchedulerComponents = window.SchedulerComponents || {};

window.SchedulerComponents.DemandView = {
    props: ['periodId'],
    emits: ['generate'],
    components: {
        'projections-modal': window.SchedulerComponents.ProjectionsModal
    },
    template: `
        <div>
            <!-- Actions -->
            <div class="flex justify-between items-center mb-6">
                <div>
                     <h3 class="text-lg font-bold text-slate-800">Análisis de Demanda Académica</h3>
                     <p class="text-sm text-slate-500">
                        Total de estudiantes activos: {{ storeState.students ? storeState.students.length : 0 }}
                     </p>
                </div>
                <div class="flex gap-2">
                    <button @click="showProjections = true" class="flex items-center gap-2 px-4 py-2 bg-white border border-slate-200 hover:bg-slate-50 text-slate-700 rounded-lg transition-colors text-sm font-medium shadow-sm">
                         <i data-lucide="user-plus" class="w-4 h-4 text-blue-500"></i> Proyecciones Manuales
                    </button>
                    <button @click="generate" :disabled="storeState.loading" class="flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors text-sm font-bold shadow-md">
                         <i data-lucide="play" class="w-4 h-4"></i> Generar Horarios
                    </button>
                </div>
            </div>

            <!-- Loading -->
            <div v-if="storeState.loading" class="py-12 flex flex-col items-center justify-center">
                <i data-lucide="loader-2" class="w-10 h-10 text-blue-600 animate-spin mb-4"></i>
                <p class="text-slate-600 font-medium">Procesando datos...</p>
            </div>

            <div v-else>
                <!-- Alert Empty -->
                 <div v-if="Object.keys(demandTree).length === 0" class="bg-blue-50 border-l-4 border-blue-500 p-4 rounded-r-lg text-blue-800 text-sm">
                    <i data-lucide="info" class="w-4 h-4 inline mr-1"></i> No hay datos de demanda registrados para este periodo.
                </div>

                <!-- Demand Tree -->
                <div class="space-y-4">
                    <div v-for="(shifts, careerName) in demandTree" :key="careerName" class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                        
                        <!-- Header (Like Expansion Panel) -->
                        <div class="p-4 bg-slate-50 flex justify-between items-center cursor-pointer select-none" @click="toggleExpansion(careerName)">
                             <h4 class="font-bold text-slate-800 text-sm uppercase tracking-wide flex items-center gap-2">
                                <i :data-lucide="isExpanded(careerName) ? 'chevron-down' : 'chevron-right'" class="w-4 h-4 text-slate-400"></i>
                                {{ careerName }}
                             </h4>
                        </div>
                        
                        <!-- Content -->
                        <div v-show="isExpanded(careerName)" class="p-4 space-y-6 animate-in slide-in-from-top-2 duration-200">
                             <div v-for="(semesters, shiftName) in shifts" :key="shiftName" class="pl-4 border-l-4 border-blue-500">
                                 <div class="text-sm font-bold text-blue-700 mb-3 uppercase tracking-wider bg-blue-50/50 inline-block px-2 py-1 rounded">
                                     Jornada: {{ shiftName }}
                                 </div>
                                 
                                 <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                     <div v-for="(semData, semKey) in semesters" :key="semKey" class="border border-slate-200 rounded-lg p-3 bg-white hover:border-blue-300 transition-colors">
                                         <div class="flex justify-between items-center border-b border-slate-100 pb-2 mb-2">
                                             <span class="font-bold text-slate-700 text-sm">{{ semData.semester_name }}</span>
                                             <span class="bg-blue-100 text-blue-800 px-2 py-0.5 rounded text-xs font-bold">{{ semData.student_count }} Est.</span>
                                         </div>
                                         <div class="space-y-1 max-h-40 overflow-y-auto pr-1 small-scroll">
                                             <div v-for="(count, courseId) in semData.course_counts" :key="courseId" class="flex justify-between items-center text-xs">
                                                  <span class="text-slate-600 truncate flex-1 mr-2" :title="courseId">ID: {{ courseId }}</span>
                                                  <span class="bg-slate-100 text-slate-600 px-1.5 py-0.5 rounded font-mono font-bold">{{ count }}</span>
                                             </div>
                                         </div>
                                     </div>
                                 </div>
                             </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Projections Modal -->
            <projections-modal
                v-model="showProjections"
                :period-id="periodId"
            ></projections-modal>
        </div>
    `,
    data() {
        return {
            showProjections: false,
            expandedItems: {} // careerName: boolean
        };
    },
    computed: {
        storeState() {
            return window.schedulerStore ? window.schedulerStore.state : {};
        },
        demandTree() {
            return this.storeState.demand || {};
        }
    },
    updated() {
        if (window.lucide) window.lucide.createIcons();
    },
    methods: {
        toggleExpansion(name) {
            this.expandedItems[name] = !this.expandedItems[name];
        },
        isExpanded(name) {
            // Default expanded if few items?
            if (this.expandedItems[name] === undefined) {
                // return true; // Auto expand all?
                this.expandedItems[name] = false;
            }
            return this.expandedItems[name];
        },
        async generate() {
            if (confirm("¿Está seguro de generar los horarios? Esto reemplazará la planificación actual no guardada.")) {
                if (window.schedulerStore) {
                    await window.schedulerStore.generateSchedules();
                    this.$emit('generate');
                }
            }
        }
    }
};
