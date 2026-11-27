/**
 * Bandeja de retiros (20261001135): flujo de gestion.
 *
 *   1. Recepcion        Bienestar toma la solicitud (portal) o la registra (otro canal).
 *   2. Gestion          Bienestar contacta, registra cada gestion, programa seguimientos,
 *                       escala a otras areas e informa la deuda. Decide: se queda o se va.
 *   3. Retiro formal    Secretaria Academica / Registros: checklist RET-01, sin deuda,
 *                       y "Ejecutar retiro formal" (Odoo + Moodle).
 *   4. Cierre           Retirado, retenido o anulado.
 *
 * La pantalla muestra siempre UNA cosa que hacer ("Que sigue") segun la etapa y el rol.
 * Datos: ajax.php -> local_grupomakro_wdr_flow_* (classes/external/wdr/flow.php).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

Vue.component('withdrawal-requests-panel', {
    props: { initialid: { type: [Number, String], default: 0 } },
    data() {
        return {
            cat: window.WDR_CATALOGS || { channels: [], reasons: [], logtypes: [], areas: [], checklist: [] },
            stage: '',
            search: '',
            rows: [],
            counts: {},
            perms: { canmanage: false, canformal: false, canview: false },
            loading: false,
            busy: false,
            detail: null,
            detailOpen: false,
            balance: null,
            balanceLoading: false,
            // Dialogos
            dlg: { activity: false, escalate: false, retained: false, formal: false, back: false, voidit: false,
                payment: false, respond: false, create: false, execute: false, upload: false },
            form: { type: 'llamada', text: '', followDate: '', followTime: '09:00', area: '', assignee: null, due: '',
                escalationId: 0, file: null },
            staff: [],
            staffLoading: false,
            create: { query: '', results: [], searching: false, student: null, channel: 'presencial', reason: '', observations: '' },
            execResult: null,
        };
    },
    computed: {
        tabs() {
            const t = [];
            if (this.perms.canview) {
                t.push({ key: 'nuevas', label: 'Nuevas', icon: 'mdi-inbox-arrow-down' });
                if (this.perms.canmanage) {
                    t.push({ key: 'mias', label: 'Mis casos', icon: 'mdi-account-heart' });
                }
                t.push({ key: 'gestion', label: 'En gestión', icon: 'mdi-account-voice' });
                t.push({ key: 'formal', label: 'Retiro formal', icon: 'mdi-file-sign' });
                t.push({ key: 'cerradas', label: 'Cerradas', icon: 'mdi-archive-outline' });
            }
            if (!this.perms.canview || (this.counts.escalamientos || 0) > 0) {
                t.push({ key: 'escalamientos', label: 'Escalados a mí', icon: 'mdi-arrow-up-bold-circle-outline' });
            }
            return t;
        },
        tabIndex: {
            get() { return Math.max(0, this.tabs.findIndex(t => t.key === this.stage)); },
            set(i) { if (this.tabs[i]) { this.stage = this.tabs[i].key; this.load(); } },
        },
        headers() {
            return [
                { text: 'Estudiante', value: 'fullname' },
                { text: 'Etapa', value: 'stage_label', width: 170 },
                { text: 'Qué sigue', value: 'next_step' },
                { text: 'Responsable', value: 'assigned_name', width: 160 },
                { text: 'Recibida', value: 'timecreated', width: 110 },
            ];
        },
        d() { return this.detail || {}; },
        stepNumber() {
            return { solicitada: 1, en_gestion: 2, retiro_formal: 3, procesada: 4, retenida: 4, rechazada: 4, cancelada: 4 }[this.d.stage] || 1;
        },
        isClosed() { return ['procesada', 'retenida', 'rechazada', 'cancelada'].includes(this.d.stage); },
        myOpenEscalations() {
            return (this.d.log || []).filter(l => l.type === 'escalamiento' && !l.resolved_at && l.assignee === this.d.me);
        },
        openEscalations() {
            return (this.d.log || []).filter(l => l.type === 'escalamiento' && !l.resolved_at);
        },
        checklistNext() {
            return (this.d.checklist || []).find(c => !c.done) || null;
        },
        checklistDone() {
            return (this.d.checklist || []).length > 0 && (this.d.checklist || []).every(c => c.done);
        },
        hasDebt() { return this.balance && this.balance.ok && this.balance.hasBalance; },
        noDebt() { return this.balance && this.balance.ok && !this.balance.hasBalance; },
        canExecute() { return this.checklistDone && this.noDebt; },
        suggestedStaff() { return this.staff.filter(s => s.suggested); },
        staffItems() {
            const sug = this.staff.filter(s => s.suggested);
            const rest = this.staff.filter(s => !s.suggested);
            const items = [];
            if (sug.length) { items.push({ header: 'Sugeridos para el área' }); items.push(...sug); }
            if (rest.length) { items.push({ header: 'Resto del personal' }); items.push(...rest); }
            return items;
        },
        followQuick() {
            const mk = (days) => { const x = new Date(); x.setDate(x.getDate() + days); return x.toISOString().slice(0, 10); };
            return [{ t: 'Mañana', v: mk(1) }, { t: 'En 3 días', v: mk(3) }, { t: 'En 1 semana', v: mk(7) }];
        },
        actionsList() {
            const a = this.d.moodle_actions || {};
            const out = [];
            if (a.odoo) out.push({ ok: a.odoo.ok, text: 'Odoo: contrato cancelado' + (a.odoo.contract ? ' (' + a.odoo.contract + ')' : '') });
            if (a.courses) out.push({ ok: a.courses.ok, text: 'Asignaturas retiradas: ' + ((a.courses.done || []).join(', ') || 'ninguna en curso')
                + ((a.courses.errors || []).length ? ' · Errores: ' + a.courses.errors.join('; ') : '') });
            if (a.pending) out.push({ ok: a.pending.ok, text: 'Solicitudes de módulo por pagar anuladas: ' + (a.pending.cancelled || 0) });
            if (a.status) out.push({ ok: a.status.ok, text: 'Estado del estudiante: Retirado' });
            if (a.suspend) out.push({ ok: a.suspend.ok, text: 'Cuenta suspendida (notas e historial conservados)' });
            return out;
        },
    },
    created() {
        this.load().then(() => {
            const id = parseInt(this.initialid, 10);
            if (id > 0) this.open({ id });
        });
    },
    methods: {
        // ── Datos ─────────────────────────────────────────────────────────
        async call(name, body = {}) {
            const url = wwwroot + '/local/grupomakro_core/ajax.php?action=local_grupomakro_' + name;
            const { data } = await axios.post(url, Object.assign({}, body, { sesskey }));
            if (!data || data.status !== 'success') {
                throw new Error((data && data.message) ? data.message.replace(/^.*?\[|\]$/g, '') : 'Error del servidor');
            }
            return data.data;
        },
        async load() {
            this.loading = true;
            try {
                const r = await this.call('wdr_flow_list', { stage: this.stage, search: this.search || '' });
                this.rows = r.rows || [];
                this.counts = r.counts || {};
                this.perms = { canmanage: !!r.canmanage, canformal: !!r.canformal, canview: !!r.canview };
                this.stage = r.stage;
            } catch (e) {
                this.notify(e.message, 'error');
            } finally {
                this.loading = false;
            }
        },
        async open(row) {
            this.detailOpen = true;
            this.detail = null;
            this.balance = null;
            this.execResult = null;
            try {
                this.detail = await this.call('wdr_flow_detail', { id: row.id });
                this.loadBalance();
            } catch (e) {
                this.detailOpen = false;
                this.notify(e.message, 'error');
            }
        },
        async loadBalance() {
            if (!this.detail) return;
            this.balanceLoading = true;
            try {
                this.balance = await this.call('wdr_flow_balance', { id: this.detail.id });
            } catch (e) {
                this.balance = { ok: false, error: e.message };
            } finally {
                this.balanceLoading = false;
            }
        },
        async act(action, payload = {}, okmsg = 'Listo.', id = null) {
            this.busy = true;
            try {
                const r = await this.call('wdr_flow_action', { id: id || this.detail.id, action, payload: JSON.stringify(payload) });
                this.detail = r;
                if (r.execution) this.execResult = r.execution;
                this.notify(okmsg, 'success');
                Object.keys(this.dlg).forEach(k => { this.dlg[k] = false; });
                this.load();
                return true;
            } catch (e) {
                this.notify(e.message, 'error');
                return false;
            } finally {
                this.busy = false;
            }
        },
        // ── Acciones de Bienestar ─────────────────────────────────────────
        resetForm() {
            this.form = Object.assign(this.form, { type: 'llamada', text: '', followDate: '', followTime: '09:00', area: '',
                assignee: null, due: '', escalationId: 0, file: null });
        },
        openDlg(name) { this.resetForm(); this.dlg[name] = true; },
        ts(date, time) {
            if (!date) return 0;
            const x = new Date(date + 'T' + (time || '09:00') + ':00');
            return Math.floor(x.getTime() / 1000);
        },
        saveActivity() {
            this.act('activity', { type: this.form.type, text: this.form.text,
                next_followup: this.ts(this.form.followDate, this.form.followTime) }, 'Gestión registrada.');
        },
        async areaChanged() {
            this.form.assignee = null;
            this.staffLoading = true;
            try {
                this.staff = await this.call('wdr_flow_staff', { area: this.form.area });
                if (this.suggestedStaff.length === 1) this.form.assignee = this.suggestedStaff[0].id;
            } catch (e) {
                this.notify(e.message, 'error');
            } finally {
                this.staffLoading = false;
            }
        },
        saveEscalation() {
            this.act('escalate', { area: this.form.area, assignee: this.form.assignee, text: this.form.text,
                due: this.ts(this.form.due, '18:00') }, 'Caso escalado. La persona lo verá en sus pendientes.');
        },
        openRespond(l) { this.resetForm(); this.form.escalationId = l.id; this.dlg.respond = true; },
        saveRespond() {
            this.act('resolve_escalation', { text: this.form.text }, 'Respuesta registrada.', this.form.escalationId);
        },
        // ── Crear solicitud ───────────────────────────────────────────────
        openCreate() {
            this.create = { query: '', results: [], searching: false, student: null, channel: 'presencial', reason: '', observations: '' };
            this.dlg.create = true;
        },
        async searchStudents() {
            const q = (this.create.query || '').trim();
            if (q.length < 3) { this.create.results = []; return; }
            this.create.searching = true;
            try {
                this.create.results = await this.call('wdr_flow_students', { query: q });
            } catch (e) {
                this.notify(e.message, 'error');
            } finally {
                this.create.searching = false;
            }
        },
        async saveCreate() {
            this.busy = true;
            try {
                const r = await this.call('wdr_flow_create', { studentid: this.create.student.id, channel: this.create.channel,
                    reason: this.create.reason, observations: this.create.observations || '' });
                this.dlg.create = false;
                this.notify('Solicitud ' + r.request_number + ' registrada y asignada a ti.', 'success');
                this.stage = 'mias';
                await this.load();
                this.detail = r;
                this.detailOpen = true;
                this.loadBalance();
            } catch (e) {
                this.notify(e.message, 'error');
            } finally {
                this.busy = false;
            }
        },
        // ── Retiro formal ─────────────────────────────────────────────────
        checkItem(c) {
            if (c.key === 'scan') { this.openDlg('upload'); return; }
            this.act('check', { item: c.key }, c.label + ': registrado.');
        },
        async uploadScan() {
            const f = this.form.file;
            if (!f) return;
            if (!/\.pdf$/i.test(f.name)) { this.notify('Solo se aceptan archivos PDF.', 'error'); return; }
            this.busy = true;
            try {
                const buf = new Uint8Array(await f.arrayBuffer());
                let bin = '';
                for (let i = 0; i < buf.length; i += 0x8000) bin += String.fromCharCode.apply(null, buf.subarray(i, i + 0x8000));
                await this.call('wdr_admin_upload_scanned', { id: this.detail.id, filename: f.name, contentbase64: btoa(bin) });
                this.dlg.upload = false;
                this.notify('Copia escaneada archivada.', 'success');
                this.detail = await this.call('wdr_flow_detail', { id: this.detail.id });
                this.load();
            } catch (e) {
                this.notify(e.message, 'error');
            } finally {
                this.busy = false;
            }
        },
        async downloadPdf() {
            try {
                const r = await this.call('wdr_flow_pdf', { id: this.detail.id });
                const a = document.createElement('a');
                a.href = 'data:application/pdf;base64,' + r.base64;
                a.download = r.filename;
                a.click();
            } catch (e) {
                this.notify(e.message, 'error');
            }
        },
        async viewScan() {
            try {
                const r = await this.call('wdr_get_scanned', { id: this.detail.id });
                const b64 = r && (r.contentbase64 || r.base64);
                if (!b64) { this.notify('No hay copia escaneada.', 'info'); return; }
                const blob = new Blob([Uint8Array.from(atob(b64), c => c.charCodeAt(0))], { type: 'application/pdf' });
                window.open(URL.createObjectURL(blob), '_blank');
            } catch (e) {
                this.notify(e.message, 'error');
            }
        },
        // ── Formato ───────────────────────────────────────────────────────
        date(ts, time = false) {
            if (!ts) return '—';
            const x = new Date(ts * 1000);
            const d = x.toLocaleDateString('es-PA', { day: '2-digit', month: 'short', year: 'numeric' });
            return time ? d + ' ' + x.toLocaleTimeString('es-PA', { hour: '2-digit', minute: '2-digit' }) : d;
        },
        money(v, cur) { return (cur && cur !== 'USD' ? cur + ' ' : '$') + Number(v || 0).toFixed(2); },
        stageColor(s) {
            return { solicitada: 'orange', en_gestion: 'blue', retiro_formal: 'deep-purple', procesada: 'grey darken-1',
                retenida: 'green', rechazada: 'blue-grey', cancelada: 'blue-grey' }[s] || 'grey';
        },
        finChip(s) {
            const m = { al_dia: ['Al día', 'green'], mora: ['En mora', 'red'], becado: ['Becado', 'teal'], convenio: ['Convenio', 'indigo'] };
            return m[s] || null;
        },
        logIcon(t) {
            return { llamada: 'mdi-phone', reunion: 'mdi-account-group', mensaje: 'mdi-whatsapp', correo: 'mdi-email-outline',
                nota: 'mdi-note-text-outline', instruccion_pago: 'mdi-cash-alert', escalamiento: 'mdi-arrow-up-bold-circle',
                etapa: 'mdi-flag-variant' }[t] || 'mdi-circle-small';
        },
        logColor(l) {
            if (l.type === 'escalamiento') return l.resolved_at ? 'green' : 'orange';
            return { etapa: 'deep-purple', instruccion_pago: 'red' }[l.type] || 'blue';
        },
        notify(msg, kind) {
            if (window.Swal && Swal.fire) {
                Swal.fire({ toast: true, position: 'top-end', showConfirmButton: false, timer: 3500, icon: kind || 'info', title: msg });
            }
        },
    },
    template: `
<v-container fluid class="pa-2 pa-md-4">
  <!-- Encabezado -->
  <div class="d-flex flex-wrap align-center mb-3" style="gap:12px">
    <div>
      <div class="text-h5 font-weight-medium">Retiros</div>
      <div class="text-body-2 grey--text text--darken-1">
        Bienestar recibe y gestiona cada solicitud para evitar la deserción. Si el estudiante confirma que se va,
        Secretaría Académica y Registro hacen el retiro formal.
      </div>
    </div>
    <v-spacer></v-spacer>
    <v-btn v-if="perms.canmanage" color="primary" depressed @click="openCreate">
      <v-icon left>mdi-plus</v-icon>Registrar solicitud
    </v-btn>
  </div>

  <!-- Etapas -->
  <v-card outlined>
    <v-tabs v-model="tabIndex" show-arrows height="56">
      <v-tab v-for="t in tabs" :key="t.key" class="text-none">
        <v-icon left small>{{ t.icon }}</v-icon>{{ t.label }}
        <v-chip x-small class="ml-2" :color="(counts[t.key]||0) > 0 && t.key !== 'cerradas' ? 'primary' : ''" label>{{ counts[t.key] || 0 }}</v-chip>
      </v-tab>
    </v-tabs>
    <v-divider></v-divider>
    <div class="d-flex align-center pa-3" style="gap:8px">
      <v-text-field v-model="search" dense outlined hide-details clearable prepend-inner-icon="mdi-magnify"
        label="Buscar por nombre, cédula o número de solicitud" @keyup.enter="load" @click:clear="search=''; load()"
        style="max-width:460px"></v-text-field>
      <v-btn icon @click="load" :loading="loading" title="Actualizar"><v-icon>mdi-refresh</v-icon></v-btn>
    </div>
    <v-data-table :headers="headers" :items="rows" :loading="loading" item-key="id" :items-per-page="25"
      no-data-text="No hay solicitudes en esta etapa." class="gmk-wdr-table" @click:row="open">
      <template v-slot:item.fullname="{ item }">
        <div class="py-2">
          <div class="font-weight-medium">{{ item.fullname }}</div>
          <div class="text-caption grey--text">{{ item.request_number }} · {{ item.id_number }}<span v-if="item.program"> · {{ item.program }}</span></div>
        </div>
      </template>
      <template v-slot:item.stage_label="{ item }">
        <v-chip small :color="stageColor(item.stage)" dark label>{{ item.stage_label }}</v-chip>
        <v-chip v-if="item.open_escalations" x-small color="orange" text-color="white" class="ml-1" label title="Escalamientos abiertos">
          <v-icon x-small left>mdi-arrow-up-bold</v-icon>{{ item.open_escalations }}</v-chip>
      </template>
      <template v-slot:item.next_step="{ item }">
        <span :class="item.followup_overdue ? 'red--text font-weight-medium' : ''">
          <v-icon v-if="item.followup_overdue" small color="red">mdi-alarm</v-icon> {{ item.next_step || '—' }}
        </span>
        <v-chip v-if="finChip(item.finstatus) && item.finstatus !== 'al_dia'" x-small class="ml-1" :color="finChip(item.finstatus)[1]" dark label>
          {{ finChip(item.finstatus)[0] }}</v-chip>
      </template>
      <template v-slot:item.assigned_name="{ item }">{{ item.assigned_name || '—' }}</template>
      <template v-slot:item.timecreated="{ item }">
        <div>{{ date(item.timecreated) }}</div><div class="text-caption grey--text">{{ item.channel_label }}</div>
      </template>
    </v-data-table>
  </v-card>

  <!-- Detalle -->
  <v-dialog v-model="detailOpen" max-width="1180" scrollable :fullscreen="$vuetify.breakpoint.smAndDown">
    <v-card v-if="!detail" class="pa-10 text-center"><v-progress-circular indeterminate color="primary"></v-progress-circular></v-card>
    <v-card v-else>
      <v-card-title class="py-3">
        <div>
          <div class="text-h6">{{ d.fullname }}</div>
          <div class="text-caption grey--text">
            {{ d.request_number }} · {{ d.id_number }}<span v-if="d.program"> · {{ d.program }}</span>
          </div>
        </div>
        <v-spacer></v-spacer>
        <v-btn small text :href="d.profile_url" target="_blank" class="text-none"><v-icon left small>mdi-account</v-icon>Perfil</v-btn>
        <v-btn small text @click="downloadPdf" class="text-none"><v-icon left small>mdi-file-pdf-box</v-icon>RET-01</v-btn>
        <v-btn icon @click="detailOpen=false"><v-icon>mdi-close</v-icon></v-btn>
      </v-card-title>
      <v-divider></v-divider>

      <!-- Etapas del proceso -->
      <v-stepper :value="stepNumber" alt-labels flat class="elevation-0">
        <v-stepper-header class="elevation-0">
          <v-stepper-step step="1" :complete="stepNumber>1">Recepción<small>Bienestar</small></v-stepper-step>
          <v-divider></v-divider>
          <v-stepper-step step="2" :complete="stepNumber>2">Gestión de retención<small>Bienestar</small></v-stepper-step>
          <v-divider></v-divider>
          <v-stepper-step step="3" :complete="stepNumber>3">Retiro formal<small>Secretaría / Registro</small></v-stepper-step>
          <v-divider></v-divider>
          <v-stepper-step step="4" :complete="d.stage==='procesada' || d.stage==='retenida'">{{ isClosed ? d.stage_label : 'Cierre' }}</v-stepper-step>
        </v-stepper-header>
      </v-stepper>
      <v-divider></v-divider>

      <v-card-text class="pt-4" style="max-height:72vh">
        <v-row>
          <!-- Columna izquierda: que sigue -->
          <v-col cols="12" md="7">

            <!-- Escalamiento asignado a mi -->
            <v-alert v-for="l in myOpenEscalations" :key="'me'+l.id" border="left" colored-border color="orange" elevation="1" class="mb-4">
              <div class="font-weight-medium mb-1"><v-icon color="orange" small>mdi-arrow-up-bold-circle</v-icon>
                Te escalaron este caso ({{ l.area_label }})</div>
              <div class="mb-2" style="white-space:pre-wrap">{{ l.body }}</div>
              <div class="text-caption grey--text mb-2">De {{ l.author }} · {{ date(l.at, true) }}<span v-if="l.due"> · Responder antes del {{ date(l.due) }}</span></div>
              <v-btn color="orange" dark depressed small @click="openRespond(l)"><v-icon left small>mdi-reply</v-icon>Responder</v-btn>
            </v-alert>

            <!-- QUE SIGUE: Nueva -->
            <v-card v-if="d.stage==='solicitada'" outlined class="mb-4 gmk-next">
              <v-card-text>
                <div class="overline orange--text">Qué sigue</div>
                <div class="text-subtitle-1 font-weight-medium mb-1">Tomar el caso y contactar al estudiante</div>
                <div class="text-body-2 mb-3">El estudiante pidió retirarse desde {{ d.channel_label.toLowerCase() }}. Al tomarlo quedas como
                  responsable: contáctalo, entiende su motivo y registra cada gestión.</div>
                <v-btn v-if="d.canmanage" color="primary" depressed :loading="busy" @click="act('take', {}, 'Caso tomado.')">
                  <v-icon left>mdi-hand-back-right</v-icon>Tomar el caso</v-btn>
                <div v-else class="text-caption grey--text">Bienestar debe tomar este caso.</div>
              </v-card-text>
            </v-card>

            <!-- QUE SIGUE: En gestion -->
            <v-card v-if="d.stage==='en_gestion'" outlined class="mb-4 gmk-next">
              <v-card-text>
                <div class="overline blue--text">Qué sigue</div>
                <div class="text-subtitle-1 font-weight-medium mb-1">
                  <template v-if="d.followup_overdue"><v-icon color="red" small>mdi-alarm</v-icon> Seguimiento vencido ({{ date(d.next_followup, true) }})</template>
                  <template v-else-if="d.next_followup">Próximo seguimiento: {{ date(d.next_followup, true) }}</template>
                  <template v-else>Contactar al estudiante y buscar retenerlo</template>
                </div>
                <div class="text-body-2 mb-3">Registra cada contacto y programa el siguiente. Si necesitas apoyo de otra área, escala el caso.
                  Cuando el estudiante decida, cierra la gestión con una de las dos decisiones.</div>
                <div v-if="d.canmanage" class="d-flex flex-wrap" style="gap:8px">
                  <v-btn color="primary" depressed @click="openDlg('activity')"><v-icon left>mdi-text-box-plus</v-icon>Registrar gestión</v-btn>
                  <v-btn outlined color="orange darken-2" @click="openDlg('escalate')"><v-icon left>mdi-arrow-up-bold-circle-outline</v-icon>Escalar</v-btn>
                </div>
                <template v-if="d.canmanage">
                  <v-divider class="my-4"></v-divider>
                  <div class="text-caption grey--text mb-2">DECISIÓN DEL ESTUDIANTE</div>
                  <div class="d-flex flex-wrap" style="gap:8px">
                    <v-btn color="green" dark depressed @click="openDlg('retained')"><v-icon left>mdi-account-check</v-icon>Se queda</v-btn>
                    <v-btn color="deep-purple" dark depressed @click="openDlg('formal')"><v-icon left>mdi-account-arrow-right</v-icon>Confirma el retiro</v-btn>
                  </div>
                  <div v-if="openEscalations.length" class="text-caption orange--text text--darken-2 mt-2">
                    Hay {{ openEscalations.length }} escalamiento(s) sin respuesta.</div>
                </template>
              </v-card-text>
            </v-card>

            <!-- QUE SIGUE: Retiro formal -->
            <v-card v-if="d.stage==='retiro_formal'" outlined class="mb-4 gmk-next">
              <v-card-text>
                <div class="overline deep-purple--text">Qué sigue</div>
                <div class="text-subtitle-1 font-weight-medium mb-1">Retiro formal</div>
                <div class="text-body-2 mb-3" v-if="d.canformal">Completa el formulario RET-01 en orden y verifica que no tenga deuda.
                  Luego ejecuta el retiro: se hace en Odoo y en Moodle en un solo paso.</div>
                <div class="text-body-2 mb-3" v-else>Enviado por {{ d.formal_requested_by_name }} el {{ date(d.formal_requested_at) }}.
                  Ahora está en manos de Secretaría Académica y Registro.</div>

                <v-list dense class="pa-0 mb-3">
                  <v-list-item v-for="(c, i) in d.checklist" :key="c.key" class="px-0">
                    <v-list-item-icon class="mr-3">
                      <v-icon :color="c.done ? 'green' : (checklistNext && checklistNext.key===c.key ? 'deep-purple' : 'grey lighten-1')">
                        {{ c.done ? 'mdi-check-circle' : 'mdi-numeric-' + (i+1) + '-circle-outline' }}</v-icon>
                    </v-list-item-icon>
                    <v-list-item-content>
                      <v-list-item-title :class="c.done ? 'grey--text' : ''">{{ c.label }}</v-list-item-title>
                      <v-list-item-subtitle v-if="c.done && c.at">{{ c.by }} · {{ date(c.at, true) }}</v-list-item-subtitle>
                    </v-list-item-content>
                    <v-list-item-action v-if="d.canformal">
                      <v-btn v-if="!c.done && checklistNext && checklistNext.key===c.key" small depressed color="deep-purple" dark
                        :loading="busy" @click="checkItem(c)">{{ c.key==='scan' ? 'Subir PDF' : 'Registrar' }}</v-btn>
                      <v-btn v-if="c.key==='scan' && c.done" small text @click="viewScan">Ver</v-btn>
                    </v-list-item-action>
                  </v-list-item>
                </v-list>

                <v-alert v-if="d.canformal && hasDebt" type="error" dense text class="mb-3">
                  Tiene deuda pendiente: <b>{{ money(balance.total, balance.currency) }}</b>. El retiro no se puede ejecutar hasta que se ponga al día.
                </v-alert>
                <v-alert v-if="d.canformal && balance && !balance.ok" type="warning" dense text class="mb-3">
                  No se pudo consultar Odoo: {{ balance.error }}</v-alert>

                <div v-if="d.canformal && d.preview" class="text-body-2 mb-3">
                  <div class="font-weight-medium mb-1">Al ejecutar se hará:</div>
                  <ul class="pl-5">
                    <li>Odoo: cancelar el contrato y las cuotas restantes, estado Retirado.</li>
                    <li>Retirar de {{ d.preview.courses.length }} asignatura(s) en curso<span v-if="d.preview.courses.length">:
                      {{ d.preview.courses.map(c => c.name).join(', ') }}</span>.</li>
                    <li v-if="d.preview.pending_module_requests">Anular {{ d.preview.pending_module_requests }} solicitud(es) de módulo por pagar.</li>
                    <li>Marcar al estudiante como Retirado y suspender su cuenta (se conservan notas e historial).</li>
                  </ul>
                </div>

                <div v-if="d.canformal" class="d-flex flex-wrap" style="gap:8px">
                  <v-btn color="deep-purple" dark depressed :disabled="!canExecute" @click="dlg.execute=true">
                    <v-icon left>mdi-account-remove</v-icon>Ejecutar retiro formal</v-btn>
                  <v-btn text @click="openDlg('back')">Devolver a gestión</v-btn>
                </div>
                <div v-if="d.canformal && !canExecute" class="text-caption grey--text mt-2">
                  <span v-if="!checklistDone">Falta: {{ checklistNext ? checklistNext.label : '' }}. </span>
                  <span v-if="!noDebt && !hasDebt">Esperando el estado financiero. </span>
                </div>
                <v-alert v-if="d.moodle_actions && d.moodle_actions.odoo && !isClosed" type="warning" dense text class="mt-3">
                  El retiro quedó a medias. Revisa el detalle abajo y vuelve a ejecutar: solo se repiten los pasos pendientes.
                </v-alert>
              </v-card-text>
            </v-card>

            <!-- Cierre -->
            <v-card v-if="isClosed" outlined class="mb-4">
              <v-card-text>
                <div class="overline">Resultado</div>
                <div class="text-subtitle-1 font-weight-medium mb-2">
                  <v-chip :color="stageColor(d.stage)" dark label small class="mr-2">{{ d.stage_label }}</v-chip>
                  {{ d.closed_by_name ? 'por ' + d.closed_by_name + ' · ' + date(d.closed_at, true) : '' }}
                </div>
                <div v-if="d.stage==='rechazada'" class="text-body-2">Motivo: {{ d.rejection_reason }}</div>
                <v-list dense v-if="actionsList.length" class="pa-0">
                  <v-list-item v-for="(a, i) in actionsList" :key="i" class="px-0">
                    <v-icon small :color="a.ok ? 'green' : 'red'" class="mr-2">{{ a.ok ? 'mdi-check' : 'mdi-alert' }}</v-icon>
                    <span class="text-body-2">{{ a.text }}</span>
                  </v-list-item>
                </v-list>
              </v-card-text>
            </v-card>
            <v-card v-if="!isClosed && actionsList.length" outlined class="mb-4">
              <v-card-text>
                <div class="overline">Pasos del retiro ejecutados</div>
                <div v-for="(a, i) in actionsList" :key="i" class="text-body-2">
                  <v-icon small :color="a.ok ? 'green' : 'red'">{{ a.ok ? 'mdi-check' : 'mdi-alert' }}</v-icon> {{ a.text }}</div>
              </v-card-text>
            </v-card>

            <!-- Datos de la solicitud -->
            <v-expansion-panels flat class="mb-2">
              <v-expansion-panel class="gmk-panel">
                <v-expansion-panel-header class="px-3">Datos de la solicitud</v-expansion-panel-header>
                <v-expansion-panel-content>
                  <table class="text-body-2 gmk-kv">
                    <tr><td>Motivo</td><td>{{ d.reason_label }}</td></tr>
                    <tr><td>Canal</td><td>{{ d.channel_label }}<span v-if="d.created_by_name"> (registrada por {{ d.created_by_name }})</span></td></tr>
                    <tr><td>Recibida</td><td>{{ date(d.timecreated, true) }}</td></tr>
                    <tr><td>Observaciones</td><td style="white-space:pre-wrap">{{ d.observations || '—' }}</td></tr>
                    <tr><td>Correo</td><td>{{ d.email || '—' }}</td></tr>
                    <tr><td>Teléfono</td><td>{{ d.phone || '—' }}</td></tr>
                    <tr><td>Periodo</td><td>{{ d.current_period || '—' }}</td></tr>
                    <tr><td>Responsable</td><td>{{ d.assigned_name || '—' }}</td></tr>
                  </table>
                  <div class="mt-3" v-if="!isClosed && (d.canmanage || d.canformal)">
                    <v-btn small text color="blue-grey" @click="openDlg('voidit')"><v-icon left small>mdi-cancel</v-icon>Anular solicitud</v-btn>
                  </div>
                </v-expansion-panel-content>
              </v-expansion-panel>
            </v-expansion-panels>
          </v-col>

          <!-- Columna derecha: finanzas y bitacora -->
          <v-col cols="12" md="5">
            <v-card outlined class="mb-4">
              <v-card-text>
                <div class="d-flex align-center">
                  <div class="overline">Estado financiero (Odoo)</div>
                  <v-spacer></v-spacer>
                  <v-btn icon small @click="loadBalance" :loading="balanceLoading" title="Consultar de nuevo"><v-icon small>mdi-refresh</v-icon></v-btn>
                </div>
                <div v-if="balanceLoading && !balance" class="text-body-2 grey--text">Consultando…</div>
                <template v-else-if="balance && balance.ok">
                  <div v-if="!balance.hasBalance" class="d-flex align-center">
                    <v-icon color="green" class="mr-2">mdi-check-decagram</v-icon>
                    <span class="text-subtitle-1 green--text text--darken-2 font-weight-medium">Al día, no debe nada</span>
                  </div>
                  <template v-else>
                    <div class="text-h5 red--text text--darken-1 font-weight-medium">{{ money(balance.total, balance.currency) }}</div>
                    <div class="text-body-2 mb-2">pendiente en {{ balance.invoiceCount }} factura(s)<span v-if="balance.overdueCount">,
                      <b class="red--text">{{ balance.overdueCount }} vencida(s)</b></span></div>
                    <v-simple-table dense v-if="balance.invoices && balance.invoices.length" class="mb-2">
                      <tbody>
                        <tr v-for="i in balance.invoices" :key="i.name">
                          <td class="text-caption">{{ i.name }}</td>
                          <td class="text-caption">vence {{ i.due || '—' }}</td>
                          <td class="text-caption text-right" :class="i.overdue ? 'red--text' : ''">{{ money(i.residual, balance.currency) }}</td>
                        </tr>
                      </tbody>
                    </v-simple-table>
                    <v-alert dense text type="warning" class="text-body-2 mb-2">
                      Para procesar el retiro debe ponerse al día. Indícaselo al estudiante.
                    </v-alert>
                    <div v-if="d.payment_instructed_at" class="text-caption green--text text--darken-2 mb-1">
                      <v-icon x-small color="green">mdi-check</v-icon> Se le indicó el {{ date(d.payment_instructed_at, true) }}</div>
                    <v-btn v-if="d.canmanage && (d.stage==='en_gestion' || d.stage==='retiro_formal')" small outlined color="red darken-1"
                      @click="openDlg('payment')"><v-icon left small>mdi-cash-check</v-icon>Registrar que se le indicó</v-btn>
                  </template>
                </template>
                <div v-else-if="balance" class="text-body-2 orange--text text--darken-2">No se pudo consultar: {{ balance.error }}</div>
              </v-card-text>
            </v-card>

            <div class="overline mb-2">Bitácora de gestión</div>
            <div v-if="!(d.log||[]).length" class="text-body-2 grey--text">Sin gestiones registradas.</div>
            <v-timeline dense align-top class="pt-0 gmk-timeline">
              <v-timeline-item v-for="l in d.log" :key="l.id" small :color="logColor(l)" :icon="logIcon(l.type)" fill-dot>
                <div class="text-caption grey--text">{{ l.type_label }} · {{ l.author }} · {{ date(l.at, true) }}</div>
                <div v-if="l.type==='escalamiento'" class="text-body-2 font-weight-medium">A {{ l.area_label }}: {{ l.assignee_name }}</div>
                <div class="text-body-2" style="white-space:pre-wrap">{{ l.body }}</div>
                <div v-if="l.due && l.type!=='escalamiento'" class="text-caption blue--text">Próximo seguimiento: {{ date(l.due, true) }}</div>
                <div v-if="l.type==='escalamiento'" class="text-caption mt-1">
                  <template v-if="l.resolved_at">
                    <div class="green--text text--darken-2">Respondido por {{ l.resolved_by_name }} · {{ date(l.resolved_at, true) }}</div>
                    <div style="white-space:pre-wrap">{{ l.response }}</div>
                  </template>
                  <template v-else>
                    <span class="orange--text text--darken-2">Pendiente<span v-if="l.due"> · antes del {{ date(l.due) }}</span></span>
                    <v-btn v-if="d.canmanage && l.assignee!==d.me" x-small text @click="openRespond(l)">Registrar respuesta</v-btn>
                  </template>
                </div>
              </v-timeline-item>
            </v-timeline>
          </v-col>
        </v-row>
      </v-card-text>
    </v-card>
  </v-dialog>

  <!-- Registrar gestion -->
  <v-dialog v-model="dlg.activity" max-width="560">
    <v-card>
      <v-card-title>Registrar gestión</v-card-title>
      <v-card-text>
        <v-btn-toggle v-model="form.type" mandatory dense color="primary" class="mb-4 flex-wrap">
          <v-btn v-for="t in cat.logtypes" :key="t.value" :value="t.value" small class="text-none">{{ t.text }}</v-btn>
        </v-btn-toggle>
        <v-textarea v-model="form.text" outlined auto-grow rows="3" label="¿Qué se habló o hizo?"
          hint="Motivo real del estudiante, alternativas ofrecidas, acuerdos." persistent-hint></v-textarea>
        <div class="text-subtitle-2 mt-4 mb-1">Próximo seguimiento</div>
        <div class="d-flex flex-wrap mb-2" style="gap:6px">
          <v-chip v-for="q in followQuick" :key="q.t" small :color="form.followDate===q.v ? 'primary' : ''" @click="form.followDate=q.v">{{ q.t }}</v-chip>
          <v-chip small :color="!form.followDate ? 'primary' : ''" @click="form.followDate=''">Sin seguimiento</v-chip>
        </div>
        <v-row dense>
          <v-col cols="7"><v-text-field v-model="form.followDate" type="date" dense outlined hide-details label="Fecha"></v-text-field></v-col>
          <v-col cols="5"><v-text-field v-model="form.followTime" type="time" dense outlined hide-details label="Hora" :disabled="!form.followDate"></v-text-field></v-col>
        </v-row>
      </v-card-text>
      <v-card-actions><v-spacer></v-spacer><v-btn text @click="dlg.activity=false">Cancelar</v-btn>
        <v-btn color="primary" depressed :disabled="!form.text.trim()" :loading="busy" @click="saveActivity">Guardar</v-btn></v-card-actions>
    </v-card>
  </v-dialog>

  <!-- Escalar -->
  <v-dialog v-model="dlg.escalate" max-width="560">
    <v-card>
      <v-card-title>Escalar el caso</v-card-title>
      <v-card-text>
        <v-select v-model="form.area" :items="cat.areas" outlined dense label="Área" @change="areaChanged"></v-select>
        <v-autocomplete v-model="form.assignee" :items="staffItems" item-text="fullname" item-value="id" outlined dense
          label="Responsable" :loading="staffLoading" :disabled="!form.area" hint="Verá el caso en sus pendientes y podrá responder." persistent-hint>
          <template v-slot:item="{ item }"><v-list-item-content><v-list-item-title>{{ item.fullname }}</v-list-item-title>
            <v-list-item-subtitle>{{ item.role }}</v-list-item-subtitle></v-list-item-content></template>
        </v-autocomplete>
        <v-textarea v-model="form.text" outlined auto-grow rows="3" class="mt-4" label="¿Qué necesitas del área?"></v-textarea>
        <v-text-field v-model="form.due" type="date" outlined dense label="Responder antes del (opcional)"></v-text-field>
      </v-card-text>
      <v-card-actions><v-spacer></v-spacer><v-btn text @click="dlg.escalate=false">Cancelar</v-btn>
        <v-btn color="orange darken-2" dark depressed :disabled="!form.area || !form.assignee || !form.text.trim()" :loading="busy" @click="saveEscalation">Escalar</v-btn></v-card-actions>
    </v-card>
  </v-dialog>

  <!-- Responder escalamiento -->
  <v-dialog v-model="dlg.respond" max-width="520">
    <v-card>
      <v-card-title>Responder el escalamiento</v-card-title>
      <v-card-text><v-textarea v-model="form.text" outlined auto-grow rows="4" label="Respuesta o gestión realizada"></v-textarea></v-card-text>
      <v-card-actions><v-spacer></v-spacer><v-btn text @click="dlg.respond=false">Cancelar</v-btn>
        <v-btn color="primary" depressed :disabled="!form.text.trim()" :loading="busy" @click="saveRespond">Enviar respuesta</v-btn></v-card-actions>
    </v-card>
  </v-dialog>

  <!-- Instruccion de pago -->
  <v-dialog v-model="dlg.payment" max-width="520">
    <v-card>
      <v-card-title>Indicación de ponerse al día</v-card-title>
      <v-card-text>
        <div class="text-body-2 mb-3" v-if="balance && balance.ok">Saldo pendiente: <b>{{ money(balance.total, balance.currency) }}</b>.</div>
        <v-textarea v-model="form.text" outlined auto-grow rows="3" label="Cómo y cuándo se le indicó (opcional)"></v-textarea>
      </v-card-text>
      <v-card-actions><v-spacer></v-spacer><v-btn text @click="dlg.payment=false">Cancelar</v-btn>
        <v-btn color="red darken-1" dark depressed :loading="busy" @click="act('payment_instruction', { text: form.text }, 'Registrado.')">Registrar</v-btn></v-card-actions>
    </v-card>
  </v-dialog>

  <!-- Se queda -->
  <v-dialog v-model="dlg.retained" max-width="520">
    <v-card>
      <v-card-title>El estudiante se queda</v-card-title>
      <v-card-text>
        <div class="text-body-2 mb-3">La solicitud se cierra como <b>Retenido</b>. Describe el acuerdo para que quede en el historial.</div>
        <v-textarea v-model="form.text" outlined auto-grow rows="3" label="Acuerdo con el estudiante"></v-textarea>
      </v-card-text>
      <v-card-actions><v-spacer></v-spacer><v-btn text @click="dlg.retained=false">Cancelar</v-btn>
        <v-btn color="green" dark depressed :disabled="!form.text.trim()" :loading="busy" @click="act('retained', { text: form.text }, 'Caso cerrado: el estudiante se queda.')">Cerrar como retenido</v-btn></v-card-actions>
    </v-card>
  </v-dialog>

  <!-- Confirma retiro -->
  <v-dialog v-model="dlg.formal" max-width="560">
    <v-card>
      <v-card-title>Enviar a retiro formal</v-card-title>
      <v-card-text>
        <div class="text-body-2 mb-3">Pasa a <b>Secretaría Académica y Registro</b>, que completarán el RET-01 y harán el retiro en Odoo y Moodle.</div>
        <v-alert v-if="hasDebt" type="warning" dense text class="text-body-2">
          Tiene deuda de <b>{{ money(balance.total, balance.currency) }}</b>. El retiro formal no se podrá ejecutar hasta que pague.
          <span v-if="!d.payment_instructed_at"> Todavía no registras que se lo indicaste.</span>
        </v-alert>
        <v-textarea v-model="form.text" outlined auto-grow rows="3" label="Resumen de la gestión y motivo final"></v-textarea>
      </v-card-text>
      <v-card-actions><v-spacer></v-spacer><v-btn text @click="dlg.formal=false">Cancelar</v-btn>
        <v-btn color="deep-purple" dark depressed :disabled="!form.text.trim()" :loading="busy" @click="act('to_formal', { text: form.text }, 'Enviado a Secretaría Académica y Registro.')">Enviar</v-btn></v-card-actions>
    </v-card>
  </v-dialog>

  <!-- Devolver a gestion -->
  <v-dialog v-model="dlg.back" max-width="520">
    <v-card>
      <v-card-title>Devolver a gestión de Bienestar</v-card-title>
      <v-card-text><v-textarea v-model="form.text" outlined auto-grow rows="3" label="¿Por qué vuelve? (p. ej. lo está reconsiderando)"></v-textarea></v-card-text>
      <v-card-actions><v-spacer></v-spacer><v-btn text @click="dlg.back=false">Cancelar</v-btn>
        <v-btn color="primary" depressed :disabled="!form.text.trim()" :loading="busy" @click="act('return_to_management', { text: form.text }, 'Devuelto a gestión.')">Devolver</v-btn></v-card-actions>
    </v-card>
  </v-dialog>

  <!-- Anular -->
  <v-dialog v-model="dlg.voidit" max-width="520">
    <v-card>
      <v-card-title>Anular la solicitud</v-card-title>
      <v-card-text>
        <div class="text-body-2 mb-3">Úsalo solo si la solicitud no corresponde (duplicada, registrada por error). Si el estudiante decidió quedarse, usa "Se queda".</div>
        <v-textarea v-model="form.text" outlined auto-grow rows="2" label="Motivo"></v-textarea>
      </v-card-text>
      <v-card-actions><v-spacer></v-spacer><v-btn text @click="dlg.voidit=false">Cancelar</v-btn>
        <v-btn color="blue-grey" dark depressed :disabled="!form.text.trim()" :loading="busy" @click="act('void', { text: form.text }, 'Solicitud anulada.')">Anular</v-btn></v-card-actions>
    </v-card>
  </v-dialog>

  <!-- Subir escaneado -->
  <v-dialog v-model="dlg.upload" max-width="480">
    <v-card>
      <v-card-title>Copia escaneada del RET-01</v-card-title>
      <v-card-text>
        <div class="text-body-2 mb-3">PDF con la firma del estudiante y los dos recibidos.</div>
        <v-file-input v-model="form.file" accept="application/pdf" outlined dense label="Archivo PDF" prepend-icon="mdi-file-pdf-box"></v-file-input>
      </v-card-text>
      <v-card-actions><v-spacer></v-spacer><v-btn text @click="dlg.upload=false">Cancelar</v-btn>
        <v-btn color="deep-purple" dark depressed :disabled="!form.file" :loading="busy" @click="uploadScan">Subir</v-btn></v-card-actions>
    </v-card>
  </v-dialog>

  <!-- Ejecutar retiro -->
  <v-dialog v-model="dlg.execute" max-width="560">
    <v-card>
      <v-card-title>¿Ejecutar el retiro formal?</v-card-title>
      <v-card-text class="text-body-2">
        <b>{{ d.fullname }}</b> quedará retirado:
        <ul class="pl-5 mt-2" v-if="d.preview">
          <li>Odoo: contrato y cuotas restantes cancelados.</li>
          <li>Retiro de {{ d.preview.courses.length }} asignatura(s) en curso.</li>
          <li v-if="d.preview.pending_module_requests">{{ d.preview.pending_module_requests }} solicitud(es) de módulo por pagar anuladas.</li>
          <li>Estado Retirado y cuenta suspendida.</li>
        </ul>
        <div class="mt-2">Esta acción no se deshace desde aquí.</div>
      </v-card-text>
      <v-card-actions><v-spacer></v-spacer><v-btn text @click="dlg.execute=false">Cancelar</v-btn>
        <v-btn color="deep-purple" dark depressed :loading="busy" @click="act('execute', {}, 'Retiro formal ejecutado.')">Sí, ejecutar</v-btn></v-card-actions>
    </v-card>
  </v-dialog>

  <!-- Registrar solicitud -->
  <v-dialog v-model="dlg.create" max-width="620">
    <v-card>
      <v-card-title>Registrar solicitud de retiro</v-card-title>
      <v-card-subtitle class="pt-1">Para solicitudes que llegan en persona, por teléfono, correo o WhatsApp. Quedará en tus casos.</v-card-subtitle>
      <v-card-text>
        <div class="text-subtitle-2 mb-1">1. Estudiante</div>
        <template v-if="!create.student">
          <v-text-field v-model="create.query" outlined dense autofocus clearable prepend-inner-icon="mdi-magnify"
            label="Nombre o cédula (mínimo 3 caracteres)" :loading="create.searching" @input="searchStudents" hide-details></v-text-field>
          <v-list dense class="mt-1" v-if="create.results.length">
            <v-list-item v-for="s in create.results" :key="s.id" :disabled="!!s.openrequest" @click="create.student=s">
              <v-list-item-content>
                <v-list-item-title>{{ s.fullname }}</v-list-item-title>
                <v-list-item-subtitle>{{ s.document }}<span v-if="s.program"> · {{ s.program }}</span>
                  <span v-if="s.openrequest" class="orange--text"> · ya tiene la solicitud {{ s.openrequest }} abierta</span>
                  <span v-if="s.suspended" class="grey--text"> · cuenta suspendida</span></v-list-item-subtitle>
              </v-list-item-content>
            </v-list-item>
          </v-list>
          <div v-else-if="(create.query||'').length >= 3 && !create.searching" class="text-caption grey--text mt-2">Sin resultados.</div>
        </template>
        <v-alert v-else dense outlined color="primary" class="mb-0">
          <div class="d-flex align-center"><div><b>{{ create.student.fullname }}</b><div class="text-caption">{{ create.student.document }} · {{ create.student.program }}</div></div>
            <v-spacer></v-spacer><v-btn small text @click="create.student=null">Cambiar</v-btn></div>
        </v-alert>
        <template v-if="create.student">
          <div class="text-subtitle-2 mt-4 mb-1">2. ¿Cómo llegó la solicitud?</div>
          <v-chip-group v-model="create.channel" mandatory active-class="primary--text">
            <v-chip v-for="c in cat.channels" :key="c.value" :value="c.value" filter outlined>{{ c.text }}</v-chip>
          </v-chip-group>
          <div class="text-subtitle-2 mt-3 mb-1">3. Motivo que expresa el estudiante</div>
          <v-chip-group v-model="create.reason" column active-class="primary--text">
            <v-chip v-for="r in cat.reasons" :key="r.value" :value="r.value" filter outlined>{{ r.text }}</v-chip>
          </v-chip-group>
          <v-textarea v-model="create.observations" outlined auto-grow rows="2" class="mt-3" label="Detalle (opcional)"></v-textarea>
        </template>
      </v-card-text>
      <v-card-actions><v-spacer></v-spacer><v-btn text @click="dlg.create=false">Cancelar</v-btn>
        <v-btn color="primary" depressed :disabled="!create.student || !create.reason" :loading="busy" @click="saveCreate">Registrar y tomar el caso</v-btn></v-card-actions>
    </v-card>
  </v-dialog>
</v-container>
`,
});
