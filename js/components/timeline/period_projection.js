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

        <div class="tl-plan-tabs tl-proj-views">
            <button class="tl-plan-tab" :class="{ 'tl-plan-tab-active': view === 'next' }" @click="view = 'next'">
                <v-icon size="14">mdi-calendar-star</v-icon> Próximo periodo ({{ result.period.name }})</button>
            <button class="tl-plan-tab" :class="{ 'tl-plan-tab-active': view === 'future' }" @click="openFuture">
                <v-icon size="14">mdi-calendar-multiple</v-icon> Próximos periodos</button>
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
            <div v-if="view === 'next'" class="tl-plan-tabs" style="flex:0 0 auto">
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

        <!-- PROXIMOS PERIODOS: matriz asignatura x periodo -->
        <template v-if="view === 'future'">
            <div class="tl-proj-filter-note">
                Asignaturas que le tocarían a los estudiantes activos en cada periodo siguiente, suponiendo que avanzan un bimestre
                por periodo y aprueban lo de su nivel (las atrasadas y reprobadas se resuelven aparte). Se recalcula cada vez:
                los estudiantes que ingresen se suman solos. Los periodos que aún no existen en el calendario van como "estimado".
                Celda verde: alguna jornada alcanza {{ min }}; ámbar: entre {{ Math.ceil(min / 2) }} y {{ min - 1 }}; roja: menos.
            </div>
            <div v-if="futureLoading" class="tl-plan-empty" style="padding:16px">Calculando periodos siguientes…</div>
            <div v-else-if="futureError" class="tl-cfg-msg tl-cfg-msg-error">{{ futureError }}</div>
            <div v-else-if="future" class="tl-proj-matrix-wrap">
                <table class="tl-proj-matrix">
                    <thead><tr>
                        <th class="tl-proj-mx-subj">Asignatura</th>
                        <th v-for="c in futureCols" :key="c.key" :title="c.estimated ? 'Periodo aún no creado: fecha estimada' : ''">
                            {{ c.name }}<div class="tl-proj-mx-date">{{ fmt(c.start) }}{{ c.estimated ? ' · est.' : '' }}</div>
                            <div class="tl-proj-mx-groups">{{ colGroups[c.key] || 0 }} grupos</div></th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="r in futureRows" :key="r.key">
                            <td class="tl-proj-mx-subj"><div class="tl-subj-name" :title="r.name">{{ r.name }}</div>
                                <div class="tl-subj-meta"><span v-if="r.isolated" class="tl-plan-tag tl-plan-tag-lock">solo {{ careerName(r.isolated) }}</span>
                                    <span v-if="r.flex" class="tl-plan-tag tl-plan-tag-muted">no obligatoria</span>
                                    <span>{{ r.levels.join(', ') }}</span></div></td>
                            <td v-for="c in futureCols" :key="c.key" class="tl-proj-mx-cell" :class="cellClass(r.cells[c.key])"
                                @click="r.cells[c.key] && showCell(r, c)" :title="cellTitle(r.cells[c.key])">
                                <template v-if="r.cells[c.key]">
                                    <b>{{ r.cells[c.key].count }}</b>
                                    <span v-if="r.cells[c.key].flex" class="tl-proj-mx-flex">+{{ r.cells[c.key].flex }}</span>
                                    <div class="tl-proj-mx-g">{{ cellGroupsLabel(r.cells[c.key]) }}</div>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-if="!futureRows.length" class="tl-plan-empty" style="padding:16px">Sin asignaturas para este filtro.</div>
            </div>
            <v-dialog :value="!!cellDetail" @input="v => { if (!v) cellDetail = null; }" max-width="640" scrollable>
                <v-card v-if="cellDetail">
                    <v-card-title class="d-flex">
                        <div>
                            <div class="text-subtitle-1 font-weight-bold">{{ cellDetail.name }}</div>
                            <div class="tl-cfg-help">{{ cellDetail.period }} • {{ cellDetail.students.length }} estudiantes • {{ cellDetail.groupsLabel }}</div>
                        </div>
                        <v-spacer></v-spacer>
                        <v-btn icon @click="cellDetail = null"><v-icon>mdi-close</v-icon></v-btn>
                    </v-card-title>
                    <v-card-text>
                        <div v-for="g in cellDetail.byJornada" :key="g.jornada" class="mb-3">
                            <div class="tl-plan-detail-block">{{ g.jornada }} • {{ g.students.length }} estudiantes • {{ g.label }}</div>
                            <div v-for="x in g.students" :key="x.lluid" class="tl-plan-name-row">
                                <span>{{ x.name }}</span>
                                <span class="tl-subj-meta-item" style="font-size:10px;color:#94A3B8">{{ shortPlan(x.plan) }} · hoy {{ x.level }}</span>
                            </div>
                        </div>
                    </v-card-text>
                </v-card>
            </v-dialog>
        </template>

        <template v-if="view === 'next'">
        <!-- GASTO MENSUAL PROYECTADO -->
        <div v-if="cost" class="tl-proj-cost">
            <div class="tl-proj-cost-head" @click="costOpen = !costOpen">
                <v-icon size="16" color="#047857">mdi-cash-multiple</v-icon>
                <b>Gasto mensual proyectado (docentes)</b>
                <span class="tl-proj-sub">{{ money(cost.total) }} en total • {{ cost.groups }} grupos • tarifa {{ money(cost.rate) }}/h</span>
                <v-spacer></v-spacer>
                <v-icon size="18">{{ costOpen ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
            </div>
            <div v-if="costOpen">
                <table class="tl-cfg-table">
                    <thead><tr><th>Mes</th><th>Grupos activos</th><th>Horas</th><th>Grupos nuevos</th><th>Continúan del periodo anterior</th><th>Total</th></tr></thead>
                    <tbody>
                        <tr v-for="m in cost.months" :key="m.key">
                            <td><b>{{ m.label }}</b></td><td>{{ m.groups }}</td><td>{{ Math.round(m.hours) }}</td>
                            <td>{{ money(m.newCost) }}</td><td>{{ money(m.contCost) }}</td><td><b>{{ money(m.newCost + m.contCost) }}</b></td>
                        </tr>
                    </tbody>
                    <tfoot><tr><td><b>Total</b></td><td></td><td><b>{{ Math.round(cost.hours) }}</b></td><td><b>{{ money(cost.newTotal) }}</b></td>
                        <td><b>{{ money(cost.contTotal) }}</b></td><td><b>{{ money(cost.total) }}</b></td></tr></tfoot>
                </table>
                <div class="tl-cfg-help mt-2">
                    Cálculo: grupos que abren en cada jornada (más excepciones y no obligatorias activadas) × horas totales de la asignatura
                    (cargas horarias) × tarifa por hora, repartido día a día desde el inicio de cada jornada: 8 semanas, o 16 si es de
                    cuatrimestre completo ({{ cost.longCount }} grupos). "Continúan" son clases aprobadas de cuatrimestre completo que siguen
                    corriendo{{ careerFilter ? ' (no se filtran por carrera)' : '' }}. Tarifa y asignaturas largas se cambian en Configuración.
                    <span v-if="cost.noHours.length" class="tl-proj-cost-warn">
                        {{ cost.noHoursGroups }} grupo(s) sin carga horaria no están costeados (cárguela en el Planificador → Cargas):
                        {{ cost.noHours.join(', ') }}.
                    </span>
                </div>
            </div>
        </div>
        <div class="tl-proj-grid-head">
            <span>Asignatura</span><span>Jornadas (estudiantes → grupos)</span><span class="tl-proj-num" title="Se cargan + pueden adelantar">Total</span>
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
                        <span v-if="isLong(r.courseid)" class="tl-plan-tag tl-plan-tag-nivel" title="Dura casi todo el cuatrimestre (dos periodos, ~16 semanas)">cuatrimestral</span>
                        <span v-if="r.flexible" class="tl-plan-tag" :class="r.flexOn ? 'tl-plan-tag-nivel' : 'tl-plan-tag-muted'"
                              title="No obligatoria en un periodo concreto (crédito 0 o marcada en Configuración): no se carga salvo que se active aquí">
                            {{ r.flexOn ? 'no obligatoria · activada' : 'no obligatoria · no se carga' }}</span>
                        <button v-if="r.flexible && canEdit" class="tl-proj-flexbtn" @click.stop="toggleFlex(r)">
                            {{ r.flexOn ? 'Quitar de este periodo' : 'Cargar en este periodo' }}</button>
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
                        <input v-if="canEdit && !j.groups && !j.exception && j.ready" type="checkbox" class="tl-proj-check"
                               :checked="!!selected[j.ekey]" @click.stop @change="toggleSel(r, j)">
                        <b>{{ abbr(j.jornada) }}</b>
                        <template v-if="j.flexWaiting && !j.ready">{{ j.flexWaiting }} (no se carga)</template>
                        <template v-else-if="careerFilter && j.mine !== j.ready">{{ j.mine }} de {{ j.ready }}</template>
                        <template v-else>{{ j.ready }}</template>
                        <template v-if="j.flexWaiting && !j.ready"></template>
                        <template v-else-if="j.exception && j.exception.mode === 'modulo'"> → módulo ({{ j.exception.userids.length }})</template>
                        <template v-else-if="j.exception"> → excepción: abre</template>
                        <template v-else-if="j.groups"> → {{ j.groups }} {{ j.groups === 1 ? 'grupo' : 'grupos' }}</template>
                        <template v-else-if="j.ready + j.adelanto >= min"> → con adelantos</template>
                        <template v-else> → no alcanza</template>
                        <v-icon v-if="j.finishing" size="11" :color="j.exception ? '#1D4ED8' : '#B91C1C'" title="Hay estudiantes que egresan">mdi-school</v-icon>
                        <button v-if="canEdit && j.exception" class="tl-proj-unx" @click.stop="removeException(j)" title="Quitar la excepción">×</button>
                    </span>
                </div>
                <div class="tl-proj-num" :title="(careerFilter ? r.mine : r.ready) + ' se cargan + ' + r.adelanto + ' pueden adelantar'">
                    <b>{{ (careerFilter ? r.mine : r.ready) + r.adelanto }}</b>
                    <div v-if="r.adelanto" class="tl-proj-num-sub">{{ careerFilter ? r.mine : r.ready }} + {{ r.adelanto }} adel.</div>
                </div>
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
        </template>
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
        planIds: { type: String, default: '' },
        min: { type: Number, default: 12 },
        max: { type: Number, default: 30 },
    },
    data() {
        return { q: '', filter: 'all', jornada: '', careerFilter: 0, open: {}, selected: {},
            view: 'next', future: null, futureLoading: false, futureError: '', cellDetail: null, costOpen: false,
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
                const studs = d.students.map(x => ({ s: students[x.key], cat: x.category, prereq: x.prereq, flex: !!x.flex }))
                    .filter(x => x.s).sort((a, b) => (b.s.finishing - a.s.finishing) || a.s.name.localeCompare(b.s.name));
                const byPlan = {};
                let finishing = 0;
                const loads = x => x.prereq !== 'no' && !x.flex && (x.cat === 'nivel' || x.cat === 'atrasada');
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
                if (d.flexible) { r.flexible = true; r.flexOn = !!d.flex_on; }
                r.jornadas.push({ jornada, ekey, ready: d.ready, adelanto: d.by_category.adelanto, blocked: d.blocked, flexWaiting: d.flex_waiting || 0,
                    students: studs, byPlan, finishing, exception, groups,
                    readyStudents: studs.filter(loads), repite: d.by_category.repite });
                r.ready += d.ready;
                r.flexWaiting = (r.flexWaiting || 0) + (d.flex_waiting || 0);
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
            })).filter(r => r.ready > 0 || r.adelanto > 0 || r.flexWaiting > 0);
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
                    adelanto: js.reduce((s, j) => s + j.adelanto, 0),
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
                { key: 'flex', label: 'No obligatorias', count: this.scoped.filter(r => r.flexible).length,
                    help: 'Crédito 0 o marcadas en Configuración: no se cargan salvo que se activen en el periodo' },
                { key: 'repite', label: 'Repitentes', count: this.scoped.filter(r => r.repite > 0).length,
                    help: 'Asignaturas con estudiantes que la reprobaron: gestión aparte, se les avisa al aprobar el horario' },
            ];
        },
        rows() {
            const q = this.q.toLowerCase();
            const f = this.filter;
            return this.scoped
                .filter(r => f === 'all' || (f === 'open' ? r.opens > 0 : f === 'finish' ? r.finishingShort > 0
                    : f === 'repite' ? r.repite > 0 : f === 'flex' ? r.flexible : r.jornadas.some(j => !j.groups && !j.exception)))
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
        // Gasto mensual: grupos que abren x horas de la asignatura x tarifa, repartido por dia en las semanas del grupo.
        cost() {
            const c = this.result.costing;
            if (!c) return null;
            const js = this.result.period.jornada_starts || {};
            const months = {};
            const day = 86400;
            const addSpan = (start, days, hours, field) => {
                if (!hours) return;
                const perDay = hours / days;
                for (let i = 0; i < days; i++) {
                    const d = new Date((start + i * day) * 1000);
                    const key = d.getFullYear() * 100 + d.getMonth();
                    const m = months[key] || (months[key] = { key, label: d.toLocaleDateString('es-PA', { month: 'long', year: 'numeric' }),
                        hours: 0, newCost: 0, contCost: 0, groupSet: {} });
                    m.hours += perDay;
                    m[field] += perDay * c.rate;
                }
            };
            const markGroups = (start, days, id) => {
                for (let i = 0; i < days; i += 7) {
                    const d = new Date((start + i * day) * 1000);
                    const key = d.getFullYear() * 100 + d.getMonth();
                    if (months[key]) months[key].groupSet[id] = true;
                }
            };
            let groups = 0, longCount = 0, hoursTotal = 0;
            const noHoursMap = {};
            this.scoped.forEach(r => r.jornadas.forEach(j => {
                const g = j.groups || (j.exception ? 1 : 0);
                if (!g) return;
                const hours = Number(c.hours[r.courseid] || 0);
                const isLong = this.isLong(r.courseid);
                const days = isLong ? 112 : 56;
                const start = js[j.jornada] || this.result.period.start;
                for (let i = 0; i < g; i++) {
                    groups++;
                    if (isLong) longCount++;
                    if (!hours) { const nk = r.name + ' (' + j.jornada + ')'; noHoursMap[nk] = (noHoursMap[nk] || 0) + 1; continue; }
                    hoursTotal += hours;
                    addSpan(start, days, hours, 'newCost');
                    markGroups(start, days, r.key + j.jornada + i);
                }
            }));
            if (!this.careerFilter) {
                (c.continuing || []).forEach(k => {
                    const days = Math.max(1, Math.round((k.enddate - k.initdate) / day));
                    if (!k.hours) return;
                    // Solo la parte desde el inicio del periodo.
                    const from = Math.max(this.result.period.start, k.initdate);
                    const left = Math.max(0, Math.round((k.enddate - from) / day));
                    const hrs = k.hours * left / days;
                    hoursTotal += hrs;
                    addSpan(from, left, hrs, 'contCost');
                    markGroups(from, left, 'c' + k.id);
                });
            }
            const list = Object.values(months).sort((a, b) => a.key - b.key)
                .map(m => Object.assign(m, { groups: Object.keys(m.groupSet).length }));
            const newTotal = list.reduce((s, m) => s + m.newCost, 0);
            const contTotal = list.reduce((s, m) => s + m.contCost, 0);
            const noHours = Object.keys(noHoursMap).map(k => k + (noHoursMap[k] > 1 ? ' ×' + noHoursMap[k] : ''));
            const noHoursGroups = Object.values(noHoursMap).reduce((a, b) => a + b, 0);
            return { months: list, rate: c.rate, groups, longCount, hours: hoursTotal, noHours, noHoursGroups, newTotal, contTotal, total: newTotal + contTotal };
        },
        colGroups() {
            const out = {};
            this.futureRows.forEach(r => Object.keys(r.cells).forEach(k => { out[k] = (out[k] || 0) + this.cellGroups(r.cells[k]); }));
            return out;
        },
        futureCols() {
            if (!this.future) return [];
            const p = this.result.period;
            return [{ key: 'next', name: p.name, start: p.start, estimated: false }]
                .concat(this.future.horizon.map(h => ({ key: 'h' + h.k, name: h.name, start: h.start, estimated: h.estimated })));
        },
        // Filas de la matriz: asignatura (+ carrera si esta aislada); celdas por periodo con el total de las jornadas visibles.
        futureRows() {
            if (!this.future) return [];
            const cf = this.careerFilter, jf = this.jornada, q = this.q.toLowerCase();
            const st = this.future.students || {};
            const rows = {};
            const add = (colkey, cid, name, iso, jornada, count, flex, keys, levels, isflex, base) => {
                if (jf && (jornada || 'Sin jornada') !== jf) return;
                const ks = keys.filter(k => st[k] && (!cf || st[k].planid === cf));
                if (cf && !ks.length) return;
                const rk = cid + '|' + iso;
                const r = rows[rk] || (rows[rk] = { key: rk, courseid: cid, name, isolated: iso, flex: false, levels: {}, cells: {} });
                r.flex = r.flex || isflex;
                (levels || []).forEach(l => { r.levels[l] = true; });
                const c = r.cells[colkey] || (r.cells[colkey] = { count: 0, flex: 0, best: 0, keys: [], byJ: {} });
                const n = cf ? ks.length - (isflex ? ks.length : 0) : count;
                const fx = cf ? (isflex ? ks.length : 0) : flex;
                // Base para grupos: los que se cargan en esa jornada (sin adelantos ni no obligatorias).
                const b = cf ? (isflex ? 0 : Math.min(ks.length, base === undefined ? ks.length : base)) : (base === undefined ? count : base);
                const jk = jornada || 'Sin jornada';
                c.byJ[jk] = (c.byJ[jk] || 0) + b;
                c.count += n; c.flex += fx; c.best = Math.max(c.best, c.byJ[jk]);
                ks.forEach(k => c.keys.push({ k, jornada }));
            };
            (this.result.demand || []).forEach(d => {
                const keys = d.students.filter(x => x.prereq !== 'no' && (x.category === 'nivel' || x.category === 'atrasada' || x.category === 'adelanto')).map(x => x.key);
                add('next', d.courseid, d.name, Number(d.isolated_plan || 0), d.jornada, d.ready + d.by_category.adelanto,
                    d.flex_waiting || 0, keys, Object.keys(d.levels), !!d.flexible && !d.flex_on, d.ready);
            });
            this.future.horizon.forEach(h => h.rows.forEach(row => {
                add('h' + h.k, row.courseid, row.name, Number(row.isolated_plan || 0), row.jornada, row.count, row.flex,
                    row.students, row.levels, row.flex > 0 && row.count === 0, row.count);
            }));
            return Object.values(rows).map(r => Object.assign(r, { levels: Object.keys(r.levels).sort() }))
                .filter(r => !q || r.name.toLowerCase().includes(q))
                .sort((a, b) => (a.levels[0] || '').localeCompare(b.levels[0] || '') || a.name.localeCompare(b.name));
        },
        selectedStudents() { return this.selectedList.reduce((s, it) => s + it.students.length, 0); },
    },
    methods: {
        toggle(k) { this.$set(this.open, k, !this.open[k]); },
        isLong(cid) { return !!(this.result.costing && (this.result.costing.long || []).includes(Number(cid))); },
        money(v) { return '$' + Number(v || 0).toLocaleString('es-PA', { minimumFractionDigits: 0, maximumFractionDigits: 0 }); },
        async openFuture() {
            this.view = 'future';
            if (this.future || this.futureLoading) return;
            this.futureLoading = true; this.futureError = '';
            try {
                const r = await window.axios.get(window.location.origin + '/webservice/rest/server.php', { params: {
                    wstoken: window.userToken, moodlewsrestformat: 'json', wsfunction: 'local_grupomakro_planning_get_demand',
                    periodid: this.result.period.id, planids: this.planIds, horizon: 18 } });
                if (r.data && r.data.exception) throw new Error(r.data.message || r.data.errorcode);
                const d = JSON.parse(r.data.data);
                this.future = { horizon: d.result.horizon || [], students: d.result.students || {} };
            } catch (e) { this.futureError = 'No se pudo calcular: ' + (e.message || e); }
            finally { this.futureLoading = false; }
        },
        cellGroups(c) {
            return Object.values(c.byJ || {}).reduce((s, n) => s + (n >= this.min ? Math.ceil(n / this.max) : 0), 0);
        },
        cellGroupsLabel(c) {
            const g = this.cellGroups(c);
            if (g) return g + (g === 1 ? ' grupo' : ' grupos');
            return c.count || c.flex ? 'no alcanza' : '';
        },
        cellClass(c) {
            if (!c) return 'tl-proj-mx-empty';
            if (!c.count && c.flex) return 'tl-proj-mx-flexonly';
            return c.best >= this.min ? 'tl-proj-mx-green' : (c.best >= Math.ceil(this.min / 2) ? 'tl-proj-mx-amber' : 'tl-proj-mx-red');
        },
        cellTitle(c) {
            if (!c) return '';
            return c.count + ' estudiantes' + (c.flex ? ' + ' + c.flex + ' en no obligatoria (no se carga)' : '')
                + ' • mayor grupo por jornada: ' + c.best + '. Clic para ver quiénes.';
        },
        showCell(r, col) {
            const st = this.future.students || {};
            const seen = {};
            const list = [];
            r.cells[col.key].keys.forEach(x => {
                const s = st[x.k];
                if (s && !seen[x.k]) { seen[x.k] = true; list.push(Object.assign({}, s, { jornada: x.jornada })); }
            });
            const cell = r.cells[col.key];
            const groups = {};
            list.forEach(s => { const j = s.jornada || 'Sin jornada'; (groups[j] = groups[j] || []).push(s); });
            const byJornada = Object.keys(groups).sort().map(j => {
                const n = cell.byJ[j] || 0;
                const g = n >= this.min ? Math.ceil(n / this.max) : 0;
                return { jornada: j, students: groups[j].sort((a, b) => a.name.localeCompare(b.name)),
                    label: g ? (g + (g === 1 ? ' grupo' : ' grupos')) : ('no alcanza el mínimo de ' + this.min) };
            });
            this.cellDetail = { name: r.name, period: col.name, students: list, byJornada, groupsLabel: this.cellGroupsLabel(cell) };
        },
        async toggleFlex(r) {
            const on = !r.flexOn;
            const msg = on ? 'Cargar "' + r.name + '" en ' + this.result.period.name + '? Sus estudiantes pasarán a contar para la demanda y el quórum.'
                : 'Quitar "' + r.name + '" de ' + this.result.period.name + '? Dejará de cargarse en este periodo.';
            if (!window.confirm(msg)) return;
            try {
                const res = await this.ws('flex_toggle', { periodid: this.result.period.id, courseid: r.courseid, enabled: on ? 1 : 0 });
                if (res.ok) this.$emit('changed', res.message); else window.alert(res.message);
            } catch (e) { window.alert('Error: ' + (e.message || e)); }
        },
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
