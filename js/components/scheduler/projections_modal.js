/**
 * Projections Modal Component (Vue 3 + Tailwind)
 * Allows users to add manual student projections (new entrants) to the demand.
 */

window.SchedulerComponents = window.SchedulerComponents || {};

window.SchedulerComponents.ProjectionsModal = {
    props: ['periodId', 'modelValue'], // Vue 3 v-model uses modelValue
    emits: ['update:modelValue'],
    template: `
        <div v-if="modelValue" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/30 backdrop-blur-sm" @click.self="close">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-3xl overflow-hidden animate-in zoom-in-95 duration-200">
                <!-- Header -->
                <div class="p-4 border-b border-slate-200 bg-slate-50 flex justify-between items-center">
                    <h3 class="font-bold text-slate-800 text-lg">Proyecciones de Nuevos Ingresos</h3>
                    <button @click="close" class="p-1 hover:bg-slate-200 rounded-full transition-colors"><i data-lucide="x" class="w-5 h-5 text-slate-400"></i></button>
                </div>
                
                <div class="p-6">
                    <div class="bg-blue-50 border-l-4 border-blue-500 p-4 rounded-r-lg mb-6 text-sm text-blue-800 mb-4">
                        <i data-lucide="info" class="w-4 h-4 inline mr-1"></i>
                        Agregue estudiantes estimados para primer ingreso que aún no están matriculados. Estos se sumarán a la demanda para la generación de horarios.
                    </div>

                    <!-- Add Form -->
                    <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end mb-6">
                        <div class="md:col-span-5">
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Carrera</label>
                            <select v-model="newEntry.career" class="w-full px-3 py-2 bg-white border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none text-sm">
                                <option value="" disabled>Seleccione...</option>
                                <option v-for="plan in formattedPlans" :key="plan" :value="plan">{{ plan }}</option>
                            </select>
                        </div>
                        <div class="md:col-span-4">
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Jornada</label>
                            <select v-model="newEntry.shift" class="w-full px-3 py-2 bg-white border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none text-sm">
                                <option value="" disabled>Seleccione...</option>
                                <option v-for="s in shifts" :key="s" :value="s">{{ s }}</option>
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Cantidad</label>
                            <input type="number" v-model.number="newEntry.count" min="1" class="w-full px-3 py-2 bg-white border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 outline-none text-sm font-mono" />
                        </div>
                        <div class="md:col-span-1">
                            <button @click="addProjection" :disabled="!isValidEntry" class="w-full p-2 bg-blue-600 hover:bg-blue-700 disabled:bg-slate-300 text-white rounded-lg flex justify-center items-center transition-colors">
                                <i data-lucide="plus" class="w-5 h-5"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="border-t border-slate-100 pt-4">
                        <div class="overflow-x-auto rounded-lg border border-slate-200">
                             <table class="w-full text-left text-sm">
                                <thead class="bg-slate-50 text-slate-500 text-xs uppercase font-bold">
                                    <tr>
                                        <th class="p-3 border-b">Carrera</th>
                                        <th class="p-3 border-b">Jornada</th>
                                        <th class="p-3 border-b text-center">Cantidad</th>
                                        <th class="p-3 border-b text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr v-for="(item, index) in localProjections" :key="index" class="hover:bg-slate-50">
                                        <td class="p-3 text-slate-700 font-medium">{{ item.career }}</td>
                                        <td class="p-3 text-slate-600">{{ item.shift }}</td>
                                        <td class="p-3 text-center font-mono">{{ item.count }}</td>
                                        <td class="p-3 text-right">
                                            <button @click="removeProjection(index)" class="text-red-500 hover:bg-red-50 p-1.5 rounded transition-colors">
                                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <tr v-if="localProjections.length === 0">
                                        <td colspan="4" class="p-4 text-center text-slate-400 italic">No hay proyecciones agregadas.</td>
                                    </tr>
                                </tbody>
                             </table>
                        </div>
                    </div>
                </div>
                
                <div class="p-4 bg-slate-50 border-t border-slate-200 flex justify-end gap-3">
                    <button @click="close" class="px-4 py-2 text-sm font-bold text-slate-500 hover:bg-slate-200 rounded-lg transition-colors">Cancelar</button>
                    <button @click="save" :disabled="saving" class="px-6 py-2 text-sm font-bold bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-all shadow-md flex items-center gap-2">
                        <i v-if="saving" data-lucide="loader-2" class="w-4 h-4 animate-spin"></i>
                        Guardar
                    </button>
                </div>
            </div>
        </div>
    `,
    data() {
        return {
            newEntry: {
                career: '',
                shift: '',
                count: 10
            },
            shifts: ['Matutina', 'Nocturna', 'Sabatina', 'Virtual'],
            localProjections: [],
            saving: false
        };
    },
    computed: {
        storeState() {
            return window.schedulerStore ? window.schedulerStore.state : {};
        },
        formattedPlans() {
            return (this.storeState.plans || []).map(p => p.fullname || p.name);
        },
        isValidEntry() {
            return this.newEntry.career && this.newEntry.shift && this.newEntry.count > 0;
        }
    },
    watch: {
        modelValue(val) {
            if (val) {
                const current = this.storeState.projections || [];
                this.localProjections = current.map(p => ({
                    career: p.career,
                    shift: p.shift,
                    count: parseInt(p.count)
                }));
                // Re-init icons when modal opens
                if (window.lucide) setTimeout(() => window.lucide.createIcons(), 100);
            }
        }
    },
    updated() {
        if (window.lucide) window.lucide.createIcons();
    },
    methods: {
        close() {
            this.$emit('update:modelValue', false);
        },
        addProjection() {
            if (!this.isValidEntry) return;

            const existing = this.localProjections.find(p =>
                p.career === this.newEntry.career && p.shift === this.newEntry.shift
            );

            if (existing) {
                existing.count += parseInt(this.newEntry.count);
            } else {
                this.localProjections.push({ ...this.newEntry });
            }
            // Reset count but keep selection for ease of entry?
            this.newEntry.count = 10;
        },
        removeProjection(index) {
            this.localProjections.splice(index, 1);
        },
        async save() {
            if (!window.schedulerStore) return;
            this.saving = true;
            try {
                await window.schedulerStore.saveProjections(this.periodId, this.localProjections);
                this.close();
            } catch (e) {
                console.error(e);
                alert("Error guardando: " + e.message);
            } finally {
                this.saving = false;
            }
        }
    }
};
