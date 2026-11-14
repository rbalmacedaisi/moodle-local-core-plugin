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
                <span>Proyección</span>
                <select v-if="periods.length" class="tl-cfg-input tl-proj-persel" :value="result.period.id" @change="changePeriod($event)"
                        title="Periodo que se planifica o se consulta">
                    <option v-for="p in periods" :key="p.id" :value="p.id">{{ p.name }} — {{ periodState(p) }}</option>
                </select>
                <span v-else>{{ result.period.name }}</span>
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
            <button v-if="hasBoard" class="tl-plan-tab" :class="{ 'tl-plan-tab-active': view === 'board' }" @click="openBoard">
                <v-icon size="14">mdi-calendar-edit</v-icon> Tablero de horarios</button>
            <button class="tl-plan-tab" :class="{ 'tl-plan-tab-active': view === 'modules' }" @click="openModules">
                <v-icon size="14">mdi-briefcase-outline</v-icon> Módulos y sin horario<span v-if="modRowsAll.length" class="tl-plan-tab-count">{{ modRowsAll.length }}</span></button>
        </div>

        <div class="tl-proj-toolbar" v-show="view !== 'board'">
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
        <!-- TABLERO DE HORARIOS (se mantiene vivo al cambiar de pestana para no perder cambios sin guardar) -->
        <schedule-board v-if="boardOpened" ref="board" v-show="view === 'board'" :periodid="result.period.id" :period-name="result.period.name"
                        :plan-ids="planIds" :can-edit="canEdit" @proposal-saved="grouping = null"></schedule-board>
        <!-- MODULOS Y SIN HORARIO: lo que se carga sin clase (no va al tablero) -->
        <div v-if="view === 'modules'" class="tl-proj-mods">
            <div class="tl-cfg-help mb-2">Asignaturas que se cargan <b>sin clase</b>: no tienen docente, aula ni horario y no aparecen en el tablero.
                <b>Sin horario</b> = práctica profesional y similares (Configuración → Costos y duración); se carga a todos los que les toca.
                <b>Módulo</b> = decidido en "Próximo periodo" (o al juntar cohortes) para estudiantes puntuales; cada uno avanza por su cuenta y no pasa por pago.
                Se inscriben al publicar, desde la ventana "Publicar" del tablero de horarios.</div>
            <div v-if="groupsLoading && !grouping" class="tl-plan-empty" style="padding:16px">Calculando…</div>
            <div v-else-if="groupsError" class="tl-cfg-msg tl-cfg-msg-error">{{ groupsError }}</div>
            <template v-else-if="grouping">
                <div class="tl-proj-mods-head">
                    <span><b>{{ modRows.length }}</b> asignatura(s) · <b>{{ modRows.reduce((a, r) => a + r.students.length, 0) }}</b> estudiante(s)</span>
                    <v-spacer></v-spacer>
                    <button class="tl-proj-flexbtn" :disabled="!modRows.length" @click="exportModules">
                        <v-icon size="13">mdi-download</v-icon> Exportar (Excel)</button>
                </div>
                <div v-for="r in modRows" :key="r.key" class="tl-proj-mods-row">
                    <div class="d-flex align-center" style="gap:8px;cursor:pointer" @click="$set(modOpen, r.key, !modOpen[r.key])">
                        <v-icon size="16">{{ modOpen[r.key] ? 'mdi-chevron-down' : 'mdi-chevron-right' }}</v-icon>
                        <span class="tl-plan-tag" :class="r.type === 'modulo' ? 'tl-plan-tag-nivel' : 'tl-plan-tag-muted'">{{ r.type === 'modulo' ? 'Módulo · sin pago' : 'Sin horario' }}</span>
                        <b>{{ r.name }}</b>
                        <span class="tl-cfg-help">· {{ r.type === 'sin_horario' ? 'todas las jornadas' : r.jornada }}<template v-if="r.isolated"> · solo {{ careerName(r.isolated) }}</template></span>
                        <v-spacer></v-spacer>
                        <b>{{ r.students.length }}</b>&nbsp;<span class="tl-cfg-help">estudiante(s)</span>
                    </div>
                    <div v-if="r.decision" class="tl-cfg-help" style="margin:2px 0 0 24px">Decisión: {{ decisionLabel(r.decision) }}<template v-if="r.decision.note"> — {{ r.decision.note }}</template>
                        <template v-if="r.decision.byname"> ({{ r.decision.byname }}, {{ fmt(r.decision.time) }})</template></div>
                    <div v-if="modOpen[r.key]" class="tl-proj-mods-list">
                        <div v-for="s in r.students" :key="s.key" class="tl-plan-name-row tl-proj-stu">
                            <span class="tl-proj-stu-name">{{ s.name }}</span>
                            <span v-if="s.finishing" class="tl-plan-tag tl-proj-tag-finish">egresa</span>
                            <span class="tl-proj-stu-meta">{{ shortPlan(s.plan) }} · {{ s.level }} · {{ s.jornada || 'sin jornada' }} · {{ s.documento }}</span>
                        </div>
                    </div>
                </div>
                <div v-if="!modRows.length" class="tl-plan-empty">Nada que cargar como módulo o sin horario con este filtro.</div>
            </template>
        </div>
        <div v-if="careerFilter && view !== 'board'" class="tl-proj-filter-note">
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
                            El tablero de horarios trabaja sobre esta versión.</span>
                    </template>
                    <template v-else>
                        <v-icon size="16" color="#64748B">mdi-content-save-outline</v-icon>
                        <span>La propuesta aún no está guardada. Guárdela para fijar los grupos: el tablero de horarios se arma sobre la propuesta guardada.</span>
                    </template>
                    <v-spacer></v-spacer>
                    <button v-if="canEdit" class="tl-cfg-btn" :disabled="proposalSaving" @click="saveProposal">{{ savedProposal ? 'Actualizar propuesta guardada' : 'Guardar propuesta' }}</button>
                    <button v-if="canEdit && savedProposal" class="tl-cfg-btn tl-cfg-btn-ghost" :disabled="proposalSaving" @click="removeProposal">Quitar</button>
                </div>
                <div class="tl-plan-tabs mb-3" style="max-width:640px">
                    <button class="tl-plan-tab" :class="{ 'tl-plan-tab-active': groupTab === 'list' }" @click="groupTab = 'list'">Grupos<span class="tl-plan-tab-count">{{ gSummary.groups }}</span></button>
                    <button class="tl-plan-tab" :class="{ 'tl-plan-tab-active': groupTab === 'alerts' }" @click="groupTab = 'alerts'">Alertas<span class="tl-plan-tab-count">{{ gSummary.alerts }}</span></button>
                </div>
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
            <div v-if="future && future.pooling && !futureLoading" class="tl-proj-pool">
                <div class="tl-proj-pool-head" @click="poolOpen = !poolOpen">
                    <v-icon size="16" color="#4F46E5">mdi-account-multiple-plus-outline</v-icon>
                    <b>Juntar cohortes chicas</b>
                    <span class="tl-proj-sub">propuestas para que asignaturas con pocos estudiantes alcancen el mínimo de {{ min }}</span>
                    <v-spacer></v-spacer>
                    <v-icon size="18">{{ poolOpen ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                </div>
                <template v-if="poolOpen">
                    <div class="tl-proj-pool-intro">
                        <div><b>¿Qué es esto?</b> Hay asignaturas a las que cada periodo llegan pocos estudiantes de una misma jornada
                            (menos de {{ min }}), así que nunca abren. El sistema propone <b>juntar estudiantes de periodos seguidos</b>
                            para formar un grupo que sí abra, moviendo a la menor cantidad de personas el menor tiempo posible.</div>
                        <div class="tl-proj-pool-legend">
                            <span class="tl-plan-tag tl-plan-tag-nivel">a tiempo</span> la ven cuando les toca
                            <span class="tl-plan-tag tl-plan-tag-atrasada">esperan</span> la ven uno o más periodos después
                            <span class="tl-plan-tag tl-plan-tag-adelanto">adelantan</span> la ven antes (ya tendrán sus prelaciones)
                            <span class="tl-plan-tag tl-plan-tag-lock">no se pueden mover</span> egresan antes o la necesitan de prelación
                        </div>
                        <div class="tl-cfg-help">Siempre se respetan las prelaciones, no se mezclan jornadas y nadie espera si egresa antes.
                            <b>Aplicar</b> solo registra una decisión de apertura para {{ result.period.name }}: no crea clases, no matricula ni toca notas;
                            se puede deshacer cuando quiera.</div>
                        <div v-if="future.pooling.excluded_long && future.pooling.excluded_long.length" class="tl-cfg-help mt-1">
                            <v-icon size="13">mdi-information-outline</v-icon> No se juntan las asignaturas de cuatrimestre completo
                            ({{ future.pooling.excluded_long.join(', ') }}): se dictan el cuatrimestre entero con su propia secuencia.
                            La lista está en Configuración → "Asignaturas de cuatrimestre completo".</div>
                    </div>
                    <div class="tl-plan-tabs mb-2" style="max-width:760px">
                        <button v-for="t in poolTabs" :key="t.key" class="tl-plan-tab" :class="{ 'tl-plan-tab-active': poolTab === t.key }" @click="poolTab = t.key"
                                :title="t.help">{{ t.label }}<span class="tl-plan-tab-count">{{ t.count }}</span></button>
                    </div>
                    <div class="tl-cfg-help mb-1">{{ (poolTabs.find(t => t.key === poolTab) || {}).help }}</div>
                    <div v-if="poolTab !== 'unsolved'" class="tl-proj-pool-list">
                        <div v-for="pl in poolRows" :key="pl.key" class="tl-proj-pool-row" :class="{ 'tl-proj-pool-applied': poolApplied(pl) }">
                            <div style="flex:1;min-width:0">
                                <div><b>{{ pl.name }}</b> <span class="tl-cfg-help">· {{ pl.jornada || 'sin jornada' }}<template v-if="pl.isolated_plan"> · solo {{ careerName(pl.isolated_plan) }}</template></span></div>
                                <div class="tl-proj-pool-say">{{ poolSentence(pl) }}</div>
                                <div v-if="pl.why_not_now" class="tl-proj-pool-why"><v-icon size="13" color="#64748B">mdi-help-circle-outline</v-icon>
                                    <b>¿Por qué no abrirla ahora juntando a todos?</b> {{ whyNotNow(pl) }}</div>
                                <div class="tl-proj-pool-chips">
                                    <span v-for="c in pl.cohorts" :key="c.k" class="tl-plan-tag" :class="poolTag(c.action)">{{ c.period }}: {{ c.students.length }} {{ poolAction(c) }}</span>
                                    <span v-if="pl.base" class="tl-plan-tag tl-plan-tag-nivel">grupo que ya abre: {{ pl.base }}</span>
                                    <span v-if="pl.blocked.length" class="tl-plan-tag tl-plan-tag-lock">{{ pl.blocked.length }} no se pueden mover</span>
                                </div>
                                <div v-if="poolApplied(pl)" class="tl-proj-pool-done"><v-icon size="14" color="#15803D">mdi-check-circle</v-icon>
                                    Aplicado en {{ result.period.name }}: {{ decisionLabel(poolException(pl)) }}</div>
                                <div v-else-if="poolException(pl)" class="tl-proj-pool-warn"><v-icon size="14" color="#B45309">mdi-alert-outline</v-icon>
                                    Ya tiene otra decisión en {{ result.period.name }}: {{ decisionLabel(poolException(pl)) }}. Si aplica esta propuesta, la reemplaza.</div>
                            </div>
                            <button class="tl-proj-flexbtn" @click="poolDetail = pl" title="Ver la lista de estudiantes de cada periodo">Ver estudiantes</button>
                            <template v-if="canEdit && poolApplicable(pl)">
                                <button v-if="poolApplied(pl)" class="tl-proj-flexbtn" @click="openPoolUndo(pl)">Deshacer</button>
                                <button v-else class="tl-cfg-btn" @click="openPoolApply(pl)" :title="poolBtnHelp(pl)">{{ poolBtn(pl) }}…</button>
                            </template>
                        </div>
                        <div v-if="!poolRows.length" class="tl-plan-empty">Nada que juntar con este filtro.</div>
                    </div>
                    <div v-else class="tl-proj-pool-list">
                        <div class="tl-cfg-help mb-1">No llegan a {{ min }} ni juntando los periodos en que pueden moverse sin romper prelaciones. Se pueden abrir por
                            excepción o como módulo desde "Próximo periodo".<template v-if="future.pooling.unsolved_later"> Hay además {{ future.pooling.unsolved_later }}
                            casos en periodos lejanos: cambian con los nuevos ingresos.</template></div>
                        <div v-for="u in poolUnsolved" :key="u.key" class="tl-proj-pool-row">
                            <div style="flex:1"><b>{{ u.name }}</b> <span class="tl-cfg-help">· {{ u.jornada || 'sin jornada' }} · {{ u.students.length }} estudiante(s)</span>
                                <span v-if="poolException(u)" class="tl-plan-tag tl-plan-tag-nivel">ya decidido: {{ decisionLabel(poolException(u)).toLowerCase() }}</span></div>
                            <button class="tl-proj-flexbtn" @click="poolDetail = Object.assign({ unsolved: true, cohorts: [{ k: 0, period: u.period, action: 'a_tiempo', shift: 0, students: u.students }], blocked: [] }, u)">Ver estudiantes</button>
                        </div>
                    </div>
                </template>
            </div>
            <v-dialog :value="!!poolDlg" @input="v => { if (!v && !poolDlg.saving) poolDlg = null; }" max-width="720" scrollable>
                <v-card v-if="poolDlg">
                    <v-card-title class="d-flex">
                        <div><div class="text-subtitle-1 font-weight-bold">{{ poolDlg.undo ? 'Deshacer: ' : '' }}{{ poolDlg.title }}</div>
                            <div class="tl-cfg-help">{{ poolDlg.p.name }} · {{ poolDlg.p.jornada || 'Sin jornada' }} · periodo {{ result.period.name }}</div></div>
                        <v-spacer></v-spacer><v-btn icon :disabled="poolDlg.saving" @click="poolDlg = null"><v-icon>mdi-close</v-icon></v-btn>
                    </v-card-title>
                    <v-card-text>
                        <div v-if="poolDlg.replacing" class="tl-cfg-msg tl-cfg-msg-error mb-2">Esta asignatura ya tiene la decisión
                            «{{ decisionLabel(poolDlg.replacing) }}»<template v-if="poolDlg.replacing.note"> (motivo: {{ poolDlg.replacing.note }})</template>.
                            Si aplica, se reemplaza por esta.</div>
                        <div class="tl-proj-pool-sec"><v-icon size="16" color="#4F46E5">mdi-arrow-right-circle-outline</v-icon> <b>Qué va a pasar</b></div>
                        <ul class="tl-proj-pool-ul"><li v-for="(t, i) in poolDlg.will" :key="'w' + i">{{ t }}</li></ul>
                        <div class="tl-proj-pool-sec"><v-icon size="16" color="#15803D">mdi-shield-check-outline</v-icon> <b>Qué NO pasa</b></div>
                        <ul class="tl-proj-pool-ul">
                            <li>No se crean ni se borran clases, no se matricula a nadie y no se tocan notas ni el avance de los estudiantes.</li>
                            <li>Las clases solo se crean cuando se publica el horario desde el tablero, con su propia confirmación.</li>
                            <li v-if="!poolDlg.undo">Se puede deshacer en cualquier momento con «Deshacer» en esta misma fila.</li>
                        </ul>
                        <template v-for="grp in poolDlg.lists">
                            <div :key="'h' + grp.label" class="tl-plan-detail-block">{{ grp.label }} ({{ grp.keys.length }})</div>
                            <div v-for="k in grp.keys" :key="grp.label + k" class="tl-plan-name-row tl-proj-stu">
                                <span class="tl-proj-stu-name">{{ stuName(k) }}</span><span class="tl-proj-stu-meta">{{ stuMeta(k) }}</span></div>
                        </template>
                        <div v-if="poolDlg.error" class="tl-cfg-msg tl-cfg-msg-error mt-2">{{ poolDlg.error }}</div>
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn text :disabled="poolDlg.saving" @click="poolDlg = null">Cancelar</v-btn>
                        <v-btn color="primary" depressed :loading="poolDlg.saving" @click="confirmPool">{{ poolDlg.undo ? 'Sí, deshacer' : 'Sí, ' + poolDlg.title.toLowerCase() }}</v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>
            <v-dialog :value="!!poolDetail" @input="v => { if (!v) poolDetail = null; }" max-width="720" scrollable>
                <v-card v-if="poolDetail">
                    <v-card-title class="d-flex">
                        <div><div class="text-subtitle-1 font-weight-bold">{{ poolDetail.name }}</div>
                            <div class="tl-cfg-help">{{ poolDetail.jornada || 'Sin jornada' }} ·
                                <template v-if="poolDetail.unsolved">sin solución: {{ poolDetail.reason }}</template>
                                <template v-else>abre en {{ poolDetail.open_period }} con {{ poolDetail.total }}</template></div></div>
                        <v-spacer></v-spacer><v-btn icon @click="poolDetail = null"><v-icon>mdi-close</v-icon></v-btn>
                    </v-card-title>
                    <v-card-text>
                        <div v-if="poolDetail.base" class="tl-cfg-help mb-2">Se suma a un grupo de {{ poolDetail.base }} estudiantes que ya abre en {{ poolDetail.open_period }}.</div>
                        <div v-for="c in poolDetail.cohorts" :key="c.k" class="mb-2">
                            <div class="tl-plan-detail-block">{{ c.period }} · {{ c.students.length }} {{ poolAction(c) }}</div>
                            <div v-for="k in c.students" :key="k" class="tl-plan-name-row tl-proj-stu">
                                <span class="tl-proj-stu-name">{{ stuName(k) }}</span><span class="tl-proj-stu-meta">{{ stuMeta(k) }}</span></div>
                        </div>
                        <template v-if="poolDetail.blocked.length">
                            <div class="tl-plan-detail-block">No se pueden mover ({{ poolDetail.blocked.length }}): siguen en su periodo</div>
                            <div v-for="b in poolDetail.blocked" :key="'b' + b.key" class="tl-plan-name-row tl-proj-stu">
                                <span class="tl-proj-stu-name">{{ stuName(b.key) }}</span><span class="tl-proj-stu-meta">{{ future.pooling.periods[b.k] }} · {{ b.reason }}</span></div>
                        </template>
                    </v-card-text>
                </v-card>
            </v-dialog>
            <div v-if="futureLoading" class="tl-plan-empty" style="padding:16px">Calculando periodos siguientes…</div>
            <div v-else-if="futureError" class="tl-cfg-msg tl-cfg-msg-error">{{ futureError }}</div>
            <div v-else-if="future" class="tl-proj-matrix-wrap">
                <div v-if="future.published" class="tl-cfg-msg mb-2" style="background:#EEF2FF;color:#312E81">
                    <b>{{ result.period.name }} ya está publicado:</b> su columna muestra solo lo que quedó cargado en sus clases y módulos.
                    Lo que le tocaba a cada estudiante y no se le cargó (grupos sin publicar, excluidos por choques, decisiones de no abrir)
                    pasa a {{ futureCols[1] ? futureCols[1].name : 'el periodo siguiente' }}; lo cargado se da por cursado.</div>
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
                                    <div v-if="poolMarks[r.key + '@' + c.key]" class="tl-proj-mx-pool" :class="{ 'tl-proj-mx-pool-done': poolMarks[r.key + '@' + c.key].applied }"
                                         :title="poolMarks[r.key + '@' + c.key].text">{{ poolMarks[r.key + '@' + c.key].label }}</div>
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
        <!-- PERIODO YA PUBLICADO: lo que se agregue se suma a lo publicado -->
        <div v-if="result.published" class="tl-proj-pubnote">
            <v-icon size="18" color="#1D4ED8">mdi-calendar-check</v-icon>
            <div style="flex:1">
                <b>{{ result.period.name }} ya está publicado{{ periodRunning ? ' y en curso' : '' }}.</b>
                Lo que agregue aquí se suma a lo publicado sin cambiar lo que ya existe: con <v-icon size="12">mdi-tune-variant</v-icon>
                en cada jornada puede <b>agregar un grupo al tablero</b> (aunque tenga 0 o pocos estudiantes) y publicarlo desde el
                Tablero de horarios; solo se programan las fechas que quedan.
            </div>
            <button v-if="canEdit" class="tl-proj-flexbtn" @click="openEnrol">Sugerencias de matrícula</button>
        </div>
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
                        <template v-else-if="poolWait(j)"> → <b>{{ poolWait(j) }}</b></template>
                        <template v-else-if="j.closed"> → no abre (decisión)</template>
                        <template v-else-if="j.exception && j.exception.mode === 'modulo'"> → módulo ({{ j.exception.userids.length }})</template>
                        <template v-else-if="gsize(j)"> → <b class="tl-proj-gsize">{{ gsizeLabel(j) }}</b><template v-if="j.exception"> (decisión)</template></template>
                        <template v-else-if="j.exception"> → excepción: abre</template>
                        <template v-else-if="j.groups"> → {{ j.groups }} {{ j.groups === 1 ? 'grupo' : 'grupos' }}</template>
                        <template v-else-if="j.ready + j.adelanto >= min"> → con adelantos</template>
                        <template v-else> → no alcanza</template>
                        <v-icon v-if="j.finishing" size="11" :color="j.exception ? '#1D4ED8' : '#B91C1C'" title="Hay estudiantes que egresan">mdi-school</v-icon>
                        <button v-if="canEdit && !r.unscheduled" class="tl-proj-decide"
                                @click.stop="decide(r, j)" title="Decidir cómo abre: automático, grupo, módulo o no abrir">
                            <v-icon size="12">mdi-tune-variant</v-icon></button>
                    </span>
                </div>
                <div v-if="rowGroups(r)" class="tl-proj-num" :title="rowGroupsHelp(r)">
                    <b>{{ rowGroups(r).total }}</b>
                    <div class="tl-proj-num-sub">en {{ rowGroups(r).n === 1 ? 'el grupo' : rowGroups(r).n + ' grupos' }}<template v-if="rowGroups(r).adel"> · {{ rowGroups(r).total - rowGroups(r).adel }} + {{ rowGroups(r).adel }} adel.</template></div>
                </div>
                <div v-else class="tl-proj-num" :title="(careerFilter ? r.mine : r.ready) + ' se cargan + ' + r.adelanto + ' pueden adelantar'">
                    <b>{{ (careerFilter ? r.mine : r.ready) + r.adelanto }}</b>
                    <div v-if="r.adelanto" class="tl-proj-num-sub">{{ careerFilter ? r.mine : r.ready }} + {{ r.adelanto }} adel.</div>
                    <div v-else-if="groupsLoading" class="tl-proj-num-sub">calculando grupos…</div>
                </div>
            </div>
            <div v-if="open[r.key]" class="tl-proj-detail">
                <div v-for="j in r.jornadas.filter(visibleJ)" :key="j.jornada" class="tl-proj-detail-col">
                    <div class="tl-plan-detail-block">{{ j.jornada }} • {{ j.ready }} les toca
                        <template v-if="j.adelanto"> · {{ j.adelanto }} podrían adelantar</template></div>
                    <div v-if="gsize(j)" class="tl-proj-gsize-note">
                        <v-icon size="13" color="#4F46E5">mdi-account-group</v-icon>
                        <b>{{ gsizeLabel(j) }}</b>: {{ gsize(j).ready }} a los que les toca + {{ gsize(j).adel }} que adelantan<template v-if="j.adelanto > gsize(j).adel">.
                        Los otros {{ j.adelanto - gsize(j).adel }} que podrían adelantar no entran: ya tienen carga suficiente sin ella</template>.
                        Los que entran llevan la marca <span class="tl-plan-tag tl-proj-tag-in">en el grupo</span>.
                    </div>
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
                        <span v-if="x.cat === 'adelanto' && gsize(j) && gsize(j).keys[x.key]" class="tl-plan-tag tl-proj-tag-in" title="Esta adelantando y entra en el grupo propuesto">en el grupo</span>
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
        <div class="tl-plan-legend" v-show="view !== 'board'">
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
                        <div v-if="exc.mode === 'modulo'" class="tl-cfg-help" style="margin:4px 0">
                            Elegir: <button class="tl-proj-flexbtn" @click="it.userids = uniq(it.students.map(x => x.s.userid))">todos</button>
                            <button class="tl-proj-flexbtn" @click="it.userids = uniq(it.students.filter(x => x.s.finishing).map(x => x.s.userid))">solo los que egresan</button>
                            <button class="tl-proj-flexbtn" @click="it.userids = []">ninguno</button>
                            • {{ it.userids.length }} elegido(s)
                        </div>
                        <template v-if="exc.mode === 'adicional'">
                            <div class="tl-cfg-help" style="margin:4px 0">
                                Estudiantes del grupo: <button class="tl-proj-flexbtn" @click="it.userids = uniq(it.students.filter(x => !x.loaded && x.cat !== 'adelanto').map(x => x.s.userid))">los que no están cargados</button>
                                <button class="tl-proj-flexbtn" @click="it.userids = []">ninguno (grupo vacío)</button>
                                • {{ it.userids.length }} elegido(s)<template v-if="!it.userids.length"> — se publica con su horario y advertencia; se matriculan después</template>
                            </div>
                            <div v-if="it.students.length" class="tl-proj-exc-students">
                                <label v-for="x in it.students" :key="'a' + x.s.userid + '-' + x.s.planid" class="tl-cfg-check" :style="x.loaded ? 'opacity:.55' : ''">
                                    <input type="checkbox" :value="x.s.userid" v-model="it.userids" :disabled="x.loaded">
                                    {{ x.s.name }} <span class="tl-cfg-help">{{ shortPlan(x.s.plan) }} · {{ x.s.level }} · {{ catLabel[x.cat] || x.cat }}</span>
                                    <span v-if="x.loaded" class="tl-plan-tag tl-plan-tag-muted">ya cargado</span>
                                </label>
                            </div>
                        </template>
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
                    <v-btn color="primary" depressed :loading="exc.saving" @click="saveException">{{ exc.mode === 'auto' ? 'Volver a automático' : (exc.mode === 'adicional' ? 'Enviar al tablero' : 'Registrar decisión') }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

        <!-- Sugerencias de matricula (periodo publicado) -->
        <v-dialog v-model="enr.show" max-width="1100" scrollable>
            <v-card>
                <v-card-title class="text-subtitle-1 font-weight-bold">Sugerencias de matrícula · {{ result.period.name }}</v-card-title>
                <v-card-text>
                    <div class="tl-cfg-help mb-2">Estudiantes a los que les toca la asignatura en {{ result.period.name }} (nivel o atrasada, con
                    prelaciones) y no están cargados en ninguna clase o módulo, con los grupos del periodo que les sirven. Se marca la sugerida:
                    misma jornada, sin choque de horario y con cupo. Al aplicar, el estudiante entra a la cola de la clase y, si la clase ya
                    está aprobada, queda matriculado en el grupo y en su avance (mismo camino que la gestión de clases).</div>
                    <div v-if="enr.loading" class="tl-cfg-help">Buscando…</div>
                    <div v-else-if="enr.error" class="tl-cfg-msg tl-cfg-msg-error">{{ enr.error }}</div>
                    <template v-else>
                        <div class="d-flex flex-wrap align-center mb-2" style="gap:6px">
                            <button v-for="f in enrFilters" :key="f.key" class="tl-plan-tab" :class="{ 'tl-plan-tab-active': enr.filter === f.key }"
                                    @click="enr.filter = f.key">{{ f.label }} <span class="tl-plan-tab-count">{{ f.count }}</span></button>
                            <input v-model="enr.q" class="tl-cfg-input" style="width:220px" placeholder="Buscar estudiante o asignatura…">
                        </div>
                        <div v-if="enr.done" class="tl-cfg-msg" :class="enr.doneOk ? 'tl-cfg-msg-ok' : 'tl-cfg-msg-error'">{{ enr.done }}</div>
                        <table class="tl-proj-enrtable">
                            <thead><tr><th></th><th>Estudiante</th><th>Asignatura</th><th>Motivo</th><th>Grupo</th></tr></thead>
                            <tbody>
                                <tr v-for="r in enrRows" :key="r.userid + '-' + r.courseid" :class="{ 'tl-proj-enr-done': r.result }">
                                    <td><input type="checkbox" v-model="r.pick" :disabled="!r.classid || !!r.result || enr.saving"></td>
                                    <td><b>{{ r.name }}</b><div class="tl-cfg-help">{{ shortPlan(r.plan) }} · {{ r.level }} · {{ r.jornada }}</div></td>
                                    <td>{{ r.course }}<div class="tl-cfg-help">{{ r.category }}</div></td>
                                    <td><span class="tl-plan-tag" :class="r.is_new ? 'tl-plan-tag-nivel' : 'tl-plan-tag-muted'">{{ r.why }}</span></td>
                                    <td>
                                        <div v-if="r.result" :style="r.result.status === 'ok' ? 'color:#047857' : 'color:#B91C1C'">{{ r.result.message }}</div>
                                        <template v-else>
                                            <select class="tl-cfg-input" style="max-width:420px" v-model.number="r.classid" @change="r.pick = !!r.classid">
                                                <option :value="0">— no matricular —</option>
                                                <option v-for="o in r.options" :key="o.classid" :value="o.classid">{{ optLabel(o) }}</option>
                                            </select>
                                            <div v-if="optOf(r) && optOf(r).clash.length" class="tl-cfg-help" style="color:#B91C1C">Choca con {{ optOf(r).clash.join(', ') }}</div>
                                            <div v-else-if="optOf(r) && optOf(r).full" class="tl-cfg-help" style="color:#B45309">Grupo lleno: no se matricula (cupo del aula)</div>
                                            <div v-else-if="optOf(r) && !optOf(r).same_jornada" class="tl-cfg-help" style="color:#B45309">Otra jornada que la del estudiante</div>
                                        </template>
                                    </td>
                                </tr>
                                <tr v-if="!enrRows.length"><td colspan="5" class="tl-cfg-help">No hay sugerencias con este filtro.</td></tr>
                            </tbody>
                        </table>
                    </template>
                </v-card-text>
                <v-card-actions>
                    <span class="tl-cfg-help">{{ enrPicked.length }} marcada(s)</span>
                    <v-spacer></v-spacer>
                    <v-btn text @click="enr.show = false">Cerrar</v-btn>
                    <v-btn color="primary" depressed :loading="enr.saving" :disabled="!enrPicked.length" @click="applyEnrol">Matricular marcadas</v-btn>
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
        // Periodos para el selector (del WS) y el que se abre por defecto (el proximo por empezar).
        periods: { type: Array, default: () => [] },
        defaultPeriod: { type: Number, default: 0 },
        min: { type: Number, default: 12 },
        max: { type: Number, default: 30 },
    },
    watch: {
        'enr.show'(v) {
            if (!v && this.enrChanged) { this.enrChanged = false; this.$emit('changed', 'Matrículas aplicadas: se recalcula la proyección.'); }
        },
        // Tras una decision se recalcula: los grupos y los periodos siguientes se vuelven a precargar.
        result() { this.prefetch(true); },
    },
    mounted() { this.prefetch(); },
    data() {
        return { q: '', filter: 'all', jornada: '', careerFilter: 0, open: {}, selected: {},
            view: 'next', boardOpened: false, future: null, poolOpen: true, poolTab: 'next', poolDetail: null, poolDlg: null, futureLoading: false, futureError: '', cellDetail: null, costOpen: false, openRep: {},
            grouping: null, groupsLoading: false, groupsError: '', modOpen: {}, groupTab: 'list', groupDetail: null, alertType: 'all', alertLimit: 200,
            savedProposal: null, proposalSaving: false,
            exc: { show: false, mode: 'grupo', items: [], note: '', error: '', saving: false },
            enr: { show: false, loading: false, error: '', rows: [], filter: 'sugeridas', q: '', saving: false, done: '', doneOk: true }, enrChanged: false,
            catLabel: { nivel: 'nivel', atrasada: 'atrasada', repite: 'repite', adelanto: 'adelanto' },
            decisionShort: { grupo: 'abre por decisión', modulo: 'módulo', cerrar: 'no abre' },
            decisionModes: [
                { key: 'auto', label: 'Automático', help: 'Abre si alcanza el mínimo de estudiantes; si no, queda como alerta.' },
                { key: 'grupo', label: 'Abrir como grupo', help: 'Abre con docente y horario aunque no alcance el mínimo.' },
                { key: 'modulo', label: 'Abrir como módulo', help: 'Sin docente, horario ni sesiones: cada estudiante elegido avanza por su cuenta hasta el fin del periodo. No pasa por pago.' },
                { key: 'cerrar', label: 'No abrir', help: 'No abre aunque alcance el mínimo. Sus estudiantes quedan en alertas para reubicarlos.' },
                { key: 'adicional', label: 'Agregar grupo al tablero', help: 'Un grupo más de esta asignatura en esta jornada, con los estudiantes que elija (o ninguno), que va directo al Tablero de horarios para ubicarlo y publicarlo. Sirve con el periodo ya publicado (se suma sin tocar lo publicado) y para abrir con 0 o pocos estudiantes (se publica con advertencia).' },
            ] };
    },
    computed: {
        periodRunning() {
            const p = (this.periods || []).find(x => Number(x.id) === Number(this.result.period.id));
            return !!p && p.state === 'en_curso';
        },
        enrFilters() {
            const rows = this.enr.rows;
            return [
                { key: 'sugeridas', label: 'Con grupo sugerido', count: rows.filter(r => r.suggested).length },
                { key: 'nuevos', label: 'Nuevos', count: rows.filter(r => r.is_new).length },
                { key: 'todas', label: 'Todas', count: rows.length },
            ];
        },
        enrRows() {
            const q = (this.enr.q || '').toLowerCase();
            return this.enr.rows.filter(r => (this.enr.filter === 'todas' || (this.enr.filter === 'nuevos' ? r.is_new : r.suggested))
                && (!q || (r.name + ' ' + r.course).toLowerCase().includes(q)));
        },
        enrPicked() {
            return this.enr.rows.filter(r => r.pick && r.classid && !r.result);
        },
        jornadaStarts() {
            const js = this.result.period.jornada_starts || {};
            return Object.keys(js).map(k => ({ name: k, ts: js[k] })).sort((a, b) => a.ts - b.ts);
        },
        careerOpts() {
            return this.careers.map(c => ({ id: Number(c.id), short: this.shortPlan(c.name) }))
                .sort((a, b) => a.short.localeCompare(b.short));
        },
        // Grupos de la propuesta automática por asignatura + jornada (clave igual a la de las decisiones).
        groupSizes() {
            if (!this.grouping || !this.grouping.groups) return null;
            const out = {};
            this.grouping.groups.forEach(g => {
                if (g.type === 'modulo' || g.type === 'sin_horario') return;
                const k = g.courseid + '|' + (g.jornada || '') + '|' + (g.isolated_plan || 0);
                const o = out[k] || (out[k] = { sizes: [], total: 0, adel: 0, ready: 0, keys: {} });
                const n = (g.students || []).length, a = (g.adel || []).length;
                o.sizes.push(n); o.total += n; o.adel += a; o.ready += n - a;
                (g.adel || []).forEach(x => { o.keys[x] = true; });
            });
            return out;
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
                const studs = d.students.map(x => ({ key: x.key, s: students[x.key], cat: x.category, prereq: x.prereq, flex: !!x.flex, loaded: !!x.loaded }))
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
        // Juntar cohortes: filtros de carrera, jornada y busqueda.
        poolVisible() {
            if (!this.future || !this.future.pooling) return [];
            const cf = this.careerFilter, jf = this.jornada, q = this.q.toLowerCase(), st = this.future.students || {};
            return this.future.pooling.pools.filter(p => (!jf || (p.jornada || 'Sin jornada') === jf) && (!q || p.name.toLowerCase().includes(q))
                && (!cf || p.cohorts.some(c => c.students.some(k => st[k] && st[k].planid === cf))));
        },
        poolStats() {
            const v = this.poolVisible;
            return { now: v.filter(p => p.open_k === 0 && !p.base).length, wait: v.filter(p => p.open_k > 0 && p.affects_next).length,
                join: v.filter(p => p.open_k === 0 && p.base).length, unsolved: this.poolUnsolved.length };
        },
        poolTabs() {
            const v = this.poolVisible;
            return [
                { key: 'next', label: 'Para decidir en ' + this.result.period.name, count: v.filter(p => p.affects_next && !p.base).length,
                    help: 'Asignaturas que cambian en ' + this.result.period.name + ': abrirlas ya juntando estudiantes que la adelantan, o dejarlas para un periodo siguiente y juntarlas allí.' },
                { key: 'join', label: 'Sumar a grupos que ya abren', count: this.poolStats.join,
                    help: 'Grupos que ya abren en ' + this.result.period.name + ' a los que se puede sumar una cohorte chica que vendría después (opcional: evita que esa cohorte quede sola).' },
                { key: 'later', label: 'Más adelante', count: v.filter(p => !p.affects_next).length,
                    help: 'Juntas que ocurrirían en periodos siguientes. Solo informativo: no cambian nada en ' + this.result.period.name + '.' },
                { key: 'unsolved', label: 'Sin solución', count: this.poolUnsolved.length, help: 'Ni juntando periodos alcanzan el mínimo.' },
            ];
        },
        poolRows() {
            const t = this.poolTab;
            return this.poolVisible.filter(p => t === 'next' ? (p.affects_next && !p.base) : t === 'join' ? (p.open_k === 0 && p.base) : !p.affects_next);
        },
        poolUnsolved() {
            if (!this.future || !this.future.pooling) return [];
            const jf = this.jornada, q = this.q.toLowerCase();
            return this.future.pooling.unsolved.filter(u => (!jf || (u.jornada || 'Sin jornada') === jf) && (!q || u.name.toLowerCase().includes(q)));
        },
        // Celdas de la matriz que participan en un grupo por juntar: clave fila@columna -> texto.
        // Marcas de la matriz por celda: sugerencia de juntar cohortes o decision ya aplicada en el periodo que se planifica.
        poolMarks() {
            const out = {};
            if (!this.future || !this.future.pooling) return out;
            this.future.pooling.pools.forEach(p => {
                const applied = this.poolApplied(p);
                p.cohorts.forEach(c => {
                    const key = p.courseid + '|' + (p.isolated_plan || 0) + '@' + (c.k === 0 ? 'next' : 'h' + c.k);
                    const opens = c.k === p.open_k;
                    const txt = (applied ? 'DECIDIDO — ' : 'Sugerencia — ') + (p.jornada || 'sin jornada') + ': ' + c.students.length + ' ' + this.poolAction(c)
                        + ' → abre en ' + p.open_period + ' con ' + p.total;
                    const m = out[key] || { text: '', applied: false, label: '' };
                    m.text = m.text ? m.text + '\n' + txt : txt;
                    m.applied = m.applied || applied;
                    m.label = applied ? (opens ? '✓ abre con ' + p.total : '✓ se junta en ' + p.open_period) : '↔ se junta';
                    out[key] = m;
                });
            });
            return out;
        },
        // Lo que se carga sin clase (modulos y sin horario), de la propuesta de grupos.
        modRowsAll() {
            if (!this.grouping || !this.grouping.groups) return [];
            const st = this.grouping.st || {};
            return this.grouping.groups.filter(g => g.type === 'modulo' || g.type === 'sin_horario').map(g => ({
                key: g.courseid + '|' + g.jornada + '|' + (g.isolated_plan || 0) + '|' + g.number, type: g.type, courseid: g.courseid,
                name: g.name, jornada: g.jornada, isolated: g.isolated_plan || 0,
                decision: g.type === 'modulo' ? (this.exceptionMap[g.courseid + '|' + g.jornada + '|' + (g.isolated_plan || 0)] || null) : null,
                students: (g.students || []).map(k => Object.assign({ key: k }, st[k] || { name: k }))
                    .sort((a, b) => String(a.name).localeCompare(String(b.name))),
            })).sort((a, b) => (a.type === b.type ? 0 : a.type === 'sin_horario' ? -1 : 1) || a.name.localeCompare(b.name));
        },
        modRows() {
            const q = this.q.toLowerCase(), jf = this.jornada, cf = this.careerFilter;
            return this.modRowsAll.filter(r => (!q || r.name.toLowerCase().includes(q)) && (!jf || r.type === 'sin_horario' || r.jornada === jf))
                .map(r => cf ? Object.assign({}, r, { students: r.students.filter(s => s.planid === cf) }) : r)
                .filter(r => r.students.length);
        },
        hasBoard() { return !!window.GmkBoard && !!Vue.options.components['schedule-board']; },
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
                { key: 'espera', label: 'Esperan para juntarse', count: c.espera || 0, help: 'La ven en un periodo siguiente junto con otra cohorte (Próximos periodos → Juntar cohortes)' },
            ];
        },
        alertRows() {
            const order = { egresa_sin_quorum: 0, sin_quorum: 1, no_abre: 2, espera: 3, carga_baja: 4, prelacion: 5 };
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
            const pub = !!this.future.published;
            (this.result.demand || []).forEach(d => {
                if (pub) {
                    // Periodo publicado: solo lo que quedo cargado en sus clases o modulos; lo demas pasa al siguiente.
                    const lk = d.students.filter(x => x.loaded).map(x => x.key);
                    if (lk.length) add('next', d.courseid, d.name, Number(d.isolated_plan || 0), d.jornada, lk.length, 0, lk, Object.keys(d.levels), false, lk.length);
                    return;
                }
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
            await this.loadGrouping();
        },
        // Arma la propuesta de grupos (solo lectura). Tambien se usa en "Próximo periodo" para mostrar el tamaño real de cada grupo.
        async loadGrouping(force) {
            if ((this.grouping && !force) || this.groupsLoading || !this.result || !this.result.period) return;
            if (!this.grouping) this.groupsLoading = true;
            this.groupsError = '';
            const pid = this.result.period.id;
            try {
                const r = await window.axios.get(window.location.origin + '/webservice/rest/server.php', { params: {
                    wstoken: window.userToken, moodlewsrestformat: 'json', wsfunction: 'local_grupomakro_planning_get_demand',
                    periodid: this.result.period.id, planids: this.planIds, groups: 1 } });
                if (r.data && r.data.exception) throw new Error(r.data.message || r.data.errorcode);
                const d = JSON.parse(r.data.data);
                if (!this.result || this.result.period.id !== pid) return;
                const byUser = {};
                Object.values(d.result.students || {}).forEach(s => { byUser[s.userid] = s; });
                this.grouping = Object.freeze(Object.assign({ st: d.result.students || {}, byUser }, d.result.grouping || { groups: [], alerts: [] }));
                this.savedProposal = d.result.saved_proposal || null;
            } catch (e) { this.groupsError = 'No se pudo armar la propuesta: ' + (e.message || e); }
            finally { this.groupsLoading = false; }
        },
        async saveProposal() {
            const msg = (this.savedProposal ? 'Actualizar la propuesta guardada' : 'Guardar la propuesta') + ' de ' + this.result.period.name
                + ' (' + this.grouping.groups.length + ' grupos)? Se guarda aparte en el periodo y el tablero de horarios se arma sobre ella. No crea clases.';
            if (!window.confirm(msg)) return;
            this.proposalSaving = true;
            try {
                const r = await this.ws('proposal_save', { periodid: this.result.period.id });
                if (!r.ok) { window.alert(r.message); return; }
                this.grouping = null; await this.openGroups();
            } catch (e) { window.alert('Error: ' + (e.message || e)); }
            finally { this.proposalSaving = false; }
        },
        async removeProposal() {
            if (!window.confirm('¿Quitar la propuesta guardada? El tablero de horarios no podrá abrirse hasta guardar otra.')) return;
            this.proposalSaving = true;
            try {
                const r = await this.ws('proposal_remove', { periodid: this.result.period.id });
                if (!r.ok) { window.alert(r.message); return; }
                this.grouping = null; await this.openGroups();
            } catch (e) { window.alert('Error: ' + (e.message || e)); }
            finally { this.proposalSaving = false; }
        },
        fmtLong(ts) { return ts ? new Date(ts * 1000).toLocaleString('es-PA', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '-'; },
        topLevels(g) {
            return Object.keys(g.levels || {}).slice(0, 3).map(l => l + ' (' + g.levels[l] + ')').join(' · ');
        },
        exportAlerts() {
            const label = { egresa_sin_quorum: 'Egresa sin quórum', sin_quorum: 'Sin quórum', carga_baja: 'Carga baja', prelacion: 'Prelación pendiente', no_abre: 'No abre (decisión)', espera: 'Espera para juntarse' };
            const esc = v => '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"';
            const lines = [['Tipo', 'Estudiante', 'Carrera', 'Nivel', 'Asignatura', 'Jornada', 'Egresa', 'Detalle'].map(esc).join(',')];
            this.alertRows.forEach(a => lines.push([label[a.type] || a.type, a.name, a.plan, a.level, a.course, a.jornada, a.finishing ? 'Sí' : '', a.detail].map(esc).join(',')));
            const blob = new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url; link.download = 'alertas_' + this.result.period.name + '.csv';
            document.body.appendChild(link); link.click(); link.remove(); URL.revokeObjectURL(url);
        },
        // Texto de una jornada de "Proximo periodo" cuando se aplico "esperar para juntarse" (cerrar o modulo con pool).
        poolWait(j) {
            const e = j.exception || j.closed;
            if (!e || !e.pool || (e.mode !== 'cerrar' && e.mode !== 'modulo')) return '';
            const p = this.future && this.future.pooling
                ? this.future.pooling.pools.find(x => this.poolEkey(x) === j.ekey && x.open_k > 0) : null;
            const mod = e.mode === 'modulo' ? e.userids.length : 0;
            const wait = Math.max(0, j.ready - mod);
            return (wait ? wait + ' esperan para juntarse' + (p ? ' (abre en ' + p.open_period + ' con ' + p.total + ')' : '') : 'no abre')
                + (mod ? ' · ' + mod + ' como módulo' : '');
        },
        openBoard() { this.view = 'board'; this.boardOpened = true; },
        async openModules() { this.view = 'modules'; await this.loadGrouping(); },
        exportModules() {
            const esc = v => '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"';
            const lines = [['Tipo', 'Asignatura', 'Jornada', 'Estudiante', 'Documento', 'Correo', 'Carrera', 'Nivel', 'Egresa', 'Decisión'].map(esc).join(',')];
            this.modRows.forEach(r => r.students.forEach(s => lines.push([r.type === 'modulo' ? 'Módulo' : 'Sin horario', r.name,
                r.type === 'sin_horario' ? 'Todas' : r.jornada, s.name, s.documento, s.email, s.plan, s.level, s.finishing ? 'Sí' : '',
                r.decision ? this.decisionLabel(r.decision) + (r.decision.note ? ' — ' + r.decision.note : '') : ''].map(esc).join(','))));
            const blob = new Blob(['\ufeff' + lines.join('\n')], { type: 'text/csv;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url; link.download = 'modulos_sin_horario_' + this.result.period.name + '.csv';
            document.body.appendChild(link); link.click(); link.remove(); URL.revokeObjectURL(url);
        },
        async openFuture() {
            this.view = 'future';
            await this.loadFuture();
        },
        async loadFuture(force) {
            if ((this.future && !force) || this.futureLoading || !this.result || !this.result.period) return;
            if (!this.future) this.futureLoading = true;
            this.futureError = '';
            try {
                const r = await window.axios.get(window.location.origin + '/webservice/rest/server.php', { params: {
                    wstoken: window.userToken, moodlewsrestformat: 'json', wsfunction: 'local_grupomakro_planning_get_demand',
                    periodid: this.result.period.id, planids: this.planIds, horizon: 18 } });
                if (r.data && r.data.exception) throw new Error(r.data.message || r.data.errorcode);
                const d = JSON.parse(r.data.data);
                this.future = Object.freeze({ horizon: d.result.horizon || [], students: d.result.students || {}, pooling: d.result.pooling || null,
                    published: !!d.result.published });
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
        async openEnrol() {
            this.enr = Object.assign({}, this.enr, { show: true, loading: true, error: '', rows: [], done: '' });
            try {
                const r = await this.ws('enrol_suggest', { periodid: this.result.period.id });
                if (!r.ok) { this.enr.error = r.message; return; }
                const d = typeof r.saved === 'string' ? JSON.parse(r.saved) : r.saved;
                this.enr.rows = (d.rows || []).map(x => Object.assign({}, x, { classid: x.suggested || 0, pick: !!x.suggested, result: null }));
                if (!this.enr.rows.some(x => x.suggested)) this.enr.filter = 'todas';
            } catch (e) { this.enr.error = 'No se pudieron cargar las sugerencias: ' + (e.message || e); }
            finally { this.enr.loading = false; }
        },
        optOf(r) { return (r.options || []).find(o => o.classid === r.classid) || null; },
        optLabel(o) {
            return o.name.replace(/^\d{4}-[IVX]+ /, '') + ' · ' + o.schedule + ' · ' + o.taken + (o.capacity ? '/' + o.capacity : '') + ' est.'
                + (o.approved ? '' : ' · sin aprobar') + (o.clash.length ? ' · CHOCA' : '') + (o.full ? ' · LLENO' : '');
        },
        async applyEnrol() {
            const pick = this.enrPicked;
            const bad = pick.filter(r => { const o = this.optOf(r); return o && (o.clash.length || o.full); });
            if (bad.length && !window.confirm(bad.length + ' de las marcadas chocan con otra clase del estudiante o el grupo está lleno '
                    + '(las llenas no se matriculan). ¿Continuar?')) return;
            if (!window.confirm('¿Matricular ' + pick.length + ' estudiante(s) en los grupos elegidos? Quedan en la cola de la clase y, si está aprobada, en el grupo.')) return;
            this.enr.saving = true; this.enr.done = '';
            try {
                let items = pick.map(r => ({ userid: r.userid, classid: r.classid }));
                let okn = 0, total = 0;
                while (items.length) {
                    const r = await this.ws('enrol_apply', { periodid: this.result.period.id, items });
                    if (!r.ok) { this.enr.done = r.message; this.enr.doneOk = false; return; }
                    const d = typeof r.saved === 'string' ? JSON.parse(r.saved) : r.saved;
                    const pending = [];
                    (d.rows || []).forEach(x => {
                        if (x.status === 'pending') { pending.push({ userid: x.userid, classid: x.classid }); return; }
                        total++; if (x.status === 'ok') okn++;
                        const row = this.enr.rows.find(y => y.userid === x.userid && y.classid === x.classid && !y.result);
                        if (row) { row.result = { status: x.status, message: x.message }; row.pick = false; }
                    });
                    items = pending;
                }
                this.enr.done = okn + ' de ' + total + ' matrícula(s) hecha(s). La proyección se actualiza al cerrar.';
                this.enr.doneOk = okn === total;
                this.enrChanged = true;
            } catch (e) { this.enr.done = 'Error: ' + (e.message || e); this.enr.doneOk = false; }
            finally { this.enr.saving = false; }
        },
        selItem(r, j) {
            // Para modulo se puede elegir entre los que se cargan y los que pueden adelantarla (con prelacion).
            const pick = j.students.filter(x => x.prereq !== 'no' && !x.flex && x.cat !== 'repite');
            return { ekey: j.ekey, courseid: r.courseid, name: r.name, jornada: j.jornada, isolated: r.isolated, students: pick, opens: j.groups > 0 };
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
            // Sin decision previa: si ya abre por quorum se parte de "Automatico".
            this.exc = { show: true, mode: cur ? cur.mode : (this.result.published ? 'adicional' : (items.every(it => it.opens) ? 'auto' : (fin ? 'modulo' : 'grupo'))),
                note: cur ? cur.note : '', error: '', saving: false, current: cur || null,
                items: items.map(it => Object.assign({}, it, {
                    // Por defecto se proponen para modulo los que egresan; si no hay, todos los que se cargan.
                    userids: (this.result.published && !cur ? it.students.filter(x => !x.loaded && x.cat !== 'adelanto')
                        : cur && cur.mode === 'modulo' ? cur.userids.slice()
                        : (it.students.some(x => x.s.finishing) ? it.students.filter(x => x.s.finishing) : it.students.filter(x => x.cat !== 'adelanto'))
                            .map(x => x.s.userid)).filter((v, i, a) => a.indexOf(v) === i) })) };
        },
        uniq(a) { return a.filter((v, i) => a.indexOf(v) === i); },
        poolAction(c) {
            if (c.action === 'espera') return 'esperan ' + c.shift + (c.shift === 1 ? ' periodo' : ' periodos');
            if (c.action === 'adelanta') return 'adelantan ' + c.shift + (c.shift === 1 ? ' periodo' : ' periodos');
            return 'a tiempo';
        },
        poolTag(a) { return a === 'espera' ? 'tl-plan-tag-atrasada' : a === 'adelanta' ? 'tl-plan-tag-adelanto' : 'tl-plan-tag-nivel'; },
        stuName(k) { const s = this.future && this.future.students[k]; return s ? s.name : k; },
        stuMeta(k) { const s = this.future && this.future.students[k]; return s ? this.shortPlan(s.plan) + ' · ' + s.level + ' · ingreso ' + (s.ingreso || '?') : ''; },
        // Se puede aplicar al periodo que se planifica si abre ahora (con cohorte o grupo de este periodo) o si la cohorte de este periodo espera.
        poolApplicable(p) {
            const has0 = p.cohorts.some(c => c.k === 0);
            return (p.open_k === 0 && (has0 || p.base)) || (p.open_k > 0 && has0);
        },
        poolEkey(p) { return p.courseid + '|' + (p.jornada || '') + '|' + (p.isolated_plan || 0); },
        poolException(p) { return this.exceptionMap[this.poolEkey(p)] || null; },
        poolApplied(p) { const e = this.poolException(p); return !!(e && e.pool); },
        poolBtn(p) { return p.open_k === 0 ? (p.base ? 'Sumarlos al grupo' : 'Abrir ahora juntando') : 'Dejar para ' + p.open_period; },
        poolBtnHelp(p) {
            return p.open_k === 0 ? 'Muestra qué cambia antes de confirmar: se propone un grupo en ' + this.result.period.name + ' con los que la adelantan'
                : 'Muestra qué cambia antes de confirmar: no se abre en ' + this.result.period.name + ' y estos estudiantes la ven en ' + p.open_period;
        },
        poolParts(list) { return list.map(c => c.students.length + ' de ' + c.period).join(', '); },
        // Motivo en lenguaje simple de por que no se abre en el periodo que se planifica juntando a todos.
        whyNotNow(p) {
            const w = p.why_not_now, now = this.result.period.name;
            const reach = w.now + w.can;
            const parts = w.now + ' a los que les toca' + (w.can ? ' + ' + w.can + ' que pueden adelantarla' : '');
            if (reach >= w.min) {
                return 'Se podría abrir en ' + now + ' con ' + reach + ' (' + parts + '), pero habría que adelantar a más estudiantes o por más '
                    + 'tiempo; la propuesta es la que menos mueve. Si se prefiere abrir ya, se puede decidir desde "Próximo periodo".';
            }
            let txt = 'En ' + now + ' llegarían ' + reach + ' (' + parts + ') y el mínimo es ' + w.min + '.';
            if (w.cannot) {
                const top = w.reasons.slice(0, 3).map(r => r.text + (r.n > 1 ? ' (' + r.n + ')' : '')).join('; ');
                txt += ' Otros ' + w.cannot + ' de periodos siguientes no pueden adelantarla: ' + top
                    + (w.reasons.length > 3 ? '; y otros motivos' : '') + '.';
            }
            return txt;
        },
        // Una frase en lenguaje simple con lo que propone el sistema.
        poolSentence(p) {
            const now = this.result.period.name;
            const c0 = p.cohorts.find(c => c.k === 0);
            const adv = p.cohorts.filter(c => c.action === 'adelanta');
            const advN = adv.reduce((a, c) => a + c.students.length, 0);
            const b0 = p.blocked.filter(b => b.k === 0).length;
            if (p.open_k === 0 && p.base) {
                const ba = p.base_adel || 0;
                return 'En ' + now + ' ya abre el grupo propuesto de ' + p.base + ' (el de "Grupos propuestos": '
                    + (ba ? (p.base - ba) + ' a los que les toca + ' + ba + ' que ya la adelantan' : 'todos a los que les toca') + '). '
                    + 'Se le pueden sumar ' + advN + ' que la adelantan desde ' + adv.map(c => c.period).join(', ') + ' (' + p.total + ' en total), '
                    + 'porque en su periodo serían ' + adv.map(c => 'solo ' + c.students.length + ' en ' + c.period).join(' y ')
                    + ' y no llegarían al mínimo de ' + this.min + '.';
            }
            if (p.open_k === 0) {
                return 'Propuesta: abrirla ahora en ' + now + ' con ' + p.total + ' estudiantes: ' + (c0 ? c0.students.length + ' a los que les toca' : 'ninguno a los que les toca')
                    + (advN ? ' + ' + advN + ' que la adelantan (' + this.poolParts(adv) + ')' : '') + '.';
            }
            if (c0) {
                const others = p.cohorts.filter(c => c.k !== 0);
                const dec = this.poolException(p);
                const pre = dec && !dec.pool && dec.mode === 'grupo'
                    ? 'Hoy está decidido abrirla en ' + now + ' bajo el mínimo (decisión de ' + (dec.byname || 'Dirección Académica') + '). Alternativa: no abrirla en ' + now + '. Los '
                    : 'Propuesta: no abrirla en ' + now + '. Los ';
                return pre + c0.students.length + ' de ' + now + ' esperan y la ven en ' + p.open_period
                    + ' junto con ' + this.poolParts(others) + ' (' + p.total + ' en total).'
                    + (b0 ? ' ' + b0 + ' no pueden esperar y se cargarían como módulo sin pago en ' + now + '.' : '');
            }
            return 'Para más adelante: abriría en ' + p.open_period + ' con ' + p.total + ' (' + this.poolParts(p.cohorts) + '). '
                + 'No cambia nada en ' + now + '; se decide al planificar ese periodo.';
        },
        // Prepara la ventana "qué va a pasar" (sin escribir nada).
        openPoolApply(p) {
            const now = this.result.period.name, j = p.jornada || 'sin jornada';
            const item = { courseid: p.courseid, jornada: p.jornada || '', isolated_plan: p.isolated_plan || 0 };
            const c0 = p.cohorts.find(c => c.k === 0);
            const st = this.future.students || {};
            const others = p.cohorts.filter(c => c.k !== 0).map(c => c.students.length + ' de ' + c.period
                + (c.action === 'a_tiempo' ? '' : ' (' + this.poolAction(c) + ')')).join(', ');
            const lists = [];
            let mode, note, title, will;
            if (p.open_k === 0) {
                const adv = [];
                p.cohorts.filter(c => c.action === 'adelanta').forEach(c => c.students.forEach(k => adv.push(k)));
                mode = 'grupo';
                item.add_keys = adv;
                title = p.base ? 'Sumarlos al grupo' : 'Abrir ahora juntando';
                note = 'Juntar cohortes: abre en ' + now + ' con ' + p.total + '; se suman ' + others + '.';
                will = [
                    'Queda registrada para ' + now + ' la decisión «Abre juntando cohortes» de ' + p.name + ' (' + j + '), con el motivo puesto por el sistema.',
                    'En «Grupos propuestos» de ' + now + ' esta asignatura aparece con ' + p.total + ' estudiantes'
                        + (p.base ? ': el grupo de ' + p.base + ' que ya abría' : (c0 ? ': ' + c0.students.length + ' a los que les toca' : '')) + ' + ' + adv.length + ' que la adelantan.',
                    'Los ' + adv.length + ' que la adelantan la tendrán en ' + now + ' en lugar de su periodo; todos tendrán sus prelaciones aprobadas para entonces.',
                    'El tablero de horarios parte de los grupos propuestos: revíselo después para ubicar este grupo.',
                ];
                if (c0 && !p.base) lists.push({ label: 'Les toca en ' + now, keys: c0.students });
                p.cohorts.filter(c => c.action === 'adelanta').forEach(c => lists.push({ label: 'La adelantan desde ' + c.period, keys: c.students }));
            } else {
                const blocked0 = p.blocked.filter(b => b.k === 0);
                mode = blocked0.length ? 'modulo' : 'cerrar';
                if (blocked0.length) item.userids = blocked0.map(b => st[b.key] ? st[b.key].userid : 0).filter(Boolean);
                title = 'Dejar para ' + p.open_period;
                note = 'Juntar cohortes: ' + c0.students.length + ' esperan para verla en ' + p.open_period + ' con ' + p.total + ' (se suman ' + others + ').'
                    + (blocked0.length ? ' ' + blocked0.length + ' no pueden esperar (egresan o la necesitan como prelación) y van como módulo.' : '');
                will = [
                    'Queda registrada para ' + now + ' la decisión «Espera para juntarse» de ' + p.name + ' (' + j + '): en ' + now + ' no se propone grupo de esta asignatura.',
                    'Los ' + c0.students.length + ' estudiantes de ' + now + ' pasan a Alertas → «Esperan para juntarse», para que se les pueda avisar.',
                    'En ' + p.open_period + ' se juntan con ' + others + ' y la asignatura abre con ' + p.total + '. Ninguno la necesita como prelación antes de esa fecha.',
                ];
                if (blocked0.length) will.push(blocked0.length + ' estudiante(s) no pueden esperar (egresan o la necesitan como prelación): se cargan en ' + now + ' como módulo sin pago (sin horario).');
                lists.push({ label: 'Esperan hasta ' + p.open_period, keys: c0.students });
                if (blocked0.length) lists.push({ label: 'Van como módulo sin pago en ' + now, keys: blocked0.map(b => b.key) });
            }
            const cur = this.poolException(p);
            this.poolDlg = { p, mode, item, note, title, will, lists, replacing: cur && !cur.pool ? cur : null, saving: false, error: '' };
        },
        openPoolUndo(p) {
            const now = this.result.period.name;
            this.poolDlg = { undo: true, p, title: this.poolBtn(p), lists: [], saving: false, error: '',
                will: ['Se quita la decisión «' + this.decisionLabel(this.poolException(p)) + '» de ' + now + '.',
                    'La asignatura vuelve a automático: abre en ' + now + ' solo si alcanza el mínimo de ' + this.min + ' con los que les toca.',
                    (this.poolException(p) || {}).mode === 'grupo' ? 'Los que la adelantaban vuelven a verla en su propio periodo.'
                        : 'Los estudiantes salen de Alertas → «Esperan para juntarse»' + ((this.poolException(p) || {}).mode === 'modulo' ? ' y se quita el módulo sin pago de los que no podían esperar.' : '.')] };
        },
        async confirmPool() {
            const d = this.poolDlg;
            d.saving = true; d.error = '';
            try {
                const r = d.undo
                    ? await this.ws('exception_remove', { periodid: this.result.period.id, keys: [this.poolEkey(d.p)] })
                    : await this.ws('exception_add', { periodid: this.result.period.id, mode: d.mode, note: d.note, pool: 1, items: [d.item] });
                if (!r.ok) { d.error = r.message; return; }
                this.poolDlg = null;
                this.$emit('changed', d.undo ? 'Se deshizo: ' + d.p.name + ' vuelve a automático.' : 'Decisión registrada: ' + d.note);
            } catch (e) { d.error = 'Error al guardar: ' + (e.message || e); }
            finally { if (this.poolDlg) this.poolDlg.saving = false; }
        },
        decisionLabel(e) {
            if (!e) return '';
            if (e.pool && e.mode === 'grupo') return 'Abre juntando cohortes (' + (e.add_keys || []).length + ' adelantan)';
            if (e.pool && e.mode === 'cerrar') return 'Espera para juntarse con un periodo siguiente';
            if (e.pool && e.mode === 'modulo') return 'Espera para juntarse; ' + e.userids.length + ' que no pueden esperar van como módulo';
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
        resetGroups() { this.grouping = null; this.loadGrouping(); },
        // force: recalcula en silencio y reemplaza al llegar (se sigue viendo lo anterior mientras tanto).
        periodState(p) {
            const s = p.state === 'por_empezar' ? 'por empezar' : (p.state === 'en_curso' ? 'en curso' : 'terminado');
            return s + (p.id === this.defaultPeriod ? ' (el próximo)' : '') + (!p.active ? ' · cerrado' : '') + (!p.has_calendar ? ' · sin calendario' : '');
        },
        // Cambia el periodo: el componente padre vuelve a calcular la proyeccion de ese periodo.
        changePeriod(ev) {
            const id = Number(ev.target.value);
            const b = this.$refs.board;
            if (b && b.dirty && !window.confirm('El tablero de horarios de ' + this.result.period.name + ' tiene cambios sin guardar. '
                    + 'Si cambia de periodo se pierden. ¿Cambiar de todos modos?')) {
                ev.target.value = this.result.period.id;
                return;
            }
            this.$emit('period', id);
        },
        async prefetch(force) {
            await this.loadGrouping(force);
            await this.loadFuture(force);
        },
        // Grupos propuestos de una jornada: tamaños, cuántos les toca, cuántos adelantan y quiénes adelantan.
        gsize(j) { return (this.groupSizes && this.groupSizes[j.ekey]) || null; },
        gsizeLabel(j) {
            const g = this.gsize(j);
            if (!g) return '';
            return g.sizes.length === 1 ? '1 grupo de ' + g.sizes[0] : g.sizes.length + ' grupos: ' + g.sizes.join(' + ');
        },
        rowGroups(r) {
            if (!this.groupSizes) return null;
            let total = 0, adel = 0, n = 0;
            r.jornadas.filter(this.visibleJ).forEach(j => { const g = this.gsize(j); if (g) { total += g.total; adel += g.adel; n += g.sizes.length; } });
            return n ? { total, adel, n } : null;
        },
        rowGroupsHelp(r) {
            const g = this.rowGroups(r);
            return g.total + ' estudiantes en los grupos propuestos: ' + (g.total - g.adel) + ' a los que les toca + ' + g.adel + ' que adelantan. '
                + 'Pueden adelantar ' + r.adelanto + ' en total; solo entran los que hacen falta o tienen poca carga.';
        },
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
            if (this.exc.mode === 'adicional') {
                if (this.exc.items.some(it => it.jornada === 'Sin jornada')) { this.exc.error = 'Elija una jornada con nombre (Diurna, Nocturna o Sabatina).'; return; }
                this.exc.saving = true;
                try {
                    const r = await this.ws('extra_add', { periodid: this.result.period.id, note: this.exc.note,
                        items: this.exc.items.map(it => ({ courseid: it.courseid, jornada: it.jornada, isolated_plan: it.isolated, userids: it.userids })) });
                    if (!r.ok) { this.exc.error = r.message; return; }
                    this.exc.show = false; this.selected = {};
                    const b = this.$refs.board;
                    if (b && !b.dirty) b.load();
                    this.$emit('changed', r.message);
                } catch (e) { this.exc.error = 'Error al guardar: ' + (e.message || e); }
                finally { this.exc.saving = false; }
                return;
            }
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
