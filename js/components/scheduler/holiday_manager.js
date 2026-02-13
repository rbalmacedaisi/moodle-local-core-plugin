/**
 * Holiday Manager Component
 * Manage academic period exceptions.
 */

window.SchedulerComponents = window.SchedulerComponents || {};

window.SchedulerComponents.HolidayManager = {
    props: ['periodId'],
    template: `
        <div class="bg-white rounded-xl shadow-sm border border-slate-100 overflow-hidden mt-6">
            <div class="p-4 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                <div>
                    <h3 class="font-bold text-slate-800">Calendario de Festivos / Excepciones</h3>
                    <p class="text-xs text-slate-500">Días que se excluirán del cálculo de horas teóricas.</p>
                </div>
                <button @click="showModal = true" class="flex items-center gap-2 px-3 py-1.5 bg-orange-600 hover:bg-orange-700 text-white rounded-lg text-xs font-bold transition-colors">
                    <i data-lucide="calendar-plus" class="w-3.5 h-3.5"></i>
                    Añadir Excepción
                </button>
            </div>

            <div class="p-4">
                <div v-if="loading" class="flex justify-center py-8">
                    <i data-lucide="refresh-cw" class="w-6 h-6 text-orange-500 animate-spin"></i>
                </div>
                
                <div v-else-if="holidays.length === 0" class="text-center py-12 border-2 border-dashed border-slate-100 rounded-xl">
                    <i data-lucide="coffee" class="w-12 h-12 text-slate-200 mx-auto mb-3"></i>
                    <p class="text-slate-400 text-sm italic">Sin festivos registrados para este periodo.</p>
                </div>

                <div v-else class="space-y-2">
                    <div v-for="h in holidays" :key="h.id" class="flex items-center justify-between p-3 rounded-lg border border-slate-100 hover:bg-slate-50 transition-colors">
                        <div class="flex items-center gap-4">
                            <div class="w-10 h-10 bg-orange-50 rounded-lg flex flex-col items-center justify-center text-orange-600 border border-orange-100">
                                <span class="text-[10px] uppercase font-bold leading-none">{{ formatDate(h.date, 'MMM') }}</span>
                                <span class="text-lg font-bold leading-none">{{ formatDate(h.date, 'DD') }}</span>
                            </div>
                            <div>
                                <h4 class="font-bold text-slate-800 text-sm">{{ h.name || 'Feriado' }}</h4>
                                <p class="text-[10px] text-slate-500 uppercase font-mono">{{ h.formatted_date }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-[10px] px-2 py-0.5 bg-slate-100 text-slate-500 rounded-full font-bold uppercase">{{ h.type }}</span>
                            <button @click="deleteHoliday(h.id)" class="p-1.5 hover:bg-red-50 text-red-500 rounded-lg transition-colors">
                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal -->
            <div v-if="showModal" class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm" @click.self="closeModal">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden animate-in zoom-in-95 duration-200">
                    <div class="p-4 border-b border-slate-100 bg-slate-50 flex justify-between items-center">
                        <h4 class="font-bold text-slate-800">Programar Festivo</h4>
                        <button @click="closeModal"><i data-lucide="x" class="w-5 h-5 text-slate-400"></i></button>
                    </div>
                    <form @submit.prevent="saveHoliday" class="p-6 space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Nombre del Evento</label>
                            <input type="text" v-model="form.name" required class="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-orange-500 outline-none transition-all" placeholder="Ej: Navidad, Día del Maestro" />
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Fecha</label>
                            <input type="date" v-model="form.dateStr" required class="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-orange-500 outline-none transition-all" />
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Tipo</label>
                            <select v-model="form.type" class="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-orange-500 outline-none transition-all">
                                <option value="feriado">Feriado Nacional</option>
                                <option value="institucional">Día Institucional</option>
                                <option value="otro">Otro</option>
                            </select>
                        </div>
                        <div class="pt-4 flex gap-3">
                            <button type="button" @click="closeModal" class="flex-1 py-2.5 border border-slate-200 text-slate-600 hover:bg-slate-50 rounded-xl font-bold transition-all">Cancelar</button>
                            <button type="submit" :disabled="saving" class="flex-1 py-2.5 bg-orange-600 hover:bg-orange-700 text-white rounded-xl font-bold shadow-lg shadow-orange-200 disabled:opacity-50 transition-all">
                                {{ saving ? 'Programar' : 'Programar' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    `,
    data() {
        return {
            holidays: [],
            loading: false,
            showModal: false,
            saving: false,
            form: {
                id: null,
                name: '',
                dateStr: '',
                type: 'feriado'
            }
        };
    },
    watch: {
        periodId: {
            immediate: true,
            handler() {
                if (this.periodId) this.loadHolidays();
            }
        }
    },
    updated() {
        if (window.lucide) window.lucide.createIcons();
    },
    methods: {
        async loadHolidays() {
            if (!this.periodId) return;
            this.loading = true;
            try {
                const res = await this._call('local_grupomakro_get_holidays', { academicperiodid: this.periodId });
                this.holidays = res || [];
            } catch (e) {
                console.error(e);
            } finally {
                this.loading = false;
            }
        },
        async saveHoliday() {
            if (!this.periodId) return;
            this.saving = true;
            try {
                const timestamp = Math.floor(new Date(this.form.dateStr).getTime() / 1000) + (12 * 3600); // Noon to avoid TZ issues
                await this._call('local_grupomakro_save_holiday', {
                    ...this.form,
                    academicperiodid: this.periodId,
                    date: timestamp
                });
                this.closeModal();
                this.loadHolidays();
            } catch (e) {
                alert("Error al guardar: " + e.message);
            } finally {
                this.saving = false;
            }
        },
        async deleteHoliday(id) {
            if (!confirm("¿Eliminar este festivo?")) return;
            try {
                await this._call('local_grupomakro_delete_holiday', { id });
                this.loadHolidays();
            } catch (e) {
                alert("Error al eliminar: " + e.message);
            }
        },
        closeModal() {
            this.showModal = false;
            this.form = { id: null, name: '', dateStr: '', type: 'feriado' };
        },
        formatDate(ts, format) {
            const d = new Date(ts * 1000);
            if (format === 'DD') return d.getDate().toString().padStart(2, '0');
            if (format === 'MMM') return d.toLocaleString('es-ES', { month: 'short' }).replace('.', '');
            return d.toLocaleDateString();
        },
        async _call(action, args = {}) {
            const url = window.location.origin + '/local/grupomakro_core/ajax.php';
            const body = new URLSearchParams();
            body.append('action', action);
            body.append('sesskey', M.cfg.sesskey);
            for (const key in args) body.append(key, args[key]);

            const res = await fetch(url, { method: 'POST', body });
            const json = await res.json();
            if (json.status === 'error') throw new Error(json.message);
            return json.data;
        }
    }
};
