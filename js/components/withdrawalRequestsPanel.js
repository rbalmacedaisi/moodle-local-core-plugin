/**
 * Administrative inbox for RET-01 withdrawal requests (20261001080).
 *
 * Mounted by pages/withdrawal_requests.php. Holds only functions; the
 * surrounding v-app, vue + vuetify + axios + sweetalert2 scripts are
 * loaded by the PHP page.
 *
 * Two data paths:
 *   - Student-side: vue.$store.dispatch('wdr/fetchMyRequests') and
 *     vue.$store.dispatch('wdr/downloadPdf') (defined in store/wdr.js).
 *   - Admin-side (inbox): the page sends POSTs directly to ajax.php with
 *     action=local_grupomakro_wdr_admin_{list,update_status,upload_scanned,
 *     get_scanned,download_pdf} for the same backing WS. This file only
 *     does the inbox UI; the create_request wizard (LXP) is in pages/
 *     solicitudes-retiro.vue and consumes the store actions.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

Vue.component('withdrawal-requests-panel', {
    // type: [Boolean, Number] para tolerar el render del prop como 1/0
    // cuando el PHP emite el booleano crudo dentro del heredoc
    // (PHP true se imprime como "1"). announcements.js tiene el mismo
    // problema pero nunca lo reporto porque nadie forzo el hard reload.
    props: { canmanage: { type: [Boolean, Number], required: true } },
    data() {
        return {
            requests: [],
            loading: false,
            submitting: false,
            filter: {
                status: '',
                search: '',
                from: '',
                to: '',
            },
            statuses: [
                { code: '', label: 'Todos' },
                { code: 'solicitada', label: 'Solicitada' },
                { code: 'pendiente_firma_presencial', label: 'Pendiente firma presencial' },
                { code: 'firmada_digital', label: 'Firmada digitalmente' },
                { code: 'recibida_direccion_academica', label: 'Recibida Dir. Académica' },
                { code: 'recibida_direccion_administrativa', label: 'Recibida Dir. Administrativa' },
                { code: 'procesada', label: 'Procesada' },
                { code: 'rechazada', label: 'Rechazada' },
                { code: 'cancelada', label: 'Cancelada' },
            ],
            drawer: { open: false, row: null },
            tab: 0,
            rejectDialog: { open: false, row: null, reason: '' },
            uploadDialog: { open: false, row: null, file: null, error: '' },
            scannedsrc: null,
        };
    },
    created() { this.loadList(); },
    methods: {
        statusColor(s) {
            return {
                solicitada: 'grey',
                pendiente_firma_presencial: 'orange',
                firmada_digital: 'teal',
                recibida_direccion_academica: 'indigo',
                recibida_direccion_administrativa: 'blue',
                procesada: 'success',
                rechazada: 'error',
                cancelada: 'error',
            }[s] || 'grey';
        },
        formatDate(ts) {
            if (!ts || ts <= 0) return '—';
            try { return new Date(ts * 1000).toLocaleString(); }
            catch (e) { return '—'; }
        },
        buildWsUrl(name) {
            return wwwroot + '/local/grupomakro_core/ajax.php?action=local_grupomakro_' + name;
        },
        callAjax(name, body = {}) {
            return axios.post(this.buildWsUrl(name.replace(/_/g, '_')), Object.assign({}, body, { sesskey }));
        },
        async loadList() {
            this.loading = true;
            try {
                // El date input envia string vacio cuando no hay filtro, pero
                // admin_list::execute_parameters() declara from/to como
                // PARAM_INT y el framework de WS de Moodle no convierte '' a 0
                // automaticamente (solo aplica VALUE_DEFAULT cuando la key esta
                // ausente). Coerce local a 0 antes de enviar para mantener
                // v-model="filter.from" con string vacio en la UI.
                const filter = Object.assign({}, this.filter, {
                    from: this.filter.from === '' || this.filter.from == null ? 0 : this.filter.from,
                    to:   this.filter.to   === '' || this.filter.to   == null ? 0 : this.filter.to,
                });
                const { data } = await this.callAjax('wdr_admin_list', filter);
                if (data && data.status === 'success') {
                    this.requests = data.data || [];
                } else {
                    this.notify(data && data.message ? data.message : 'No se pudo cargar la bandeja.', 'error');
                    this.requests = [];
                }
            } catch (e) {
                this.notify('No se pudo conectar con el servidor.', 'error');
                this.requests = [];
            } finally {
                this.loading = false;
            }
        },
        openDrawer(row) {
            this.drawer = { open: true, row: row };
            this.tab = 0;
            this.scannedsrc = null;
            this.loadDetail(row.id);
        },
        async loadDetail(id) {
            try {
                const { data } = await this.callAjax('wdr_get_request_detail', { id });
                if (data && data.status === 'success' && data.data) {
                    this.drawer.row = Object.assign({}, this.drawer.row, data.data);
                }
            } catch (e) {
                this.notify('No se pudo cargar el detalle.', 'error');
            }
        },
        async doAction(row, action, extra = {}) {
            if (!this.canmanage) return;
            this.submitting = true;
            try {
                const { data } = await this.callAjax('wdr_admin_update_status',
                    Object.assign({ id: row.id, action }, extra));
                if (data && data.status === 'success') {
                    this.notify('Estado actualizado.', 'success');
                    this.drawer.row.status = data.data.status;
                    row.status = data.data.status;
                } else {
                    this.notify(data && data.message ? data.message : 'No se pudo actualizar.', 'error');
                }
            } catch (e) {
                this.notify('No se pudo conectar con el servidor.', 'error');
            } finally {
                this.submitting = false;
                this.loadList();
            }
        },
        async downloadPdf(row) {
            const target = row || this.drawer.row;
            if (!target) return;
            try {
                const { data } = await this.callAjax('wdr_download_pdf', { id: target.id });
                if (data && data.status === 'success' && data.data && data.data.contentbase64) {
                    const blob = new Blob(
                        [Uint8Array.from(atob(data.data.contentbase64), c => c.charCodeAt(0))],
                        { type: 'application/pdf' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = data.data.filename || (data.data.request_number + '.pdf');
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                } else {
                    this.notify('No se pudo descargar el PDF.', 'error');
                }
            } catch (e) {
                this.notify('No se pudo conectar con el servidor.', 'error');
            }
        },
        async fetchScanned(row) {
            if (!row || !row.has_scanned) return;
            try {
                const { data } = await this.callAjax('wdr_get_scanned', { id: row.id });
                if (data && data.status === 'success' && data.data && data.data.available) {
                    this.scannedsrc = 'data:' + data.data.mimetype + ';base64,' + data.data.contentbase64;
                } else {
                    this.scannedsrc = null;
                }
            } catch (e) {
                this.scannedsrc = null;
            }
        },
        openRejectDialog(row) {
            this.rejectDialog = { open: true, row, reason: '' };
        },
        async confirmReject() {
            const r = this.rejectDialog.reason.trim();
            if (!r) return this.notify('Indique el motivo del rechazo.', 'warning');
            await this.doAction(this.rejectDialog.row, 'reject', { reject_reason: r });
            this.rejectDialog.open = false;
        },
        openUpload(row) {
            this.uploadDialog = { open: true, row, file: null, error: '' };
        },
        pickFile(ev) {
            const f = (ev && ev.target && ev.target.files && ev.target.files[0]) || null;
            this.uploadDialog.file = f;
            this.uploadDialog.error = '';
            if (!f) return;
            if (f.size > 12 * 1024 * 1024) {
                this.uploadDialog.error = 'El archivo excede 12 MB.';
                this.uploadDialog.file = null;
                return;
            }
            if (f.type !== 'application/pdf' && !/\.pdf$/i.test(f.name)) {
                this.uploadDialog.error = 'Solo se aceptan archivos PDF.';
                this.uploadDialog.file = null;
                return;
            }
        },
        async uploadSigned() {
            const f = this.uploadDialog.file;
            if (!f) return;
            this.submitting = true;
            try {
                const buf = await f.arrayBuffer();
                const b64 = btoa(String.fromCharCode(...new Uint8Array(buf)));
                const { data } = await this.callAjax('wdr_admin_upload_scanned', {
                    id: this.uploadDialog.row.id,
                    filename: f.name,
                    contentbase64: b64,
                });
                if (data && data.status === 'success') {
                    this.notify('Copia firmada archivada.', 'success');
                    this.uploadDialog.open = false;
                    this.loadList();
                    this.loadDetail(this.uploadDialog.row.id);
                } else {
                    this.notify(data && data.message ? data.message : 'No se pudo subir.', 'error');
                }
            } catch (e) {
                this.notify('No se pudo leer el archivo.', 'error');
            } finally {
                this.submitting = false;
            }
        },
        notify(msg, kind) {
            if (window.Swal && Swal.fire) {
                Swal.fire({ toast: true, position: 'top-end', showConfirmButton: false,
                    timer: 3000, icon: kind || 'info', title: msg });
            } else if (kind === 'error') {
                console.error(msg);
            }
        },
    },
    template: `
<v-container>
  <v-card outlined>
    <v-card-title>
      <div>
        <div class="text-h6">{{ 'Bandeja RET-01' }}</div>
        <div class="text-caption grey--text">{{ 'Director Académico · Secretaría Académica · Director General' }}</div>
      </div>
      <v-spacer></v-spacer>
      <v-btn color="primary" text @click="loadList"><v-icon left>mdi-refresh</v-icon>{{ 'Refrescar' }}</v-btn>
    </v-card-title>
    <v-divider></v-divider>
    <v-card-text>
      <v-row dense>
        <v-col cols="12" sm="6" md="3">
          <v-select v-model="filter.status" :items="statuses" item-text="label" item-value="code"
                    :label="'Estado'" outlined dense clearable></v-select>
        </v-col>
        <v-col cols="12" sm="6" md="4">
          <v-text-field v-model="filter.search"
            :label="'Buscar (solicitud, nombre, cédula)'"
            outlined dense clearable prepend-inner-icon="mdi-magnify"></v-text-field>
        </v-col>
        <v-col cols="6" sm="3" md="2.5">
          <v-text-field v-model="filter.from" type="date" :label="'Desde'" outlined dense hide-details></v-text-field>
        </v-col>
        <v-col cols="6" sm="3" md="2.5">
          <v-text-field v-model="filter.to" type="date" :label="'Hasta'" outlined dense hide-details></v-text-field>
        </v-col>
      </v-row>

      <v-data-table
        :headers="[
          { text: 'Solicitud', value: 'request_number' },
          { text: 'Estudiante', value: 'fullname' },
          { text: 'Cédula', value: 'id_number' },
          { text: 'Carrera', value: 'program' },
          { text: 'Motivo', value: 'reason' },
          { text: 'Estado', value: 'status' },
          { text: 'Creada', value: 'timecreated' },
          { text: 'Acciones', value: 'actions', sortable: false }
        ]"
        :items="requests"
        :loading="loading"
        :items-per-page="10"
        class="elevation-0">
        <template v-slot:item.status="{ item }">
          <v-chip small :color="statusColor(item.status)" dark>{{ item.status }}</v-chip>
        </template>
        <template v-slot:item.timecreated="{ item }">{{ formatDate(item.timecreated) }}</template>
        <template v-slot:item.actions="{ item }">
          <v-btn icon small color="primary" :title="'Ver detalle'" @click="openDrawer(item)">
            <v-icon small>mdi-eye</v-icon>
          </v-btn>
          <v-btn icon small color="success" :title="'Descargar RET-01'" @click="downloadPdf(item)">
            <v-icon small>mdi-download</v-icon>
          </v-btn>
        </template>
        <template v-slot:no-data>
          <div class="py-6 text-center">{{ 'Sin solicitudes todavía.' }}</div>
        </template>
      </v-data-table>
    </v-card-text>
  </v-card>

  <!-- Drawer with detail + actions -->
  <v-navigation-drawer v-if="drawer.open" v-model="drawer.open" temporary right width="540">
    <v-toolbar flat>
      <v-toolbar-title>{{ drawer.row ? drawer.row.request_number : '' }}</v-toolbar-title>
      <v-spacer></v-spacer>
      <v-btn icon @click="drawer.open = false"><v-icon>mdi-close</v-icon></v-btn>
    </v-toolbar>
    <v-tabs v-model="tab" grow>
      <v-tab>Resumen</v-tab>
      <v-tab>Recibido</v-tab>
      <v-tab>Archivo firmado</v-tab>
      <v-tab>Historial</v-tab>
    </v-tabs>
    <v-tabs-items v-model="tab">
      <v-tab-item>
        <v-list dense>
          <v-list-item v-for="(v,k) in {
            Estudiante: drawer.row && drawer.row.fullname,
            Cédula: drawer.row && drawer.row.id_number,
            'Carrera/Programa': drawer.row && drawer.row.program,
            Motivo: drawer.row && drawer.row.reason,
            'Opción de pago': drawer.row && drawer.row.payment_option,
            'Detalle de pago': drawer.row && drawer.row.payment_option_detail,
            'Período actual': drawer.row && drawer.row.current_period,
            'Último período': drawer.row && drawer.row.last_period,
            Estado: drawer.row && drawer.row.status,
            Creada: drawer.row && formatDate(drawer.row.timecreated)
          }" :key="k">
            <v-list-item-content>
              <v-list-item-title>{{ k }}</v-list-item-title>
              <v-list-item-subtitle>{{ v || '—' }}</v-list-item-subtitle>
            </v-list-item-content>
          </v-list-item>
        </v-list>
      </v-tab-item>
      <v-tab-item>
        <v-card-text>
          <v-btn block color="primary" :disabled="!canmanage || submitting"
                 @click="doAction(drawer.row, 'record_da')">
            {{ 'Registrar recibido Dir. Académica' }}
          </v-btn>
          <v-divider class="my-3"></v-divider>
          <v-btn block color="primary" :disabled="!canmanage || submitting"
                 @click="doAction(drawer.row, 'record_admin')">
            {{ 'Registrar recibido Dir. Administrativa' }}
          </v-btn>
          <v-divider class="my-3"></v-divider>
          <v-btn block color="success" :disabled="!canmanage || submitting"
                 @click="doAction(drawer.row, 'process')">
            {{ 'Marcar procesada' }}
          </v-btn>
          <v-divider class="my-3"></v-divider>
          <v-btn block color="error" outlined :disabled="!canmanage || submitting"
                 @click="openRejectDialog(drawer.row)">
            {{ 'Rechazar' }}
          </v-btn>
        </v-card-text>
      </v-tab-item>
      <v-tab-item>
        <v-card-text>
          <v-btn block color="primary" :disabled="!canmanage" @click="openUpload(drawer.row)">
            <v-icon left>mdi-upload</v-icon>
            {{ 'Subir PDF firmado' }}
          </v-btn>
          <v-divider class="my-3"></v-divider>
          <v-btn block color="info" outlined :disabled="!drawer.row || !drawer.row.has_scanned"
                 @click="fetchScanned(drawer.row)">
            <v-icon left>mdi-eye</v-icon>
            {{ 'Ver copia firmada' }}
          </v-btn>
          <iframe v-if="scannedsrc" :src="scannedsrc" style="width:100%;height:60vh;margin-top:8px" frameborder="0"></iframe>
        </v-card-text>
      </v-tab-item>
      <v-tab-item>
        <v-card-text>
          <v-timeline dense>
            <v-timeline-item v-if="drawer.row" color="primary" small>
              <strong>Solicitada</strong>
              <div class="text-caption">{{ formatDate(drawer.row.timecreated) }}</div>
            </v-timeline-item>
            <v-timeline-item v-if="drawer.row && drawer.row.received_da_at" color="indigo" small>
              <strong>Recibida por Dir. Académica</strong>
              <div class="text-caption">{{ formatDate(drawer.row.received_da_at) }}</div>
            </v-timeline-item>
            <v-timeline-item v-if="drawer.row && drawer.row.received_admin_at" color="blue" small>
              <strong>Recibida por Dir. Administrativa</strong>
              <div class="text-caption">{{ formatDate(drawer.row.received_admin_at) }}</div>
            </v-timeline-item>
            <v-timeline-item v-if="drawer.row && drawer.row.status === 'firmada_digital'" color="teal" small>
              <strong>Subida copia firmada</strong>
            </v-timeline-item>
            <v-timeline-item v-if="drawer.row && drawer.row.status === 'procesada'" color="success" small>
              <strong>Procesada</strong>
            </v-timeline-item>
            <v-timeline-item v-if="drawer.row && drawer.row.status === 'rechazada'" color="error" small>
              <strong>Rechazada</strong>
              <div class="text-caption" v-if="drawer.row.rejection_reason">{{ drawer.row.rejection_reason }}</div>
            </v-timeline-item>
          </v-timeline>
        </v-card-text>
      </v-tab-item>
    </v-tabs-items>
  </v-navigation-drawer>

  <!-- Reject dialog -->
  <v-dialog v-model="rejectDialog.open" max-width="500">
    <v-card>
      <v-card-title>{{ 'Rechazar' }}</v-card-title>
      <v-card-text>
        <v-textarea v-model="rejectDialog.reason" :label="'Motivo del rechazo'"
                    rows="3" outlined dense></v-textarea>
      </v-card-text>
      <v-card-actions>
        <v-spacer></v-spacer>
        <v-btn text @click="rejectDialog.open = false">Cancelar</v-btn>
        <v-btn color="error" :loading="submitting" @click="confirmReject">Rechazar</v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

  <!-- Upload dialog -->
  <v-dialog v-model="uploadDialog.open" max-width="560">
    <v-card>
      <v-card-title>{{ 'Subir PDF firmado' }}</v-card-title>
      <v-card-text>
        <p class="text-caption">{{ 'PDF firmado, máx. 12 MB. Solo se acepta un archivo por solicitud.' }}</p>
        <v-file-input v-model="uploadDialog.file" accept="application/pdf" :label="'PDF'" outlined dense
                      @change="pickFile"></v-file-input>
        <v-alert v-if="uploadDialog.error" type="error" dense outlined>{{ uploadDialog.error }}</v-alert>
      </v-card-text>
      <v-card-actions>
        <v-spacer></v-spacer>
        <v-btn text @click="uploadDialog.open = false">Cancelar</v-btn>
        <v-btn color="primary" :loading="submitting" :disabled="!uploadDialog.file" @click="uploadSigned">
          Subir
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>
</v-container>
`,
});
