// Proyeccion de asignaturas del proximo periodo (estilo linea de tiempo).
// Recibe el resultado de demand_engine (WS local_grupomakro_planning_get_demand) y muestra, por asignatura,
// cuantos estudiantes la deben ver en cada jornada y cuantos grupos se abririan (minimo y maximo configurables).
//  - Una asignatura de una carrera marcada como "no se mezcla" sale en su propia fila ("solo <carrera>").
//  - Lo que no alcanza el minimo se puede abrir por excepcion (grupo) o como modulo para estudiantes puntuales;
//    la decision se guarda en el periodo (WS local_grupomakro_planning_save_config, secciones exception_*).
//  - Los estudiantes que egresan con este periodo y quedan en una asignatura sin quorum se resaltan.
Vue.component('period-projection', {
    template: `
    <div class="tl-proj">
        <div class="tl-proj-head">
            <div class="tl-proj-title">
                <v-icon size="18" color="#4F46E5">mdi-calendar-star</v-icon>
                <span>Proyección {{ result.period.name }}</span>
                <span class="tl-proj-sub">
                    <template v-if="jornadaStarts.length">
                        <span v-for="(j, i) in jornadaStarts" :key="j.name">{{ i ? ' · ' : 'inicia ' }}{{ j.name }} {{ fmt(j.ts) }}</span>
                    </template>
                    <template v-else>inicia {{ fmt(result.period.start) }}</template>
                    • {{ careerFilter ? careerName(careerFilter) : scopeLabel }}</span>
                <v-tooltip bottom max-width="420">
                    <template v-slot:activator="{ on }"><v-icon size="15" color="#94A3B8" v-on="on">mdi-information-outline</v-icon></template>
                    <span>Asignaturas que el sistema proyecta abrir en {{ result.period.name }}. Para cada estudiante activo se toma
                    su nivel al inicio del periodo y su plan: le toca la asignatura de su bimestre, las atrasadas que nunca cursó y
                    (las reprobadas no se cargan solas: se informan aparte y se avisa al estudiante al aprobar el horario).
                    Se agrupa por asignatura y jornada, juntando niveles y carreras,
                    salvo lo marcado como "no se mezcla" en Configuración. Un grupo abre con {{ min }} o más estudiantes y se divide
                    a partir de {{ max }}. Lo que no alcanza se puede abrir por excepción o como módulo: marque las casillas.</span>
                </v-tooltip>
            </div>
            <div class="tl-proj-kpis">
                <div class="tl-proj-kpi"><b>{{ summary.subjects }}</b><span>asignaturas abren</span></div>
                <div class="tl-proj-kpi"><b>{{ summary.groups }}</b><span>grupos</span></div>
                <div class="tl-proj-kpi tl-proj-kpi-ok"><b>{{ summary.covered }}</b><span>cupos cubiertos</span></div>
                <div class="tl-proj-kpi tl-proj-kpi-warn" title="Inscripciones que quedan en asignaturas/jornadas que no alcanzan el mínimo y no tienen excepción">
                    <b>{{ summary.uncovered }}</b><span>sin quórum</span></div>
                <div class="tl-proj-kpi tl-proj-kpi-alert" :class="{ 'tl-proj-kpi-click': summary.finishingShort }" @click="summary.finishingShort && (filter = 'finish')"
                     title="Estudiantes que terminan su plan con este periodo y tienen al menos una asignatura sin quórum ni excepción. Clic para verlas.">
                    <b>{{ summary.finishingShort }}</b><span>egresan sin quórum</span></div>
            </div>
        </div>

        <div class="tl-proj-toolbar">
            <select v-if="showCareers" v-model.number="careerFilter" class="tl-cfg-input tl-proj-career" title="Filtrar por carrera">
                <option :value="0">Todas las carreras</option>
                <option v-for="c in careerOpts" :key="c.id" :value="c.id">{{ c.short }}</option>
            </select>
            <div class="tl-panel-search" style="flex:1;max-width:260px">
                <v-icon size="14" color="#94A3B8">mdi-magnify</v-icon>
                <input v-model="q" type="text" placeholder="Buscar asignatura..." class="tl-panel-search-input"/>
            </div>
            <div class="tl-plan-tabs" style="flex:0 0 auto">
                <button v-for="f in filters" :key="f.key" class="tl-plan-tab" :class="{ 'tl-plan-tab-active': filter === f.key }"
                        @click="filter = f.key" :title="f.help">{{ f.label }}<span class="tl-plan-tab-count">{{ f.count }}</span></button>
            </div>
            <div class="tl-plan-tabs" style="flex:0 0 auto">
                <button v-for="j in jornadaOpts" :key="j" class="tl-plan-tab" :class="{ 'tl-plan-tab-active': jornada === j }"
                        @click="jornada = j">{{ j || 'Todas' }}</button>
            </div>
        </div>
        <div v-if="careerFilter" class="tl-proj-filter-note">
            Mostrando las asignaturas que necesita <b>{{ careerName(careerFilter) }}</b>. En cada jornada se ve cuántos son de esta
            carrera y, si el grupo se completa con otras carreras, el total del grupo.
        </div>
        <div v-if="canEdit && selectedList.length" class="tl-proj-selbar">
            <span><b>{{ selectedList.length }}</b> seleccionada(s) sin quórum • {{ selectedStudents }} estudiantes</span>
            <button class="tl-cfg-btn" @click="openException">Abrir por excepción…</button>
            <button class="tl-cfg-btn tl-cfg-btn-ghost" @click="selected = {}">Limpiar selección</button>
        </div>

        <div class="tl-proj-grid-head">
            <span>Asignatura</span><span>Jornadas (estudiantes → grupos)</span><span class="tl-proj-num">Total</span>
        </div>
        <div v-if="!rows.length" class="tl-plan-empty" style="padding:16px">Sin asignaturas para este filtro.</div>
        <div v-for="r in rows" :key="r.key" class="tl-proj-row" :class="{ 'tl-proj-row-open': open[r.key] }">
            <div class="tl-proj-main" @click="toggle(r.key)">
                <div class="tl-subj-sem" :class="'tl-sem-' + rowSem(r)" style="align-self:stretch"></div>
                <div class="tl-proj-name">
                    <div class="tl-subj-name" :title="r.name">{{ r.name }}</div>
                    <div class="tl-subj-meta">
                        <span v-if="r.isolated" class="tl-plan-tag tl-plan-tag-lock" title="No se mezcla con otras carreras (Configuración → Reglas de agrupación)">
                            <v-icon size="9" color="#B45309">mdi-lock</v-icon> solo {{ careerName(r.isolated) }}</span>
                        <span v-if="r.finishingShort" class="tl-plan-tag tl-proj-tag-finish" title="Estudiantes que egresan con este periodo y esta asignatura no alcanza el mínimo">
                            <v-icon size="9" color="#fff">mdi-school</v-icon> {{ r.finishingShort }} egresan sin quórum</span>
                        <span>{{ r.levels.join(', ') }}</span>
                        <span v-if="showCareers && !r.isolated">{{ r.plans.join(' • ') }}</span>
                        <span v-if="r.repite" class="tl-plan-tag tl-plan-tag-repite" title="Reprobaron esta asignatura. No se cargan automáticamente: al aprobar el horario se les avisa que pueden solicitar el cargue en el área académica y pagarla. No cuentan para el mínimo del grupo.">
                            +{{ r.repite }} repitentes (gestión aparte)</span>
                        <span v-if="r.atrasada" style="color:#C2410C">{{ r.atrasada }} atrasados</span>
                        <span v-if="r.blocked" style="color:#B45309"><v-icon size="10" color="#B45309">mdi-lock-outline</v-icon>{{ r.blocked }} sin prelación</span>
                    </div>
                </div>
                <div class="tl-proj-jornadas">
                    <span v-for="j in r.jornadas" :key="j.jornada" class="tl-proj-chip" :class="'tl-proj-chip-' + chipSem(j)" :title="chipHelp(j)">
                        <input v-if="canEdit && !j.groups && !j.exception" type="checkbox" class="tl-proj-check"
                               :checked="!!selected[j.ekey]" @click.stop @change="toggleSel(r, j)">
                        <b>{{ abbr(j.jornada) }}</b>
                        <template v-if="careerFilter && j.mine !== j.ready">{{ j.mine }} de {{ j.ready }}</template>
                        <template v-else>{{ j.ready }}</template>
                        <template v-if="j.exception && j.exception.mode === 'modulo'"> → módulo ({{ j.exception.userids.length }})</template>
                        <template v-else-if="j.exception"> → excepción: abre</template>
                        <template v-else-if="j.groups"> → {{ j.groups }} {{ j.groups === 1 ? 'grupo' : 'grupos' }}</template>
                        <template v-else-if="j.ready + j.adelanto >= min"> → con adelantos</template>
                        <template v-else> → no alcanza</template>
                        <v-icon v-if="j.finishing" size="11" :color="j.exception ? '#1D4ED8' : '#B91C1C'" title="Hay estudiantes que egresan">mdi-school</v-icon>
                        <button v-if="canEdit && j.exception" class="tl-proj-unx" @click.stop="removeException(j)" title="Quitar la excepción">×</button>
                    </span>
                </div>
                <div class="tl-proj-num"><b>{{ careerFilter ? r.mine : r.ready }}</b></div>
            </div>
            <div v-if="open[r.key]" class="tl-proj-detail">
                <div v-for="j in r.jornadas" :key="j.jornada" class="tl-proj-detail-col">
                    <div class="tl-plan-detail-block">{{ j.jornada }} • {{ j.ready }} estudiantes
                        <template v-if="j.adelanto"> (+{{ j.adelanto }} pueden adelantar)</template></div>
                    <div v-if="j.exception" class="tl-proj-exc-note">
                        <v-icon size="12" color="#1D4ED8">mdi-shield-check-outline</v-icon>
                        {{ j.exception.mode === 'modulo' ? 'Módulo para ' + j.exception.userids.length + ' estudiante(s)' : 'Abre por excepción' }}
                        — {{ j.exception.note }} <span class="tl-proj-exc-by">({{ j.exception.byname }}, {{ fmt(j.exception.time) }})</span>
                    </div>
                    <div v-for="x in j.students" :key="x.s.lluid" class="tl-plan-name-row"
                         :class="{ 'tl-proj-other': careerFilter && x.s.planid !== careerFilter }">
                        <span :title="x.s.plan">{{ x.s.name }}</span>
                        <span v-if="x.s.finishing" class="tl-plan-tag tl-proj-tag-finish" title="Termina su plan con este periodo">egresa</span>
                        <span v-if="j.exception && j.exception.mode === 'modulo' && j.exception.userids.includes(x.s.userid)" class="tl-plan-tag tl-plan-tag-nivel">módulo</span>
                        <span class="tl-subj-meta-item" style="font-size:10px;color:#94A3B8">{{ showCareers ? shortPlan(x.s.plan) + ' · ' : '' }}{{ x.s.level }}</span>
                        <span class="tl-plan-tag" :class="'tl-plan-tag-' + x.cat">{{ catLabel[x.cat] }}</span>
                        <span v-if="x.cat === 'repite'" class="tl-plan-tag tl-plan-tag-muted" title="No se carga automáticamente; recibe el aviso cuando se apruebe el horario">aviso al aprobar</span>
                        <span v-if="x.prereq === 'no'" class="tl-plan-tag tl-plan-tag-lock">prelación</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="tl-plan-legend">
            <span><span class="tl-filter-dot tl-dot-green"></span> abre ({{ min }} o más)</span>
            <span><span class="tl-filter-dot tl-dot-blue"></span> abre por excepción o como módulo</span>
            <span><span class="tl-filter-dot tl-dot-orange"></span> abre si se suman adelantos</span>
            <span><span class="tl-filter-dot tl-dot-red"></span> no alcanza: alerta para revisar</span>
            <span><v-icon size="11" color="#B91C1C">mdi-school</v-icon> hay estudiantes que egresan</span>
            <span><v-icon size="11" color="#B45309">mdi-lock</v-icon> no se mezcla con otras carreras</span>
            <span>D = Diurna · N = Nocturna · S = Sabatina</span>
        </div>

        <!-- Excepcion: abrir sin quorum o como modulo -->
        <v-dialog v-model="exc.show" max-width="640" scrollable>
            <v-card>
                <v-card-title class="text-subtitle-1 font-weight-bold">Abrir por excepción</v-card-title>
                <v-card-text>
                    <div class="tl-cfg-help mb-3">La decisión queda registrada en el periodo {{ result.period.name }} con su motivo.
                    Las clases se crean después, al generar y publicar los horarios.</div>
                    <div class="tl-plan-tabs mb-3">
                        <button class="tl-plan-tab" :class="{ 'tl-plan-tab-active': exc.mode === 'grupo' }" @click="exc.mode = 'grupo'">
                            Abrir el grupo aunque no alcance el mínimo</button>
                        <button class="tl-plan-tab" :class="{ 'tl-plan-tab-active': exc.mode === 'modulo' }" @click="exc.mode = 'modulo'">
                            Abrir como módulo para estudiantes puntuales</button>
                    </div>
                    <div v-for="it in exc.items" :key="it.ekey" class="tl-proj-exc-item">
                        <div><b>{{ it.name }}</b> <span class="tl-cfg-help">• {{ it.jornada }}{{ it.isolated ? ' • solo ' + careerName(it.isolated) : '' }} • {{ it.students.length }} estudiantes</span></div>
                        <div v-if="exc.mode === 'modulo'" class="tl-proj-exc-students">
                            <label v-for="x in it.students" :key="x.s.userid + '-' + x.s.planid" class="tl-cfg-check">
                                <input type="checkbox" :value="x.s.userid" v-model="it.userids">
                                {{ x.s.name }} <span class="tl-cfg-help">{{ shortPlan(x.s.plan) }} · {{ x.s.level }}</span>
                                <span v-if="x.s.finishing" class="tl-plan-tag tl-proj-tag-finish">egresa</span>
                            </label>
                        </div>
                    </div>
                    <label class="tl-cfg-fields mt-3" style="display:block">
                        <span style="font-size:13px;font-weight:600">Motivo (obligatorio)</span>
                        <textarea v-model="exc.note" rows="2" class="tl-cfg-input" style="width:100%" placeholder="Ej.: estudiantes por egresar; autorizado por Dirección Académica"></textarea>
                    </label>
                    <div v-if="exc.error" class="tl-cfg-msg tl-cfg-msg-error mt-2">{{ exc.error }}</div>
                </v-card-text>
                <v-card-actions><v-spacer></v-spacer>
                    <v-btn text @click="exc.show = false">Cancelar</v-btn>
                    <v-btn color="primary" depressed :loading="exc.saving" @click="saveException">Registrar excepción</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
    `,
    props: {
        result: { type: Object, required: true },
        careers: { type: Array, default: () => [] },
        scopeLabel: { type: String, default: 'todas las carreras' },
        showCareers: { type: Boolean, default: true },
        canEdit: { type: Boolean, default: true },
        min: { type: Number, default: 12 },
        max: { type: Number, default: 30 },
    },
    data() {
        return { q: '', filter: 'all', jornada: '', careerFilter: 0, open: {}, selected: {},
            exc: { show: false, mode: 'grupo', items: [], note: '', error: '', saving: false },
            catLabel: { nivel: 'nivel', atrasada: 'atrasada', repite: 'repite', adelanto: 'adelanto' } };
    },
    computed: {
        jornadaStarts() {
            const js = this.result.period.jornada_starts || {};
            return Object.keys(js).map(k => ({ name: k, ts: js[k] })).sort((a, b) => a.ts - b.ts);
        },
        careerOpts() {
            return this.careers.map(c => ({ id: Number(c.id), short: this.shortPlan(c.name) }))
                .sort((a, b) => a.short.localeCompare(b.short));
        },
        exceptionMap() {
            const m = {};
            (this.result.exceptions || []).forEach(e => { m[e.key] = e; });
            return m;
        },
        // Una fila por asignatura (curso Moodle) y, si esta aislada, por carrera; con su detalle por jornada.
        allRows() {
            const students = this.result.students || {};
            const by = {};
            (this.result.demand || []).forEach(d => {
                const iso = Number(d.isolated_plan || 0);
                const key = d.courseid + '|' + iso;
                const r = by[key] || (by[key] = { key, courseid: d.courseid, name: d.name, isolated: iso, ready: 0, adelanto: 0,
                    blocked: 0, repite: 0, atrasada: 0, levels: {}, plans: {}, planIds: {}, jornadas: [] });
                const studs = d.students.map(x => ({ s: students[x.key], cat: x.category, prereq: x.prereq }))
                    .filter(x => x.s).sort((a, b) => (b.s.finishing - a.s.finishing) || a.s.name.localeCompare(b.s.name));
                const byPlan = {};
                let finishing = 0;
                const loads = x => x.prereq !== 'no' && (x.cat === 'nivel' || x.cat === 'atrasada');
                studs.forEach(x => {
                    if (loads(x)) {
                        byPlan[x.s.planid] = (byPlan[x.s.planid] || 0) + 1;
                        if (x.s.finishing) finishing++;
                    }
                });
                const jornada = d.jornada || 'Sin jornada';
                const ekey = d.courseid + '|' + (d.jornada || '') + '|' + iso;
                const exception = this.exceptionMap[ekey] || null;
                const groups = d.ready >= this.min ? Math.ceil(d.ready / this.max) : 0;
                r.jornadas.push({ jornada, ekey, ready: d.ready, adelanto: d.by_category.adelanto, blocked: d.blocked,
                    students: studs, byPlan, finishing, exception, groups,
                    readyStudents: studs.filter(loads), repite: d.by_category.repite });
                r.ready += d.ready;
                r.adelanto += d.by_category.adelanto;
                r.blocked += d.blocked;
                r.repite += d.by_category.repite;
                r.atrasada += d.by_category.atrasada;
                Object.keys(d.levels).forEach(l => { r.levels[l] = true; });
                Object.keys(d.plans).forEach(p => { r.plans[this.careerName(p)] = true; r.planIds[p] = true; });
            });
            return Object.values(by).map(r => Object.assign(r, {
                levels: Object.keys(r.levels).sort(),
                plans: Object.keys(r.plans),
                jornadas: r.jornadas.sort((a, b) => b.ready - a.ready),
            })).filter(r => r.ready > 0 || r.adelanto > 0);
        },
        // Filas visibles: carrera, jornada y busqueda (los conteos se recalculan con lo visible).
        scoped() {
            const cf = this.careerFilter;
            return this.allRows.map(r => {
                let js = r.jornadas;
                if (this.jornada) js = js.filter(j => j.jornada === this.jornada);
                js = js.map(j => Object.assign({}, j, { mine: cf ? (j.byPlan[cf] || 0) : j.ready,
                    finishingShort: (!j.groups && !j.exception) ? j.readyStudents.filter(x => x.s.finishing && (!cf || x.s.planid === cf)).length : 0 }));
                if (cf) js = js.filter(j => j.mine > 0 || j.students.some(x => x.s.planid === cf));
                const opens = js.filter(j => j.groups || j.exception);
                return Object.assign({}, r, { jornadas: js, ready: js.reduce((s, j) => s + j.ready, 0),
                    mine: js.reduce((s, j) => s + j.mine, 0), groups: js.reduce((s, j) => s + j.groups, 0),
                    opens: opens.length, finishingShort: js.reduce((s, j) => s + j.finishingShort, 0),
                    repite: js.reduce((s, j) => s + (cf ? j.students.filter(x => x.cat === 'repite' && x.s.planid === cf).length : j.repite), 0) });
            }).filter(r => r.jornadas.length && (!cf || r.planIds[cf]));
        },
        filters() {
            const opens = this.scoped.filter(r => r.opens > 0).length;
            const fin = this.scoped.filter(r => r.finishingShort > 0).length;
            return [
                { key: 'all', label: 'Todas', count: this.scoped.length, help: 'Todas las asignaturas con demanda' },
                { key: 'open', label: 'Abren', count: opens, help: 'Al menos una jornada alcanza el mínimo o tiene excepción' },
                { key: 'short', label: 'No alcanzan', count: this.scoped.filter(r => r.jornadas.some(j => !j.groups && !j.exception)).length,
                    help: 'Tienen al menos una jornada sin quórum ni excepción' },
                { key: 'finish', label: 'Egresan sin quórum', count: fin, help: 'Hay estudiantes que terminan su plan y la asignatura no alcanza el mínimo' },
                { key: 'repite', label: 'Repitentes', count: this.scoped.filter(r => r.repite > 0).length,
                    help: 'Asignaturas con estudiantes que la reprobaron: gestión aparte, se les avisa al aprobar el horario' },
            ];
        },
        rows() {
            const q = this.q.toLowerCase();
            const f = this.filter;
            return this.scoped
                .filter(r => f === 'all' || (f === 'open' ? r.opens > 0 : f === 'finish' ? r.finishingShort > 0
                    : f === 'repite' ? r.repite > 0 : r.jornadas.some(j => !j.groups && !j.exception)))
                .filter(r => !q || r.name.toLowerCase().includes(q))
                .sort((a, b) => (f === 'finish' ? b.finishingShort - a.finishingShort : 0) || b.groups - a.groups
                    || (b.mine - a.mine) || b.ready - a.ready || a.name.localeCompare(b.name));
        },
        jornadaOpts() {
            const s = {};
            this.allRows.forEach(r => r.jornadas.forEach(j => { s[j.jornada] = true; }));
            return [''].concat(Object.keys(s).sort());
        },
        summary() {
            let subjects = 0, groups = 0, covered = 0, uncovered = 0;
            const cf = this.careerFilter;
            const finishing = new Set();
            this.scoped.forEach(r => {
                if (r.opens) subjects++;
                r.jornadas.forEach(j => {
                    const n = cf ? j.mine : j.ready;
                    if (j.groups) { groups += j.groups; covered += n; }
                    else if (j.exception) { groups += 1; covered += j.exception.mode === 'modulo' ? j.exception.userids.length : n; }
                    else {
                        uncovered += n;
                        j.readyStudents.forEach(x => { if (x.s.finishing && (!cf || x.s.planid === cf)) finishing.add(x.s.lluid); });
                    }
                });
            });
            return { subjects, groups, covered, uncovered, finishingShort: finishing.size };
        },
        selectedList() { return Object.values(this.selected); },
        selectedStudents() { return this.selectedList.reduce((s, it) => s + it.students.length, 0); },
    },
    methods: {
        toggle(k) { this.$set(this.open, k, !this.open[k]); },
        toggleSel(r, j) {
            if (this.selected[j.ekey]) { this.$delete(this.selected, j.ekey); return; }
            this.$set(this.selected, j.ekey, { ekey: j.ekey, courseid: r.courseid, name: r.name, jornada: j.jornada,
                isolated: r.isolated, students: j.readyStudents });
        },
        openException() {
            this.exc = { show: true, mode: this.selectedList.some(it => it.students.some(x => x.s.finishing)) ? 'modulo' : 'grupo',
                note: '', error: '', saving: false,
                items: this.selectedList.map(it => Object.assign({}, it, {
                    // Por defecto se proponen para modulo los estudiantes que egresan; si no hay, todos.
                    userids: (it.students.some(x => x.s.finishing) ? it.students.filter(x => x.s.finishing) : it.students)
                        .map(x => x.s.userid).filter((v, i, a) => a.indexOf(v) === i) })) };
        },
        async ws(section, data) {
            const body = new URLSearchParams({ wstoken: window.userToken, moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_planning_save_config', section, data: JSON.stringify(data) });
            const r = await window.axios.post(window.location.origin + '/webservice/rest/server.php', body);
            if (r.data && r.data.exception) throw new Error(r.data.message || r.data.errorcode);
            return r.data;
        },
        async saveException() {
            this.exc.error = '';
            if (!this.exc.note.trim()) { this.exc.error = 'Indique el motivo de la excepción.'; return; }
            if (this.exc.mode === 'modulo' && this.exc.items.some(it => !it.userids.length)) {
                this.exc.error = 'Elija al menos un estudiante en cada asignatura para abrirla como módulo.'; return;
            }
            this.exc.saving = true;
            try {
                const r = await this.ws('exception_add', { periodid: this.result.period.id, mode: this.exc.mode, note: this.exc.note,
                    items: this.exc.items.map(it => ({ courseid: it.courseid, jornada: it.jornada === 'Sin jornada' ? '' : it.jornada,
                        isolated_plan: it.isolated, userids: this.exc.mode === 'modulo' ? it.userids : [] })) });
                if (!r.ok) { this.exc.error = r.message; return; }
                this.exc.show = false; this.selected = {};
                this.$emit('changed', r.message);
            } catch (e) { this.exc.error = 'Error al guardar: ' + (e.message || e); }
            finally { this.exc.saving = false; }
        },
        async removeException(j) {
            if (!window.confirm('¿Quitar la excepción de ' + j.exception.course + ' (' + j.jornada + ')? Volverá a quedar como "no alcanza".')) return;
            try {
                const r = await this.ws('exception_remove', { periodid: this.result.period.id, keys: [j.ekey] });
                if (r.ok) this.$emit('changed', r.message); else window.alert(r.message);
            } catch (e) { window.alert('Error al quitar: ' + (e.message || e)); }
        },
        careerName(id) {
            const c = this.careers.find(x => String(x.id) === String(id));
            return c ? this.shortPlan(c.name) : ('Plan ' + id);
        },
        rowSem(r) {
            if (r.groups) return 'green';
            if (r.opens) return 'blue';
            return r.jornadas.some(j => j.ready + j.adelanto >= this.min) ? 'orange' : 'red';
        },
        chipSem(j) { return j.groups ? 'green' : (j.exception ? 'blue' : (j.ready + j.adelanto >= this.min ? 'orange' : 'red')); },
        chipHelp(j) {
            const mine = this.careerFilter && j.mine !== j.ready ? (j.mine + ' de esta carrera; ') : '';
            const fin = j.finishing ? ' ' + j.finishing + ' estudiante(s) egresan con este periodo.' : '';
            if (j.exception) return mine + (j.exception.mode === 'modulo' ? 'Módulo para ' + j.exception.userids.length + ' estudiante(s). ' : 'Abre por excepción. ')
                + 'Motivo: ' + j.exception.note + fin;
            if (j.groups) return mine + j.ready + ' estudiantes en ' + j.jornada + ': ' + j.groups + ' grupo(s) de hasta ' + this.max + '.' + fin;
            if (j.ready + j.adelanto >= this.min) return mine + 'No alcanza el mínimo de ' + this.min + ' por sí sola; sumando ' + j.adelanto + ' estudiantes que pueden adelantarla, sí.' + fin;
            return mine + 'No alcanza el mínimo de ' + this.min + '. Marque la casilla para abrirla por excepción o como módulo.' + fin;
        },
        abbr(j) { return j && j !== 'Sin jornada' ? j.charAt(0) : '?'; },
        shortPlan(name) {
            let n = String(name || '').replace(/^T[ÉE]CNICO SUPERIOR EN\s+/i, '').replace(/^CURSO DE\s+/i, '');
            n = n.charAt(0) + n.slice(1).toLowerCase();
            return n.length > 30 ? n.slice(0, 28) + '…' : n;
        },
        fmt(ts) { return ts ? new Date(ts * 1000).toLocaleDateString('es-PA', { day: '2-digit', month: 'short' }) : '-'; },
    },
});
