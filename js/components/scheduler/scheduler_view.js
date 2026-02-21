/**
 * Scheduler View Component (Vue 3 + Tailwind)
 * Main orchestrator for the Scheduling Module.
 */

window.SchedulerComponents = window.SchedulerComponents || {};

window.SchedulerComponents.SchedulerView = {
    template: `
        <div class="space-y-6">
            <!-- Toolbar -->
            <div class="flex flex-col md:flex-row justify-between items-center gap-4 bg-white p-4 rounded-xl shadow-sm border border-gray-100">
                <div class="flex items-center gap-2">
                    <i data-lucide="calendar-clock" class="w-6 h-6 text-blue-600"></i>
                    <h2 class="text-xl font-bold text-slate-800">Planificador de Horarios</h2>
                </div>
                
                <div class="flex items-center gap-4 w-full md:w-auto">
                    <div class="flex flex-col flex-1 md:w-64">
                         <label class="text-xs text-slate-500 font-bold mb-1">Periodo Académico</label>
                         <select v-model="selectedPeriod" @change="onPeriodChange" class="bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm font-medium focus:ring-2 focus:ring-blue-500 outline-none">
                            <option :value="null" disabled>Seleccione un periodo</option>
                            <option v-for="p in periods" :key="p.id" :value="p.id">{{ p.name }}</option>
                         </select>
                    </div>
                    
                    <div class="flex flex-col flex-1 md:w-48">
                         <label class="text-xs text-slate-500 font-bold mb-1">Visualización</label>
                         <select v-model="storeState.subperiodFilter" class="bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm font-medium focus:ring-2 focus:ring-blue-500 outline-none">
                            <option :value="0">Todo el Periodo</option>
                            <option :value="1">P-I (Bloque 1)</option>
                            <option :value="2">P-II (Bloque 2)</option>
                         </select>
                    </div>

                    <div class="flex flex-col flex-1 md:w-64">
                         <label class="text-xs text-slate-500 font-bold mb-1">Carrera (Filtro Matrix)</label>
                         <select v-model="storeState.careerFilter" class="bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm font-medium focus:ring-2 focus:ring-blue-500 outline-none">
                            <option :value="null">Todas las Carreras</option>
                            <option v-for="career in careerList" :key="career" :value="career">{{ career }}</option>
                         </select>
                    </div>

                    <div class="flex flex-col flex-1 md:w-64">
                         <label class="text-xs text-slate-500 font-bold mb-1">Jornada (Filtro Matrix)</label>
                         <select v-model="storeState.shiftFilter" class="bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-sm font-medium focus:ring-2 focus:ring-blue-500 outline-none">
                            <option :value="null">Todas las Jornadas</option>
                            <option v-for="shift in shiftList" :key="shift" :value="shift">{{ shift }}</option>
                         </select>
                    </div>

                    <button @click="refreshData" :disabled="storeState.loading" class="p-2 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-lg transition-colors mt-4">
                        <i data-lucide="refresh-cw" class="w-5 h-5" :class="{'animate-spin': storeState.loading}"></i>
                    </button>
                </div>
            </div>

            <!-- Tabs -->
            <div class="border-b border-slate-200 flex gap-4 overflow-x-auto">
                <button 
                    @click="activeTab = 0"
                    :class="['px-4 py-2 text-sm font-bold border-b-2 transition-colors flex items-center gap-2', activeTab === 0 ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-700']">
                    <i data-lucide="bar-chart-2" class="w-4 h-4"></i> Análisis de Demanda
                </button>
                <button 
                    @click="activeTab = 1"
                    :disabled="!isPeriodSelected"
                    :class="['px-4 py-2 text-sm font-bold border-b-2 transition-colors flex items-center gap-2', activeTab === 1 ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:text-slate-700', !isPeriodSelected ? 'opacity-50 cursor-not-allowed' : '']">
                    <i data-lucide="layout-grid" class="w-4 h-4"></i> Tablero de Planificación
                </button>
                <button 
                    @click="activeTab = 2"
                    :disabled="!isPeriodSelected"
                    :class="['px-4 py-2 text-sm font-bold border-b-2 transition-colors flex items-center gap-2', activeTab === 2 ? 'border-orange-600 text-orange-700' : 'border-transparent text-slate-500 hover:text-slate-700', !isPeriodSelected ? 'opacity-50 cursor-not-allowed' : '']">
                    <i data-lucide="file-text" class="w-4 h-4"></i> Reportes
                </button>
            </div>

            <!-- Content -->
            <div class="mt-4">
                <!-- Tab 0: Demand View -->
                <div v-if="activeTab === 0">
                     <div v-if="!isPeriodSelected" class="flex flex-col items-center justify-center py-20 text-slate-400">
                        <i data-lucide="mouse-pointer-2" class="w-16 h-16 mb-4 opacity-50"></i>
                        <p class="text-lg font-medium">Seleccione un periodo arriba para comenzar</p>
                     </div>
                     <demand-view 
                        v-else
                        :period-id="selectedPeriod"
                        @generate="goToBoard"
                     ></demand-view>
                </div>

                <!-- Tab 1: Planning Board -->
                <div v-if="activeTab === 1 && isPeriodSelected" class="flex flex-col h-full">
                    <div class="flex justify-end mb-2">
                        <div class="bg-white border border-slate-200 rounded-lg p-1 flex gap-1 shadow-sm">
                            <button 
                                @click="boardView = 'calendar'"
                                :class="['px-3 py-1 text-xs font-bold rounded transition-colors', boardView === 'calendar' ? 'bg-blue-100 text-blue-700' : 'text-slate-500 hover:bg-slate-50']"
                            >
                                <i data-lucide="calendar" class="w-3 h-3 inline mr-1"></i> Vista Semanal
                            </button>
                            <button 
                                @click="boardView = 'monthly'"
                                :class="['px-3 py-1 text-xs font-bold rounded transition-colors', boardView === 'monthly' ? 'bg-teal-100 text-teal-700' : 'text-slate-500 hover:bg-slate-50']"
                            >
                                <i data-lucide="calendar-days" class="w-3 h-3 inline mr-1"></i> Calendario Mensual
                            </button>
                            <button 
                                @click="boardView = 'grouped'" 
                                :class="['px-3 py-1 text-xs font-bold rounded transition-colors', boardView === 'grouped' ? 'bg-blue-100 text-blue-700' : 'text-slate-500 hover:bg-slate-50']"
                            >
                                <i data-lucide="layers" class="w-3 h-3 inline mr-1"></i> Por Periodo de Ingreso
                            </button>
                        </div>
                    </div>

                    <planning-board 
                        v-if="boardView === 'calendar'"
                        :period-id="selectedPeriod"
                    ></planning-board>
                    <full-calendar-view
                        v-else-if="boardView === 'monthly'"
                    ></full-calendar-view>
                    <period-grouped-view 
                        v-else
                        :period-id="selectedPeriod"
                    ></period-grouped-view>
                </div>
                
                <!-- Tab 2: Reports -->
                <div v-if="activeTab === 2 && isPeriodSelected" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <div @click="exportGroupPDF" class="bg-white p-6 rounded-xl shadow-sm border border-slate-100 hover:shadow-md cursor-pointer transition-all hover:border-red-200 group">
                        <div class="flex items-center justify-center w-12 h-12 bg-red-50 rounded-full mb-4 group-hover:bg-red-100 transition-colors">
                            <i data-lucide="file-text" class="w-6 h-6 text-red-600"></i>
                        </div>
                        <h3 class="text-lg font-bold text-slate-800 mb-1">Horarios por Grupo</h3>
                        <p class="text-sm text-slate-500">Generar PDF matricial con horarios semanales agrupados por cohorte/grupo.</p>
                    </div>
                    
                    <div @click="exportTeacherPDF" class="bg-white p-6 rounded-xl shadow-sm border border-slate-100 hover:shadow-md cursor-pointer transition-all hover:border-blue-200 group">
                        <div class="flex items-center justify-center w-12 h-12 bg-blue-50 rounded-full mb-4 group-hover:bg-blue-100 transition-colors">
                            <i data-lucide="users" class="w-6 h-6 text-blue-600"></i>
                        </div>
                        <h3 class="text-lg font-bold text-slate-800 mb-1">Horarios por Docente</h3>
                        <p class="text-sm text-slate-500">Generar PDF individual con la carga horaria asignada a cada docente.</p>
                    </div>
                </div>
            </div>
            
            <!-- Toast / Snackbar -->
            <div v-if="snackbar.show" class="fixed bottom-4 right-4 z-50 animate-in slide-in-from-bottom-5 fade-in duration-300">
                 <div :class="['px-4 py-3 rounded-lg shadow-lg flex items-center gap-3 text-white font-medium', snackbar.color === 'success' ? 'bg-green-600' : 'bg-red-600']">
                     <i :data-lucide="snackbar.color === 'success' ? 'check-circle' : 'alert-circle'" class="w-5 h-5"></i>
                     <span>{{ snackbar.text }}</span>
                     <button @click="snackbar.show = false" class="ml-2 opacity-80 hover:opacity-100"><i data-lucide="x" class="w-4 h-4"></i></button>
                 </div>
            </div>
        </div>
    `,
    data() {
        return {
            activeTab: 0,
            boardView: 'calendar',
            selectedPeriod: null,
            periods: [],
            snackbar: { show: false, text: '', color: 'success' }
        };
    },
    computed: {
        storeState() {
            return window.schedulerStore ? window.schedulerStore.state : {};
        },
        isPeriodSelected() {
            return !!this.selectedPeriod;
        },
        careerList() {
            const demand = this.storeState.demand || {};
            return Object.keys(demand).sort();
        },
        shiftList() {
            const demand = this.storeState.demand || {};
            const shifts = new Set();
            Object.values(demand).forEach(cDetails => {
                Object.keys(cDetails).forEach(sName => shifts.add(sName));
            });
            return Array.from(shifts).sort();
        }
    },
    created() {
        this.loadPeriods();
        // Expose switch function for other components
        window.switchSchedulerTab = (index) => {
            this.activeTab = index;
        };
    },
    updated() {
        // Refresh icons when DOM updates
        if (window.lucide) window.lucide.createIcons();
    },
    methods: {
        async loadPeriods() {
            try {
                const response = await this._fetch('local_grupomakro_get_academic_periods');
                if (response) {
                    this.periods = response;
                }
            } catch (e) {
                console.error("Error loading periods", e);
            }
        },
        async onPeriodChange() {
            if (!this.selectedPeriod) return;
            if (window.schedulerStore) {
                await window.schedulerStore.loadAll(this.selectedPeriod);
                this.activeTab = 0;
            }
        },
        refreshData() {
            if (this.selectedPeriod) {
                this.onPeriodChange();
            }
        },
        goToBoard() {
            this.activeTab = 1;
        },
        async _fetch(action, params = {}) {
            const url = window.wsUrl || (window.location.origin + '/local/grupomakro_core/ajax.php');
            const body = new URLSearchParams();
            body.append('action', action);
            body.append('sesskey', M.cfg.sesskey);
            for (const k in params) body.append(k, params[k]);

            const res = await fetch(url, { method: 'POST', body });
            const json = await res.json();
            return json.status === 'success' ? json.data : [];
        },
        exportGroupPDF() {
            if (!window.SchedulerPDF || !window.schedulerStore) return;
            const state = window.schedulerStore.state;
            const schedules = state.generatedSchedules;
            const subperiod = state.subperiodFilter;
            const period = this.periods.find(p => p.id === this.selectedPeriod);

            const cohortsMap = {};
            schedules.forEach(sch => {
                const key = `${sch.career} - ${sch.shift} - ${sch.levelDisplay || 'S?'}`;
                if (!cohortsMap[key]) {
                    cohortsMap[key] = {
                        key: key,
                        career: sch.career,
                        shift: sch.shift,
                        schedules: []
                    };
                }
                cohortsMap[key].schedules.push(sch);
            });

            window.SchedulerPDF.generateGroupSchedulesPDF(Object.values(cohortsMap), period ? period.name : '', new Set(), subperiod);
        },
        exportTeacherPDF() {
            if (!window.SchedulerPDF || !window.schedulerStore) return;
            const state = window.schedulerStore.state;
            const period = this.periods.find(p => p.id === this.selectedPeriod);
            const subperiod = state.subperiodFilter;
            window.SchedulerPDF.generateTeacherSchedulesPDF(state.generatedSchedules, period ? period.name : '', subperiod);
        },
        showToast(text, color = 'success') {
            this.snackbar = { show: true, text, color };
            setTimeout(() => this.snackbar.show = false, 3000);
        }
    },
    watch: {
        'storeState.error'(val) {
            if (val) this.showToast(val, 'error');
        },
        'storeState.successMessage'(val) {
            if (val) {
                this.showToast(val, 'success');
                window.schedulerStore.state.successMessage = null;
            }
        }
    }
};
