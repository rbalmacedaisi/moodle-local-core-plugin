// Tablero de horarios de la linea de tiempo (pestana Proyeccion -> Tablero de horarios).
// Parte de la propuesta de grupos guardada, ubica cada grupo en dia y hora, le asigna docente y aula, muestra los
// choques en vivo y sugiere arreglos. Guarda un borrador APARTE en el periodo (no toca el Planificador Academico ni
// crea clases). Las habilidades y la disponibilidad de los docentes se editan aqui y se guardan al momento.
// Motor: window.GmkBoard (board_engine.js).
Vue.component('schedule-board', {
    template: `
    <div class="tl-sb">
        <div v-if="loading" class="tl-plan-empty" style="padding:16px">Cargando el tablero…</div>
        <div v-else-if="error" class="tl-cfg-msg tl-cfg-msg-error">{{ error }} <button class="tl-proj-flexbtn" @click="load">Reintentar</button></div>
        <template v-else-if="board && board.needs_proposal">
            <div class="tl-sb-empty">
                <v-icon size="36" color="#6366F1">mdi-calendar-edit</v-icon>
                <div><b>El tablero se arma sobre la propuesta de grupos guardada.</b></div>
                <div class="tl-cfg-help">Guarde la propuesta (pestaña Grupos propuestos) para fijar los grupos y empezar a ubicarlos.
                    Se guarda aparte en el periodo: no crea clases ni toca el borrador del Planificador Académico.</div>
                <button v-if="canEdit" class="tl-cfg-btn mt-2" :disabled="saving" @click="saveProposal">Guardar la propuesta y abrir el tablero</button>
            </div>
        </template>
        <template v-else-if="board">
            <!-- propuesta guardada desactualizada -->
            <div v-if="board.proposal_changes" class="tl-sb-stale">
                <div><v-icon size="16" color="#B45309">mdi-alert-outline</v-icon>
                    <b>El tablero usa la propuesta de grupos guardada el {{ fmtLong(board.proposal_changes.saved_at) }}</b><template
                        v-if="board.proposal_changes.byname"> por {{ board.proposal_changes.byname }}</template>,
                    y desde entonces cambiaron decisiones o la configuración.
                    <button class="tl-proj-flexbtn" @click="staleOpen = !staleOpen">{{ staleOpen ? 'Ocultar' : 'Ver qué cambió' }}</button>
                    <button v-if="canEdit" class="tl-cfg-btn" :disabled="saving" @click="saveProposal"
                            title="Recalcula los grupos con las decisiones y la configuración de hoy. Las fichas que siguen igual conservan su día, hora, docente y aula.">
                        Actualizar la propuesta</button></div>
                <div v-if="board.proposal_changes.dropped.length" class="tl-cfg-help mt-1">Ya no se muestran en el tablero
                    ({{ board.proposal_changes.dropped.length }}): {{ board.proposal_changes.dropped.map(d => d.name + ' · ' + d.jornada + ' (' + d.reason + ')').join('; ') }}.</div>
                <div v-if="staleOpen" class="tl-sb-stale-list">
                    <div v-for="d in board.proposal_changes.gone" :key="'g' + d.name + d.jornada">✕ <b>{{ d.name }}</b> · {{ d.jornada }}: {{ d.reason }}</div>
                    <div v-for="d in board.proposal_changes.added" :key="'a' + d.name + d.jornada">＋ <b>{{ d.name }}</b> · {{ d.jornada }}: nueva, {{ d.now }}</div>
                    <div v-for="d in board.proposal_changes.changed" :key="'c' + d.name + d.jornada">↻ <b>{{ d.name }}</b> · {{ d.jornada }}: antes {{ d.was }}, hoy {{ d.now }}</div>
                </div>
            </div>
            <!-- ayuda -->
            <div class="tl-sb-help" v-if="showHelp">
                <div><b>Cómo usar el tablero</b> <button class="tl-proj-unx" style="color:#64748B" @click="showHelp = false" title="Ocultar">×</button></div>
                <ol>
                    <li><b>Ubique los grupos:</b> arrástrelos de la lista de la izquierda al día y la hora, o use <b>Acomodar automáticamente</b>.
                        Mientras arrastra, la casilla se pinta <span class="tl-sb-dot tl-sb-ok"></span> sin choques,
                        <span class="tl-sb-dot tl-sb-soft"></span> con avisos o <span class="tl-sb-dot tl-sb-hard"></span> con choques, y dice por qué.</li>
                    <li><b>Docente y aula:</b> haga clic en un grupo y elija en el panel de la derecha (los mejores primero), o use <b>Asignar docentes</b>.
                        Si a un docente le falta la habilidad o la disponibilidad, se puede registrar desde ahí.</li>
                    <li><b>Resuelva los choques</b> en rojo con las sugerencias del panel. <b>Bloquee</b> lo que ya está decidido para que el acomodo automático no lo mueva.</li>
                    <li><b>Guarde</b> el borrador: queda aparte en el periodo. No se crea ninguna clase todavía.</li>
                </ol>
            </div>
            <!-- barra -->
            <div class="tl-sb-bar">
                <div class="tl-plan-tabs" style="flex:0 0 auto">
                    <button v-for="j in jornadas" :key="j" class="tl-plan-tab" :class="{ 'tl-plan-tab-active': jornada === j }" @click="jornada = j">
                        {{ j }}<span class="tl-plan-tab-count" :class="{ 'tl-sb-count-hard': jStats[j].hard }">{{ jStats[j].placed }}/{{ jStats[j].total }}</span></button>
                </div>
                <div class="tl-panel-search" style="flex:1;max-width:220px">
                    <v-icon size="14" color="#94A3B8">mdi-magnify</v-icon>
                    <input v-model="q" type="text" placeholder="Buscar asignatura o docente…" class="tl-panel-search-input"/>
                </div>
                <select class="tl-cfg-input" style="width:auto" v-model.number="weekSel" title="Ver solo las clases de una semana académica">
                    <option :value="0">Todas las semanas</option>
                    <option v-for="w in weekOpts" :key="w" :value="w">Semana {{ w }}</option>
                </select>
                <v-spacer></v-spacer>
                <v-menu offset-y>
                    <template v-slot:activator="{ on }"><button class="tl-cfg-btn tl-cfg-btn-ghost" v-on="on" title="Imprimir el horario tal como está en el tablero">
                        <v-icon size="14">mdi-printer</v-icon> Imprimir</button></template>
                    <v-list dense>
                        <v-list-item v-for="m in printModes" :key="m.id" @click="printReport(m.id)"><v-list-item-content>
                            <v-list-item-title>{{ m.title }}</v-list-item-title><v-list-item-subtitle>{{ m.sub }}</v-list-item-subtitle></v-list-item-content></v-list-item>
                    </v-list>
                </v-menu>
                <button class="tl-cfg-btn tl-cfg-btn-ghost" :disabled="exporting" @click="exportExcel"
                        title="Excel con el horario, las fechas de cada sesión, los estudiantes de cada grupo, docentes, aulas y choques">
                    <v-icon size="14">mdi-microsoft-excel</v-icon> {{ exporting ? 'Exportando…' : 'Excel' }}</button>
                <template v-if="canEdit">
                    <v-menu offset-y>
                        <template v-slot:activator="{ on }"><button class="tl-cfg-btn" v-on="on"><v-icon size="14" color="#fff">mdi-auto-fix</v-icon> Acomodar automáticamente</button></template>
                        <v-list dense>
                            <v-list-item @click="autoPlace(false)"><v-list-item-content><v-list-item-title>Ubicar los que faltan</v-list-item-title>
                                <v-list-item-subtitle>No mueve lo que ya está en el tablero</v-list-item-subtitle></v-list-item-content></v-list-item>
                            <v-list-item @click="autoPlace(true)"><v-list-item-content><v-list-item-title>Reacomodar todo (menos lo bloqueado)</v-list-item-title>
                                <v-list-item-subtitle>Vuelve a ubicar todos los grupos sin candado</v-list-item-subtitle></v-list-item-content></v-list-item>
                        </v-list>
                    </v-menu>
                    <button class="tl-cfg-btn tl-cfg-btn-ghost" @click="autoAssign" title="Docente con la habilidad, disponible y libre, el menos cargado; y aula libre que alcance">
                        <v-icon size="14">mdi-account-tie</v-icon> Asignar docentes y aulas</button>
                    <button class="tl-cfg-btn tl-cfg-btn-ghost" @click="teacherDlg.show = true"><v-icon size="14">mdi-account-cog</v-icon> Docentes</button>
                    <button class="tl-cfg-btn tl-cfg-btn-ghost" :disabled="!history.length" @click="undo" title="Deshacer el último cambio"><v-icon size="14">mdi-undo</v-icon></button>
                    <button class="tl-cfg-btn" :class="{ 'tl-sb-dirty': dirty }" :disabled="!dirty || saving" @click="askSave">
                        <v-icon size="14" color="#fff">mdi-content-save</v-icon> {{ dirty ? 'Guardar borrador' : 'Guardado' }}</button>
                    <button class="tl-cfg-btn tl-sb-pubbtn" @click="openPublish" title="Revisar y crear las clases del tablero (pendientes de aprobación) y los módulos">
                        <v-icon size="14" color="#fff">mdi-rocket-launch-outline</v-icon> Publicar…</button>
                </template>
            </div>
            <!-- estado -->
            <div class="tl-sb-status">
                <span><b>{{ sum.placed }}</b> de {{ sum.total }} grupos con horario</span>
                <span class="tl-sb-chip" :class="sum.hard ? 'tl-sb-chip-hard' : 'tl-sb-chip-ok'" @click="onlyIssues = !onlyIssues" title="Clic para ver solo los grupos con choques o avisos">
                    {{ sum.hard }} con choques</span>
                <span class="tl-sb-chip tl-sb-chip-soft" @click="onlyIssues = !onlyIssues">{{ sum.soft }} con avisos</span>
                <span class="tl-sb-chip">{{ sum.noTeacher }} sin docente</span>
                <span class="tl-sb-chip">{{ sum.noRoom }} sin aula</span>
                <span v-if="onlyIssues" class="tl-sb-chip tl-sb-chip-on" @click="onlyIssues = false">viendo solo con choques/avisos ×</span>
                <v-spacer></v-spacer>
                <span class="tl-cfg-help">
                    <template v-if="dirty"><v-icon size="13" color="#B45309">mdi-alert-circle-outline</v-icon> Cambios sin guardar</template>
                    <template v-else-if="draftInfo.saved_at">Guardado {{ fmtLong(draftInfo.saved_at) }} por {{ draftInfo.byname }}</template>
                    <template v-else>Aún no se guardó ningún borrador</template>
                    <button v-if="!showHelp" class="tl-proj-flexbtn" @click="showHelp = true">Ayuda</button>
                </span>
            </div>
            <div v-if="lastAction" class="tl-cfg-msg" :class="lastAction.error ? 'tl-cfg-msg-error' : 'tl-cfg-msg-ok'">{{ lastAction.text }}
                <button v-if="lastAction.failed && lastAction.failed.length" class="tl-proj-flexbtn" @click="failedOpen = !failedOpen">
                    {{ failedOpen ? 'Ocultar detalle' : 'Ver cuáles y por qué (' + lastAction.failed.length + ')' }}</button>
                <button class="tl-proj-unx" style="color:#64748B" @click="lastAction = null" title="Cerrar">×</button>
                <div v-if="failedOpen && lastAction.failed && lastAction.failed.length" class="tl-sb-failed">
                    <div v-for="f in lastAction.failed" :key="f.key" class="tl-sb-failed-row" @click="goTo(f.key)"><b>{{ groupLabel(f.key) }}</b>: {{ f.reason }}</div>
                </div>
            </div>

            <div class="tl-sb-main" :class="{ 'tl-sb-main-nosel': !info }">
                <!-- sin ubicar -->
                <div class="tl-sb-side" @dragover.prevent @drop.prevent="dropUnplace">
                    <div class="tl-sb-side-head">Sin horario ({{ unplaced.length }})</div>
                    <div class="tl-cfg-help" style="margin:0 0 6px">Arrastre al día y la hora. Suelte aquí un grupo del tablero para quitarle el horario.</div>
                    <div v-for="g in unplaced" :key="g.key" class="tl-sb-item" :class="{ 'tl-sb-item-sel': sel === g.key }"
                         :draggable="canEdit" @dragstart="dragStart($event, g.key, false)" @dragend="dragEnd" @click="select(g.key)">
                        <div class="tl-sb-item-name">{{ g.name }}</div>
                        <div class="tl-sb-item-meta">G{{ g.number }}/{{ g.of }}<template v-if="g.parts > 1"> · parte {{ g.part }}/{{ g.parts }}</template> · {{ g.size }} est
                            · {{ g.sessions || '?' }} ses. de {{ g.duration }} min<span v-if="g.long"> · cuatrimestral</span></div>
                        <div class="tl-sb-item-meta">{{ topLevels(g) }}</div>
                    </div>
                    <div v-if="!unplaced.length" class="tl-plan-empty">Todos los grupos de {{ jornada }} tienen horario.</div>
                </div>

                <!-- cuadricula -->
                <div class="tl-sb-gridwrap">
                    <div class="tl-sb-grid" :style="{ gridTemplateColumns: gridCols }">
                        <div class="tl-sb-corner"></div>
                        <div v-for="d in days" :key="'h' + d" class="tl-sb-dayhead">{{ dayName(d) }}
                            <span class="tl-sb-dayhol" v-if="holidaysOn(d).length" :title="holidaysOn(d).join(', ')"><v-icon size="11" color="#B45309">mdi-calendar-remove</v-icon>{{ holidaysOn(d).length }}</span></div>
                        <div class="tl-sb-axis" :style="{ height: colHeight + 'px' }">
                            <div v-for="h in hourMarks" :key="h" class="tl-sb-hour" :style="{ top: (h - win.start) * ppm + 'px' }">{{ hhmm(h) }}</div>
                        </div>
                        <div v-for="d in days" :key="'c' + d" class="tl-sb-col" :style="{ height: colHeight + 'px' }"
                             @dragover.prevent="dragOver($event, d)" @dragleave="dragLeave($event, d)" @drop.prevent="drop($event, d)">
                            <div v-for="h in hourMarks" :key="'l' + h" class="tl-sb-line" :style="{ top: (h - win.start) * ppm + 'px' }"></div>
                            <div v-if="lunch" class="tl-sb-lunch" :style="{ top: (lunch.start - win.start) * ppm + 'px', height: (lunch.end - lunch.start) * ppm + 'px' }">almuerzo</div>
                            <div v-if="outside.before" class="tl-sb-out" :style="{ top: 0, height: outside.before * ppm + 'px' }"></div>
                            <div v-if="outside.after" class="tl-sb-out" :style="{ bottom: 0, height: outside.after * ppm + 'px' }"></div>
                            <div v-for="c in cardsByDay[d] || []" :key="c.id" class="tl-sb-card"
                                 :class="['tl-sb-card-' + c.sev, { 'tl-sb-card-fixed': c.fixed, 'tl-sb-card-sel': sel === c.id, 'tl-sb-card-dim': c.dim, 'tl-sb-card-rel': related[c.id] }]"
                                 :style="cardStyle(c)" :draggable="canEdit && !c.fixed && !c.locked && !c.chain"
                                 @dragstart="dragStart($event, c.id, true)" @dragend="dragEnd" @click.stop="c.chain ? null : (c.fixed ? (fixedSel = c) : select(c.id))"
                                 :title="c.title">
                                <template v-if="c.chain">
                                    <div class="tl-sb-card-name"><v-icon size="11">mdi-link-variant</v-icon> {{ c.chain.length }} asignaturas seguidas · {{ hhmm(c.start) }}–{{ hhmm(c.end) }}</div>
                                    <div class="tl-sb-chain">
                                        <div v-for="seg in c.chain" :key="seg.id" class="tl-sb-seg" :class="['tl-sb-seg-' + seg.sev, { 'tl-sb-seg-sel': sel === seg.id }]"
                                             :style="{ left: (seg.w0 / seg.total * 100) + '%', width: ((seg.w1 - seg.w0 + 1) / seg.total * 100) + '%' }"
                                             :draggable="canEdit && !seg.locked" @dragstart.stop="dragStart($event, seg.id, true)" @dragend="dragEnd"
                                             @click.stop="select(seg.id)" :title="seg.title">
                                            <div class="tl-sb-seg-name">{{ seg.name }}</div>
                                            <div class="tl-sb-seg-w">s{{ seg.w0 + 1 }}<template v-if="seg.w1 > seg.w0">–{{ seg.w1 + 1 }}</template></div>
                                        </div>
                                    </div>
                                </template>
                                <template v-else>
                                    <div class="tl-sb-card-name"><v-icon v-if="c.locked" size="10">mdi-lock</v-icon>{{ c.name }}</div>
                                    <div class="tl-sb-card-meta">{{ c.line1 }}</div>
                                    <div class="tl-sb-card-meta">{{ c.line2 }}</div>
                                    <div v-if="c.span && c.span.partial" class="tl-sb-wbar" :title="'Semanas ' + (c.span.w0 + 1) + ' a ' + (c.span.w1 + 1) + ' de ' + c.span.total">
                                        <div :style="{ left: (c.span.w0 / c.span.total * 100) + '%', width: ((c.span.w1 - c.span.w0 + 1) / c.span.total * 100) + '%' }"></div></div>
                                </template>
                            </div>
                            <div v-if="hover && hover.day === d" class="tl-sb-ghost" :class="'tl-sb-ghost-' + hover.sev"
                                 :style="{ top: (hover.start - win.start) * ppm + 'px', height: (hover.end - hover.start) * ppm + 'px' }">
                                <b>{{ hhmm(hover.start) }}–{{ hhmm(hover.end) }}</b>
                                <div v-for="(m, i) in hover.msgs.slice(0, 3)" :key="i">{{ m }}</div>
                                <div v-if="hover.sev === 'ok'">Sin choques</div>
                            </div>
                        </div>
                    </div>
                    <div class="tl-plan-legend">
                        <span><span class="tl-sb-dot tl-sb-ok"></span> sin choques</span>
                        <span><span class="tl-sb-dot tl-sb-soft"></span> avisos (disponibilidad, habilidad, capacidad, almuerzo)</span>
                        <span><span class="tl-sb-dot tl-sb-hard"></span> choques (estudiantes, docente, aula, fuera de jornada)</span>
                        <span><span class="tl-sb-dot tl-sb-fixed"></span> clase ya creada (no se mueve)</span>
                        <span><v-icon size="11">mdi-lock</v-icon> bloqueado</span>
                    </div>
                </div>

                <!-- panel del grupo -->
                <div class="tl-sb-panel" v-if="info">
                    <div class="d-flex">
                        <div style="flex:1">
                            <div class="tl-sb-panel-title">{{ info.g.name }}</div>
                            <div v-if="info.g.published" class="tl-cfg-msg tl-cfg-msg-ok" style="margin:4px 0">Publicada: es la clase {{ info.g.published }}
                                (pendiente de aprobación o ya aprobada). Ya no se cambia desde el tablero, sino desde la gestión de clases.</div>
                            <div class="tl-cfg-help">Grupo {{ info.g.number }} de {{ info.g.of }} · {{ info.g.jornada }} · {{ info.g.size }} estudiantes
                                <template v-if="info.g.adelantos"> ({{ info.g.adelantos }} adelantos)</template></div>
                            <div class="tl-cfg-help">{{ topLevels(info.g) }}</div>
                        </div>
                        <button class="tl-proj-unx" style="color:#64748B;font-size:18px" @click="sel = null" title="Cerrar">×</button>
                    </div>
                    <div class="tl-sb-sec">
                        <div class="tl-sb-sec-title">Horario</div>
                        <div v-if="placedSel">{{ dayName(info.p.day) }} {{ hhmm(info.p.start) }}–{{ hhmm(info.p.end) }}
                            <span class="tl-cfg-help">({{ info.p.end - info.p.start }} min{{ info.g.hours ? ', carga ' + info.g.hours + ' h' : '' }})</span>
                            <div class="tl-cfg-help">{{ E.weeksLabel(S, sel, info.p) }}</div></div>
                        <div v-else class="tl-cfg-help">Sin horario: arrástrelo a la cuadrícula o use una sugerencia.</div>
                        <div class="tl-cfg-help">{{ info.g.sessions || '?' }} sesiones<template v-if="info.g.parts > 1"> en esta parte (parte {{ info.g.part }} de {{ info.g.parts }}: tiene más sesiones que semanas tiene
                            el bloque, por eso va en {{ info.g.parts }} fichas, en días distintos o seguidas en la misma franja si caben)</template>. Las asignaturas cortas usan solo las semanas que necesitan
                            y pueden ir seguidas de otra en la misma franja.</div>
                        <div class="d-flex align-center mt-1" style="gap:6px;flex-wrap:wrap" v-if="canEdit">
                            <label class="tl-cfg-help">Duración
                                <select class="tl-cfg-input" style="width:90px" :value="durSel" @change="setDuration(Number($event.target.value))">
                                    <option v-for="m in durOptions" :key="m" :value="m">{{ m }} min</option></select></label>
                            <label v-if="placedSel && weekOptions.length > 1" class="tl-cfg-help">Semanas
                                <select class="tl-cfg-input" style="width:190px" :value="info.p.week || 0" @change="setPlace(sel, { week: Number($event.target.value) })">
                                    <option v-for="o in weekOptions" :key="o.week" :value="o.week">{{ o.label }}</option></select></label>
                            <label class="tl-cfg-check"><input type="checkbox" :checked="info.p.locked" @change="setLock($event.target.checked)"> Bloquear</label>
                            <button v-if="placedSel" class="tl-proj-flexbtn" @click="unplace(sel)">Quitar del horario</button>
                        </div>
                    </div>
                    <div class="tl-sb-sec">
                        <div class="tl-sb-sec-title">Preferencia de día y hora</div>
                        <div v-if="prefInfo" class="tl-cfg-help">
                            {{ prefInfo.source === 'asignatura' ? 'De la asignatura' : 'De la jornada ' + info.g.jornada }}:
                            {{ prefInfo.days.length ? prefInfo.days.map(d => dayName(d)).join(', ') : 'cualquier día' }}<template v-if="prefInfo.start >= 0">
                            · {{ hhmm(prefInfo.start) }}–{{ hhmm(prefInfo.end) }}</template>
                            <span v-if="info.conf.some(c => c.type === 'preferencia')" style="color:#B45309"> · esta ficha está fuera de la preferencia</span>
                            <div v-if="prefInfo.source === 'asignatura' && (prefInfo.strict || prefInfo.override || prefInfo.teacherid || prefInfo.parallel.length || prefInfo.raw.times_per_week)" class="tl-sb-rule">
                                Regla: <template v-if="prefInfo.strict">obligatoria</template><template v-else>preferencia</template>
                                <template v-if="prefInfo.override"> · su franja vale para todas las jornadas</template>
                                <template v-if="prefInfo.teacherid"> · docente fijo: {{ teacherName(prefInfo.teacherid) }}</template>
                                <template v-if="prefInfo.raw.times_per_week"> · {{ prefInfo.raw.times_per_week }} vez/veces por semana</template>
                                <template v-if="prefInfo.parallel.length"> · en paralelo hasta {{ prefInfo.parallelMax }} grupos con: {{ prefInfo.parallel.map(c => courseName(c)).join(', ') }}</template>
                            </div>
                        </div>
                        <div v-else class="tl-cfg-help">Sin preferencia (se puede definir por jornada en Configuración o aquí para la asignatura).</div>
                        <button v-if="canEdit" class="tl-proj-flexbtn mt-1" @click="openPref">{{ prefInfo && prefInfo.source === 'asignatura' ? 'Cambiar' : 'Guardar' }} preferencia de esta asignatura</button>
                    </div>
                    <div class="tl-sb-sec">
                        <div class="tl-sb-sec-title">Docente</div>
                        <div v-if="info.p.teacherid" class="d-flex align-center" style="gap:6px">
                            <b>{{ teacherName(info.p.teacherid) }}</b>
                            <button v-if="canEdit" class="tl-proj-flexbtn" @click="setTeacher(0)">quitar</button>
                            <button class="tl-proj-flexbtn" @click="openTeacher(info.p.teacherid)">ver docente</button>
                        </div>
                        <div v-else class="tl-cfg-help">Sin docente.</div>
                        <input v-model="tq" class="tl-cfg-input mt-1" style="width:100%" placeholder="Buscar docente…">
                        <div class="tl-sb-opts">
                            <div v-for="o in teacherOpts" :key="o.id" class="tl-sb-opt" :class="{ 'tl-sb-opt-on': o.id === info.p.teacherid }">
                                <div style="flex:1;min-width:0" @click="canEdit && setTeacher(o.id)">
                                    <div class="tl-sb-opt-name">{{ o.name }}</div>
                                    <div class="tl-sb-opt-tags">
                                        <span :class="o.skill ? 'tl-sb-tag-ok' : 'tl-sb-tag-no'">{{ o.skill ? '✓ habilidad' : '✗ sin habilidad' }}</span>
                                        <span v-if="placedSel" :class="o.avail ? 'tl-sb-tag-ok' : 'tl-sb-tag-no'">{{ o.avail ? '✓ disponible' : (o.hasAvail ? '✗ no disponible' : '✗ sin disponibilidad') }}</span>
                                        <span v-if="o.busy" class="tl-sb-tag-no">✗ ocupado</span>
                                        <span class="tl-sb-tag">{{ Math.round(o.load / 60 * 10) / 10 }} h/sem</span>
                                    </div>
                                </div>
                                <button v-if="canEdit && !o.skill" class="tl-proj-flexbtn" @click="quickSkill(o)" title="Registrar que este docente puede dictar la asignatura">+ habilidad</button>
                                <button v-if="canEdit && placedSel && !o.avail" class="tl-proj-flexbtn" @click="quickAvail(o)" title="Agregar esta franja a su disponibilidad">+ disponibilidad</button>
                            </div>
                        </div>
                    </div>
                    <div class="tl-sb-sec">
                        <div class="tl-sb-sec-title">Aula</div>
                        <select class="tl-cfg-input" style="width:100%" :value="info.p.roomid" :disabled="!canEdit" @change="setRoom(Number($event.target.value))">
                            <option :value="0">Sin aula</option>
                            <option v-for="r in roomOpts" :key="r.id" :value="r.id">{{ r.name }} · {{ r.capacity }} puestos{{ r.busy ? ' · ocupada' : '' }}{{ !r.fits ? ' · chica' : '' }}</option>
                        </select>
                    </div>
                    <div class="tl-sb-sec" v-if="info.conf.length">
                        <div class="tl-sb-sec-title">Choques y avisos</div>
                        <div v-for="(c, i) in info.conf" :key="i" class="tl-sb-conf" :class="'tl-sb-conf-' + c.sev" @click="c.with && groupsMap[c.with] && goTo(c.with)">
                            <v-icon size="13" :color="c.sev === 'hard' ? '#B91C1C' : (c.sev === 'soft' ? '#B45309' : '#64748B')">
                                {{ c.sev === 'hard' ? 'mdi-close-octagon' : (c.sev === 'soft' ? 'mdi-alert' : 'mdi-information-outline') }}</v-icon>
                            <span>{{ c.msg }}</span>
                        </div>
                    </div>
                    <div class="tl-sb-sec" v-if="canEdit && !info.sugg.some(x => x.kind === 'mover' || x.kind === 'reparar') && info.why">
                        <div class="tl-sb-sec-title">Sin hora libre</div>
                        <div class="tl-cfg-help">{{ info.why }}</div>
                    </div>
                    <div class="tl-sb-sec" v-if="canEdit && info.sugg.length">
                        <div class="tl-sb-sec-title">Sugerencias</div>
                        <div v-for="(s, i) in info.sugg" :key="i" class="tl-sb-sugg">
                            <div style="flex:1"><b>{{ s.label }}</b><div class="tl-cfg-help">{{ s.why }}<template v-if="s.teacher"> · docente libre: {{ s.teacher.name }}</template></div></div>
                            <button class="tl-proj-flexbtn" @click="applySugg(s)">Aplicar</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- clase fija -->
            <v-dialog :value="!!fixedSel" @input="v => { if (!v) fixedSel = null; }" max-width="460">
                <v-card v-if="fixedSel">
                    <v-card-title class="text-subtitle-1 font-weight-bold">{{ fixedSel.name }}</v-card-title>
                    <v-card-text>
                        <div class="tl-cfg-help">Clase ya creada{{ fixedSel.samePeriod ? ' en este periodo' : ' de otro periodo que sigue corriendo' }}: no se mueve desde aquí,
                            pero sus estudiantes, docente y aula cuentan para los choques.</div>
                        <div class="mt-2">{{ dayName(fixedSel.day) }} {{ hhmm(fixedSel.start) }}–{{ hhmm(fixedSel.end) }} · {{ fixedSel.line2 }}</div>
                        <div class="tl-cfg-help">Del {{ fmt(fixedSel.from) }} al {{ fmt(fixedSel.to) }} · {{ fixedSel.size }} estudiantes</div>
                    </v-card-text>
                    <v-card-actions><v-spacer></v-spacer><v-btn text @click="fixedSel = null">Cerrar</v-btn></v-card-actions>
                </v-card>
            </v-dialog>

            <!-- guardar -->
            <v-dialog v-model="saveDlg" max-width="520">
                <v-card>
                    <v-card-title class="text-subtitle-1 font-weight-bold">Guardar el borrador del tablero</v-card-title>
                    <v-card-text>
                        <div class="tl-cfg-help mb-2">Se guarda aparte en el periodo {{ periodName }}. No se crea ninguna clase, no se inscribe a nadie y no se toca
                            el borrador del Planificador Académico.</div>
                        <table class="tl-cfg-table">
                            <tr><td>Grupos con horario</td><td><b>{{ sum.placed }}</b> de {{ sum.total }}</td></tr>
                            <tr><td>Con choques</td><td :style="{ color: sum.hard ? '#B91C1C' : '' }"><b>{{ sum.hard }}</b></td></tr>
                            <tr><td>Con avisos</td><td><b>{{ sum.soft }}</b></td></tr>
                            <tr><td>Sin docente / sin aula</td><td><b>{{ sum.noTeacher }}</b> / <b>{{ sum.noRoom }}</b></td></tr>
                        </table>
                        <div v-if="sum.hard" class="tl-cfg-msg tl-cfg-msg-error mt-2">Hay choques: se puede guardar como borrador, pero habrá que resolverlos antes de publicar.</div>
                    </v-card-text>
                    <v-card-actions><v-spacer></v-spacer>
                        <v-btn text @click="saveDlg = false">Cancelar</v-btn>
                        <v-btn color="primary" depressed :loading="saving" @click="save">Guardar borrador</v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>

            <!-- preferencia de dia y hora de la asignatura -->
            <v-dialog v-model="prefDlg.show" max-width="520">
                <v-card v-if="info">
                    <v-card-title class="text-subtitle-1 font-weight-bold">Preferencia de {{ info.g.name }}</v-card-title>
                    <v-card-text>
                        <div class="tl-cfg-help mb-2">El acomodo automático y las sugerencias la usan primero. Si no cabe sin choques, se usa otro día u hora
                            y la ficha queda anotada. Vale para los próximos periodos (Configuración → Jornadas y horarios).</div>
                        <div class="mb-2"><b>Días:</b>
                            <button v-for="d in [1, 2, 3, 4, 5, 6]" :key="d" type="button" class="tl-cfg-daybtn" :class="{ 'tl-cfg-daybtn-on': prefDlg.days.includes(d) }"
                                    @click="prefDlg.days = prefDlg.days.includes(d) ? prefDlg.days.filter(x => x !== d) : prefDlg.days.concat([d]).sort()">{{ E.DAYS_SHORT[d] }}</button></div>
                        <div class="mb-2"><b>Franja (opcional):</b> <input type="time" class="tl-cfg-input" v-model="prefDlg.start"> a <input type="time" class="tl-cfg-input" v-model="prefDlg.end">
                            <button v-if="placedSel" class="tl-proj-flexbtn" @click="prefDlg.days = [info.p.day]; prefDlg.start = hhmm(info.p.start); prefDlg.end = hhmm(info.p.end)">usar la ubicación actual</button></div>
                        <div class="mb-2"><b>Para:</b>
                            <select class="tl-cfg-input" v-model.number="prefDlg.planid">
                                <option :value="0">Todas las carreras</option>
                                <option v-for="pid in info.g.plans" :key="pid" :value="pid">Solo {{ planName(pid) }}</option>
                            </select>
                            <select class="tl-cfg-input" v-model="prefDlg.jornada">
                                <option value="">En todas las jornadas</option>
                                <option :value="info.g.jornada">Solo jornada {{ info.g.jornada }}</option>
                            </select></div>
                        <div v-if="prefDlg.msg" class="tl-cfg-msg tl-cfg-msg-error">{{ prefDlg.msg }}</div>
                    </v-card-text>
                    <v-card-actions><v-spacer></v-spacer>
                        <v-btn v-if="prefInfo && prefInfo.source === 'asignatura'" text color="error" :loading="prefDlg.saving" @click="savePref(true)">Quitar</v-btn>
                        <v-btn text @click="prefDlg.show = false">Cancelar</v-btn>
                        <v-btn color="primary" depressed :loading="prefDlg.saving" @click="savePref(false)">Guardar preferencia</v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>

            <!-- publicar -->
            <v-dialog v-model="pub.show" max-width="980" scrollable :persistent="pub.running">
                <v-card>
                    <v-card-title class="d-flex">
                        <div><div class="text-subtitle-1 font-weight-bold">Publicar {{ periodName }}</div>
                            <div class="tl-cfg-help">Crea las clases del tablero como las crea el Planificador Académico: <b>sin aprobar</b>, con sus estudiantes
                                en la cola, su horario, grupo, sección, asistencia y sesiones virtuales. Al aprobarlas el área académica, se inscribe a los
                                estudiantes. Nunca modifica ni borra clases existentes.</div></div>
                        <v-spacer></v-spacer><v-btn icon :disabled="pub.running" @click="pub.show = false"><v-icon>mdi-close</v-icon></v-btn>
                    </v-card-title>
                    <v-card-text>
                        <div v-if="dirty" class="tl-cfg-msg tl-cfg-msg-error">Hay cambios sin guardar. Guarde el borrador primero: se publica exactamente lo guardado.</div>
                        <div v-else-if="pub.loading" class="tl-plan-empty" style="padding:16px">Revisando el tablero en el servidor…</div>
                        <div v-else-if="pub.error" class="tl-cfg-msg tl-cfg-msg-error">{{ pub.error }}</div>
                        <template v-else-if="pub.plan && !pub.result">
                            <div class="tl-proj-kpis mb-3">
                                <div class="tl-proj-kpi tl-proj-kpi-ok"><b>{{ pub.plan.totals.listo }}</b><span>grupos listos</span></div>
                                <div class="tl-proj-kpi tl-proj-kpi-warn"><b>{{ pub.plan.totals.bloqueado }}</b><span>no se pueden publicar</span></div>
                                <div class="tl-proj-kpi"><b>{{ pub.plan.totals.publicado }}</b><span>ya publicados</span></div>
                                <div class="tl-proj-kpi"><b>{{ pubSelQueue }}</b><span>estudiantes en cola</span></div>
                            </div>
                            <div class="tl-sb-sec-title">Grupos listos ({{ pubReady.length }})
                                <button class="tl-proj-flexbtn" @click="pubAll(true)">todos</button><button class="tl-proj-flexbtn" @click="pubAll(false)">ninguno</button></div>
                            <table class="tl-cfg-table tl-sb-pubtable">
                                <thead><tr><th></th><th>Asignatura</th><th>Jornada</th><th>Horario</th><th>Docente / aula</th><th>Est.</th><th>Fechas</th><th>Avisos</th></tr></thead>
                                <tbody>
                                    <tr v-for="g in pubReady" :key="g.key">
                                        <td><input type="checkbox" v-model="pub.sel[g.key]"></td>
                                        <td>{{ g.name }} <span class="tl-cfg-help">G{{ g.number }}</span></td><td>{{ g.jornada }}</td>
                                        <td>{{ dayName(g.day) }} {{ hhmm(g.start) }}–{{ hhmm(g.end) }}</td>
                                        <td>{{ g.teacher || 'Sin docente' }} · {{ g.room || 'sin aula' }}</td><td>{{ g.size }}</td>
                                        <td class="tl-cfg-help">{{ fmt(g.from) }} – {{ fmt(g.to) }} · {{ (g.dates || []).length }} sesiones</td>
                                        <td class="tl-cfg-help" style="color:#B45309">{{ g.warnings.join(' ') }}</td>
                                    </tr>
                                </tbody>
                            </table>
                            <template v-if="pubBlocked.length">
                                <div class="tl-sb-sec-title mt-3">No se pueden publicar ({{ pubBlocked.length }}) <button class="tl-proj-flexbtn" @click="pub.showBlocked = !pub.showBlocked">{{ pub.showBlocked ? 'ocultar' : 'ver por qué' }}</button></div>
                                <div v-if="pub.showBlocked" class="tl-sb-failed">
                                    <div v-for="g in pubBlocked" :key="g.key" class="tl-sb-failed-row" @click="pub.show = false; goTo(g.key)"><b>{{ g.name }} G{{ g.number }} ({{ g.jornada }})</b>: {{ g.blocks.join(' ') }}</div>
                                </div>
                            </template>
                            <template v-if="pub.plan.modules.length">
                                <div class="tl-sb-sec-title mt-3">Módulos decididos en la proyección</div>
                                <div class="tl-cfg-msg mb-1" style="background:#FFFBEB;color:#78350F">Los módulos <b>inscriben de inmediato</b> a los estudiantes elegidos (como el flujo de
                                    módulos del área académica), <b>sin pago</b>, en {{ periodName }}, con plazo hasta el {{ fmt(pub.plan.module_due) }}.</div>
                                <label v-for="m in pub.plan.modules" :key="'m' + m.courseid" class="tl-cfg-check d-flex" style="gap:6px">
                                    <input type="checkbox" v-model="pub.mods[m.courseid]" :disabled="!m.pending.length">
                                    <span><b>{{ m.name }}</b> · {{ m.jornada || 'sin jornada' }} · {{ m.pending.length }} por inscribir<template v-if="m.done"> ({{ m.done }} ya inscritos)</template>
                                        <span class="tl-cfg-help">— {{ m.note }}</span></span>
                                </label>
                            </template>
                            <template v-if="pub.plan.unscheduled.length">
                                <div class="tl-sb-sec-title mt-3">Asignaturas sin horario (práctica profesional)</div>
                                <div class="tl-cfg-help mb-1">Se cargan por el mismo flujo de módulos, sin pago ni docente, con plazo hasta el {{ fmt(pub.plan.module_due) }}.
                                    Márquelas solo si así se gestionan en el área académica.</div>
                                <label v-for="m in pub.plan.unscheduled" :key="'u' + m.courseid" class="tl-cfg-check d-flex" style="gap:6px">
                                    <input type="checkbox" v-model="pub.uns[m.courseid]" :disabled="!m.pending.length">
                                    <span><b>{{ m.name }}</b> · {{ m.pending.length }} por cargar<template v-if="m.done"> ({{ m.done }} ya cargados)</template></span>
                                </label>
                            </template>
                            <label class="tl-cfg-check mt-3 d-flex" style="gap:6px;font-weight:600">
                                <input type="checkbox" v-model="pub.confirm">
                                <span>Entiendo: se crearán {{ pubKeys.length }} clase(s) sin aprobar con {{ pubSelQueue }} estudiante(s) en cola<template v-if="pubModCount">,
                                    y se inscribirá a {{ pubModCount }} estudiante(s) en módulos de inmediato</template>. Lo publicado ya no se mueve desde el tablero.</span>
                            </label>
                        </template>
                        <template v-if="pub.running || pub.result">
                            <div class="mb-2"><b>{{ pub.running ? 'Publicando…' : 'Publicación terminada' }}</b> {{ pub.done }} de {{ pub.total }} pasos</div>
                            <v-progress-linear :value="pub.total ? pub.done / pub.total * 100 : 0" height="8" rounded color="#4F46E5"></v-progress-linear>
                            <template v-if="pub.result">
                                <div class="tl-cfg-msg mt-3" :class="pub.result.failed.length || pub.result.progress.length ? 'tl-cfg-msg-error' : 'tl-cfg-msg-ok'">
                                    {{ pub.result.created.length }} clase(s) creada(s) pendientes de aprobación<template v-if="pub.result.failed.length">;
                                    {{ pub.result.failed.length }} no se crearon (no quedó nada a medias)</template><template v-if="pub.result.modules.length">;
                                    {{ pub.result.modules.filter(x => x.status === 'ok').length }} de {{ pub.result.modules.length }} inscripciones en módulos</template>.</div>
                                <div v-for="c in pub.result.created" :key="'c' + c.classid" class="tl-cfg-help">✓ {{ c.name }} (clase {{ c.classid }}, {{ c.queue }} en cola)</div>
                                <div v-for="f in pub.result.failed" :key="'f' + f.key" style="color:#B91C1C;font-size:12px">✗ {{ f.name }}: {{ f.error }}</div>
                                <div v-for="(m, i) in pub.result.modules" :key="'mm' + i" class="tl-cfg-help" :style="{ color: m.status === 'ok' ? '' : '#B45309' }">
                                    {{ m.status === 'ok' ? '✓' : '!' }} {{ m.course }} — {{ m.name }}: {{ m.message }}</div>
                                <div v-if="pub.result.progress.length" class="tl-cfg-msg mt-2" style="background:#FFFBEB;color:#78350F">
                                    Al crear las actividades, Moodle recalculó notas de esas asignaturas y cambió {{ pubProgressCount }} registro(s) de avance de estudiantes
                                    (normalmente solo la fecha de la nota). Quedó el antes y el después en el respaldo de la publicación para revisarlo.</div>
                            </template>
                        </template>
                    </v-card-text>
                    <v-card-actions v-if="!dirty">
                        <v-spacer></v-spacer>
                        <template v-if="pub.result"><v-btn color="primary" depressed @click="pub.show = false; load()">Cerrar y recargar el tablero</v-btn></template>
                        <template v-else>
                            <v-btn text :disabled="pub.running" @click="pub.show = false">Cancelar</v-btn>
                            <v-btn color="primary" depressed :loading="pub.running" :disabled="!pub.plan || !pub.confirm || (!pubKeys.length && !pubModCount)" @click="publish">Publicar</v-btn>
                        </template>
                    </v-card-actions>
                </v-card>
            </v-dialog>

            <!-- docentes: habilidades y disponibilidad -->
            <v-dialog v-model="teacherDlg.show" max-width="860" scrollable>
                <v-card>
                    <v-card-title class="d-flex">
                        <div><div class="text-subtitle-1 font-weight-bold">Docentes</div>
                            <div class="tl-cfg-help">Habilidades (qué asignaturas puede dictar) y disponibilidad por día. Los cambios se guardan al momento en el
                                perfil del docente y valen para todos los periodos (también los ve el Planificador Académico).</div></div>
                        <v-spacer></v-spacer><v-btn icon @click="teacherDlg.show = false"><v-icon>mdi-close</v-icon></v-btn>
                    </v-card-title>
                    <v-card-text>
                        <div class="tl-sb-tdlg">
                            <div class="tl-sb-tlist">
                                <input v-model="teacherDlg.q" class="tl-cfg-input" style="width:100%" placeholder="Buscar docente…">
                                <div v-for="t in teacherList" :key="t.id" class="tl-sb-opt" :class="{ 'tl-sb-opt-on': teacherDlg.id === t.id }" @click="teacherDlg.id = t.id">
                                    <div style="flex:1;min-width:0"><div class="tl-sb-opt-name">{{ t.name }}</div>
                                        <div class="tl-sb-opt-tags"><span class="tl-sb-tag">{{ t.skills.length }} habilidades</span>
                                            <span :class="t.has_avail ? 'tl-sb-tag' : 'tl-sb-tag-no'">{{ t.has_avail ? 'con disponibilidad' : 'sin disponibilidad' }}</span>
                                            <span class="tl-sb-tag">{{ Math.round(t.load / 60 * 10) / 10 }} h/sem</span></div></div>
                                </div>
                            </div>
                            <div class="tl-sb-tdetail" v-if="tSel">
                                <div class="tl-sb-panel-title">{{ tSel.name }}</div>
                                <div class="tl-cfg-help mb-2">{{ Math.round(tLoad / 60 * 10) / 10 }} h por semana en el tablero ({{ tGroups.length }} grupos)</div>
                                <div class="tl-sb-sec-title">Habilidades</div>
                                <div class="tl-cfg-chips mb-2">
                                    <span v-for="cid in tSel.skills" :key="cid" class="tl-cfg-chip tl-cfg-chip-on">{{ courseName(cid) }}
                                        <button v-if="canEdit" class="tl-proj-unx" @click="skill(tSel.id, cid, false)" title="Quitar la habilidad">×</button></span>
                                    <span v-if="!tSel.skills.length" class="tl-plan-empty">Ninguna registrada.</span>
                                </div>
                                <v-autocomplete v-if="canEdit" dense outlined hide-details :items="skillItems" item-text="name" item-value="courseid"
                                    v-model="teacherDlg.addSkill" label="Agregar habilidad: escriba la asignatura…" no-data-text="Sin resultados"
                                    @change="v => { if (v) { skill(tSel.id, v, true); $nextTick(() => { teacherDlg.addSkill = null; }); } }"></v-autocomplete>
                                <div class="tl-sb-sec-title mt-3">Disponibilidad</div>
                                <div v-for="d in [1, 2, 3, 4, 5, 6]" :key="'av' + d" class="tl-sb-avrow">
                                    <div class="tl-sb-avday">{{ dayName(d) }}</div>
                                    <div style="flex:1">
                                        <span v-for="(r, i) in availOf(tSel, d)" :key="i" class="tl-cfg-chip tl-cfg-chip-on">{{ hhmm(r[0]) }}–{{ hhmm(r[1]) }}
                                            <button v-if="canEdit" class="tl-proj-unx" @click="removeRange(tSel, d, i)" title="Quitar esta franja">×</button></span>
                                        <span v-if="!availOf(tSel, d).length" class="tl-cfg-help">No disponible</span>
                                    </div>
                                    <template v-if="canEdit">
                                        <input type="time" step="300" class="tl-cfg-input" style="width:96px" v-model="teacherDlg.newRange[d].a">
                                        <input type="time" step="300" class="tl-cfg-input" style="width:96px" v-model="teacherDlg.newRange[d].b">
                                        <button class="tl-proj-flexbtn" @click="addRange(tSel, d)">Agregar</button>
                                    </template>
                                </div>
                                <div v-if="teacherDlg.msg" class="tl-cfg-msg mt-2" :class="teacherDlg.err ? 'tl-cfg-msg-error' : 'tl-cfg-msg-ok'">{{ teacherDlg.msg }}</div>
                            </div>
                            <div v-else class="tl-cfg-help" style="padding:12px">Elija un docente de la lista.</div>
                        </div>
                    </v-card-text>
                </v-card>
            </v-dialog>
        </template>
    </div>
    `,
    props: {
        periodid: { type: Number, required: true },
        periodName: { type: String, default: '' },
        planIds: { type: String, default: '' },
        canEdit: { type: Boolean, default: true },
    },
    data() {
        return { loading: false, error: '', board: null, tick: 0, jornada: 'Diurna', q: '', tq: '', onlyIssues: false, sel: null, fixedSel: null,
            hover: null, failedOpen: false, dirty: false, weekSel: 0,
            prefDlg: { show: false, days: [], start: '', end: '', planid: 0, jornada: '', saving: false, msg: '' }, history: [], saving: false, saveDlg: false, showHelp: true, lastAction: null, version: 0,
            draftInfo: { saved_at: 0, byname: '' }, ppm: 1.1, staleOpen: false, exporting: false,
            printModes: [
                { id: 'jornada', title: 'Horario por jornada', sub: 'Una hoja por jornada con la cuadrícula semanal' },
                { id: 'docente', title: 'Horario por docente', sub: 'Una hoja por docente: cuadrícula y detalle' },
                { id: 'aula', title: 'Horario por aula', sub: 'Una hoja por aula: ocupación y detalle' },
                { id: 'listado', title: 'Listado de horarios', sub: 'Tabla por jornada con fechas, docente, aula y estado' },
                { id: 'listas', title: 'Listas de clase', sub: 'Cada grupo con sus estudiantes, documento y carrera' }],
            pub: { show: false, loading: false, error: '', plan: null, sel: {}, mods: {}, uns: {}, confirm: false, showBlocked: false,
                running: false, done: 0, total: 0, result: null },
            teacherDlg: { show: false, id: 0, q: '', addSkill: null, msg: '', err: false,
                newRange: { 1: { a: '', b: '' }, 2: { a: '', b: '' }, 3: { a: '', b: '' }, 4: { a: '', b: '' }, 5: { a: '', b: '' }, 6: { a: '', b: '' } } } };
    },
    created() {
        this.S = null;          // estado del motor (no reactivo; se refresca con tick)
        this.drag = null;
        this._unload = (e) => { if (this.dirty) { e.preventDefault(); e.returnValue = ''; } };
        window.addEventListener('beforeunload', this._unload);
        this.load();
    },
    beforeDestroy() { window.removeEventListener('beforeunload', this._unload); },
    computed: {
        E() { return window.GmkBoard; },
        jornadas() {
            this.tick; // eslint-disable-line no-unused-expressions
            if (!this.S) return [];
            const s = {};
            this.S.order.forEach(k => { s[this.S.groups[k].jornada] = true; });
            return ['Diurna', 'Nocturna', 'Sabatina'].filter(j => s[j]).concat(Object.keys(s).filter(j => !['Diurna', 'Nocturna', 'Sabatina'].includes(j)));
        },
        sum() { this.tick; return this.S ? this.E.summary(this.S) : { total: 0, placed: 0, hard: 0, soft: 0, noTeacher: 0, noRoom: 0, conflicts: {} }; },
        jStats() {
            const out = {};
            this.jornadas.forEach(j => { out[j] = { total: 0, placed: 0, hard: 0 }; });
            if (!this.S) return out;
            this.S.order.forEach(k => {
                const g = this.S.groups[k], o = out[g.jornada];
                if (!o) return;
                o.total++;
                if (this.E.placed(this.S.place[k])) o.placed++;
                if (this.E.severity(this.sum.conflicts[k]) === 'hard') o.hard++;
            });
            return out;
        },
        groupsMap() { this.tick; return this.S ? this.S.groups : {}; },
        // Dias de la jornada mas los que usan sus fichas (p. ej. el domingo de un seminario).
        days() {
            if (!this.S) return [];
            const d = new Set(this.E.jornadaDays(this.S.cfg, this.jornada));
            Object.keys(this.S.groups).forEach(k => { if (this.S.groups[k].jornada === this.jornada) this.E.daysFor(this.S, k).forEach(x => d.add(x)); });
            return [...d].sort((a, b) => a - b);
        },
        // Franja visible: la de la jornada, ampliada si algo quedo afuera.
        win() {
            this.tick;
            if (!this.S) return { start: 480, end: 1320 };
            const w = this.E.window(this.S.cfg, this.jornada);
            let a = w.start, b = w.end;
            this.S.order.forEach(k => {
                const p = this.S.place[k];
                if (this.S.groups[k].jornada === this.jornada && this.E.placed(p)) { a = Math.min(a, p.start); b = Math.max(b, p.end); }
            });
            a = Math.floor((a - 30) / 60) * 60; b = Math.ceil((b + 30) / 60) * 60;
            return { start: Math.max(0, a), end: Math.min(24 * 60, b), wStart: w.start, wEnd: w.end };
        },
        outside() { return { before: Math.max(0, this.win.wStart - this.win.start), after: Math.max(0, this.win.end - this.win.wEnd) }; },
        colHeight() { return (this.win.end - this.win.start) * this.ppm; },
        hourMarks() { const out = []; for (let h = Math.ceil(this.win.start / 60) * 60; h <= this.win.end; h += 60) out.push(h); return out; },
        lunch() {
            if (!this.S) return null;
            const a = this.E.toMin(this.S.cfg.lunchStart), b = this.E.toMin(this.S.cfg.lunchEnd);
            if (a < 0 || b <= a || b <= this.win.start || a >= this.win.end) return null;
            return { start: Math.max(a, this.win.start), end: Math.min(b, this.win.end) };
        },
        matchQ() {
            const q = this.q.trim().toLowerCase();
            return (name, tid) => !q || name.toLowerCase().includes(q) || (tid && this.teacherName(tid).toLowerCase().includes(q));
        },
        cardsByDay() {
            this.tick;
            const out = {};
            if (!this.S) return out;
            const conf = this.sum.conflicts;
            this.days.forEach(d => { out[d] = []; });
            this.S.order.forEach(k => {
                const g = this.S.groups[k], p = this.S.place[k];
                if (g.jornada !== this.jornada || !this.E.placed(p) || !out[p.day]) return;
                const sev = this.E.severity(conf[k]);
                const wl = this.E.weeksLabel(this.S, k, p);
                const partial = wl && wl.indexOf('todo') !== 0;
                const dim = (this.onlyIssues && sev === 'ok') || !this.matchQ(g.name, p.teacherid);
                const span = this.E.weekSpan(this.S, k, p);
                if (this.weekSel && (this.weekSel - 1 < span.w0 || this.weekSel - 1 > span.w1)) return;
                out[p.day].push({ id: k, name: g.name, start: p.start, end: p.end, sev, locked: p.locked, dim, span,
                    line1: (g.published ? '✓ publicada · ' : '') + 'G' + g.number + (g.parts > 1 ? ' (' + g.part + '/' + g.parts + ')' : '') + ' · ' + g.size + ' est · '
                        + (partial ? wl.split(' · ')[0] + ' · ' + wl.split(' · ')[1] : this.E.hhmm(p.start) + '–' + this.E.hhmm(p.end)),
                    line2: (p.teacherid ? this.teacherName(p.teacherid) : 'Sin docente') + ' · ' + (p.roomid ? this.roomName(p.roomid) : 'sin aula'),
                    title: g.name + ' (G' + g.number + ')\n' + this.E.hhmm(p.start) + '–' + this.E.hhmm(p.end) + ' · ' + wl + '\n'
                        + (conf[k] || []).map(c => '• ' + c.msg).join('\n') });
            });
            // Clases ya creadas de esta jornada (o con estudiantes de los grupos de esta jornada).
            const users = new Set();
            this.S.order.forEach(k => { if (this.S.groups[k].jornada === this.jornada) this.S.groups[k].userids.forEach(u => users.add(u)); });
            this.S.fixed.forEach(f => {
                if (!out[f.day]) return;
                if (f.jornada !== this.jornada && ![...f.users].some(u => users.has(u))) return;
                if (f.to && f.to < (this.S.period.start || 0) + 7 * 86400) return;
                out[f.day].push({ id: f.id, name: f.name, start: f.start, end: f.end, sev: 'fixed', fixed: true, locked: true,
                    dim: this.onlyIssues || !this.matchQ(f.name, f.teacherid), from: f.from, to: f.to, day: f.day, samePeriod: f.samePeriod, size: f.users.size,
                    line1: this.E.hhmm(f.start) + '–' + this.E.hhmm(f.end) + ' · ya creada',
                    line2: (f.teacherid ? this.teacherName(f.teacherid) : 'Sin docente') + ' · ' + (f.roomid ? this.roomName(f.roomid) : 'sin aula'),
                    title: f.name + '\nClase ya creada: cuenta para los choques, no se mueve desde aquí.' });
            });
            // Cadenas: fichas de semanas distintas en la misma franja (mismo inicio) se dibujan como un solo bloque.
            Object.keys(out).forEach(d => {
                const byStart = {};
                out[d].forEach(c => { if (!c.fixed && c.span && c.span.partial) (byStart[c.start] = byStart[c.start] || []).push(c); });
                Object.keys(byStart).forEach(st => {
                    const list = byStart[st].sort((a, b) => a.span.w0 - b.span.w0);
                    if (list.length < 2) return;
                    const disjoint = list.every((c, i) => !i || c.span.w0 > list[i - 1].span.w1);
                    if (!disjoint) return;
                    out[d] = out[d].filter(c => !list.includes(c));
                    out[d].push({ id: 'chain:' + d + ':' + st, chain: list.map(c => Object.assign({}, c, { w0: c.span.w0, w1: c.span.w1, total: c.span.total })),
                        start: Number(st), end: Math.max(...list.map(c => c.end)), sev: list.some(c => c.sev === 'hard') ? 'hard' : (list.some(c => c.sev === 'soft') ? 'soft' : 'ok'),
                        dim: list.every(c => c.dim), title: list.map(c => c.title).join('\n\n') });
                });
            });
            Object.keys(out).forEach(d => { out[d] = this.E.lanes(out[d]); });
            return out;
        },
        weekOpts() {
            this.tick;
            if (!this.S) return [];
            const k = this.S.order.find(x => this.S.groups[x].jornada === this.jornada && !this.S.groups[x].long);
            const n = k ? Math.max(...this.days.map(d => ((this.S.groups[k].calendar || {}).days || {})[d] ? this.S.groups[k].calendar.days[d].length : 0)) : 0;
            return Array.from({ length: n }, (_, i) => i + 1);
        },
        prefInfo() {
            if (!this.info) return null;
            return this.E.prefFor(this.S, this.sel);
        },
        // Columnas mas anchas en los dias con varios grupos en paralelo, para que se lean las tarjetas.
        gridCols() {
            return '52px ' + this.days.map(d => {
                const cards = this.cardsByDay[d] || [];
                const lanes = Math.max(1, ...cards.map(c => c.lanes || 1));
                // Las cadenas necesitan mas ancho para leer cada tramo de semanas.
                const chainW = Math.max(0, ...cards.filter(c => c.chain).map(c => c.chain.length * 90));
                return 'minmax(' + Math.max(150, lanes * 92 + (chainW ? Math.max(0, chainW - 92) : 0)) + 'px, 1fr)';
            }).join(' ');
        },
        // Grupos que chocan con el seleccionado (se resaltan).
        related() {
            const r = {};
            if (!this.info) return r;
            this.info.conf.forEach(c => { if (c.with) r[c.with] = true; });
            return r;
        },
        unplaced() {
            this.tick;
            if (!this.S) return [];
            return this.S.order.filter(k => this.S.groups[k].jornada === this.jornada && !this.E.placed(this.S.place[k]))
                .map(k => this.S.groups[k]).filter(g => this.matchQ(g.name, 0))
                .sort((a, b) => b.size - a.size || a.name.localeCompare(b.name));
        },
        placedSel() { return !!(this.info && this.E.placed(this.info.p)); },
        info() {
            this.tick;
            if (!this.S || !this.sel || !this.S.groups[this.sel]) return null;
            const g = this.S.groups[this.sel], p = this.S.place[this.sel];
            const conf = this.sum.conflicts[this.sel] || [];
            const sugg = this.E.suggestions(this.S, this.sel, 4);
            const blocked = (!this.E.placed(p) || conf.some(c => c.sev === 'hard')) && !sugg.some(x => x.kind === 'mover' || x.kind === 'reparar');
            return { g, p, conf, sugg, why: blocked ? this.E.whyNoSlot(this.S, this.sel) : '' };
        },
        weekOptions() {
            if (!this.info || !this.E.placed(this.info.p)) return [];
            const out = [];
            for (let w = 0; w <= this.E.maxWeek(this.S, this.sel, this.info.p.day); w++) {
                const p = Object.assign({}, this.info.p, { week: w });
                const cs = this.E.conflicts(this.S, this.sel, p);
                const sev = this.E.severity(cs);
                out.push({ week: w, label: this.E.weeksLabel(this.S, this.sel, p).split(' · ').slice(0, 2).join(' · ') + (sev === 'hard' ? ' ✗' : sev === 'soft' ? ' !' : '') });
            }
            return out;
        },
        durSel() { return this.info ? (this.E.placed(this.info.p) ? this.info.p.end - this.info.p.start : this.info.g.duration) : 120; },
        durOptions() {
            const out = [];
            for (let m = 30; m <= 300; m += 15) out.push(m);
            if (!out.includes(this.durSel)) out.push(this.durSel);
            return out.sort((a, b) => a - b);
        },
        teacherOpts() {
            if (!this.info) return [];
            const q = this.tq.trim().toLowerCase();
            const all = this.E.teacherOptions(this.S, this.sel);
            return (q ? all.filter(o => o.name.toLowerCase().includes(q)) : all).slice(0, q ? 30 : 8);
        },
        roomOpts() { return this.info ? this.E.roomOptions(this.S, this.sel) : []; },
        teacherList() {
            this.tick;
            if (!this.S) return [];
            const q = this.teacherDlg.q.trim().toLowerCase();
            return Object.values(this.S.teachers).filter(t => !q || t.name.toLowerCase().includes(q))
                .map(t => Object.assign({}, t, { load: this.E.teacherLoad(this.S, t.id) }))
                .sort((a, b) => a.name.localeCompare(b.name));
        },
        tSel() { this.tick; return this.S && this.teacherDlg.id ? this.S.teachers[this.teacherDlg.id] : null; },
        tLoad() { this.tick; return this.tSel ? this.E.teacherLoad(this.S, this.tSel.id) : 0; },
        tGroups() { this.tick; return this.tSel ? this.S.order.filter(k => this.S.place[k].teacherid === this.tSel.id) : []; },
        pubReady() { return this.pub.plan ? this.pub.plan.groups.filter(g => g.status === 'listo') : []; },
        pubBlocked() { return this.pub.plan ? this.pub.plan.groups.filter(g => g.status === 'bloqueado') : []; },
        pubKeys() { return this.pubReady.filter(g => this.pub.sel[g.key]).map(g => g.key); },
        pubSelQueue() { return this.pubReady.filter(g => this.pub.sel[g.key]).reduce((a, g) => a + g.size, 0); },
        pubModCount() {
            if (!this.pub.plan) return 0;
            return this.pub.plan.modules.filter(m => this.pub.mods[m.courseid]).reduce((a, m) => a + m.pending.length, 0)
                + this.pub.plan.unscheduled.filter(m => this.pub.uns[m.courseid]).reduce((a, m) => a + m.pending.length, 0);
        },
        pubProgressCount() {
            return this.pub.result ? this.pub.result.progress.reduce((a, x) => a + Object.keys(x.rows || {}).length, 0) : 0;
        },
        skillItems() {
            if (!this.board || !this.tSel) return [];
            return (this.board.skills_catalog || []).filter(c => !this.tSel.skills.includes(c.courseid));
        },
    },
    methods: {
        reportCtx() {
            return { S: this.S, E: this.E, board: this.board, conflicts: this.sum.conflicts, periodName: this.periodName || (this.board.period && this.board.period.name) || '',
                dirty: this.dirty, draftInfo: this.draftInfo };
        },
        printReport(mode) {
            if (!window.GmkBoardReport || !this.S) return;
            window.GmkBoardReport.print(this.reportCtx(), mode);
        },
        async exportExcel() {
            if (!window.GmkBoardReport || !this.S) return;
            this.exporting = true;
            try {
                const r = await window.GmkBoardReport.excel(this.reportCtx());
                this.lastAction = { text: 'Excel descargado: ' + r.groups + ' grupos, ' + r.sessions + ' sesiones con fecha y ' + r.students + ' filas de estudiantes.'
                    + (this.dirty ? ' Incluye los cambios sin guardar.' : '') };
            } catch (e) { this.lastAction = { error: true, text: 'No se pudo exportar: ' + (e.message || e) }; }
            finally { this.exporting = false; }
        },
        hhmm(m) { return this.E.hhmm(m); },
        dayName(d) { return this.E.DAYS[d] || ''; },
        fmt(ts) { return ts ? new Date(ts * 1000).toLocaleDateString('es-PA', { day: '2-digit', month: 'short' }) : '-'; },
        fmtLong(ts) { return ts ? new Date(ts * 1000).toLocaleString('es-PA', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '-'; },
        teacherName(id) { const t = this.S && this.S.teachers[id]; return t ? t.name : 'Docente #' + id; },
        roomName(id) { const r = this.S && this.S.rooms[id]; return r ? r.name : 'Aula #' + id; },
        courseName(cid) {
            const c = (this.board.skills_catalog || []).find(x => x.courseid === cid);
            return c ? c.name : 'Asignatura ' + cid;
        },
        groupLabel(k) { const g = this.S.groups[k]; return g ? g.name + ' G' + g.number + ' (' + g.jornada + ')' : k; },
        topLevels(g) { return Object.keys(g.levels || {}).slice(0, 3).map(l => l + ' (' + g.levels[l] + ')').join(' · '); },
        holidaysOn(d) {
            if (!this.S) return [];
            const from = (this.S.period.jornada_starts || {})[this.jornada] || this.S.period.start;
            return this.S.holidays.filter(h => h.day === d && h.date >= from && h.date < from + 16 * 7 * 86400)
                .map(h => new Date(h.date * 1000).toLocaleDateString('es-PA', { day: '2-digit', month: 'short' }) + ' ' + h.name);
        },
        cardStyle(c) {
            const w = 100 / (c.lanes || 1);
            return { top: (c.start - this.win.start) * this.ppm + 'px', height: Math.max(18, (c.end - c.start) * this.ppm - 2) + 'px',
                left: 'calc(' + (c.lane * w) + '% + 2px)', width: 'calc(' + w + '% - 4px)' };
        },
        async ws(section, data) {
            const body = new URLSearchParams({ wstoken: window.userToken, moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_planning_save_config', section, data: JSON.stringify(data) });
            const r = await window.axios.post(window.location.origin + '/webservice/rest/server.php', body);
            if (r.data && r.data.exception) throw new Error(r.data.message || r.data.errorcode);
            return Object.assign({}, r.data, { saved: typeof r.data.saved === 'string' ? JSON.parse(r.data.saved) : r.data.saved });
        },
        async load() {
            this.loading = true; this.error = '';
            try {
                const r = await window.axios.get(window.location.origin + '/webservice/rest/server.php', { params: {
                    wstoken: window.userToken, moodlewsrestformat: 'json', wsfunction: 'local_grupomakro_planning_get_demand',
                    periodid: this.periodid, planids: this.planIds, board: 1 } });
                if (r.data && r.data.exception) throw new Error(r.data.message || r.data.errorcode);
                const d = JSON.parse(r.data.data);
                const b = d.result.board;
                if (!b) throw new Error('El periodo no tiene datos para el tablero.');
                this.board = Object.freeze(b);
                this.S = this.E.create(b, (b.draft && b.draft.items) || {});
                this.version = b.draft ? b.draft.version : 0;
                this.draftInfo = { saved_at: b.draft ? b.draft.saved_at : 0, byname: b.draft ? b.draft.byname : '' };
                this.history = []; this.dirty = false; this.sel = null; this.lastAction = null;
                if (this.jornadas.length && !this.jornadas.includes(this.jornada)) this.jornada = this.jornadas[0];
                this.tick++;
                if (this.jornadas.length && !this.jornadas.includes(this.jornada)) this.jornada = this.jornadas[0];
            } catch (e) { this.error = 'No se pudo cargar el tablero: ' + (e.message || e); }
            finally { this.loading = false; }
        },
        async saveProposal() {
            const upd = !!(this.board && this.board.proposal_changes);
            if (!window.confirm(upd
                ? '¿Actualizar la propuesta de grupos con las decisiones y la configuración de hoy? Los grupos que ya no van salen del tablero y los nuevos quedan sin ubicar; las fichas que siguen igual conservan su día, hora, docente y aula. No crea clases.'
                : '¿Guardar la propuesta de grupos actual para armar el tablero? Se guarda aparte en el periodo; no crea clases.')) return;
            this.saving = true;
            try {
                const r = await this.ws('proposal_save', { periodid: this.periodid });
                if (!r.ok) { window.alert(r.message); return; }
                this.$emit('proposal-saved');
                await this.load();
            } catch (e) { window.alert('Error: ' + (e.message || e)); }
            finally { this.saving = false; }
        },
        // ---- cambios (con deshacer) ----
        remember() {
            this.history.push(JSON.stringify(this.S.place));
            if (this.history.length > 60) this.history.shift();
            this.dirty = true;
        },
        undo() {
            const prev = this.history.pop();
            if (!prev) return;
            this.S.place = JSON.parse(prev);
            this.dirty = true; this.tick++;
        },
        select(k) { this.sel = this.sel === k ? null : k; this.tq = ''; },
        goTo(k) {
            const g = this.S.groups[k];
            if (g) { this.jornada = g.jornada; this.sel = k; this.tq = ''; }
        },
        setPlace(k, patch) {
            if (!this.canEdit) return;
            if (this.S.groups[k] && this.S.groups[k].published) {
                this.lastAction = { text: 'Ese grupo ya está publicado: se cambia desde la gestión de clases, no desde el tablero.', error: true };
                return;
            }
            this.remember();
            this.S.place[k] = Object.assign({}, this.S.place[k], patch);
            this.tick++;
        },
        unplace(k) { this.setPlace(k, { day: 0, start: 0, end: 0, week: 0 }); },
        setDuration(m) {
            const p = this.info.p;
            if (this.E.placed(p)) this.setPlace(this.sel, { end: p.start + m });
            else { this.S.groups[this.sel].duration = m; this.tick++; }
        },
        setLock(v) { this.setPlace(this.sel, { locked: v }); },
        setTeacher(id) {
            const sibs = this.E.siblings(this.S, this.sel);
            if (sibs.some(k => this.S.groups[k].published)) { this.setPlace(this.sel, { teacherid: id }); return; }
            this.remember();
            sibs.forEach(k => { this.S.place[k] = Object.assign({}, this.S.place[k], { teacherid: id }); });
            this.tick++;
        },
        setRoom(id) { this.setPlace(this.sel, { roomid: id }); },
        applySugg(s) {
            if (s.kind === 'mover') {
                const patch = { day: s.day, start: s.start, end: s.end, week: s.week || 0 };
                if (s.teacher && !this.info.p.teacherid) patch.teacherid = s.teacher.id;
                this.setPlace(this.sel, patch);
            } else if (s.kind === 'docente') this.setPlace(this.sel, { teacherid: s.teacherid });
            else if (s.kind === 'aula') this.setPlace(this.sel, { roomid: s.roomid });
            else if (s.kind === 'reparar') {
                this.remember();
                const ok = this.E.repair(this.S, this.sel);
                this.tick++;
                this.lastAction = { text: ok ? 'Se le hizo lugar moviendo otros grupos (puede deshacerlo).' : 'Ya no se pudo hacerle lugar; el tablero cambió.', error: !ok };
                if (!ok) this.history.pop();
            }
        },
        autoPlace(all) {
            if (all && !window.confirm('¿Reacomodar todos los grupos que no están bloqueados? Puede deshacerlo con el botón de deshacer.')) return;
            this.remember();
            const r = this.E.autoPlace(this.S, { all });
            this.tick++;
            this.lastAction = { text: 'Acomodo automático: ' + r.placed.length + ' ficha(s) ubicadas sin choques'
                + (r.chained ? ' (' + r.chained + ' encadenadas en secuencia con otras asignaturas cortas)' : '')
                + (r.failed.length ? '; ' + r.failed.length + ' sin hora libre (ver detalle).' : '.'), failed: r.failed, error: false };
        },
        autoAssign() {
            this.remember();
            const r = this.E.autoAssign(this.S);
            this.tick++;
            this.lastAction = { text: 'Docentes asignados: ' + r.assigned + '; aulas: ' + r.rooms + '.'
                + (r.missing.length ? ' ' + r.missing.length + ' grupo(s) sin un docente con la habilidad y disponibilidad libre a esa hora.' : ''),
                failed: r.missing.map(k => ({ key: k, reason: 'Ningún docente con la habilidad está disponible y libre a esa hora. Elija uno en el panel (se muestran los avisos) o registre habilidad/disponibilidad.' })),
                error: false };
        },
        // ---- arrastrar ----
        dragStart(e, k, fromGrid) {
            if (!this.canEdit) { e.preventDefault(); return; }
            this.dragSel = true;
            const p = this.S.place[k];
            const rect = e.target.getBoundingClientRect();
            this.drag = { key: k, grab: fromGrid ? (e.clientY - rect.top) / this.ppm : 0,
                dur: this.E.placed(p) ? p.end - p.start : this.S.groups[k].duration };
            e.dataTransfer.effectAllowed = 'move';
            try { e.dataTransfer.setData('text/plain', k); } catch (x) { /* IE */ }
        },
        dragEnd() { this.drag = null; this.hover = null; },
        dragOver(e, day) {
            if (!this.drag) return;
            const col = e.currentTarget.getBoundingClientRect();
            let start = this.win.start + (e.clientY - col.top) / this.ppm - this.drag.grab;
            start = Math.round(start / 5) * 5;
            start = Math.max(this.win.start, Math.min(this.win.end - this.drag.dur, start));
            if (this.hover && this.hover.day === day && this.hover.start === start) return;
            const p = this.S.place[this.drag.key];
            const test = { day, start, end: start + this.drag.dur, teacherid: p.teacherid, roomid: p.roomid, week: 0 };
            // Elige sola la semana de inicio que menos choca (asi una asignatura corta se encadena despues de otra).
            const bw = this.E.bestWeek(this.S, this.drag.key, test);
            test.week = bw.week;
            const cs = bw.conflicts;
            const wl = this.E.weeksLabel(this.S, this.drag.key, test);
            this.hover = { day, start, end: start + this.drag.dur, week: bw.week, sev: this.E.severity(cs),
                msgs: (wl && wl.indexOf('todo') !== 0 ? [wl] : []).concat(cs.filter(c => c.sev !== 'info').map(c => c.msg)) };
        },
        dragLeave(e, day) {
            if (this.hover && this.hover.day === day && !e.currentTarget.contains(e.relatedTarget)) this.hover = null;
        },
        drop(e, day) {
            if (!this.drag || !this.hover) return;
            this.setPlace(this.drag.key, { day, start: this.hover.start, end: this.hover.end, week: this.hover.week || 0 });
            this.dragEnd();
        },
        dropUnplace() {
            if (this.drag && this.E.placed(this.S.place[this.drag.key])) this.unplace(this.drag.key);
            this.dragEnd();
        },
        // ---- guardar ----
        askSave() { this.saveDlg = true; },
        async save() {
            this.saving = true;
            try {
                const r = await this.ws('board_save', { periodid: this.periodid, version: this.version, items: this.E.serialize(this.S) });
                if (!r.ok) {
                    window.alert(r.message);
                    if (r.saved && r.saved.conflict && window.confirm('¿Recargar el tablero ahora? Se perderán los cambios que no guardó.')) await this.load();
                    return;
                }
                if (r.saved) {
                    this.version = r.saved.version;
                    this.draftInfo = { saved_at: r.saved.saved_at, byname: r.saved.byname };
                }
                this.dirty = false; this.saveDlg = false;
                this.lastAction = { text: r.message, error: false };
            } catch (e) { window.alert('Error al guardar: ' + (e.message || e)); }
            finally { this.saving = false; }
        },
        // ---- preferencia de la asignatura (se guarda al momento en la configuracion) ----
        planName(pid) {
            const c = (this.board.careers || []).find(x => Number(x.id) === Number(pid));
            return c ? c.name : 'Carrera ' + pid;
        },
        openPref() {
            const pf = this.prefInfo;
            const raw = pf && pf.source === 'asignatura' ? pf.raw : null;
            this.prefDlg = { show: true, days: raw ? raw.days.map(Number) : (this.placedSel ? [this.info.p.day] : []), start: raw ? raw.start : '',
                end: raw ? raw.end : '', planid: raw ? Number(raw.planid || 0) : 0, jornada: raw ? (raw.jornada || '') : '', saving: false, msg: '' };
        },
        async savePref(remove) {
            const g = this.info.g;
            this.prefDlg.saving = true; this.prefDlg.msg = '';
            try {
                const r = await this.ws(remove ? 'subject_pref_remove' : 'subject_pref_set', { courseid: g.courseid, planid: this.prefDlg.planid, jornada: this.prefDlg.jornada,
                    days: this.prefDlg.days, start: this.prefDlg.start, end: this.prefDlg.end });
                if (!r.ok) { this.prefDlg.msg = r.message; return; }
                this.S.prefs.subjects = r.saved || [];
                this.prefDlg.show = false;
                this.tick++;
                this.lastAction = { text: r.message + ' Use "Acomodar automáticamente" para aplicarla a lo que no está bloqueado.', error: false };
            } catch (e) { this.prefDlg.msg = 'Error: ' + (e.message || e); }
            finally { this.prefDlg.saving = false; }
        },
        // ---- publicar (fase D) ----
        async openPublish() {
            this.pub = Object.assign(this.pub, { show: true, loading: !this.dirty, error: '', plan: null, sel: {}, mods: {}, uns: {}, confirm: false,
                showBlocked: false, running: false, done: 0, total: 0, result: null });
            if (this.dirty) return;
            try {
                const r = await this.ws('publish_plan', { periodid: this.periodid });
                if (!r.ok) { this.pub.error = r.message; return; }
                this.pub.plan = r.saved;
                const sel = {};
                r.saved.groups.forEach(g => { if (g.status === 'listo') sel[g.key] = true; });
                const mods = {};
                r.saved.modules.forEach(m => { mods[m.courseid] = m.pending.length > 0; });
                const uns = {};
                r.saved.unscheduled.forEach(m => { uns[m.courseid] = false; });
                this.pub.sel = sel; this.pub.mods = mods; this.pub.uns = uns;
            } catch (e) { this.pub.error = 'No se pudo revisar: ' + (e.message || e); }
            finally { this.pub.loading = false; }
        },
        pubAll(v) { const sel = {}; this.pubReady.forEach(g => { sel[g.key] = v; }); this.pub.sel = sel; },
        async publish() {
            const keys = this.pubKeys.slice();
            const mods = Object.keys(this.pub.mods).filter(k => this.pub.mods[k]).map(Number);
            const uns = Object.keys(this.pub.uns).filter(k => this.pub.uns[k]).map(Number);
            // Por tandas: 3 clases por llamada (cada una crea grupo, seccion y actividades) y luego los modulos.
            const batches = [];
            for (let i = 0; i < keys.length; i += 3) batches.push({ keys: keys.slice(i, i + 3), modules: [], unscheduled: [] });
            if (mods.length || uns.length) batches.push({ keys: [], modules: mods, unscheduled: uns });
            const res = { created: [], failed: [], modules: [], progress: [] };
            this.pub.running = true; this.pub.done = 0; this.pub.total = batches.length;
            try {
                for (const b of batches) {
                    const r = await this.ws('publish', Object.assign({ periodid: this.periodid, version: this.pub.plan.version }, b));
                    if (!r.ok) {
                        res.failed.push({ key: '-', name: 'Tanda ' + (this.pub.done + 1), error: r.message });
                        break;
                    }
                    res.created = res.created.concat(r.saved.created || []);
                    res.failed = res.failed.concat(r.saved.failed || []);
                    res.modules = res.modules.concat(r.saved.modules || []);
                    res.progress = res.progress.concat(r.saved.progress_changed || []);
                    this.pub.done++;
                }
            } catch (e) {
                res.failed.push({ key: '-', name: 'Conexión', error: (e.message || e) + '. Lo que se alcanzó a crear quedó registrado; vuelva a revisar antes de reintentar.' });
            } finally {
                this.pub.running = false;
                this.pub.result = res;
                this.lastAction = { text: 'Publicación: ' + res.created.length + ' clase(s) creadas pendientes de aprobación'
                    + (res.failed.length ? ', ' + res.failed.length + ' con error' : '') + '.', error: !!res.failed.length };
            }
        },
        // ---- docentes (se guarda al momento) ----
        openTeacher(id) { this.teacherDlg.id = id; this.teacherDlg.msg = ''; this.teacherDlg.show = true; },
        applyTeacherState(st) {
            const t = this.S.teachers[st.id];
            if (!t) return;
            t.skills = st.skills;
            t.skillSet = new Set(st.skills.map(Number));
            t.avail = st.avail || {};
            t.has_avail = !!st.has_avail;
            this.tick++;
        },
        async teacherWrite(section, data, confirmMsg) {
            if (confirmMsg && !window.confirm(confirmMsg)) return false;
            this.teacherDlg.msg = ''; this.teacherDlg.err = false;
            try {
                const r = await this.ws(section, data);
                this.teacherDlg.msg = r.message; this.teacherDlg.err = !r.ok;
                if (r.ok && r.saved) this.applyTeacherState(r.saved);
                if (!r.ok) window.alert(r.message);
                this.lastAction = { text: r.message, error: !r.ok };
                return r.ok;
            } catch (e) { this.teacherDlg.msg = 'Error: ' + (e.message || e); this.teacherDlg.err = true; return false; }
        },
        skill(uid, cid, add) {
            const msg = add ? '¿Registrar que ' + this.teacherName(uid) + ' puede dictar ' + this.courseName(cid) + '? Se guarda de inmediato y vale para todos los periodos.'
                : '¿Quitar la habilidad ' + this.courseName(cid) + ' a ' + this.teacherName(uid) + '? Se guarda de inmediato.';
            return this.teacherWrite(add ? 'teacher_skill_add' : 'teacher_skill_remove', { userid: uid, courseid: cid }, msg);
        },
        quickSkill(o) { this.skill(o.id, this.info.g.courseid, true); },
        availOf(t, d) { return (t.avail || {})[d] || (t.avail || {})[String(d)] || []; },
        writeDay(t, d, ranges, msg) {
            return this.teacherWrite('teacher_availability', { userid: t.id, day: d,
                ranges: ranges.map(r => ({ start: this.hhmm(r[0]), end: this.hhmm(r[1]) })) }, msg);
        },
        quickAvail(o) {
            const p = this.info.p, t = this.S.teachers[o.id];
            const ranges = this.availOf(t, p.day).concat([[p.start, p.end]]);
            this.writeDay(t, p.day, ranges, '¿Agregar ' + this.dayName(p.day) + ' ' + this.hhmm(p.start) + '–' + this.hhmm(p.end) + ' a la disponibilidad de '
                + o.name + '? Se guarda de inmediato y vale para todos los periodos.');
        },
        addRange(t, d) {
            const nr = this.teacherDlg.newRange[d];
            const a = this.E.toMin(nr.a), b = this.E.toMin(nr.b);
            if (a < 0 || b <= a) { window.alert('Indique hora de inicio y de fin (la de fin mayor).'); return; }
            this.writeDay(t, d, this.availOf(t, d).concat([[a, b]]), '¿Agregar ' + this.dayName(d) + ' ' + nr.a + '–' + nr.b + ' a la disponibilidad de ' + t.name + '?')
                .then(ok => { if (ok) { nr.a = ''; nr.b = ''; } });
        },
        removeRange(t, d, i) {
            const cur = this.availOf(t, d);
            const r = cur[i];
            this.writeDay(t, d, cur.filter((x, j) => j !== i), '¿Quitar ' + this.dayName(d) + ' ' + this.hhmm(r[0]) + '–' + this.hhmm(r[1])
                + ' de la disponibilidad de ' + t.name + '? Si ya dicta clases en esa franja, no se permitirá.');
        },
    },
});
