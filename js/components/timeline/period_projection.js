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
                    a partir de {{ max }}. Con el botón de ajustes de cada jornada se decide si abre como grupo, como módulo (sin
                    horario ni pago) o no abre. La práctica profesional se carga sin horario.</span>
                </v-tooltip>
            </div>
            <div v-if="view === 'next'" class="tl-proj-kpis">
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
            <button class="tl-plan-tab" :class="{ 'tl-plan-tab-active': view === 'groups' }" @click="openGroups">
                <v-icon size="14">mdi-account-group</v-icon> Grupos propuestos</button>
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
            <button class="tl-cfg-btn" @click="openException()">Decidir apertura…</button>
            <button class="tl-cfg-btn tl-cfg-btn-ghost" @click="selected = {}">Limpiar selección</button>
        </div>

        <!-- GRUPOS PROPUESTOS (agrupacion automatica, solo lectura) -->
        <template v-if="view === 'groups'">
            <div class="tl-proj-filter-note">
                Propuesta automática de grupos para {{ result.period.name }}: por asignatura y jornada, de {{ min }} a {{ max }} estudiantes,
                repartidos parejo y con las cohortes juntas. Los adelantos solo se cargan a quien queda con menos de la carga mínima.
                Los repitentes se gestionan aparte. Es una propuesta: no se guarda nada hasta enviarla al tablero de horarios.
            </div>
            <div v-if="groupsLoading" class="tl-plan-empty" style="padding:16px">Armando grupos…</div>
            <div v-else-if="groupsError" class="tl-cfg-msg tl-cfg-msg-error">{{ groupsError }}</div>
            <template v-else-if="grouping">
                <div class="tl-proj-kpis mb-3">
                    <div class="tl-proj-kpi"><b>{{ gSummary.groups }}</b><span>grupos</span></div>
                    <div class="tl-proj-kpi"><b>{{ gSummary.seats }}</b><span>cupos</span></div>
                    <div class="tl-proj-kpi tl-proj-kpi-ok"><b>{{ gSummary.students }}</b><span>estudiantes con carga</span></div>
                    <div class="tl-proj-kpi"><b>{{ gSummary.adelantos }}</b><span>adelantos</span></div>
                    <div class="tl-proj-kpi tl-proj-kpi-warn tl-proj-kpi-click" @click="groupTab = 'alerts'"><b>{{ gSummary.alerts }}</b><span>alertas</span></div>
                </div>
                <div class="tl-proj-savebar">
                    <template v-if="savedProposal">
                        <v-icon size="16" color="#047857">mdi-content-save-check-outline</v-icon>
                        <span>Propuesta guardada aparte el {{ fmtLong(savedProposal.saved_at) }} por {{ savedProposal.byname }} ({{ savedProposal.groups }} grupos).
                            La comparación usa esta versión.</span>
                    </template>
                    <template v-else>
                        <v-icon size="16" color="#64748B">mdi-content-save-outline</v-icon>
                        <span>La propuesta aún no está guardada. Guárdela para compararla con el borrador del tablero de horarios (el borrador no se toca).</span>
                    </template>
                    <v-spacer></v-spacer>
                    <button v-if="canEdit" class="tl-cfg-btn" :disabled="proposalSaving" @click="saveProposal">{{ savedProposal ? 'Actualizar propuesta guardada' : 'Guardar propuesta para comparar' }}</button>
                    <button v-if="canEdit && savedProposal" class="tl-cfg-btn tl-cfg-btn-ghost" :disabled="proposalSaving" @click="removeProposal">Quitar</button>
                </div>
                <div class="tl-plan-tabs mb-3" style="max-width:640px">
                    <button class="tl-plan-tab" :class="{ 'tl-plan-tab-active': groupTab === 'list' }" @click="groupTab = 'list'">Grupos<span class="tl-plan-tab-count">{{ gSummary.groups }}</span></button>
                    <button class="tl-plan-tab" :class="{ 'tl-plan-tab-active': groupTab === 'alerts' }" @click="groupTab = 'alerts'">Alertas<span class="tl-plan-tab-count">{{ gSummary.alerts }}</span></button>
                    <button class="tl-plan-tab" :class="{ 'tl-plan-tab-active': groupTab === 'compare' }" @click="groupTab = 'compare'">Comparar con el tablero<span class="tl-plan-tab-count">{{ cmpRowsAll.length }}</span></button>
                </div>
                <!-- comparacion con el borrador del tablero de horarios -->
                <template v-if="groupTab === 'compare'">
                    <div v-if="!comparison || !comparison.board_items" class="tl-plan-empty" style="padding:16px">El periodo no tiene borrador en el tablero de horarios.</div>
                    <template v-else>
                        <div class="tl-proj-filter-note">
                            Borrador del tablero de horarios (Planificador Académico) vs. {{ comparison.source === 'guardada' ? 'la propuesta guardada' : 'la propuesta actual (sin guardar)' }},
                            por asignatura y jornada. "En común" son estudiantes que están en ambos. El borrador del tablero solo se lee.
                        </div>
                        <div class="tl-proj-kpis mb-3">
                            <div class="tl-proj-kpi"><b>{{ comparison.totals.board_groups }} / {{ comparison.totals.proposal_groups }}</b><span>grupos tablero / propuesta</span></div>
                            <div class="tl-proj-kpi"><b>{{ comparison.totals.board_seats }} / {{ comparison.totals.proposal_seats }}</b><span>cupos tablero / propuesta</span></div>
                            <div class="tl-proj-kpi tl-proj-kpi-ok"><b>{{ comparison.totals.same }}</b><span>en común</span></div>
                            <div class="tl-proj-kpi tl-proj-kpi-warn"><b>{{ comparison.totals.only_board }}</b><span>solo en tablero</span></div>
                            <div class="tl-proj-kpi"><b>{{ comparison.totals.only_proposal }}</b><span>solo en propuesta</span></div>
                        </div>
                        <div class="tl-plan-tabs mb-2" style="max-width:640px">
                            <button v-for="t in cmpStates" :key="t.key" class="tl-plan-tab" :class="{ 'tl-plan-tab-active': cmpState === t.key }"
                                    @click="cmpState = t.key" :title="t.help">{{ t.label }}<span class="tl-plan-tab-count">{{ t.count }}</span></button>
                        </div>
                        <table class="tl-cfg-table">
                            <thead><tr><th>Asignatura</th><th>Jornada</th><th>Tablero</th><th>Propuesta</th><th>En común</th><th>Solo tablero</th><th>Solo propuesta</th><th></th></tr></thead>
                            <tbody>
                                <tr v-for="row in cmpRows" :key="row.courseid + row.jornada" class="tl-proj-cmp-row" @click="cmpDetail = row">
                                    <td>{{ row.name }}</td><td>{{ row.jornada || '—' }}</td>
                                    <td>{{ row.board.groups ? row.board.groups + ' gr · ' + row.board.students + ' est' : '—' }}</td>
                                    <td>{{ row.proposal.groups ? row.proposal.groups + ' gr · ' + row.proposal.students + ' est' : '—' }}</td>
                                    <td>{{ row.common }}</td><td>{{ row.only_board.length }}</td><td>{{ row.only_proposal.length }}</td>
                                    <td><span class="tl-plan-tag" :class="cmpTag(row.status)">{{ cmpLabel[row.status] }}</span></td>
                                </tr>
                            </tbody>
                        </table>
                        <div v-if="!cmpRows.length" class="tl-plan-empty" style="padding:16px">Sin filas para este filtro.</div>
                    </template>
                </template>
                <!-- lista de grupos -->
                <template v-if="groupTab === 'list'">
                    <div v-if="!groupRows.length" class="tl-plan-empty" style="padding:16px">Sin grupos para este filtro.</div>
                    <div v-for="row in groupRows" :key="row.key" class="tl-proj-grow">
                        <div class="tl-proj-grow-name">
                            <div class="tl-subj-name" :title="row.name">{{ row.name }}</div>
                            <div class="tl-subj-meta">
                                <span>{{ row.jornada || 'Sin jornada' }}</span>
                                <span v-if="row.isolated" class="tl-plan-tag tl-plan-tag-lock">solo {{ careerName(row.isolated) }}</span>
                                <span v-if="row.long" class="tl-plan-tag tl-plan-tag-nivel">cuatrimestral</span>
                                <span v-if="row.decision" class="tl-plan-tag tl-plan-tag-lock">{{ decisionShort[row.decision] }}</span>
                            </div>
                            <button v-if="canEdit && row.jornada !== 'Sin horario'" class="tl-proj-flexbtn mt-1" @click="decideKey(row.key)"
                                    title="Abrir como grupo, como módulo (sin horario ni pago) o no abrir">
                                <v-icon size="12">mdi-tune-variant</v-icon> Decidir apertura</button>
                        </div>
                        <div class="tl-proj-gcards">
                            <div v-for="gr in row.groups" :key="gr.id" class="tl-proj-gcard" :class="'tl-proj-gcard-' + gr.type" @click="groupDetail = gr">
                                <div class="tl-proj-gcard-top"><b>{{ groupTitle(gr) }}</b>
                                    <span class="tl-proj-gcard-size">{{ gr.size }}</span></div>
                                <div class="tl-proj-gcard-meta">{{ topLevels(gr) }}</div>
                                <div class="tl-proj-gcard-meta">
                                    <span v-if="gr.adelantos">{{ gr.adelantos }} adelanto{{ gr.adelantos > 1 ? 's' : '' }}</span>
                                    <span v-if="gr.type === 'excepcion'" class="tl-plan-tag tl-plan-tag-nivel">excepción</span>
                                    <span v-if="showCareers && gr.plans.length > 1">{{ gr.plans.length }} carreras</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
                <!-- alertas -->
                <template v-if="groupTab === 'alerts'">
                    <div class="tl-proj-toolbar">
                        <div class="tl-plan-tabs" style="flex:0 0 auto">
                            <button v-for="t in alertTypes" :key="t.key" class="tl-plan-tab" :class="{ 'tl-plan-tab-active': alertType === t.key }"
                                    @click="alertType = t.key" :title="t.help">{{ t.label }}<span class="tl-plan-tab-count">{{ t.count }}</span></button>
                        </div>
                        <v-spacer></v-spacer>
                        <button class="tl-cfg-btn tl-cfg-btn-ghost" @click="exportAlerts">Descargar (CSV)</button>
                    </div>
                    <table class="tl-cfg-table">
                        <thead><tr><th>Estudiante</th><th>Carrera</th><th>Nivel</th><th>Asignatura</th><th>Jornada</th><th>Detalle</th></tr></thead>
                        <tbody>
                            <tr v-for="(al, i) in alertRows.slice(0, alertLimit)" :key="i">
                                <td>{{ al.name }} <span v-if="al.finishing" class="tl-plan-tag tl-proj-tag-finish">egresa</span></td>
                                <td class="tl-cfg-help">{{ shortPlan(al.plan) }}</td><td>{{ al.level }}</td>
                                <td>{{ al.course || '—' }}</td><td>{{ al.jornada || '—' }}</td><td class="tl-cfg-help">{{ al.detail }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="alertRows.length > alertLimit" class="tl-plan-more" @click="alertLimit += 200">Ver más ({{ alertRows.length - alertLimit }})</div>
                    <div v-if="!alertRows.length" class="tl-plan-empty" style="padding:16px">Sin alertas para este filtro.</div>
                </template>
            </template>
            <v-dialog :value="!!cmpDetail" @input="v => { if (!v) cmpDetail = null; }" max-width="680" scrollable>
                <v-card v-if="cmpDetail">
                    <v-card-title class="d-flex">
                        <div>
                            <div class="text-subtitle-1 font-weight-bold">{{ cmpDetail.name }}</div>
                            <div class="tl-cfg-help">{{ cmpDetail.jornada || 'Sin jornada' }} • tablero {{ cmpDetail.board.groups }} grupo(s), {{ cmpDetail.board.students }} est •
                                propuesta {{ cmpDetail.proposal.groups }} grupo(s), {{ cmpDetail.proposal.students }} est • en común {{ cmpDetail.common }}</div>
                        </div>
                        <v-spacer></v-spacer>
                        <v-btn icon @click="cmpDetail = null"><v-icon>mdi-close</v-icon></v-btn>
                    </v-card-title>
                    <v-card-text>
                        <div class="tl-proj-cmp-cols">
                            <div>
                                <div class="tl-plan-detail-block">Solo en el tablero ({{ cmpDetail.only_board.length }})</div>
                                <div v-for="u in cmpDetail.only_board" :key="'b' + u" class="tl-plan-name-row tl-proj-stu">
                                    <span class="tl-proj-stu-name">{{ whoName(u) }}</span><span class="tl-proj-stu-meta">{{ whyBoard(u, cmpDetail.courseid) }}</span>
                                </div>
                            </div>
                            <div>
                                <div class="tl-plan-detail-block">Solo en la propuesta ({{ cmpDetail.only_proposal.length }})</div>
                                <div v-for="u in cmpDetail.only_proposal" :key="'p' + u" class="tl-plan-name-row tl-proj-stu">
                                    <span class="tl-proj-stu-name">{{ whoName(u) }}</span><span class="tl-proj-stu-meta">{{ whoMeta(u) }}</span>
                                </div>
                            </div>
                        </div>
                    </v-card-text>
                </v-card>
            </v-dialog>
            <v-dialog :value="!!groupDetail" @input="v => { if (!v) groupDetail = null; }" max-width="640" scrollable>
                <v-card v-if="groupDetail">
                    <v-card-title class="d-flex">
                        <div>
                            <div class="text-subtitle-1 font-weight-bold">{{ groupDetail.name }}</div>
                            <div class="tl-cfg-help">{{ groupTitle(groupDetail, true) }} •
                                {{ groupDetail.jornada || 'Sin jornada' }} • {{ groupDetail.size }} estudiantes
                                <template v-if="groupDetail.note"> • {{ groupDetail.note }}</template></div>
                        </div>
                        <v-spacer></v-spacer>
                        <v-btn icon @click="groupDetail = null"><v-icon>mdi-close</v-icon></v-btn>
                    </v-card-title>
                    <v-card-text>
                        <div v-if="groupDetail.type === 'modulo'" class="tl-proj-exc-note mb-2">Módulo: sin docente, horario ni sesiones; cada estudiante
                        avanza por su cuenta hasta el fin del periodo. No pasa por pago. Se crea al publicar, como los módulos del área académica.</div>
                    <div v-if="groupDetail.type === 'sin_horario'" class="tl-proj-exc-note mb-2">Sin horario: se carga a los estudiantes sin docente, aula
                        ni sesiones (práctica profesional). No va al tablero de horarios ni al gasto.</div>
                    <div class="tl-cfg-help mb-2">Cohortes: <span v-for="(n, c) in groupDetail.cohorts" :key="c">{{ c }} ({{ n }}); </span></div>
                        <div v-for="k in groupDetail.students" :key="k" class="tl-plan-name-row tl-proj-stu">
                            <span class="tl-proj-stu-name">{{ grouping.st[k].name }}</span>
                            <span v-if="grouping.st[k].finishing" class="tl-plan-tag tl-proj-tag-finish">egresa</span>
                            <span v-if="groupDetail.adel && groupDetail.adel.includes(k)" class="tl-plan-tag tl-plan-tag-adelanto">adelanto</span>
                            <span class="tl-proj-stu-meta">{{ shortPlan(grouping.st[k].plan) }} · {{ grouping.st[k].level }}</span>
                        </div>
                    </v-card-text>
                </v-card>
            </v-dialog>
        </template>

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
                <span class="tl-proj-sub">{{ money(cost.total) }} en total • {{ cost.groups - cost.fixedGroups }} grupos de costo variable • tarifa {{ money(cost.rate) }}/h</span>
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
                    <span v-if="cost.fixedGroups" style="display:block;margin-top:4px">{{ cost.fixedGroups }} grupo(s) con docente de planta
                        no se costean (asignaturas marcadas en Configuración → Costos y duración).</span>
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
                        <span v-if="r.unscheduled" class="tl-plan-tag tl-plan-tag-nivel" title="Práctica profesional o similar: se carga sin horario">sin horario</span>
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
                    <span v-for="j in r.jornadas.filter(visibleJ)" :key="j.jornada" class="tl-proj-chip" :class="'tl-proj-chip-' + chipSem(j)" :title="chipHelp(j)">
                        <input v-if="canEdit && !j.groups && !j.exception && !j.closed && !r.unscheduled && j.ready" type="checkbox" class="tl-proj-check"
                               :checked="!!selected[j.ekey]" @click.stop @change="toggleSel(r, j)" title="Marcar para decidir varias a la vez">
                        <b>{{ r.unscheduled ? 'Sin horario' : abbr(j.jornada) }}</b>
                        <template v-if="j.flexWaiting && !j.ready">{{ j.flexWaiting }} (no se carga)</template>
                        <template v-else-if="careerFilter && j.mine !== j.ready">{{ j.mine }} de {{ j.ready }}</template>
                        <template v-else>{{ j.ready }}</template>
                        <template v-if="j.flexWaiting && !j.ready"></template>
                        <template v-else-if="r.unscheduled"> → se carga sin horario</template>
                        <template v-else-if="j.closed"> → no abre (decisión)</template>
                        <template v-else-if="j.exception && j.exception.mode === 'modulo'"> → módulo ({{ j.exception.userids.length }})</template>
                        <template v-else-if="j.exception"> → excepción: abre</template>
                        <template v-else-if="j.groups"> → {{ j.groups }} {{ j.groups === 1 ? 'grupo' : 'grupos' }}</template>
                        <template v-else-if="j.ready + j.adelanto >= min"> → con adelantos</template>
                        <template v-else> → no alcanza</template>
                        <v-icon v-if="j.finishing" size="11" :color="j.exception ? '#1D4ED8' : '#B91C1C'" title="Hay estudiantes que egresan">mdi-school</v-icon>
                        <button v-if="canEdit && !r.unscheduled && (j.ready || j.adelanto || j.exception || j.closed)" class="tl-proj-decide"
                                @click.stop="decide(r, j)" title="Decidir cómo abre: automático, grupo, módulo o no abrir">
                            <v-icon size="12">mdi-tune-variant</v-icon></button>
                    </span>
                </div>
                <div class="tl-proj-num" :title="(careerFilter ? r.mine : r.ready) + ' se cargan + ' + r.adelanto + ' pueden adelantar'">
                    <b>{{ (careerFilter ? r.mine : r.ready) + r.adelanto }}</b>
                    <div v-if="r.adelanto" class="tl-proj-num-sub">{{ careerFilter ? r.mine : r.ready }} + {{ r.adelanto }} adel.</div>
                </div>
            </div>
            <div v-if="open[r.key]" class="tl-proj-detail">
                <div v-for="j in r.jornadas.filter(visibleJ)" :key="j.jornada" class="tl-proj-detail-col">
                    <div class="tl-plan-detail-block">{{ j.jornada }} • {{ j.ready }} estudiantes
                        <template v-if="j.adelanto"> (+{{ j.adelanto }} pueden adelantar)</template></div>
                    <div v-if="j.exception || j.closed" class="tl-proj-exc-note">
                        <v-icon size="12" color="#1D4ED8">mdi-shield-check-outline</v-icon>
                        {{ decisionLabel(j.exception || j.closed) }}
                        — {{ (j.exception || j.closed).note }} <span class="tl-proj-exc-by">({{ (j.exception || j.closed).byname }}, {{ fmt((j.exception || j.closed).time) }})</span>
                        <button v-if="canEdit" class="tl-proj-flexbtn" @click.stop="decide(r, j)">Cambiar</button>
                    </div>
                    <div v-if="r.unscheduled" class="tl-proj-exc-note">
                        <v-icon size="12" color="#1D4ED8">mdi-briefcase-outline</v-icon>
                        Sin horario: se carga a todos los que les toca, en un solo grupo que junta las jornadas, sin mínimo, sin docente
                        ni aula y fuera del tablero de horarios (Configuración → Costos y duración).
                    </div>
                    <div v-for="x in j.students.filter(y => y.cat !== 'repite')" :key="x.s.lluid" class="tl-plan-name-row tl-proj-stu"
                         :class="{ 'tl-proj-other': careerFilter && x.s.planid !== careerFilter }">
                        <span class="tl-proj-stu-name" :title="x.s.name + ' · ' + x.s.plan">{{ x.s.name }}</span>
                        <span v-if="x.s.finishing" class="tl-plan-tag tl-proj-tag-finish" title="Termina su plan con este periodo">egresa</span>
                        <span v-if="j.exception && j.exception.mode === 'modulo' && j.exception.userids.includes(x.s.userid)" class="tl-plan-tag tl-plan-tag-nivel">módulo</span>
                        <span class="tl-proj-stu-meta" :title="x.s.plan">{{ showCareers && !r.isolated ? shortPlan(x.s.plan) + ' · ' : '' }}{{ x.s.level }}</span>
                        <span class="tl-plan-tag" :class="'tl-plan-tag-' + x.cat">{{ catLabel[x.cat] }}</span>
                        <span v-if="x.prereq === 'no'" class="tl-plan-tag tl-plan-tag-lock">prelación</span>
                    </div>
                </div>
                <!-- Repitentes: gestion aparte, columna plegable -->
                <div v-if="repeatersOf(r).length" class="tl-proj-detail-col tl-proj-rep-col">
                    <button class="tl-proj-rep-head" @click.stop="toggleRep(r.key)">
                        <v-icon size="14" color="#B91C1C">{{ openRep[r.key] ? 'mdi-chevron-down' : 'mdi-chevron-right' }}</v-icon>
                        Repitentes · gestión aparte ({{ repeatersOf(r).length }})
                    </button>
                    <div class="tl-cfg-help" style="margin:2px 0 6px">No se cargan solos: al aprobar el horario se les avisa que pueden solicitar el cargue en el área académica y pagarla.</div>
                    <template v-if="openRep[r.key]">
                        <div v-for="x in repeatersOf(r)" :key="'rep' + x.s.lluid + x.jornada" class="tl-plan-name-row tl-proj-stu">
                            <span class="tl-proj-stu-name" :title="x.s.name + ' · ' + x.s.plan">{{ x.s.name }}</span>
                            <span v-if="x.s.finishing" class="tl-plan-tag tl-proj-tag-finish">egresa</span>
                            <span class="tl-proj-stu-meta">{{ x.jornada }} · {{ x.s.level }}</span>
                            <span v-if="x.prereq === 'no'" class="tl-plan-tag tl-plan-tag-lock">prelación</span>
                        </div>
                    </template>
                </div>
            </div>
        </div>
        </template>
        <div class="tl-plan-legend">
            <span><span class="tl-filter-dot tl-dot-green"></span> abre ({{ min }} o más)</span>
            <span><span class="tl-filter-dot tl-dot-blue"></span> abre por decisión o como módulo</span>
            <span><v-icon size="11">mdi-tune-variant</v-icon> decidir apertura (grupo, módulo o no abrir)</span>
            <span><span class="tl-filter-dot tl-dot-orange"></span> abre si se suman adelantos</span>
            <span><span class="tl-filter-dot tl-dot-red"></span> no alcanza: alerta para revisar</span>
            <span><v-icon size="11" color="#B91C1C">mdi-school</v-icon> hay estudiantes que egresan</span>
            <span><v-icon size="11" color="#B45309">mdi-lock</v-icon> no se mezcla con otras carreras</span>
            <span>D = Diurna · N = Nocturna · S = Sabatina</span>
        </div>

        <!-- Excepcion: abrir sin quorum o como modulo -->
        <v-dialog v-model="exc.show" max-width="640" scrollable>
            <v-card>
                <v-card-title class="text-subtitle-1 font-weight-bold">Decidir apertura</v-card-title>
                <v-card-text>
                    <div class="tl-cfg-help mb-3">La decisión queda registrada en el periodo {{ result.period.name }} con su motivo y se
                    refleja en los grupos propuestos. No se crea nada todavía: las clases y los módulos se crean al publicar.</div>
                    <div class="tl-proj-modes mb-3">
                        <label v-for="m in decisionModes" :key="m.key" class="tl-proj-mode" :class="{ 'tl-proj-mode-on': exc.mode === m.key }">
                            <input type="radio" :value="m.key" v-model="exc.mode">
                            <div><b>{{ m.label }}</b><div class="tl-cfg-help">{{ m.help }}</div></div>
                        </label>
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
                    <label v-if="exc.mode !== 'auto'" class="tl-cfg-fields mt-3" style="display:block">
                        <span style="font-size:13px;font-weight:600">Motivo (obligatorio)</span>
                        <textarea v-model="exc.note" rows="2" class="tl-cfg-input" style="width:100%" placeholder="Ej.: estudiantes por egresar; autorizado por Dirección Académica"></textarea>
                    </label>
                    <div v-if="exc.error" class="tl-cfg-msg tl-cfg-msg-error mt-2">{{ exc.error }}</div>
                </v-card-text>
                <v-card-actions><v-spacer></v-spacer>
                    <v-btn text @click="exc.show = false">Cancelar</v-btn>
                    <v-btn color="primary" depressed :loading="exc.saving" @click="saveException">{{ exc.mode === 'auto' ? 'Volver a automático' : 'Registrar decisión' }}</v-btn>
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
    watch: {
        result() { if (this.grouping) this.resetGroups(); },
    },
    data() {
        return { q: '', filter: 'all', jornada: '', careerFilter: 0, open: {}, selected: {},
            view: 'next', future: null, futureLoading: false, futureError: '', cellDetail: null, costOpen: false, openRep: {},
            grouping: null, groupsLoading: false, groupsError: '', groupTab: 'list', groupDetail: null, alertType: 'all', alertLimit: 200,
            comparison: null, savedProposal: null, proposalSaving: false, cmpState: 'all', cmpDetail: null,
            cmpLabel: { igual: 'igual', distinto: 'distinto', solo_tablero: 'solo tablero', solo_propuesta: 'solo propuesta' },
            exc: { show: false, mode: 'grupo', items: [], note: '', error: '', saving: false },
            catLabel: { nivel: 'nivel', atrasada: 'atrasada', repite: 'repite', adelanto: 'adelanto' },
            decisionShort: { grupo: 'abre por decisión', modulo: 'módulo', cerrar: 'no abre' },
            decisionModes: [
                { key: 'auto', label: 'Automático', help: 'Abre si alcanza el mínimo de estudiantes; si no, queda como alerta.' },
                { key: 'grupo', label: 'Abrir como grupo', help: 'Abre con docente y horario aunque no alcance el mínimo.' },
                { key: 'modulo', label: 'Abrir como módulo', help: 'Sin docente, horario ni sesiones: cada estudiante elegido avanza por su cuenta hasta el fin del periodo. No pasa por pago.' },
                { key: 'cerrar', label: 'No abrir', help: 'No abre aunque alcance el mínimo. Sus estudiantes quedan en alertas para reubicarlos.' },
            ] };
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
                const dec = this.exceptionMap[ekey] || null;
                const closed = dec && dec.mode === 'cerrar' ? dec : null;
                const exception = closed ? null : dec;
                const unscheduled = this.isUnscheduled(d.courseid);
                const groups = closed ? 0 : (unscheduled ? (d.ready > 0 ? 1 : 0) : (d.ready >= this.min ? Math.ceil(d.ready / this.max) : 0));
                if (unscheduled) r.unscheduled = true;
                if (d.flexible) { r.flexible = true; r.flexOn = !!d.flex_on; }
                r.jornadas.push({ jornada, ekey, ready: d.ready, adelanto: d.by_category.adelanto, blocked: d.blocked, flexWaiting: d.flex_waiting || 0,
                    students: studs, byPlan, finishing, exception, closed, groups,
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
                jornadas: r.unscheduled ? [this.mergeJ(r.jornadas)] : r.jornadas.sort((a, b) => b.ready - a.ready),
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
        // Grupos filtrados por carrera, jornada y busqueda, agrupados por asignatura + jornada.
        groupRows() {
            if (!this.grouping) return [];
            const cf = this.careerFilter, jf = this.jornada, q = this.q.toLowerCase();
            const st = this.grouping.st;
            const rows = {};
            this.grouping.groups.forEach(g => {
                if (jf && (g.jornada || 'Sin jornada') !== jf) return;
                if (q && !g.name.toLowerCase().includes(q)) return;
                if (cf && !g.students.some(k => st[k] && st[k].planid === cf)) return;
                const r = rows[g.rowkey] || (rows[g.rowkey] = { key: g.rowkey, name: g.name, jornada: g.jornada, isolated: g.isolated_plan,
                    long: g.long, decision: g.decision || '', groups: [] });
                r.groups.push(g);
            });
            return Object.values(rows).sort((a, b) => a.name.localeCompare(b.name) || (a.jornada || '').localeCompare(b.jornada || ''));
        },
        cmpRowsAll() {
            if (!this.comparison) return [];
            const jf = this.jornada, q = this.q.toLowerCase(), cf = this.careerFilter;
            const st = this.grouping ? this.grouping.byUser : {};
            return this.comparison.rows.filter(r => (!jf || (r.jornada || 'Sin jornada') === jf) && (!q || r.name.toLowerCase().includes(q))
                && (!cf || r.only_board.concat(r.only_proposal).some(u => st[u] && st[u].planid === cf) || r.common > 0));
        },
        cmpStates() {
            const c = {};
            this.cmpRowsAll.forEach(r => { c[r.status] = (c[r.status] || 0) + 1; });
            return [
                { key: 'all', label: 'Todas', count: this.cmpRowsAll.length, help: 'Todas las asignaturas y jornadas' },
                { key: 'distinto', label: 'Distintas', count: c.distinto || 0, help: 'Están en ambos pero con otros grupos o estudiantes' },
                { key: 'solo_tablero', label: 'Solo tablero', count: c.solo_tablero || 0, help: 'Están en el borrador del tablero y no en la propuesta' },
                { key: 'solo_propuesta', label: 'Solo propuesta', count: c.solo_propuesta || 0, help: 'Están en la propuesta y no en el tablero' },
                { key: 'igual', label: 'Iguales', count: c.igual || 0, help: 'Mismos grupos y mismos estudiantes' },
            ];
        },
        cmpRows() {
            const order = { distinto: 0, solo_tablero: 1, solo_propuesta: 2, igual: 3 };
            return this.cmpRowsAll.filter(r => this.cmpState === 'all' || r.status === this.cmpState)
                .sort((a, b) => (order[a.status] - order[b.status]) || a.name.localeCompare(b.name));
        },
        gSummary() {
            const rows = this.groupRows;
            let groups = 0, seats = 0, adel = 0;
            const studs = {};
            rows.forEach(r => r.groups.forEach(g => { groups++; seats += g.size; adel += g.adelantos; g.students.forEach(k => { studs[k] = true; }); }));
            return { groups, seats, adelantos: adel, students: Object.keys(studs).length, alerts: this.filteredAlerts.length };
        },
        filteredAlerts() {
            if (!this.grouping) return [];
            const cf = this.careerFilter, jf = this.jornada, q = this.q.toLowerCase();
            const st = this.grouping.st;
            return this.grouping.alerts.filter(a => (!cf || (st[a.key] && st[a.key].planid === cf))
                && (!jf || (a.jornada || 'Sin jornada') === jf || !a.courseid)
                && (!q || (a.course + ' ' + a.name).toLowerCase().includes(q)));
        },
        alertTypes() {
            const c = {};
            this.filteredAlerts.forEach(a => { c[a.type] = (c[a.type] || 0) + 1; });
            return [
                { key: 'all', label: 'Todas', count: this.filteredAlerts.length, help: 'Todas las alertas' },
                { key: 'egresa_sin_quorum', label: 'Egresan sin quórum', count: c.egresa_sin_quorum || 0, help: 'Terminan su plan y una asignatura no abre' },
                { key: 'sin_quorum', label: 'Sin quórum', count: c.sin_quorum || 0, help: 'Debían ver la asignatura pero no alcanza el mínimo' },
                { key: 'carga_baja', label: 'Carga baja', count: c.carga_baja || 0, help: 'Quedan con menos de la carga mínima teniendo más pendientes' },
                { key: 'prelacion', label: 'Prelación pendiente', count: c.prelacion || 0, help: 'Les toca pero no tienen aprobada la prelación' },
                { key: 'no_abre', label: 'No abre (decisión)', count: c.no_abre || 0, help: 'Se decidió no abrir la asignatura en esa jornada' },
            ];
        },
        alertRows() {
            const order = { egresa_sin_quorum: 0, sin_quorum: 1, no_abre: 2, carga_baja: 3, prelacion: 4 };
            return this.filteredAlerts.filter(a => this.alertType === 'all' || a.type === this.alertType)
                .sort((a, b) => (order[a.type] - order[b.type]) || (b.finishing - a.finishing) || a.name.localeCompare(b.name));
        },
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
            let groups = 0, longCount = 0, hoursTotal = 0, fixedGroups = 0;
            const fixed = (c.fixed || []).map(Number);
            const noHoursMap = {};
            this.scoped.forEach(r => r.jornadas.forEach(j => {
                const g = j.groups || (j.exception ? 1 : 0);
                if (!g) return;
                if (r.unscheduled) return;
                if (fixed.includes(Number(r.courseid))) { fixedGroups += g; return; }
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
                    if (fixed.includes(Number(k.courseid))) { fixedGroups++; return; }
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
            return { months: list, rate: c.rate, groups, longCount, hours: hoursTotal, noHours, noHoursGroups, fixedGroups,
                newTotal, contTotal, total: newTotal + contTotal };
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
        toggleRep(k) { this.$set(this.openRep, k, !this.openRep[k]); },
        // Una jornada se muestra si tiene a alguien que se carga, puede adelantar, espera (no obligatoria) o tiene excepcion;
        // las que solo tienen repitentes van en la columna de repitentes.
        visibleJ(j) { return j.ready > 0 || j.adelanto > 0 || (j.flexWaiting || 0) > 0 || j.blocked > 0 || !!j.exception; },
        repeatersOf(r) {
            const out = [];
            r.jornadas.forEach(j => j.students.forEach(x => {
                if (x.cat === 'repite' && (!this.careerFilter || x.s.planid === this.careerFilter)) out.push(Object.assign({ jornada: j.jornada }, x));
            }));
            return out.sort((a, b) => (b.s.finishing - a.s.finishing) || a.s.name.localeCompare(b.s.name));
        },
        isLong(cid) { return !!(this.result.costing && (this.result.costing.long || []).includes(Number(cid))); },
        money(v) { return '$' + Number(v || 0).toLocaleString('es-PA', { minimumFractionDigits: 0, maximumFractionDigits: 0 }); },
        async openGroups() {
            this.view = 'groups';
            if (this.grouping || this.groupsLoading) return;
            this.groupsLoading = true; this.groupsError = '';
            try {
                const r = await window.axios.get(window.location.origin + '/webservice/rest/server.php', { params: {
                    wstoken: window.userToken, moodlewsrestformat: 'json', wsfunction: 'local_grupomakro_planning_get_demand',
                    periodid: this.result.period.id, planids: this.planIds, groups: 1 } });
                if (r.data && r.data.exception) throw new Error(r.data.message || r.data.errorcode);
                const d = JSON.parse(r.data.data);
                const byUser = {};
                Object.values(d.result.students || {}).forEach(s => { byUser[s.userid] = s; });
                this.grouping = Object.assign({ st: d.result.students || {}, byUser }, d.result.grouping || { groups: [], alerts: [] });
                this.comparison = d.result.comparison || null;
                this.savedProposal = d.result.saved_proposal || null;
            } catch (e) { this.groupsError = 'No se pudo armar la propuesta: ' + (e.message || e); }
            finally { this.groupsLoading = false; }
        },
        async saveProposal() {
            const msg = (this.savedProposal ? 'Actualizar la propuesta guardada' : 'Guardar la propuesta') + ' de ' + this.result.period.name
                + ' (' + this.grouping.groups.length + ' grupos) para compararla? Se guarda aparte en el periodo; el borrador del tablero de horarios no se toca.';
            if (!window.confirm(msg)) return;
            this.proposalSaving = true;
            try {
                const r = await this.ws('proposal_save', { periodid: this.result.period.id });
                if (!r.ok) { window.alert(r.message); return; }
                this.grouping = null; await this.openGroups(); this.groupTab = 'compare';
            } catch (e) { window.alert('Error: ' + (e.message || e)); }
            finally { this.proposalSaving = false; }
        },
        async removeProposal() {
            if (!window.confirm('¿Quitar la propuesta guardada? La comparación pasará a usar la propuesta actual.')) return;
            this.proposalSaving = true;
            try {
                const r = await this.ws('proposal_remove', { periodid: this.result.period.id });
                if (!r.ok) { window.alert(r.message); return; }
                this.grouping = null; await this.openGroups();
            } catch (e) { window.alert('Error: ' + (e.message || e)); }
            finally { this.proposalSaving = false; }
        },
        cmpTag(st) { return { igual: 'tl-plan-tag-nivel', distinto: 'tl-plan-tag-lock', solo_tablero: 'tl-plan-tag-repite', solo_propuesta: 'tl-plan-tag-adelanto' }[st]; },
        whoName(u) { const s = this.grouping && this.grouping.byUser[u]; return s ? s.name : 'Usuario #' + u; },
        whoMeta(u) { const s = this.grouping && this.grouping.byUser[u]; return s ? this.shortPlan(s.plan) + ' · ' + s.level + ' · ' + (s.jornada || 'sin jornada') : ''; },
        // Por que un estudiante del tablero no esta en la propuesta (segun la demanda actual).
        whyBoard(u, cid) {
            const s = this.grouping && this.grouping.byUser[u];
            if (!s) return 'no es estudiante activo de la proyección';
            const sub = (s.subjects || []).find(x => Number(x.courseid) === Number(cid));
            if (!sub) {
                const o = (s.other || []).find(x => Number(x.courseid) === Number(cid));
                if (o) return this.whoMeta(u) + ' · ' + (Number(o.status) === 2 ? 'la está cursando' : 'en reválida o migración');
                return this.whoMeta(u) + ' · no le toca (ya aprobada o de otro nivel)';
            }
            if (sub.category === 'repite') return this.whoMeta(u) + ' · repitente (gestión aparte)';
            if (sub.prereq === 'no') return this.whoMeta(u) + ' · prelación pendiente';
            if (sub.flexible && !sub.flex_on) return this.whoMeta(u) + ' · no obligatoria sin activar';
            if (sub.category === 'adelanto') return this.whoMeta(u) + ' · adelanto no necesario';
            return this.whoMeta(u) + ' · otra jornada o grupo sin quórum';
        },
        fmtLong(ts) { return ts ? new Date(ts * 1000).toLocaleString('es-PA', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '-'; },
        topLevels(g) {
            return Object.keys(g.levels || {}).slice(0, 3).map(l => l + ' (' + g.levels[l] + ')').join(' · ');
        },
        exportAlerts() {
            const label = { egresa_sin_quorum: 'Egresa sin quórum', sin_quorum: 'Sin quórum', carga_baja: 'Carga baja', prelacion: 'Prelación pendiente', no_abre: 'No abre (decisión)' };
            const esc = v => '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"';
            const lines = [['Tipo', 'Estudiante', 'Carrera', 'Nivel', 'Asignatura', 'Jornada', 'Egresa', 'Detalle'].map(esc).join(',')];
            this.alertRows.forEach(a => lines.push([label[a.type] || a.type, a.name, a.plan, a.level, a.course, a.jornada, a.finishing ? 'Sí' : '', a.detail].map(esc).join(',')));
            const blob = new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url; link.download = 'alertas_' + this.result.period.name + '.csv';
            document.body.appendChild(link); link.click(); link.remove(); URL.revokeObjectURL(url);
        },
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
        selItem(r, j) {
            // Para modulo se puede elegir entre los que se cargan y los que pueden adelantarla (con prelacion).
            const pick = j.students.filter(x => x.prereq !== 'no' && !x.flex && x.cat !== 'repite');
            return { ekey: j.ekey, courseid: r.courseid, name: r.name, jornada: j.jornada, isolated: r.isolated, students: pick };
        },
        toggleSel(r, j) {
            if (this.selected[j.ekey]) { this.$delete(this.selected, j.ekey); return; }
            this.$set(this.selected, j.ekey, this.selItem(r, j));
        },
        // Decision de una sola asignatura + jornada (desde el chip o desde los grupos propuestos).
        decide(r, j) {
            const cur = j.exception || j.closed;
            this.openException([this.selItem(r, j)], cur);
        },
        decideKey(ekey) {
            for (const r of this.allRows) {
                const j = r.jornadas.find(x => x.ekey === ekey);
                if (j) { this.decide(r, j); return; }
            }
            window.alert('No se encontró la asignatura en la proyección.');
        },
        openException(list, cur) {
            const items = list || this.selectedList;
            const fin = items.some(it => it.students.some(x => x.s.finishing));
            this.exc = { show: true, mode: cur ? cur.mode : (fin ? 'modulo' : 'grupo'),
                note: cur ? cur.note : '', error: '', saving: false, current: cur || null,
                items: items.map(it => Object.assign({}, it, {
                    // Por defecto se proponen para modulo los que egresan; si no hay, todos los que se cargan.
                    userids: (cur && cur.mode === 'modulo' ? cur.userids.slice()
                        : (it.students.some(x => x.s.finishing) ? it.students.filter(x => x.s.finishing) : it.students.filter(x => x.cat !== 'adelanto'))
                            .map(x => x.s.userid)).filter((v, i, a) => a.indexOf(v) === i) })) };
        },
        decisionLabel(e) {
            if (!e) return '';
            if (e.mode === 'modulo') return 'Módulo (sin horario ni pago) para ' + e.userids.length + ' estudiante(s)';
            if (e.mode === 'cerrar') return 'No abre por decisión';
            return 'Abre como grupo por decisión';
        },
        groupTitle(g, long) {
            if (g.type === 'modulo') return 'Módulo · sin pago';
            if (g.type === 'sin_horario') return 'Sin horario';
            return 'Grupo ' + g.number + (long ? ' de ' + g.of : '');
        },
        isUnscheduled(cid) { return !!(this.result.costing && (this.result.costing.unscheduled || []).includes(Number(cid))); },
        // Sin horario: las jornadas se juntan en una sola.
        mergeJ(js) {
            const m = { jornada: 'Sin horario', ekey: js[0].ekey, ready: 0, adelanto: 0, blocked: 0, flexWaiting: 0, students: [], byPlan: {},
                finishing: 0, exception: null, closed: null, groups: 0, readyStudents: [], repite: 0 };
            js.forEach(j => {
                ['ready', 'adelanto', 'blocked', 'flexWaiting', 'finishing', 'repite'].forEach(k => { m[k] += j[k] || 0; });
                m.students = m.students.concat(j.students);
                m.readyStudents = m.readyStudents.concat(j.readyStudents);
                Object.keys(j.byPlan).forEach(p => { m.byPlan[p] = (m.byPlan[p] || 0) + j.byPlan[p]; });
            });
            m.groups = m.ready > 0 ? 1 : 0;
            return m;
        },
        async ws(section, data) {
            const body = new URLSearchParams({ wstoken: window.userToken, moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_planning_save_config', section, data: JSON.stringify(data) });
            const r = await window.axios.post(window.location.origin + '/webservice/rest/server.php', body);
            if (r.data && r.data.exception) throw new Error(r.data.message || r.data.errorcode);
            return r.data;
        },
        resetGroups() { this.grouping = null; if (this.view === 'groups') this.openGroups(); },
        async saveException() {
            this.exc.error = '';
            if (this.exc.mode === 'auto') {
                // Volver a automatico = quitar la decision registrada.
                const keys = this.exc.items.map(it => it.ekey).filter(k => this.exceptionMap[k]);
                if (!keys.length) { this.exc.show = false; return; }
                this.exc.saving = true;
                try {
                    const r = await this.ws('exception_remove', { periodid: this.result.period.id, keys });
                    if (!r.ok) { this.exc.error = r.message; return; }
                    this.exc.show = false; this.selected = {};
                    this.$emit('changed', 'Vuelve a automático: abre si alcanza el mínimo.');
                } catch (e) { this.exc.error = 'Error al guardar: ' + (e.message || e); }
                finally { this.exc.saving = false; }
                return;
            }
            if (!this.exc.note.trim()) { this.exc.error = 'Indique el motivo de la decisión.'; return; }
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
        chipSem(j) { return j.closed ? 'grey' : j.groups ? 'green' : (j.exception ? 'blue' : (j.ready + j.adelanto >= this.min ? 'orange' : 'red')); },
        chipHelp(j) {
            const mine = this.careerFilter && j.mine !== j.ready ? (j.mine + ' de esta carrera; ') : '';
            const fin = j.finishing ? ' ' + j.finishing + ' estudiante(s) egresan con este periodo.' : '';
            if (j.closed) return mine + 'No abre por decisión. Motivo: ' + j.closed.note + fin;
            if (j.exception) return mine + this.decisionLabel(j.exception) + '. Motivo: ' + j.exception.note + fin;
            if (j.groups) return mine + j.ready + ' estudiantes en ' + j.jornada + ': ' + j.groups + ' grupo(s) de hasta ' + this.max + '.' + fin;
            if (j.ready + j.adelanto >= this.min) return mine + 'No alcanza el mínimo de ' + this.min + ' por sí sola; sumando ' + j.adelanto + ' estudiantes que pueden adelantarla, sí.' + fin;
            return mine + 'No alcanza el mínimo de ' + this.min + '. Use el botón de ajustes para abrirla como grupo, como módulo o dejarla cerrada.' + fin;
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
