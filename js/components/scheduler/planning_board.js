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
                        <h3 class="text-sm font-bold text-slate-700">Por Asignar ({{ unassignedClasses.length }})</h3>
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
                            <div class="flex items-center gap-2 text-xs text-slate-500">
                                <span class="bg-blue-50 text-blue-700 px-1.5 py-0.5 rounded font-medium">{{ cls.levelDisplay }}</span>
                                <span>{{ cls.shift }}</span>
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
                                        class="absolute left-1 right-1 rounded border overflow-hidden p-1 shadow-sm cursor-pointer hover:shadow-md transition-all z-10 group"
                                        :class="hasConflict(cls) ? 'bg-red-50 border-red-300' : 'bg-blue-50 border-blue-200 hover:border-blue-400'"
                                        :style="getEventStyle(cls)"
                                        draggable="true"
                                        @dragstart="onDragStart($event, cls)"
                                        @click="editClass(cls)"
                                    >
                                        <div class="text-[10px] font-bold leading-tight line-clamp-2" :class="hasConflict(cls) ? 'text-red-800' : 'text-blue-800'">
                                            {{ cls.subjectName }}
                                        </div>
                                        <div class="text-[9px] leading-tight text-slate-500 mt-0.5">
                                            <span v-if="cls.teacherName" class="block font-medium text-slate-700 truncate">{{ cls.teacherName }}</span>
                                            <span v-else class="block text-orange-500 italic">Por asignar</span>
                                            <span class="block truncate">{{ cls.room || 'Sin aula' }}</span>
                                        </div>
                                        
                                        <!-- Conflict Indicator -->
                                        <div v-if="hasConflict(cls)" class="absolute top-0 right-0 p-0.5 bg-red-500 text-white rounded-bl">
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
                         <h4 class="font-bold text-slate-800">Editar Clase</h4>
                         <button @click="editDialog = false"><i data-lucide="x" class="w-4 h-4 text-slate-400"></i></button>
                    </div>
                    <div class="p-4 space-y-3">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Docente</label>
                            <input type="text" v-model="selectedClass.teacherName" class="w-full px-3 py-1.5 border rounded text-sm outline-none focus:ring-2 focus:ring-blue-500" placeholder="Nombre del docente" />
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Aula</label>
                            <input type="text" v-model="selectedClass.room" class="w-full px-3 py-1.5 border rounded text-sm outline-none focus:ring-2 focus:ring-blue-500" placeholder="E.g. A-101" />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Inicio</label>
                                <input type="time" v-model="selectedClass.start" class="w-full px-2 py-1.5 border rounded text-sm outline-none focus:ring-2 focus:ring-blue-500" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Fin</label>
                                <input type="time" v-model="selectedClass.end" class="w-full px-2 py-1.5 border rounded text-sm outline-none focus:ring-2 focus:ring-blue-500" />
                            </div>
                        </div>
                        <div class="pt-2">
                             <button @click="unassignClass" class="w-full py-2 border border-red-200 text-red-600 hover:bg-red-50 rounded text-sm font-bold transition-colors">
                                Desasignar (Mover a Lista)
                             </button>
                        </div>
                    </div>
                    <div class="p-3 bg-slate-50 border-t border-slate-200 flex justify-end">
                        <button @click="editDialog = false" class="px-4 py-1.5 bg-blue-600 text-white rounded text-sm font-bold hover:bg-blue-700">Listo</button>
                    </div>
                </div>
             </div>
        </div>
    `,
    data() {
        return {
            search: '',
            days: ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado'],
            startHour: 7,
            endHour: 22,
            draggedClass: null,
            editDialog: false,
            selectedClass: null,
            saving: false
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
            return this.allClasses.filter(c => c.day === 'N/A' || !c.day);
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
        }
    },
    updated() {
        if (window.lucide) window.lucide.createIcons();
    },
    methods: {
        getClassesForDay(day) {
            return this.allClasses.filter(c => c.day === day && c.start && c.end);
        },
        getEventStyle(cls) {
            const startMins = this.toMins(cls.start);
            // const endMins = this.toMins(cls.end);
            const duration = (this.toMins(cls.end) - startMins);
            const dayStartMins = this.startHour * 60;

            // 56px (h-14) per hour
            const pixelsPerHour = 56;

            const top = ((startMins - dayStartMins) / 60) * pixelsPerHour;
            const height = (duration / 60) * pixelsPerHour;

            return {
                top: `${top}px`,
                height: `${height}px`
            };
        },
        toMins(t) {
            if (!t) return 0;
            const [h, m] = t.split(':').map(Number);
            return h * 60 + m;
        },
        hasConflict(cls) {
            if (!cls.teacherName) return false;
            const others = this.getClassesForDay(cls.day).filter(c => c.id !== cls.id && c.teacherName === cls.teacherName);
            const s1 = this.toMins(cls.start);
            const e1 = this.toMins(cls.end);

            return others.some(o => {
                const s2 = this.toMins(o.start);
                const e2 = this.toMins(o.end);
                return Math.max(s1, s2) < Math.min(e1, e2);
            });
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

            this.draggedClass = null;
        },
        editClass(cls) {
            this.selectedClass = cls;
            this.editDialog = true;
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
        }
    }
};
