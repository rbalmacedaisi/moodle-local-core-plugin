/**
 * Admin staff roster (RF-03 / RF-09.3).
 *
 * Vue 2 + Vuetify 2. Mounted by pages/wellness_staff_panel.php.
 *
 * Features:
 *  - Table of every rolekey (psicÃ³logo titular/suplente, Talento Humano,
 *    Bienestar Estudiantil) with linked Moodle user + email override.
 *  - Dialog with v-autocomplete to pick the new user (calls the existing
 *    local_grupomakro_search_users WS).
 *  - "Ver historial" drawer with v-timeline of audit rows.
 */
Vue.component('staff-panel', {
    data() {
        return {
            roles: [],
            catalog: {},
            loading: false,
            saving: false,
            dialog: false,
            editing: null,
            form: {
                rolekey: '',
                role_label: '',
                userid: 0,
                email_override: '',
                notify_on_request: true,
                notify_on_change: true,
            },
            userSearch: '',
            userOptions: [],
            historyDialog: false,
            historyRole: null,
            historyRows: [],
            snack: { show: false, color: 'success', text: '' },
        }
    },
    async mounted() {
        await this.refresh()
    },
    methods: {
        async refresh() {
            this.loading = true
            try {
                const res = await axios.post(ajaxUrl, {
                    action: 'local_grupomakro_admin_list_staff',
                    args: {},
                }, { params: { sesskey }, timeout: 30000 })
                if (res.data && res.data.status === 'success' && res.data.data) {
                    this.roles = res.data.data.roles || []
                    const cat = {}
                    for (const c of (res.data.data.catalog || [])) cat[c.rolekey] = c.label
                    this.catalog = cat
                }
            } catch (e) {
                this.toast('Error: ' + (e.message || e), 'error')
            } finally {
                this.loading = false
            }
        },
        toast(text, color = 'success') {
            this.snack = { show: true, color, text }
        },
        openEdit(role) {
            this.editing = role
            this.form = {
                rolekey: role.rolekey,
                role_label: role.role_label || '',
                userid: role.userid,
                email_override: role.email_override || '',
                notify_on_request: !!role.notify_on_request,
                notify_on_change: !!role.notify_on_change,
            }
            this.userSearch = role.user_fullname || ''
            this.userOptions = role.userid
                ? [{ id: role.userid, fullname: role.user_fullname, email: role.user_email }]
                : []
            this.dialog = true
        },
        async onUserQuery(value) {
            if (!value || value.length < 2) { this.userOptions = []; return }
            try {
                const res = await axios.post(ajaxUrl, {
                    action: 'local_grupomakro_search_users',
                    args: { query: value, limit: 8 },
                }, { params: { sesskey }, timeout: 30000 })
                if (res.data && res.data.status === 'success' && res.data.data) {
                    this.userOptions = res.data.data.users || []
                }
            } catch (e) {
                // Soft-fail: keep the current selection.
            }
        },
        pickUser(u) {
            this.form.userid = u.id
            this.userSearch = u.fullname || ((u.firstname || '') + ' ' + (u.lastname || ''))
        },
        clearUser() {
            this.form.userid = 0
            this.userSearch = ''
        },
        async save() {
            this.saving = true
            try {
                const res = await axios.post(ajaxUrl, {
                    action: 'local_grupomakro_admin_upsert_staff',
                    args: {
                        rolekey: this.form.rolekey,
                        role_label: this.form.role_label,
                        userid: this.form.userid,
                        email_override: this.form.email_override,
                        notify_on_request: this.form.notify_on_request,
                        notify_on_change: this.form.notify_on_change,
                    },
                }, { params: { sesskey }, timeout: 30000 })
                if (res.data && res.data.status === 'success') {
                    this.toast('Personal actualizado.')
                    this.dialog = false
                    await this.refresh()
                } else {
                    this.toast(res.data && res.data.message ? res.data.message : 'Error', 'error')
                }
            } catch (e) {
                this.toast('Error: ' + (e.message || e), 'error')
            } finally {
                this.saving = false
            }
        },
        async openHistory(role) {
            this.historyRole = role
            this.historyDialog = true
            try {
                const res = await axios.post(ajaxUrl, {
                    action: 'local_grupomakro_admin_staff_history',
                    args: { rolekey: role.rolekey },
                }, { params: { sesskey }, timeout: 30000 })
                if (res.data && res.data.status === 'success' && res.data.data) {
                    this.historyRows = res.data.data.history || []
                }
            } catch (e) {
                this.toast('Error: ' + (e.message || e), 'error')
            }
        },
        formatDate(ts) {
            if (!ts) return 'â€”'
            return new Date(ts * 1000).toLocaleString()
        },
    },
    template: `
<v-container fluid>
  <v-alert type="info" text class="mb-4">
    Cada rol estÃ¡ mapeado a un usuario Moodle con login. El <strong>email override</strong>
    es opcional: si se define, las notificaciones salen a esa direcciÃ³n (Ãºtil para bandejas
    genÃ©ricas del Ã¡rea como <em>bienestar&#64;isi.edu.pa</em>); si se deja vacÃ­o, se usa el email del usuario.
    Todos los cambios quedan auditados.
  </v-alert>

  <v-card>
    <v-card-title>Personal asignado</v-card-title>
    <v-data-table
      :headers="[
        { text: 'Rol', value: 'role_label' },
        { text: 'Usuario Moodle', value: 'user_fullname' },
        { text: 'Email destino', value: 'effective_email' },
        { text: 'Notificaciones', value: '_notif', sortable: false },
        { text: 'Activo', value: 'active', align: 'center' },
        { text: 'Acciones', value: '_actions', sortable: false, align: 'center' },
      ]"
      :items="roles"
      :loading="loading"
      dense
    >
      <template v-slot:item.role_label="{ item }">
        {{ item.role_label }}
        <div class="caption grey--text">{{ item.rolekey }}</div>
      </template>
      <template v-slot:item.user_fullname="{ item }">
        <span v-if="item.userid">{{ item.user_fullname }}</span>
        <span v-else class="grey--text caption">(sin asignar)</span>
        <div v-if="item.userid" class="caption grey--text">{{ item.user_email }}</div>
      </template>
      <template v-slot:item.effective_email="{ item }">
        <span v-if="item.effective_email">{{ item.effective_email }}</span>
        <span v-else class="grey--text caption">(vacÃ­o)</span>
      </template>
      <template v-slot:item._notif="{ item }">
        <v-chip x-small :color="item.notify_on_request ? 'green' : 'grey'" class="mr-1" dark>
          {{ item.notify_on_request ? 'Solicitudes' : 'â€”' }}
        </v-chip>
        <v-chip x-small :color="item.notify_on_change ? 'blue' : 'grey'" dark>
          {{ item.notify_on_change ? 'Cambios' : 'â€”' }}
        </v-chip>
      </template>
      <template v-slot:item.active="{ item }">
        <v-chip :color="item.active ? 'green' : 'grey'" small dark>{{ item.active ? 'SÃ­' : 'No' }}</v-chip>
      </template>
      <template v-slot:item._actions="{ item }">
        <v-btn icon small @click="openEdit(item)"><v-icon>mdi-pencil</v-icon></v-btn>
        <v-btn icon small @click="openHistory(item)" title="Historial"><v-icon>mdi-history</v-icon></v-btn>
      </template>
    </v-data-table>
  </v-card>

  <!-- Edit dialog -->
  <v-dialog v-model="dialog" max-width="640" scrollable>
    <v-card>
      <v-card-title v-if="editing">
        Editar â€” {{ catalog[editing.rolekey] || editing.rolekey }}
      </v-card-title>
      <v-card-text>
        <v-autocomplete
          v-model="form.userid"
          :items="userOptions"
          :search-input.sync="userSearch"
          item-text="fullname"
          item-value="id"
          label="Usuario Moodle"
          placeholder="Buscar por nombre, apellido o email"
          prepend-inner-icon="mdi-account-search"
          @update:search-input="onUserQuery"
          clearable
          @click:clear="clearUser"
          return-object
        >
          <template v-slot:item="{ item }">
            <v-list-item-content>
              <v-list-item-title>{{ item.fullname }}</v-list-item-title>
              <v-list-item-subtitle>{{ item.email }}</v-list-item-subtitle>
            </v-list-item-content>
          </template>
          <template v-slot:selection="{ item }">
            <span v-if="item">{{ item.fullname }} ({{ item.email }})</span>
          </template>
        </v-autocomplete>

        <v-text-field v-model="form.role_label" label="Etiqueta visible" hint="Ej: Dulce Jurado â€” Talento Humano" persistent-hint></v-text-field>
        <v-text-field v-model="form.email_override" label="Email override" hint="Si se deja vacÃ­o, se usa el email del usuario Moodle seleccionado." persistent-hint></v-text-field>

        <v-switch v-model="form.notify_on_request" label="Recibir copia cuando un estudiante solicita cita" inset></v-switch>
        <v-switch v-model="form.notify_on_change" label="Recibir copia en cambios de estado (confirmar / cancelar / modificar)" inset></v-switch>
      </v-card-text>
      <v-card-actions>
        <v-spacer></v-spacer>
        <v-btn text @click="dialog = false">Cancelar</v-btn>
        <v-btn color="primary" :loading="saving" @click="save">Guardar</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <!-- History timeline dialog -->
  <v-dialog v-model="historyDialog" max-width="720" scrollable>
    <v-card v-if="historyRole">
      <v-card-title>
        Historial â€” {{ catalog[historyRole.rolekey] || historyRole.rolekey }}
        <v-spacer></v-spacer>
        <v-btn icon @click="historyDialog = false"><v-icon>mdi-close</v-icon></v-btn>
      </v-card-title>
      <v-card-text>
        <v-timeline v-if="historyRows.length > 0" align-top dense>
          <v-timeline-item
            v-for="h in historyRows"
            :key="h.id"
            :color="h.new_userid ? 'green' : 'grey'"
            small
          >
            <template v-slot:opposite>
              <span class="caption grey--text">{{ formatDate(h.changed_at) }}</span>
            </template>
            <div class="text-body-2">
              <strong>{{ h.changed_by_name }}</strong>
              cambiÃ³ de
              <span v-if="h.old_fullname">{{ h.old_fullname }}</span><span v-else class="grey--text">(sin asignar)</span>
              <span v-if="h.old_email"> &lt;{{ h.old_email }}&gt;</span>
              â†’ <strong>{{ h.new_fullname || '(sin asignar)' }}</strong>
              <span v-if="h.new_email"> &lt;{{ h.new_email }}&gt;</span>
            </div>
          </v-timeline-item>
        </v-timeline>
        <v-alert v-else type="info" text>
          Sin cambios registrados para este rol.
        </v-alert>
      </v-card-text>
    </v-card>
  </v-dialog>

  <v-snackbar v-model="snack.show" :color="snack.color" timeout="3500" top>
    {{ snack.text }}
  </v-snackbar>
</v-container>
`
});