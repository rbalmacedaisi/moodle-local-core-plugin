Vue.component('student-list-modal', {
    template: `
    <v-dialog v-model="dialogVisible" max-width="980" persistent>
        <v-card class="tl-modal">
            <!-- HEADER -->
            <div class="tl-modal-head">
                <div class="tl-modal-head-info">
                    <div class="tl-modal-avatar">
                        <v-icon size="20" color="white">mdi-account-group</v-icon>
                    </div>
                    <div>
                        <h3 class="tl-modal-title">Estudiantes del Bimestre</h3>
                        <div class="tl-modal-sub">
                            <span class="tl-modal-chip">
                                <v-icon size="11" color="#6366F1">mdi-calendar-blank</v-icon>
                                {{ subperiodName }}
                            </span>
                            <span class="tl-modal-chip">
                                <v-icon size="11" color="#6366F1">mdi-flag-variant</v-icon>
                                Cohorte {{ intakePeriod }}
                            </span>
                        </div>
                    </div>
                </div>
                <button class="tl-modal-close" @click="close" title="Cerrar">
                    <v-icon size="18" color="#64748B">mdi-close</v-icon>
                </button>
            </div>

            <!-- KPI ROW -->
            <div class="tl-modal-kpis" v-if="!loading && students.length > 0">
                <div class="tl-modal-kpi">
                    <v-icon size="14" color="#6366F1">mdi-account-multiple</v-icon>
                    <span class="tl-modal-kpi-num">{{ students.length }}</span>
                    <span class="tl-modal-kpi-lbl">Total</span>
                </div>
                <div class="tl-modal-kpi">
                    <v-icon size="14" color="#10B981">mdi-check-circle</v-icon>
                    <span class="tl-modal-kpi-num">{{ activeCount }}</span>
                    <span class="tl-modal-kpi-lbl">Activos</span>
                </div>
                <div class="tl-modal-kpi">
                    <v-icon size="14" color="#EF4444">mdi-close-circle</v-icon>
                    <span class="tl-modal-kpi-num">{{ students.length - activeCount }}</span>
                    <span class="tl-modal-kpi-lbl">Inactivos</span>
                </div>
            </div>

            <!-- TOOLBAR -->
            <div class="tl-modal-toolbar">
                <div class="tl-modal-search">
                    <v-icon size="14" color="#94A3B8">mdi-magnify</v-icon>
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Buscar por nombre, identificación o email..."
                        class="tl-modal-search-input"
                    />
                </div>
            </div>

            <!-- PROGRESS -->
            <v-progress-linear v-if="loading" indeterminate color="#6366F1" class="tl-modal-progress"></v-progress-linear>

            <!-- ALERTS -->
            <v-alert v-if="errorMsg" type="error" outlined class="ma-4 tl-alert" border="left">
                {{ errorMsg }}
            </v-alert>
            <v-alert v-if="!loading && students.length === 0" type="info" outlined class="ma-4" border="left">
                No hay estudiantes en este bimestre.
            </v-alert>

            <!-- TABLE -->
            <div v-if="!loading && students.length > 0" class="tl-modal-table-wrap">
                <table class="tl-modal-table">
                    <thead>
                        <tr>
                            <th class="tl-th-id">ID</th>
                            <th>Estudiante</th>
                            <th>Contacto</th>
                            <th class="tl-th-center">Estado</th>
                            <th class="tl-th-center">Cohorte</th>
                            <th class="tl-th-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in filteredStudents" :key="s.userid" class="tl-modal-row">
                            <td>
                                <span class="tl-modal-id">{{ s.username }}</span>
                            </td>
                            <td>
                                <div class="tl-modal-student">
                                    <div class="tl-modal-avatar-sm">
                                        {{ initials(s.firstname, s.lastname) }}
                                    </div>
                                    <div>
                                        <div class="tl-modal-student-name">{{ s.firstname }} {{ s.lastname }}</div>
                                        <div class="tl-modal-student-sub">{{ s.fullname }}</div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="tl-modal-contact">
                                    <div v-if="s.email" class="tl-modal-contact-item">
                                        <v-icon size="11" color="#94A3B8">mdi-email-outline</v-icon>
                                        {{ s.email }}
                                    </div>
                                    <div v-if="s.phone" class="tl-modal-contact-item">
                                        <v-icon size="11" color="#94A3B8">mdi-phone-outline</v-icon>
                                        {{ s.phone }}
                                    </div>
                                    <div v-if="!s.email && !s.phone" class="tl-modal-contact-empty">—</div>
                                </div>
                            </td>
                            <td class="tl-td-center">
                                <span class="tl-status-pill" :class="'tl-status-' + (s.status === 'activo' ? 'ok' : 'off')">
                                    <span class="tl-status-dot"></span>
                                    {{ s.status }}
                                </span>
                            </td>
                            <td class="tl-td-center">
                                <span class="tl-cohort-pill">{{ s.intake_period }}</span>
                            </td>
                            <td class="tl-td-center">
                                <v-menu offset-y>
                                    <template v-slot:activator="{ on, attrs }">
                                        <button
                                            class="tl-btn-action"
                                            v-bind="attrs"
                                            v-on="on"
                                        >
                                            <v-icon left size="14" color="#6366F1">mdi-swap-horizontal</v-icon>
                                            Reasignar
                                            <v-icon right size="12" color="#94A3B8">mdi-chevron-down</v-icon>
                                        </button>
                                    </template>
                                    <v-list dense class="tl-reassign-list">
                                        <v-list-item
                                            v-for="period in availablePeriods"
                                            :key="period"
                                            @click="reassignPeriod(s, period)"
                                            :disabled="period === s.intake_period"
                                            :class="{ 'tl-reassign-current': period === s.intake_period }"
                                        >
                                            <v-list-item-title class="d-flex align-center">
                                                <v-icon
                                                    left
                                                    size="14"
                                                    :color="period === s.intake_period ? '#10B981' : '#6366F1'"
                                                >
                                                    {{ period === s.intake_period ? 'mdi-check-circle' : 'mdi-calendar-arrow-right' }}
                                                </v-icon>
                                                <span>{{ period }}</span>
                                                <span v-if="period === s.intake_period" class="tl-reassign-current-tag">Actual</span>
                                            </v-list-item-title>
                                        </v-list-item>
                                    </v-list>
                                </v-menu>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- FOOTER -->
            <div class="tl-modal-foot">
                <span class="tl-modal-foot-info">
                    <v-icon size="12" color="#94A3B8">mdi-information-outline</v-icon>
                    Mostrando {{ filteredStudents.length }} de {{ students.length }} estudiantes
                </span>
                <button class="tl-btn-secondary" @click="close">Cerrar</button>
            </div>
        </v-card>

        <!-- TOAST -->
        <transition name="tl-fade">
        <div v-if="snackbar.show" class="tl-toast" :class="'tl-toast-' + snackbar.color">
            <v-icon size="16" color="white">{{ snackbar.color === 'success' ? 'mdi-check-circle' : 'mdi-alert-circle' }}</v-icon>
            <span>{{ snackbar.text }}</span>
        </div>
        </transition>
    </v-dialog>
    `,
    props: {
        value: { type: Boolean, default: false },
        careerId: { type: Number, required: true },
        subperiodId: { type: Number, required: true },
        subperiodName: { type: String, default: '' },
        intakePeriod: { type: String, required: true },
    },
    data() {
        return {
            loading: false,
            errorMsg: '',
            students: [],
            availablePeriods: [],
            search: '',
            snackbar: { show: false, text: '', color: 'success' },
        };
    },
    computed: {
        dialogVisible: {
            get() { return this.value; },
            set(v) { this.$emit('input', v); },
        },
        wsUrl() { return window.location.origin + '/webservice/rest/server.php'; },
        token() { return window.userToken; },
        activeCount() { return this.students.filter(s => s.status === 'activo').length; },
        filteredStudents() {
            if (!this.search) return this.students;
            const q = this.search.toLowerCase();
            return this.students.filter(s =>
                (s.firstname || '').toLowerCase().includes(q) ||
                (s.lastname || '').toLowerCase().includes(q) ||
                (s.username || '').toLowerCase().includes(q) ||
                (s.email || '').toLowerCase().includes(q) ||
                (s.fullname || '').toLowerCase().includes(q)
            );
        },
    },
    watch: {
        value(v) { if (v) this.loadStudents(); },
    },
    methods: {
        async loadStudents() {
            this.loading = true;
            this.errorMsg = '';
            try {
                const response = await window.axios.get(this.wsUrl, {
                    params: {
                        wstoken: this.token,
                        wsfunction: 'local_grupomakro_get_students_by_subperiod',
                        moodlewsrestformat: 'json',
                        learningplanid: this.careerId,
                        subperiodid: this.subperiodId,
                        intake_period: this.intakePeriod,
                    }
                });
                const data = response.data;
                if (data && data.exception) {
                    this.errorMsg = data.message || data.errorcode || 'Error del servidor';
                    return;
                }
                this.students = data.students || [];
                this.availablePeriods = data.available_periods || [];
            } catch (e) {
                this.errorMsg = 'Error de conexión al cargar estudiantes.';
                console.error('[studentlistmodal] Error:', e);
            } finally {
                this.loading = false;
            }
        },
        async reassignPeriod(student, newPeriod) {
            if (student.intake_period === newPeriod) return;
            try {
                const response = await window.axios.post(this.wsUrl, null, {
                    params: {
                        wstoken: this.token,
                        wsfunction: 'local_grupomakro_reassign_student_intake_period',
                        moodlewsrestformat: 'json',
                        userid: student.userid,
                        new_intake_period: newPeriod,
                    }
                });
                const data = response.data;
                if (data && data.exception) {
                    this.showSnackbar('Error: ' + (data.message || data.errorcode), 'error');
                    return;
                }
                if (data.success) {
                    this.showSnackbar(`${student.firstname} ${student.lastname} → ${newPeriod}`, 'success');
                    await this.loadStudents();
                }
            } catch (e) {
                this.showSnackbar('Error al reasignar periodo.', 'error');
                console.error('[studentlistmodal] Reassign error:', e);
            }
        },
        showSnackbar(text, color) {
            this.snackbar.text = text;
            this.snackbar.color = color;
            this.snackbar.show = true;
            setTimeout(() => { this.snackbar.show = false; }, 3500);
        },
        initials(first, last) {
            const f = (first || '').trim().charAt(0);
            const l = (last  || '').trim().charAt(0);
            return (f + l).toUpperCase() || '?';
        },
        close() {
            this.$emit('input', false);
            this.$emit('close');
        },
    },
});
