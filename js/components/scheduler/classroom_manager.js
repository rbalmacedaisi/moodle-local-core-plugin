/**
 * Classroom Manager Component
 * CRUD and Excel upload for physical spaces.
 */

window.SchedulerComponents = window.SchedulerComponents || {};

window.SchedulerComponents.ClassroomManager = {
    template: `
        <div class="bg-white rounded-xl shadow-sm border border-slate-100 overflow-hidden">
            <div class="p-4 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                <div>
                    <h3 class="font-bold text-slate-800">Gestión de Aulas</h3>
                    <p class="text-xs text-slate-500">Defina la capacidad y disponibilidad de espacios físicos.</p>
                </div>
                <button @click="showAddModal = true" class="flex items-center gap-2 px-3 py-1.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-xs font-bold transition-colors">
                    <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                    Nueva Aula
                </button>
            </div>

            <div class="p-4">
                <div v-if="loading" class="flex justify-center py-8">
                    <i data-lucide="refresh-cw" class="w-6 h-6 text-blue-500 animate-spin"></i>
                </div>
                
                <div v-else-if="classrooms.length === 0" class="text-center py-12 border-2 border-dashed border-slate-100 rounded-xl">
                    <i data-lucide="door-open" class="w-12 h-12 text-slate-200 mx-auto mb-3"></i>
                    <p class="text-slate-400 text-sm italic">No hay aulas registradas aún.</p>
                </div>

                <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div v-for="room in classrooms" :key="room.id" class="p-4 rounded-xl border border-slate-200 hover:border-blue-300 transition-all group relative">
                        <div class="flex justify-between items-start mb-2">
                            <span class="font-bold text-slate-800">{{ room.name }}</span>
                            <div class="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button @click="editRoom(room)" class="p-1 hover:bg-blue-50 text-blue-600 rounded"><i data-lucide="edit-2" class="w-3.5 h-3.5"></i></button>
                                <button @click="deleteRoom(room.id)" class="p-1 hover:bg-red-50 text-red-600 rounded"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button>
                            </div>
                        </div>
                        <div class="flex items-center gap-4 text-xs">
                            <div class="flex items-center gap-1.5 text-slate-500">
                                <i data-lucide="users" class="w-3.5 h-3.5"></i>
                                <span>Capacidad: <strong>{{ room.capacity }}</strong></span>
                            </div>
                            <span :class="['px-2 py-0.5 rounded-full font-bold', room.active == 1 ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-500']">
                                {{ room.active == 1 ? 'Activa' : 'Inactiva' }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal Aula -->
            <div v-if="showAddModal" class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm" @click.self="closeModal">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden animate-in zoom-in-95 duration-200">
                    <div class="p-4 border-b border-slate-100 bg-slate-50 flex justify-between items-center">
                        <h4 class="font-bold text-slate-800">{{ form.id ? 'Editar Aula' : 'Agregar Nueva Aula' }}</h4>
                        <button @click="closeModal"><i data-lucide="x" class="w-5 h-5 text-slate-400"></i></button>
                    </div>
                    <form @submit.prevent="saveClassroom" class="p-6 space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Nombre / Código</label>
                            <input type="text" v-model="form.name" required class="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none transition-all" placeholder="Ej: Aula 101, Laboratorio A" />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Capacidad Máx.</label>
                                <input type="number" v-model.number="form.capacity" required class="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none transition-all" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Estado</label>
                                <select v-model.number="form.active" class="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-blue-500 outline-none transition-all">
                                    <option :value="1">Activa</option>
                                    <option :value="0">Inactiva</option>
                                </select>
                            </div>
                        </div>
                        <div class="pt-4 flex gap-3">
                            <button type="button" @click="closeModal" class="flex-1 py-2.5 border border-slate-200 text-slate-600 hover:bg-slate-50 rounded-xl font-bold transition-all">Cancelar</button>
                            <button type="submit" :disabled="saving" class="flex-1 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl font-bold shadow-lg shadow-blue-200 disabled:opacity-50 transition-all">
                                {{ saving ? 'Guardando...' : 'Guardar' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    `,
    data() {
        return {
            classrooms: [],
            loading: false,
            showAddModal: false,
            saving: false,
            form: {
                id: null,
                name: '',
                capacity: 40,
                active: 1,
                type: 'general'
            }
        };
    },
    mounted() {
        this.loadClassrooms();
    },
    updated() {
        if (window.lucide) window.lucide.createIcons();
    },
    methods: {
        async loadClassrooms() {
            this.loading = true;
            try {
                const res = await this._call('local_grupomakro_get_classrooms');
                this.classrooms = res || [];
            } catch (e) {
                console.error(e);
            } finally {
                this.loading = false;
            }
        },
        async saveClassroom() {
            this.saving = true;
            try {
                await this._call('local_grupomakro_save_classroom', this.form);
                this.closeModal();
                this.loadClassrooms();
            } catch (e) {
                alert("Error al guardar: " + e.message);
            } finally {
                this.saving = false;
            }
        },
        editRoom(room) {
            this.form = { ...room };
            this.showAddModal = true;
        },
        async deleteRoom(id) {
            if (!confirm("¿Está seguro de eliminar esta aula?")) return;
            try {
                await this._call('local_grupomakro_delete_classroom', { id });
                this.loadClassrooms();
            } catch (e) {
                alert("Error al eliminar: " + e.message);
            }
        },
        closeModal() {
            this.showAddModal = false;
            this.form = { id: null, name: '', capacity: 40, active: 1, type: 'general' };
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
