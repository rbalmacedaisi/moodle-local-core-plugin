Vue.component('intake-timeline', {
    template: `
    <div class="tl-page">
        <!-- HEADER -->
        <div class="tl-subheader">
            <a :href="backUrl" class="tl-back-btn">
                <v-icon size="16" color="#6366F1">mdi-arrow-left</v-icon>
                <span>Carreras</span>
            </a>
            <div class="tl-subheader-title" v-if="timelineData.career">
                <h2>{{ timelineData.career.name }}</h2>
                <span class="tl-subheader-meta">
                    {{ timelineData.intake_periods ? timelineData.intake_periods.length : 0 }} cohorte(s) •
                    <strong>{{ totalLxpActive }}</strong> activos de <strong>{{ totalLxpCount }}</strong> matriculados
                </span>
            </div>
        </div>

        <!-- KPI BAR -->
        <div v-if="!loading && timelineData.intake_periods && timelineData.intake_periods.length" class="tl-kpi-bar">
            <div class="tl-kpi">
                <div class="tl-kpi-icon" style="background: #EEF2FF; color: #6366F1;">
                    <v-icon size="20">mdi-account-group</v-icon>
                </div>
                <div class="tl-kpi-body">
                    <div class="tl-kpi-num">{{ totalLxpActive }}</div>
                    <div class="tl-kpi-lbl">Estudiantes activos</div>
                </div>
            </div>
            <div class="tl-kpi">
                <div class="tl-kpi-icon" style="background: #FEF3C7; color: #F59E0B;">
                    <v-icon size="20">mdi-account-arrow-right</v-icon>
                </div>
                <div class="tl-kpi-body">
                    <div class="tl-kpi-num">{{ generalDropoutRate }}%</div>
                    <div class="tl-kpi-lbl">Deserción total</div>
                </div>
            </div>
            <div class="tl-kpi">
                <div class="tl-kpi-icon" style="background: #D1FAE5; color: #10B981;">
                    <v-icon size="20">mdi-school-outline</v-icon>
                </div>
                <div class="tl-kpi-body">
                    <div class="tl-kpi-num">{{ totalGraduating }}</div>
                    <div class="tl-kpi-lbl">Próximos graduandos</div>
                </div>
            </div>
            <div class="tl-kpi">
                <div class="tl-kpi-icon" style="background: #DBEAFE; color: #3B82F6;">
                    <v-icon size="20">mdi-database</v-icon>
                </div>
                <div class="tl-kpi-body">
                    <div class="tl-kpi-num">{{ totalOdooCount }}</div>
                    <div class="tl-kpi-lbl">CRM (Odoo)</div>
                </div>
            </div>
        </div>

        <!-- ERROR -->
        <v-alert v-if="errorMsg" type="error" outlined class="tl-alert mb-4" border="left">
            <div class="d-flex align-center">
                <v-icon left color="error">mdi-alert-circle</v-icon>
                {{ errorMsg }}
            </div>
        </v-alert>

        <!-- LOADING -->
        <div v-if="loading" class="tl-skel-stack">
            <div v-for="n in 3" :key="n" class="tl-skel-cohort">
                <div class="tl-skel" style="height: 24px; width: 30%; margin-bottom: 12px;"></div>
                <div class="tl-skel" style="height: 80px; width: 100%; border-radius: 8px;"></div>
            </div>
        </div>

        <!-- EMPTY -->
        <div v-else-if="!timelineData.intake_periods || timelineData.intake_periods.length === 0" class="tl-empty">
            <div class="tl-empty-icon">
                <v-icon size="64" color="#CBD5E1">mdi-account-clock-outline</v-icon>
            </div>
            <h3 class="tl-empty-title">No hay periodos de ingreso</h3>
            <p class="tl-empty-sub">Verifica que los estudiantes tengan el campo "Periodo de Ingreso" definido en su perfil.</p>
        </div>

        <!-- COHORT LIST -->
        <div v-else class="tl-cohorts">
            <div
                v-for="(ip, idx) in timelineData.intake_periods"
                :key="ip.period"
                class="tl-cohort"
                :class="{ 'tl-cohort-open': openPanels.includes(idx) }"
            >
                <!-- COHORT HEADER (always visible) -->
                <div class="tl-cohort-head" @click="togglePanel(idx)">
                    <div class="tl-cohort-id">
                        <div class="tl-cohort-num">{{ idx + 1 }}</div>
                        <div>
                            <div class="tl-cohort-period">Cohorte {{ ip.period }}</div>
                            <div class="tl-cohort-sub">
                                <span class="tl-cohort-pill tl-cohort-pill-active">
                                    <v-icon size="12" color="#10B981">mdi-check-circle</v-icon>
                                    {{ ip.lxp_active }} activos
                                </span>
                                <span class="tl-cohort-pill">
                                    {{ ip.lxp_count }} matriculados
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="tl-cohort-funnel">
                        <div class="tl-funnel-stage">
                            <v-icon size="14" color="#6366F1">mdi-database</v-icon>
                            <span class="tl-funnel-num">{{ ip.odoo_count != null ? ip.odoo_count : '—' }}</span>
                            <span class="tl-funnel-lbl">CRM</span>
                        </div>
                        <div class="tl-funnel-arrow">
                            <v-icon size="14" color="#CBD5E1">mdi-arrow-right</v-icon>
                        </div>
                        <div class="tl-funnel-stage">
                            <v-icon size="14" color="#6366F1">mdi-school</v-icon>
                            <span class="tl-funnel-num">{{ ip.lxp_count }}</span>
                            <span class="tl-funnel-lbl">LXP</span>
                        </div>
                        <div class="tl-funnel-arrow">
                            <v-icon size="14" color="#CBD5E1">mdi-arrow-right</v-icon>
                        </div>
                        <div class="tl-funnel-stage">
                            <v-icon size="14" color="#10B981">mdi-check-circle</v-icon>
                            <span class="tl-funnel-num">{{ ip.lxp_active }}</span>
                            <span class="tl-funnel-lbl">Activos</span>
                        </div>
                    </div>
                    <div class="tl-cohort-actions">
                        <span
                            class="tl-cohort-badge"
                            :class="'tl-badge-' + dropoutClass(ip.dropout_rate)"
                        >
                            {{ ip.dropout_rate != null ? ip.dropout_rate + '%' : '—' }}
                            <span class="tl-badge-lbl">deserción</span>
                        </span>
                        <button
                            class="tl-btn-icon"
                            @click.stop="openSubjectsPanel(ip.period)"
                            title="Asignaturas"
                        >
                            <v-icon size="16" color="#6366F1">mdi-book-open-variant</v-icon>
                        </button>
                        <button
                            class="tl-btn-icon tl-btn-reassign"
                            @click.stop="openBulkReassign(ip)"
                            title="Reclasificar cohorte"
                        >
                            <v-icon size="16" color="#F59E0B">mdi-account-multiple-convert</v-icon>
                        </button>
                        <button
                            class="tl-btn-icon tl-btn-renew"
                            @click.stop="openRenewModal(ip)"
                            title="Renovar periodo (B1→B2 / B2→C[n+1]B1 / B2 último C→graduando)"
                        >
                            <v-icon size="16" color="#10B981">mdi-calendar-refresh-outline</v-icon>
                        </button>
                        <button class="tl-btn-icon tl-chevron" :class="{ 'tl-chevron-open': openPanels.includes(idx) }">
                            <v-icon size="18" color="#64748B">mdi-chevron-down</v-icon>
                        </button>
                    </div>
                </div>

                <!-- COHORT BODY (collapsible) -->
                <div v-show="openPanels.includes(idx)" class="tl-cohort-body">
                    <!-- FUNNEL VISUAL -->
                    <div class="tl-funnel-bar">
                        <div class="tl-funnel-bar-step" style="background: #6366F1;">
                            <v-icon size="14" color="white">mdi-database</v-icon>
                            <span>CRM/Odoo</span>
                            <strong>{{ ip.odoo_count != null ? ip.odoo_count : '—' }}</strong>
                        </div>
                        <div class="tl-funnel-bar-arrow">
                            <v-icon size="14" color="#94A3B8">mdi-arrow-right</v-icon>
                        </div>
                        <div class="tl-funnel-bar-step" style="background: #3B82F6;">
                            <v-icon size="14" color="white">mdi-school</v-icon>
                            <span>Matriculados</span>
                            <strong>{{ ip.lxp_count }}</strong>
                        </div>
                        <div class="tl-funnel-bar-arrow">
                            <v-icon size="14" color="#94A3B8">mdi-arrow-right</v-icon>
                        </div>
                        <div class="tl-funnel-bar-step tl-funnel-bar-step-active" style="background: #10B981;">
                            <v-icon size="14" color="white">mdi-check-circle</v-icon>
                            <span>Activos</span>
                            <strong>{{ ip.lxp_active }}</strong>
                        </div>
                    </div>

                    <!-- PROGRESO ACADÉMICO -->
                    <div class="tl-section-title">
                        <v-icon size="14" color="#64748B">mdi-progress-clock</v-icon>
                        <span>Progreso Académico por Cuatrimestre</span>
                    </div>

                    <div class="tl-quarter-strip">
                        <template v-for="(period, pIdx) in timelineData.curriculum">
                            <div
                                v-if="pIdx > 0"
                                :key="'arrow-'+period.id"
                                class="tl-quarter-arrow"
                            >
                                <v-icon size="14" color="#CBD5E1">mdi-chevron-right</v-icon>
                            </div>

                            <div :key="period.id" class="tl-quarter">
                                <div
                                    class="tl-quarter-head"
                                    :style="{ background: quarterBg(pIdx), color: quarterColor(pIdx) }"
                                >
                                    <span class="tl-quarter-name">{{ period.name }}</span>
                                </div>
                                <div class="tl-quarter-body">
                                    <div class="tl-quarter-count">
                                        <span class="tl-quarter-num" :style="{ color: quarterColor(pIdx) }">
                                            {{ getLevelCount(ip, period.id, 'active') }}
                                        </span>
                                        <span class="tl-quarter-lbl">activos</span>
                                    </div>
                                    <div class="tl-quarter-sub">
                                        <span class="tl-quarter-sub-item">
                                            <v-icon size="10" color="#10B981">mdi-check-circle</v-icon>
                                            {{ getLevelCount(ip, period.id, 'active') }}
                                        </span>
                                        <span class="tl-quarter-sub-item">
                                            <v-icon size="10" color="#EF4444">mdi-close-circle</v-icon>
                                            {{ getLevelCount(ip, period.id, 'inactive') }}
                                        </span>
                                    </div>
                                    <div v-if="period.subperiods && period.subperiods.length > 0" class="tl-bims">
                                        <div
                                            v-for="sp in period.subperiods"
                                            :key="sp.sp_id"
                                            class="tl-bim"
                                            :style="{ borderColor: quarterColor(pIdx) + '44' }"
                                            @click="openStudentList(ip, sp, period)"
                                            :title="'Ver estudiantes de ' + sp.sp_name"
                                        >
                                            <div class="tl-bim-name" :style="{ color: quarterColor(pIdx) }">
                                                {{ sp.sp_name }}
                                            </div>
                                            <div class="tl-bim-num" :style="{ color: quarterColor(pIdx) }">
                                                {{ getSubLevelCount(ip, sp.sp_id, 'active') || '—' }}
                                            </div>
                                            <div class="tl-bim-sub">
                                                <span style="color: #10B981;">{{ getSubLevelCount(ip, sp.sp_id, 'active') || 0 }}</span>
                                                <span style="color: #94A3B8;">/</span>
                                                <span style="color: #EF4444;">{{ getSubLevelCount(ip, sp.sp_id, 'inactive') || 0 }}</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div v-else class="tl-bim-empty">Sin bimestres</div>
                                </div>
                            </div>
                        </template>

                        <div class="tl-quarter-arrow">
                            <v-icon size="14" color="#CBD5E1">mdi-chevron-right</v-icon>
                        </div>

                        <div class="tl-quarter tl-quarter-grad">
                            <div class="tl-quarter-head" style="background: #FEF3C7; color: #B45309;">
                                <span class="tl-quarter-name">Graduando</span>
                            </div>
                            <div class="tl-quarter-body">
                                <v-icon size="22" color="#F59E0B">mdi-school-outline</v-icon>
                                <div class="tl-quarter-count">
                                    <span class="tl-quarter-num" style="color: #B45309;">{{ getGraduating(ip) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- DESERCIÓN ENTRE CUATRIMESTRES -->
                    <div v-if="timelineData.curriculum && timelineData.curriculum.length > 1" class="tl-section mt-4">
                        <div class="tl-section-title">
                            <v-icon size="14" color="#64748B">mdi-trending-down</v-icon>
                            <span>Deserción entre Cuatrimestres</span>
                        </div>
                        <div class="tl-dropoffs">
                            <div
                                v-for="(rate, rIdx) in getQuarterDropoutRates(ip)"
                                :key="rIdx"
                                class="tl-dropoff"
                                :class="'tl-dropoff-' + dropoutClass(rate.rate)"
                            >
                                <span class="tl-dropoff-label">Q{{ rIdx + 1 }} → Q{{ rIdx + 2 }}</span>
                                <span class="tl-dropoff-num">{{ rate.rate != null ? rate.rate + '%' : '—' }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- MODAL -->
        <student-list-modal
            v-model="showStudentModal"
            :career-id="careerId"
            :subperiod-id="selectedSubperiod.sp_id"
            :subperiod-name="selectedSubperiod.sp_name"
            :intake-period="selectedIntakePeriod"
            @close="closeStudentModal"
        ></student-list-modal>

        <!-- BULK REASSIGN MODAL -->
        <bulk-reassign-modal
            v-if="showBulkReassign"
            :cohort="bulkReassignCohort"
            :learning-plan-id="careerId"
            :career-name="timelineData.career ? timelineData.career.name : 'Carrera'"
            @close="closeBulkReassign"
            @done="onBulkReassignDone"
        ></bulk-reassign-modal>

        <!-- PERIOD RENEWAL MODAL (FASE 2) -->
        <renewal-modal
            v-if="showRenewal"
            :cohort="renewalCohort"
            :learning-plan-id="careerId"
            :career-name="timelineData.career ? timelineData.career.name : 'Carrera'"
            @close="closeRenewal"
            @done="onRenewalDone"
        ></renewal-modal>

        <!-- BULK REASSIGN TOAST -->
        <transition name="tl-fade">
            <div v-if="toast" :class="['tl-toast', 'tl-toast-' + toast.type]">
                <v-icon size="18" dark>{{ toast.type === 'success' ? 'mdi-check-circle' : 'mdi-alert-circle' }}</v-icon>
                <span>{{ toast.msg }}</span>
            </div>
        </transition>
    </div>
    `,
    props: {
        careerId: { type: [Number, String], default: 0 },
        backUrl:  { type: String, default: '' },
    },
    data() {
        return {
            loading: true,
            errorMsg: '',
            timelineData: { career: null, curriculum: [], intake_periods: [] },
            openPanels: [],
            quarterColors:   ['#10B981', '#3B82F6', '#F59E0B', '#8B5CF6', '#06B6D4', '#EF4444'],
            quarterBgColors: ['#D1FAE5', '#DBEAFE', '#FEF3C7', '#EDE9FE', '#CFFAFE', '#FEE2E2'],
            showStudentModal: false,
            selectedSubperiod: { sp_id: 0, sp_name: '' },
            selectedIntakePeriod: '',
            selectedCohortForPanel: '2026',
            // Bulk reassign (FASE 1)
            showBulkReassign: false,
            bulkReassignCohort: '',
            // Period renewal (FASE 2)
            showRenewal: false,
            renewalCohort: '',
            toast: null,
        };
    },
    computed: {
        wsUrl() { return window.location.origin + '/webservice/rest/server.php'; },
        token() { return window.userToken; },
        generalDropoutRate() {
            if (!this.timelineData.intake_periods || !this.timelineData.intake_periods.length) return '—';
            let totalCrm = 0, totalActive = 0, valid = 0;
            this.timelineData.intake_periods.forEach(ip => {
                if (ip.odoo_count != null) {
                    totalCrm   += ip.odoo_count;
                    totalActive += ip.lxp_active;
                    valid++;
                }
            });
            if (!valid || !totalCrm) return '—';
            return Math.round(((totalCrm - totalActive) / totalCrm) * 100);
        },
        totalLxpActive() {
            return (this.timelineData.intake_periods || []).reduce((s, ip) => s + (ip.lxp_active || 0), 0);
        },
        totalLxpCount() {
            return (this.timelineData.intake_periods || []).reduce((s, ip) => s + (ip.lxp_count || 0), 0);
        },
        totalOdooCount() {
            return (this.timelineData.intake_periods || []).reduce((s, ip) => s + (ip.odoo_count || 0), 0);
        },
        totalGraduating() {
            if (!this.timelineData.curriculum || !this.timelineData.curriculum.length) return 0;
            const last = this.timelineData.curriculum[this.timelineData.curriculum.length - 1];
            return (this.timelineData.intake_periods || []).reduce((s, ip) => {
                return s + this.getLevelCount(ip, last.id, 'active');
            }, 0);
        },
    },
    created() {
        this.loadTimeline();
    },
    methods: {
        togglePanel(idx) {
            const i = this.openPanels.indexOf(idx);
            if (i === -1) this.openPanels.push(idx);
            else this.openPanels.splice(i, 1);
        },
        async loadTimeline() {
            this.loading = true;
            this.errorMsg = '';
            try {
                const response = await window.axios.get(this.wsUrl, {
                    params: {
                        wstoken: this.token,
                        wsfunction: 'local_grupomakro_get_student_career_timeline',
                        moodlewsrestformat: 'json',
                        learningplanid: this.careerId,
                    }
                });
                const data = response.data;
                if (data && data.exception) {
                    this.errorMsg = data.message || data.errorcode || 'Error desconocido';
                    return;
                }
                if (data && data.career) {
                    this.timelineData = data;
                    this.openPanels = data.intake_periods.map((_, i) => i);
                    this.$emit('lp-selected', this.careerId);
                    if (data.intake_periods && data.intake_periods.length > 0) {
                        this.selectedCohortForPanel = data.intake_periods[0].period;
                    }
                }
            } catch (e) {
                this.errorMsg = 'Error de conexión al cargar la línea de tiempo.';
                console.error('[timeline] Error:', e);
            } finally {
                this.loading = false;
            }
        },
        openSubjectsPanel(period) {
            this.$emit('toggle-courses');
            this.$emit('cohort-selected', period || this.selectedCohortForPanel || '2026');
        },
        quarterColor(idx) { return this.quarterColors[idx % this.quarterColors.length]; },
        quarterBg(idx)    { return this.quarterBgColors[idx % this.quarterBgColors.length]; },
        dropoutClass(rate) {
            if (rate == null) return 'muted';
            if (rate >= 20) return 'error';
            if (rate >= 10) return 'warning';
            return 'success';
        },
        dropoutColor(rate) {
            const c = this.dropoutClass(rate);
            return c === 'error' ? '#EF4444' : c === 'warning' ? '#F59E0B' : c === 'success' ? '#10B981' : '#94A3B8';
        },
        getLevelCount(ip, periodId, type) {
            if (!ip.levels) return 0;
            const found = ip.levels.find(l => l.period_id === periodId);
            return found ? (found[type] || 0) : 0;
        },
        getSubLevelCount(ip, subperiodId, type) {
            if (!ip.sublevel_counts) return 0;
            const found = ip.sublevel_counts.find(s => s.subperiod_id === subperiodId);
            return found ? (found[type] || 0) : 0;
        },
        getGraduating(ip) {
            if (!this.timelineData.curriculum || !this.timelineData.curriculum.length) return '—';
            const last = this.timelineData.curriculum[this.timelineData.curriculum.length - 1];
            return this.getLevelCount(ip, last.id, 'active') || '—';
        },
        getQuarterDropoutRates(ip) {
            const cur = this.timelineData.curriculum;
            if (!cur || cur.length < 2) return [];
            return cur.slice(0, -1).map((period, i) => {
                const a = this.getLevelCount(ip, period.id, 'active') + this.getLevelCount(ip, period.id, 'inactive');
                const b = this.getLevelCount(ip, cur[i + 1].id, 'active') + this.getLevelCount(ip, cur[i + 1].id, 'inactive');
                return { rate: a === 0 ? null : Math.round(((a - b) / a) * 100) };
            });
        },
        openStudentList(ip, sp, period) {
            this.selectedSubperiod = { sp_id: sp.sp_id, sp_name: sp.sp_name };
            this.selectedIntakePeriod = ip.period;
            this.showStudentModal = true;
        },
        closeStudentModal() {
            this.showStudentModal = false;
        },
        openBulkReassign(ip) {
            this.bulkReassignCohort = ip.period;
            this.showBulkReassign = true;
        },
        closeBulkReassign() {
            this.showBulkReassign = false;
        },
        onBulkReassignDone(payload) {
            this.showBulkReassign = false;
            this.showToast('success', payload.message || 'Estudiantes reasignados correctamente');
            // Reload timeline to reflect new counts
            this.loadTimeline();
        },
        showToast(type, msg) {
            this.toast = { type, msg };
            setTimeout(() => { this.toast = null; }, 4500);
        },
    },
});
