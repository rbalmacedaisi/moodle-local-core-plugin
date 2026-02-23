/**
 * Schedule Report View Component (Vue 3 + Tailwind)
 * Replicates the original React reporting view with filtering, teacher assignment, and exports.
 */

window.SchedulerComponents = window.SchedulerComponents || {};

window.SchedulerComponents.ReportView = {
    props: ['periodId'],
    template: `
        <div class="flex flex-col h-full space-y-4 animate-in fade-in duration-500">
            <!-- REPORT HEADER -->
            <div class="flex flex-wrap md:flex-nowrap justify-between items-center bg-white p-4 rounded-xl shadow-sm border border-slate-200 gap-4">
                <div class="flex items-center gap-4">
                    <div class="bg-indigo-600 p-2 rounded-lg text-white shadow-lg shadow-indigo-100">
                        <i data-lucide="file-text" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <h2 class="text-lg font-black text-slate-800 leading-tight">Reporte de Horarios</h2>
                        <div class="flex items-center gap-2 mt-0.5" v-if="academicPeriod">
                            <p class="text-[10px] text-slate-500 font-bold uppercase tracking-wider">{{ academicPeriod.start }} al {{ academicPeriod.end }}</p>
                            <span class="text-[10px] text-slate-300">•</span>
                            <span class="text-[10px] text-indigo-600 font-bold uppercase">{{ reportedSchedules.length }} Sesiones listadas</span>
                        </div>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-4">
                    <button
                        @click="showOnlyWithRoom = !showOnlyWithRoom"
                        :class="['flex items-center gap-2 px-3 py-1.5 rounded-lg text-[10px] font-black uppercase transition-all border', showOnlyWithRoom ? 'bg-amber-50 border-amber-200 text-amber-700 shadow-sm' : 'bg-white border-slate-200 text-slate-500 hover:bg-slate-50']"
                    >
                        <i data-lucide="map-pin" :class="['w-3 h-3', showOnlyWithRoom ? 'text-amber-500' : 'text-slate-400']"></i>
                        {{ showOnlyWithRoom ? 'Filtrado: Solo con Aula' : 'Filtrar: Solo con Aula' }}
                    </button>
                    <button
                        v-if="hasActiveFilters"
                        @click="clearFilters"
                        class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-[10px] font-black uppercase transition-all border border-red-200 bg-red-50 text-red-600 hover:bg-red-100"
                    >
                        <i data-lucide="trash-2" class="w-3 h-3"></i>
                        Limpiar Filtros
                    </button>
                    <div class="flex gap-6 border-r border-slate-100 pr-6 mr-6 hidden md:flex">
                        <div class="text-right">
                            <div class="text-[10px] text-slate-400 font-bold uppercase">Horas Reloj</div>
                            <div class="text-xl font-black text-slate-700 flex items-center justify-end gap-1">
                                {{ totalHours.toFixed(1) }}h
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-[10px] text-blue-400 font-bold uppercase">Horas Académicas</div>
                            <div class="text-xl font-black text-blue-600 flex items-center justify-end gap-1">
                                {{ totalAcademicHours.toFixed(1) }}h
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-col gap-2">
                        <div class="flex gap-2">
                             <button
                                @click="exportToXLSX"
                                class="flex-1 flex items-center justify-center gap-2 bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-lg font-bold text-xs transition-all shadow-md shadow-green-100"
                            >
                                <i data-lucide="download" class="w-3.5 h-3.5"></i> Exportar Horarios
                            </button>
                            <button
                                @click="exportStudentsXLSX"
                                class="flex-1 flex items-center justify-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-lg font-bold text-xs transition-all shadow-md shadow-blue-100"
                            >
                                <i data-lucide="users" class="w-3.5 h-3.5"></i> Exportar Listado Est.
                            </button>
                        </div>
                        <div class="flex gap-2">
                             <button
                                @click="generateStudentPDFs"
                                class="flex-1 flex items-center justify-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1.5 rounded-lg font-bold text-xs transition-all shadow-md shadow-indigo-100"
                            >
                                <i data-lucide="file-text" class="w-3.5 h-3.5"></i> PDF Estudiantes
                            </button>
                            <button
                                @click="handleTeacherPDFs"
                                class="flex-1 flex items-center justify-center gap-2 bg-amber-600 hover:bg-amber-700 text-white px-3 py-1.5 rounded-lg font-bold text-xs transition-all shadow-md shadow-amber-100"
                            >
                                <i data-lucide="file-text" class="w-3.5 h-3.5"></i> PDF Docentes
                            </button>
                        </div>
                        <button
                            @click="onRestoreTeachers"
                            class="w-full flex items-center justify-center gap-2 bg-slate-100 hover:bg-red-50 text-slate-600 hover:text-red-700 px-3 py-1.5 rounded-lg font-bold text-xs transition-all border border-slate-200"
                            title="Limpia todas las asignaciones manuales en esta vista"
                        >
                            <i data-lucide="refresh-cw" class="w-3.5 h-3.5"></i> Restaurar Asignaciones
                        </button>
                    </div>
                </div>
            </div>

            <!-- TABLE CONTAINER -->
            <div class="flex-1 bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden flex flex-col min-h-[500px]">
                <div class="overflow-auto flex-1 relative">
                    <table class="w-full text-sm text-left border-collapse min-w-[1200px]">
                        <thead class="bg-slate-50 text-slate-500 text-[10px] uppercase font-bold sticky top-0 z-20 border-b border-slate-200 shadow-sm">
                            <tr>
                                <th class="p-3 border-r border-slate-100 min-w-[200px]">
                                    <div class="flex flex-col gap-2">
                                        <span>Asignatura</span>
                                        <input
                                            type="text"
                                            placeholder="Buscar..."
                                            v-model="columnFilters.subjectName"
                                            class="px-2 py-1 text-[9px] font-bold border border-slate-200 rounded bg-white w-full uppercase focus:ring-1 focus:ring-indigo-300 outline-none"
                                        />
                                    </div>
                                </th>
                                <th class="p-3 border-r border-slate-100 min-w-[150px]">
                                    <div class="flex flex-col gap-2">
                                        <span>Docente</span>
                                        <input
                                            type="text"
                                            placeholder="Buscar..."
                                            v-model="columnFilters.teacherName"
                                            class="px-2 py-1 text-[9px] font-bold border border-slate-200 rounded bg-white w-full uppercase focus:ring-1 focus:ring-indigo-300 outline-none"
                                        />
                                    </div>
                                </th>
                                <th class="p-3 border-r border-slate-100 min-w-[120px]">
                                    <div class="flex flex-col gap-2">
                                        <span>Grupo</span>
                                        <input
                                            type="text"
                                            placeholder="Buscar..."
                                            v-model="columnFilters.levelDisplay"
                                            class="px-2 py-1 text-[9px] font-bold border border-slate-200 rounded bg-white w-full uppercase focus:ring-1 focus:ring-indigo-300 outline-none"
                                        />
                                    </div>
                                </th>
                                <th class="p-3 border-r border-slate-100 min-w-[100px]">
                                    <div class="flex flex-col gap-2">
                                        <span>Día</span>
                                        <input
                                            type="text"
                                            placeholder="Filtrar..."
                                            v-model="columnFilters.day"
                                            class="px-2 py-1 text-[9px] font-bold border border-slate-200 rounded bg-white w-full uppercase focus:ring-1 focus:ring-indigo-300 outline-none"
                                        />
                                    </div>
                                </th>
                                <th class="p-3 border-r border-slate-100 text-center">Horario</th>
                                <th class="p-3 border-r border-slate-100 text-center">Fechas</th>
                                <th class="p-3 border-r border-slate-100 min-w-[120px]">
                                    <div class="flex flex-col gap-2">
                                        <span>Aula</span>
                                        <input
                                            type="text"
                                            placeholder="Filtrar..."
                                            v-model="columnFilters.room"
                                            class="px-2 py-1 text-[9px] font-bold border border-slate-200 rounded bg-white w-full uppercase focus:ring-1 focus:ring-indigo-300 outline-none"
                                        />
                                    </div>
                                </th>
                                <th class="p-3 border-r border-slate-100 text-center" title="Estudiantes">Est.</th>
                                <th class="p-3 border-r border-slate-100 text-center" title="Sesiones en el Periodo">Ses.</th>
                                <th class="p-3 border-r border-slate-100 text-center bg-blue-50/30">Carga Teórica</th>
                                <th class="p-3 border-r border-slate-100 text-center">Horas Reloj</th>
                                <th class="p-3 text-center text-blue-600">Horas Acad.</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="(s, idx) in reportedSchedules" :key="s.id || idx" class="hover:bg-slate-50 transition-colors">
                                <td class="p-3 font-bold text-slate-700 border-r border-slate-50 max-w-[250px] truncate" :title="s.subjectName">
                                    {{ s.subjectName }}
                                </td>
                                <td
                                    class="p-3 border-r border-slate-50 font-medium text-blue-600 cursor-pointer hover:bg-blue-50 transition-colors group relative"
                                    @click="openTeacherModal(s)"
                                    title="Click para asignar docente"
                                >
                                    <div class="flex items-center gap-2">
                                        <template v-if="s.teacherName">
                                            <span :class="s.isManualTeacher ? 'text-amber-600 font-bold' : ''">{{ s.teacherName }}</span>
                                            <span v-if="s.isManualTeacher" class="bg-amber-100 text-amber-700 text-[8px] px-1 rounded uppercase">Manual</span>
                                        </template>
                                        <span v-else class="text-slate-300 italic group-hover:text-blue-400">No asignado</span>
                                        <i data-lucide="users" class="w-3 h-3 opacity-0 group-hover:opacity-100 text-blue-400 absolute right-2"></i>
                                    </div>
                                </td>
                                <td class="p-3 border-r border-slate-50 relative group">
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="text-xs font-black text-indigo-700">
                                            {{ s.levelDisplay }} • G-{{ s.subGroup || 1 }}
                                        </div>
                                        <template v-if="s.cohortsInvolved && s.cohortsInvolved.length > 0">
                                            <i data-lucide="info" class="w-3 h-3 text-slate-300 hover:text-indigo-500 cursor-help transition-colors"></i>
                                            <div class="absolute left-full top-0 ml-2 hidden group-hover:block z-50 w-64 bg-slate-800 text-white text-[9px] p-2 rounded-lg shadow-xl border border-slate-700">
                                                <p class="font-black border-b border-slate-600 mb-1 pb-1 text-indigo-300">Composición Subgrupo {{ s.subGroup || 1 }}:</p>
                                                <div class="flex flex-wrap gap-1 mt-1">
                                                    <span v-for="c in s.cohortsInvolved" :key="c" class="bg-slate-700 px-1 rounded">{{ c }}</span>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </td>
                                <td class="p-3 border-r border-slate-50 font-medium text-slate-600">{{ s.day }}</td>
                                <td class="p-3 border-r border-slate-50 text-center">
                                    <span class="bg-slate-100 text-slate-600 px-1.5 py-1 rounded text-[10px] font-bold font-mono whitespace-nowrap">
                                        {{ formatAMPM(s.start) }} - {{ formatAMPM(s.end) }}
                                    </span>
                                </td>
                                <td class="p-3 border-r border-slate-50 text-center">
                                    <div class="flex flex-col text-[9px] font-bold text-slate-500 leading-tight whitespace-nowrap">
                                        <span>{{ getSessionRange(s).start }}</span>
                                        <span class="text-[8px] opacity-50 font-normal">hasta</span>
                                        <span>{{ getSessionRange(s).end }}</span>
                                    </div>
                                </td>
                                <td class="p-3 border-r border-slate-50 font-bold text-slate-600">
                                    <span v-if="s.room === 'SIN AULA' || !s.room" class="text-red-500 italic">No asignada</span>
                                    <span v-else>{{ s.room }}</span>
                                </td>
                                <td class="p-3 border-r border-slate-50 text-center font-bold text-slate-600">
                                    {{ s.studentCount || 0 }}
                                </td>
                                <td class="p-3 border-r border-slate-50 text-center font-bold text-slate-500">
                                    {{ getSessionCount(s) }}x
                                </td>
                                <td class="p-3 border-r border-slate-50 text-center font-bold text-slate-400 bg-slate-50/50">
                                    {{ s.totalLoad || '--' }}h
                                </td>
                                <td class="p-3 border-r border-slate-100 text-center">
                                    <span :class="['font-black px-2 py-1 rounded-full text-xs', isOverloaded(s) ? 'bg-amber-100 text-amber-700' : 'bg-indigo-50 text-indigo-600']">
                                        {{ getTotalPeriodHrs(s).toFixed(1) }}h
                                    </span>
                                </td>
                                <td class="p-3 text-center">
                                    <span class="font-black text-blue-600 bg-blue-50 px-2 py-1 rounded-full text-xs">
                                        {{ getTotalAcademicHrs(s).toFixed(1) }}h
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="reportedSchedules.length === 0">
                                <td colspan="12" class="p-12 text-center text-slate-400 italic font-bold">
                                    No hay datos que coincidan con los filtros.
                                </td>
                            </tr>
                        </tbody>
                        <tfoot class="bg-slate-50 font-bold border-t border-slate-200">
                            <tr>
                                <td colspan="10" class="p-4 text-right uppercase text-[10px] text-slate-500 border-r border-slate-100">Totales Listados</td>
                                <td class="p-4 text-center text-indigo-700 text-lg font-black border-r border-slate-100">{{ totalHours.toFixed(1) }}h</td>
                                <td class="p-4 text-center text-blue-700 text-lg font-black">{{ totalAcademicHours.toFixed(1) }}h</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <!-- TEACHER SELECTION MODAL -->
            <div v-if="editingTeacherSchedule" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4 backdrop-blur-sm animate-in fade-in duration-200" @click.self="editingTeacherSchedule = null">
                <div class="bg-white rounded-xl shadow-2xl max-w-md w-full overflow-hidden animate-in zoom-in-95 duration-200 flex flex-col max-h-[90vh]">
                    <div class="p-4 border-b border-slate-100 bg-slate-50 flex justify-between items-center shrink-0">
                        <div>
                            <h3 class="font-bold text-slate-800">Asignar Docente</h3>
                            <p class="text-xs text-slate-500 font-medium truncate max-w-[300px]">{{ editingTeacherSchedule.subjectName }}</p>
                        </div>
                        <button @click="editingTeacherSchedule = null" class="text-slate-400 hover:text-slate-600">
                            <i data-lucide="x" class="w-5 h-5"></i>
                        </button>
                    </div>

                    <div class="p-2 overflow-y-auto flex-1">
                        <div class="space-y-1">
                            <button
                                @click="assignTeacher(null)"
                                class="w-full text-left px-4 py-3 rounded-lg hover:bg-red-50 text-red-600 font-bold text-sm transition-colors flex items-center gap-2 mb-2 border border-dashed border-red-200 group"
                            >
                                <i data-lucide="trash-2" class="w-4 h-4 opacity-70 group-hover:opacity-100"></i> Desasignar / Vacante
                            </button>

                            <template v-if="uniqueCandidates.length > 0">
                                <button
                                    v-for="(teacher, index) in uniqueCandidates"
                                    :key="index"
                                    @click="assignTeacher(teacher.name, teacher.id)"
                                    :class="['w-full text-left px-4 py-3 rounded-lg font-medium text-sm transition-colors flex items-center justify-between group', 
                                             editingTeacherSchedule.teacherName === teacher.name ? 'bg-indigo-50 text-indigo-700 border border-indigo-200' : 'hover:bg-slate-50 text-slate-700 border border-transparent']"
                                >
                                    <span class="flex items-center gap-2">
                                        <div :class="['w-2 h-2 rounded-full', editingTeacherSchedule.teacherName === teacher.name ? 'bg-indigo-500' : 'bg-slate-300 group-hover:bg-indigo-300']"></div>
                                        {{ teacher.name }}
                                    </span>
                                    <i v-if="editingTeacherSchedule.teacherName === teacher.name" data-lucide="check-circle-2" class="w-4 h-4 text-indigo-600"></i>
                                </button>
                            </template>
                            <div v-else class="p-6 text-center text-slate-400">
                                <i data-lucide="users" class="w-8 h-8 mx-auto mb-2 opacity-30"></i>
                                <p class="text-sm font-medium">No se encontraron docentes con esta competencia.</p>
                                <p class="text-xs mt-1">Verifique la carga de disponibilidades.</p>
                            </div>
                        </div>
                    </div>
                    <div class="p-3 bg-slate-50 border-t border-slate-100 text-center shrink-0">
                        <button @click="editingTeacherSchedule = null" class="text-xs font-bold text-slate-500 hover:text-slate-700">Cancelar</button>
                    </div>
                </div>
            </div>

            <!-- Footer Note -->
            <div class="bg-indigo-50 border border-indigo-100 p-3 rounded-xl flex items-start sm:items-center gap-3">
                <div class="p-1.5 bg-indigo-600 rounded-lg text-white shadow-sm shadow-indigo-100 shrink-0"><i data-lucide="info" class="w-3.5 h-3.5"></i></div>
                <p class="text-[11px] text-indigo-800 leading-tight font-medium">
                    <b>Detalle de Subgrupos:</b> Pasa el cursor sobre el icono de información en la columna <b>Grupo</b> para ver el listado de cohortes.<br class="hidden sm:block"/>
                    <b>Asignación Manual:</b> Haga clic en el nombre de un docente en la tabla para reasignar la clase basado en las competencias cargadas.
                </p>
            </div>
        </div>
    `,
    data() {
        return {
            editingTeacherSchedule: null,
            showOnlyWithRoom: false,
            columnFilters: {
                subjectName: '',
                teacherName: '',
                levelDisplay: '',
                day: '',
                room: ''
            }
        };
    },
    computed: {
        storeState() {
            return window.schedulerStore ? window.schedulerStore.state : {};
        },
        academicPeriod() {
            return this.storeState.context?.period || { start: 'Inicio', end: 'Fin' };
        },
        allSchedules() {
            const filter = this.storeState.subperiodFilter;
            const generated = this.storeState.generatedSchedules || [];
            if (filter === 0) return generated;
            return generated.filter(c => c.subperiod === 0 || c.subperiod === filter);
        },
        reportedSchedules() {
            let list = this.allSchedules;

            // Only assigned to a day
            list = list.filter(s => s.day && s.day !== 'N/A');

            if (this.showOnlyWithRoom) {
                list = list.filter(s => s.room && s.room !== 'SIN AULA' && s.room !== 'Por Asignar');
            }

            Object.keys(this.columnFilters).forEach(col => {
                const query = this.columnFilters[col].toLowerCase().trim();
                if (query) {
                    list = list.filter(s => {
                        // levelDisplay vs career
                        const val = String(s[col] || '').toLowerCase();
                        return val.includes(query);
                    });
                }
            });

            return list;
        },
        hasActiveFilters() {
            return this.showOnlyWithRoom || Object.values(this.columnFilters).some(v => v !== '');
        },
        dayCounts() {
            const counts = { 'Lunes': 0, 'Martes': 0, 'Miercoles': 0, 'Jueves': 0, 'Viernes': 0, 'Sabado': 0, 'Domingo': 0 };
            // Use context holidays or empty array
            const holidays = this.storeState.context?.holidays || [];
            if (!this.academicPeriod?.start || !this.academicPeriod?.end) return counts;

            const dayMap = ['Domingo', 'Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado'];
            const holidaySet = new Set(holidays.map(h => h.date));

            // Parse safe UTC dates avoiding timezone shifts
            const startStr = this.academicPeriod.start;
            const endStr = this.academicPeriod.end;
            if (!startStr.includes('-') || !endStr.includes('-')) return counts; // Invalid

            let d = new Date(startStr + 'T00:00:00');
            const end = new Date(endStr + 'T00:00:00');

            while (d <= end) {
                const dayIdx = d.getDay();
                const isoDate = d.toISOString().split('T')[0];
                if (!holidaySet.has(isoDate)) {
                    // Miercoles without accent mapping
                    const dayName = dayMap[dayIdx] === 'Miércoles' ? 'Miercoles' : dayMap[dayIdx];
                    counts[dayName]++;
                }
                d.setDate(d.getDate() + 1);
            }
            return counts;
        },
        totalHours() {
            return this.reportedSchedules.reduce((acc, s) => acc + this.getTotalPeriodHrs(s), 0);
        },
        totalAcademicHours() {
            return this.reportedSchedules.reduce((acc, s) => acc + this.getTotalAcademicHrs(s), 0);
        },
        uniqueCandidates() {
            if (!this.editingTeacherSchedule) return [];
            const subj = this.editingTeacherSchedule.subjectName.toLowerCase();
            const instructors = this.storeState.instructors || [];

            const matches = instructors.filter(inst => {
                return inst.competencyNames && inst.competencyNames.some(c => c.toLowerCase() === subj);
            });
            return matches.map(m => ({ id: m.instructorId || m.id, name: m.instructorName }));
        }
    },
    mounted() {
        this.$nextTick(() => { if (window.lucide) window.lucide.createIcons(); });

        // Ensure XLSX is available. If not, try loading it dynamically.
        if (typeof XLSX === 'undefined') {
            const script = document.createElement('script');
            script.src = 'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js';
            document.head.appendChild(script);
        }

        // Ensure jsPDF and AutoTable are available for PDFs
        if (typeof jspdf === 'undefined' || typeof jsPDF === 'undefined') {
            const script = document.createElement('script');
            script.src = 'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js';
            document.head.appendChild(script);

            script.onload = () => {
                const autoTableScript = document.createElement('script');
                autoTableScript.src = 'https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js';
                document.head.appendChild(autoTableScript);
            };
        }
    },
    updated() {
        if (window.lucide) window.lucide.createIcons();
    },
    methods: {
        clearFilters() {
            this.columnFilters = { subjectName: '', teacherName: '', levelDisplay: '', day: '', room: '' };
            this.showOnlyWithRoom = false;
        },
        formatAMPM(timeStr) {
            if (!timeStr) return '';
            const parts = timeStr.split(':');
            let h = parseInt(parts[0], 10);
            const m = parts[1] || '00';
            const ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12;
            h = h ? h : 12;
            return `${h}:${m} ${ampm}`;
        },
        getSessionCount(s) {
            if (s.assignedDates) return s.assignedDates.length;
            let occurrences = this.dayCounts[s.day] || 0;
            if (s.maxSessions && s.maxSessions > 0) {
                occurrences = Math.min(occurrences, s.maxSessions);
            }
            return occurrences;
        },
        getTotalPeriodHrs(s) {
            if (!s.start || !s.end) return 0;
            const [h1, m1] = s.start.split(':').map(Number);
            const [h2, m2] = s.end.split(':').map(Number);
            const weeklyHrs = ((h2 * 60 + m2) - (h1 * 60 + m1)) / 60;
            return weeklyHrs * this.getSessionCount(s);
        },
        getTotalAcademicHrs(s) {
            if (!s.start || !s.end) return 0;
            const [h1, m1] = s.start.split(':').map(Number);
            const [h2, m2] = s.end.split(':').map(Number);
            const totalMins = ((h2 * 60 + m2) - (h1 * 60 + m1)) * this.getSessionCount(s);
            return totalMins / 45; // 1 academic hr = 45 mins
        },
        isOverloaded(s) {
            return this.getTotalPeriodHrs(s) > (s.totalLoad || 0) + 0.5;
        },
        getSessionRange(s) {
            if (s.assignedDates && s.assignedDates.length > 0) {
                const sorted = [...s.assignedDates].sort();
                return { start: sorted[0], end: sorted[sorted.length - 1] };
            }
            return {
                start: this.academicPeriod?.start || '',
                end: this.academicPeriod?.end || ''
            };
        },
        openTeacherModal(s) {
            this.editingTeacherSchedule = s;
            this.$nextTick(() => { if (window.lucide) window.lucide.createIcons(); });
        },
        async assignTeacher(teacherName, teacherId = null) {
            if (!this.editingTeacherSchedule) return;

            // Re-assign locally and mark as manual
            this.editingTeacherSchedule.teacherName = teacherName;
            this.editingTeacherSchedule.instructorId = teacherId;
            this.editingTeacherSchedule.isManualTeacher = !!teacherName;

            // Try saving through store to persist changes
            try {
                if (window.schedulerStore) {
                    window.schedulerStore.state.successMessage = 'Docente modificado temporalmente. Recuerde guardar los cambios en el Tablero de Planificación.';
                    // Or call an explicit save: await window.schedulerStore.saveGeneration(this.periodId, this.allSchedules);
                }
            } catch (e) {
                console.error("Error updating teacher", e);
            }

            this.editingTeacherSchedule = null;
        },
        onRestoreTeachers() {
            if (confirm("¿Estás seguro de que deseas limpiar todas las asignaciones manuales en esta vista? Se intentará restaurar al último estado guardado o generado.")) {
                this.reportedSchedules.forEach(s => {
                    if (s.isManualTeacher) {
                        // Reset flags (requires a re-generation logic ideally, but UI reset is starting point)
                        s.teacherName = null;
                        s.instructorId = null;
                        s.isManualTeacher = false;
                    }
                });
                alert("Asignaciones manuales restablecidas.");
            }
        },
        checkXLSX() {
            if (typeof XLSX === 'undefined') {
                alert("La biblioteca de exportación a Excel no está disponible. Por favor intente en unos segundos.");
                return false;
            }
            return true;
        },
        exportToXLSX() {
            if (!this.checkXLSX()) return;

            const data = this.reportedSchedules.map(s => {
                const range = this.getSessionRange(s);
                return {
                    'Asignatura': s.subjectName,
                    'Docente': s.teacherName || 'Sin asignar',
                    'Jornada': s.shift,
                    'Grupo': s.levelDisplay,
                    'Subgrupo': s.subGroup || 1,
                    'Detalle Grupos': (s.cohortsInvolved || []).join(', '),
                    'Dia': s.day,
                    'Inicio': this.formatAMPM(s.start),
                    'Fin': this.formatAMPM(s.end),
                    'Fecha Inicio': range.start,
                    'Fecha Fin': range.end,
                    'Aula': s.room,
                    'Estudiantes': s.studentCount || 0,
                    'Sesiones Periodo': this.getSessionCount(s),
                    'Carga Teórica (h)': s.totalLoad || 0,
                    'Horas Reloj (60m)': Number(this.getTotalPeriodHrs(s).toFixed(2)),
                    'Horas Académicas (45m)': Number(this.getTotalAcademicHrs(s).toFixed(2))
                };
            });

            const worksheet = XLSX.utils.json_to_sheet(data);
            const workbook = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(workbook, worksheet, "Horarios");
            const filename = 'Reporte_Horarios_' + new Date().toISOString().slice(0, 10) + '.xlsx';
            XLSX.writeFile(workbook, filename);
        },
        exportStudentsXLSX() {
            if (!this.checkXLSX()) return;
            const allStudents = this.storeState.students || [];

            const studentRows = [];
            this.reportedSchedules.forEach(s => {
                const totalHrs = Number(this.getTotalPeriodHrs(s).toFixed(2));
                const acadHrs = Number(this.getTotalAcademicHrs(s).toFixed(2));

                if (s.studentIds && s.studentIds.length > 0) {
                    // Get student objects from global store
                    const classStudents = allStudents.filter(st =>
                        s.studentIds.some(sid => String(sid) === String(st.dbId || st.id))
                    );

                    classStudents.forEach(st => {
                        studentRows.push({
                            'Asignatura': s.subjectName,
                            'Docente': s.teacherName || 'Sin asignar',
                            'Nivel': st.level || 'N/A',
                            'Grupo': s.levelDisplay,
                            'Subgrupo': 'G-' + (s.subGroup || 1),
                            'Dia': s.day,
                            'Horario': this.formatAMPM(s.start) + ' - ' + this.formatAMPM(s.end),
                            'Aula': s.room,
                            'Carga Teórica (h)': s.totalLoad || 0,
                            'Horas Reloj (60m)': totalHrs,
                            'Horas Académicas (45m)': acadHrs,
                            'ID Estudiante': st.id,
                            'Nombre Estudiante': st.name,
                            'Carrera': st.career,
                            'Jornada': st.shift || s.shift,
                            'Periodo Entrada': st.entry_period || 'N/A'
                        });
                    });
                }
            });

            if (studentRows.length === 0) {
                alert("No hay estudiantes asignados en las clases filtradas para exportar.");
                return;
            }

            const worksheet = XLSX.utils.json_to_sheet(studentRows);
            const workbook = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(workbook, worksheet, "Listado de Estudiantes");
            const filename = 'Listado_Estudiantes_' + new Date().toISOString().slice(0, 10) + '.xlsx';
            XLSX.writeFile(workbook, filename);
        },
        generateStudentPDFs() {
            // Uses standard window.jspdf format normally loaded via script
            if (!window.jspdf || !window.jspdf.jsPDF) {
                alert("La biblioteca de PDF no está lista aún. Intente nuevamente.");
                return;
            }

            const jsPDF = window.jspdf.jsPDF;
            const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });

            const allStudents = this.storeState.students || [];
            console.log("ReportView: allStudents count:", allStudents.length);

            // Extract unique students from currently filtered schedules
            const studentSet = new Set();
            this.reportedSchedules.forEach(s => {
                if (s.studentIds) {
                    s.studentIds.forEach(id => {
                        studentSet.add(String(id));
                    });
                }
            });
            console.log("ReportView: studentSet unique IDs:", Array.from(studentSet));

            const studentsToReport = allStudents.filter(st => {
                // Check both potential identifiers for a match
                const sid = String(st.id);
                const sDbId = st.dbId ? String(st.dbId) : null;
                return studentSet.has(sid) || (sDbId && studentSet.has(sDbId));
            });
            console.log("ReportView: studentsToReport count:", studentsToReport.length);

            if (studentsToReport.length === 0) {
                alert("No hay estudiantes para generar reportes en las clases seleccionadas.");
                return;
            }

            studentsToReport.forEach((student, index) => {
                if (index > 0) doc.addPage();

                const sid = String(student.id);
                const sDbId = student.dbId ? String(student.dbId) : null;

                const studentSchedules = this.reportedSchedules.filter(s =>
                    s.studentIds && s.studentIds.some(qsid => {
                        const qs = String(qsid);
                        return qs === sid || (sDbId && qs === sDbId);
                    })
                );

                // 1. Header Section
                doc.setFontSize(10);
                doc.setFont("helvetica", "bold");
                doc.text("REPORTES MOODLE - INSTITUCIONAL", 148.5, 10, { align: "center" });
                doc.text("HORARIO DE ESTUDIANTE", 148.5, 16, { align: "center" });

                // Box for logo
                doc.setDrawColor(200);
                doc.rect(14, 6, 40, 15);
                doc.setFontSize(7);
                doc.text("Logo Moodle", 34, 15, { align: "center" });

                // Metadata Row
                if (doc.autoTable) {
                    doc.autoTable({
                        startY: 25,
                        head: [['Código: SCH-01', 'Generado desde Plugin', 'Fecha de Emisión : ' + new Date().toLocaleDateString(), 'Página: ' + (index + 1) + ' de ' + studentsToReport.length]],
                        theme: 'plain',
                        styles: { fontSize: 8, fontStyle: 'normal', cellPadding: 1, border: { top: 0.1, bottom: 0.1, left: 0.1, right: 0.1 } },
                        columnStyles: { 0: { cellWidth: 40 }, 1: { cellWidth: 50 }, 2: { cellWidth: 100 }, 3: { cellWidth: 'auto', halign: 'right' } }
                    });

                    // Student Info Table
                    doc.autoTable({
                        startY: doc.lastAutoTable.finalY + 2,
                        body: [
                            [
                                { content: 'Estudiante: ' + student.name, styles: { fontStyle: 'bold' } },
                                { content: 'N° Identificación: ' + student.id, styles: { fontStyle: 'bold' } }
                            ],
                            [
                                { content: 'Programa: ' + (student.career || 'N/A'), styles: { fontStyle: 'bold' } },
                                { content: 'Periodo: ' + (this.academicPeriod.id || 'N/A'), styles: { fontStyle: 'bold' } }
                            ],
                            [
                                { content: 'Jornada: ' + (student.shift || 'N/A'), styles: { fontStyle: 'bold' } },
                                { content: 'Grupo(s): ' + ([...new Set(studentSchedules.map(s => s.levelDisplay))].join(', ') || ''), styles: { fontStyle: 'bold' } }
                            ]
                        ],
                        theme: 'grid',
                        styles: { fontSize: 8, cellPadding: 1.5, lineColor: [200, 200, 200], lineWidth: 0.1 },
                        columnStyles: { 0: { cellWidth: 160 }, 1: { cellWidth: 'auto' } }
                    });

                    // Weekly Grid
                    const days = ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado', 'Domingo'];
                    const displayDays = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
                    const gridBody = [[]];

                    days.forEach((day, dIdx) => {
                        const dayClasses = studentSchedules.filter(s => s.day === day || s.day === day.replace('é', 'e'));
                        let cellText = "";
                        dayClasses.forEach(cls => {
                            cellText += 'Horario: ' + cls.start + ' - ' + cls.end + '\n';
                            cellText += 'Aula: ' + cls.room + '\n';
                            cellText += 'Asignatura: ' + cls.subjectName + '\n';
                            cellText += 'Docente: ' + (cls.teacherName || 'POR ASIGNAR') + '\n\n';
                        });
                        gridBody[0][dIdx] = cellText;
                    });

                    doc.autoTable({
                        startY: doc.lastAutoTable.finalY + 5,
                        head: [displayDays],
                        body: gridBody,
                        theme: 'grid',
                        styles: { fontSize: 7, cellPadding: 2, overflow: 'linebreak', halign: 'left', valign: 'top', lineColor: [150, 150, 150], lineWidth: 0.1 },
                        headStyles: { fillColor: [240, 240, 240], textColor: [50, 50, 50], fontStyle: 'bold', halign: 'center' },
                        columnStyles: {
                            0: { cellWidth: 38 }, 1: { cellWidth: 38 }, 2: { cellWidth: 42 }, 3: { cellWidth: 38 },
                            4: { cellWidth: 38 }, 5: { cellWidth: 38 }, 6: { cellWidth: 38 }
                        }
                    });
                } else {
                    doc.text("Error: modulo autoTable no encontrado en el contexto actual", 14, 40);
                }
            });

            const pdfFilename = 'Horarios_Estudiantes_' + new Date().toISOString().slice(0, 10) + '.pdf';
            doc.save(pdfFilename);
        },
        handleTeacherPDFs() {
            if (window.SchedulerPDF && typeof window.SchedulerPDF.generateTeacherSchedulesPDF === 'function') {
                const state = window.schedulerStore.state;
                window.SchedulerPDF.generateTeacherSchedulesPDF(this.reportedSchedules, this.academicPeriod?.name || '', state.subperiodFilter);
            } else {
                alert("La funcionalidad de exportar PDF de Docentes no está disponible. Requiere SchedulerPDF.");
            }
        }
    }
};
