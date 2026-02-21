/**
 * Period Grouped View Component
 * Visualizes schedules grouped by Academic Level (Cohorts) to detect gaps and overlaps.
 */

window.SchedulerComponents = window.SchedulerComponents || {};

window.SchedulerComponents.PeriodGroupedView = {
    props: ['periodId'],
    template: `
        <div class="flex flex-col h-[calc(100vh-200px)] bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <!-- Header Controls -->
            <div class="p-3 border-b border-gray-200 flex justify-between items-center bg-slate-50">
                <div class="flex items-center gap-4">
                     <h3 class="font-bold text-slate-700 text-sm">Vista por Niveles (Cohortes)</h3>
                     <div class="flex gap-2">
                        <span class="flex items-center gap-1 text-xs text-slate-500">
                            <span class="w-2 h-2 rounded-full bg-blue-500"></span> Mañana
                        </span>
                        <span class="flex items-center gap-1 text-xs text-slate-500">
                            <span class="w-2 h-2 rounded-full bg-indigo-500"></span> Noche
                        </span>
                     </div>
                </div>
            </div>

            <!-- Matrix -->
            <div class="flex-1 overflow-auto p-4">
                <div v-for="(group, levelName) in groupedSchedules" :key="levelName" class="mb-8 border border-slate-200 rounded-lg overflow-hidden shadow-sm">
                    <!-- Level Header -->
                    <div class="bg-slate-100 px-4 py-2 border-b border-slate-200 flex justify-between items-center">
                        <h4 class="font-bold text-slate-800 text-sm">{{ levelName }}</h4>
                        <span class="text-xs text-slate-500 font-mono">{{ group.totalHours }} horas asignadas</span>
                    </div>

                    <!-- Days Grid -->
                    <div class="grid grid-cols-6 divide-x divide-slate-200 bg-white">
                        <div v-for="day in days" :key="day" class="min-h-[150px]">
                            <div class="bg-slate-50 px-2 py-1 text-[10px] font-bold text-center text-slate-500 border-b border-slate-100 uppercase">
                                {{ day }}
                            </div>
                            <div class="p-2 space-y-2">
                                <div v-for="cls in getClasses(group.classes, day)" :key="cls.id" 
                                    class="p-2 rounded border text-xs relative group hover:shadow-md transition-all"
                                    :class="getCardClass(cls)"
                                >
                                    <div class="font-bold leading-tight mb-1">{{ cls.subjectName }}</div>
                                    <div class="flex justify-between items-center text-[10px] opacity-80">
                                        <span>{{ cls.start }} - {{ cls.end }}</span>
                                    </div>
                                    <div class="text-[10px] opacity-70 truncate mt-0.5">
                                        {{ cls.teacherName || 'Sin docente' }}
                                    </div>
                                    
                                     <!-- Tooltip on Hover -->
                                    <div class="absolute inset-0 bg-white/95 opacity-0 group-hover:opacity-100 transition-opacity p-2 flex flex-col justify-center items-center text-center border-2 border-blue-500 rounded z-10">
                                        <span class="font-bold text-blue-700">{{ cls.room || 'Sin aula' }}</span>
                                        <span class="text-[10px] text-slate-600 block mb-2">{{ cls.subperiod === 0 ? 'Semestral' : (cls.subperiod === 1 ? 'Bloque 1' : 'Bloque 2') }}</span>
                                        <button @click.stop="viewStudents(cls)" class="px-2 py-1 bg-blue-100 text-blue-700 rounded text-[10px] font-bold hover:bg-blue-200 pointer-events-auto">
                                            <i data-lucide="users" class="w-3 h-3 inline mr-1"></i> Ver Estudiantes
                                        </button>
                                    </div>
                                </div>
                                <div v-if="getClasses(group.classes, day).length === 0" class="h-full flex items-center justify-center">
                                    <span class="text-slate-300 text-[10px]">-</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div v-if="!groupedSchedules || Object.keys(groupedSchedules).length === 0" class="flex flex-col items-center justify-center h-full text-slate-400">
                     <i data-lucide="layout-list" class="w-12 h-12 mb-2 opacity-50"></i>
                     <p>No hay horarios asignados visibles con los filtros actuales.</p>
                </div>
            </div>

             <!-- View Students Modal -->
             <div v-if="studentsDialog" class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/20 backdrop-blur-sm" @click.self="studentsDialog = false">
                <div class="bg-white rounded-xl shadow-2xl w-full max-w-md overflow-hidden animate-in zoom-in-95 duration-200">
                    <div class="p-4 border-b border-slate-200 bg-slate-50 flex justify-between items-center">
                         <h4 class="font-bold text-slate-800">Estudiantes Asignados ({{ currentStudents.length }})</h4>
                         <button @click="studentsDialog = false"><i data-lucide="x" class="w-4 h-4 text-slate-400"></i></button>
                    </div>
                    <div class="p-0 max-h-[60vh] overflow-y-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-slate-50 text-xs text-slate-500 uppercase font-bold sticky top-0">
                                <tr>
                                    <th class="px-4 py-2 border-b">ID</th>
                                    <th class="px-4 py-2 border-b">Nombre</th>
                                    <th class="px-4 py-2 border-b">Carrera</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="stu in currentStudents" :key="stu.id" class="hover:bg-slate-50">
                                    <td class="px-4 py-2 font-mono text-xs text-slate-500">{{ stu.id }}</td>
                                    <td class="px-4 py-2 font-medium text-slate-800">{{ stu.name }}</td>
                                    <td class="px-4 py-2 text-xs text-slate-500">{{ stu.career }}</td>
                                </tr>
                                <tr v-if="currentStudents.length === 0">
                                    <td colspan="3" class="px-4 py-8 text-center text-slate-400 italic">
                                        No hay información de estudiantes disponible.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3 bg-slate-50 border-t border-slate-200 flex justify-end">
                        <button @click="studentsDialog = false" class="px-4 py-1.5 bg-slate-200 text-slate-700 rounded text-sm font-bold hover:bg-slate-300">Cerrar</button>
                    </div>
                </div>
             </div>
        </div>
    `,
    data() {
        return {
            days: ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado'],
            studentsDialog: false,
            currentStudents: []
        };
    },
    computed: {
        storeState() {
            return window.schedulerStore ? window.schedulerStore.state : {};
        },
        allClasses() {
            return (this.storeState && this.storeState.generatedSchedules) ? this.storeState.generatedSchedules : [];
        },
        groupedSchedules() {
            const filter = this.storeState.subperiodFilter;
            const careerFilter = this.storeState.careerFilter;
            const shiftFilter = this.storeState.shiftFilter;
            const groups = {};

            this.allClasses.forEach(c => {
                if (filter !== 0 && c.subperiod !== 0 && c.subperiod !== filter) return;
                if (!c.day || c.day === 'N/A') return;

                // Career filter (Robust check)
                if (careerFilter) {
                    const inList = c.careerList && c.careerList.includes(careerFilter);
                    const inString = c.career && c.career.includes(careerFilter);
                    if (!inList && !inString) return;
                }

                // Shift filter
                if (shiftFilter && c.shift !== shiftFilter) return;

                // Group Key: Career + Level + Shift
                // Actually, viewing by Level across careers might be messy if levels don't align.
                // Better: Group by Career, then Level.
                // Or "Career - Level - Shift" as the Header.
                const key = `${c.career || 'General'} - ${c.levelDisplay} (${c.shift})`;

                if (!groups[key]) {
                    groups[key] = {
                        classes: [],
                        totalHours: 0
                    };
                }
                groups[key].classes.push(c);

                // Approx duration
                const start = this.toMins(c.start);
                const end = this.toMins(c.end);
                groups[key].totalHours += (end - start) / 60;
            });

            // Sort keys?
            // Sort keys
            const sortedKeys = Object.keys(groups).sort();
            const result = {};
            sortedKeys.forEach(key => {
                result[key] = groups[key];
            });
            return result;
        }
    },
    updated() {
        if (window.lucide) window.lucide.createIcons();
    },
    methods: {
        getClasses(classes, day) {
            return classes.filter(c => c.day === day).sort((a, b) => this.toMins(a.start) - this.toMins(b.start));
        },
        toMins(t) {
            if (!t || typeof t !== 'string') return 0;
            const parts = t.split(':');
            if (parts.length < 2) return 0;
            const [h, m] = parts.map(Number);
            return h * 60 + m;
        },
        getCardClass(cls) {
            if (!cls) return 'bg-slate-50 border-slate-200 text-slate-700';

            const shift = cls.shift || '';
            if (shift.toLowerCase().includes('noche')) {
                return 'bg-indigo-50 border-indigo-200 text-indigo-900';
            }
            if (shift.toLowerCase().includes('mañana')) {
                return 'bg-blue-50 border-blue-200 text-blue-900';
            }
            return 'bg-slate-50 border-slate-200 text-slate-700';
        },
        viewStudents(cls) {
            if (!window.schedulerStore || !cls.studentIds) {
                this.currentStudents = [];
                this.studentsDialog = true;
                return;
            }

            const allStudents = window.schedulerStore.state.students || [];
            this.currentStudents = allStudents.filter(s => cls.studentIds.includes(s.dbId));
            this.studentsDialog = true;
        }
    }
};
