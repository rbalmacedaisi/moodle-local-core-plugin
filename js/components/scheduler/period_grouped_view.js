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
                     <h3 class="font-bold text-slate-700 text-sm">Vista por Periodo de Ingreso</h3>
                     <div class="flex gap-2">
                        <span class="flex items-center gap-1 text-xs text-slate-500">
                            <span class="w-2 h-2 rounded-full bg-blue-500"></span> Mañana
                        </span>
                        <span class="flex items-center gap-1 text-xs text-slate-500">
                            <span class="w-2 h-2 rounded-full bg-indigo-500"></span> Noche
                        </span>
                     </div>
                </div>
                <button @click="exportToPDF" class="flex items-center gap-2 px-3 py-1.5 bg-red-50 text-red-600 hover:bg-red-100 hover:text-red-700 font-bold text-xs rounded-lg transition-colors border border-red-100 shadow-sm">
                    <i data-lucide="file-text" class="w-4 h-4"></i> Exportar a PDF
                </button>
            </div>

            <!-- Matrix -->
            <div class="flex-1 overflow-auto p-4">
                <div v-for="(group, groupKey) in groupedSchedules" :key="groupKey" class="mb-8 border border-slate-200 rounded-lg overflow-hidden shadow-sm">
                    <!-- Level Header -->
                    <div class="bg-slate-700 px-4 py-2 border-b border-slate-200 flex justify-between items-center">
                        <div class="flex items-center gap-3">
                            <span class="bg-blue-600 text-white text-[10px] font-black px-2 py-0.5 rounded uppercase tracking-wide">{{ group.entryPeriod }}</span>
                            <span class="font-bold text-white text-sm">{{ group.career }}</span>
                            <span class="text-[11px] text-slate-300 bg-slate-600 px-2 py-0.5 rounded">{{ group.shift }}</span>
                        </div>
                        <span class="text-xs text-slate-300 font-mono">{{ group.totalHours.toFixed(1) }} h · {{ group.totalPeriodStudents }} est.</span>
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
                                    <div class="font-bold leading-tight mb-1">
                                        {{ cls.subjectName }}
                                        <span v-if="cls.isExternal" class="inline-block bg-amber-500 text-white text-[7px] px-1 rounded uppercase font-black ml-1">Externo</span>
                                    </div>
                                    <div class="flex justify-between items-center text-[10px] opacity-80">
                                        <span>{{ cls.start }} - {{ cls.end }}</span>
                                    </div>
                                    <div class="text-[10px] opacity-70 truncate mt-0.5" :title="cls.career">
                                        {{ cls.career }}
                                    </div>
                                    <div class="text-[10px] opacity-70 truncate mt-0.5 font-medium">
                                        {{ cls.teacherName || 'Sin docente' }}
                                    </div>
                                    
                                     <!-- Tooltip on Hover -->
                                    <div class="absolute inset-0 bg-white/95 opacity-0 group-hover:opacity-100 transition-opacity p-2 flex flex-col justify-center items-center text-center border-2 border-blue-500 rounded z-10">
                                        <span class="font-bold text-blue-700">{{ cls.room || 'Sin aula' }}</span>
                                        <span v-if="cls.isExternal" class="text-[9px] text-amber-600 font-bold uppercase mt-1">Periodo Externo</span>
                                        <span class="text-[9px] text-slate-500 italic mt-1">{{ cls.shift }}</span>
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

                    <!-- Fichas sin horario asignado -->
                    <div v-if="group.classes.filter(c => !c.day || c.day === 'N/A').length > 0"
                         class="border-t border-dashed border-slate-300 p-3 bg-slate-50">
                        <span class="text-[10px] font-bold text-slate-400 uppercase block mb-2">Sin asignar</span>
                        <div class="flex flex-wrap gap-2">
                            <div v-for="cls in group.classes.filter(c => !c.day || c.day === 'N/A')" :key="'na-'+cls.id"
                                 class="p-2 rounded border text-xs bg-amber-50 border-amber-200 text-amber-800 min-w-[120px]">
                                <div class="font-bold leading-tight mb-0.5">{{ cls.subjectName }}</div>
                                <div class="text-[10px] opacity-70 truncate">{{ cls.career }}</div>
                                <div class="text-[10px] text-amber-500 font-medium">Sin horario</div>
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
            const allStudents = this.storeState.students || [];

            // ── Paso 1: Consolidar fichas del mismo subjectName+shift+subperiod+carrera ──
            // Fichas divididas por quórum (gen-1, gen-2...) se fusionan en una sola.
            const consolidatedMap = {};
            this.allClasses.forEach(c => {
                if (filter !== 0 && c.subperiod !== 0 && c.subperiod !== filter) return;

                if (careerFilter) {
                    const inList = c.careerList && c.careerList.includes(careerFilter);
                    const inString = c.career && c.career.includes(careerFilter);
                    if (!inList && !inString) return;
                }
                if (shiftFilter && c.shift !== shiftFilter) return;

                const ck = `${c.subjectName}||${c.shift}||${c.subperiod}||${c.career}`;
                if (!consolidatedMap[ck]) {
                    consolidatedMap[ck] = {
                        ...c,
                        studentIds: [...(c.studentIds || [])],
                        _sidSet: new Set((c.studentIds || []).map(id => String(id))),
                        _placed: c.day && c.day !== 'N/A',
                    };
                } else {
                    const existing = consolidatedMap[ck];
                    (c.studentIds || []).forEach(id => {
                        const sid = String(id);
                        if (!existing._sidSet.has(sid)) {
                            existing._sidSet.add(sid);
                            existing.studentIds.push(id);
                        }
                    });
                    if (!existing._placed && c.day && c.day !== 'N/A') {
                        existing.day   = c.day;
                        existing.start = c.start;
                        existing.end   = c.end;
                        existing.room  = c.room;
                        existing._placed = true;
                    }
                    existing.studentCount = existing.studentIds.length;
                }
            });

            // ── Paso 2: Agrupar por (período de ingreso | carrera | jornada) ──
            // Clave compuesta: "PERÍODO_INGRESO|||CARRERA|||JORNADA"
            // Fichas externas → se procesan después en Paso 3.
            Object.values(consolidatedMap).forEach(c => {
                let entryPeriods = ['Sin Definir'];
                if (c.isExternal) {
                    return; // externas en Paso 3
                } else if (c.studentIds && c.studentIds.length > 0) {
                    const sidSet = new Set(c.studentIds.map(id => String(id)));
                    const classStudents = allStudents.filter(s =>
                        sidSet.has(String(s.dbId)) || sidSet.has(String(s.id))
                    );
                    if (classStudents.length > 0) {
                        const periodSet = new Set();
                        classStudents.forEach(s => periodSet.add(s.entry_period || 'Sin Definir'));
                        entryPeriods = Array.from(periodSet);
                    }
                }

                const career  = c.career  || 'Sin Carrera';
                const shift   = c.shift   || 'Sin Jornada';
                const durationHours = (c.day && c.day !== 'N/A')
                    ? (this.toMins(c.end) - this.toMins(c.start)) / 60
                    : 0;

                entryPeriods.forEach(ep => {
                    // Normalizar: una ficha puede tener multi-carrera (careerList); crear clave por cada carrera
                    const careers = (c.careerList && c.careerList.length > 0) ? c.careerList : [career];
                    careers.forEach(cr => {
                        const key = `${ep}|||${cr}|||${shift}`;
                        if (!groups[key]) {
                            groups[key] = {
                                entryPeriod: ep,
                                career: cr,
                                shift: shift,
                                classes: [],
                                totalHours: 0,
                                totalPeriodStudents: allStudents.filter(s =>
                                    (s.entry_period || 'Sin Definir') === ep
                                ).length,
                            };
                        }
                        groups[key].classes.push(c);
                        groups[key].totalHours += durationHours;
                    });
                });
            });

            // ── Paso 3: Añadir fichas externas a grupos de la misma carrera ──
            const externalClasses = Object.values(consolidatedMap).filter(c => c.isExternal);
            if (externalClasses.length > 0) {
                Object.keys(groups).forEach(key => {
                    const groupCareer = groups[key].career;
                    externalClasses.forEach(c => {
                        // Solo añadir si la ficha externa corresponde a la carrera del grupo
                        const careers = (c.careerList && c.careerList.length > 0) ? c.careerList : [c.career || ''];
                        if (careers.some(cr => cr === groupCareer)) {
                            groups[key].classes.push(c);
                        }
                    });
                });
            }

            // Ordenar: primero por período, luego carrera, luego jornada
            const sortedKeys = Object.keys(groups).sort();
            const result = {};
            sortedKeys.forEach(key => { result[key] = groups[key]; });
            return result;
        }
    },
    updated() {
        if (window.lucide) window.lucide.createIcons();
    },
    methods: {
        exportToPDF() {
            if (!window.SchedulerPDF) {
                alert("El módulo de exportación PDF no está cargado.");
                return;
            }

            // Extract Academic Period info
            let periodName = 'Periodo Académico';
            if (this.periodId && window.SchedulerComponents && window.SchedulerComponents.SchedulerView) {
                // Not strictly necessary since generateIntakePeriodPDF can take a string
            }

            const activePeriodInfo = (window.schedulerStore && window.schedulerStore.state.context.period)
                ? window.schedulerStore.state.context.period
                : '';

            window.SchedulerPDF.generateIntakePeriodPDF(this.groupedSchedules, activePeriodInfo, this.storeState.subperiodFilter, this.storeState.students || []);
        },
        getClasses(classes, day) {
            const normalize = s => s ? s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim() : '';
            const normDay = normalize(day);
            return classes.filter(c => normalize(c.day) === normDay).sort((a, b) => this.toMins(a.start) - this.toMins(b.start));
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
            const sidSet = new Set((cls.studentIds).map(id => String(id)));
            this.currentStudents = allStudents.filter(s =>
                sidSet.has(String(s.dbId)) || sidSet.has(String(s.id))
            );
            this.studentsDialog = true;
        }
    }
};
