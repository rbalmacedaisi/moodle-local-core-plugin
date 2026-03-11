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
                        <div class="flex items-center gap-2">
                            <div class="relative w-32">
                                 <input type="text" v-model="search" placeholder="Busca..." class="w-full pl-7 pr-2 py-1 text-xs border rounded-lg focus:ring-1 focus:ring-blue-500 outline-none" />
                                 <i data-lucide="search" class="absolute left-2 top-1.5 w-3 h-3 text-slate-400"></i>
                            </div>
                            <button @click="openAddSubject" class="flex items-center gap-1 px-2 py-1 text-xs font-bold bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors" title="Añadir asignatura sin demanda real">
                                <i data-lucide="plus" class="w-3 h-3"></i> Añadir
                            </button>
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
                                <span v-if="loadsMap[cls.subjectName]"
                                    class="flex items-center gap-0.5 bg-violet-50 text-violet-700 px-1.5 py-0.5 rounded font-semibold"
                                    :title="'Carga horaria: ' + (loadsMap[cls.subjectName].total_hours || loadsMap[cls.subjectName].totalHours) + 'h totales · intensidad: ' + (loadsMap[cls.subjectName].intensity) + 'h/ses'">
                                    <i data-lucide="clock" class="w-2.5 h-2.5"></i>
                                    {{ loadsMap[cls.subjectName].total_hours || loadsMap[cls.subjectName].totalHours }}h
                                </span>
                                <span v-else class="flex items-center gap-0.5 text-slate-300 italic" title="Sin carga horaria definida">
                                    <i data-lucide="clock" class="w-2.5 h-2.5"></i> def.
                                </span>
                                <div class="ml-auto flex gap-1">
                                    <button @click.stop="openAddQuorum(cls)" class="text-slate-400 hover:text-green-600 transition-colors" title="Añadir quórum proyectado">
                                        <i data-lucide="user-plus" class="w-3 h-3"></i>
                                    </button>
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
                         <div class="flex gap-2">
                             <button @click="saveChanges" :disabled="saving || publishing" class="flex items-center gap-2 px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg text-xs font-bold shadow-sm transition-colors disabled:opacity-50">
                                <i data-lucide="save" class="w-3 h-3"></i>
                                {{ saving ? 'Guardando...' : 'Guardar Borrador' }}
                             </button>
                             <button @click="publishSchedules" :disabled="saving || publishing" class="flex items-center gap-2 px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs font-bold shadow-sm transition-colors disabled:opacity-50" title="Crear clases en Moodle y hacerlas visibles en Gestión de Clases">
                                <i data-lucide="send" class="w-3 h-3"></i>
                                {{ publishing ? 'Publicando...' : 'Publicar Horarios' }}
                             </button>
                         </div>
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
                                    @dragover.prevent="onDragOver($event, day)"
                                    @dragleave="onDragLeave(day)"
                                    @drop="onDrop($event, day)"
                                >
                                    <!-- Grid Lines (half-hour marks) -->
                                    <template v-for="t in timeSlots" :key="t">
                                        <div class="h-7 border-b border-slate-100 border-dashed"></div>
                                        <div class="h-7 border-b border-slate-200"></div>
                                    </template>

                                    <!-- Ghost Preview (DOM-direct, not Vue-reactive) -->
                                    <div :ref="el => registerGhostRef(el, day)"
                                        style="display:none;position:absolute;left:4px;right:4px;border-radius:6px;border:2px dashed #60a5fa;background:rgba(191,219,254,0.6);z-index:100;pointer-events:none;align-items:center;justify-content:center;"
                                    >
                                        <span style="font-size:11px;font-weight:700;color:#1d4ed8;background:rgba(255,255,255,0.8);padding:1px 8px;border-radius:4px;box-shadow:0 1px 2px rgba(0,0,0,.15)"></span>
                                    </div>

                                    <!-- Placed Events -->
                                    <div v-for="cls in getClassesForDay(day)" :key="cls.id"
                                        class="absolute left-1 right-1 rounded border-2 overflow-visible p-1 shadow-md cursor-grab hover:shadow-lg transition-all z-20 group select-none"
                                        :class="[
                                            cls.isExternal && getConflicts(cls).length > 0 ? 'bg-red-50 border-red-400 ring-2 ring-red-200' :
                                            cls.isExternal ? 'bg-amber-100 border-amber-500 ring-2 ring-amber-200' :
                                            getConflicts(cls).length > 0 ? 'bg-red-50 border-red-300' :
                                            getLoadCoverage(cls).under ? 'bg-orange-50 border-orange-300 hover:border-orange-400' :
                                            'bg-blue-50 border-blue-200 hover:border-blue-400'
                                        ]"
                                        :style="getEventStyle(cls)"
                                        :title="getConflictTooltip(cls)"
                                        :draggable="!cls.isExternal && !resizingClass"
                                        @dragstart="onDragStart($event, cls)"
                                        @click="editClass(cls)"
                                    >
                                        <div class="text-[10px] font-bold leading-tight line-clamp-2" :class="getConflicts(cls).length > 0 ? 'text-red-800' : (cls.isExternal ? 'text-amber-800' : 'text-blue-800')">
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
                                            <!-- Course load indicator -->
                                            <div class="mt-0.5">
                                                <div v-if="loadsMap[cls.subjectName]" class="flex items-center gap-1"
                                                    :class="getLoadCoverage(cls).under ? 'text-orange-600' : 'text-violet-700'">
                                                    <i data-lucide="clock" class="w-2.5 h-2.5 shrink-0"></i>
                                                    <span class="font-semibold"
                                                        :title="getLoadCoverage(cls).under
                                                            ? 'Carga incompleta: ' + getLoadCoverage(cls).actual + '/' + getLoadCoverage(cls).required + ' sesiones (' + (loadsMap[cls.subjectName].total_hours || loadsMap[cls.subjectName].totalHours) + 'h requeridas)'
                                                            : 'Carga cubierta: ' + (loadsMap[cls.subjectName].total_hours || loadsMap[cls.subjectName].totalHours) + 'h'">
                                                        {{ loadsMap[cls.subjectName].total_hours || loadsMap[cls.subjectName].totalHours }}h{{ getLoadCoverage(cls).required ? ' · ' + getLoadCoverage(cls).actual + '/' + getLoadCoverage(cls).required + ' ses.' : '' }}
                                                    </span>
                                                    <i v-if="getLoadCoverage(cls).under" data-lucide="alert-circle" class="w-2.5 h-2.5 shrink-0 text-orange-500"></i>
                                                </div>
                                                <div v-else-if="!cls.isExternal" class="flex items-center gap-1 text-slate-400" title="Sin carga horaria definida, usa configuración por defecto">
                                                    <i data-lucide="clock" class="w-2.5 h-2.5 shrink-0"></i>
                                                    <span class="italic">defecto</span>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Actions -->
                                        <div class="absolute bottom-5 right-1 flex gap-1">
                                            <button @click.stop="openAddQuorum(cls)" class="p-0.5 bg-white rounded shadow hover:bg-green-50 transition-colors" title="Añadir quórum proyectado">
                                                <i data-lucide="user-plus" class="w-3 h-3 text-slate-400 hover:text-green-600"></i>
                                            </button>
                                            <button @click.stop="viewStudents(cls)" class="p-0.5 bg-white rounded shadow text-slate-500 hover:text-blue-600" title="Ver Estudiantes">
                                                <i data-lucide="users" class="w-3 h-3"></i>
                                            </button>
                                        </div>

                                        <!-- Conflict Indicator -->
                                        <div v-if="getConflicts(cls).length > 0" class="absolute top-0 right-0 p-0.5 bg-red-500 text-white rounded-bl">
                                            <i data-lucide="alert-triangle" class="w-3 h-3"></i>
                                        </div>
                                        <!-- Load coverage indicator (only when no conflict badge) -->
                                        <div v-else-if="getLoadCoverage(cls).under"
                                            class="absolute top-0 right-0 p-0.5 bg-orange-400 text-white rounded-bl"
                                            :title="'Carga incompleta: ' + getLoadCoverage(cls).actual + '/' + getLoadCoverage(cls).required + ' sesiones'">
                                            <i data-lucide="alert-circle" class="w-3 h-3"></i>
                                        </div>

                                        <!-- Resize Handle (bottom edge) -->
                                        <div v-if="!cls.isExternal"
                                            class="absolute bottom-0 left-0 right-0 h-3 cursor-s-resize flex items-center justify-center group/resize"
                                            @mousedown.stop="onResizeStart($event, cls)"
                                            title="Arrastrar para cambiar duración"
                                        >
                                            <div class="w-8 h-1 rounded-full bg-blue-300 group-hover/resize:bg-blue-500 transition-colors"></div>
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
                            <div class="relative">
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Aula</label>
                                <input type="text"
                                    v-model="roomSearch"
                                    @input="onRoomChange"
                                    @focus="showRoomList = true"
                                    @blur="hideRoomList"
                                    :disabled="selectedClass.isExternal"
                                    class="w-full px-3 py-1.5 border rounded text-sm outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-slate-100 disabled:cursor-not-allowed"
                                    placeholder="Buscar aula..." />
                                <div v-if="showRoomList && filteredRooms.length > 0"
                                    class="absolute z-[100] w-full mt-1 bg-white border border-slate-200 rounded-lg shadow-xl max-h-48 overflow-y-auto overflow-x-hidden">
                                    <div v-for="room in filteredRooms" :key="room.name"
                                        @mousedown="selectRoom(room)"
                                        class="px-3 py-2 hover:bg-blue-50 cursor-pointer text-sm border-b border-slate-100 last:border-0 flex justify-between items-center group">
                                        <span class="font-medium text-slate-700">{{ room.name }}</span>
                                        <span class="text-[9px] text-slate-400 group-hover:text-blue-500 font-mono">Cap. {{ room.capacity }}</span>
                                    </div>
                                </div>
                                <!-- Conflictos de aula -->
                                <div v-if="getRoomConflicts(selectedClass).length > 0" class="mt-1 space-y-1">
                                    <div v-for="conflict in getRoomConflicts(selectedClass)" :key="conflict.type" class="text-[10px] bg-red-50 text-red-700 p-1.5 rounded border border-red-100 flex items-start gap-1">
                                        <i data-lucide="alert-circle" class="w-3 h-3 shrink-0 mt-0.5"></i>
                                        <span>{{ conflict.message }}</span>
                                    </div>
                                </div>
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
                        <!-- Session info + Optimize button -->
                        <div v-if="!selectedClass.isExternal && selectedClass.day && selectedClass.day !== 'N/A'" class="bg-slate-50 border border-slate-200 rounded-lg p-2.5 space-y-1.5">
                            <div class="flex items-center justify-between">
                                <span class="text-[10px] font-bold text-slate-500 uppercase tracking-wide">Sesiones programadas</span>
                                <button @click="openOptimize(selectedClass)" class="flex items-center gap-1 px-2 py-1 bg-violet-600 hover:bg-violet-700 text-white rounded text-[10px] font-bold transition-colors">
                                    <i data-lucide="sparkles" class="w-3 h-3"></i> Optimizar
                                </button>
                            </div>
                            <div class="grid grid-cols-2 gap-x-3 gap-y-1 text-[10px] text-slate-600">
                                <div class="flex items-center gap-1">
                                    <i data-lucide="calendar" class="w-3 h-3 text-slate-400 shrink-0"></i>
                                    <span class="font-medium">{{ effectiveSessionCount }} sesiones</span>
                                </div>
                                <div v-if="loadsMap[selectedClass.subjectName]" class="flex items-center gap-1">
                                    <i data-lucide="clock" class="w-3 h-3 text-violet-400 shrink-0"></i>
                                    <span class="text-violet-700 font-medium">{{ loadsMap[selectedClass.subjectName].total_hours || loadsMap[selectedClass.subjectName].totalHours }}h carga</span>
                                </div>
                                <div v-if="selectedClass.assignedDates && selectedClass.assignedDates.length > 0" class="flex items-center gap-1 col-span-2">
                                    <i data-lucide="arrow-right" class="w-3 h-3 text-slate-400 shrink-0"></i>
                                    <span>{{ formatDate(selectedClass.assignedDates[0]) }} → {{ formatDate(selectedClass.assignedDates[selectedClass.assignedDates.length - 1]) }}</span>
                                </div>
                            </div>
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

             <!-- Optimize Modal -->
             <div v-if="optimizeDialog && selectedClass" class="fixed inset-0 z-[70] flex items-center justify-center p-4 bg-black/30 backdrop-blur-sm" @click.self="optimizeDialog = false">
                <div class="bg-white rounded-xl shadow-2xl w-full max-w-md overflow-hidden animate-in zoom-in-95 duration-200">
                    <div class="p-4 border-b border-slate-200 bg-violet-50 flex justify-between items-center">
                        <div>
                            <h4 class="font-bold text-violet-900 flex items-center gap-2">
                                <i data-lucide="sparkles" class="w-4 h-4"></i> Optimizar Sesiones
                            </h4>
                            <p class="text-[10px] text-violet-600 mt-0.5">{{ selectedClass.subjectName }}</p>
                        </div>
                        <button @click="optimizeDialog = false"><i data-lucide="x" class="w-4 h-4 text-slate-400"></i></button>
                    </div>
                    <div class="p-4 space-y-3">
                        <!-- Current state summary -->
                        <div class="bg-slate-50 rounded-lg p-3 text-xs space-y-1 text-slate-600">
                            <div class="flex justify-between">
                                <span class="font-medium text-slate-700">Día programado:</span>
                                <span>{{ selectedClass.day }} {{ selectedClass.start }} – {{ selectedClass.end }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="font-medium text-slate-700">Duración sesión:</span>
                                <span>{{ selectedClassDuration }} min</span>
                            </div>
                            <div class="flex justify-between" v-if="loadsMap[selectedClass.subjectName]">
                                <span class="font-medium text-slate-700">Carga horaria:</span>
                                <span class="text-violet-700 font-bold">{{ loadsMap[selectedClass.subjectName].total_hours || loadsMap[selectedClass.subjectName].totalHours }}h totales</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="font-medium text-slate-700">Sesiones actuales:</span>
                                <span :class="effectiveSessionCount === optimalSessions ? 'text-green-600 font-bold' : 'text-orange-600 font-bold'">
                                    {{ effectiveSessionCount }}{{ optimalSessions ? ' (óptimo: ' + optimalSessions + ')' : '' }}
                                </span>
                            </div>
                            <div v-if="selectedClass.assignedDates && selectedClass.assignedDates.length > 0" class="flex justify-between">
                                <span class="font-medium text-slate-700">Rango actual:</span>
                                <span>{{ formatDate(selectedClass.assignedDates[0]) }} → {{ formatDate(selectedClass.assignedDates[selectedClass.assignedDates.length - 1]) }}</span>
                            </div>
                        </div>

                        <!-- Suggestions list -->
                        <div v-if="optimizeSuggestions.length > 0">
                            <p class="text-[10px] font-bold text-slate-500 uppercase mb-2">Sugerencias</p>
                            <div class="space-y-2">
                                <div v-for="(sug, idx) in optimizeSuggestions" :key="idx"
                                    class="border rounded-lg p-3 cursor-pointer transition-all hover:shadow-md"
                                    :class="sug.type === 'adjust' ? 'border-violet-200 bg-violet-50 hover:border-violet-400' : 'border-blue-200 bg-blue-50 hover:border-blue-400'"
                                    @click="applySuggestion(sug)">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="flex-1">
                                            <p class="text-xs font-bold" :class="sug.type === 'adjust' ? 'text-violet-800' : 'text-blue-800'">{{ sug.label }}</p>
                                            <p class="text-[10px] mt-0.5" :class="sug.type === 'adjust' ? 'text-violet-600' : 'text-blue-600'">{{ sug.description }}</p>
                                            <p v-if="sug.dateRange" class="text-[10px] mt-1 font-medium text-slate-500">
                                                {{ sug.dateRange }}
                                            </p>
                                        </div>
                                        <span class="shrink-0 px-2 py-1 rounded text-[10px] font-bold uppercase"
                                            :class="sug.type === 'adjust' ? 'bg-violet-200 text-violet-800' : 'bg-blue-200 text-blue-800'">
                                            Aplicar
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div v-else class="text-center text-slate-400 text-xs py-4 italic">
                            No hay sugerencias disponibles — la configuración ya es óptima.
                        </div>
                    </div>
                    <div class="p-3 bg-slate-50 border-t border-slate-200 flex justify-end">
                        <button @click="optimizeDialog = false" class="px-4 py-1.5 bg-slate-200 text-slate-700 rounded text-sm font-bold hover:bg-slate-300">Cerrar</button>
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

            <!-- Modal: Añadir Quórum Proyectado -->
            <div v-if="quorumDialog"
                 class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 backdrop-blur-sm"
                 @click.self="quorumDialog = false">
                <div class="bg-white rounded-xl shadow-2xl p-6 w-80">
                    <h3 class="font-bold text-slate-800 mb-1">Añadir Quórum Proyectado</h3>
                    <p class="text-xs text-slate-500 mb-4">
                        Estudiantes adicionales para <strong>{{ quorumTarget?.subjectName }}</strong>.
                        Actual: <span class="font-mono">{{ quorumTarget?.studentCount || 0 }}</span>
                    </p>
                    <input type="number" v-model.number="quorumAmount" min="1"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-center text-lg font-mono focus:ring-2 focus:ring-green-500 outline-none mb-4"
                           placeholder="0" />
                    <div class="flex justify-end gap-3">
                        <button @click="quorumDialog = false"
                                class="px-4 py-2 text-sm text-slate-500 hover:bg-slate-100 rounded-lg">
                            Cancelar
                        </button>
                        <button @click="confirmAddQuorum"
                                :disabled="!quorumAmount || quorumAmount < 1"
                                class="px-4 py-2 text-sm font-bold bg-green-600 hover:bg-green-700 disabled:bg-slate-300 text-white rounded-lg">
                            Añadir
                        </button>
                    </div>
                </div>
            </div>

            <!-- Modal: Añadir Asignatura al Tablero -->
            <div v-if="addSubjectDialog"
                 class="fixed inset-0 z-50 flex items-center justify-center bg-black/30 backdrop-blur-sm"
                 @click.self="addSubjectDialog = false">
                <div class="bg-white rounded-xl shadow-2xl p-6 w-[480px]">
                    <h3 class="font-bold text-slate-800 mb-1">Añadir Asignatura al Tablero</h3>
                    <p class="text-xs text-slate-500 mb-4">
                        Busca una asignatura del catálogo para agregar al tablero con quórum proyectado.
                    </p>
                    <input type="text" v-model="subjectSearch"
                           class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-green-500 outline-none mb-2"
                           placeholder="Buscar asignatura..." />
                    <div class="max-h-48 overflow-y-auto border border-slate-200 rounded-lg mb-4">
                        <div v-if="filteredSubjectList.length === 0"
                             class="p-3 text-center text-slate-400 text-sm italic">
                            No se encontraron asignaturas.
                        </div>
                        <div v-for="subj in filteredSubjectList" :key="subj.id"
                             @click="selectSubjectToAdd(subj)"
                             :class="['p-3 cursor-pointer border-b border-slate-100 hover:bg-green-50 transition-colors text-sm',
                                      selectedSubjectToAdd?.id === subj.id ? 'bg-green-100 font-bold' : '']">
                            <div class="font-medium text-slate-800">{{ subj.name }}</div>
                            <div class="text-xs text-slate-500">
                                {{ (subj.careers || []).map(c => c.name).join(', ') }}
                                — {{ subj.semester_name }}
                            </div>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Quórum proyectado</label>
                        <input type="number" v-model.number="addSubjectCount" min="1"
                               class="w-full border border-slate-300 rounded-lg px-3 py-2 text-center text-lg font-mono focus:ring-2 focus:ring-green-500 outline-none"
                               placeholder="0" />
                    </div>
                    <div class="flex justify-end gap-3">
                        <button @click="addSubjectDialog = false"
                                class="px-4 py-2 text-sm text-slate-500 hover:bg-slate-100 rounded-lg">
                            Cancelar
                        </button>
                        <button @click="confirmAddSubject"
                                :disabled="!selectedSubjectToAdd || !addSubjectCount || addSubjectCount < 1"
                                class="px-4 py-2 text-sm font-bold bg-green-600 hover:bg-green-700 disabled:bg-slate-300 text-white rounded-lg">
                            Añadir al Tablero
                        </button>
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
            // Resize state
            resizingClass: null,
            resizeStartY: 0,
            resizeStartEnd: '',
            editDialog: false,
            selectedClass: null,
            saving: false,
            publishing: false,
            studentsDialog: false,
            currentStudents: [],
            logDialog: false,
            currentLog: [],
            teacherSearch: '',
            showTeacherList: false,
            roomSearch: '',
            showRoomList: false,
            optimizeDialog: false,
            optimizeSuggestions: [],
            // Modal +Quórum (ficha existente)
            quorumDialog: false,
            quorumTarget: null,
            quorumAmount: 1,
            // Modal Añadir Asignatura
            addSubjectDialog: false,
            subjectSearch: '',
            selectedSubjectToAdd: null,
            addSubjectCount: 1,
        };
    },
    computed: {
        storeState() {
            return window.schedulerStore ? window.schedulerStore.state : {};
        },
        allClasses() {
            return this.storeState.generatedSchedules || [];
        },
        filteredSubjectList() {
            const allSubjects = Object.values(this.storeState.subjects || {});
            const q = (this.subjectSearch || '').toLowerCase().trim();
            if (!q) return allSubjects.slice(0, 50);
            return allSubjects.filter(s => s.name && s.name.toLowerCase().includes(q)).slice(0, 50);
        },
        unassignedClasses() {
            const filter = this.storeState.subperiodFilter;
            const careerFilter = this.storeState.careerFilter;
            const shiftFilter = this.storeState.shiftFilter;
            const entryPeriodFilter = this.storeState.entryPeriodFilter;

            // Precompute Set of student IDs belonging to the selected entry period
            let entryPeriodSidSet = null;
            if (entryPeriodFilter) {
                const allStudents = this.storeState.students || [];
                entryPeriodSidSet = new Set(
                    allStudents
                        .filter(s => (s.entry_period || 'Sin Definir') === entryPeriodFilter)
                        .flatMap(s => [String(s.dbId), String(s.id)])
                );
            }

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

                // Entry period filter: at least one student in the class belongs to selected period
                if (entryPeriodSidSet) {
                    const ids = c.studentIds || [];
                    if (!ids.some(id => entryPeriodSidSet.has(String(id)))) return false;
                }

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
        loadsMap() {
            const loads = this.storeState.context?.loads || [];
            const map = {};
            // Helper: strip nomenclature wrapper "PERIOD (S) SUBJECT (TYPE) ROOM" → "SUBJECT"
            const stripNomenclature = (name) => name
                .replace(/^\S+[-–]\S+\s+\([A-Z]\)\s+/i, '') // remove "2026-II (S) "
                .replace(/\s+\((PRESENCIAL|VIRTUAL|MIXTA)\).*$/i, '') // remove " (PRESENCIAL) AULA X"
                .trim();
            loads.forEach(l => {
                const raw = (l.subjectname || l.subjectName || '').trim();
                if (!raw) return;
                map[raw] = l;
                map[raw.toUpperCase()] = l;
            });
            // Also index by the nomenclature-stripped version of each schedule's subjectName
            // so that "2026-II (S) INGLÉS I (PRESENCIAL) AULA L" resolves to the "INGLÉS I" load entry.
            // We do this by iterating generatedSchedules and mapping their full name → load entry.
            const schedules = this.storeState.generatedSchedules || [];
            schedules.forEach(cls => {
                if (!cls.subjectName || map[cls.subjectName]) return; // already indexed
                const stripped = stripNomenclature(cls.subjectName.toUpperCase());
                if (map[stripped]) map[cls.subjectName] = map[stripped];
            });
            return map;
        },
        selectedClassDuration() {
            if (!this.selectedClass?.start || !this.selectedClass?.end) return 0;
            return this.toMins(this.selectedClass.end) - this.toMins(this.selectedClass.start);
        },
        optimalSessions() {
            if (!this.selectedClass) return null;
            const load = this.loadsMap[this.selectedClass.subjectName];
            if (!load) return null;
            const totalH = parseFloat(load.total_hours || load.totalHours || 0);
            const durH = this.selectedClassDuration / 60;
            if (totalH > 0 && durH > 0) return Math.ceil(totalH / durH);
            return null;
        },
        effectiveSessionCount() {
            if (!this.selectedClass) return 0;
            const ad = this.selectedClass.assignedDates;
            if (Array.isArray(ad) && ad.length > 0) return ad.length;
            // assignedDates missing/empty → compute from period dates
            if (!this.selectedClass.day || this.selectedClass.day === 'N/A' || !window.SchedulerAlgorithm?.getDatesForDay) return 0;
            const normalizedDay = this.selectedClass.day.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            return window.SchedulerAlgorithm.getDatesForDay(normalizedDay, this.storeState.context, this.selectedClass.subperiod || 0).length;
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
        },
        filteredRooms() {
            const rooms = this.storeState.context?.classrooms || [];
            if (!this.roomSearch) return rooms.slice(0, 15);
            const s = this.roomSearch.toLowerCase();
            return rooms.filter(r =>
                (r.name || '').toLowerCase().includes(s)
            ).slice(0, 50);
        },

        // --- Cached computed maps (recalculated only when allClasses changes) ---

        // Normalized day name per class id: { id -> 'LUNES' }
        normalizedDayMap() {
            const map = {};
            for (const c of this.allClasses) {
                map[c.id] = c.day ? c.day.trim().normalize("NFD").replace(/[\u0300-\u036f]/g, "").toUpperCase() : '';
            }
            return map;
        },

        // toMins cache per class id: { id -> { start, end } }
        classMinsMap() {
            const map = {};
            for (const c of this.allClasses) {
                map[c.id] = { start: this._toMins(c.start), end: this._toMins(c.end) };
            }
            return map;
        },

        // Classes grouped by normalized day, pre-filtered and pre-sorted
        // { 'LUNES' -> [...], 'MARTES' -> [...], ... }
        classesByDay() {
            const filter = this.storeState.subperiodFilter;
            const careerFilter = this.storeState.careerFilter;
            const shiftFilter = this.storeState.shiftFilter;
            const entryPeriodFilter = this.storeState.entryPeriodFilter;
            const ndMap = this.normalizedDayMap;
            const mMap = this.classMinsMap;
            const result = {};

            // Precompute Set of student IDs belonging to the selected entry period
            let entryPeriodSidSet = null;
            if (entryPeriodFilter) {
                const allStudents = this.storeState.students || [];
                entryPeriodSidSet = new Set(
                    allStudents
                        .filter(s => (s.entry_period || 'Sin Definir') === entryPeriodFilter)
                        .flatMap(s => [String(s.dbId), String(s.id)])
                );
            }

            for (const c of this.allClasses) {
                const nd = ndMap[c.id];
                if (!nd || nd === 'N/A' || !c.start || !c.end) continue;

                if (!c.isExternal) {
                    if (careerFilter) {
                        const inList = c.careerList && c.careerList.includes(careerFilter);
                        const inString = c.career && c.career.includes(careerFilter);
                        if (!inList && !inString) continue;
                    }
                    if (shiftFilter && c.shift !== shiftFilter) continue;
                    if (filter !== 0 && c.subperiod !== 0 && c.subperiod !== filter) continue;

                    // Entry period filter
                    if (entryPeriodSidSet) {
                        const ids = c.studentIds || [];
                        if (!ids.some(id => entryPeriodSidSet.has(String(id)))) continue;
                    }
                }

                if (!result[nd]) result[nd] = [];
                result[nd].push(c);
            }

            // Pre-sort each day by start time
            for (const nd of Object.keys(result)) {
                result[nd].sort((a, b) => mMap[a.id].start - mMap[b.id].start);
            }
            return result;
        },

        // Overlap layout cache: { id -> { top, height, left, width, zIndex } }
        // Column-packing sweep (Google Calendar style) — cards never visually overlap:
        // 1. Assign each card the first free column (greedy, sorted by start time).
        // 2. Expand each card rightward to fill empty adjacent columns within its time range.
        eventStyleMap() {
            const pixelsPerHour = 56;
            const dayStartMins = this.startHour * 60;
            const mMap = this.classMinsMap;
            const styleMap = {};

            for (const [, dayClasses] of Object.entries(this.classesByDay)) {
                if (dayClasses.length === 0) continue;

                // --- Pass 1: assign column index to each card ---
                // colEnds[col] = end time of the last card placed in that column
                const colEnds = [];
                const cardCol = {};

                for (const c of dayClasses) {
                    const sm = mMap[c.id].start;
                    const em = mMap[c.id].end;
                    let placed = -1;
                    for (let col = 0; col < colEnds.length; col++) {
                        if (colEnds[col] <= sm) { placed = col; colEnds[col] = em; break; }
                    }
                    if (placed === -1) { placed = colEnds.length; colEnds.push(em); }
                    cardCol[c.id] = placed;
                }

                const totalCols = colEnds.length;

                // --- Pass 2: compute how many columns each card can span ---
                // A card at col C can expand right up to (but not including) the nearest
                // column that has a card whose time range overlaps with this card.
                const ivs = dayClasses.map(c => ({
                    id: c.id, s: mMap[c.id].start, e: mMap[c.id].end, col: cardCol[c.id]
                }));

                for (const iv of ivs) {
                    let spanEnd = totalCols;
                    for (const other of ivs) {
                        if (other.col <= iv.col) continue;
                        if (Math.max(iv.s, other.s) < Math.min(iv.e, other.e)) {
                            spanEnd = Math.min(spanEnd, other.col);
                        }
                    }
                    const colW = 96 / totalCols;
                    styleMap[iv.id] = {
                        top:    `${((iv.s - dayStartMins) / 60) * pixelsPerHour}px`,
                        height: `${((iv.e - iv.s)        / 60) * pixelsPerHour}px`,
                        left:   `${iv.col * colW + 1}%`,
                        width:  `${(spanEnd - iv.col) * colW - 2}%`,
                        zIndex: 10 + iv.col
                    };
                }
            }
            return styleMap;
        },

        // Conflict cache: { id -> conflict[] }
        // Only computed when not dragging to avoid thrashing at 60fps
        conflictMap() {
            if (this.draggedClass !== null) return this._lastConflictMap || {};
            if (!window.SchedulerAlgorithm?.detectConflicts) return {};
            const context = {
                ...this.storeState.context,
                instructors: this.storeState.instructors,
                students: this.storeState.students
            };
            const map = {};
            for (const c of this.allClasses) {
                if (c.day && c.day !== 'N/A') {
                    map[c.id] = window.SchedulerAlgorithm.detectConflicts(c, this.allClasses, context);
                } else {
                    map[c.id] = [];
                }
            }
            this._lastConflictMap = map;
            return map;
        },
    },
    updated() {
        if (window.lucide) window.lucide.createIcons();
    },
    methods: {
        normalizeDay(d) {
            if (!d) return '';
            return d.trim().normalize("NFD").replace(/[\u0300-\u036f]/g, "").toUpperCase();
        },
        // Internal toMins — used by computed maps (not reactive, just pure util)
        _toMins(t) {
            if (!t || typeof t !== 'string') return 0;
            const parts = t.trim().split(':');
            if (parts.length < 2) return 0;
            return (parseInt(parts[0], 10) || 0) * 60 + (parseInt(parts[1], 10) || 0);
        },
        getClassesForDay(day) {
            const nd = this.normalizeDay(day);
            return this.classesByDay[nd] || [];
        },
        getEventStyle(cls) {
            // Use pre-computed style — fall back to inline calculation if not cached yet
            if (this.eventStyleMap[cls.id]) return this.eventStyleMap[cls.id];
            // Fallback (first render before computed settles)
            const pixelsPerHour = 56;
            const sm = this._toMins(cls.start);
            const em = this._toMins(cls.end);
            const dayStartMins = this.startHour * 60;
            return {
                top: `${((sm - dayStartMins) / 60) * pixelsPerHour}px`,
                height: `${((em - sm) / 60) * pixelsPerHour}px`,
                left: '2%', width: '92%', zIndex: 10
            };
        },
        toMins(t) {
            return this._toMins(t);
        },
        getConflicts(cls) {
            return this.conflictMap[cls.id] || [];
        },
        getTeacherConflicts(cls) {
            if (!cls) return [];
            return this.getConflicts(cls).filter(i => ['teacher', 'availability', 'competency'].includes(i.type));
        },
        getRoomConflicts(cls) {
            if (!cls) return [];
            return this.getConflicts(cls).filter(i => ['room', 'capacity'].includes(i.type));
        },
        /**
         * Returns { required: N, actual: M, under: bool } for a placed card.
         * `required` = ceil(totalHours / sessionHours), `actual` = assignedDates.length.
         * `under` = true when actual < required and load data is available.
         */
        getLoadCoverage(cls) {
            if (!cls || !cls.subjectName) return { required: null, actual: null, under: false };
            const load = this.loadsMap[cls.subjectName];
            if (!load || cls.isExternal) return { required: null, actual: null, under: false };
            const totalH = parseFloat(load.total_hours || load.totalHours || 0);
            if (!totalH) return { required: null, actual: null, under: false };
            const startM = this.toMins(cls.start || '00:00');
            const endM   = this.toMins(cls.end   || '00:00');
            const durH   = endM > startM ? (endM - startM) / 60 : 0;
            if (!durH) return { required: null, actual: null, under: false };
            const required = Math.ceil(totalH / durH);
            // Mirror effectiveSessionCount: use assignedDates if present, otherwise count period dates for that day
            let actual = 0;
            if (Array.isArray(cls.assignedDates) && cls.assignedDates.length > 0) {
                actual = cls.assignedDates.length;
            } else if (cls.day && cls.day !== 'N/A' && window.SchedulerAlgorithm?.getDatesForDay) {
                const normalizedDay = cls.day.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
                actual = window.SchedulerAlgorithm.getDatesForDay(normalizedDay, this.storeState.context, cls.subperiod || 0).length;
            }
            return { required, actual, under: actual < required };
        },
        getConflictTooltip(cls) {
            const issues = this.getConflicts(cls);
            if (issues.length === 0) return cls.subjectName;
            return issues.map(i => `[${i.type.toUpperCase()}] ${i.message}`).join('\n');
        },
        // Convert Y position (pixels from top of column) to snapped time string
        yToTime(y) {
            const pixelsPerHour = 56;
            const totalMins = (y / pixelsPerHour) * 60;
            const snapped = Math.round(totalMins / 5) * 5; // snap to 5-min grid
            const absoluteMins = this.startHour * 60 + snapped;
            const h = Math.floor(absoluteMins / 60);
            const m = absoluteMins % 60;
            return `${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}`;
        },
        onDragStart(e, cls) {
            this.draggedClass = cls;
            this._hideAllGhosts();
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', cls.id);
        },
        registerGhostRef(el, day) {
            if (!this._ghostRefs) this._ghostRefs = {};
            if (el) this._ghostRefs[day] = el;
        },
        _hideAllGhosts() {
            for (const el of Object.values(this._ghostRefs || {})) {
                el.style.display = 'none';
            }
        },
        _showGhost(day, top, height, label) {
            const refs = this._ghostRefs || {};
            for (const [d, el] of Object.entries(refs)) {
                if (d === day) {
                    el.style.top    = top + 'px';
                    el.style.height = height + 'px';
                    el.style.display = 'flex';
                    const span = el.querySelector('span');
                    if (span) span.textContent = label;
                } else {
                    el.style.display = 'none';
                }
            }
        },
        onDragOver(e, day) {
            if (!this.draggedClass) return;
            e.preventDefault();

            // Capture coords immediately (event object may be recycled after rAF)
            const clientY = e.clientY;
            const rectTop = e.currentTarget.getBoundingClientRect().top;

            if (this._dragRafId) return;
            this._dragRafId = requestAnimationFrame(() => {
                this._dragRafId = null;
                if (!this.draggedClass) return;

                const y = Math.max(0, clientY - rectTop);
                const start = this.yToTime(y);

                const configDuration = this.storeState.context?.configSettings?.sessionDuration || 120;
                const isFirstPlacement = !this.draggedClass.day || this.draggedClass.day === 'N/A';
                const duration = isFirstPlacement
                    ? configDuration
                    : (this.draggedClass.end ? (this.toMins(this.draggedClass.end) - this.toMins(this.draggedClass.start)) : configDuration);

                const endMins = this.toMins(start) + duration;
                const endH = Math.floor(endMins / 60);
                const endM = endMins % 60;
                const end = `${endH.toString().padStart(2, '0')}:${endM.toString().padStart(2, '0')}`;

                const pixelsPerHour = 56;
                const dayStartMins = this.startHour * 60;
                const top = ((this.toMins(start) - dayStartMins) / 60) * pixelsPerHour;
                const height = (duration / 60) * pixelsPerHour;

                this._showGhost(day, top, height, `${start} – ${end}`);
            });
        },
        onDragLeave(day) {
            const el = (this._ghostRefs || {})[day];
            if (el) el.style.display = 'none';
        },
        onDrop(e, day) {
            if (!this.draggedClass) return;
            this._hideAllGhosts();

            const rect = e.currentTarget.getBoundingClientRect();
            const y = Math.max(0, e.clientY - rect.top);
            const start = this.yToTime(y);

            const configDuration = this.storeState.context?.configSettings?.sessionDuration || 120;
            const isFirstPlacement = !this.draggedClass.day || this.draggedClass.day === 'N/A';
            const currentDuration = isFirstPlacement
                ? configDuration
                : (this.draggedClass.end ? (this.toMins(this.draggedClass.end) - this.toMins(this.draggedClass.start)) : configDuration);
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

            // Recalculate assignedDates
            if (window.SchedulerAlgorithm?.getDatesForDay && this.storeState.context) {
                let newDates = window.SchedulerAlgorithm.getDatesForDay(
                    day, this.storeState.context, this.draggedClass.subperiod || 0
                );
                // Apply course load limit (total_hours / session_duration)
                const loads = this.storeState.context?.loads || [];
                const loadData = loads.find(l =>
                    (l.subjectname || l.subjectName) === this.draggedClass.subjectName
                );
                if (loadData && newDates.length > 0) {
                    const totalHours = parseFloat(loadData.total_hours || loadData.totalHours || 0);
                    const sessionHours = currentDuration / 60;
                    if (totalHours > 0 && sessionHours > 0) {
                        const maxSessions = Math.ceil(totalHours / sessionHours);
                        newDates = newDates.slice(0, maxSessions);
                        this.draggedClass.maxSessions = maxSessions;
                    }
                }
                this.draggedClass.assignedDates = newDates.length ? newDates : undefined;
            }

            this.draggedClass = null;
        },
        onResizeStart(e, cls) {
            if (cls.isExternal) return;
            e.preventDefault();
            this.resizingClass = cls;
            this.resizeStartY = e.clientY;
            this.resizeStartEnd = cls.end;

            const onMove = (ev) => {
                const deltaY = ev.clientY - this.resizeStartY;
                const pixelsPerHour = 56;
                const deltaMins = (deltaY / pixelsPerHour) * 60;
                const baseEndMins = this.toMins(this.resizeStartEnd);
                const newEndMins = Math.round((baseEndMins + deltaMins) / 5) * 5; // snap 5 min
                const minDuration = 15;
                const startMins = this.toMins(cls.start);
                const clampedEnd = Math.max(startMins + minDuration, newEndMins);
                const h = Math.floor(clampedEnd / 60);
                const m = clampedEnd % 60;
                cls.end = `${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}`;
            };
            const onUp = () => {
                // Recalculate assignedDates with new duration after resize
                if (this.resizingClass && window.SchedulerAlgorithm?.getDatesForDay && this.storeState.context) {
                    const resized = this.resizingClass;
                    let newDates = window.SchedulerAlgorithm.getDatesForDay(
                        resized.day, this.storeState.context, resized.subperiod || 0
                    );
                    const loads = this.storeState.context?.loads || [];
                    const loadData = loads.find(l =>
                        (l.subjectname || l.subjectName) === resized.subjectName
                    );
                    if (loadData && newDates.length > 0) {
                        const totalHours = parseFloat(loadData.total_hours || loadData.totalHours || 0);
                        const newDuration = this.toMins(resized.end) - this.toMins(resized.start);
                        const sessionHours = newDuration / 60;
                        if (totalHours > 0 && sessionHours > 0) {
                            const maxSessions = Math.ceil(totalHours / sessionHours);
                            newDates = newDates.slice(0, maxSessions);
                            resized.maxSessions = maxSessions;
                        }
                    }
                    resized.assignedDates = newDates.length ? newDates : undefined;
                }
                this.resizingClass = null;
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
            };
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        },
        formatDate(isoStr) {
            if (!isoStr) return '';
            const [y, m, d] = isoStr.split('-');
            return `${d}/${m}/${y}`;
        },
        openOptimize(cls) {
            this.selectedClass = cls;
            this.optimizeSuggestions = this._buildOptimizeSuggestions(cls);
            this.optimizeDialog = true;
            this.$nextTick(() => { if (window.lucide) window.lucide.createIcons(); });
        },
        _buildOptimizeSuggestions(cls) {
            if (!cls.day || cls.day === 'N/A' || !window.SchedulerAlgorithm?.getDatesForDay) return [];

            const context = this.storeState.context;
            const load = this.loadsMap[cls.subjectName];
            // Normalize day: remove accents so 'Miércoles' → 'Miercoles' matches getDatesForDay's dayMap
            const normalizedDay = cls.day.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            const allDates = window.SchedulerAlgorithm.getDatesForDay(normalizedDay, context, cls.subperiod || 0);
            const durMins = this.toMins(cls.end) - this.toMins(cls.start);
            const durH = durMins / 60;

            if (allDates.length === 0) return [];

            const suggestions = [];

            // --- Suggestion type A: Adjust sessions to match load ---
            if (load) {
                const totalH = parseFloat(load.total_hours || load.totalHours || 0);
                if (totalH > 0 && durH > 0) {
                    const optimal = Math.ceil(totalH / durH);
                    // Use assignedDates if non-empty, else fall back to all period dates
                    const effectiveDates = (Array.isArray(cls.assignedDates) && cls.assignedDates.length > 0) ? cls.assignedDates : allDates;
                    const current = effectiveDates.length;

                    if (current !== optimal) {
                        const dates = allDates.slice(0, optimal);
                        const label = current > optimal
                            ? `Reducir a ${optimal} sesiones (${totalH}h ÷ ${durH}h/ses)`
                            : `Ampliar a ${optimal} sesiones (${totalH}h ÷ ${durH}h/ses)`;
                        suggestions.push({
                            type: 'adjust',
                            label,
                            description: `Ajusta las sesiones exactamente a la carga horaria de ${totalH}h.`,
                            dateRange: dates.length > 0 ? `${this.formatDate(dates[0])} → ${this.formatDate(dates[dates.length - 1])}` : '',
                            dates,
                            targetClass: cls
                        });
                    }

                    // --- Suggestion type B: Split into N consecutive blocks ---
                    // If optimal < allDates.length, we can offer split options
                    // so multiple groups cover the same day consecutively
                    const siblings = this.allClasses.filter(c =>
                        c.id !== cls.id &&
                        c.subjectName === cls.subjectName &&
                        c.day && c.day !== 'N/A'
                    );

                    if (optimal < allDates.length && siblings.length > 0) {
                        // There are sibling classes on other days — just suggest adjusting
                    } else if (optimal < allDates.length) {
                        // No siblings — offer to split into 2 consecutive blocks on the same day
                        const half = Math.ceil(optimal / 2);
                        if (half >= 1 && half < allDates.length) {
                            const blockA = allDates.slice(0, half);
                            const blockB = allDates.slice(half, half * 2 <= allDates.length ? half * 2 : allDates.length);
                            if (blockB.length > 0) {
                                suggestions.push({
                                    type: 'split',
                                    label: `Dividir en 2 bloques consecutivos (${half} ses. c/u)`,
                                    description: `Bloque A: primeras ${half} semanas. Bloque B: siguientes ${blockB.length} semanas en el mismo día y hora.`,
                                    dateRange: `Bloque A: ${this.formatDate(blockA[0])} → ${this.formatDate(blockA[blockA.length-1])} | Bloque B: ${this.formatDate(blockB[0])} → ${this.formatDate(blockB[blockB.length-1])}`,
                                    dates: blockA,
                                    datesB: blockB,
                                    targetClass: cls
                                });
                            }
                        }
                    }
                }
            }

            // --- Suggestion type C: consecutive sibling detection ---
            // Find classes with same subjectName placed on same day — suggest they use consecutive date windows
            const sameSubjectSameDay = this.allClasses.filter(c =>
                c.id !== cls.id &&
                c.subjectName === cls.subjectName &&
                c.day === cls.day &&
                c.start === cls.start &&
                c.end === cls.end
            );

            if (sameSubjectSameDay.length > 0 && load) {
                const totalH = parseFloat(load.total_hours || load.totalHours || 0);
                if (totalH > 0 && durH > 0) {
                    const optimal = Math.ceil(totalH / durH);
                    const allSiblings = [cls, ...sameSubjectSameDay];
                    const hasOverlap = allSiblings.some(s =>
                        s.assignedDates && cls.assignedDates &&
                        s.id !== cls.id &&
                        s.assignedDates.some(d => cls.assignedDates.includes(d))
                    );

                    if (hasOverlap || !cls.assignedDates) {
                        const windows = [];
                        let offset = 0;
                        for (const sib of allSiblings) {
                            windows.push(allDates.slice(offset, offset + optimal));
                            offset += optimal;
                        }
                        if (windows[0]?.length > 0) {
                            const myIdx = allSiblings.findIndex(s => s.id === cls.id);
                            suggestions.push({
                                type: 'consecutive',
                                label: `Distribuir consecutivamente con ${sameSubjectSameDay.length} ficha(s) hermana(s)`,
                                description: `Asigna ventanas de ${optimal} sesiones sin solapamiento a cada grupo en el mismo día/hora.`,
                                dateRange: windows[myIdx]?.length > 0 ? `Esta ficha: ${this.formatDate(windows[myIdx][0])} → ${this.formatDate(windows[myIdx][windows[myIdx].length-1])}` : '',
                                windows,
                                siblings: allSiblings,
                                targetClass: cls
                            });
                        }
                    }
                }
            }

            return suggestions;
        },
        applySuggestion(sug) {
            if (sug.type === 'adjust') {
                sug.targetClass.assignedDates = sug.dates;
                sug.targetClass.maxSessions = sug.dates.length;
            } else if (sug.type === 'split') {
                // Apply block A to this class; block B requires a sibling — just apply A for now
                sug.targetClass.assignedDates = sug.dates;
                sug.targetClass.maxSessions = sug.dates.length;
            } else if (sug.type === 'consecutive') {
                // Apply each window to each sibling
                sug.siblings.forEach((sib, i) => {
                    if (sug.windows[i] && sug.windows[i].length > 0) {
                        sib.assignedDates = sug.windows[i];
                        sib.maxSessions = sug.windows[i].length;
                    }
                });
            }
            this.optimizeDialog = false;
            // Rebuild suggestions for updated state
            if (this.selectedClass) {
                this.optimizeSuggestions = this._buildOptimizeSuggestions(this.selectedClass);
            }
        },
        editClass(cls) {
            this.selectedClass = cls;
            this.teacherSearch = cls.teacherName || '';
            this.roomSearch = cls.room || '';
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
        hideRoomList() {
            setTimeout(() => { this.showRoomList = false; }, 200);
        },
        selectRoom(room) {
            if (!this.selectedClass) return;
            this.selectedClass.room = room.name;
            this.roomSearch = room.name;
            this.showRoomList = false;
        },
        onRoomChange() {
            if (!this.selectedClass) return;
            this.selectedClass.room = this.roomSearch || null;
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
        async publishSchedules() {
            if (!window.schedulerStore) return;
            const assignedCount = this.allClasses.filter(c => c.day && c.day !== 'N/A').length;
            if (assignedCount === 0) {
                alert('No hay clases asignadas para publicar. Ubica al menos una ficha en el calendario antes de publicar.');
                return;
            }
            if (!confirm(`¿Publicar ${assignedCount} clase(s) programada(s)? Esto creará o actualizará los registros en Gestión de Clases y los estudiantes quedarán asignados.`)) return;
            this.publishing = true;
            try {
                const periodId = window.schedulerStore.state.activePeriod;
                // First save draft to ensure latest state is in DB
                await window.schedulerStore.saveGeneration(periodId, this.allClasses);
                // Then commit to live classes
                await window.schedulerStore.publishGeneration(periodId, this.allClasses);
                alert('Horarios publicados correctamente. Las clases ya están disponibles en Gestión de Clases.');
            } catch (e) {
                alert('Error al publicar: ' + e.message);
            } finally {
                this.publishing = false;
            }
        },
        async viewStudents(cls) {
            if (!window.schedulerStore) return;

            // For external courses or if we suspect local list is incomplete, fetch from backend
            if (cls.isExternal || !cls.studentIds || cls.studentIds.length === 0 || (cls.studentCount > 0 && (!this.storeState.students || this.storeState.students.length === 0))) {
                // If the class has students but we don't have metadata, fetch specifically for this class
                const fetched = await window.schedulerStore.fetchClassStudents(cls.id);
                if (fetched && fetched.length > 0) {
                    this.currentStudents = fetched;
                    this.studentsDialog = true;
                    return;
                }
            }

            // Fallback to local filter if fetch failed or if it's internal and we have the data
            const allStudents = window.schedulerStore.state.students || [];
            this.currentStudents = allStudents.filter(s => (cls.studentIds || []).includes(s.id || s.dbId));
            this.studentsDialog = true;
        },
        viewAuditLog(cls) {
            this.selectedClass = cls;
            this.currentLog = cls.auditLog || [];
            this.logDialog = true;
        },
        // --- Quórum en ficha existente ---
        openAddQuorum(cls) {
            this.quorumTarget = cls;
            this.quorumAmount = 1;
            this.quorumDialog = true;
            this.$nextTick(() => { if (window.lucide) window.lucide.createIcons(); });
        },
        confirmAddQuorum() {
            if (!this.quorumTarget || !this.quorumAmount || this.quorumAmount < 1) return;
            window.schedulerStore.addQuorumToSchedule(this.quorumTarget.id, this.quorumAmount);
            this.quorumDialog = false;
            this.quorumTarget = null;
            this.quorumAmount = 1;
        },
        // --- Añadir asignatura sin demanda ---
        openAddSubject() {
            this.subjectSearch = '';
            this.selectedSubjectToAdd = null;
            this.addSubjectCount = 1;
            this.addSubjectDialog = true;
            this.$nextTick(() => { if (window.lucide) window.lucide.createIcons(); });
        },
        selectSubjectToAdd(subj) {
            this.selectedSubjectToAdd = subj;
        },
        confirmAddSubject() {
            if (!this.selectedSubjectToAdd || !this.addSubjectCount || this.addSubjectCount < 1) return;
            window.schedulerStore.addManualScheduleItem(this.selectedSubjectToAdd, this.addSubjectCount);
            this.addSubjectDialog = false;
            this.selectedSubjectToAdd = null;
            this.subjectSearch = '';
            this.addSubjectCount = 1;
        },
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
    },
    mounted() {
        this._ghostRefs = {};   // day -> ghost DOM element (non-reactive)
        this._dragRafId = null;
        this._onDragEnd = () => {
            if (this._dragRafId) { cancelAnimationFrame(this._dragRafId); this._dragRafId = null; }
            this._hideAllGhosts();
            this.draggedClass = null;
        };
        document.addEventListener('dragend', this._onDragEnd);
    },
    unmounted() {
        document.removeEventListener('dragend', this._onDragEnd);
        if (this._dragRafId) { cancelAnimationFrame(this._dragRafId); this._dragRafId = null; }
    }
};
