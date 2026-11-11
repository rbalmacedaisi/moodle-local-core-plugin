// Proyeccion de asignaturas del proximo periodo (estilo linea de tiempo). Solo lectura.
// Recibe el resultado de demand_engine (WS local_grupomakro_planning_get_demand) y muestra, por asignatura,
// cuantos estudiantes la deben ver en cada jornada y cuantos grupos se abririan (minimo y maximo configurables).
// Una asignatura de una carrera marcada como "no se mezcla" sale en su propia fila ("solo <carrera>").
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
                <v-tooltip bottom max-width="400">
                    <template v-slot:activator="{ on }"><v-icon size="15" color="#94A3B8" v-on="on">mdi-information-outline</v-icon></template>
                    <span>Asignaturas que el sistema proyecta abrir en {{ result.period.name }}. Para cada estudiante activo se toma
                    su nivel al inicio del periodo y su plan: le toca la asignatura de su bimestre, las atrasadas que nunca cursó y
                    las reprobadas (siempre que tenga la prelación). Se agrupa por asignatura y jornada, juntando niveles y carreras,
                    salvo las carreras o asignaturas marcadas como "no se mezclan" en Configuración → Reglas de agrupación.
                    Un grupo abre con {{ min }} o más estudiantes y se divide a partir de {{ max }}.</span>
                </v-tooltip>
            </div>
            <div class="tl-proj-kpis">
                <div class="tl-proj-kpi"><b>{{ summary.subjects }}</b><span>asignaturas abren</span></div>
                <div class="tl-proj-kpi"><b>{{ summary.groups }}</b><span>grupos</span></div>
                <div class="tl-proj-kpi tl-proj-kpi-ok"><b>{{ summary.covered }}</b><span>cupos cubiertos</span></div>
                <div class="tl-proj-kpi tl-proj-kpi-warn" title="Inscripciones que quedan en asignaturas/jornadas que no alcanzan el mínimo">
                    <b>{{ summary.uncovered }}</b><span>sin quórum</span></div>
            </div>
        </div>

        <div class="tl-proj-toolbar">
            <select v-if="showCareers" v-model.number="careerFilter" class="tl-cfg-input tl-proj-career" title="Filtrar por carrera">
                <option :value="0">Todas las carreras</option>
                <option v-for="c in careerOpts" :key="c.id" :value="c.id">{{ c.short }}</option>
            </select>
            <div class="tl-panel-search" style="flex:1;max-width:300px">
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
                        <span>{{ r.levels.join(', ') }}</span>
                        <span v-if="showCareers && !r.isolated">{{ r.plans.join(' • ') }}</span>
                        <span v-if="r.repite" style="color:#DC2626">{{ r.repite }} repiten</span>
                        <span v-if="r.atrasada" style="color:#C2410C">{{ r.atrasada }} atrasados</span>
                        <span v-if="r.blocked" style="color:#B45309"><v-icon size="10" color="#B45309">mdi-lock-outline</v-icon>{{ r.blocked }} sin prelación</span>
                    </div>
                </div>
                <div class="tl-proj-jornadas">
                    <span v-for="j in r.jornadas" :key="j.jornada" class="tl-proj-chip" :class="'tl-proj-chip-' + chipSem(j)"
                          :title="chipHelp(j)">
                        <b>{{ abbr(j.jornada) }}</b>
                        <template v-if="careerFilter && j.mine !== j.ready">{{ j.mine }} de {{ j.ready }}</template>
                        <template v-else>{{ j.ready }}</template>
                        <template v-if="j.groups"> → {{ j.groups }} {{ j.groups === 1 ? 'grupo' : 'grupos' }}</template>
                        <template v-else-if="j.ready + j.adelanto >= min"> → con adelantos</template>
                        <template v-else> → no alcanza</template>
                    </span>
                </div>
                <div class="tl-proj-num"><b>{{ careerFilter ? r.mine : r.ready }}</b></div>
            </div>
            <div v-if="open[r.key]" class="tl-proj-detail">
                <div v-for="j in r.jornadas" :key="j.jornada" class="tl-proj-detail-col">
                    <div class="tl-plan-detail-block">{{ j.jornada }} • {{ j.ready }} estudiantes
                        <template v-if="j.adelanto"> (+{{ j.adelanto }} pueden adelantar)</template></div>
                    <div v-for="x in j.students" :key="x.s.lluid" class="tl-plan-name-row"
                         :class="{ 'tl-proj-other': careerFilter && x.s.planid !== careerFilter }">
                        <span :title="x.s.plan">{{ x.s.name }}</span>
                        <span class="tl-subj-meta-item" style="font-size:10px;color:#94A3B8">{{ showCareers ? shortPlan(x.s.plan) + ' · ' : '' }}{{ x.s.level }}</span>
                        <span class="tl-plan-tag" :class="'tl-plan-tag-' + x.cat">{{ catLabel[x.cat] }}</span>
                        <span v-if="x.prereq === 'no'" class="tl-plan-tag tl-plan-tag-lock">prelación</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="tl-plan-legend">
            <span><span class="tl-filter-dot tl-dot-green"></span> abre ({{ min }} o más)</span>
            <span><span class="tl-filter-dot tl-dot-orange"></span> abre si se suman adelantos</span>
            <span><span class="tl-filter-dot tl-dot-red"></span> no alcanza: alerta para revisar</span>
            <span><v-icon size="11" color="#B45309">mdi-lock</v-icon> no se mezcla con otras carreras</span>
            <span>D = Diurna · N = Nocturna · S = Sabatina</span>
        </div>
    </div>
    `,
    props: {
        result: { type: Object, required: true },
        careers: { type: Array, default: () => [] },
        scopeLabel: { type: String, default: 'todas las carreras' },
        showCareers: { type: Boolean, default: true },
        min: { type: Number, default: 12 },
        max: { type: Number, default: 30 },
    },
    data() {
        return { q: '', filter: 'all', jornada: '', careerFilter: 0, open: {},
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
                    .filter(x => x.s).sort((a, b) => a.s.name.localeCompare(b.s.name));
                const byPlan = {};
                studs.forEach(x => {
                    if (x.prereq !== 'no' && x.cat !== 'adelanto') byPlan[x.s.planid] = (byPlan[x.s.planid] || 0) + 1;
                });
                r.jornadas.push({ jornada: d.jornada || 'Sin jornada', ready: d.ready, adelanto: d.by_category.adelanto,
                    blocked: d.blocked, students: studs, byPlan, groups: d.ready >= this.min ? Math.ceil(d.ready / this.max) : 0 });
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
                groups: r.jornadas.reduce((s, j) => s + j.groups, 0),
            })).filter(r => r.ready > 0 || r.adelanto > 0);
        },
        // Filas visibles: carrera, jornada, busqueda y "abren / no alcanzan".
        scoped() {
            const cf = this.careerFilter;
            return this.allRows.map(r => {
                let js = r.jornadas;
                if (this.jornada) js = js.filter(j => j.jornada === this.jornada);
                js = js.map(j => Object.assign({}, j, { mine: cf ? (j.byPlan[cf] || 0) : j.ready }));
                if (cf) js = js.filter(j => j.mine > 0 || j.students.some(x => x.s.planid === cf));
                return Object.assign({}, r, { jornadas: js, ready: js.reduce((s, j) => s + j.ready, 0),
                    mine: js.reduce((s, j) => s + j.mine, 0), groups: js.reduce((s, j) => s + j.groups, 0) });
            }).filter(r => r.jornadas.length && (!cf || r.planIds[cf]));
        },
        filters() {
            const opens = this.scoped.filter(r => r.groups > 0).length;
            return [
                { key: 'all', label: 'Todas', count: this.scoped.length, help: 'Todas las asignaturas con demanda' },
                { key: 'open', label: 'Abren', count: opens, help: 'Al menos una jornada alcanza el mínimo' },
                { key: 'short', label: 'No alcanzan', count: this.scoped.length - opens, help: 'Ninguna jornada alcanza el mínimo: generan alertas' },
            ];
        },
        rows() {
            const q = this.q.toLowerCase();
            return this.scoped
                .filter(r => this.filter === 'all' || (this.filter === 'open' ? r.groups > 0 : r.groups === 0))
                .filter(r => !q || r.name.toLowerCase().includes(q))
                .sort((a, b) => b.groups - a.groups || (b.mine - a.mine) || b.ready - a.ready || a.name.localeCompare(b.name));
        },
        jornadaOpts() {
            const s = {};
            this.allRows.forEach(r => r.jornadas.forEach(j => { s[j.jornada] = true; }));
            return [''].concat(Object.keys(s).sort());
        },
        summary() {
            let subjects = 0, groups = 0, covered = 0, uncovered = 0;
            const cf = this.careerFilter;
            this.scoped.forEach(r => {
                if (r.groups) subjects++;
                r.jornadas.forEach(j => {
                    groups += j.groups;
                    const n = cf ? j.mine : j.ready;
                    if (j.groups) covered += n; else uncovered += n;
                });
            });
            return { subjects, groups, covered, uncovered };
        },
    },
    methods: {
        toggle(k) { this.$set(this.open, k, !this.open[k]); },
        careerName(id) {
            const c = this.careers.find(x => String(x.id) === String(id));
            return c ? this.shortPlan(c.name) : ('Plan ' + id);
        },
        rowSem(r) { return r.groups ? 'green' : (r.jornadas.some(j => j.ready + j.adelanto >= this.min) ? 'orange' : 'red'); },
        chipSem(j) { return j.groups ? 'green' : (j.ready + j.adelanto >= this.min ? 'orange' : 'red'); },
        chipHelp(j) {
            const mine = this.careerFilter && j.mine !== j.ready ? (j.mine + ' de esta carrera; ') : '';
            if (j.groups) return mine + j.ready + ' estudiantes en ' + j.jornada + ': ' + j.groups + ' grupo(s) de hasta ' + this.max + '.';
            if (j.ready + j.adelanto >= this.min) return mine + 'No alcanza el mínimo de ' + this.min + ' por sí sola; sumando ' + j.adelanto + ' estudiantes que pueden adelantarla, sí.';
            return mine + 'No alcanza el mínimo de ' + this.min + ': estos estudiantes quedarán en alertas para revisar.';
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
