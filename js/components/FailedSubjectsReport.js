/**
 * Failed Subjects Report — root Vue component.
 *
 * Loaded by pages/failed_subjects_report.php. Defines the
 * <failed-subjects-report> component which:
 *   - Lets the admin pick an academic period and filter by jornada /
 *     career / class availability / quota.
 *   - Shows a sortable table of (student, course) pairs with a
 *     semáforo (green / red / amber) indicating whether the course
 *     is projected to open in the chosen period AND has free quota
 *     in the student's jornada.
 *   - Lets the admin click the student name to open a detail drawer
 *     with full contact info and historical failed subjects.
 *   - Lets the admin enrol the student directly. If the class is
 *     full the action requires an explicit confirmation
 *     (force-over-quota, logged in gmk_class_absence_history).
 */
Vue.component('failed-subjects-report', {
    props: {},
    data() {
        // Pull config (sesskey, ajaxUrl, wwwRoot) from the global
        // window.fsConfig namespace populated by the page. This avoids
        // passing complex values through Vue attribute binding which
        // would otherwise try to parse unquoted strings like
        // :ajax-url="https://..." as JS expressions and fail.
        var cfg = (typeof window !== 'undefined' && window.fsConfig) || {};
        return {
            loading: false,
            error: null,

            sesskey: cfg.sesskey || '',
            ajaxUrl: cfg.ajaxUrl || '',
            wwwRoot: cfg.wwwRoot || '',

            periods: [],
            plans: [],
            rows: [],
            total: 0,
            summary: { students: 0, failed_total: 0, with_class: 0, with_quota: 0, full_classes: 0, periodid: 0 },

            page: 0,
            perpage: 50,
            perpageOptions: [25, 50, 100, 200],
            tableOptions: { page: 1, itemsPerPage: 50, sortBy: ['lastname'], sortDesc: [false] },

            filters: {
                periodid: 0,
                search: '',
                learningplanid: 0,
                jornada: '',
                hasclass: 'all',
                hasquota: 'all',
                financial_status: ''
            },
            jornadaOptions: ['Diurno', 'Nocturno', 'Sabatino'],
            financialOptions: ['al_dia', 'mora', 'becado', 'periodo_gracia'],

            drawerOpen: false,
            drawerUserid: 0,

            fetchTimer: null
        };
    },
    computed: {
        emptyMessage() {
            return this.filters.periodid > 0
                ? 'No hay datos para los filtros actuales.'
                : 'Seleccione un período académico para ver los grupos proyectados.';
        }
    },
    mounted() {
        this.fetchPeriods();
    },
    methods: {
        // -- Data fetching ------------------------------------------------

        async fetchPeriods() {
            try {
                const r = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_get_failed_subjects_periods',
                    args: {},
                    sesskey: this.sesskey
                });
                if (r.data && r.data.status === 'success') {
                    this.periods = r.data.data || [];
                }
            } catch (e) {
                console.error('[FSR] fetchPeriods', e);
            }
            this.fetchReport();
        },

        async fetchReport() {
            this.loading = true;
            this.error = null;
            try {
                const r = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_get_failed_subjects_report',
                    args: {
                        periodid:         Number(this.filters.periodid) || 0,
                        search:           this.filters.search || '',
                        learningplanid:   Number(this.filters.learningplanid) || 0,
                        jornada:          this.filters.jornada || '',
                        hasclass:         this.filters.hasclass || 'all',
                        hasquota:         this.filters.hasquota || 'all',
                        financial_status: this.filters.financial_status || '',
                        page:             Number(this.page) || 0,
                        perpage:          Number(this.perpage) || 50
                    },
                    sesskey: this.sesskey
                });
                if (r.data && r.data.status === 'success') {
                    const d = r.data.data || {};
                    this.rows    = d.rows || [];
                    this.total   = d.total || 0;
                    this.summary = d.summary || this.summary;
                    this.plans   = d.learningplans || this.plans;
                } else {
                    throw new Error((r.data && r.data.message) || 'Error al cargar el reporte');
                }
            } catch (e) {
                console.error('[FSR] fetchReport', e);
                this.error = e.message || 'No se pudo cargar el reporte.';
                this.rows = [];
            } finally {
                this.loading = false;
            }
        },

        debouncedFetch() {
            clearTimeout(this.fetchTimer);
            this.fetchTimer = setTimeout(() => { this.page = 0; this.fetchReport(); }, 300);
        },

        onPeriodChange() {
            this.page = 0;
            this.fetchReport();
        },

        onFilterChange() {
            this.page = 0;
            this.fetchReport();
        },

        onTableOptions(opt) {
            this.page    = (opt.page || 1) - 1;
            this.perpage = opt.itemsPerPage || 50;
            this.fetchReport();
        },

        async clearCache() {
            try {
                await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_clear_failed_subjects_cache',
                    args: {},
                    sesskey: this.sesskey
                });
            } catch (e) { /* non-fatal */ }
            this.page = 0;
            this.fetchReport();
        },

        // -- UI actions ---------------------------------------------------

        onStudentClick(row) {
            this.drawerUserid = row.userid;
            this.drawerOpen   = true;
        },

        onDrawerClose() {
            this.drawerOpen = false;
        },

        async enrolStudent(row, force) {
            if (!row.classid) {
                return;
            }
            const doCall = async (forceOver) => {
                try {
                    const r = await axios.post(window.wsUrl, {
                        action: 'local_grupomakro_enrol_student_from_failed_subjects',
                        args: { userid: row.userid, classid: row.classid, force_over: !!forceOver },
                        sesskey: this.sesskey
                    });
                    if (r.data && r.data.status === 'success') {
                        return r.data.data;
                    }
                    throw new Error((r.data && r.data.message) || 'Error');
                } catch (e) {
                    return { status: 'error', message: e.message };
                }
            };

            if (row.is_full && !force) {
                const ok = await Swal.fire({
                    title: 'Forzar matrícula?',
                    text: 'El grupo está en cupo lleno (' + row.enrolled_count + '/' + row.classroomcapacity + '). El estudiante será matriculado de todos modos y la acción se registrará en la bitácora.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Sí, forzar',
                    cancelButtonText: 'Cancelar'
                });
                if (!ok || !ok.value) return;
                force = true;
            }

            const result = await doCall(force);
            if (result.status === 'ok') {
                Swal.fire('Éxito', 'Estudiante matriculado correctamente.', 'success');
                // Update local row to reflect new count.
                row.enrolled_count = (result.enrolled_count || row.enrolled_count + 1);
                row.is_full = row.classroomcapacity > 0 && row.enrolled_count >= row.classroomcapacity;
                this.fetchReport();  // refresh to update summary
            } else if (result.status === 'already_enrolled') {
                Swal.fire('Aviso', result.message || 'El estudiante ya estaba matriculado.', 'info');
            } else if (result.status === 'quota_exceeded') {
                Swal.fire('Cupo lleno', result.message || 'El grupo está lleno.', 'warning');
            } else {
                Swal.fire('Error', result.message || 'No se pudo matricular al estudiante.', 'error');
            }
        },

        // -- Display helpers ---------------------------------------------

        jornadaPillClass(j) {
            if (j === 'Diurno')   return 'fsr-pill-blue';
            if (j === 'Nocturno') return 'fsr-pill-purple';
            if (j === 'Sabatino') return 'fsr-pill-amber';
            return 'fsr-pill-grey';
        },
        financialPillClass(c) {
            const k = (c || '').toLowerCase();
            if (k === 'al_dia') return 'fsr-pill-green';
            if (k === 'mora')   return 'fsr-pill-red';
            if (k === 'becado') return 'fsr-pill-blue';
            if (k === 'periodo_gracia') return 'fsr-pill-amber';
            return 'fsr-pill-grey';
        },
        fmtDate(ts) {
            if (!ts) return '—';
            const d = new Date(ts * 1000);
            return d.toLocaleDateString();
        },
        fmtGrade(g) {
            if (g === null || g === undefined) return '—';
            const n = Number(g);
            return n > 0 ? n.toFixed(1) : '—';
        },
        formatQuota(row) {
            if (!row.classid) return '—';
            return row.enrolled_count + ' / ' + (row.classroomcapacity || 0);
        },
        semaforoClass(row) {
            if (!row.classid) return 'fsr-sem-na';
            if (row.is_full)   return 'fsr-sem-full';
            return 'fsr-sem-ok';
        },
        semaforoText(row) {
            if (!row.classid) return 'SIN GRUPO';
            if (row.is_full)   return 'LLENO';
            return 'OK';
        },
        semaforoIcon(row) {
            if (!row.classid) return 'mdi-minus-circle-outline';
            if (row.is_full)   return 'mdi-close-circle';
            return 'mdi-check-circle';
        }
    },
    template: `
        <v-container fluid style="max-width:100% !important;">

            <!-- Header -->
            <v-row class="mx-0 mb-3">
                <v-col cols="12" class="py-0 px-0 d-flex align-center">
                    <div>
                        <h2 class="mb-0">
                            <v-icon left color="red darken-2">mdi-clipboard-alert-outline</v-icon>
                            Asignaturas Perdidas vs Grupos Proyectados
                        </h2>
                        <div class="text-caption grey--text">
                            Estudiantes con asignaturas reprobadas (status 5/7) y los grupos que se proyecta abrir en el período seleccionado. Match por jornada y cupo.
                        </div>
                    </div>
                    <v-spacer></v-spacer>
                    <v-btn color="primary" class="rounded-lg mr-2" elevation="0" @click="clearCache" :loading="loading">
                        <v-icon left>mdi-refresh</v-icon>
                        Refrescar
                    </v-btn>
                </v-col>
            </v-row>

            <!-- Filters -->
            <v-card outlined class="pa-3 rounded-lg mb-3">
                <v-row dense>
                    <v-col cols="12" md="3">
                        <v-select
                            v-model="filters.periodid"
                            :items="periods"
                            item-text="name" item-value="id"
                            label="Período académico"
                            dense outlined
                            @change="onPeriodChange"
                        ></v-select>
                    </v-col>
                    <v-col cols="12" md="3">
                        <v-text-field
                            v-model="filters.search"
                            label="Buscar (nombre, cédula, asignatura)"
                            dense outlined clearable
                            prepend-inner-icon="mdi-magnify"
                            @input="debouncedFetch"
                        ></v-text-field>
                    </v-col>
                    <v-col cols="12" md="3">
                        <v-select
                            v-model="filters.learningplanid"
                            :items="plans"
                            item-text="name" item-value="id"
                            label="Carrera / plan"
                            dense outlined clearable
                            @change="onFilterChange"
                        ></v-select>
                    </v-col>
                    <v-col cols="12" md="3">
                        <v-select
                            v-model="filters.jornada"
                            :items="jornadaOptions"
                            label="Jornada del estudiante"
                            dense outlined clearable
                            @change="onFilterChange"
                        ></v-select>
                    </v-col>
                    <v-col cols="6" md="2">
                        <v-select
                            v-model="filters.hasclass"
                            :items="[
                                { value: 'all', text: 'Cualquiera' },
                                { value: 'yes', text: 'Con grupo proyectado' },
                                { value: 'no',  text: 'Sin grupo proyectado' }
                            ]"
                            item-text="text" item-value="value"
                            label="Estado del grupo"
                            dense outlined
                            @change="onFilterChange"
                        ></v-select>
                    </v-col>
                    <v-col cols="6" md="2">
                        <v-select
                            v-model="filters.hasquota"
                            :items="[
                                { value: 'all', text: 'Cualquiera' },
                                { value: 'yes', text: 'Con cupo disponible' },
                                { value: 'no',  text: 'Lleno o sin grupo' }
                            ]"
                            item-text="text" item-value="value"
                            label="Cupo"
                            dense outlined
                            @change="onFilterChange"
                        ></v-select>
                    </v-col>
                    <v-col cols="6" md="2">
                        <v-select
                            v-model="filters.financial_status"
                            :items="financialOptions"
                            label="Estado financiero"
                            dense outlined clearable
                            @change="onFilterChange"
                        ></v-select>
                    </v-col>
                </v-row>
            </v-card>

            <!-- Summary KPI cards -->
            <v-row class="mx-0 mb-3">
                <v-col cols="6" md="2">
                    <v-card outlined class="pa-3 fsr-kpi text-center">
                        <div class="text-caption grey--text text-uppercase">Estudiantes</div>
                        <div class="text-h4 font-weight-bold primary--text">{{ summary.students }}</div>
                    </v-card>
                </v-col>
                <v-col cols="6" md="2">
                    <v-card outlined class="pa-3 fsr-kpi text-center">
                        <div class="text-caption grey--text text-uppercase">Reprobadas</div>
                        <div class="text-h4 font-weight-bold red--text text--darken-2">{{ summary.failed_total }}</div>
                    </v-card>
                </v-col>
                <v-col cols="6" md="2">
                    <v-card outlined class="pa-3 fsr-kpi text-center">
                        <div class="text-caption grey--text text-uppercase">Con grupo</div>
                        <div class="text-h4 font-weight-bold blue--text text--darken-2">{{ summary.with_class }}</div>
                    </v-card>
                </v-col>
                <v-col cols="6" md="2">
                    <v-card outlined class="pa-3 fsr-kpi text-center">
                        <div class="text-caption grey--text text-uppercase">Con cupo</div>
                        <div class="text-h4 font-weight-bold green--text text--darken-2">{{ summary.with_quota }}</div>
                    </v-card>
                </v-col>
                <v-col cols="6" md="2">
                    <v-card outlined class="pa-3 fsr-kpi text-center">
                        <div class="text-caption grey--text text-uppercase">Lleno</div>
                        <div class="text-h4 font-weight-bold red--text">{{ summary.full_classes }}</div>
                    </v-card>
                </v-col>
                <v-col cols="6" md="2">
                    <v-card outlined class="pa-3 fsr-kpi text-center">
                        <div class="text-caption grey--text text-uppercase">Total filas</div>
                        <div class="text-h4 font-weight-bold">{{ total }}</div>
                    </v-card>
                </v-col>
            </v-row>

            <!-- Banner when no period is selected -->
            <v-alert v-if="!filters.periodid" type="info" dense text class="mb-3">
                Sin período seleccionado — solo se muestran reprobadas (sin match de grupo).
            </v-alert>

            <!-- Data table -->
            <v-card outlined class="rounded-lg">
                <v-data-table
                    :headers="[
                        { text: 'Cédula',    value: 'cedula',           sortable: false, width: 110 },
                        { text: 'Estudiante',value: 'student_name',     sortable: true,  width: 220 },
                        { text: 'Asignatura',value: 'coursename',       sortable: true },
                        { text: 'Nota',      value: 'last_grade',       sortable: true,  width: 80,  align: 'center' },
                        { text: 'Reprobada', value: 'failed_at',        sortable: true,  width: 110 },
                        { text: 'Jornada',   value: 'jornada_estudiante', sortable: true, width: 100, align: 'center' },
                        { text: 'Grupo',     value: 'classname',        sortable: true },
                        { text: 'Cupo',      value: 'classroomcapacity',sortable: true,  width: 90,  align: 'center' },
                        { text: 'Contacto',  value: 'phone',            sortable: false, width: 200 },
                        { text: 'Financiero',value: 'financial_status', sortable: false, width: 120, align: 'center' },
                        { text: 'Acción',    value: 'action',           sortable: false, width: 130, align: 'center' }
                    ]"
                    :items="rows"
                    :loading="loading"
                    :options.sync="tableOptions"
                    @update:options="onTableOptions"
                    :server-items-length="total"
                    :footer-props="{ 'items-per-page-options': perpageOptions, showFirstLastPage: true }"
                    :no-data-text="emptyMessage"
                    :no-results-text="emptyMessage"
                    class="elevation-0 fsr-table-vuetify"
                    item-key="progress_id"
                >
                    <template v-slot:item.cedula="{ item }">
                        <span class="fsr-nowrap">{{ item.cedula || '—' }}</span>
                    </template>
                    <template v-slot:item.student_name="{ item }">
                        <a href="javascript:void(0)" class="fsr-link"
                           @click="onStudentClick(item)">{{ item.student_name }}</a>
                    </template>
                    <template v-slot:item.last_grade="{ item }">
                        <span class="fsr-grade" :class="item.last_grade > 0 ? 'fsr-sem-full' : ''">
                            {{ fmtGrade(item.last_grade) }}
                        </span>
                    </template>
                    <template v-slot:item.failed_at="{ item }">
                        <span class="fsr-nowrap">{{ fmtDate(item.failed_at) }}</span>
                    </template>
                    <template v-slot:item.jornada_estudiante="{ item }">
                        <span class="fsr-pill" :class="jornadaPillClass(item.jornada_estudiante)">
                            {{ item.jornada_estudiante || '—' }}
                        </span>
                    </template>
                    <template v-slot:item.classname="{ item }">
                        <span v-if="!item.classid" class="text-caption grey--text">— sin grupo —</span>
                        <span v-else>
                            <div class="fsr-truncate" :title="item.classname">{{ item.classname }}</div>
                            <div class="text-caption grey--text" v-if="item.jornada_grupo">
                                Jornada: {{ item.jornada_grupo }}
                            </div>
                        </span>
                    </template>
                    <template v-slot:item.classroomcapacity="{ item }">
                        <div :class="semaforoClass(item)">
                            <v-icon small :color="item.is_full ? 'red' : (item.classid ? 'green' : 'grey')">
                                {{ semaforoIcon(item) }}
                            </v-icon>
                            <span style="margin-left:4px">{{ formatQuota(item) }}</span>
                        </div>
                    </template>
                    <template v-slot:item.phone="{ item }">
                        <div class="text-caption">
                            <div v-if="item.contact_mobile">
                                <v-icon small color="primary">mdi-cellphone</v-icon>
                                <span>{{ item.contact_mobile }}</span>
                            </div>
                            <div v-if="item.contact_phone && item.contact_phone !== item.contact_mobile">
                                <v-icon small color="primary">mdi-phone</v-icon>
                                <span>{{ item.contact_phone }}</span>
                            </div>
                            <div v-if="!item.contact_mobile && !item.contact_phone" class="grey--text">
                                —
                            </div>
                        </div>
                    </template>
                    <template v-slot:item.financial_status="{ item }">
                        <span v-if="item.financial_status" class="fsr-pill"
                              :class="financialPillClass(item.financial_status)">
                            {{ item.financial_label || item.financial_status }}
                        </span>
                        <span v-else class="text-caption grey--text">—</span>
                    </template>
                    <template v-slot:item.action="{ item }">
                        <v-btn v-if="!item.classid" disabled small
                               class="fsr-action-btn" color="grey lighten-2">
                            Sin grupo
                        </v-btn>
                        <v-btn v-else-if="item.is_full" small
                               class="fsr-action-btn" color="red" outlined
                               @click="enrolStudent(item, true)">
                            <v-icon left small>mdi-alert-circle</v-icon>
                            Forzar
                        </v-btn>
                        <v-btn v-else small
                               class="fsr-action-btn" color="success"
                               @click="enrolStudent(item, false)">
                            <v-icon left small>mdi-account-plus</v-icon>
                            Matricular
                        </v-btn>
                    </template>
                </v-data-table>
            </v-card>

            <failed-subjects-drawer
                :userid="drawerUserid"
                :open.sync="drawerOpen"
                @close="onDrawerClose"
            ></failed-subjects-drawer>
        </v-container>
    `
});
