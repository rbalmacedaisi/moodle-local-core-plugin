/**
 * Planning Board Component (Vue 3 + Tailwind)
 * Interactive drag-and-drop interface for scheduling classes.
 */

window.SchedulerComponents = window.SchedulerComponents || {};

window.SchedulerComponents.PlanningBoard = {
    props: ['periodId'],
    template: `
        <div class="flex flex-col h-[calc(100vh-200px)] overflow-hidden bg-white rounded-xl shadow-sm border border-gray-100">
             <div class="flex h-full">
                <!-- Unassigned List (Left) -->
                <div class="w-1/4 h-full flex flex-col border-r border-gray-200 bg-slate-50">
                    <div class="p-3 border-b border-gray-200 bg-white shadow-sm flex items-center justify-between">
                        <h3 class="text-sm font-bold text-slate-700">
                            Por Asignar ({{ unassignedClasses.length }})
                            <span class="ml-2 text-[10px] text-amber-600 font-bold bg-amber-50 px-1.5 py-0.5 rounded border border-amber-200">
                                {{ allClasses.filter(c => c.isExternal).length }} Externos | Total: {{ allClasses.length }}
                            </span>
                        </h3>
                        <div class="relative w-32">
                             <input type="text" v-model="search" placeholder="Busca..." class="w-full pl-7 pr-2 py-1 text-xs border rounded-lg focus:ring-1 focus:ring-blue-500 outline-none" />
                             <i data-lucide="search" class="absolute left-2 top-1.5 w-3 h-3 text-slate-400"></i>
                        </div>
                    </div>
                    
                    <div class="flex-1 overflow-y-auto p-2 space-y-2">
                        <div v-for="cls in filteredUnassigned" :key="cls.id"
                            draggable="true"
                            @dragstart="onDragStart($event, cls)"
                            class="bg-white p-3 rounded-lg border border-slate-200 shadow-sm cursor-grab active:cursor-grabbing hover:border-blue-400 transition-colors group relative"
                        >
                            <div class="flex justify-between items-start mb-1">
                                <span class="font-bold text-slate-800 text-sm leading-tight line-clamp-2" :title="cls.subjectName">{{ cls.subjectName }}</span>
                                <span class="text-[10px] font-mono bg-slate-100 px-1.5 py-0.5 rounded text-slate-500">{{ cls.studentCount }}</span>
                            </div>
                            <div class="text-[10px] flex flex-wrap gap-1 items-center text-slate-500">
                                <span class="bg-blue-50 text-blue-700 px-1.5 py-0.5 rounded font-medium">{{ cls.levelDisplay }}</span>
                                <span>{{ cls.shift }}</span>
                                <div class="ml-auto flex gap-1">
                                    <button @click.stop="viewStudents(cls)" class="text-slate-400 hover:text-blue-600" title="Ver Estudiantes">
                                        <i data-lucide="users" class="w-3 h-3"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <!-- Placement Warning -->
                            <div v-if="cls.warning" class="mt-2 text-[10px] bg-orange-50 text-orange-700 p-1.5 rounded border border-orange-100 flex flex-col gap-1.5">
                                <div class="flex items-start gap-1">
                                    <i data-lucide="alert-circle" class="w-3 h-3 shrink-0 mt-0.5"></i>
                                    <span>{{ cls.warning }}</span>
                                </div>
                                <button v-if="cls.auditLog && cls.auditLog.length > 0" 
                                        @click.stop="viewAuditLog(cls)" 
                                        class="text-left text-orange-800 font-bold hover:underline flex items-center gap-1">
                                    <i data-lucide="scroll" class="w-3 h-3"></i>
                                    Ver Bitácora de Intentos
                                </button>
                            </div>

                            <!-- Assign Badge -->
                            <div class="absolute -right-1 -top-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                <span class="bg-blue-600 text-white rounded-full p-1"><i data-lucide="grip-vertical" class="w-3 h-3"></i></span>
                            </div>
                            </div>
                            <div v-if="filteredUnassigned.length === 0" class="text-center text-slate-400 text-xs py-4 italic">
                            No hay clases pendientes.
                        </div>
                        </div>
                    </div>

                <!-- Calendar Grid (Right) -->
                <div class="flex-1 h-full flex flex-col relative">
                    <div class="p-2 border-b border-gray-200 flex justify-between items-center bg-white z-10">
                         <div class="flex gap-2">
                             <button class="px-3 py-1 text-xs font-bold bg-slate-100 text-slate-700 rounded hover:bg-slate-200 transition-colors">Semana</button>
                         </div>
                         <button @click="saveChanges" :disabled="saving" class="flex items-center gap-2 px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg text-xs font-bold shadow-sm transition-colors">
                            <i data-lucide="save" class="w-3 h-3"></i>
                            {{ saving ? 'Guardando...' : 'Guardar Cambios' }}
                         </button>
                    </div>
                    
                    <div class="flex-1 overflow-auto relative bg-white">
                        <div class="min-w-[800px] relative">
                            <!-- Header -->
                            <div class="sticky top-0 z-20 flex border-b border-slate-200 bg-slate-50 shadow-sm">
                                <div class="w-12 shrink-0 border-r border-slate-200 bg-slate-50"></div> <!-- Time Col Header -->
                                <div v-for="day in days" :key="day" class="flex-1 py-2 text-center text-xs font-bold text-slate-600 uppercase border-r border-slate-200 last:border-0">
                                    {{ day }}
                                </div>
                            </div>
                            
                            <!-- Body -->
                            <div class="flex relative">
                                <!-- Time Labels Col -->
                                <div class="w-12 shrink-0 border-r border-slate-200 bg-white z-10">
                                    <div v-for="t in timeSlots" :key="t" class="h-14 border-b border-slate-100 text-[10px] text-slate-400 text-right pr-2 pt-1">
                                        {{ t }}
                                    </div>
                                </div>
                                
                                <!-- Day Columns -->
                                <div v-for="day in days" :key="day" 
                                    class="flex-1 border-r border-slate-200 last:border-0 relative min-h-[900px] bg-slate-50/10"
                                    @dragover.prevent
                                    @drop="onDrop($event, day)"
                                >
                                    <!-- Grid Lines -->
                                    <div v-for="t in timeSlots" :key="t" class="h-14 border-b border-slate-100 border-dashed"></div>
                                    
                                    <!-- Placed Events -->
                                    <div v-for="cls in getClassesForDay(day)" :key="cls.id"
                                        class="absolute left-1 right-1 rounded border-2 overflow-hidden p-1 shadow-md cursor-pointer hover:shadow-lg transition-all z-20 group"
                                        :class="[
                                            cls.isExternal ? 'bg-amber-100 border-amber-500 ring-2 ring-amber-200' : (getConflicts(cls).length > 0 ? 'bg-red-50 border-red-300' : 'bg-blue-50 border-blue-200 hover:border-blue-400')
                                        ]"
                                        :style="getEventStyle(cls)"
                                        :title="getConflictTooltip(cls)"
                                        :draggable="!cls.isExternal"
                                        @dragstart="onDragStart($event, cls)"
                                        @click="editClass(cls)"
                                    >
                                        <div class="text-[10px] font-bold leading-tight line-clamp-2" :class="cls.isExternal ? 'text-amber-800' : (getConflicts(cls).length > 0 ? 'text-red-800' : 'text-blue-800')">
                                            {{ cls.subjectName }}
                                            <span v-if="cls.isExternal" class="inline-block bg-amber-500 text-white text-[7px] px-1 rounded uppercase font-black ml-1">Externo</span>
                                            <span v-if="cls.excluded_dates && cls.excluded_dates.length > 0" class="inline-block bg-red-500 text-white text-[8px] px-1 rounded-full animate-pulse" title="Días liberados">
                                                {{ cls.excluded_dates.length }}
                                            </span>
                                        </div>
                                        <div class="text-[9px] leading-tight text-slate-500 mt-0.5">
                                            <span v-if="cls.teacherName" class="block font-medium text-slate-700 truncate">{{ cls.teacherName }}</span>
                                            <span v-else class="block text-orange-500 italic">Por asignar</span>
                                            <div class="flex items-center justify-between">
                                                <span class="truncate">{{ cls.room || 'Sin aula' }}</span>
                                                <span class="bg-blue-100 text-blue-700 font-bold px-1 rounded ml-1">{{ cls.typeLabel }}</span>
                                                <span v-if="cls.studentCount < 12" class="bg-red-100 text-red-600 px-1 rounded font-bold" title="Quórum Insuficiente">
                                                    <i data-lucide="users" class="w-2 h-2 inline-block -mt-1"></i> {{ cls.studentCount }}
                                                </span>
                                            </div>
                                        </div>
                                        
                                        <!-- Actions -->
                                        <div class="absolute bottom-1 right-1 flex gap-1">
                                            <button @click.stop="viewStudents(cls)" class="p-0.5 bg-white rounded shadow text-slate-500 hover:text-blue-600" title="Ver Estudiantes">
                                                <i data-lucide="users" class="w-3 h-3"></i>
                                            </button>
                                        </div>
                                        
                                        <!-- Conflict Indicator -->
                                        <div v-if="getConflicts(cls).length > 0" class="absolute top-0 right-0 p-0.5 bg-red-500 text-white rounded-bl">
                                            <i data-lucide="alert-triangle" class="w-3 h-3"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
             </div>

             <!-- Edit Modal (Tailwind) -->
             <div v-if="editDialog" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/20 backdrop-blur-sm" @click.self="editDialog = false">
                <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm overflow-hidden animate-in zoom-in-95 duration-200" v-if="selectedClass">
                     <div class="p-4 border-b border-slate-200 bg-slate-50 flex justify-between items-center">
                          <h4 class="font-bold text-slate-800">{{ selectedClass.isExternal ? 'Detalles de Clase Externa' : 'Editar Clase' }}</h4>
                          <button @click="editDialog = false"><i data-lucide="x" class="w-4 h-4 text-slate-400"></i></button>
                     </div>
                     <div class="p-4 space-y-3" :class="{'opacity-80': selectedClass.isExternal}">
                        <div v-if="selectedClass.isExternal" class="bg-amber-50 border border-amber-200 p-2 rounded-lg flex items-center gap-2 mb-2">
                            <i data-lucide="info" class="w-4 h-4 text-amber-600"></i>
                            <span class="text-[10px] font-bold text-amber-700 uppercase">Clase de otro periodo. Solo lectura.</span>
                        </div>
                        <div class="mb-3">
                            <h3 class="font-bold text-lg text-slate-800 leading-tight">{{ selectedClass.subjectName }}</h3>
                            <p class="text-xs text-slate-500 mt-1">{{ selectedClass.career }} &bull; {{ selectedClass.levelDisplay }}</p>
                        </div>
                        <div class="relative">
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Docente</label>
                            <div class="relative">
                                <input type="text" 
                                    v-model="teacherSearch" 
                                    @input="onTeacherChange"
                                    @focus="showTeacherList = true"
                                    @blur="hideTeacherList"
                                    :disabled="selectedClass.isExternal"
                                    class="w-full px-3 py-1.5 border rounded text-sm outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-slate-100 disabled:cursor-not-allowed" 
                                    placeholder="Buscar docente o 'Sin asignar'..." />
                                
                                <div v-if="showTeacherList && filteredInstructors.length > 0" 
                                    class="absolute z-[100] w-full mt-1 bg-white border border-slate-200 rounded-lg shadow-xl max-h-48 overflow-y-auto overflow-x-hidden">
                                    <div v-for="inst in filteredInstructors" :key="inst.instructorId"
                                        @mousedown="selectInstructor(inst)"
                                        class="px-3 py-2 hover:bg-blue-50 cursor-pointer text-sm border-b border-slate-100 last:border-0 flex justify-between items-center group">
                                        <span class="font-medium text-slate-700">{{ inst.instructorName }}</span>
                                        <span class="text-[9px] text-slate-400 group-hover:text-blue-500 uppercase font-bold">Seleccionar</span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Display current selection if not searching -->
                            <div v-if="!showTeacherList && selectedClass.teacherName" class="mt-1 flex items-center gap-1.5">
                                <span class="text-[10px] bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded font-bold uppercase">Actual: {{ selectedClass.teacherName }}</span>
                            </div>

                            <!-- Conflict Warnings in Modal -->
                            <div v-if="getTeacherConflicts(selectedClass).length > 0" class="mt-2 space-y-1">
                                <div v-for="conflict in getTeacherConflicts(selectedClass)" :key="conflict.type" class="text-[10px] bg-red-50 text-red-700 p-1.5 rounded border border-red-100 flex items-start gap-1">
                                    <i data-lucide="alert-circle" class="w-3 h-3 shrink-0 mt-0.5"></i>
                                    <span>{{ conflict.message }}</span>
                                </div>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Tipo de Clase</label>
                                <select v-model="selectedClass.type" @change="onTypeChange" :disabled="selectedClass.isExternal" class="w-full px-3 py-1.5 border rounded text-sm outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-slate-100 disabled:cursor-not-allowed">
                                    <option :value="1">Virtual</option>
                                    <option :value="0">Presencial</option>
                                    <option :value="2">Mixta</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Aula</label>
                                <input type="text" v-model="selectedClass.room" :disabled="selectedClass.isExternal" class="w-full px-3 py-1.5 border rounded text-sm outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-slate-100 disabled:cursor-not-allowed" placeholder="E.g. A-101" />
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Inicio</label>
                                <input type="time" v-model="selectedClass.start" :disabled="selectedClass.isExternal" class="w-full px-2 py-1.5 border rounded text-sm outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-slate-100 disabled:cursor-not-allowed" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Fin</label>
                                <input type="time" v-model="selectedClass.end" :disabled="selectedClass.isExternal" class="w-full px-2 py-1.5 border rounded text-sm outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-slate-100 disabled:cursor-not-allowed" />
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Bloque / Sub-Periodo</label>
                            <select v-model="selectedClass.subperiod" :disabled="selectedClass.isExternal" class="w-full px-3 py-1.5 border rounded text-sm outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-slate-100 disabled:cursor-not-allowed">
                                <option :value="0">Ambos / Todo el periodo</option>
                                <option :value="1">P-I (Bloque 1)</option>
                                <option :value="2">P-II (Bloque 2)</option>
                            </select>
                        </div>
                        <div class="pt-2 flex flex-col gap-2">
                              <button @click="viewStudents(selectedClass)" class="w-full py-2 bg-slate-100 text-slate-700 hover:bg-slate-200 rounded text-sm font-bold transition-colors flex items-center justify-center gap-2">
                                <i data-lucide="users" class="w-4 h-4"></i> Ver Lista de Alumnos ({{ selectedClass.studentCount || 0 }})
                              </button>
                             <button v-if="!selectedClass.isExternal" @click="unassignClass" class="w-full py-2 border border-red-200 text-red-600 hover:bg-red-50 rounded text-sm font-bold transition-colors">
                                Desasignar (Mover a Lista)
                             </button>
                        </div>
                    </div>
                    <div class="p-3 bg-slate-50 border-t border-slate-200 flex justify-end">
                        <button @click="editDialog = false" class="px-4 py-1.5 bg-blue-600 text-white rounded text-sm font-bold hover:bg-blue-700">Listo</button>
                    </div>
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

             <!-- View Audit Log Modal -->
             <div v-if="logDialog" class="fixed inset-0 z-[65] flex items-center justify-center p-4 bg-black/30 backdrop-blur-sm" @click.self="logDialog = false">
                <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl overflow-hidden animate-in zoom-in-95 duration-200">
                    <div class="p-4 border-b border-slate-200 bg-slate-50 flex justify-between items-center">
                         <div>
                            <h4 class="font-bold text-slate-800">Bitácora de Intentos</h4>
                            <p class="text-[10px] text-slate-500">{{ selectedClass?.subjectName }}</p>
                         </div>
                         <button @click="logDialog = false"><i data-lucide="x" class="w-4 h-4 text-slate-400"></i></button>
                    </div>
                    <div class="p-0 max-h-[70vh] overflow-y-auto">
                        <table class="w-full text-xs text-left">
                            <thead class="bg-slate-50 text-slate-500 uppercase font-bold sticky top-0">
                                <tr>
                                    <th class="px-4 py-2 border-b">Día</th>
                                    <th class="px-4 py-2 border-b">Hora</th>
                                    <th class="px-4 py-2 border-b">Estado</th>
                                    <th class="px-4 py-2 border-b">Detalle del Fallo</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="(entry, idx) in currentLog" :key="idx" class="hover:bg-slate-50">
                                    <td class="px-4 py-2 font-bold">{{ entry.day }}</td>
                                    <td class="px-4 py-2 font-mono text-slate-500">{{ entry.time }}</td>
                                    <td class="px-4 py-2">
                                        <span :class="{
                                            'text-orange-600 bg-orange-50': entry.status === 'Conflict',
                                            'text-red-600 bg-red-50': entry.status === 'RoomBusy',
                                            'text-slate-500 bg-slate-100': entry.status === 'Lunch'
                                        }" class="px-1.5 py-0.5 rounded font-bold uppercase text-[9px] whitespace-nowrap">
                                            {{ entry.status }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 text-slate-600">{{ entry.detail }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3 bg-slate-50 border-t border-slate-200 flex justify-end">
                        <button @click="logDialog = false" class="px-4 py-1.5 bg-slate-200 text-slate-700 rounded text-sm font-bold hover:bg-slate-300">Entendido</button>
                    </div>
                </div>
             </div>
        </div>
    `,
    data() {
        return {
            search: '',
            days: ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'],
            startHour: 7,
            endHour: 22,
            draggedClass: null,
            editDialog: false,
            selectedClass: null,
            saving: false,
            studentsDialog: false,
            currentStudents: [],
            logDialog: false,
            currentLog: [],
            teacherSearch: '',
            showTeacherList: false
        };
    },
    computed: {
        storeState() {
            return window.schedulerStore ? window.schedulerStore.state : {};
        },
        allClasses() {
            return this.storeState.generatedSchedules || [];
        },
        unassignedClasses() {
            const filter = this.storeState.subperiodFilter;
            const careerFilter = this.storeState.careerFilter;
            const shiftFilter = this.storeState.shiftFilter;

            return this.allClasses.filter(c => {
                const isUnassigned = c.day === 'N/A' || !c.day;
                if (!isUnassigned) return false;

                // External courses bypass filtering (they are read-only markers from other periods)
                if (c.isExternal) return true;

                // Career filter (Robust check)
                if (careerFilter) {
                    const inList = c.careerList && c.careerList.includes(careerFilter);
                    const inString = c.career && c.career.includes(careerFilter);
                    if (!inList && !inString) return false;
                }

                // Shift filter
                if (shiftFilter && c.shift !== shiftFilter) return false;

                if (filter === 0) return true;
                return c.subperiod === 0 || c.subperiod === filter;
            });
        },
        filteredUnassigned() {
            if (!this.search) return this.unassignedClasses;
            const s = this.search.toLowerCase();
            return this.unassignedClasses.filter(c =>
                c.subjectName.toLowerCase().includes(s) ||
                c.levelDisplay.toLowerCase().includes(s)
            );
        },
        timeSlots() {
            const slots = [];
            for (let h = this.startHour; h <= this.endHour; h++) {
                slots.push(`${h.toString().padStart(2, '0')}:00`);
            }
            return slots;
        },
        filteredInstructors() {
            const instructors = this.storeState.instructors || [];
            if (!this.teacherSearch) return instructors.slice(0, 10);
            const s = this.teacherSearch.toLowerCase();
            return instructors.filter(i =>
                (i.instructorName || '').toLowerCase().includes(s)
            ).slice(0, 50);
        }
    },
    updated() {
        if (window.lucide) window.lucide.createIcons();
    },
    methods: {
        normalizeDay(d) {
            if (!d) return '';
            return d.trim().normalize("NFD").replace(/[\u0300-\u036f]/g, "").toUpperCase();
        },
        getClassesForDay(day) {
            const filter = this.storeState.subperiodFilter;
            const careerFilter = this.storeState.careerFilter;
            const shiftFilter = this.storeState.shiftFilter;

            return this.allClasses.filter(c => {
                const cDay = this.normalizeDay(c.day);
                const tDay = this.normalizeDay(day);

                if (cDay !== tDay || !c.start || !c.end) return false;

                // External courses bypass filtering (they are read-only markers from other periods)
                if (c.isExternal) return true;

                // Career filter (Robust check)
                if (careerFilter) {
                    const inList = c.careerList && c.careerList.includes(careerFilter);
                    const inString = c.career && c.career.includes(careerFilter);
                    if (!inList && !inString) return false;
                }

                // Shift filter
                if (shiftFilter && c.shift !== shiftFilter) return false;

                if (filter === 0) return true;
                return c.subperiod === 0 || c.subperiod === filter;
            });
        },
        getEventStyle(cls) {
            const startMins = this.toMins(cls.start);
            const duration = (this.toMins(cls.end) - startMins);
            const dayStartMins = this.startHour * 60;
            const pixelsPerHour = 56;

            const top = ((startMins - dayStartMins) / 60) * pixelsPerHour;
            const height = (duration / 60) * pixelsPerHour;

            // Cascading/Overlap Logic
            const dayClasses = this.getClassesForDay(cls.day).sort((a, b) => this.toMins(a.start) - this.toMins(b.start));
            const overlaps = dayClasses.filter(c => {
                const s1 = this.toMins(c.start);
                const e1 = this.toMins(c.end);
                const s2 = this.toMins(cls.start);
                const e2 = this.toMins(cls.end);
                return Math.max(s1, s2) < Math.min(e1, e2);
            });

            const index = overlaps.findIndex(c => c.id === cls.id);
            const count = overlaps.length;

            const left = (index / count) * 95;
            const width = 100 / count;

            return {
                top: `${top}px`,
                height: `${height}px`,
                left: `${left + 2}%`,
                width: `${width - 4}%`,
                zIndex: 10 + index
            };
        },
        toMins(t) {
            if (!t || typeof t !== 'string') return 0;
            const parts = t.trim().split(':');
            if (parts.length < 2) return 0;
            const h = parseInt(parts[0], 10) || 0;
            const m = parseInt(parts[1], 10) || 0;
            return (h * 60) + m;
        },
        getConflicts(cls) {
            if (!window.SchedulerAlgorithm || !window.SchedulerAlgorithm.detectConflicts) return [];
            const context = {
                ...this.storeState.context,
                instructors: this.storeState.instructors,
                students: this.storeState.students
            };
            return window.SchedulerAlgorithm.detectConflicts(cls, this.allClasses, context);
        },
        getTeacherConflicts(cls) {
            if (!cls) return [];
            const issues = this.getConflicts(cls);
            return issues.filter(i => ['teacher', 'availability', 'competency'].includes(i.type));
        },
        getConflictTooltip(cls) {
            const issues = this.getConflicts(cls);
            if (issues.length === 0) return cls.subjectName;
            return issues.map(i => `[${i.type.toUpperCase()}] ${i.message}`).join('\n');
        },
        onDragStart(e, cls) {
            this.draggedClass = cls;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', cls.id);
        },
        onDrop(e, day) {
            if (!this.draggedClass) return;

            const rect = e.currentTarget.getBoundingClientRect();
            const y = e.clientY - rect.top;

            // 56px per hour (h-14)
            const pixelsPerHour = 56;

            const hourOffset = Math.floor(y / pixelsPerHour);
            // Remainder for minutes
            const remainder = y % pixelsPerHour;
            const minOffset = Math.floor(remainder / (pixelsPerHour / 2)) * 30; // Snap to 30m

            const newStartHour = this.startHour + hourOffset;
            const start = `${newStartHour.toString().padStart(2, '0')}:${minOffset.toString().padStart(2, '0')}`;

            const currentDuration = this.draggedClass.end ? (this.toMins(this.draggedClass.end) - this.toMins(this.draggedClass.start)) : 120;
            const endMins = this.toMins(start) + currentDuration;
            const endH = Math.floor(endMins / 60);
            const endM = endMins % 60;
            const end = `${endH.toString().padStart(2, '0')}:${endM.toString().padStart(2, '0')}`;

            this.draggedClass.day = day;
            this.draggedClass.start = start;
            this.draggedClass.end = end;

            // Update classdays bitmask
            const dayIdx = this.days.indexOf(day);
            if (dayIdx !== -1) {
                const mask = [0, 0, 0, 0, 0, 0, 0];
                mask[dayIdx] = 1;
                this.draggedClass.classdays = mask.join('/');
            }

            this.draggedClass = null;
        },
        editClass(cls) {
            this.selectedClass = cls;
            this.teacherSearch = cls.teacherName || '';
            this.editDialog = true;
            // Ensure icons are created for newly dynamic content
            this.$nextTick(() => { if (window.lucide) window.lucide.createIcons(); });
        },
        hideTeacherList() {
            // Delay to allow mousedown to trigger before the list is removed
            setTimeout(() => {
                this.showTeacherList = false;
            }, 200);
        },
        selectInstructor(inst) {
            if (!this.selectedClass) return;
            this.selectedClass.teacherName = inst.instructorName;
            this.selectedClass.instructorId = inst.instructorId || inst.id;
            this.teacherSearch = inst.instructorName;
            this.showTeacherList = false;
        },
        onTeacherChange() {
            if (!this.selectedClass) return;

            // If search is cleared, unassign
            if (!this.teacherSearch) {
                this.selectedClass.teacherName = null;
                this.selectedClass.instructorId = null;
                return;
            }

            const inst = this.storeState.instructors.find(i => i.instructorName === this.teacherSearch);
            if (inst) {
                this.selectedClass.teacherName = inst.instructorName;
                this.selectedClass.instructorId = inst.instructorId || inst.id;
            }
        },
        onTypeChange() {
            if (!this.selectedClass) return;
            const typeMap = { 0: 'Presencial', 1: 'Virtual', 2: 'Mixta' };
            this.selectedClass.typeLabel = typeMap[this.selectedClass.type];
        },
        unassignClass() {
            if (this.selectedClass) {
                this.selectedClass.day = 'N/A';
                this.selectedClass.start = '00:00';
                this.selectedClass.end = '00:00';
                this.editDialog = false;
            }
        },
        async saveChanges() {
            if (!window.schedulerStore) return;
            this.saving = true;
            try {
                const periodId = window.schedulerStore.state.activePeriod;
                await window.schedulerStore.saveGeneration(periodId, this.allClasses);
            } catch (e) {
                alert("Error saving: " + e.message);
            } finally {
                this.saving = false;
            }
        },
        viewStudents(cls) {
            if (!window.schedulerStore || !cls.studentIds) {
                this.currentStudents = [];
                this.studentsDialog = true;
                return;
            }

            const allStudents = window.schedulerStore.state.students || [];
            // Filter students whose id is in cls.studentIds
            this.currentStudents = allStudents.filter(s => cls.studentIds.includes(s.id || s.dbId));
            this.studentsDialog = true;
        },
        viewAuditLog(cls) {
            this.selectedClass = cls;
            this.currentLog = cls.auditLog || [];
            this.logDialog = true;
        }
    },
    watch: {
        allClasses: {
            immediate: true,
            handler(newVal) {
                if (!newVal) return;
                const externals = newVal.filter(c => c.isExternal);
                console.log(`DEBUG PlanningBoard: allClasses updated. Length: ${newVal.length}, Externals found: ${externals.length}`);
                if (externals.length > 0) {
                    const first = externals[0];
                    console.log(`DEBUG PlanningBoard: First external - ID: ${first.id}, Subject: ${first.subjectName}, Period: ${first.periodid}, Day: ${first.day}`);
                }
            }
        }
    }
};
