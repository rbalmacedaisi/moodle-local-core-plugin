// Pestana Configuracion de la linea de tiempo: todo lo que define la planificacion y la generacion de horarios.
// Lee y guarda con los WS local_grupomakro_planning_get_config / save_config (permiso manage_academic_planning).
// Cada guardado muestra antes el resumen de cambios y luego confirma lo que quedo guardado (releido de la base).
Vue.component('planning-config', {
    template: `
    <div class="tl-cfg">
        <div class="tl-cfg-intro">
            <v-icon size="18" color="#4F46E5">mdi-cog-outline</v-icon>
            <span>Aquí se define todo lo que usa el sistema para proyectar asignaturas y generar horarios. Es la misma información
            que usa el Planificador Académico: un cambio aquí se ve allá y viceversa.</span>
        </div>
        <div class="tl-plan-tabs tl-cfg-tabs">
            <button v-for="s in sections" :key="s.key" class="tl-plan-tab" :class="{ 'tl-plan-tab-active': section === s.key }"
                    @click="section = s.key"><v-icon size="14">{{ s.icon }}</v-icon> {{ s.label }}</button>
        </div>

        <div v-if="loading" class="tl-plan-empty" style="padding:24px">Cargando configuración…</div>
        <div v-else-if="error" class="tl-cfg-msg tl-cfg-msg-error">{{ error }}</div>
        <template v-else>
            <transition name="tl-fade">
                <div v-if="msg" class="tl-cfg-msg" :class="msg.ok ? 'tl-cfg-msg-ok' : 'tl-cfg-msg-error'">
                    <v-icon size="16" :color="msg.ok ? '#047857' : '#B91C1C'">{{ msg.ok ? 'mdi-check-circle' : 'mdi-alert-circle' }}</v-icon>
                    {{ msg.text }}
                </div>
            </transition>

            <!-- PERIODOS -->
            <div v-if="section === 'periods'" class="tl-cfg-card">
                <div class="tl-cfg-card-head">
                    <div><b>Periodos académicos</b>
                        <div class="tl-cfg-help">Fechas de inicio y fin de cada periodo y el día en que empiezan las clases de cada jornada
                        (por ejemplo, sabatinos el sábado y diurnos/nocturnos el lunes). La proyección y el avance de nivel usan la jornada que empieza primero.</div>
                    </div>
                    <label class="tl-cfg-check"><input type="checkbox" v-model="showClosed"> Ver periodos cerrados</label>
                </div>
                <table class="tl-cfg-table">
                    <thead><tr><th>Periodo</th><th>Inicio</th><th>Fin</th><th v-for="j in jornadas" :key="j">Clases {{ j }}</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="p in visiblePeriods" :key="p.id" :class="{ 'tl-cfg-row-dirty': periodDirty(p) }">
                            <td><b>{{ p.name }}</b> <span v-if="p.status === 1" class="tl-plan-tag tl-plan-tag-nivel">activo</span>
                                <span v-else class="tl-plan-tag tl-plan-tag-muted">cerrado</span></td>
                            <td><input type="date" class="tl-cfg-input" v-model="p.e.startdate"></td>
                            <td><input type="date" class="tl-cfg-input" v-model="p.e.enddate"></td>
                            <td v-for="j in jornadas" :key="j"><input type="date" class="tl-cfg-input" v-model="p.e.jornadas[j]" :title="'Primer día de clases de la jornada ' + j"></td>
                            <td class="tl-cfg-actions">
                                <button class="tl-cfg-btn" :disabled="!periodDirty(p) || saving" @click="askSavePeriod(p)">Guardar</button>
                                <button v-if="periodDirty(p)" class="tl-cfg-btn tl-cfg-btn-ghost" @click="resetPeriod(p)">Deshacer</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- REGLAS -->
            <div v-if="section === 'rules'" class="tl-cfg-card">
                <div class="tl-cfg-card-head"><div><b>Reglas de agrupación</b>
                    <div class="tl-cfg-help">Con qué tamaño se abre un grupo y cuándo se avisa que un estudiante quedó con muy pocas asignaturas.</div></div></div>
                <div class="tl-cfg-fields">
                    <label>Mínimo de estudiantes para abrir un grupo<input type="number" min="1" class="tl-cfg-input" v-model.number="rules.min_group">
                        <small>Por debajo de este número la asignatura queda en alerta "sin quórum".</small></label>
                    <label>Máximo de estudiantes por grupo<input type="number" min="1" class="tl-cfg-input" v-model.number="rules.max_group">
                        <small>Si la demanda lo supera, se divide en grupos parejos (31 → 16 + 15).</small></label>
                    <label>Carga mínima por estudiante<input type="number" min="1" max="10" class="tl-cfg-input" v-model.number="rules.min_load">
                        <small>Si le tocan menos asignaturas y tiene más pendientes, se genera la alerta "carga baja".</small></label>
                </div>
                <div class="tl-cfg-footer"><button class="tl-cfg-btn" :disabled="!rulesDirty || saving" @click="askSave('rules', rules, rulesDiff())">Guardar reglas</button></div>
            </div>

            <!-- MEZCLA DE CARRERAS -->
            <div v-if="section === 'rules'" class="tl-cfg-card">
                <div class="tl-cfg-card-head"><div><b>Mezcla de carreras</b>
                    <div class="tl-cfg-help">Por defecto, una asignatura que comparten varias carreras se dicta en un mismo grupo para llenarlo mejor.
                    Aquí se define qué no se mezcla. Lo usan la proyección y el tablero de horarios.</div></div></div>
                <div class="tl-cfg-sub">Carreras que no se mezclan con ninguna otra</div>
                <div class="tl-cfg-help mb-2">Todas sus asignaturas van en grupos solo de esa carrera.</div>
                <div class="tl-cfg-chips">
                    <span v-for="c in careers" :key="c.id" class="tl-cfg-chip" :class="{ 'tl-cfg-chip-on': iso.careers.includes(c.id) }"
                          @click="toggleIsoCareer(c.id)">
                        <v-icon size="13" :color="iso.careers.includes(c.id) ? '#B45309' : '#CBD5E1'">{{ iso.careers.includes(c.id) ? 'mdi-lock' : 'mdi-lock-open-variant-outline' }}</v-icon>
                        {{ shortPlan(c.name) }}
                    </span>
                </div>
                <div class="tl-cfg-sub mt-4">Asignaturas puntuales que no se mezclan</div>
                <div class="tl-cfg-help mb-2">Solo esa asignatura de esa carrera va en grupos propios; el resto de sus asignaturas sí se puede mezclar.</div>
                <subject-picker :careers="careers.filter(c => !iso.careers.includes(c.id))" require-career
                    :exclude="iso.subjects.map(x => x.planid + '|' + x.courseid)" @add="isoAdd"></subject-picker>
                <table class="tl-cfg-table" v-if="iso.subjects.length">
                    <thead><tr><th>Carrera</th><th>Asignatura</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="(s, i) in iso.subjects" :key="s.planid + '-' + s.courseid">
                            <td>{{ shortPlan(careerById(s.planid).name) }}</td><td>{{ courseName(s.planid, s.courseid) }}</td>
                            <td class="tl-cfg-actions"><button class="tl-cfg-btn tl-cfg-btn-ghost" @click="iso.subjects.splice(i, 1)">Quitar</button></td>
                        </tr>
                    </tbody>
                </table>
                <div v-else class="tl-plan-empty">Ninguna asignatura puntual aislada.</div>
                <div class="tl-cfg-footer"><button class="tl-cfg-btn" :disabled="!isoDirty || saving" @click="askSave('isolation', iso, isoDiff())">Guardar mezcla de carreras</button></div>
            </div>

            <!-- COSTOS Y DURACION -->
            <div v-if="section === 'rules'" class="tl-cfg-card">
                <div class="tl-cfg-card-head"><div><b>Costos y duración</b>
                    <div class="tl-cfg-help">Se usan para el gasto mensual proyectado. Las asignaturas de cuatrimestre completo duran dos
                    periodos (~16 semanas) y se pagan repartidas en esos meses.</div></div></div>
                <div class="tl-cfg-fields" style="grid-template-columns: 260px">
                    <label>Tarifa por hora docente (USD)<input type="number" min="1" step="0.5" class="tl-cfg-input" v-model.number="costs.hour_rate">
                        <small>La misma tarifa predeterminada del Análisis Financiero Docente.</small></label>
                </div>
                <div class="tl-cfg-sub mt-3">Asignaturas de cuatrimestre completo</div>
                <div class="tl-cfg-help mb-2" v-if="!costs.long_configured">Hoy se toman del historial (dictadas casi siempre en clases de más de 10 semanas). Al guardar, la lista queda fija.</div>
                <div class="tl-cfg-chips mb-2">
                    <span v-for="c in costs.long" :key="c.courseid" class="tl-cfg-chip tl-cfg-chip-on">{{ c.name }}
                        <button class="tl-proj-unx" @click="costs.long = costs.long.filter(x => x.courseid !== c.courseid)">×</button></span>
                    <span v-if="!costs.long.length" class="tl-plan-empty">Ninguna.</span>
                </div>
                <subject-picker :careers="careers" :exclude="costs.long.map(c => c.courseid)" @add="longAdd"></subject-picker>
                <div class="tl-cfg-sub mt-3">Asignaturas con docente de planta (no se costean)</div>
                <div class="tl-cfg-help mb-2">Sus grupos se siguen proyectando y abriendo, pero no suman al gasto mensual, que es solo de costo variable.</div>
                <div class="tl-cfg-chips mb-2">
                    <span v-for="c in costs.fixed" :key="c.courseid" class="tl-cfg-chip tl-cfg-chip-on">{{ c.name }}
                        <button class="tl-proj-unx" @click="costs.fixed = costs.fixed.filter(x => x.courseid !== c.courseid)">×</button></span>
                    <span v-if="!costs.fixed.length" class="tl-plan-empty">Ninguna: todas se costean.</span>
                </div>
                <subject-picker :careers="careers" :exclude="costs.fixed.map(c => c.courseid)" @add="fixedAdd"></subject-picker>
                <div class="tl-cfg-help" v-if="costs.long_suggested && costs.long_suggested.length">Sugeridas por el historial:
                    <span v-for="s in costs.long_suggested" :key="s.courseid">{{ s.name }} ({{ s.largas }} largas / {{ s.cortas }} cortas); </span></div>
                <div class="tl-cfg-footer"><button class="tl-cfg-btn" :disabled="!costsDirty || saving"
                    @click="askSave('costs', { hour_rate: costs.hour_rate, long: costs.long.map(c => c.courseid), fixed: costs.fixed.map(c => c.courseid) }, costsDiff())">Guardar costos y duración</button></div>
            </div>

            <!-- ASIGNATURAS NO OBLIGATORIAS -->
            <div v-if="section === 'rules'" class="tl-cfg-card">
                <div class="tl-cfg-card-head"><div><b>Asignaturas no obligatorias en un periodo concreto</b>
                    <div class="tl-cfg-help">No se cargan por defecto en la proyección: aparecen marcadas y se activan en cada periodo con
                    "Cargar en este periodo". Son automáticamente las de crédito 0; aquí puede agregar otras o excluir alguna de crédito 0.</div></div></div>
                <div class="tl-cfg-sub">Automáticas (crédito 0)</div>
                <div class="tl-cfg-chips mb-3">
                    <span v-for="c in flexAuto" :key="c.courseid" class="tl-cfg-chip">{{ c.name }}</span>
                    <span v-if="!flexAuto.length" class="tl-plan-empty">Ninguna asignatura con crédito 0.</span>
                </div>
                <div class="tl-cfg-sub">Marcar otra asignatura como no obligatoria</div>
                <subject-picker :careers="careers" require-career :notes="autoNotes"
                    :exclude="flex.add.concat(flex.remove)" @add="items => flexAdd('add', items)"></subject-picker>
                <div class="tl-cfg-sub mt-3">Hacer obligatoria en una carrera una de crédito 0</div>
                <div class="tl-cfg-help mb-2">Para una asignatura de crédito 0 que en esa carrera sí debe cargarse siempre.</div>
                <subject-picker :careers="autoCareers" require-career
                    :exclude="flex.add.concat(flex.remove)" @add="items => flexAdd('remove', items)"></subject-picker>
                <table class="tl-cfg-table" v-if="flex.add.length || flex.remove.length">
                    <thead><tr><th>Carrera</th><th>Asignatura</th><th>Ajuste</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="k in flex.add" :key="'a' + k"><td>{{ shortPlan(careerById(+k.split('|')[0]).name) }}</td><td>{{ courseName(+k.split('|')[0], +k.split('|')[1]) }}</td>
                            <td><span class="tl-plan-tag tl-plan-tag-muted">no obligatoria</span></td>
                            <td class="tl-cfg-actions"><button class="tl-cfg-btn tl-cfg-btn-ghost" @click="flex.add = flex.add.filter(x => x !== k)">Quitar</button></td></tr>
                        <tr v-for="k in flex.remove" :key="'r' + k"><td>{{ shortPlan(careerById(+k.split('|')[0]).name) }}</td><td>{{ courseName(+k.split('|')[0], +k.split('|')[1]) }}</td>
                            <td><span class="tl-plan-tag tl-plan-tag-nivel">obligatoria aquí</span></td>
                            <td class="tl-cfg-actions"><button class="tl-cfg-btn tl-cfg-btn-ghost" @click="flex.remove = flex.remove.filter(x => x !== k)">Quitar</button></td></tr>
                    </tbody>
                </table>
                <div class="tl-cfg-footer"><button class="tl-cfg-btn" :disabled="!flexDirty || saving" @click="askSave('flexible', flex, flexDiff())">Guardar no obligatorias</button></div>
            </div>

            <!-- JORNADAS Y HORARIOS -->
            <div v-if="section === 'scheduler'" class="tl-cfg-card">
                <div class="tl-cfg-card-head"><div><b>Jornadas y horarios</b>
                    <div class="tl-cfg-help">Franja horaria de cada jornada, duración de las sesiones, receso entre clases y almuerzo.
                    Se usan al generar los horarios.</div></div></div>
                <div class="tl-cfg-fields">
                    <label v-for="j in jornadas" :key="j">{{ j }}
                        <span class="tl-cfg-range"><input type="time" class="tl-cfg-input" v-model="sched.shiftWindows[j].start"> a
                        <input type="time" class="tl-cfg-input" v-model="sched.shiftWindows[j].end"></span></label>
                    <label>Duración de cada sesión (minutos)<input type="number" min="30" step="5" class="tl-cfg-input" v-model.number="sched.sessionDuration"></label>
                    <label>Receso entre clases (minutos)<input type="number" min="0" step="5" class="tl-cfg-input" v-model.number="sched.intervalMinutes"></label>
                    <label>Almuerzo<span class="tl-cfg-range"><input type="time" class="tl-cfg-input" v-model="sched.lunchStart"> a
                        <input type="time" class="tl-cfg-input" v-model="sched.lunchEnd"></span></label>
                </div>
                <div class="tl-cfg-footer"><button class="tl-cfg-btn" :disabled="!schedDirty || saving" @click="askSave('scheduler', { config: sched }, schedDiff())">Guardar jornadas</button></div>
            </div>

            <!-- AULAS -->
            <div v-if="section === 'classrooms'" class="tl-cfg-card">
                <div class="tl-cfg-card-head"><div><b>Aulas</b> <span class="tl-plan-tab-count">{{ classrooms.filter(c => c.active).length }} activas</span>
                    <div class="tl-cfg-help">Solo las aulas activas se usan al generar horarios; la capacidad limita el tamaño del grupo que puede recibir.
                    Un aula con clases asignadas no se puede borrar: desactívela.</div></div>
                    <button class="tl-cfg-btn" @click="newClassroom">+ Nueva aula</button></div>
                <table class="tl-cfg-table">
                    <thead><tr><th>Nombre</th><th>Capacidad</th><th>Tipo</th><th>Activa</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="c in classrooms" :key="c.id || 'nueva'" :class="{ 'tl-cfg-row-dirty': c.dirty, 'tl-cfg-row-off': !c.active }">
                            <td><input class="tl-cfg-input" v-model="c.name" @input="c.dirty = true"></td>
                            <td><input type="number" min="1" class="tl-cfg-input tl-cfg-input-sm" v-model.number="c.capacity" @input="c.dirty = true"></td>
                            <td><input class="tl-cfg-input" v-model="c.type" @input="c.dirty = true"></td>
                            <td><input type="checkbox" v-model="c.active" :true-value="1" :false-value="0" @change="c.dirty = true"></td>
                            <td class="tl-cfg-actions">
                                <button class="tl-cfg-btn" :disabled="!c.dirty || saving" @click="saveDirect('classroom', c)">Guardar</button>
                                <button v-if="c.id && !c.in_use" class="tl-cfg-btn tl-cfg-btn-danger" :disabled="saving" @click="askDelete('classroom_delete', c, 'el aula ' + c.name)">Borrar</button>
                                <span v-else-if="c.id" class="tl-plan-tag tl-plan-tag-muted" title="Tiene clases u horarios asignados">en uso</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- FESTIVOS -->
            <div v-if="section === 'holidays'" class="tl-cfg-card">
                <div class="tl-cfg-card-head"><div><b>Festivos</b>
                    <div class="tl-cfg-help">Días sin clases. Valen para todos los periodos: cada periodo usa los que caen entre sus fechas.</div></div></div>
                <div class="tl-cfg-inline">
                    <input type="date" class="tl-cfg-input" v-model="newHoliday.date">
                    <input class="tl-cfg-input" style="flex:1" placeholder="Nombre (ej. Día de la Independencia)" v-model="newHoliday.name">
                    <button class="tl-cfg-btn" :disabled="!newHoliday.date || !newHoliday.name || saving" @click="addHoliday">Agregar</button>
                    <label class="tl-cfg-check"><input type="checkbox" v-model="showPastHolidays"> Ver pasados</label>
                </div>
                <table class="tl-cfg-table">
                    <thead><tr><th>Fecha</th><th>Nombre</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="h in visibleHolidays" :key="h.id">
                            <td>{{ fmtLong(h.date) }}</td><td>{{ h.name }}</td>
                            <td class="tl-cfg-actions"><button class="tl-cfg-btn tl-cfg-btn-danger" :disabled="saving" @click="askDelete('holiday_delete', h, 'el festivo ' + h.name)">Borrar</button></td>
                        </tr>
                        <tr v-if="!visibleHolidays.length"><td colspan="3" class="tl-plan-empty">Sin festivos próximos.</td></tr>
                    </tbody>
                </table>
            </div>

            <!-- CARGAS -->
            <div v-if="section === 'loads'" class="tl-cfg-card">
                <div class="tl-cfg-card-head"><div><b>Cargas horarias</b> <span class="tl-plan-tab-count">{{ loads.length }}</span>
                    <div class="tl-cfg-help">Horas totales e intensidad semanal de cada asignatura; definen cuántas sesiones tiene una clase.
                    Se cargan desde Excel en el Planificador Académico (Configuración de Periodos → Cargas).</div></div>
                    <div class="tl-panel-search" style="width:260px"><v-icon size="14" color="#94A3B8">mdi-magnify</v-icon>
                        <input v-model="loadQ" class="tl-panel-search-input" placeholder="Buscar asignatura..."></div></div>
                <table class="tl-cfg-table">
                    <thead><tr><th>Asignatura</th><th>Horas totales</th><th>Intensidad</th></tr></thead>
                    <tbody><tr v-for="l in visibleLoads" :key="l.subject"><td>{{ l.subject }}</td><td>{{ l.total_hours }}</td><td>{{ l.intensity }}</td></tr></tbody>
                </table>
            </div>
        </template>

        <!-- Confirmacion con resumen de cambios -->
        <v-dialog v-model="confirm.show" max-width="520">
            <v-card>
                <v-card-title class="text-subtitle-1 font-weight-bold">{{ confirm.title }}</v-card-title>
                <v-card-text>
                    <div class="mb-2">Se va a guardar lo siguiente:</div>
                    <div v-for="(c, i) in confirm.changes" :key="i" class="tl-cfg-change">
                        <span>{{ c.label }}</span><span class="tl-cfg-before">{{ c.before || '—' }}</span>
                        <v-icon size="14">mdi-arrow-right</v-icon><b>{{ c.after || '—' }}</b>
                    </div>
                    <div v-if="confirm.note" class="tl-cfg-help mt-2">{{ confirm.note }}</div>
                </v-card-text>
                <v-card-actions><v-spacer></v-spacer>
                    <v-btn text @click="confirm.show = false">Cancelar</v-btn>
                    <v-btn :color="confirm.danger ? 'error' : 'primary'" depressed :loading="saving" @click="doConfirm">{{ confirm.danger ? 'Borrar' : 'Guardar' }}</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    </div>
    `,
    data() {
        return {
            loading: true, error: '', saving: false, msg: null, section: 'periods', showClosed: false, showPastHolidays: false, loadQ: '',
            jornadas: ['Sabatina', 'Diurna', 'Nocturna'],
            sections: [
                { key: 'periods', label: 'Periodos', icon: 'mdi-calendar-range' },
                { key: 'rules', label: 'Reglas de agrupación', icon: 'mdi-account-group-outline' },
                { key: 'scheduler', label: 'Jornadas y horarios', icon: 'mdi-clock-outline' },
                { key: 'classrooms', label: 'Aulas', icon: 'mdi-door' },
                { key: 'holidays', label: 'Festivos', icon: 'mdi-calendar-remove-outline' },
                { key: 'loads', label: 'Cargas horarias', icon: 'mdi-timer-sand' },
            ],
            periods: [], rules: {}, rulesOrig: {}, sched: null, schedOrig: '', classrooms: [], holidays: [], loads: [],
            newHoliday: { date: '', name: '' },
            careers: [], iso: { careers: [], subjects: [] }, isoOrig: '', isoPick: { planid: 0, courseid: 0 },
            flexAuto: [], flex: { add: [], remove: [] }, flexOrig: '', flexPick: { planid: 0, courseid: 0 },
            costs: { hour_rate: 18, long: [], fixed: [], long_configured: false, long_suggested: [], all_courses: [] }, costsOrig: '', longPick: 0, fixedPick: 0,
            confirm: { show: false, title: '', changes: [], note: '', danger: false, run: null },
        };
    },
    computed: {
        wsUrl() { return window.location.origin + '/webservice/rest/server.php'; },
        visiblePeriods() { return this.periods.filter(p => this.showClosed || p.status === 1); },
        rulesDirty() { return JSON.stringify(this.rules) !== JSON.stringify(this.rulesOrig); },
        schedDirty() { return this.sched && JSON.stringify(this.sched) !== this.schedOrig; },
        visibleHolidays() {
            const today = Math.floor(Date.now() / 1000) - 86400;
            return this.holidays.filter(h => this.showPastHolidays || h.date >= today);
        },
        isoDirty() { return JSON.stringify(this.isoNorm(this.iso)) !== this.isoOrig; },
        autoNotes() {
            const n = {};
            this.flexAuto.forEach(c => { n[c.courseid] = 'crédito 0 (ya es no obligatoria)'; });
            return n;
        },
        // Carreras con solo sus asignaturas de credito 0 (para "hacerla obligatoria aqui").
        autoCareers() {
            return this.careers.map(c => Object.assign({}, c, { courses: (c.courses || []).filter(x => this.isAuto(x.courseid)) }))
                .filter(c => c.courses.length);
        },
        costsDirty() { return this.costsKey() !== this.costsOrig || !this.costs.long_configured; },
        flexDirty() { return JSON.stringify({ add: this.flex.add.slice().sort(), remove: this.flex.remove.slice().sort() }) !== this.flexOrig; },
        pickCourses() {
            const c = this.careerById(this.isoPick.planid);
            const taken = this.iso.subjects.filter(s => s.planid === this.isoPick.planid).map(s => s.courseid);
            return (c.courses || []).filter(x => !taken.includes(x.courseid));
        },
        visibleLoads() {
            const q = this.loadQ.toLowerCase();
            return this.loads.filter(l => !q || l.subject.toLowerCase().includes(q));
        },
    },
    created() { this.load(); },
    methods: {
        async ws(fn, params) {
            const body = new URLSearchParams(Object.assign({ wstoken: window.userToken, wsfunction: fn, moodlewsrestformat: 'json' }, params || {}));
            const r = await window.axios.post(this.wsUrl, body);
            if (r.data && r.data.exception) throw new Error(r.data.message || r.data.errorcode);
            return r.data;
        },
        async load() {
            this.loading = true; this.error = '';
            try {
                const d = JSON.parse((await this.ws('local_grupomakro_planning_get_config')).data);
                this.periods = d.periods.map(p => Object.assign(p, { e: this.periodEdit(p) }));
                this.rules = Object.assign({}, d.rules); this.rulesOrig = Object.assign({}, d.rules);
                const s = JSON.parse(JSON.stringify(d.scheduler || {}));
                s.shiftWindows = s.shiftWindows || {};
                this.jornadas.forEach(j => { s.shiftWindows[j] = s.shiftWindows[j] || { start: '', end: '' }; });
                this.sched = s; this.schedOrig = JSON.stringify(s);
                this.classrooms = d.classrooms.map(c => Object.assign(c, { dirty: false }));
                this.holidays = d.holidays; this.loads = d.loads;
                this.careers = d.careers || [];
                const iso = d.isolation || { careers: [], subjects: [] };
                this.iso = { careers: (iso.careers || []).map(Number), subjects: (iso.subjects || []).map(s => ({ planid: Number(s.planid), courseid: Number(s.courseid) })) };
                this.isoOrig = JSON.stringify(this.isoNorm(this.iso));
                this.costs = Object.assign({ hour_rate: 18, long: [], fixed: [], long_configured: false, long_suggested: [], all_courses: [] }, d.costs || {});
                this.costsOrig = this.costsKey();
                const fl = d.flexible || { auto: [], add: [], remove: [] };
                this.flexAuto = fl.auto || [];
                this.flex = { add: (fl.add || []).slice(), remove: (fl.remove || []).slice() };
                this.flexOrig = JSON.stringify({ add: this.flex.add.slice().sort(), remove: this.flex.remove.slice().sort() });
            } catch (e) {
                this.error = 'No se pudo cargar la configuración: ' + (e.message || e);
            } finally { this.loading = false; }
        },
        // ---- periodos ----
        toDate(ts) {
            if (!ts) return '';
            const d = new Date(ts * 1000);
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        },
        toTs(s, end) { return s ? Math.floor(new Date(s + (end ? 'T23:59:59' : 'T00:00:00')).getTime() / 1000) : 0; },
        periodEdit(p) {
            const js = {};
            this.jornadas.forEach(j => { js[j] = this.toDate((p.jornada_starts || {})[j]); });
            return { startdate: this.toDate(p.startdate), enddate: this.toDate(p.enddate), jornadas: js };
        },
        periodDirty(p) { return JSON.stringify(p.e) !== JSON.stringify(this.periodEdit(p)); },
        resetPeriod(p) { p.e = this.periodEdit(p); },
        askSavePeriod(p) {
            const o = this.periodEdit(p), changes = [];
            if (o.startdate !== p.e.startdate) changes.push({ label: 'Inicio del periodo', before: o.startdate, after: p.e.startdate });
            if (o.enddate !== p.e.enddate) changes.push({ label: 'Fin del periodo', before: o.enddate, after: p.e.enddate });
            this.jornadas.forEach(j => { if (o.jornadas[j] !== p.e.jornadas[j]) changes.push({ label: 'Clases ' + j, before: o.jornadas[j], after: p.e.jornadas[j] }); });
            const js = {};
            this.jornadas.forEach(j => { if (p.e.jornadas[j]) js[j] = this.toTs(p.e.jornadas[j]); });
            this.openConfirm('Guardar fechas de ' + p.name, changes, 'period',
                { id: p.id, startdate: this.toTs(p.e.startdate), enddate: this.toTs(p.e.enddate, true), jornada_starts: js },
                'El bloque de clases del calendario académico arranca con la jornada que empieza primero. Esto también lo usa el avance automático de nivel.');
        },
        // ---- reglas / jornadas ----
        rulesDiff() {
            const L = { min_group: 'Mínimo por grupo', max_group: 'Máximo por grupo', min_load: 'Carga mínima' };
            return Object.keys(L).filter(k => this.rules[k] !== this.rulesOrig[k]).map(k => ({ label: L[k], before: this.rulesOrig[k], after: this.rules[k] }));
        },
        schedDiff() {
            const o = JSON.parse(this.schedOrig), s = this.sched, out = [];
            this.jornadas.forEach(j => {
                const a = (o.shiftWindows[j] || {}), b = s.shiftWindows[j];
                if (a.start !== b.start || a.end !== b.end) out.push({ label: j, before: (a.start || '') + '–' + (a.end || ''), after: b.start + '–' + b.end });
            });
            [['sessionDuration', 'Duración de sesión'], ['intervalMinutes', 'Receso'], ['lunchStart', 'Inicio almuerzo'], ['lunchEnd', 'Fin almuerzo']]
                .forEach(([k, l]) => { if (o[k] !== s[k]) out.push({ label: l, before: o[k], after: s[k] }); });
            return out;
        },
        // ---- costos y duracion ----
        costsKey() {
            return JSON.stringify({ r: Number(this.costs.hour_rate), l: this.costs.long.map(c => c.courseid).sort((a, b) => a - b),
                f: (this.costs.fixed || []).map(c => c.courseid).sort((a, b) => a - b) });
        },
        addFixed() {
            const c = (this.costs.all_courses || []).find(x => x.courseid === this.fixedPick);
            if (c) this.costs.fixed.push(c);
            this.fixedPick = 0;
        },
        isoAdd(items) { items.forEach(it => this.iso.subjects.push({ planid: it.planid, courseid: it.courseid })); },
        longAdd(items) { items.forEach(it => this.costs.long.push({ courseid: it.courseid, name: it.name })); },
        fixedAdd(items) { items.forEach(it => this.costs.fixed.push({ courseid: it.courseid, name: it.name })); },
        flexAdd(kind, items) {
            items.forEach(it => {
                const k = it.planid + '|' + it.courseid;
                this.flex.add = this.flex.add.filter(x => x !== k);
                this.flex.remove = this.flex.remove.filter(x => x !== k);
                this.flex[kind].push(k);
            });
        },
        addLong() {
            const c = (this.costs.all_courses || []).find(x => x.courseid === this.longPick);
            if (c) this.costs.long.push(c);
            this.longPick = 0;
        },
        costsDiff() {
            const o = JSON.parse(this.costsOrig), n = JSON.parse(this.costsKey()), out = [];
            const name = id => ((this.costs.all_courses || []).find(x => x.courseid === id) || { name: 'Curso ' + id }).name;
            if (o.r !== n.r) out.push({ label: 'Tarifa por hora', before: '$' + o.r, after: '$' + n.r });
            n.l.filter(x => !o.l.includes(x)).forEach(x => out.push({ label: 'Cuatrimestre completo', before: '', after: name(x) }));
            o.l.filter(x => !n.l.includes(x)).forEach(x => out.push({ label: 'Deja de ser cuatrimestral', before: name(x), after: '' }));
            n.f.filter(x => !(o.f || []).includes(x)).forEach(x => out.push({ label: 'Docente de planta (no se costea)', before: '', after: name(x) }));
            (o.f || []).filter(x => !n.f.includes(x)).forEach(x => out.push({ label: 'Vuelve a costearse', before: name(x), after: '' }));
            if (!out.length && !this.costs.long_configured) out.push({ label: 'Fijar la lista sugerida del historial', before: 'automática', after: n.l.length + ' asignaturas' });
            return out;
        },
        // ---- no obligatorias ----
        isAuto(cid) { return this.flexAuto.some(c => c.courseid === cid); },
        flexMark(kind) {
            const k = this.flexPick.planid + '|' + this.flexPick.courseid;
            this.flex.add = this.flex.add.filter(x => x !== k);
            this.flex.remove = this.flex.remove.filter(x => x !== k);
            this.flex[kind].push(k);
            this.flexPick.courseid = 0;
        },
        flexDiff() {
            const o = JSON.parse(this.flexOrig), out = [];
            const lbl = k => { const [p, c] = k.split('|').map(Number); return this.courseName(p, c) + ' (' + this.shortPlan(this.careerById(p).name) + ')'; };
            this.flex.add.filter(k => !o.add.includes(k)).forEach(k => out.push({ label: 'No obligatoria', before: '', after: lbl(k) }));
            o.add.filter(k => !this.flex.add.includes(k)).forEach(k => out.push({ label: 'Vuelve a obligatoria', before: lbl(k), after: '' }));
            this.flex.remove.filter(k => !o.remove.includes(k)).forEach(k => out.push({ label: 'Obligatoria aquí (crédito 0)', before: '', after: lbl(k) }));
            o.remove.filter(k => !this.flex.remove.includes(k)).forEach(k => out.push({ label: 'Vuelve a no obligatoria', before: lbl(k), after: '' }));
            return out;
        },
        // ---- mezcla de carreras ----
        isoNorm(i) {
            return { careers: i.careers.slice().sort((a, b) => a - b),
                subjects: i.subjects.map(s => s.planid + '|' + s.courseid).sort() };
        },
        careerById(id) { return this.careers.find(c => c.id === id) || { name: 'Plan ' + id, courses: [] }; },
        courseName(pid, cid) { const x = (this.careerById(pid).courses || []).find(c => c.courseid === cid); return x ? x.name : 'Curso ' + cid; },
        shortPlan(name) {
            let n = String(name || '').replace(/^T[ÉE]CNICO SUPERIOR EN\s+/i, '').replace(/^CURSO DE\s+/i, '');
            return n.charAt(0) + n.slice(1).toLowerCase();
        },
        toggleIsoCareer(id) {
            const i = this.iso.careers.indexOf(id);
            if (i === -1) { this.iso.careers.push(id); this.iso.subjects = this.iso.subjects.filter(s => s.planid !== id); }
            else this.iso.careers.splice(i, 1);
        },
        addIsoSubject() {
            this.iso.subjects.push({ planid: this.isoPick.planid, courseid: this.isoPick.courseid });
            this.isoPick.courseid = 0;
        },
        isoDiff() {
            const o = JSON.parse(this.isoOrig), n = this.isoNorm(this.iso), out = [];
            n.careers.filter(c => !o.careers.includes(c)).forEach(c => out.push({ label: 'No se mezcla', before: 'se mezcla', after: this.shortPlan(this.careerById(c).name) }));
            o.careers.filter(c => !n.careers.includes(c)).forEach(c => out.push({ label: 'Vuelve a mezclarse', before: this.shortPlan(this.careerById(c).name), after: 'se mezcla' }));
            const lbl = k => { const [p, c] = k.split('|').map(Number); return this.courseName(p, c) + ' (' + this.shortPlan(this.careerById(p).name) + ')'; };
            n.subjects.filter(k => !o.subjects.includes(k)).forEach(k => out.push({ label: 'Asignatura aislada', before: '', after: lbl(k) }));
            o.subjects.filter(k => !n.subjects.includes(k)).forEach(k => out.push({ label: 'Deja de estar aislada', before: lbl(k), after: '' }));
            return out;
        },
        askSave(section, data, changes) { this.openConfirm('Confirmar cambios', changes, section, data, ''); },
        // ---- aulas / festivos ----
        newClassroom() { this.classrooms.unshift({ id: 0, name: '', capacity: 30, type: 'general', active: 1, in_use: false, dirty: true }); },
        async saveDirect(section, row) {
            await this.send(section, { id: row.id, name: row.name, capacity: row.capacity, type: row.type, active: row.active });
        },
        async addHoliday() {
            await this.send('holiday', { date: this.toTs(this.newHoliday.date), name: this.newHoliday.name });
            this.newHoliday = { date: '', name: '' };
        },
        askDelete(section, row, label) {
            this.confirm = { show: true, title: '¿Borrar ' + label + '?', changes: [], danger: true,
                note: 'Se guarda un respaldo antes de borrar.', run: () => this.send(section, { id: row.id }) };
        },
        openConfirm(title, changes, section, data, note) {
            if (!changes.length) { this.flash(true, 'No hay cambios para guardar.'); return; }
            this.confirm = { show: true, title, changes, note, danger: false, run: () => this.send(section, data) };
        },
        async doConfirm() { await this.confirm.run(); this.confirm.show = false; },
        async send(section, data) {
            this.saving = true;
            try {
                const r = await this.ws('local_grupomakro_planning_save_config', { section, data: JSON.stringify(data) });
                this.flash(r.ok, r.ok ? r.message + ' Verificado en la base.' : r.message);
                if (r.ok) { await this.load(); this.$emit('saved', section); }
            } catch (e) {
                this.flash(false, 'Error al guardar: ' + (e.message || e));
            } finally { this.saving = false; }
        },
        flash(ok, text) { this.msg = { ok, text }; clearTimeout(this._t); this._t = setTimeout(() => { this.msg = null; }, 6000); },
        fmtLong(ts) { return new Date(ts * 1000).toLocaleDateString('es-PA', { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' }); },
    },
});
