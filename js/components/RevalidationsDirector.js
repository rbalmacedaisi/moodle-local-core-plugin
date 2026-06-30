Vue.component('revalidations-director', {
    template: `
        <v-container fluid style="max-width:100% !important;">
            <!-- Header -->
            <v-row class="mx-0 mb-3">
                <v-col cols="12" class="py-0 px-0 d-flex align-center">
                    <div>
                        <h2 class="mb-0">
                            <v-icon left color="amber darken-2">mdi-school-outline</v-icon>
                            Panel de Reválidas — Director Académico
                        </h2>
                        <div class="text-caption grey--text">
                            Seguimiento institucional de solicitudes, pagos y calificaciones.
                        </div>
                    </div>
                    <v-spacer></v-spacer>
                    <v-btn color="primary" class="rounded-lg mr-2" elevation="0" @click="fetchList" :loading="loading">
                        <v-icon left>mdi-refresh</v-icon>
                        Refrescar
                    </v-btn>
                    <v-btn v-if="canCreateExtemp"
                        color="amber darken-2" class="white--text rounded-lg" elevation="0"
                        @click="openCreateModal">
                        <v-icon left>mdi-clock-alert-outline</v-icon>
                        Crear solicitud extemporánea
                    </v-btn>
                </v-col>
            </v-row>

            <!-- KPI cards -->
            <v-row class="mx-0 mb-4">
                <v-col cols="6" md="3">
                    <v-card outlined class="pa-3 rounded-lg text-center" :color="statusFilter === 'unpaid' ? 'amber lighten-4' : ''" @click="setFilter('unpaid')" style="cursor:pointer">
                        <div class="text-caption grey--text text-uppercase">Pendientes de pago</div>
                        <div class="text-h4 font-weight-bold amber--text text--darken-4">{{ counts.unpaid }}</div>
                    </v-card>
                </v-col>
                <v-col cols="6" md="3">
                    <v-card outlined class="pa-3 rounded-lg text-center" :color="statusFilter === 'paid_ungraded' ? 'blue lighten-4' : ''" @click="setFilter('paid_ungraded')" style="cursor:pointer">
                        <div class="text-caption grey--text text-uppercase">Pagadas (sin calificar)</div>
                        <div class="text-h4 font-weight-bold blue--text text--darken-4">{{ counts.paid_ungraded }}</div>
                    </v-card>
                </v-col>
                <v-col cols="6" md="3">
                    <v-card outlined class="pa-3 rounded-lg text-center" :color="statusFilter === 'graded' ? 'green lighten-4' : ''" @click="setFilter('graded')" style="cursor:pointer">
                        <div class="text-caption grey--text text-uppercase">Calificadas</div>
                        <div class="text-h4 font-weight-bold green--text text--darken-4">{{ counts.graded }}</div>
                    </v-card>
                </v-col>
                <v-col cols="6" md="3">
                    <v-card outlined class="pa-3 rounded-lg text-center" :color="statusFilter === 'all' ? 'grey lighten-3' : ''" @click="setFilter('all')" style="cursor:pointer">
                        <div class="text-caption grey--text text-uppercase">Total</div>
                        <div class="text-h4 font-weight-bold">{{ counts.total }}</div>
                    </v-card>
                </v-col>
            </v-row>

            <!-- Filters -->
            <v-row class="mx-0 mb-3">
                <v-col cols="12" md="4">
                    <v-text-field v-model="filters.search" @input="debouncedFetch" label="Buscar (nombre, cédula, email)" prepend-inner-icon="mdi-magnify" clearable hide-details dense outlined></v-text-field>
                </v-col>
                <v-col cols="6" md="3">
                    <v-select v-model="statusFilter" :items="statusOptions" label="Estado" @change="fetchList" hide-details dense outlined></v-select>
                </v-col>
                <v-col cols="6" md="3">
                    <v-select v-model="filters.createdByDirectorOnly" :items="createdByOptions" label="Origen" @change="fetchList" hide-details dense outlined></v-select>
                </v-col>
                <v-col cols="6" md="2">
                    <v-switch v-model="filters.includeExtemporaneous" label="Incluir extemporáneas" @change="fetchList" hide-details dense color="amber darken-2"></v-switch>
                </v-col>
            </v-row>

            <!-- Table -->
            <v-card outlined class="rounded-lg">
                <v-data-table
                    :headers="headers"
                    :items="rows"
                    :loading="loading"
                    :options.sync="tableOptions"
                    :server-items-length="total"
                    :items-per-page="perpage"
                    :footer-props="{ 'items-per-page-options': [10,25,50,100] }"
                    class="rd-table"
                    @update:options="onTableOptions"
                    :no-data-text="loading ? 'Cargando...' : 'No hay solicitudes que coincidan con los filtros.'"
                >
                    <template v-slot:item.student_name="{ item }">
                        <div class="font-weight-medium">{{ item.student_name }}</div>
                        <div class="text-caption grey--text">
                            {{ item.student_idnumber || '—' }}
                            <span v-if="item.student_email"> · {{ item.student_email }}</span>
                        </div>
                    </template>
                    <template v-slot:item.coursename="{ item }">
                        <div>{{ item.coursename }}</div>
                        <div class="text-caption grey--text">{{ item.classname }}<span v-if="item.periodname"> · {{ item.periodname }}</span></div>
                    </template>
                    <template v-slot:item.instructor_name="{ item }">{{ item.instructor_name || '—' }}</template>
                    <template v-slot:item.originalgrade="{ item }">{{ formatGrade(item.originalgrade) }}</template>
                    <template v-slot:item.payment_state="{ item }">
                        <span v-if="item.payment_state === 'paid'" class="rd-pill rd-pill-green">Pagada</span>
                        <span v-else class="rd-pill rd-pill-amber">Sin pagar</span>
                        <span v-if="item.invoice_number" class="text-caption d-block">{{ item.invoice_number }}</span>
                    </template>
                    <template v-slot:item.status="{ item }">
                        <span v-if="item.status === 'consolidated'" class="rd-pill rd-pill-green">
                            {{ item.result === 'approved' ? 'Aprobada' : (item.result === 'failed' ? 'Reprobada' : 'Consolidada') }}
                        </span>
                        <span v-else class="rd-pill rd-pill-blue">Programada</span>
                        <span v-if="item.extemporaneous" class="rd-pill rd-pill-purple d-block mt-1"
                              :title="item.extemporaneous_reason || 'Solicitud extemporánea'">
                            Extemp.
                        </span>
                    </template>
                    <template v-slot:item.sessionstart="{ item }">{{ formatDateTime(item.sessionstart) }}</template>
                    <template v-slot:item.actions="{ item }">
                        <v-btn x-small icon color="primary" @click="refreshOne(item)" :loading="!!refreshing[item.id]" :title="'Refrescar pago'">
                            <v-icon small>mdi-refresh</v-icon>
                        </v-btn>
                        <v-btn v-if="item.payment_link" x-small icon color="blue" :href="item.payment_link" target="_blank" :title="'Ver factura en Odoo'">
                            <v-icon small>mdi-receipt</v-icon>
                        </v-btn>
                        <v-btn v-if="item.bbb_url" x-small icon color="purple" :href="item.bbb_url" target="_blank" :title="'Sesión BBB'">
                            <v-icon small>mdi-video</v-icon>
                        </v-btn>
                        <v-btn x-small icon color="info" @click="showReason(item)" :title="'Ver detalle'">
                            <v-icon small>mdi-information-outline</v-icon>
                        </v-btn>
                    </template>
                </v-data-table>
            </v-card>

            <!-- Modal: create extemporaneous -->
            <create-extemp-revalidation-modal
                v-if="createModalOpen"
                v-model="createModalOpen"
                @created="onExtempCreated"
            ></create-extemp-revalidation-modal>

            <!-- Reason dialog -->
            <v-dialog v-model="reasonDialog" max-width="540">
                <v-card>
                    <v-card-title class="text-h6">Detalle de solicitud</v-card-title>
                    <v-card-text>
                        <div v-if="reasonItem">
                            <div><strong>Estudiante:</strong> {{ reasonItem.student_name }}</div>
                            <div><strong>Asignatura:</strong> {{ reasonItem.coursename }}</div>
                            <div><strong>Clase:</strong> {{ reasonItem.classname }}</div>
                            <div><strong>Estado:</strong> {{ reasonItem.status }} · {{ reasonItem.payment_state }}</div>
                            <div><strong>Creado por:</strong> {{ reasonItem.createdby_name || '—' }} ({{ formatDateTime(reasonItem.timecreated) }})</div>
                            <div v-if="reasonItem.extemporaneous">
                                <strong>Extemporánea:</strong>
                                <span class="rd-pill rd-pill-purple">Sí</span>
                            </div>
                            <v-divider class="my-3"></v-divider>
                            <div><strong>Motivo:</strong></div>
                            <div class="text-body-2 mt-1">{{ reasonItem.extemporaneous_reason || '—' }}</div>
                        </div>
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn text @click="reasonDialog = false">Cerrar</v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>
        </v-container>
    `,
    props: {
        canCreateExtemp: { type: Boolean, default: false }
    },
    data() {
        return {
            loading: false,
            rows: [],
            counts: { unpaid: 0, paid_ungraded: 0, graded: 0, total: 0 },
            total: 0,
            page: 0,
            perpage: 25,
            statusFilter: 'unpaid',
            statusOptions: [
                { text: 'Pendientes de pago', value: 'unpaid' },
                { text: 'Pagadas (sin calificar)', value: 'paid_ungraded' },
                { text: 'Calificadas', value: 'graded' },
                { text: 'Todas', value: 'all' }
            ],
            filters: {
                search: '',
                includeExtemporaneous: true,
                createdByDirectorOnly: false,
                classid: 0,
                periodid: 0,
                instructorid: 0
            },
            createdByOptions: [
                { text: 'Todos los orígenes', value: false },
                { text: 'Solo creadas por director', value: true }
            ],
            refreshing: {},
            createModalOpen: false,
            reasonDialog: false,
            reasonItem: null,
            tableOptions: {},
            fetchTimer: null,
            headers: [
                { text: 'Estudiante', value: 'student_name', sortable: false },
                { text: 'Asignatura / Clase', value: 'coursename', sortable: false },
                { text: 'Docente', value: 'instructor_name', sortable: false },
                { text: 'Nota original', value: 'originalgrade', sortable: false, align: 'center' },
                { text: 'Pago', value: 'payment_state', sortable: false, align: 'center' },
                { text: 'Estado', value: 'status', sortable: false, align: 'center' },
                { text: 'Sesión', value: 'sessionstart', sortable: false },
                { text: 'Acciones', value: 'actions', sortable: false, align: 'center', width: 220 }
            ]
        };
    },
    created() {
        this.fetchList();
    },
    methods: {
        setFilter(status) {
            this.statusFilter = status;
            this.page = 0;
            this.fetchList();
        },
        debouncedFetch() {
            clearTimeout(this.fetchTimer);
            this.fetchTimer = setTimeout(() => { this.page = 0; this.fetchList(); }, 300);
        },
        onTableOptions(opt) {
            this.page = (opt.page || 1) - 1;
            this.perpage = opt.itemsPerPage || 25;
            this.fetchList();
        },
        async fetchList() {
            this.loading = true;
            try {
                const r = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_list_revalidations_director',
                    args: {
                        status_filter: this.statusFilter,
                        classid: this.filters.classid,
                        periodid: this.filters.periodid,
                        instructorid: this.filters.instructorid,
                        search: this.filters.search || '',
                        include_extemporaneous: this.filters.includeExtemporaneous,
                        created_by_director_only: this.filters.createdByDirectorOnly === true,
                        page: this.page,
                        perpage: this.perpage
                    },
                    sesskey: window.Y ? window.Y.config.sesskey : sesskey
                });
                if (r.data && r.data.status === 'success') {
                    this.rows = r.data.data.rows;
                    this.counts = r.data.data.counts;
                    this.total = r.data.data.total;
                } else {
                    throw new Error((r.data && r.data.message) || 'Error al cargar las solicitudes');
                }
            } catch (e) {
                console.error('[RevalidationsDirector] fetchList error:', e);
                Swal.fire('Error', e.message || 'No se pudieron cargar las solicitudes.', 'error');
                this.rows = [];
                this.counts = { unpaid: 0, paid_ungraded: 0, graded: 0, total: 0 };
                this.total = 0;
            } finally {
                this.loading = false;
            }
        },
        async refreshOne(item) {
            this.$set(this.refreshing, item.id, true);
            try {
                const r = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_refresh_revalidation_payment',
                    args: { revalidationid: item.id },
                    sesskey: window.Y ? window.Y.config.sesskey : sesskey
                });
                if (r.data && r.data.status === 'success') {
                    const paid = r.data.data.paid;
                    if (paid) {
                        Swal.fire({ icon: 'success', title: 'Pago confirmado', text: 'La factura está pagada.', timer: 1800, showConfirmButton: false });
                    } else {
                        Swal.fire({ icon: 'info', title: 'Aún sin pagar', text: 'La factura todavía no figura como pagada en Odoo.', timer: 2200, showConfirmButton: false });
                    }
                    await this.fetchList();
                } else {
                    throw new Error((r.data && r.data.message) || 'No se pudo refrescar');
                }
            } catch (e) {
                console.error('[RevalidationsDirector] refreshOne error:', e);
                Swal.fire('Error', e.message || 'No se pudo refrescar el pago.', 'error');
            } finally {
                this.$set(this.refreshing, item.id, false);
            }
        },
        openCreateModal() {
            this.createModalOpen = true;
        },
        onExtempCreated() {
            this.fetchList();
        },
        showReason(item) {
            this.reasonItem = item;
            this.reasonDialog = true;
        },
        formatGrade(n) {
            if (n === null || n === undefined || isNaN(parseFloat(n))) return '—';
            return parseFloat(n).toFixed(1);
        },
        formatDateTime(ts) {
            if (!ts) return '—';
            const d = new Date(ts * 1000);
            if (isNaN(d.getTime())) return '—';
            return d.toLocaleString('es-PA', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
        }
    }
});