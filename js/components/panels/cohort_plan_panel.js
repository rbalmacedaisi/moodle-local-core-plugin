// Panel lateral de planificacion de una cohorte (mismo estilo que el Tablero de Asignaturas).
// Recibe el plan ya calculado por intake-timeline (WS local_grupomakro_planning_get_demand). Solo lectura.
Vue.component('cohort-plan-panel', {
    template: `
    <transition name="tl-slide">
    <aside v-if="visible && plan" class="tl-panel tl-plan-panel">
        <header class="tl-panel-head">
            <div class="tl-panel-head-top">
                <div>
                    <h3 class="tl-panel-title">
                        <v-icon size="18" color="white">mdi-calendar-account</v-icon>
                        Planificación {{ plan.period.name }}
                    </h3>
                    <div class="tl-panel-sub">
                        <template v-if="cohort">Cohorte <strong>{{ cohort }}</strong></template>
                        <template v-else><strong>Sin periodo de ingreso</strong></template>
                        • desde {{ fmt(plan.period.start) }} • {{ plan.students.length }} estudiantes • nivel {{ plan.level }}
                    </div>
                </div>
                <div class="d-flex" style="gap:6px">
                    <button class="tl-panel-close" @click="showHelp = !showHelp" title="¿Cómo se calcula?">
                        <v-icon size="16" color="white">mdi-help</v-icon>
                    </button>
                    <button class="tl-panel-close" @click="$emit('close')" title="Cerrar">
                        <v-icon size="18" color="white">mdi-close</v-icon>
                    </button>
                </div>
            </div>
            <div class="tl-panel-kpis">
                <div class="tl-panel-kpi" title="Estudiantes activos de la cohorte">
                    <div class="tl-panel-kpi-num">{{ plan.students.length }}</div>
                    <div class="tl-panel-kpi-lbl">Est.</div>
                </div>
                <div class="tl-panel-kpi tl-panel-kpi-error" title="Repitentes: con asignaturas reprobadas por repetir">
                    <v-icon size="12" color="#FECACA">mdi-refresh</v-icon>
                    <div class="tl-panel-kpi-num">{{ plan.counts.repitentes }}</div>
                </div>
                <div class="tl-panel-kpi tl-panel-kpi-warning" title="Carga baja: menos de 3 asignaturas teniendo más pendientes">
                    <v-icon size="12" color="#FDE68A">mdi-scale-unbalanced</v-icon>
                    <div class="tl-panel-kpi-num">{{ plan.counts.carga_baja }}</div>
                </div>
                <div class="tl-panel-kpi tl-panel-kpi-info" title="Con prelación pendiente">
                    <v-icon size="12" color="#BFDBFE">mdi-lock-outline</v-icon>
                    <div class="tl-panel-kpi-num">{{ plan.counts.bloqueados }}</div>
                </div>
                <div class="tl-panel-kpi tl-panel-kpi-success" title="Estarán en el último bimestre de su plan">
                    <v-icon size="12" color="#A7F3D0">mdi-school-outline</v-icon>
                    <div class="tl-panel-kpi-num">{{ plan.counts.egresan }}</div>
                </div>
            </div>
        </header>

        <div v-if="showHelp" class="tl-plan-help">
            <p><b>Qué muestra:</b> las asignaturas que le tocan a cada estudiante en el periodo {{ plan.period.name }}
            (un bimestre de clases), según su plan, lo que ya aprobó, lo que reprobó y las prelaciones.</p>
            <p><b>Por su nivel</b> = las de su bimestre · <b>Atrasada</b> = de antes y nunca la cursó ·
            <b>Repite</b> = reprobada · <b>Adelanto</b> = de los próximos bimestres con prelación cumplida (sirve para completar carga o grupos).
            Las atrasadas y reprobadas se ven junto con otra cohorte que esté en ese nivel.</p>
            <p>El nivel se calcula con la misma regla del avance automático de cuatrimestre y bimestre. Esta vista <b>solo lee</b>: no cambia nada.</p>
        </div>

        <div class="tl-panel-toolbar">
            <div class="tl-plan-tabs">
                <button v-for="t in tabs" :key="t.key" class="tl-plan-tab" :class="{ 'tl-plan-tab-active': tab === t.key }"
                        @click="tab = t.key" :title="t.help">
                    {{ t.label }}<span v-if="t.count != null" class="tl-plan-tab-count">{{ t.count }}</span>
                </button>
            </div>
            <div class="tl-panel-search">
                <v-icon size="14" color="#94A3B8">mdi-magnify</v-icon>
                <input v-model="q" type="text" :placeholder="tab === 'subjects' ? 'Buscar asignatura...' : 'Buscar estudiante o cédula...'" class="tl-panel-search-input"/>
                <button v-if="q" class="tl-panel-search-clear" @click="q = ''"><v-icon size="12" color="#94A3B8">mdi-close</v-icon></button>
            </div>
        </div>

        <div class="tl-panel-content">
            <!-- ASIGNATURAS -->
            <template v-if="tab === 'subjects'">
                <div v-for="g in groupDefs" :key="g.key" v-if="filtered(g.key).length" class="mb-2">
                    <div class="tl-plan-group-head"><span>{{ g.title }}</span><span class="tl-plan-block-date">{{ g.help }}</span></div>
                    <div v-for="s in filtered(g.key)" :key="g.key + s.courseid" class="tl-subj tl-plan-subj"
                         :class="{ 'tl-plan-subj-zero': !s.ready }" @click="toggle(g.key + s.courseid)">
                        <div class="tl-subj-sem" :class="'tl-sem-' + semaphore(s)"></div>
                        <div class="tl-subj-body">
                            <div class="tl-subj-name-row">
                                <span class="tl-subj-name" :title="s.name">{{ s.name }}</span>
                            </div>
                            <div class="tl-subj-meta">
                                <span class="tl-subj-meta-item">{{ s.level }}</span>
                                <span v-if="s.cats.nivel" class="tl-subj-meta-item">{{ s.cats.nivel }} nivel</span>
                                <span v-if="s.cats.atrasada" class="tl-subj-meta-item" style="color:#C2410C">{{ s.cats.atrasada }} atrasada</span>
                                <span v-if="s.cats.repite" class="tl-subj-meta-item" style="color:#DC2626">{{ s.cats.repite }} repite</span>
                                <span v-if="s.cats.adelanto" class="tl-subj-meta-item" style="color:#0D9488">{{ s.cats.adelanto }} pueden adelantar</span>
                                <span v-if="s.blocked" class="tl-subj-meta-item" style="color:#B45309"><v-icon size="10" color="#B45309">mdi-lock-outline</v-icon>{{ s.blocked }} sin prelación</span>
                            </div>
                            <div v-if="open[g.key + s.courseid]" class="tl-plan-names">
                                <div v-for="x in s.students" :key="x.s.lluid" class="tl-plan-name-row">
                                    <span>{{ x.s.name }}</span>
                                    <span class="tl-plan-tag" :class="'tl-plan-tag-' + x.cat">{{ catLabel[x.cat] }}</span>
                                    <span v-if="x.prereq === 'no'" class="tl-plan-tag tl-plan-tag-lock" :title="'Falta aprobar: ' + x.missing.join(', ')">prelación</span>
                                </div>
                            </div>
                        </div>
                        <div class="tl-subj-stats" :class="'tl-stats-' + semaphore(s)" :title="statsHelp(s)">
                            <v-icon size="11" :color="semColor(s)">mdi-account-multiple</v-icon>
                            <span class="tl-subj-stats-num">{{ g.key === 'adelanto' ? s.cats.adelanto : s.ready }}</span>
                        </div>
                    </div>
                </div>
                <div v-if="!groupDefs.some(g => filtered(g.key).length)" class="tl-panel-empty">
                    <v-icon size="40" color="#CBD5E1">mdi-book-off-outline</v-icon><p>Sin asignaturas</p>
                </div>
                <div class="tl-plan-legend">
                    <span><span class="tl-filter-dot tl-dot-green"></span> 12 o más (abre grupo)</span>
                    <span><span class="tl-filter-dot tl-dot-orange"></span> 6–11 (se junta con otras cohortes)</span>
                    <span><span class="tl-filter-dot tl-dot-red"></span> menos de 6</span>
                </div>
            </template>

            <!-- ESTUDIANTES / REPITENTES / ALERTAS -->
            <template v-else>
                <div v-if="!filteredStudents.length" class="tl-panel-empty">
                    <v-icon size="40" color="#CBD5E1">mdi-account-search-outline</v-icon><p>Sin estudiantes</p>
                </div>
                <div v-for="s in filteredStudents" :key="s.lluid" class="tl-plan-stu" @click="toggle('u' + s.lluid)">
                    <div class="tl-plan-stu-top">
                        <div style="min-width:0">
                            <div class="tl-plan-stu-name">{{ s.name }}</div>
                            <div class="tl-subj-meta">
                                <span>{{ s.documento }}</span><span>{{ s.jornada || 'Sin jornada' }}</span>
                                <span>nivel {{ s.level }}</span><span v-if="s.registered !== s.level">(hoy {{ s.registered }})</span>
                            </div>
                        </div>
                        <div class="tl-plan-load" :title="'Asignaturas que le tocan (sin adelantos) / carga normal de su bimestre en el plan'">
                            <span :class="{ 'tl-plan-low': s.flags.includes('carga_baja') }">{{ s.load }}</span>/{{ s.normal_load }}
                        </div>
                    </div>
                    <div class="tl-plan-flags">
                        <span v-if="s.repite.length" class="tl-plan-tag tl-plan-tag-repite">repite {{ s.repite.length }}</span>
                        <span v-if="s.blocked.length" class="tl-plan-tag tl-plan-tag-lock">prelación pendiente</span>
                        <span v-if="s.last" class="tl-plan-tag tl-plan-tag-nivel">último bimestre</span>
                        <span v-for="f in s.flags" :key="f" v-if="flagLabel[f]" class="tl-plan-tag" :class="'tl-plan-flag-' + f" :title="flagHelp[f]">{{ flagLabel[f] }}</span>
                    </div>
                    <div v-if="open['u' + s.lluid]" class="tl-plan-detail" @click.stop>
                        <div class="tl-plan-contact">
                            <a :href="'mailto:' + s.email"><v-icon size="12" color="#6366F1">mdi-email-outline</v-icon> {{ s.email }}</a>
                            <a v-if="s.phone" :href="wa(s.phone)" target="_blank"><v-icon size="12" color="#10B981">mdi-whatsapp</v-icon> {{ s.phone }}</a>
                        </div>
                        <div v-for="x in s.subjects" :key="x.courseid" class="tl-plan-name-row">
                            <span :title="x.name">{{ x.name }}</span>
                            <span class="tl-subj-meta-item" style="font-size:10px;color:#94A3B8">{{ x.level }}</span>
                            <span class="tl-plan-tag" :class="'tl-plan-tag-' + x.category">{{ catLabel[x.category] }}</span>
                            <span v-if="x.prereq === 'no'" class="tl-plan-tag tl-plan-tag-lock" :title="'Falta aprobar: ' + x.missing.join(', ')">prelación</span>
                            <span v-else-if="x.prereq === 'condicionada'" class="tl-plan-tag tl-plan-tag-muted" :title="'Sujeta a aprobar: ' + x.conditional.join(', ')">condicionada</span>
                        </div>
                        <div v-if="!s.subjects.length" class="tl-plan-empty">Nada pendiente.</div>
                        <div v-if="s.other.length" class="tl-plan-empty">En curso / reválida: {{ s.other.map(o => o.name).join(', ') }}</div>
                    </div>
                </div>
            </template>
        </div>
    </aside>
    </transition>
    `,
    props: {
        visible: { type: Boolean, default: false },
        cohort: { type: String, default: '' },
        plan: { type: Object, default: null },
        initialTab: { type: String, default: 'subjects' },
    },
    data() {
        return {
            tab: this.initialTab, q: '', open: {}, showHelp: false,
            groupDefs: [
                { key: 'nivel', title: 'Por su nivel', help: 'las de su bimestre' },
                { key: 'pend', title: 'Atrasadas y para repetir', help: 'con otra cohorte de ese nivel' },
                { key: 'adelanto', title: 'Pueden adelantar', help: 'para completar carga o grupos' },
                { key: 'bloqueadas', title: 'Con prelación pendiente', help: 'no se pueden cargar aún' },
            ],
            catLabel: { nivel: 'nivel', atrasada: 'atrasada', repite: 'repite', adelanto: 'adelanto' },
            flagLabel: {
                carga_baja: 'carga baja', sin_ingreso: 'sin ingreso', sin_jornada: 'sin jornada', suspendido: 'suspendido',
                atrasado_vs_cohorte: 'atrás de su cohorte', adelantado_vs_cohorte: 'adelante de su cohorte',
            },
            flagHelp: {
                carga_baja: 'Le tocan menos de 3 asignaturas teniendo más pendientes.',
                sin_ingreso: 'Sin periodo de ingreso válido: nivel estimado como el registrado + 1.',
                sin_jornada: 'Sin jornada en el perfil.', suspendido: 'Cuenta de Moodle suspendida.',
                atrasado_vs_cohorte: 'En un nivel menor que la mayoría de su cohorte.',
                adelantado_vs_cohorte: 'En un nivel mayor que la mayoría de su cohorte.',
            },
        };
    },
    watch: {
        initialTab(v) { this.tab = v; },
        visible(v) { if (v) { this.tab = this.initialTab; this.q = ''; this.open = {}; } },
    },
    computed: {
        tabs() {
            const c = this.plan ? this.plan.counts : {};
            return [
                { key: 'subjects', label: 'Asignaturas', count: null, help: 'Asignaturas que verá la cohorte en el periodo' },
                { key: 'students', label: 'Estudiantes', count: this.plan ? this.plan.students.length : 0, help: 'Carga, avisos y contacto por estudiante' },
                { key: 'repite', label: 'Repitentes', count: c.repitentes, help: 'Con asignaturas reprobadas por repetir' },
                { key: 'alerts', label: 'Alertas', count: c.alertas, help: 'Carga baja, prelación pendiente, datos incompletos' },
            ];
        },
        filteredStudents() {
            if (!this.plan) return [];
            const q = this.q.toLowerCase();
            return this.plan.students.filter(s => {
                if (this.tab === 'repite' && !s.repite.length) return false;
                if (this.tab === 'alerts' && !this.hasAlert(s)) return false;
                return !q || (s.name + ' ' + s.documento).toLowerCase().includes(q);
            });
        },
    },
    methods: {
        filtered(key) {
            const q = this.q.toLowerCase();
            return ((this.plan.groups || {})[key] || []).filter(s => !q || s.name.toLowerCase().includes(q));
        },
        hasAlert(s) {
            return s.blocked.length || s.flags.some(f => ['carga_baja', 'sin_ingreso', 'sin_jornada', 'suspendido'].includes(f));
        },
        semaphore(s) { return s.ready >= 12 ? 'green' : (s.ready >= 6 ? 'orange' : 'red'); },
        semColor(s) { return { green: '#10B981', orange: '#F59E0B', red: '#EF4444' }[this.semaphore(s)]; },
        statsHelp(s) {
            return s.ready + ' estudiantes de esta cohorte la deben ver (sin contar adelantos ni prelaciones pendientes). '
                + 'El grupo final se arma junto con otras cohortes y carreras que vean la misma asignatura.';
        },
        toggle(k) { this.$set(this.open, k, !this.open[k]); },
        fmt(ts) {
            return ts ? new Date(ts * 1000).toLocaleDateString('es-PA', { day: '2-digit', month: 'short' }) : '-';
        },
        wa(p) {
            let n = String(p || '').replace(/\D/g, '');
            if (n.length === 8) n = '507' + n;
            return 'https://wa.me/' + n;
        },
    },
});
