/**
 * General Configuration Component (Vue 3 + Tailwind)
 * Allows setting global scheduler parameters (interval, shifts, lunch)
 */

window.SchedulerComponents = window.SchedulerComponents || {};

window.SchedulerComponents.GeneralConfig = {
    template: `
        <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200 animate-in fade-in duration-300">
            <div class="flex items-center justify-between mb-6">
                <h3 class="font-bold text-slate-700 flex items-center gap-2">
                    <i data-lucide="clock" class="w-5 h-5 text-orange-500"></i>
                    Parámetros Generales de Horario
                </h3>
                <button 
                    @click="saveConfig" 
                    :disabled="storeState.loading"
                    class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-lg flex items-center gap-2 transition-colors">
                    <span v-show="!storeState.loading" class="flex items-center"><i data-lucide="save" class="w-4 h-4"></i></span>
                    <span v-show="storeState.loading" class="flex items-center"><i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i></span>
                    Guardar Parámetros
                </button>
            </div>
            
            <div v-if="storeState.error" class="mb-6 p-4 bg-red-50 text-red-700 border border-red-200 rounded-lg flex items-start gap-3">
                <span class="mt-0.5"><i data-lucide="alert-circle" class="w-5 h-5"></i></span>
                <p class="text-sm font-medium">{{ storeState.error }}</p>
            </div>
            <div v-if="storeState.successMessage" class="mb-6 p-4 bg-green-50 text-green-700 border border-green-200 rounded-lg flex items-start gap-3">
                <span class="mt-0.5"><i data-lucide="check-circle" class="w-5 h-5"></i></span>
                <p class="text-sm font-medium">{{ storeState.successMessage }}</p>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <!-- Left: Global Settings -->
                <div class="space-y-6">
                    <!-- Interval and Global Bounds -->
                    <div class="grid grid-cols-2 gap-4 pb-4 border-b border-slate-100">
                        <div>
                            <label class="block text-[10px] font-bold text-slate-500 mb-1 uppercase">Intervalo (min)</label>
                            <input
                                type="number"
                                v-model.number="localConfig.intervalMinutes"
                                class="w-full px-3 py-2 border border-slate-300 rounded focus:ring-2 focus:ring-orange-200 outline-none"
                                min="5"
                                step="5"
                            />
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 mb-1 uppercase">Inicio Global</label>
                                <input type="time" v-model="localConfig.startTime" class="w-full px-2 py-2 border border-slate-300 rounded text-xs focus:ring-2 focus:ring-orange-200 outline-none" />
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-slate-500 mb-1 uppercase">Fin Global</label>
                                <input type="time" v-model="localConfig.endTime" class="w-full px-2 py-2 border border-slate-300 rounded text-xs focus:ring-2 focus:ring-orange-200 outline-none" />
                            </div>
                        </div>
                    </div>

                    <!-- LUNCH HOUR RANGE -->
                    <div class="p-4 bg-orange-50 rounded-lg border border-orange-100">
                        <div class="flex items-center gap-2 text-orange-700 mb-2">
                            <i data-lucide="coffee" class="w-5 h-5"></i>
                            <span class="text-xs font-bold uppercase">Rango de Almuerzo (Restringido)</span>
                        </div>
                        <div class="flex items-center gap-3 mt-2">
                            <div class="flex-1">
                                <label class="block text-[10px] font-bold text-orange-600 mb-1 uppercase">Inicio Almuerzo</label>
                                <input
                                    type="time"
                                    v-model="localConfig.lunchStart"
                                    class="w-full px-3 py-2 border border-orange-200 rounded text-xs focus:ring-2 focus:ring-orange-300 outline-none"
                                />
                            </div>
                            <span class="text-orange-300 font-bold self-end mb-2">-</span>
                            <div class="flex-1">
                                <label class="block text-[10px] font-bold text-orange-600 mb-1 uppercase">Fin Almuerzo</label>
                                <input
                                    type="time"
                                    v-model="localConfig.lunchEnd"
                                    class="w-full px-3 py-2 border border-orange-200 rounded text-xs focus:ring-2 focus:ring-orange-300 outline-none"
                                />
                            </div>
                        </div>
                        <p class="text-[10px] text-orange-600 mt-2 italic">* El algoritmo no programará clases dentro de este rango independientemente de la jornada.</p>
                    </div>
                </div>

                <!-- Right: Shift Specific Windows -->
                <div>
                     <h4 class="text-sm font-bold text-slate-700 mb-3 uppercase tracking-wider">Límites por Jornada</h4>
                     <p class="text-xs text-slate-500 mb-4">
                        Defina las ventanas de tiempo válidas para programar clases según la jornada de la cohorte.
                     </p>
                     
                     <div class="space-y-3">
                         <div v-for="shift in ['Diurna', 'Nocturna', 'Sabatina']" :key="shift" class="flex items-center gap-4 bg-slate-50 p-3 rounded-lg border border-slate-200 hover:border-blue-200 transition-colors">
                             <div class="min-w-[80px]">
                                 <span class="font-bold text-xs text-slate-700 uppercase">{{ shift }}</span>
                             </div>
                             <div class="flex items-center gap-2 flex-1">
                                 <input
                                     type="time"
                                     v-model="localConfig.shiftWindows[shift].start"
                                     class="px-2 py-1.5 border border-slate-300 rounded text-xs w-full focus:ring-2 focus:ring-blue-200 outline-none"
                                 />
                                 <span class="text-slate-400 font-bold">-</span>
                                 <input
                                     type="time"
                                     v-model="localConfig.shiftWindows[shift].end"
                                     class="px-2 py-1.5 border border-slate-300 rounded text-xs w-full focus:ring-2 focus:ring-blue-200 outline-none"
                                 />
                             </div>
                         </div>
                     </div>
                </div>
            </div>
        </div>
    `,
    props: {
        periodId: {
            type: Number,
            required: true
        }
    },
    data() {
        return {
            localConfig: {
                intervalMinutes: 30,
                startTime: '07:00',
                endTime: '22:00',
                lunchStart: '12:00',
                lunchEnd: '13:00',
                shiftWindows: {
                    Diurna: { start: '07:00', end: '18:00' },
                    Nocturna: { start: '18:00', end: '22:00' },
                    Sabatina: { start: '07:00', end: '17:00' }
                }
            }
        };
    },
    computed: {
        storeState() {
            return window.schedulerStore ? window.schedulerStore.state : {};
        },
        globalConfig() {
            return this.storeState.context?.configSettings || {};
        }
    },
    watch: {
        globalConfig: {
            handler(newVal) {
                if (newVal && Object.keys(newVal).length > 0) {
                    // Deep merge to default structure
                    this.localConfig = JSON.parse(JSON.stringify({ ...this.localConfig, ...newVal }));

                    // Ensure shiftWindows exists
                    if (!this.localConfig.shiftWindows) {
                        this.localConfig.shiftWindows = {
                            Diurna: { start: '07:00', end: '18:00' },
                            Nocturna: { start: '18:00', end: '22:00' },
                            Sabatina: { start: '07:00', end: '17:00' }
                        };
                    } else {
                        ['Diurna', 'Nocturna', 'Sabatina'].forEach(shift => {
                            if (!this.localConfig.shiftWindows[shift]) {
                                this.localConfig.shiftWindows[shift] = { start: '07:00', end: '22:00' };
                            }
                        });
                    }
                }
            },
            deep: true,
            immediate: true
        }
    },
    mounted() {
        if (window.lucide) {
            window.lucide.createIcons();
        }
        this.loadInitialData();
    },
    updated() {
        if (window.lucide) {
            window.lucide.createIcons();
        }
    },
    methods: {
        async loadInitialData() {
            if (this.periodId && window.schedulerStore) {
                // If context is completely missing or empty, fetch it so settings populate
                if (!this.storeState.context || !this.storeState.context.configSettings) {
                    await window.schedulerStore.loadContext(this.periodId);
                }
            }
        },
        async saveConfig() {
            if (!this.periodId || !window.schedulerStore) return;

            // Clean up config object
            const configToSave = JSON.parse(JSON.stringify(this.localConfig));
            await window.schedulerStore.saveConfigSettings(this.periodId, configToSave);
        }
    }
};
