/**
 * SupportAssistant.js
 * Mesa de ayuda (20261001126): pestana "Asistente IA" de la configuracion.
 * Muestra lo que el asistente semanal (agente externo, ver
 * docs/asistente/AGENTE_SEMANAL.md) dejo importado: sugerencias por area con
 * su evidencia, tipificacion de los tickets e historial de ejecuciones.
 * Anadir / Descartar / Recuperar llaman a local_grupomakro_support_ai_action;
 * "Editar y anadir" de un problema comun lo abre el dialogo de problemas
 * comunes del padre (evento edit); el de una respuesta rapida, el de aqui.
 * Mobile first: tarjetas a ancho completo, botones de 44 px.
 */
Vue.component('support-assistant', {
    props: { areas: { type: Array, default: () => [] }, categories: { type: Array, default: () => [] } },
    template: `
    <div class="gmk-ai">
      <v-progress-linear v-if="loading" indeterminate class="mb-2"></v-progress-linear>
      <p class="text--secondary">Cada semana el asistente lee cómo se cerraron los tickets (ya anonimizados en el servidor, sin notas internas y sin Psicología), los tipifica y propone mejoras. Nada llega a estudiantes ni docentes hasta que alguien pulsa <strong>Añadir</strong>.</p>
      <div v-if="d && lastRun" class="text-caption mb-3">
        Última ejecución: {{ fmt.date(lastRun.timecreated) }} · {{ lastRun.classified }} ticket(s) tipificados · {{ lastRun.suggestions }} sugerencia(s)
        <span v-if="lastRun.model"> · {{ lastRun.model }}</span>
      </div>
      <v-alert v-if="d && !d.runs.length" type="info" text dense>Todavía no hay ejecuciones del asistente.</v-alert>

      <v-btn-toggle v-model="view" mandatory dense class="mb-3 gmk-ai-toggle" color="primary">
        <v-btn value="pending" small>Sugerencias<v-chip v-if="pending.length" x-small class="ml-1">{{ pending.length }}</v-chip></v-btn>
        <v-btn value="types" small>Tipificación</v-btn>
        <v-btn value="runs" small>Historial</v-btn>
      </v-btn-toggle>

      <template v-if="d && view === 'pending'">
        <div class="d-flex flex-wrap mb-2">
          <v-chip small class="mr-1 mb-1" :color="areaFilter === 0 ? 'primary' : ''" :text-color="areaFilter === 0 ? 'white' : ''" @click="areaFilter = 0">Todas</v-chip>
          <v-chip v-for="a in d.areas" :key="a.id" small class="mr-1 mb-1" :color="areaFilter === a.id ? 'primary' : ''"
            :text-color="areaFilter === a.id ? 'white' : ''" @click="areaFilter = a.id">{{ a.name }} ({{ pendingOf(a.id).length }})</v-chip>
        </div>
        <p v-if="!shown.length" class="text-caption text--secondary">Sin sugerencias pendientes.</p>
        <v-card v-for="sg in shown" :key="sg.id" outlined class="mb-3 pa-3">
          <div class="d-flex flex-wrap align-center mb-1">
            <v-chip x-small :color="kindColor(sg.kind)" text-color="white" class="mr-2 mb-1">{{ sg.kindlabel }}</v-chip>
            <span class="text-caption text--secondary mb-1">{{ areaName(sg.areaid) }}<span v-if="sg.categoryid"> / {{ topicName(sg.categoryid) }}</span></span>
          </div>
          <div class="font-weight-medium gmk-wrap mb-1">{{ sg.title }}</div>
          <div class="text-caption mb-2">
            <v-icon x-small>mdi-ticket-outline</v-icon> Respaldada por <strong>{{ sg.evidence }}</strong> ticket(s)
            <span v-if="sg.stats && sg.stats.avgrating !== null && sg.stats.avgrating !== undefined"> · calificación promedio {{ sg.stats.avgrating }}/5</span>
            <span v-if="sg.stats && sg.stats.solvedpct !== null && sg.stats.solvedpct !== undefined"> · {{ sg.stats.solvedpct }}% dijo que se resolvió</span>
            <span v-if="sg.stats && sg.stats.reopened"> · {{ sg.stats.reopened }} reabierto(s)</span>
          </div>
          <div v-if="sg.rationale" class="text-body-2 gmk-wrap mb-2">{{ sg.rationale }}</div>

          <div v-if="sg.kind === 'faq_improve' && sg.current" class="mb-2">
            <div class="text-caption">Ahora: vistas {{ sg.current.views }} · se solucionó {{ sg.current.solved }} · <span class="red--text">siguió con el problema {{ sg.current.notsolved }}</span></div>
            <v-expansion-panels flat accordion class="mt-1">
              <v-expansion-panel>
                <v-expansion-panel-header class="px-2 text-caption">Ver la respuesta actual</v-expansion-panel-header>
                <v-expansion-panel-content><div class="gmk-faq-answer pa-2" v-html="sg.current.answerhtml"></div></v-expansion-panel-content>
              </v-expansion-panel>
            </v-expansion-panels>
          </div>
          <div class="text-caption text--secondary mb-1">{{ sg.kind === 'canned' ? 'Texto para el agente' : (sg.kind === 'faq_improve' ? 'Respuesta propuesta' : 'Respuesta') }}</div>
          <div class="gmk-faq-answer pa-2 mb-2" v-html="sg.bodyhtml"></div>
          <div v-if="sg.links && sg.links.length" class="mb-2">
            <v-chip v-for="(l, i) in sg.links" :key="i" x-small outlined class="mr-1 mb-1"><v-icon x-small left>{{ linkIcon(l.kind) }}</v-icon>{{ l.label }}</v-chip>
          </div>
          <v-expansion-panels v-if="sg.examples && sg.examples.length" flat accordion class="mb-2">
            <v-expansion-panel>
              <v-expansion-panel-header class="px-2 text-caption">Ejemplos anonimizados ({{ sg.examples.length }})</v-expansion-panel-header>
              <v-expansion-panel-content>
                <div v-for="(ex, i) in sg.examples" :key="i" class="text-caption gmk-wrap mb-2 gmk-ai-example">«{{ ex }}»</div>
              </v-expansion-panel-content>
            </v-expansion-panel>
          </v-expansion-panels>
          <div class="gmk-ai-actions">
            <v-btn depressed color="primary" :loading="busy === sg.id" @click="add(sg)"><v-icon left small>mdi-plus</v-icon>Añadir</v-btn>
            <v-btn outlined color="primary" :disabled="busy === sg.id" @click="edit(sg)"><v-icon left small>mdi-pencil</v-icon>Editar y añadir</v-btn>
            <v-btn text color="grey darken-1" :disabled="busy === sg.id" @click="dismiss(sg)">Descartar</v-btn>
          </div>
        </v-card>

        <div v-if="resolved.length" class="mt-4">
          <div class="text-subtitle-2 mb-1">Atendidas en los últimos 30 días</div>
          <v-card v-for="sg in resolved" :key="'r' + sg.id" outlined class="mb-2 pa-2">
            <div class="d-flex align-center">
              <div class="flex-grow-1 gmk-min0">
                <div class="text-body-2 gmk-wrap">{{ sg.title }}</div>
                <div class="text-caption text--secondary">{{ sg.kindlabel }} · {{ areaName(sg.areaid) }} ·
                  <span :class="sg.status === 'added' ? 'success--text' : ''">{{ sg.status === 'added' ? 'Añadida' : 'Descartada' }}</span>
                  por {{ sg.resolvedby || '—' }} · {{ fmt.date(sg.timeresolved) }}</div>
              </div>
              <v-btn v-if="sg.status === 'dismissed'" small text color="primary" :loading="busy === sg.id" @click="restore(sg)">Recuperar</v-btn>
            </div>
          </v-card>
        </div>
      </template>

      <template v-if="d && view === 'types'">
        <p class="text-caption text--secondary">Cuántos tickets hubo por tipo de problema (últimas 4 semanas y total), cómo se resolvieron, si el asistente considera que se resolvió y la calificación del estudiante.</p>
        <div v-for="a in d.areas" :key="'t' + a.id" class="mb-4">
          <div class="text-subtitle-1 font-weight-medium mb-1">{{ a.name }}</div>
          <p v-if="!typesOf(a.id).length" class="text-caption text--secondary mb-0">Sin tickets tipificados.</p>
          <v-simple-table v-else dense class="gmk-stack">
            <thead><tr><th>Problema</th><th>4 semanas</th><th>Total</th><th>Resuelto</th><th>Calificación</th><th>Cómo se resolvió</th></tr></thead>
            <tbody>
              <tr v-for="t in typesOf(a.id)" :key="t.problemtype">
                <td class="gmk-wrap">{{ t.problemlabel || t.problemtype }}</td>
                <td>{{ t.recent }}</td><td>{{ t.total }}</td>
                <td>{{ t.resolvedpct }}%</td>
                <td>{{ t.avgrating !== null ? t.avgrating + '/5' : '—' }}</td>
                <td class="text-caption gmk-wrap">{{ t.resolutions.map(r => r.label + ' (' + r.n + ')').join(' · ') }}</td>
              </tr>
            </tbody>
          </v-simple-table>
        </div>
      </template>

      <template v-if="d && view === 'runs'">
        <v-simple-table dense class="gmk-stack">
          <thead><tr><th>Cuándo</th><th>Periodo</th><th>Tickets</th><th>Sugerencias</th><th>Modelo</th><th>Tokens</th><th>Costo</th></tr></thead>
          <tbody>
            <tr v-for="r in d.runs" :key="r.id">
              <td class="text-no-wrap">{{ fmt.date(r.timecreated) }}</td>
              <td class="text-caption">{{ r.periodfrom ? fmt.date(r.periodfrom) : '—' }} – {{ r.periodto ? fmt.date(r.periodto) : '—' }}</td>
              <td>{{ r.classified }}<span v-if="r.skipped" class="text--secondary"> (+{{ r.skipped }} omitidos)</span></td>
              <td>{{ r.suggestions }}</td>
              <td class="text-caption">{{ r.model || '—' }}</td>
              <td class="text-caption">{{ r.tokensin || r.tokensout ? (r.tokensin + ' / ' + r.tokensout) : '—' }}</td>
              <td class="text-caption">{{ r.costusd !== null ? 'US$' + r.costusd : 'Suscripción' }}</td>
            </tr>
          </tbody>
        </v-simple-table>
        <div v-for="r in d.runs.filter(x => x.summary).slice(0, 3)" :key="'s' + r.id" class="text-caption gmk-wrap mt-2">
          <strong>{{ fmt.date(r.timecreated) }}:</strong> {{ r.summary }}
        </div>
      </template>

      <v-dialog v-model="cannedDialog.open" max-width="640" :fullscreen="$vuetify.breakpoint.xsOnly" scrollable>
        <v-card v-if="cannedDialog.row">
          <v-card-title>Respuesta rápida · {{ areaName(cannedDialog.row.areaid) }}</v-card-title>
          <v-card-text>
            <v-text-field v-model="cannedDialog.row.title" label="Título" outlined dense counter="150" maxlength="150"></v-text-field>
            <v-textarea v-model="cannedDialog.row.body" label="Texto" outlined auto-grow rows="5"></v-textarea>
          </v-card-text>
          <v-card-actions>
            <v-spacer></v-spacer>
            <v-btn text @click="cannedDialog.open = false">Cancelar</v-btn>
            <v-btn color="primary" depressed :loading="busy === cannedDialog.row.id" @click="saveCanned">Añadir</v-btn>
          </v-card-actions>
        </v-card>
      </v-dialog>
    </div>`,
    data() {
        return {
            fmt: window.gmkSupportFormat, d: null, loading: false, busy: 0, view: 'pending', areaFilter: 0,
            cannedDialog: { open: false, row: null },
        };
    },
    computed: {
        pending() { return this.d ? this.d.suggestions.filter(s => s.status === 'pending') : []; },
        resolved() { return this.d ? this.d.suggestions.filter(s => s.status !== 'pending' && (!this.areaFilter || s.areaid === this.areaFilter)) : []; },
        shown() { return this.areaFilter ? this.pendingOf(this.areaFilter) : this.pending; },
        lastRun() { return this.d && this.d.runs.length ? this.d.runs[0] : null; },
    },
    mounted() { this.load(); },
    methods: {
        async load() {
            this.loading = true;
            try {
                this.setData(await window.gmkSupportCall('local_grupomakro_support_ai_get', {}));
            } catch (e) {
                this.$emit('notify', e.message, 'error');
            } finally {
                this.loading = false;
            }
        },
        setData(data) { this.d = JSON.parse(data.json); },
        pendingOf(areaid) { return this.pending.filter(s => s.areaid === areaid); },
        typesOf(areaid) { return this.d.taxonomy.filter(t => t.areaid === areaid); },
        areaName(id) {
            const a = this.areas.find(x => x.id === id) || (this.d ? this.d.areas.find(x => x.id === id) : null);
            return a ? a.name : '';
        },
        topicName(id) { const c = this.categories.find(x => x.id === id); return c ? c.name : ''; },
        kindColor(k) { return { faq_new: 'teal', faq_improve: 'orange darken-2', canned: 'indigo' }[k] || 'grey'; },
        linkIcon(k) { return { appstore: 'mdi-apple', playstore: 'mdi-google-play' }[k] || 'mdi-link'; },
        async call(sg, op, payload) {
            this.busy = sg.id;
            try {
                const r = await window.gmkSupportCall('local_grupomakro_support_ai_action', {
                    id: sg.id, op: op, payload: payload ? JSON.stringify(payload) : '',
                });
                this.setData(r);
                this.$emit('changed');
                this.$emit('notify', { add: 'Añadido', dismiss: 'Descartada', restore: 'Recuperada' }[op]);
                return true;
            } catch (e) {
                this.$emit('notify', e.message, 'error');
                return false;
            } finally {
                this.busy = 0;
            }
        },
        async add(sg) {
            const what = sg.kind === 'faq_improve' ? 'Se reemplazará la respuesta actual de este problema común.'
                : (sg.kind === 'canned' ? 'Se creará la respuesta rápida para los agentes del área.'
                    : 'Se publicará como problema común activo del área.');
            if (!(await this.confirm(what + ' ¿Continuar?'))) return;
            await this.call(sg, 'add', null);
        },
        edit(sg) {
            if (sg.kind === 'canned') {
                this.cannedDialog = { open: true, row: { id: sg.id, areaid: sg.areaid, title: sg.title, body: sg.body } };
                return;
            }
            this.$emit('edit', sg);
        },
        async saveCanned() {
            const r = this.cannedDialog.row;
            const sg = this.d.suggestions.find(s => s.id === r.id);
            if (await this.call(sg, 'add', { title: r.title, body: r.body })) this.cannedDialog.open = false;
        },
        async dismiss(sg) {
            if (!(await this.confirm('¿Descartar esta sugerencia? El asistente no la volverá a proponer en 60 días.'))) return;
            await this.call(sg, 'dismiss', null);
        },
        async restore(sg) { await this.call(sg, 'restore', null); },
        async confirm(text) {
            if (this.$swal) {
                const r = await this.$swal.fire({ text: text, icon: 'question', showCancelButton: true, confirmButtonText: 'Sí', cancelButtonText: 'Cancelar' });
                return r.isConfirmed;
            }
            return window.confirm(text);
        },
    },
});
