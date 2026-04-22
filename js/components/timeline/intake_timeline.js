Vue.component('intake-timeline', {
    template: `
    <v-container fluid class="pa-4">

        <!-- Header con breadcrumb y métricas -->
        <v-row align="center" class="mb-2">
            <v-col cols="auto">
                <v-btn text small :href="backUrl" class="pl-0">
                    <v-icon left>mdi-arrow-left</v-icon>
                    Carreras
                </v-btn>
            </v-col>
        </v-row>

        <v-row v-if="loading">
            <v-col cols="12" class="text-center py-10">
                <v-progress-circular indeterminate color="primary" size="50"></v-progress-circular>
                <p class="mt-4 text--secondary">Cargando línea de tiempo...</p>
            </v-col>
        </v-row>

        <template v-else>
            <v-row align="center" class="mb-4">
                <v-col>
                    <h2 class="text-h5 font-weight-bold">
                        <v-icon large class="mr-2" color="primary">mdi-chart-timeline-variant</v-icon>
                        {{ timelineData.career ? timelineData.career.name : '' }}
                    </h2>
                    <p class="text--secondary mb-0 mt-1">
                        Progreso por periodo de ingreso — {{ timelineData.intake_periods ? timelineData.intake_periods.length : 0 }} cohorte(s)
                    </p>
                </v-col>
                <v-col cols="auto">
                    <v-chip color="error" outlined>
                        <v-icon left small>mdi-account-arrow-right</v-icon>
                        Deserción total: {{ generalDropoutRate }}%
                    </v-chip>
                </v-col>
            </v-row>

            <v-expansion-panels v-model="openPanels" multiple accordion>
                <v-expansion-panel
                    v-for="(ip, idx) in timelineData.intake_periods"
                    :key="ip.period"
                    class="mb-3"
                    style="border-radius:8px; overflow:hidden;"
                >
                    <v-expansion-panel-header class="py-3">
                        <v-row align="center" no-gutters>
                            <v-col cols="auto" class="mr-3">
                                <v-icon color="primary">mdi-calendar-account</v-icon>
                            </v-col>
                            <v-col>
                                <span class="font-weight-bold text-subtitle-1">Cohorte {{ ip.period }}</span>
                                <span class="text--secondary text-caption ml-3">
                                    {{ ip.lxp_active }} activos / {{ ip.lxp_count }} matriculados
                                </span>
                            </v-col>
                            <v-col cols="auto" class="mr-6">
                                <v-chip
                                    x-small
                                    :color="dropoutColor(ip.dropout_rate)"
                                    dark
                                    class="ml-2"
                                >
                                    <v-icon x-small left>mdi-trending-down</v-icon>
                                    {{ ip.dropout_rate != null ? ip.dropout_rate + '% deserción' : 'Sin dato Odoo' }}
                                </v-chip>
                            </v-col>
                        </v-row>
                    </v-expansion-panel-header>

                    <v-expansion-panel-content class="pa-0">
                        <div class="timeline-scroll-wrap px-4 pb-5 pt-2" style="overflow-x:auto;">
                            <div class="timeline-row" style="display:flex; align-items:flex-start; gap:0; min-width:max-content;">

                                <!-- Stage: CRM/Odoo -->
                                <div class="stage-wrapper">
                                    <div class="stage-label text-caption text--secondary text-center mb-1">CRM / Odoo</div>
                                    <v-card outlined class="stage-card crm-card" style="border-color:#1976D2; min-width:120px;">
                                        <v-card-text class="text-center py-3 px-2">
                                            <v-icon color="#1976D2" class="mb-1">mdi-account-group</v-icon>
                                            <div class="text-h6 font-weight-bold" style="color:#1976D2;">
                                                {{ ip.odoo_count != null ? ip.odoo_count : '—' }}
                                            </div>
                                            <div class="text-caption text--secondary">Total</div>
                                            <v-divider class="my-1"></v-divider>
                                            <div class="d-flex justify-space-between text-caption mt-1">
                                                <span><v-icon x-small color="success">mdi-check-circle</v-icon> {{ ip.odoo_active != null ? ip.odoo_active : '—' }}</span>
                                                <span><v-icon x-small color="error">mdi-close-circle</v-icon> {{ ip.odoo_count != null && ip.odoo_active != null ? (ip.odoo_count - ip.odoo_active) : '—' }}</span>
                                            </div>
                                        </v-card-text>
                                    </v-card>
                                </div>

                                <!-- Flecha + dropout -->
                                <div class="arrow-wrapper" style="display:flex;flex-direction:column;align-items:center;justify-content:flex-end;padding-bottom:18px;min-width:60px;">
                                    <v-chip x-small :color="dropoutColor(dropoutBetween(ip.odoo_count, ip.lxp_count))" dark class="mb-1" style="font-size:10px;">
                                        {{ dropoutBetweenLabel(ip.odoo_count, ip.lxp_count) }}
                                    </v-chip>
                                    <v-icon color="grey">mdi-arrow-right</v-icon>
                                </div>

                                <!-- Stage: LXP (entrada) -->
                                <div class="stage-wrapper">
                                    <div class="stage-label text-caption text--secondary text-center mb-1">LXP Matrícula</div>
                                    <v-card outlined class="stage-card lxp-card" style="border-color:#388E3C; min-width:120px;">
                                        <v-card-text class="text-center py-3 px-2">
                                            <v-icon color="#388E3C" class="mb-1">mdi-school</v-icon>
                                            <div class="text-h6 font-weight-bold" style="color:#388E3C;">
                                                {{ ip.lxp_count }}
                                            </div>
                                            <div class="text-caption text--secondary">Total</div>
                                            <v-divider class="my-1"></v-divider>
                                            <div class="d-flex justify-space-between text-caption mt-1">
                                                <span><v-icon x-small color="success">mdi-check-circle</v-icon> {{ ip.lxp_active }}</span>
                                                <span><v-icon x-small color="error">mdi-close-circle</v-icon> {{ ip.lxp_count - ip.lxp_active }}</span>
                                            </div>
                                        </v-card-text>
                                    </v-card>
                                </div>

                                <!-- Flecha -->
                                <div class="arrow-wrapper" style="display:flex;flex-direction:column;align-items:center;justify-content:flex-end;padding-bottom:18px;min-width:40px;">
                                    <v-icon color="grey">mdi-arrow-right</v-icon>
                                </div>

                                <!-- LXP Académico: Cuatrimestres -->
                                <div class="stage-wrapper">
                                    <div class="stage-label text-caption text--secondary text-center mb-1">Progreso Académico</div>
                                    <div style="display:flex; align-items:flex-start; gap:6px;">

                                        <template v-for="(period, pIdx) in timelineData.curriculum">
                                            <!-- Flecha entre cuatrimestres -->
                                            <div v-if="pIdx > 0" class="arrow-wrapper" style="display:flex;align-items:center;justify-content:center;align-self:center;min-width:20px;">
                                                <v-icon small color="grey lighten-1">mdi-chevron-right</v-icon>
                                            </div>

                                            <!-- Cuatrimestre card -->
                                            <div :key="period.id">
                                                <v-card
                                                    outlined
                                                    class="quarter-card"
                                                    :style="{ borderColor: quarterColor(pIdx), minWidth: '120px' }"
                                                >
                                                    <v-card-title class="py-2 px-3 text-caption font-weight-bold justify-center"
                                                        :style="{ color: quarterColor(pIdx), background: quarterBg(pIdx) }">
                                                        {{ period.name }}
                                                    </v-card-title>

                                                    <v-card-text class="pa-2">
                                                        <!-- Conteo global del cuatrimestre -->
                                                        <div class="text-center mb-2">
                                                            <div class="text-h6 font-weight-bold" :style="{ color: quarterColor(pIdx) }">
                                                                {{ getLevelCount(ip, period.id, 'active') }}
                                                            </div>
                                                            <div class="text-caption text--secondary">
                                                                <v-icon x-small color="success">mdi-check-circle</v-icon> activos
                                                                &nbsp;
                                                                <v-icon x-small color="error">mdi-close-circle</v-icon> {{ getLevelCount(ip, period.id, 'inactive') }}
                                                            </div>
                                                        </div>

                                                        <!-- Bimestres -->
                                                        <div v-if="period.subperiods && period.subperiods.length > 0"
                                                             style="display:flex; gap:4px; justify-content:center;">
                                                            <v-card
                                                                v-for="sp in period.subperiods"
                                                                :key="sp.sp_id"
                                                                outlined
                                                                class="bimestre-card text-center pa-1"
                                                                :style="{ borderColor: quarterColor(pIdx) + '66', minWidth:'72px' }"
                                                            >
                                                                <div class="text-caption font-weight-medium" :style="{ color: quarterColor(pIdx) }">
                                                                    {{ sp.sp_name }}
                                                                </div>
                                                                <div class="text-subtitle-2 font-weight-bold mt-1" :style="{ color: quarterColor(pIdx) }">
                                                                    {{ getSubLevelCount(ip, sp.sp_id, 'active') || '—' }}
                                                                </div>
                                                                <div class="text-caption text--secondary" style="font-size:10px!important">
                                                                    <span style="color:#4CAF50">✓ {{ getSubLevelCount(ip, sp.sp_id, 'active') || 0 }}</span>
                                                                    &nbsp;
                                                                    <span style="color:#F44336">✗ {{ getSubLevelCount(ip, sp.sp_id, 'inactive') || 0 }}</span>
                                                                </div>
                                                            </v-card>
                                                        </div>
                                                        <!-- Si no hay bimestres definidos -->
                                                        <div v-else class="text-caption text--secondary text-center">
                                                            Sin bimestres
                                                        </div>
                                                    </v-card-text>
                                                </v-card>
                                            </div>
                                        </template>

                                        <!-- Flecha final -->
                                        <div class="arrow-wrapper" style="display:flex;align-items:center;justify-content:center;align-self:center;min-width:20px;">
                                            <v-icon small color="grey lighten-1">mdi-chevron-right</v-icon>
                                        </div>

                                        <!-- Graduando -->
                                        <div>
                                            <v-card outlined class="quarter-card text-center" style="border-color:#FFC107; min-width:90px; align-self:center;">
                                                <v-card-title class="py-2 px-3 text-caption font-weight-bold justify-center"
                                                    style="color:#F57F17; background:#FFF8E1;">
                                                    Graduando
                                                </v-card-title>
                                                <v-card-text class="pa-2 text-center">
                                                    <v-icon color="amber darken-2" class="mb-1">mdi-school-outline</v-icon>
                                                    <div class="text-h6 font-weight-bold amber--text text--darken-2">
                                                        {{ getGraduating(ip) }}
                                                    </div>
                                                </v-card-text>
                                            </v-card>
                                        </div>

                                    </div>
                                </div>

                            </div>
                        </div>

                        <!-- Resumen de tasas de deserción por cuatrimestre -->
                        <v-row class="px-4 pb-4" v-if="timelineData.curriculum && timelineData.curriculum.length > 1">
                            <v-col cols="12">
                                <div class="text-caption text--secondary mb-2 font-weight-medium">Tasa de deserción entre cuatrimestres:</div>
                                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                    <v-chip
                                        v-for="(rate, rIdx) in getQuarterDropoutRates(ip)"
                                        :key="rIdx"
                                        x-small
                                        :color="dropoutColor(rate.rate)"
                                        dark
                                    >
                                        Q{{ rIdx + 1 }}→Q{{ rIdx + 2 }}: {{ rate.rate != null ? rate.rate + '%' : '—' }}
                                    </v-chip>
                                </div>
                            </v-col>
                        </v-row>
                    </v-expansion-panel-content>
                </v-expansion-panel>
            </v-expansion-panels>

            <v-alert v-if="!timelineData.intake_periods || timelineData.intake_periods.length === 0"
                type="info" outlined class="mt-4">
                No se encontraron periodos de ingreso con datos para esta carrera.
                Asegúrate de que los estudiantes tengan el campo "Periodo de Ingreso" en su perfil.
            </v-alert>
        </template>
    </v-container>
    `,
    props: {
        careerId:  { type: [Number, String], default: 0 },
        wstoken:   { type: String, default: '' },
        wwwroot:   { type: String, default: '' },
        backUrl:   { type: String, default: 'student_timeline.php' },
    },
    data() {
        return {
            loading: true,
            timelineData: { career: null, curriculum: [], intake_periods: [] },
            openPanels: [],
            quarterColors: ['#388E3C', '#1976D2', '#F57C00', '#7B1FA2', '#0097A7', '#C62828'],
            quarterBgColors: ['#E8F5E9', '#E3F2FD', '#FFF3E0', '#F3E5F5', '#E0F7FA', '#FFEBEE'],
        };
    },
    computed: {
        generalDropoutRate() {
            if (!this.timelineData.intake_periods || this.timelineData.intake_periods.length === 0) return '—';
            let totalCrm = 0, totalActive = 0, valid = 0;
            this.timelineData.intake_periods.forEach(ip => {
                if (ip.odoo_count != null) {
                    totalCrm += ip.odoo_count;
                    totalActive += ip.lxp_active;
                    valid++;
                }
            });
            if (valid === 0 || totalCrm === 0) return '—';
            return Math.round(((totalCrm - totalActive) / totalCrm) * 100);
        },
    },
    created() {
        this.loadTimeline();
    },
    methods: {
        async loadTimeline() {
            this.loading = true;
            try {
                const res = await axios.post(
                    this.wwwroot + '/webservice/rest/server.php',
                    new URLSearchParams({
                        wstoken: this.wstoken,
                        wsfunction: 'local_grupomakro_get_student_career_timeline',
                        moodlewsrestformat: 'json',
                        learningplanid: this.careerId,
                    })
                );
                if (res.data && res.data.career) {
                    this.timelineData = res.data;
                    // Open all panels by default
                    this.openPanels = this.timelineData.intake_periods.map((_, i) => i);
                }
            } catch (e) {
                console.error('Error cargando timeline:', e);
            } finally {
                this.loading = false;
            }
        },

        quarterColor(idx) {
            return this.quarterColors[idx % this.quarterColors.length];
        },

        quarterBg(idx) {
            return this.quarterBgColors[idx % this.quarterBgColors.length];
        },

        dropoutColor(rate) {
            if (rate == null) return 'grey';
            if (rate >= 20) return 'error';
            if (rate >= 10) return 'warning';
            return 'success';
        },

        dropoutBetween(countA, countB) {
            if (countA == null || countA === 0) return null;
            return Math.round(((countA - countB) / countA) * 100);
        },

        dropoutBetweenLabel(countA, countB) {
            const r = this.dropoutBetween(countA, countB);
            return r != null ? '-' + r + '%' : '—';
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
            if (!this.timelineData.curriculum || this.timelineData.curriculum.length === 0) return '—';
            const lastPeriod = this.timelineData.curriculum[this.timelineData.curriculum.length - 1];
            return this.getLevelCount(ip, lastPeriod.id, 'active') || '—';
        },

        getQuarterDropoutRates(ip) {
            const curriculum = this.timelineData.curriculum;
            if (!curriculum || curriculum.length < 2) return [];
            const rates = [];
            for (let i = 0; i < curriculum.length - 1; i++) {
                const countA = this.getLevelCount(ip, curriculum[i].id, 'active')
                             + this.getLevelCount(ip, curriculum[i].id, 'inactive');
                const countB = this.getLevelCount(ip, curriculum[i + 1].id, 'active')
                             + this.getLevelCount(ip, curriculum[i + 1].id, 'inactive');
                if (countA === 0) {
                    rates.push({ rate: null });
                } else {
                    rates.push({ rate: Math.round(((countA - countB) / countA) * 100) });
                }
            }
            return rates;
        },
    },
});
