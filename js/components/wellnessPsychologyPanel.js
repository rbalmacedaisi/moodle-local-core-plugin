/**
 * Admin psychology panel: agenda + slots editor (RF-09.3).
 *
 * Two tabs:
 *   1. Agenda (filterable by psychologist / status / date range)
 *   2. Slots (CRUD over gmk_wellness_psy_slot)
 *
 * Vue 2 + Vuetify 2, mounted by /local/grupomakro_core/pages/wellness_psychology_panel.php.
 */
Vue.component('psychology-panel', {
    data() {
        const today = new Date()
        const from = Math.floor(today.getTime() / 1000) - 7 * 86400
        const to = from + 90 * 86400
        return {
            tab: 'agenda',
            // Agenda filters
            fPsycho: 0,
            fStatus: '',
            fFrom: from,
            fTo: to,
            // Data
            appointments: [],
            slots: [],
            specialists: [], // unique psychologist list derived from appointments
            loading: false,
            // Status change dialog
            statusDialog: false,
            statusAppt: null,
            statusNew: '',
            statusReason: '',
            statusNotes: '',
            statusSaving: false,
            // Slot dialog
            slotDialog: false,
            slotSaving: false,
            slot: this._blankSlot(),
            snack: { show: false, color: 'success', text: '' },
        }
    },
    computed: {
        statusItems() {
            return [
                { text: 'Todos', value: '' },
                { text: 'Pendiente', value: 'pendiente' },
                { text: 'Confirmada', value: 'confirmada' },
                { text: 'Modificada', value: 'modificada' },
                { text: 'Cancelada', value: 'cancelada' },
                { text: 'Atendida', value: 'atendida' },
                { text: 'No asistiÃƒÂ³', value: 'no_asistio' },
            ]
        },
        weekdayItems() {
            return [
                { text: 'Domingo', value: 0 },
                { text: 'Lunes', value: 1 },
                { text: 'Martes', value: 2 },
                { text: 'MiÃƒÂ©rcoles', value: 3 },
                { text: 'Jueves', value: 4 },
                { text: 'Viernes', value: 5 },
                { text: 'SÃƒÂ¡bado', value: 6 },
            ]
        },
        modalityItems() {
            return [
                { text: 'Presencial', value: 'presencial' },
                { text: 'Virtual', value: 'virtual' },
                { text: 'Mixto', value: 'mixto' },
            ]
        },
        statusColor() {
            return (s) => ({
                pendiente:  'orange',
                confirmada: 'green',
                modificada: 'blue',
                cancelada:  'grey',
                atendida:   'teal',
                no_asistio: 'red',
            })[s] || 'grey'
        },
        statusLabel() {
            return (s) => ({
                pendiente:  'Pendiente',
                confirmada: 'Confirmada',
                modificada: 'Modificada',
                cancelada:  'Cancelada',
                atendida:   'Atendida',
                no_asistio: 'No asistiÃƒÂ³',
            })[s] || s
        },
        modalityLabel() {
            return (m) => ({ presencial: 'Presencial', virtual: 'Virtual', mixto: 'Mixto' })[m] || m
        },
        psychologistOptions() {
            const map = new Map()
            for (const a of this.appointments) {
                if (a.psychologist_userid) {
                    map.set(a.psychologist_userid, a.psychologist_name || ('#' + a.psychologist_userid))
                }
            }
            return [{ text: 'Todos los psicÃƒÂ³logos', value: 0 },
                ...Array.from(map, ([id, name]) => ({ text: name, value: id }))]
        },
    },
    async mounted() {
        await this.refreshAppointments()
        await this.refreshSlots()
    },
    methods: {
        _blankSlot() {
            return {
                id: 0,
                psychologist_userid: 0,
                weekday: 1,
                starttime: '09:00',
                endtime: '10:00',
                modality: 'presencial',
                duration_minutes: 45,
                location: '',
                valid_from: 0,
                valid_until: 0,
                active: true,
            }
        },
        async refreshAppointments() {
            this.loading = true
            try {
                const res = await axios.post(ajaxUrl, {
                    action: 'local_grupomakro_admin_list_psychology_appointments',
                    args: {
                        psychologist_userid: this.fPsycho,
                        status: this.fStatus,
                        from: this.fFrom,
                        to: this.fTo,
                    },
                }, { params: { sesskey }, timeout: 30000 })
                if (res.data && res.data.status === 'success' && res.data.data) {
                    this.appointments = res.data.data.appointments || []
                }
            } catch (e) {
                this.toast('Error al cargar la agenda: ' + (e.message || e), 'error')
            } finally {
                this.loading = false
            }
        },
        async refreshSlots() {
            try {
                const res = await axios.post(ajaxUrl, {
                    action: 'local_grupomakro_admin_save_psychology_slots',
                    args: { action: 'list' },
                }, { params: { sesskey }, timeout: 30000 })
                if (res.data && res.data.status === 'success' && res.data.data) {
                    this.slots = res.data.data.slots || []
                }
            } catch (e) {
                this.toast('Error al cargar slots: ' + (e.message || e), 'error')
            }
        },
        toast(text, color = 'success') {
            this.snack = { show: true, color, text }
        },
        formatDate(ts) {
            if (!ts) return 'Ã¢â‚¬â€'
            return new Date(ts * 1000).toLocaleString()
        },
        weekdayLabel(w) {
            return this.weekdayItems.find(x => x.value === w)?.text || ''
        },
        openStatusDialog(a) {
            this.statusAppt = a
            this.statusNew = a.status
            this.statusReason = ''
            this.statusNotes = ''
            this.statusDialog = true
        },
        async saveStatus() {
            if (!this.statusAppt) return
            this.statusSaving = true
            try {
                const res = await axios.post(ajaxUrl, {
                    action: 'local_grupomakro_admin_update_psychology_appointment',
                    args: {
                        appointmentid: this.statusAppt.id,
                        status: this.statusNew,
                        cancel_reason: this.statusReason,
                        attendees_notes: this.statusNotes,
                        new_appointment_at: 0,
                    },
                }, { params: { sesskey }, timeout: 30000 })
                if (res.data && res.data.status === 'success') {
                    this.toast('Estado actualizado.')
                    this.statusDialog = false
                    await this.refreshAppointments()
                } else {
                    this.toast(res.data && res.data.message ? res.data.message : 'Error', 'error')
                }
            } catch (e) {
                this.toast('Error: ' + (e.message || e), 'error')
            } finally {
                this.statusSaving = false
            }
        },
        openSlotDialog(s) {
            this.slot = s
                ? Object.assign(this._blankSlot(), s)
                : this._blankSlot()
            this.slotDialog = true
        },
        async saveSlot() {
            this.slotSaving = true
            try {
                const res = await axios.post(ajaxUrl, {
                    action: 'local_grupomakro_admin_save_psychology_slots',
                    args: {
                        action: 'upsert',
                        slot: JSON.stringify(this.slot),
                    },
                }, { params: { sesskey }, timeout: 30000 })
                if (res.data && res.data.status === 'success') {
                    this.toast('Slot guardado.')
                    this.slotDialog = false
                    await this.refreshSlots()
                } else {
                    this.toast(res.data && res.data.message ? res.data.message : 'Error', 'error')
                }
            } catch (e) {
                this.toast('Error: ' + (e.message || e), 'error')
            } finally {
                this.slotSaving = false
            }
        },
        async toggleSlot(s) {
            try {
                const res = await axios.post(ajaxUrl, {
                    action: 'local_grupomakro_admin_save_psychology_slots',
                    args: { action: 'toggle', slotid: s.id, active: !s.active },
                }, { params: { sesskey }, timeout: 30000 })
                if (res.data && res.data.status === 'success') {
                    await this.refreshSlots()
                }
            } catch (e) {
                this.toast('Error: ' + (e.message || e), 'error')
            }
        },
    },
    template: `
<v-container fluid>
  <v-tabs v-model="tab" background-color="primary" dark grow>
    <v-tab value="agenda">
      <v-icon left>mdi-calendar-clock</v-icon> Agenda
    </v-tab>
    <v-tab value="slots">
      <v-icon left>mdi-clock-outline</v-icon> Horarios recurrentes
    </v-tab>
  </v-tabs>

  <v-window v-model="tab" class="mt-4">

    <!-- Ã¢â€â‚¬Ã¢â€â‚¬ AGENDA Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ -->
    <v-window-item value="agenda">
      <v-card>
        <v-card-title>
          <v-select v-model="fPsycho" :items="psychologistOptions" label="Especialista" hide-details style="max-width:240px"></v-select>
          <v-select v-model="fStatus" :items="statusItems" label="Estado" hide-details style="max-width:200px" class="ml-3"></v-select>
          <v-spacer></v-spacer>
          <v-btn color="primary" @click="refreshAppointments">
            <v-icon left>mdi-refresh</v-icon> Refrescar
          </v-btn>
        </v-card-title>
        <v-data-table
          :headers="[
            { text: 'Fecha', value: 'appointment_at' },
            { text: 'Estudiante', value: 'student_fullname' },
            { text: 'PsicÃƒÂ³logo', value: 'psychologist_name' },
            { text: 'Modalidad', value: 'modality' },
            { text: 'Estado', value: 'status' },
            { text: 'Motivo', value: 'reason', sortable: false },
            { text: 'Acciones', value: '_actions', sortable: false, align: 'center' },
          ]"
          :items="appointments"
          :loading="loading"
          dense
        >
          <template v-slot:item.appointment_at="{ item }">{{ formatDate(item.appointment_at) }}</template>
          <template v-slot:item.student_fullname="{ item }">
            {{ item.student_firstname }} {{ item.student_lastname }}
            <div class="caption grey--text">{{ item.student_email }}</div>
          </template>
          <template v-slot:item.modality="{ item }">{{ modalityLabel(item.modality) }}</template>
          <template v-slot:item.status="{ item }">
            <v-chip :color="statusColor(item.status)" small dark>{{ statusLabel(item.status) }}</v-chip>
          </template>
          <template v-slot:item.reason="{ item }">
            <div class="text-truncate" style="max-width:280px">{{ item.reason }}</div>
          </template>
          <template v-slot:item._actions="{ item }">
            <v-btn icon small @click="openStatusDialog(item)"><v-icon>mdi-pencil</v-icon></v-btn>
          </template>
        </v-data-table>
      </v-card>
    </v-window-item>

    <!-- Ã¢â€â‚¬Ã¢â€â‚¬ SLOTS Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬Ã¢â€â‚¬ -->
    <v-window-item value="slots">
      <v-card>
        <v-card-title>
          <span class="text-h6">Slots publicados</span>
          <v-spacer></v-spacer>
          <v-btn color="primary" @click="openSlotDialog(null)">
            <v-icon left>mdi-plus</v-icon> Nuevo slot
          </v-btn>
        </v-card-title>
        <v-data-table
          :headers="[
            { text: 'PsicÃƒÂ³logo', value: 'psychologist_name' },
            { text: 'DÃƒÂ­a', value: 'weekday' },
            { text: 'Inicio', value: 'starttime' },
            { text: 'Fin', value: 'endtime' },
            { text: 'DuraciÃƒÂ³n', value: 'duration_minutes' },
            { text: 'Modalidad', value: 'modality' },
            { text: 'UbicaciÃƒÂ³n', value: 'location', sortable: false },
            { text: 'Activo', value: 'active', align: 'center' },
            { text: 'Acciones', value: '_actions', sortable: false, align: 'center' },
          ]"
          :items="slots"
          dense
        >
          <template v-slot:item.weekday="{ item }">{{ weekdayLabel(item.weekday) }}</template>
          <template v-slot:item.modality="{ item }">{{ modalityLabel(item.modality) }}</template>
          <template v-slot:item.active="{ item }">
            <v-chip :color="item.active ? 'green' : 'grey'" small dark>{{ item.active ? 'SÃƒÂ­' : 'No' }}</v-chip>
          </template>
          <template v-slot:item._actions="{ item }">
            <v-btn icon small @click="openSlotDialog(item)"><v-icon>mdi-pencil</v-icon></v-btn>
            <v-btn icon small @click="toggleSlot(item)">
              <v-icon>{{ item.active ? 'mdi-toggle-switch' : 'mdi-toggle-switch-off-outline' }}</v-icon>
            </v-btn>
          </template>
        </v-data-table>
      </v-card>
    </v-window-item>

  </v-window>

  <!-- Status dialog -->
  <v-dialog v-model="statusDialog" max-width="600" scrollable>
    <v-card>
      <v-card-title v-if="statusAppt">
        Cambiar estado Ã¢â‚¬â€ {{ statusAppt.student_firstname }} {{ statusAppt.student_lastname }}
        <div class="caption grey--text">{{ formatDate(statusAppt.appointment_at) }} Ã‚Â· {{ modalityLabel(statusAppt.modality) }}</div>
      </v-card-title>
      <v-card-text>
        <v-select v-model="statusNew" :items="statusItems.filter(x => x.value !== '')" label="Nuevo estado"></v-select>
        <v-textarea v-if="statusNew === 'cancelada'" v-model="statusReason" label="Motivo de cancelaciÃƒÂ³n" rows="2"></v-textarea>
        <v-textarea v-model="statusNotes" label="Notas del especialista" rows="3"></v-textarea>
      </v-card-text>
      <v-card-actions>
        <v-spacer></v-spacer>
        <v-btn text @click="statusDialog = false">Cancelar</v-btn>
        <v-btn color="primary" :loading="statusSaving" @click="saveStatus">Guardar</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <!-- Slot dialog -->
  <v-dialog v-model="slotDialog" max-width="600" scrollable>
    <v-card>
      <v-card-title>{{ slot.id ? 'Editar slot' : 'Nuevo slot' }}</v-card-title>
      <v-card-text>
        <v-text-field v-model.number="slot.psychologist_userid" label="ID PsicÃƒÂ³logo (userid)" type="number" hint="Use el admin de Staff para mapear el usuario correctamente." persistent-hint></v-text-field>
        <v-select v-model="slot.weekday" :items="weekdayItems" label="DÃƒÂ­a"></v-select>
        <v-row>
          <v-col cols="6"><v-text-field v-model="slot.starttime" label="Hora inicio (HH:MM)"></v-text-field></v-col>
          <v-col cols="6"><v-text-field v-model="slot.endtime" label="Hora fin (HH:MM)"></v-text-field></v-col>
        </v-row>
        <v-row>
          <v-col cols="6">
            <v-select v-model="slot.modality" :items="modalityItems" label="Modalidad"></v-select>
          </v-col>
          <v-col cols="6">
            <v-text-field v-model.number="slot.duration_minutes" label="DuraciÃƒÂ³n (min)" type="number"></v-text-field>
          </v-col>
        </v-row>
        <v-text-field v-model="slot.location" label="UbicaciÃƒÂ³n / sala virtual"></v-text-field>
        <v-switch v-model="slot.active" label="Activo" inset></v-switch>
      </v-card-text>
      <v-card-actions>
        <v-spacer></v-spacer>
        <v-btn text @click="slotDialog = false">Cancelar</v-btn>
        <v-btn color="primary" :loading="slotSaving" @click="saveSlot">Guardar</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <v-snackbar v-model="snack.show" :color="snack.color" timeout="3500" top>
    {{ snack.text }}
  </v-snackbar>
</v-container>
`
});