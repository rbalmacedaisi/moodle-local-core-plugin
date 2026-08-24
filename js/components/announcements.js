/**
 * Admin broadcasts (info / warning) dashboard.
 *
 * Vue 2 component mounted by /local/grupomakro_core/pages/announcements.php.
 * Lets admins:
 *   - list existing broadcasts with recipients and acked counters
 *   - create a new broadcast (type / scope / audience / ack / priority)
 *   - drill into per-career acknowledgement stats for any broadcast
 *   - toggle the active flag on a broadcast
 */
Vue.component('announcements', {
    props: {
        canmanage: { type: Boolean, default: false }
    },
    data() {
        return {
            // List state
            messages: [],
            careers: [],
            groups: [],
            loading: false,

            // Filter for the list
            search: '',
            typeFilter: '',   // '', 'info', 'warning'
            activeFilter: '', // '', '1', '0'

            // Stats dialog state
            statsDialog: false,
            statsMessage: null,
            statsRows: [],
            statsTotals: { total_recipients: 0, total_acked: 0, percent: 0 },
            loadingStats: false,

            // Recipients dialog
            recipientsDialog: false,
            recipientsMessage: null,
            recipients: [],
            loadingRecipients: false,
            recipientSearch: '',

            // Creation form
            createDialog: false,
            saving: false,
            form: {
                title: '',
                messagetext: '',
                messagetype: 'info',
                audience_scope: 'all',
                audience_careerid: 0,
                audience_groupid: 0,
                require_ack: true,
                ack_label: 'He leído y estoy de acuerdo',
                priority: 50,
                starts_at: 0,
                ends_at: 0,
                has_window: false,
                starts_date: '',
                ends_date: '',
            },
            // Errors keyed by field name
            formErrors: {},
        };
    },
    computed: {
        headers() {
            return [
                { text: 'Título',         value: 'title',         sortable: true  },
                { text: 'Tipo',           value: 'messagetype',   sortable: true, align: 'center' },
                { text: 'Destinatarios',  value: 'audience_scope', sortable: true },
                { text: 'Prioridad',      value: 'priority',      sortable: true, align: 'center' },
                { text: 'Mensaje',        value: '_typeLabel',    sortable: false, align: 'center' },
                { text: 'Aceptación',     value: '_ackPercent',   sortable: false, align: 'center' },
                { text: 'Estado',         value: '_activeLabel',  sortable: true, align: 'center' },
                { text: 'Publicado',      value: 'timecreated',   sortable: true  },
                { text: 'Acciones',       value: '_actions',      sortable: false, align: 'center' },
            ];
        },
        recipientHeaders() {
            return [
                { text: 'Estudiante',     value: 'name',       sortable: true  },
                { text: 'Correo',         value: 'email',      sortable: true  },
                { text: 'Carrera',        value: 'careername', sortable: true  },
                { text: 'Estado',         value: '_stateLabel', sortable: false, align: 'center' },
                { text: 'Aceptado',       value: 'timeacknowledged', sortable: true },
            ];
        },
        filteredMessages() {
            const t = (this.search || '').toLowerCase().trim();
            return (this.messages || []).filter(m => {
                if (this.typeFilter && m.messagetype !== this.typeFilter) return false;
                if (this.activeFilter !== '' && String(m.active ? 1 : 0) !== String(this.activeFilter)) return false;
                if (!t) return true;
                return (m.title || '').toLowerCase().indexOf(t) !== -1;
            }).map(m => ({
                ...m,
                _typeLabel: m.messagetype === 'warning' ? 'Advertencia' : 'Informativo',
                _activeLabel: m.active ? 'Activo' : 'Desactivado',
                _ackPercent: m.recipients > 0 ? Math.round((m.acked / m.recipients) * 100) : 0,
                _ackPercentLabel: m.recipients > 0 ? `${m.acked}/${m.recipients} (${Math.round((m.acked / m.recipients) * 100)}%)` : '0/0',
            }));
        },
        scopeLabel() {
            return (m) => {
                if (m.audience_scope === 'all') return 'Todos los estudiantes';
                if (m.audience_scope === 'career') {
                    const c = (this.careers || []).find(x => x.id === m.audience_careerid);
                    return `Carrera: ${c ? c.name : '#' + m.audience_careerid}`;
                }
                if (m.audience_scope === 'group') {
                    const g = (this.groups || []).find(x => x.id === m.audience_groupid);
                    return `Grupo: ${g ? (g.coursename ? g.coursename + ' / ' + g.name : g.name) : '#' + m.audience_groupid}`;
                }
                return m.audience_scope || '—';
            };
        },
    },
    mounted() {
        this.refresh();
    },
    methods: {
        async refresh() {
            this.loading = true;
            try {
                const res = await window.axios.get(ajaxUrl, { params: {
                    action: 'local_grupomakro_admin_list_messages',
                    sesskey,
                }});
                const data = (res.data || {}).data || {};
                this.messages = Array.isArray(data.messages) ? data.messages : [];
                this.careers  = Array.isArray(data.careers)  ? data.careers  : [];
                this.groups   = Array.isArray(data.groups)   ? data.groups   : [];
            } catch (e) {
                console.error('Error loading announcements:', e);
                this.showMessage('error', 'No se pudieron cargar los mensajes.');
            } finally {
                this.loading = false;
            }
        },
        openCreate() {
            this.form = {
                title: '',
                messagetext: '',
                messagetype: 'info',
                audience_scope: 'all',
                audience_careerid: 0,
                audience_groupid: 0,
                require_ack: true,
                ack_label: 'He leído y estoy de acuerdo',
                priority: 50,
                starts_at: 0,
                ends_at: 0,
                has_window: false,
                starts_date: '',
                ends_date: '',
            };
            this.formErrors = {};
            this.createDialog = true;
        },
        duplicateFrom(m) {
            if (!m) return;
            this.form = {
                title: '(Copia) ' + (m.title || ''),
                messagetext: m.messagetext || '',
                messagetype: m.messagetype || 'info',
                audience_scope: m.audience_scope || 'all',
                audience_careerid: m.audience_careerid || 0,
                audience_groupid: m.audience_groupid || 0,
                require_ack: !!m.require_ack,
                ack_label: m.ack_label || 'He leído y estoy de acuerdo',
                priority: m.priority || 50,
                starts_at: m.starts_at || 0,
                ends_at: m.ends_at || 0,
                has_window: !!(m.starts_at || m.ends_at),
                starts_date: m.starts_at ? this.tsToDatetimeLocal(m.starts_at) : '',
                ends_date: m.ends_at ? this.tsToDatetimeLocal(m.ends_at) : '',
            };
            this.formErrors = {};
            this.createDialog = true;
            this.showMessage('info', 'Plantilla cargada. Revisa los datos y publica.');
        },
        tsToDatetimeLocal(ts) {
            if (!ts) return '';
            const d = new Date(ts * 1000);
            const pad = (n) => String(n).padStart(2, '0');
            return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
                 + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
        },
        async save() {
            this.formErrors = {};
            if (!this.form.title || this.form.title.trim() === '') {
                this.formErrors.title = 'El título es obligatorio.';
            }
            if (!this.form.messagetext || this.form.messagetext.trim() === '') {
                this.formErrors.messagetext = 'El mensaje es obligatorio.';
            }
            if (Object.keys(this.formErrors).length > 0) return;

            this.saving = true;
            try {
                const startsAt = this.form.has_window && this.form.starts_date
                    ? Math.floor(new Date(this.form.starts_date).getTime() / 1000)
                    : 0;
                const endsAt = this.form.has_window && this.form.ends_date
                    ? Math.floor(new Date(this.form.ends_date).getTime() / 1000)
                    : 0;

                const payload = {
                    title: this.form.title,
                    messagetext: this.form.messagetext,
                    messagetype: this.form.messagetype,
                    audience_scope: this.form.audience_scope,
                    audience_careerid: this.form.audience_careerid || 0,
                    audience_groupid: this.form.audience_groupid || 0,
                    require_ack: this.form.require_ack,
                    ack_label: this.form.ack_label || '',
                    priority: this.form.priority || 50,
                    starts_at: startsAt,
                    ends_at: endsAt,
                };
                const res = await window.axios.post(ajaxUrl + '?action=local_grupomakro_create_admin_message&sesskey=' + encodeURIComponent(sesskey), {
                    args: payload,
                });
                const body = res.data || {};
                if (body.status === 'success') {
                    this.createDialog = false;
                    const data = body.data || {};
                    this.showMessage('success', `Mensaje creado. ${data.recipients || 0} destinatario(s).`);
                    this.refresh();
                } else {
                    this.showMessage('error', body.message || 'No se pudo crear el mensaje.');
                }
            } catch (e) {
                console.error('Error creating message:', e);
                this.showMessage('error', e.message || 'Error inesperado.');
            } finally {
                this.saving = false;
            }
        },
        async toggleActive(m) {
            const target = !m.active;
            const confirm = await window.Swal.fire({
                title: target ? 'Activar mensaje' : 'Desactivar mensaje',
                text: target
                    ? 'El mensaje volverá a mostrarse a los destinatarios.'
                    : 'El mensaje dejará de mostrarse a los destinatarios hasta que se reactive.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: target ? 'Activar' : 'Desactivar',
                cancelButtonText: 'Cancelar',
            });
            if (!confirm.isConfirmed) return;
            try {
                const res = await window.axios.post(ajaxUrl + '?action=local_grupomakro_set_admin_message_active&sesskey=' + encodeURIComponent(sesskey), {
                    args: { messageid: m.id, active: target },
                });
                const body = res.data || {};
                if (body.status === 'success') {
                    this.showMessage('success', target ? 'Mensaje activado.' : 'Mensaje desactivado.');
                    this.refresh();
                } else {
                    this.showMessage('error', body.message || 'No se pudo cambiar el estado.');
                }
            } catch (e) {
                this.showMessage('error', e.message || 'Error inesperado.');
            }
        },
        async openStats(m) {
            this.statsDialog = true;
            this.statsMessage = m;
            this.statsRows = [];
            this.statsTotals = { total_recipients: 0, total_acked: 0, percent: 0 };
            this.loadingStats = true;
            try {
                const res = await window.axios.get(ajaxUrl, { params: {
                    action: 'local_grupomakro_get_admin_message_stats',
                    sesskey,
                    messageid: m.id,
                }});
                const data = ((res.data || {}).data) || {};
                this.statsRows = Array.isArray(data.stats) ? data.stats : [];
                this.statsTotals = {
                    total_recipients: data.total_recipients || 0,
                    total_acked:      data.total_acked || 0,
                    percent:          data.percent || 0,
                };
            } catch (e) {
                this.showMessage('error', 'No se pudieron cargar las estadísticas.');
            } finally {
                this.loadingStats = false;
            }
        },
        async openRecipients(m) {
            this.recipientsDialog = true;
            this.recipientsMessage = m;
            this.recipients = [];
            this.recipientSearch = '';
            this.loadingRecipients = true;
            try {
                const res = await window.axios.get(ajaxUrl, { params: {
                    action: 'local_grupomakro_list_admin_message_recipients',
                    sesskey,
                    messageid: m.id,
                }});
                const data = ((res.data || {}).data) || {};
                this.recipients = (Array.isArray(data.recipients) ? data.recipients : []).map(r => ({
                    ...r,
                    _stateLabel: r.acked ? 'Aceptado' : (r.timeacknowledged ? 'Visto' : 'Pendiente'),
                }));
            } catch (e) {
                this.showMessage('error', 'No se pudieron cargar los destinatarios.');
            } finally {
                this.loadingRecipients = false;
            }
        },
        // ── Helpers ──────────────────────────────────────────────────────────
        formatDate(ts) {
            if (!ts) return '—';
            const d = new Date(ts * 1000);
            return d.toLocaleDateString('es-PA', { day: '2-digit', month: '2-digit', year: 'numeric' }) +
                ' ' + d.toLocaleTimeString('es-PA', { hour: '2-digit', minute: '2-digit' });
        },
        typeColor(t) { return t === 'warning' ? 'orange darken-2' : 'green darken-2'; },
        priorityColor(p) {
            if (p >= 80) return 'red darken-2';
            if (p >= 50) return 'orange darken-2';
            if (p >= 20) return 'amber darken-2';
            return 'grey darken-1';
        },
        priorityLabel(p) {
            if (p >= 80) return 'Crítica';
            if (p >= 50) return 'Alta';
            if (p >= 20) return 'Media';
            return 'Baja';
        },
        showMessage(type, text) {
            window.Swal.fire({ icon: type, text, toast: true, position: 'top-end',
                showConfirmButton: false, timer: 4000 });
        },
    },
    template: `
    <div class="pa-4">
      <v-card class="mb-4">
        <v-card-title class="d-flex align-center">
          <v-icon left color="primary">mdi-bullhorn-outline</v-icon>
          Mensajes a estudiantes
          <v-spacer/>
          <v-text-field
            v-model="search"
            prepend-inner-icon="mdi-magnify"
            label="Buscar por título"
            single-line
            hide-details
            dense
            outlined
            class="mr-3"
            style="max-width:280px"
          />
          <v-select
            v-model="typeFilter"
            :items="[{text:'Todos', value:''}, {text:'Informativos', value:'info'}, {text:'Advertencias', value:'warning'}]"
            label="Tipo"
            hide-details dense outlined
            class="mr-3" style="max-width:160px"
          />
          <v-select
            v-model="activeFilter"
            :items="[{text:'Todos', value:''}, {text:'Activos', value:'1'}, {text:'Desactivados', value:'0'}]"
            label="Estado"
            hide-details dense outlined
            class="mr-3" style="max-width:160px"
          />
          <v-btn v-if="canmanage" color="primary" dark depressed @click="openCreate">
            <v-icon left>mdi-plus</v-icon>
            Nuevo mensaje
          </v-btn>
        </v-card-title>
        <v-card-text>
          <v-data-table
            :headers="headers"
            :items="filteredMessages"
            :loading="loading"
            :items-per-page="15"
            class="elevation-1"
            dense
          >
            <template v-slot:item.messagetype="{ item }">
              <v-chip small :color="typeColor(item.messagetype)" dark class="text-uppercase font-weight-bold">
                {{ item._typeLabel }}
              </v-chip>
            </template>
            <template v-slot:item.priority="{ item }">
              <v-chip small :color="priorityColor(item.priority)" dark>
                {{ item.priority }} · {{ priorityLabel(item.priority) }}
              </v-chip>
            </template>
            <template v-slot:item.audience_scope="{ item }">
              <span>{{ scopeLabel(item) }}</span>
              <small class="d-block grey--text text--darken-1" v-if="item.recipients > 0">
                {{ item.recipients }} destinatario(s)
              </small>
            </template>
            <template v-slot:item._ackPercent="{ item }">
              <div class="d-flex align-center" style="min-width:120px">
                <v-progress-linear
                  :value="item._ackPercent"
                  :color="item._ackPercent >= 80 ? 'green darken-2' : (item._ackPercent >= 40 ? 'orange' : 'red darken-2')"
                  height="8"
                  rounded
                  class="flex-grow-1 mr-2"
                />
                <span class="text-caption">{{ item._ackPercentLabel }}</span>
              </div>
            </template>
            <template v-slot:item.timecreated="{ item }">
              {{ formatDate(item.timecreated) }}
            </template>
            <template v-slot:item._activeLabel="{ item }">
              <v-chip small :color="item.active ? 'green' : 'grey'" dark>
                {{ item._activeLabel }}
              </v-chip>
            </template>
            <template v-slot:item._actions="{ item }">
              <v-btn icon small color="info" title="Estadísticas por carrera" @click="openStats(item)">
                <v-icon small>mdi-chart-bar</v-icon>
              </v-btn>
              <v-btn icon small color="primary" title="Destinatarios" @click="openRecipients(item)">
                <v-icon small>mdi-account-multiple-outline</v-icon>
              </v-btn>
              <v-btn v-if="canmanage" icon small color="deep-purple accent-2"
                     title="Duplicar como plantilla" @click="duplicateFrom(item)">
                <v-icon small>mdi-content-copy</v-icon>
              </v-btn>
              <v-btn v-if="canmanage" icon small :color="item.active ? 'grey' : 'green darken-2'"
                     :title="item.active ? 'Desactivar' : 'Activar'" @click="toggleActive(item)">
                <v-icon small>{{ item.active ? 'mdi-eye-off-outline' : 'mdi-eye-outline' }}</v-icon>
              </v-btn>
            </template>
          </v-data-table>
        </v-card-text>
      </v-card>

      <!-- ── Stats dialog ───────────────────────────────────────────────── -->
      <v-dialog v-model="statsDialog" max-width="720" scrollable>
        <v-card>
          <v-card-title class="text-h6">
            <v-icon left color="primary">mdi-chart-bar</v-icon>
            Acuse de recibo por carrera
            <small class="ml-2 grey--text" v-if="statsMessage">
              — {{ statsMessage.title }}
            </small>
          </v-card-title>
          <v-divider/>
          <v-card-text>
            <div class="d-flex align-center mb-4" v-if="!loadingStats">
              <div class="flex-grow-1">
                <div class="text-caption grey--text">Aceptación global</div>
                <div class="text-h4">{{ statsTotals.percent }}%</div>
                <div class="text-caption">
                  {{ statsTotals.total_acked }} de {{ statsTotals.total_recipients }} destinatarios
                </div>
              </div>
              <v-progress-circular
                :value="statsTotals.percent"
                :size="80"
                :width="8"
                :color="statsTotals.percent >= 80 ? 'green darken-2' : (statsTotals.percent >= 40 ? 'orange' : 'red darken-2')"
              >
                {{ statsTotals.percent }}%
              </v-progress-circular>
            </div>
            <v-skeleton-loader v-if="loadingStats" type="article, list-item-three-line, list-item-three-line"/>
            <v-data-table
              v-else
              :headers="[
                { text: 'Carrera', value: 'careername' },
                { text: 'Total',   value: 'total',       align: 'right' },
                { text: 'Aceptaron', value: 'acked',     align: 'right' },
                { text: 'Pendientes', value: 'pending',   align: 'right' },
                { text: '%',        value: 'percent',     align: 'right' },
              ]"
              :items="statsRows"
              :items-per-page="-1"
              dense
              hide-default-footer
            >
              <template v-slot:item.percent="{ item }">
                <v-chip small :color="item.percent >= 80 ? 'green' : (item.percent >= 40 ? 'orange' : 'red darken-2')" dark>
                  {{ item.percent }}%
                </v-chip>
              </template>
            </v-data-table>
          </v-card-text>
          <v-divider/>
          <v-card-actions>
            <v-spacer/>
            <v-btn color="primary" text @click="statsDialog = false">Cerrar</v-btn>
          </v-card-actions>
        </v-card>
      </v-dialog>

      <!-- ── Recipients dialog ──────────────────────────────────────────── -->
      <v-dialog v-model="recipientsDialog" max-width="780" scrollable>
        <v-card>
          <v-card-title class="text-h6">
            <v-icon left color="primary">mdi-account-multiple-outline</v-icon>
            Destinatarios
            <small class="ml-2 grey--text" v-if="recipientsMessage">
              — {{ recipientsMessage.title }}
            </small>
          </v-card-title>
          <v-divider/>
          <v-card-text>
            <v-text-field
              v-model="recipientSearch"
              append-icon="mdi-magnify"
              label="Filtrar por nombre"
              hide-details dense outlined
              class="mb-3"
            />
            <v-data-table
              :headers="recipientHeaders"
              :items="(recipients || []).filter(r => !recipientSearch || (r.name || '').toLowerCase().indexOf(recipientSearch.toLowerCase()) !== -1)"
              :loading="loadingRecipients"
              :items-per-page="15"
              dense
            >
              <template v-slot:item._stateLabel="{ item }">
                <v-chip small :color="item.acked ? 'green darken-2' : (item.timeacknowledged ? 'blue-grey' : 'red darken-2')" dark>
                  {{ item._stateLabel }}
                </v-chip>
              </template>
              <template v-slot:item.timeacknowledged="{ item }">
                {{ item.timeacknowledged ? formatDate(item.timeacknowledged) : '—' }}
              </template>
            </v-data-table>
          </v-card-text>
          <v-divider/>
          <v-card-actions>
            <v-spacer/>
            <v-btn color="primary" text @click="recipientsDialog = false">Cerrar</v-btn>
          </v-card-actions>
        </v-card>
      </v-dialog>

      <!-- ── Create dialog ─────────────────────────────────────────────── -->
      <v-dialog v-model="createDialog" max-width="720" persistent scrollable>
        <v-card>
          <v-card-title class="text-h6">
            <v-icon left color="primary">mdi-plus-circle-outline</v-icon>
            Nuevo mensaje
          </v-card-title>
          <v-divider/>
          <v-card-text>
            <v-text-field
              v-model="form.title"
              label="Título"
              :error-messages="formErrors.title ? [formErrors.title] : []"
              outlined dense
            />
            <v-textarea
              v-model="form.messagetext"
              label="Mensaje"
              :error-messages="formErrors.messagetext ? [formErrors.messagetext] : []"
              outlined
              auto-grow rows="3"
            />

            <v-row dense>
              <v-col cols="12" sm="6">
                <v-select
                  v-model="form.messagetype"
                  :items="[{text:'Informativo (verde)', value:'info'}, {text:'Advertencia (naranja)', value:'warning'}]"
                  label="Tipo de mensaje"
                  outlined dense
                />
              </v-col>
              <v-col cols="12" sm="6">
                <v-select
                  v-model="form.audience_scope"
                  :items="[{text:'Todos los estudiantes', value:'all'}, {text:'Por carrera', value:'career'}, {text:'Por grupo', value:'group'}]"
                  label="Destinatarios"
                  outlined dense
                />
              </v-col>
            </v-row>

            <v-row v-if="form.audience_scope === 'career'" dense>
              <v-col cols="12">
                <v-select
                  v-model="form.audience_careerid"
                  :items="careers.map(c => ({text: c.name, value: c.id}))"
                  label="Carrera"
                  outlined dense
                />
              </v-col>
            </v-row>

            <v-row v-if="form.audience_scope === 'group'" dense>
              <v-col cols="12">
                <v-select
                  v-model="form.audience_groupid"
                  :items="groups.map(g => ({text: (g.coursename ? g.coursename + ' / ' : '') + g.name, value: g.id}))"
                  label="Grupo"
                  outlined dense
                />
              </v-col>
            </v-row>

            <v-row dense>
              <v-col cols="12" sm="6">
                <v-checkbox
                  v-model="form.require_ack"
                  label="Mostrar check de verificación de lectura"
                  hide-details dense
                />
              </v-col>
              <v-col cols="12" sm="6">
                <v-text-field
                  v-model="form.ack_label"
                  label="Texto del check"
                  :disabled="!form.require_ack"
                  outlined dense
                />
              </v-col>
            </v-row>

            <v-row dense>
              <v-col cols="12" sm="6">
                <v-text-field
                  v-model.number="form.priority"
                  type="number"
                  min="1" max="100"
                  label="Prioridad"
                  hint="Mayor = toma precedencia sobre las alertas de inasistencias (50 por defecto)."
                  persistent-hint
                  outlined dense
                />
              </v-col>
              <v-col cols="12" sm="6">
                <v-checkbox
                  v-model="form.has_window"
                  label="Ventana de publicación (opcional)"
                  hide-details dense
                />
              </v-col>
            </v-row>

            <v-row v-if="form.has_window" dense>
              <v-col cols="12" sm="6">
                <v-text-field
                  v-model="form.starts_date"
                  type="datetime-local"
                  label="Visible desde"
                  outlined dense
                />
              </v-col>
              <v-col cols="12" sm="6">
                <v-text-field
                  v-model="form.ends_date"
                  type="datetime-local"
                  label="Visible hasta"
                  outlined dense
                />
              </v-col>
            </v-row>
          </v-card-text>
          <v-divider/>
          <v-card-actions>
            <v-spacer/>
            <v-btn text @click="createDialog = false" :disabled="saving">Cancelar</v-btn>
            <v-btn color="primary" depressed :loading="saving" @click="save">
              Publicar
            </v-btn>
          </v-card-actions>
        </v-card>
      </v-dialog>
    </div>
    `,
});
