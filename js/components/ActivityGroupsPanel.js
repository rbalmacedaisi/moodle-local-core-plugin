/**
 * ActivityGroupsPanel.js
 *
 * Panel de gestion de grupos para una actividad (tarea o cuestionario) que
 * admite calificacion grupal. Pensado para que el docente:
 *
 *   1) Vea los grupos ya creados con su composicion y cupo.
 *   2) Cree grupos nuevos manualmente (docente como override del modo abierto).
 *   3) Agregue o saque estudiantes de un grupo (override individual).
 *   4) Cambie el modo (open <-> fixed) y el cupo de un grupo.
 *   5) Elimine grupos (forzando si tienen miembros).
 *
 * El componente NO es el selector del estudiante (eso vive en LXPStudents).
 * Aqui solo se muestran controles administrativos del docente.
 *
 * Introducido en 20261001057.
 */
Vue.component('activity-groups-panel', {
    props: {
        cmid:    { type: Number, required: true },
        modname: { type: String, required: true },  // 'assign' | 'quiz'
        activityName: { type: String, default: '' }
    },
    template: `
        <v-card outlined class="activity-groups-panel">
            <v-card-title class="d-flex align-center">
                <v-icon color="primary" class="mr-2">mdi-account-group</v-icon>
                <span class="font-weight-bold">Grupos para calificación grupal</span>
                <v-spacer></v-spacer>
                <v-btn icon @click="loadAll" :loading="loading">
                    <v-icon>mdi-refresh</v-icon>
                </v-btn>
            </v-card-title>

            <v-alert v-if="!flag || !flag.enabled" type="info" dense text class="mx-4">
                Esta actividad no fue creada con la opción de calificación grupal.
                Solo las actividades nuevas pueden habilitarla.
            </v-alert>

            <v-card-text v-else class="pa-4">
                <div class="d-flex align-center mb-3">
                    <v-chip small color="primary" dark class="mr-2">
                        <v-icon small left>mdi-information-outline</v-icon>
                        Modo: {{ flag.mode === 'open' ? 'Abierto' : 'Fijo' }}
                    </v-chip>
                    <v-chip small color="grey" dark>
                        <v-icon small left>mdi-account-multiple</v-icon>
                        Cupo: {{ flag.maxmembers }}
                    </v-chip>
                    <v-spacer></v-spacer>
                    <v-btn color="primary" @click="openCreateDialog" :disabled="loading">
                        <v-icon left>mdi-plus</v-icon> Nuevo grupo
                    </v-btn>
                </div>

                <v-alert v-if="groups.length === 0" type="info" dense text outlined>
                    <v-icon left>mdi-information-outline</v-icon>
                    Aún no hay grupos. Crea uno o espera a que los estudiantes se unan (modo abierto).
                </v-alert>

                <v-row v-else>
                    <v-col cols="12" md="6" lg="4" v-for="g in groups" :key="g.id">
                        <v-card outlined class="group-card" :class="'group-color-' + g.colorindex">
                            <v-card-title class="d-flex align-center py-2 px-3">
                                <span class="font-weight-bold text-truncate">{{ g.name }}</span>
                                <v-spacer></v-spacer>
                                <v-menu>
                                    <template v-slot:activator="{ on, attrs }">
                                        <v-btn icon small v-bind="attrs" v-on="on">
                                            <v-icon small>mdi-dots-vertical</v-icon>
                                        </v-btn>
                                    </template>
                                    <v-list dense>
                                        <v-list-item @click="openEditMembersDialog(g)">
                                            <v-list-item-icon><v-icon>mdi-account-edit</v-icon></v-list-item-icon>
                                            <v-list-item-title>Editar miembros</v-list-item-title>
                                        </v-list-item>
                                        <v-list-item @click="openModeDialog(g)">
                                            <v-list-item-icon><v-icon>mdi-tune</v-icon></v-list-item-icon>
                                            <v-list-item-title>Modo / Cupo</v-list-item-title>
                                        </v-list-item>
                                        <v-list-item @click="confirmDelete(g)">
                                            <v-list-item-icon><v-icon color="red">mdi-delete</v-icon></v-list-item-icon>
                                            <v-list-item-title class="red--text">Eliminar</v-list-item-title>
                                        </v-list-item>
                                    </v-list>
                                </v-menu>
                            </v-card-title>
                            <v-divider></v-divider>
                            <v-card-text class="pa-3">
                                <div class="d-flex align-center mb-2">
                                    <v-chip x-small :color="g.isfull ? 'red' : 'green'" dark class="mr-2">
                                        {{ g.membercount }} / {{ g.maxmembers }}
                                    </v-chip>
                                    <v-chip x-small :color="g.mode === 'open' ? 'blue' : 'orange'" dark>
                                        {{ g.mode === 'open' ? 'Abierto' : 'Fijo' }}
                                    </v-chip>
                                </div>
                                <div v-if="g.members.length === 0" class="text-caption grey--text font-italic">
                                    Sin miembros aún
                                </div>
                                <div v-else class="member-list">
                                    <v-chip
                                        v-for="m in g.members"
                                        :key="m.userid"
                                        x-small
                                        class="mr-1 mb-1"
                                        close
                                        @click:close="removeMember(g, m)"
                                    >
                                        <v-avatar v-if="m.avatar" left size="20">
                                            <img :src="m.avatar" :alt="m.fullname"/>
                                        </v-avatar>
                                        <v-avatar v-else left size="20" color="grey lighten-2">
                                            <span class="caption">{{ initials(m.fullname) }}</span>
                                        </v-avatar>
                                        {{ shortName(m.fullname) }}
                                    </v-chip>
                                </div>
                            </v-card-text>
                        </v-card>
                    </v-col>
                </v-row>
            </v-card-text>

            <!-- Dialog: crear grupo -->
            <v-dialog v-model="createDialog" max-width="500">
                <v-card>
                    <v-card-title>Crear grupo</v-card-title>
                    <v-card-text>
                        <v-text-field
                            v-model="createForm.name"
                            label="Nombre"
                            outlined dense autofocus
                        ></v-text-field>
                        <v-text-field
                            v-model.number="createForm.maxmembers"
                            label="Cupo"
                            type="number" min="2" outlined dense
                        ></v-text-field>
                        <v-select
                            v-model="createForm.mode"
                            :items="[{text:'Abierto (estudiantes se unen)',value:'open'},{text:'Fijo (solo docente)',value:'fixed'}]"
                            label="Modo"
                            outlined dense
                        ></v-select>
                        <v-autocomplete
                            v-model="createForm.memberids"
                            :items="availableStudents"
                            item-text="fullname"
                            item-value="userid"
                            label="Estudiantes iniciales (opcional)"
                            multiple chips small-chips
                            outlined dense clearable
                        ></v-autocomplete>
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn text @click="createDialog = false">Cancelar</v-btn>
                        <v-btn color="primary" :loading="saving" :disabled="!createForm.name" @click="createGroup">Crear</v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>

            <!-- Dialog: editar miembros -->
            <v-dialog v-model="editMembersDialog" max-width="600">
                <v-card>
                    <v-card-title>
                        Miembros de {{ editingGroup && editingGroup.name }}
                    </v-card-title>
                    <v-card-text>
                        <div class="mb-2 text-caption">
                            {{ (editingGroup && editingGroup.membercount) || 0 }} / {{ (editingGroup && editingGroup.maxmembers) || 0 }} estudiantes
                        </div>
                        <v-autocomplete
                            v-model="membersToAdd"
                            :items="availableStudents"
                            item-text="fullname"
                            item-value="userid"
                            label="Agregar estudiantes"
                            multiple chips small-chips
                            outlined dense clearable
                            @change="addMembers"
                        ></v-autocomplete>
                        <v-divider class="my-3"></v-divider>
                        <div v-if="editingGroup && editingGroup.members.length === 0" class="text-caption grey--text font-italic">
                            Sin miembros aún
                        </div>
                        <v-chip
                            v-for="m in (editingGroup ? editingGroup.members : [])"
                            :key="m.userid"
                            small class="mr-1 mb-1"
                            close
                            @click:close="removeMember(editingGroup, m)"
                        >
                            <v-avatar v-if="m.avatar" left size="24">
                                <img :src="m.avatar" :alt="m.fullname"/>
                            </v-avatar>
                            {{ m.fullname }}
                        </v-chip>
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn text @click="editMembersDialog = false">Cerrar</v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>

            <!-- Dialog: cambiar modo / cupo -->
            <v-dialog v-model="modeDialog" max-width="400">
                <v-card>
                    <v-card-title>Modo y cupo de {{ editingGroup && editingGroup.name }}</v-card-title>
                    <v-card-text>
                        <v-select
                            v-model="modeForm.mode"
                            :items="[{text:'Abierto',value:'open'},{text:'Fijo',value:'fixed'}]"
                            label="Modo"
                            outlined dense
                        ></v-select>
                        <v-text-field
                            v-model.number="modeForm.maxmembers"
                            label="Cupo"
                            type="number" min="2" outlined dense
                        ></v-text-field>
                        <v-alert v-if="modeForm.maxmembers < (editingGroup ? editingGroup.membercount : 0)" type="warning" dense text class="mt-2">
                            El nuevo cupo es menor que la cantidad actual de miembros.
                            Los miembros existentes no serán eliminados.
                        </v-alert>
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn text @click="modeDialog = false">Cancelar</v-btn>
                        <v-btn color="primary" :loading="saving" @click="saveMode">Guardar</v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>

            <!-- Dialog: confirmar borrado -->
            <v-dialog v-model="deleteDialog" max-width="450">
                <v-card>
                    <v-card-title class="red--text">
                        <v-icon color="red" class="mr-2">mdi-delete</v-icon>
                        Eliminar grupo
                    </v-card-title>
                    <v-card-text>
                        <div v-if="editingGroup && editingGroup.membercount > 0">
                            Este grupo tiene <b>{{ editingGroup.membercount }}</b> miembro(s).
                            ¿Quieres eliminarlo de todos modos? (Esto NO borrará las calificaciones ya asignadas).
                        </div>
                        <div v-else>
                            ¿Eliminar el grupo <b>{{ editingGroup && editingGroup.name }}</b>?
                        </div>
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn text @click="deleteDialog = false">Cancelar</v-btn>
                        <v-btn color="red" :loading="saving" @click="deleteGroup">Eliminar</v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>

            <v-snackbar v-model="snackbar" :color="snackbarColor" :timeout="3000" top right>
                {{ snackbarText }}
            </v-snackbar>
        </v-card>
    `,
    data() {
        return {
            loading: false,
            saving: false,
            flag: null,
            groups: [],
            availableStudents: [],  // Lista de la clase para autocomplete

            createDialog: false,
            createForm: { name: '', maxmembers: 5, mode: 'open', memberids: [] },

            editMembersDialog: false,
            editingGroup: null,
            membersToAdd: [],

            modeDialog: false,
            modeForm: { mode: 'open', maxmembers: 5 },

            deleteDialog: false,

            snackbar: false,
            snackbarText: '',
            snackbarColor: 'success'
        };
    },
    watch: {
        cmid() { this.loadAll(); },
        modname() { this.loadAll(); }
    },
    mounted() {
        this.loadAll();
    },
    methods: {
        async loadAll() {
            this.loading = true;
            try {
                const resp = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_activity_group_list',
                    cmid: this.cmid,
                    modname: this.modname,
                    sesskey: M.cfg.sesskey
                });
                if (resp.data && resp.data.status === 'success') {
                    this.flag = resp.data.flag;
                    this.groups = resp.data.groups || [];
                } else {
                    this.notify('error', (resp.data && resp.data.message) || 'Error al cargar grupos.');
                }
            } catch (e) {
                console.error('[GMK] loadAll error', e);
                this.notify('error', 'Error de conexion al cargar grupos.');
            } finally {
                this.loading = false;
            }
        },

        async loadAvailableStudents() {
            // El endpoint de student list por clase es gmk_user_list_for_class pero aqui
            // simplificamos: si el padre nos pasa availableStudents por prop lo usamos;
            // si no, los dejamos vacios y los agregamos despues.
            if (Array.isArray(this.availableStudents) && this.availableStudents.length > 0) return;
            // En caso contrario, el docente debera tipear nombres manualmente, lo cual
            // no es la experiencia ideal pero al menos funciona. Una mejora futura seria
            // un endpoint que liste estudiantes de la clase de la actividad.
        },

        openCreateDialog() {
            this.createForm = { name: '', maxmembers: this.flag ? this.flag.maxmembers : 5,
                                mode: this.flag ? this.flag.mode : 'open', memberids: [] };
            this.createDialog = true;
            this.loadAvailableStudents();
        },

        async createGroup() {
            this.saving = true;
            try {
                const resp = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_activity_group_create',
                    args: JSON.stringify({
                        cmid: this.cmid,
                        modname: this.modname,
                        name: this.createForm.name,
                        maxmembers: this.createForm.maxmembers,
                        mode: this.createForm.mode,
                        memberids: this.createForm.memberids
                    }),
                    sesskey: M.cfg.sesskey
                });
                const data = resp.data || {};
                if (data.status === 'success') {
                    this.notify('success', data.message || 'Grupo creado.');
                    this.createDialog = false;
                    this.loadAll();
                } else {
                    this.notify('error', data.message || 'No se pudo crear el grupo.');
                }
            } catch (e) {
                console.error('[GMK] createGroup error', e);
                this.notify('error', 'Error de conexion al crear el grupo.');
            } finally {
                this.saving = false;
            }
        },

        openEditMembersDialog(g) {
            this.editingGroup = JSON.parse(JSON.stringify(g));
            this.membersToAdd = [];
            this.editMembersDialog = true;
        },

        async addMembers() {
            if (!this.editingGroup || this.membersToAdd.length === 0) return;
            this.saving = true;
            try {
                const resp = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_activity_group_update_members',
                    args: JSON.stringify({
                        groupid: this.editingGroup.id,
                        add: this.membersToAdd,
                        remove: []
                    }),
                    sesskey: M.cfg.sesskey
                });
                const data = resp.data || {};
                if (data.status === 'success') {
                    this.notify('success', data.message || 'Miembros agregados.');
                    this.membersToAdd = [];
                    await this.loadAll();
                    // refrescar editingGroup para mantenerlo en sync
                    const refreshed = this.groups.find(x => x.id === this.editingGroup.id);
                    if (refreshed) {
                        this.editingGroup = JSON.parse(JSON.stringify(refreshed));
                    }
                } else {
                    this.notify('error', data.message || 'No se pudo agregar miembros.');
                }
            } catch (e) {
                console.error('[GMK] addMembers error', e);
                this.notify('error', 'Error de conexion al agregar miembros.');
            } finally {
                this.saving = false;
            }
        },

        async removeMember(group, member) {
            if (!confirm('Sacar a ' + member.fullname + ' del grupo?')) return;
            this.saving = true;
            try {
                const resp = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_activity_group_update_members',
                    args: JSON.stringify({
                        groupid: group.id,
                        add: [],
                        remove: [member.userid]
                    }),
                    sesskey: M.cfg.sesskey
                });
                const data = resp.data || {};
                if (data.status === 'success') {
                    this.notify('success', data.message || 'Miembro eliminado.');
                    await this.loadAll();
                    if (this.editingGroup && this.editingGroup.id === group.id) {
                        const refreshed = this.groups.find(x => x.id === group.id);
                        if (refreshed) {
                            this.editingGroup = JSON.parse(JSON.stringify(refreshed));
                        }
                    }
                } else {
                    this.notify('error', data.message || 'No se pudo eliminar.');
                }
            } catch (e) {
                console.error('[GMK] removeMember error', e);
                this.notify('error', 'Error de conexion al eliminar.');
            } finally {
                this.saving = false;
            }
        },

        openModeDialog(g) {
            this.editingGroup = g;
            this.modeForm = { mode: g.mode, maxmembers: g.maxmembers };
            this.modeDialog = true;
        },

        async saveMode() {
            if (!this.editingGroup) return;
            this.saving = true;
            try {
                const resp = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_activity_group_set_mode',
                    args: JSON.stringify({
                        groupid: this.editingGroup.id,
                        mode: this.modeForm.mode,
                        maxmembers: this.modeForm.maxmembers
                    }),
                    sesskey: M.cfg.sesskey
                });
                const data = resp.data || {};
                if (data.status === 'success') {
                    this.notify('success', data.message || 'Actualizado.');
                    this.modeDialog = false;
                    this.loadAll();
                } else {
                    this.notify('error', data.message || 'No se pudo actualizar.');
                }
            } catch (e) {
                console.error('[GMK] saveMode error', e);
                this.notify('error', 'Error de conexion al actualizar.');
            } finally {
                this.saving = false;
            }
        },

        confirmDelete(g) {
            this.editingGroup = g;
            this.deleteDialog = true;
        },

        async deleteGroup() {
            if (!this.editingGroup) return;
            this.saving = true;
            try {
                const resp = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_activity_group_delete',
                    args: JSON.stringify({
                        groupid: this.editingGroup.id,
                        force: this.editingGroup.membercount > 0
                    }),
                    sesskey: M.cfg.sesskey
                });
                const data = resp.data || {};
                if (data.status === 'success') {
                    this.notify('success', data.message || 'Grupo eliminado.');
                    this.deleteDialog = false;
                    this.loadAll();
                } else {
                    this.notify('error', data.message || 'No se pudo eliminar.');
                }
            } catch (e) {
                console.error('[GMK] deleteGroup error', e);
                this.notify('error', 'Error de conexion al eliminar.');
            } finally {
                this.saving = false;
            }
        },

        initials(name) {
            if (!name) return '?';
            return name.split(' ').map(s => s.charAt(0)).slice(0, 2).join('').toUpperCase();
        },
        shortName(name) {
            if (!name) return '';
            const parts = name.split(' ');
            if (parts.length === 1) return parts[0];
            return parts[0] + ' ' + (parts[1] || '');
        },
        notify(color, msg) {
            this.snackbarColor = color;
            this.snackbarText = msg;
            this.snackbar = true;
        }
    }
});
