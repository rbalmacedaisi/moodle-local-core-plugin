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
                                             <div v-for="(val, courseId) in semData.course_counts" :key="courseId" class="flex justify-between items-center text-xs group">
                                                  <span class="text-slate-600 truncate flex-1 mr-2" :title="courseId">ID: {{ courseId }}</span>
                                                  <button 
                                                    @click="openStudentList(val)" 
                                                    :class="{'hover:bg-blue-200 hover:text-blue-800 cursor-pointer': typeof val === 'object' && val.students && val.students.length > 0}"
                                                    class="bg-slate-100 text-slate-600 px-1.5 py-0.5 rounded font-mono font-bold transition-colors"
                                                  >
                                                    {{ getStudentCount(val) }}
                                                  </button>
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

            <!-- Students List Modal -->
            <div v-if="showStudentsModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4 animate-in fade-in duration-200">
                <div class="bg-white rounded-xl shadow-2xl w-full max-w-md max-h-[80vh] flex flex-col overflow-hidden animate-in zoom-in-95 duration-200">
                    <div class="p-4 border-b border-slate-100 flex justify-between items-center bg-slate-50">
                         <h3 class="font-bold text-slate-800">Estudiantes ({{ selectedStudentList.length }})</h3>
                         <button @click="closeStudentModal" class="text-slate-400 hover:text-slate-600 transition-colors">
                             <i data-lucide="x" class="w-5 h-5"></i>
                         </button>
                    </div>
                    <div class="p-0 overflow-y-auto flex-1 small-scroll">
                         <div v-if="selectedStudentList.length === 0" class="p-8 text-center text-slate-500">
                             <i data-lucide="users" class="w-8 h-8 mx-auto mb-2 text-slate-300"></i>
                             No se encontraron detalles de estudiantes.
                         </div>
                         <ul v-else class="divide-y divide-slate-100">
                             <li v-for="student in selectedStudentList" :key="student.id" class="p-3 hover:bg-slate-50 flex items-center gap-3 transition-colors">
                                 <div class="w-8 h-8 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold text-xs shrink-0">
                                     {{ getInitials(student.fullname) }}
                                 </div>
                                 <div class="min-w-0">
                                     <p class="text-sm font-bold text-slate-700 truncate">{{ student.fullname }}</p>
                                     <p class="text-xs text-slate-500 truncate">{{ student.email || 'Sin email' }}</p>
                                     <p v-if="student.documentnumber" class="text-[10px] text-slate-400 font-mono">ID: {{ student.documentnumber }}</p>
                                 </div>
                             </li>
                         </ul>
                    </div>
                    <div class="p-3 border-t border-slate-100 bg-slate-50 flex justify-end">
                        <button @click="closeStudentModal" class="px-4 py-2 bg-white border border-slate-200 hover:bg-slate-100 text-slate-700 rounded-lg text-sm font-bold transition-colors shadow-sm">
                            Cerrar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `,
    data() {
        return {
            showProjections: false,
            showStudentsModal: false,
            selectedStudentList: [],
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
            if (this.expandedItems[name] === undefined) {
                this.expandedItems[name] = false;
            }
            return this.expandedItems[name];
        },
        getStudentCount(val) {
            if (val && typeof val === 'object') {
                return val.count || 0;
            }
            return val || 0;
        },
        openStudentList(val) {
            if (!val || typeof val !== 'object' || !val.students || val.students.length === 0) {
                return;
            }

            const studentIds = val.students;
            // Prefer window store if available for most up-to-date data, fallback to prop
            const allStudents = (window.schedulerStore && window.schedulerStore.state.students) || this.storeState.students || [];

            console.log("GMK Debug: Opening Modal. IDs:", studentIds);

            if (allStudents.length > 0) {
                console.log("GMK Debug: First Student in Store:", JSON.stringify(allStudents[0]));
            } else {
                console.error("GMK Debug: allStudents is empty! Scheduler store might not be loaded correctly.");
            }

            // Map IDs to student objects with robust matching
            this.selectedStudentList = studentIds.map(targetId => {
                const targetStr = String(targetId).trim().toLowerCase();

                const found = allStudents.find(s => {
                    // Check against 'id' (idnumber or id)
                    if (s.id && String(s.id).trim().toLowerCase() === targetStr) return true;
                    // Check against 'dbId' (internal id) just in case
                    if (s.dbId && String(s.dbId).trim().toLowerCase() === targetStr) return true;
                    return false;
                });

                if (!found) {
                    console.warn(`GMK Debug: Student not found. Target: '${targetId}'`);
                }

                return found || {
                    id: targetId,
                    fullname: 'Estudiante Desconocido',
                    email: '',
                    documentnumber: targetId
                };
            });

            this.showStudentsModal = true;
        },
        closeStudentModal() {
            this.showStudentsModal = false;
            this.selectedStudentList = [];
        },
        getInitials(name) {
            if (!name) return '?';
            return name
                .split(' ')
                .map(n => n[0])
                .slice(0, 2)
                .join('')
                .toUpperCase();
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
