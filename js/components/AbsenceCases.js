/**
 * AbsenceCases.js
 * Seguimiento de inasistencias (20261001131). Mobile first.
 *  - Casos: estudiantes bloqueados por inasistencia en clases en curso, con
 *    contacto a un toque y el historial de gestiones (solo agregar).
 *  - Supervisión (gerencia y administradores): indicadores que llevan al
 *    detalle, serie semanal, resultados y gestiones por persona.
 * Datos: WS local_grupomakro_absence_* (gmkSupportCall, SupportApi.js).
 */
(function () {
    if (document.getElementById('gmk-abs-css')) {
        return;
    }
    const css = `
#gmk-app .gmk-abs { max-width: 1200px; margin: 0 auto; }
#gmk-app .gmk-abs-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 10px; }
@media (min-width: 900px) { #gmk-app .gmk-abs-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
#gmk-app .gmk-abs-card { border-left: 4px solid #9e9e9e; min-width: 0; }
#gmk-app .gmk-abs-card--due { border-left-color: #e53935; }
#gmk-app .gmk-abs-card--ok { border-left-color: #43a047; }
#gmk-app .gmk-abs-card--closed { border-left-color: #9e9e9e; opacity: .85; }
#gmk-app .gmk-abs-sub { font-size: 12px; color: rgba(0,0,0,.6); overflow-wrap: anywhere; }
#gmk-app .gmk-abs-contacts { display: flex; flex-wrap: wrap; gap: 6px; }
#gmk-app .gmk-abs-contacts .v-btn { text-transform: none; letter-spacing: normal; }
#gmk-app .gmk-abs-kpis { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
@media (min-width: 700px) { #gmk-app .gmk-abs-kpis { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
#gmk-app .gmk-abs-kpi { cursor: pointer; padding: 12px; min-height: 84px; }
#gmk-app .gmk-abs-kpi .n { font-size: 26px; font-weight: 500; line-height: 1.1; }
#gmk-app .gmk-abs-log { border-left: 2px solid rgba(0,0,0,.12); padding-left: 10px; margin-bottom: 10px; }
#gmk-app .gmk-abs-bars { display: flex; align-items: flex-end; gap: 6px; height: 140px; overflow-x: auto; padding-bottom: 2px; }
#gmk-app .gmk-abs-week { flex: 1 0 30px; display: flex; flex-direction: column; align-items: center; gap: 2px; }
#gmk-app .gmk-abs-week .b { display: flex; align-items: flex-end; gap: 2px; height: 110px; }
#gmk-app .gmk-abs-week .b span { width: 8px; border-radius: 2px 2px 0 0; display: block; }
#gmk-app .gmk-abs-hbar { height: 10px; border-radius: 5px; background: #1976d2; }
#gmk-app .gmk-abs-wrap { white-space: normal; overflow-wrap: anywhere; }
`;
    const style = document.createElement('style');
    style.id = 'gmk-abs-css';
    style.textContent = css;
    document.head.appendChild(style);
})();

Vue.component('absence-cases', {
    props: { config: { type: Object, default: () => ({}) } },
    data() {
        return {
            tab: this.config.tab === 'supervision' ? 1 : 0,
            filter: 'open',
            search: '',
            cases: [],
            meta: { canmanage: false, cansupervise: false, statuses: {}, channels: {}, results: {}, reasons: {} },
            loading: false,
            error: '',
            drill: null,
            dialog: false,
            current: null,
            saving: false,
            form: this.emptyForm(),
            stats: null,
            seq: 0,
        };
    },
    computed: {
        filters() {
            return [
                { value: 'open', text: 'Abiertos' },
                { value: 'due', text: 'Por gestionar' },
                { value: 'unmanaged', text: 'Sin gestión' },
                { value: 'overdue', text: 'Seguimiento vencido' },
                { value: 'closed', text: 'Cerrados' },
                { value: 'all', text: 'Todos' },
            ];
        },
        dueCount() {
            return this.cases.filter(c => c.due).length;
        },
        kpis() {
            if (!this.stats) return [];
            const i = this.stats.indicators;
            return [
                { key: 'open', label: 'Casos abiertos', n: i.open.n, ids: i.open.ids, color: '#1976d2' },
                { key: 'unmanaged', label: 'Sin ninguna gestión', n: i.unmanaged.n, ids: i.unmanaged.ids, color: '#e53935' },
                { key: 'overdue', label: 'Seguimiento vencido', n: i.overdue.n, ids: i.overdue.ids, color: '#fb8c00' },
                { key: 'late', label: 'Primer contacto fuera de plazo', n: i.late.n, ids: i.late.ids, color: '#6a1b9a' },
                { key: 'ontime', label: 'Primer contacto a tiempo', n: i.ontime.n, ids: i.ontime.ids, color: '#43a047' },
                { key: 'closed', label: 'Cerrados por gestión', n: i.closed.n, ids: i.closed.ids, color: '#546e7a' },
                { key: 'auto_closed', label: 'Cerrados solos (desbloqueo)', n: i.auto_closed.n, ids: i.auto_closed.ids, color: '#90a4ae' },
            ];
        },
        seriesMax() {
            if (!this.stats) return 1;
            return Math.max(1, ...this.stats.series.map(s => Math.max(s.actions, s.opened, s.closed)));
        },
        resultsMax() {
            if (!this.stats || !this.stats.results.length) return 1;
            return Math.max(1, ...this.stats.results.map(r => r.n));
        },
        statusOptions() {
            return Object.keys(this.meta.statuses).filter(k => k !== 'nuevo').map(k => ({ value: k, text: this.meta.statuses[k] }));
        },
        channelOptions() {
            return Object.keys(this.meta.channels).map(k => ({ value: k, text: this.meta.channels[k] }));
        },
        resultOptions() {
            return Object.keys(this.meta.results).map(k => ({ value: k, text: this.meta.results[k] }));
        },
        reasonOptions() {
            return Object.keys(this.meta.reasons).map(k => ({ value: k, text: this.meta.reasons[k] }));
        },
        correctionOptions() {
            if (!this.current) return [];
            return this.current.log.filter(l => l.result !== 'correccion')
                .map(l => ({ value: l.id, text: this.fmt(l.time, true) + ' · ' + l.resultlabel + ' (' + l.author + ')' }));
        },
    },
    created() {
        this.load();
        if (this.config.caseid) {
            this.open({ id: this.config.caseid });
        }
    },
    watch: {
        tab(v) {
            if (v === 1 && !this.stats) this.loadStats();
        },
    },
    methods: {
        emptyForm() {
            const d = new Date();
            d.setDate(d.getDate() + 2);
            return { channel: 'llamada', result: 'contactado', note: '', next: d.toISOString().slice(0, 10),
                status: 'en_seguimiento', closed_reason: '', correctsid: null };
        },
        async call(method, args) {
            return JSON.parse((await window.gmkSupportCall(method, args)).json);
        },
        async load() {
            const seq = ++this.seq;
            this.loading = true;
            this.error = '';
            try {
                const args = { status: this.filter, search: this.search || '' };
                if (this.drill) args.ids = this.drill.ids.join(',');
                const r = await this.call('local_grupomakro_absence_cases_list', args);
                if (seq !== this.seq) return;
                this.cases = r.cases;
                this.meta = r;
                if (this.tab === 1 && r.cansupervise && !this.stats) this.loadStats();
            } catch (e) {
                if (seq === this.seq) this.error = e.message || 'No se pudieron cargar los casos.';
            } finally {
                if (seq === this.seq) this.loading = false;
            }
        },
        async loadStats() {
            try {
                this.stats = await this.call('local_grupomakro_absence_cases_stats', { weeks: 8 });
            } catch (e) {
                this.error = e.message || 'No se pudieron cargar los indicadores.';
            }
        },
        setFilter(v) {
            this.filter = v;
            this.drill = null;
            this.load();
        },
        drillTo(k) {
            this.drill = { label: k.label, ids: k.ids.length ? k.ids : [0] };
            this.tab = 0;
            this.load();
        },
        clearDrill() {
            this.drill = null;
            this.load();
        },
        async open(c) {
            this.error = '';
            try {
                this.current = await this.call('local_grupomakro_absence_cases_get', { caseid: c.id });
                this.form = this.emptyForm();
                this.dialog = true;
            } catch (e) {
                this.error = e.message || 'No se pudo abrir el caso.';
            }
        },
        async save() {
            const f = this.form;
            if (f.result === 'correccion' && (!f.correctsid || !f.note.trim())) {
                this.error = 'Una corrección necesita la gestión que corrige y una nota.';
                return;
            }
            const closing = f.status === 'cerrado';
            if (closing && !f.closed_reason) {
                this.error = 'Indica el motivo del cierre.';
                return;
            }
            if (!closing && f.result !== 'correccion' && !f.next) {
                this.error = 'Indica la fecha del próximo seguimiento (o cierra el caso).';
                return;
            }
            this.saving = true;
            this.error = '';
            try {
                const next = !closing && f.next ? Math.floor(new Date(f.next + 'T09:00:00').getTime() / 1000) : 0;
                this.current = await this.call('local_grupomakro_absence_cases_log', {
                    caseid: this.current.id, channel: f.channel, result: f.result, note: f.note || '',
                    next_followup: next, status: f.status, closed_reason: closing ? f.closed_reason : '',
                    correctsid: f.result === 'correccion' ? (f.correctsid || 0) : 0,
                });
                this.form = this.emptyForm();
                this.stats = null;
                this.load();
            } catch (e) {
                this.error = e.message || 'No se pudo guardar la gestión.';
            } finally {
                this.saving = false;
            }
        },
        fmt(ts, time) {
            if (!ts) return '';
            return new Date(ts * 1000).toLocaleString('es-PA', time
                ? { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }
                : { day: '2-digit', month: 'short', year: 'numeric' });
        },
        ago(ts) {
            if (!ts) return '';
            const d = Math.floor((Date.now() / 1000 - ts) / 86400);
            return d <= 0 ? 'hoy' : (d === 1 ? 'hace 1 día' : 'hace ' + d + ' días');
        },
        cardClass(c) {
            if (c.status === 'cerrado') return 'gmk-abs-card--closed';
            return c.due ? 'gmk-abs-card--due' : 'gmk-abs-card--ok';
        },
        statusColor(s) {
            return { nuevo: 'red', en_gestion: 'orange', en_seguimiento: 'blue', cerrado: 'grey' }[s] || 'grey';
        },
    },
    template: `
<div class="gmk-abs pa-2 pa-sm-4">
  <v-tabs v-model="tab" grow class="mb-3">
    <v-tab>Casos</v-tab>
    <v-tab v-if="meta.cansupervise">Supervisión</v-tab>
  </v-tabs>

  <v-alert v-if="error" type="error" dense outlined dismissible class="gmk-abs-wrap" @input="error = ''">{{ error }}</v-alert>

  <!-- CASOS -->
  <div v-show="tab === 0">
    <div class="d-flex flex-wrap align-center mb-2" style="gap: 8px;">
      <v-select :items="filters" :value="filter" @change="setFilter" dense outlined hide-details label="Mostrar" style="max-width: 220px; min-width: 160px;"></v-select>
      <v-text-field v-model="search" dense outlined hide-details clearable label="Buscar nombre, cédula o clase"
                    prepend-inner-icon="mdi-magnify" style="min-width: 200px; flex: 1 1 200px;" @keyup.enter="load" @click:clear="search=''; load()"></v-text-field>
      <v-btn icon :loading="loading" @click="load" aria-label="Actualizar"><v-icon>mdi-refresh</v-icon></v-btn>
    </div>
    <v-alert v-if="drill" type="info" dense text class="gmk-abs-wrap">
      Detalle del indicador: <b>{{ drill.label }}</b> ({{ cases.length }})
      <v-btn small text color="primary" @click="clearDrill">Ver todos</v-btn>
    </v-alert>
    <div class="gmk-abs-sub mb-2" v-if="!drill">{{ cases.length }} caso(s) · {{ dueCount }} por gestionar ahora</div>

    <div v-if="loading && !cases.length" class="text-center pa-6"><v-progress-circular indeterminate color="primary"></v-progress-circular></div>
    <div v-if="!loading && !cases.length" class="text-center pa-6 gmk-abs-sub">No hay casos con este filtro.</div>

    <div class="gmk-abs-grid">
      <v-card v-for="c in cases" :key="c.id" outlined :class="['gmk-abs-card', cardClass(c)]">
        <div class="pa-3">
          <div class="d-flex align-start" style="gap: 8px;">
            <div style="flex: 1 1 auto; min-width: 0;">
              <div class="subtitle-1 font-weight-medium gmk-abs-wrap">{{ c.student }}</div>
              <div class="gmk-abs-sub">{{ c.document }}<span v-if="c.plan"> · {{ c.plan }}</span><span v-if="c.journey"> · {{ c.journey }}</span></div>
            </div>
            <v-chip x-small :color="statusColor(c.status)" dark class="flex-shrink-0">{{ c.statuslabel }}</v-chip>
          </div>
          <div class="mt-1 gmk-abs-wrap" style="font-size: 14px;">{{ c.classname }}</div>
          <div class="gmk-abs-sub">{{ c.absences }} inasistencia(s) · bloqueado {{ fmt(c.blocked_at) }} ({{ ago(c.blocked_at) }})<span v-if="c.teacher"> · {{ c.teacher }}</span></div>
          <div class="gmk-abs-sub mt-1">
            <span v-if="c.status === 'cerrado'">Cerrado {{ fmt(c.closedat) }}: {{ c.closed_reasonlabel }}</span>
            <span v-else-if="!c.actions" style="color: #e53935; font-weight: 500;">Sin gestión todavía</span>
            <span v-else>{{ c.actions }} gestión(es) · último: {{ c.last_resultlabel }} {{ ago(c.last_action_at) }} ·
              <b :style="{ color: c.due ? '#e53935' : '#2e7d32' }">seguimiento {{ fmt(c.next_followup) }}</b></span>
          </div>
          <div class="gmk-abs-contacts mt-2">
            <template v-for="(k, i) in c.contact">
              <v-btn v-if="k.type === 'phone'" :key="'t' + i" small outlined color="primary" :href="k.tel"><v-icon left small>mdi-phone</v-icon>{{ k.value }}</v-btn>
              <v-btn v-if="k.type === 'phone' && k.whatsapp" :key="'w' + i" small outlined color="green" :href="k.whatsapp" target="_blank" rel="noopener"><v-icon left small>mdi-whatsapp</v-icon>WhatsApp</v-btn>
              <v-btn v-if="k.type === 'email'" :key="'e' + i" small outlined :href="'mailto:' + k.value"><v-icon left small>mdi-email-outline</v-icon>{{ k.label }}</v-btn>
            </template>
            <span v-if="!c.contact.length" class="gmk-abs-sub">Sin datos de contacto</span>
          </div>
          <div class="d-flex mt-2">
            <v-spacer></v-spacer>
            <v-btn color="primary" depressed @click="open(c)">{{ meta.canmanage && c.status !== 'cerrado' ? 'Gestionar' : 'Ver detalle' }}<v-icon right>mdi-chevron-right</v-icon></v-btn>
          </div>
        </div>
      </v-card>
    </div>
  </div>

  <!-- SUPERVISIÓN -->
  <div v-if="meta.cansupervise" v-show="tab === 1">
    <div v-if="!stats" class="text-center pa-6"><v-progress-circular indeterminate color="primary"></v-progress-circular></div>
    <template v-else>
      <div class="gmk-abs-kpis mb-3">
        <v-card v-for="k in kpis" :key="k.key" outlined class="gmk-abs-kpi" :style="{ borderLeft: '4px solid ' + k.color }" @click="drillTo(k)">
          <div class="n" :style="{ color: k.color }">{{ k.n }}</div>
          <div class="gmk-abs-sub">{{ k.label }}</div>
        </v-card>
        <v-card outlined class="gmk-abs-kpi" style="cursor: default;">
          <div class="n">{{ stats.ontime_pct === null ? '—' : stats.ontime_pct + '%' }}</div>
          <div class="gmk-abs-sub">Contactados dentro de {{ stats.first_contact_days }} días hábiles
            <span v-if="stats.avg_first_hours !== null"> · promedio {{ stats.avg_first_hours }} h al primer contacto</span></div>
        </v-card>
      </div>
      <div class="gmk-abs-sub mb-2">Toca un indicador para ver sus casos.</div>

      <v-card outlined class="pa-3 mb-3">
        <div class="subtitle-2 mb-2">Por semana (últimas {{ stats.series.length }})</div>
        <div class="gmk-abs-bars">
          <div v-for="s in stats.series" :key="s.label" class="gmk-abs-week" :title="s.label + ': ' + s.actions + ' gestiones, ' + s.opened + ' casos nuevos, ' + s.closed + ' cerrados'">
            <div class="b">
              <span :style="{ height: (s.actions / seriesMax * 100) + 'px', background: '#1976d2' }"></span>
              <span :style="{ height: (s.opened / seriesMax * 100) + 'px', background: '#e53935' }"></span>
              <span :style="{ height: (s.closed / seriesMax * 100) + 'px', background: '#43a047' }"></span>
            </div>
            <div class="gmk-abs-sub">{{ s.label }}</div>
          </div>
        </div>
        <div class="gmk-abs-sub mt-1">
          <span style="color:#1976d2">■</span> gestiones · <span style="color:#e53935">■</span> casos nuevos · <span style="color:#43a047">■</span> cerrados por gestión
        </div>
      </v-card>

      <div class="gmk-abs-grid">
        <v-card outlined class="pa-3">
          <div class="subtitle-2 mb-2">Resultados de las gestiones</div>
          <div v-if="!stats.results.length" class="gmk-abs-sub">Todavía no hay gestiones.</div>
          <div v-for="r in stats.results" :key="r.key" class="mb-2">
            <div class="d-flex gmk-abs-sub"><span>{{ r.label }}</span><v-spacer></v-spacer><b>{{ r.n }}</b></div>
            <div class="gmk-abs-hbar" :style="{ width: (r.n / resultsMax * 100) + '%' }"></div>
          </div>
        </v-card>
        <v-card outlined class="pa-3">
          <div class="subtitle-2 mb-2">Gestiones por persona</div>
          <div v-if="!stats.byauthor.length" class="gmk-abs-sub">Todavía no hay gestiones.</div>
          <div v-for="a in stats.byauthor" :key="a.name" class="d-flex gmk-abs-sub mb-1">
            <span class="gmk-abs-wrap">{{ a.name }}</span><v-spacer></v-spacer><b>{{ a.n }}</b><span class="ml-2">última {{ ago(a.last) }}</span>
          </div>
          <div class="subtitle-2 mt-3 mb-2" v-if="stats.reasons.length">Motivos de cierre</div>
          <div v-for="r in stats.reasons" :key="r.key" class="d-flex gmk-abs-sub mb-1"><span>{{ r.label }}</span><v-spacer></v-spacer><b>{{ r.n }}</b></div>
        </v-card>
      </div>
    </template>
  </div>

  <!-- DETALLE -->
  <v-dialog v-model="dialog" max-width="760" :fullscreen="$vuetify.breakpoint.xsOnly" scrollable>
    <v-card v-if="current">
      <v-toolbar flat dense>
        <v-toolbar-title class="gmk-abs-wrap" style="font-size: 16px;">{{ current.student }}</v-toolbar-title>
        <v-spacer></v-spacer>
        <v-btn icon @click="dialog = false" aria-label="Cerrar"><v-icon>mdi-close</v-icon></v-btn>
      </v-toolbar>
      <v-card-text class="pt-2">
        <div class="gmk-abs-sub">{{ current.document }}<span v-if="current.plan"> · {{ current.plan }}</span><span v-if="current.journey"> · {{ current.journey }}</span></div>
        <div class="gmk-abs-wrap mt-1">{{ current.classname }}</div>
        <div class="gmk-abs-sub">{{ current.absences }} inasistencia(s) · bloqueado {{ fmt(current.blocked_at) }}<span v-if="current.teacher"> · {{ current.teacher }}</span></div>
        <div class="mt-1"><v-chip x-small :color="statusColor(current.status)" dark>{{ current.statuslabel }}</v-chip>
          <span class="gmk-abs-sub" v-if="current.next_followup && current.status !== 'cerrado'"> próximo seguimiento {{ fmt(current.next_followup) }}</span></div>
        <div class="gmk-abs-contacts mt-2">
          <template v-for="(k, i) in current.contact">
            <v-btn v-if="k.type === 'phone'" :key="'t' + i" small outlined color="primary" :href="k.tel"><v-icon left small>mdi-phone</v-icon>{{ k.value }}</v-btn>
            <v-btn v-if="k.type === 'phone' && k.whatsapp" :key="'w' + i" small outlined color="green" :href="k.whatsapp" target="_blank" rel="noopener"><v-icon left small>mdi-whatsapp</v-icon>WhatsApp</v-btn>
            <v-btn v-if="k.type === 'email'" :key="'e' + i" small outlined :href="'mailto:' + k.value"><v-icon left small>mdi-email-outline</v-icon>{{ k.value }}</v-btn>
          </template>
        </div>

        <v-divider class="my-3"></v-divider>
        <div v-if="meta.canmanage">
          <div class="subtitle-2 mb-2">Registrar gestión</div>
          <div class="d-flex flex-wrap" style="gap: 8px;">
            <v-select v-model="form.channel" :items="channelOptions" label="Canal" dense outlined hide-details style="flex: 1 1 140px;"></v-select>
            <v-select v-model="form.result" :items="resultOptions" label="Resultado" dense outlined hide-details style="flex: 1 1 200px;"></v-select>
          </div>
          <v-select v-if="form.result === 'correccion'" v-model="form.correctsid" :items="correctionOptions" label="Gestión que corrige" dense outlined hide-details class="mt-2"></v-select>
          <v-textarea v-model="form.note" label="Qué se habló / acuerdos" rows="2" auto-grow outlined dense hide-details class="mt-2"></v-textarea>
          <div class="d-flex flex-wrap mt-2" style="gap: 8px;">
            <v-select v-model="form.status" :items="statusOptions" label="Estado del caso" dense outlined hide-details style="flex: 1 1 160px;"></v-select>
            <v-text-field v-if="form.status !== 'cerrado'" v-model="form.next" type="date" label="Próximo seguimiento" dense outlined hide-details style="flex: 1 1 160px;"></v-text-field>
            <v-select v-else v-model="form.closed_reason" :items="reasonOptions" label="Motivo del cierre" dense outlined hide-details style="flex: 1 1 200px;"></v-select>
          </div>
          <div class="gmk-abs-sub mt-1">Hasta esa fecha el caso no aparece como pendiente en "Mis pendientes". Lo registrado no se puede editar ni borrar: si hay un error, registra una corrección.</div>
          <div class="d-flex mt-2"><v-spacer></v-spacer><v-btn color="primary" depressed :loading="saving" @click="save">Guardar gestión</v-btn></div>
          <v-divider class="my-3"></v-divider>
        </div>

        <div class="subtitle-2 mb-2">Historial ({{ current.log.length }})</div>
        <div v-if="!current.log.length" class="gmk-abs-sub">Todavía no hay gestiones.</div>
        <div v-for="l in current.log" :key="l.id" class="gmk-abs-log">
          <div class="d-flex flex-wrap" style="gap: 4px 8px;">
            <b>{{ l.resultlabel }}</b><span class="gmk-abs-sub">{{ l.channellabel }} · {{ fmt(l.time, true) }} · {{ l.author }}</span>
          </div>
          <div v-if="l.note" class="gmk-abs-wrap" style="white-space: pre-wrap;">{{ l.note }}</div>
          <div class="gmk-abs-sub">
            <span v-if="l.newstatuslabel">Estado: {{ l.newstatuslabel }}</span>
            <span v-if="l.closed_reasonlabel"> · cierre: {{ l.closed_reasonlabel }}</span>
            <span v-if="l.next_followup"> · seguimiento {{ fmt(l.next_followup) }}</span>
            <span v-if="l.corrects"> · corrige la gestión #{{ l.corrects }}</span>
          </div>
        </div>
      </v-card-text>
    </v-card>
  </v-dialog>
</div>`,
});
