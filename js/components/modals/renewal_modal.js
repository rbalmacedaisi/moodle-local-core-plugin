/**
 * RENEWAL MODAL (FASE 2)
 * Modal para renovar el periodo académico de los estudiantes de una cohorte.
 *
 * Reglas AUTOMÁTICAS (no se pide elegir al usuario):
 *  - Estudiantes en B1 de C[n] -> pasan a B2 de C[n]. TODOS (activos + inactivos).
 *  - Estudiantes en B2 de C[n] (con C[n] != último) -> pasan a B1 de C[n+1]. SOLO ACTIVOS.
 *  - Estudiantes en B2 del último C -> graduando (currentperiodid y currentsubperiodid = NULL). SOLO ACTIVOS.
 *  - Inactivos en B2 -> quedan donde están (cálculo de deserción).
 *  - Si un graduando tiene asignaturas pendientes (excluyendo práctica profesional) -> se marca con warning en rojo.
 *
 * Flujo:
 *  1. Al abrirse, pide al backend get_period_renewal_preview
 *  2. Muestra resumen + 4 listas (siguiente bimestre, siguiente cuatrimestre, graduando OK, graduando con warning, inactivos que se quedan)
 *  3. Botón "Confirmar y ejecutar" llama execute_period_renewal
 *  4. Si hay warnings de graduandos con pendientes, exige confirm_warnings=true
 *
 * Endpoints Moodle:
 *   - local_grupomakro_get_period_renewal_preview
 *   - local_grupomakro_execute_period_renewal
 *
 * API:
 *   <renewal-modal
 *     v-if="showModal"
 *     :cohort="'2026'"
 *     :learning-plan-id="2"
 *     :career-name="'Lic. en Enfermería'"
 *     @close="close"
 *     @done="onDone">
 *   </renewal-modal>
 */

Vue.component('renewal-modal', {
    props: {
        cohort:         { type: String, required: true },
        learningPlanId: { type: [String, Number], required: true },
        careerName:     { type: String, default: 'Carrera' },
    },
    data() {
        return {
            loading:        false,
            executing:      false,
            error:          '',
            preview:        null,    // response from get_period_renewal_preview
            requiresConfirm: false,  // if there are graduates with pending courses
            confirmWarnings: false,  // user has ticked the "accept warnings" checkbox
            expandedSections: {
                next_bim: true,
                next_cuatri: true,
                graduate_ok: true,
                graduate_warn: true,
                stays_inactive: false,
                skipped: false,
            },
        };
    },
    computed: {
        summary() { return this.preview && this.preview.summary ? this.preview.summary : null; },
        hasAnyAction() {
            if (!this.summary) return false;
            return (this.summary.to_next_bim_count + this.summary.to_next_cuatri_count +
                    this.summary.to_graduate_ok_count + this.summary.to_graduate_warn_count) > 0;
        },
        hasWarnings() {
            return this.summary && this.summary.to_graduate_warn_count > 0;
        },
        isEmpty() {
            if (!this.summary) return false;
            return !this.hasAnyAction &&
                   this.summary.stays_inactive_count === 0 &&
                   this.summary.skipped_count === 0;
        },
    },
    watch: {
        // When warnings show up, uncheck confirm checkbox
        hasWarnings(val) {
            if (val) this.confirmWarnings = false;
        }
    },
    mounted() {
        this.fetchPreview();
    },
    methods: {
        async fetchPreview() {
            this.loading = true;
            this.error = '';
            try {
                const params = {
                    wstoken: userToken,
                    wsfunction: 'local_grupomakro_get_period_renewal_preview',
                    moodlewsrestformat: 'json',
                    learningplanid: this.learningPlanId,
                };
                if (this.cohort) {
                    params['intake_period'] = this.cohort;
                }
                const resp = await axios.get(M.cfg.wwwroot + '/webservice/rest/server.php', { params });
                const data = resp.data;
                if (data && data.exception) {
                    this.error = data.message || 'Error al obtener la vista previa';
                } else {
                    this.preview = data;
                }
            } catch (e) {
                this.error = 'Error de red: ' + (e.message || e);
            }
            this.loading = false;
        },
        async executeRenewal() {
            if (this.executing) return;
            if (this.hasWarnings && !this.confirmWarnings) {
                this.requiresConfirm = true;
                return;
            }
            if (!confirm('¿Confirma la renovación de periodo para esta cohorte? Esta acción no se puede deshacer.')) {
                return;
            }
            this.executing = true;
            this.error = '';
            try {
                const params = {
                    wstoken: userToken,
                    wsfunction: 'local_grupomakro_execute_period_renewal',
                    moodlewsrestformat: 'json',
                    learningplanid: this.learningPlanId,
                };
                if (this.cohort) {
                    params['intake_period'] = this.cohort;
                }
                if (this.confirmWarnings) {
                    params['confirm_warnings'] = 1;
                }
                const resp = await axios.get(M.cfg.wwwroot + '/webservice/rest/server.php', { params });
                const data = resp.data;
                if (data && data.exception) {
                    this.error = data.message || 'Error al ejecutar la renovación';
                } else if (data && data.requires_confirmation) {
                    this.requiresConfirm = true;
                    this.error = data.message || 'Debe confirmar las advertencias';
                } else {
                    this.$emit('done', data);
                }
            } catch (e) {
                this.error = 'Error de red: ' + (e.message || e);
            }
            this.executing = false;
        },
        toggleSection(key) {
            this.expandedSections[key] = !this.expandedSections[key];
        },
        closeModal() {
            this.$emit('close');
        },
    },
    template: `
    <v-dialog :value="true" persistent max-width="920" scrollable>
    <v-card class="tl-modal tl-renewal-modal" tile>
            <!-- HEADER -->
            <div class="tl-modal-head">
                <div class="tl-modal-head-info tl-modal-header-titles">
                    <div>
                        <div class="tl-modal-eyebrow">FASE 2 · Renovación de periodo</div>
                        <h2 class="tl-modal-title">
                            <v-icon color="#10B981" size="22">mdi-calendar-refresh-outline</v-icon>
                            Renovar periodo · cohorte {{ cohort || '(todas)' }}
                        </h2>
                        <div class="tl-modal-subtitle">{{ careerName }}</div>
                    </div>
                </div>
                <button class="tl-modal-close" @click="closeModal" aria-label="Cerrar">
                    <v-icon size="20" color="#64748B">mdi-close</v-icon>
                </button>
            </div>

            <!-- BODY -->
            <div class="tl-modal-body">
                <!-- LOADING -->
                <div v-if="loading" class="tl-modal-loading">
                    <div class="tl-spinner"></div>
                    <span>Calculando destinos para los estudiantes…</span>
                </div>

                <!-- ERROR -->
                <div v-if="error" class="tl-modal-error">
                    <v-icon size="18" color="#EF4444">mdi-alert-circle</v-icon>
                    <span>{{ error }}</span>
                </div>

                <!-- NO DATA -->
                <div v-if="!loading && !error && isEmpty" class="tl-modal-empty">
                    <v-icon size="48" color="#94A3B8">mdi-account-off-outline</v-icon>
                    <h3>Sin estudiantes para renovar</h3>
                    <p>No se encontraron estudiantes activos en esta cohorte que requieran promoción.</p>
                </div>

                <!-- CONTENT -->
                <div v-if="!loading && !error && preview && hasAnyAction" class="tl-renewal-content">
                    <!-- RULES EXPLANATION -->
                    <div class="tl-renewal-rules">
                        <div class="tl-renewal-rule">
                            <v-icon size="16" color="#3B82F6">mdi-arrow-right-bold-circle</v-icon>
                            <span>Estudiantes en <strong>B1</strong> pasan a <strong>B2</strong> del mismo periodo (todos).</span>
                        </div>
                        <div class="tl-renewal-rule">
                            <v-icon size="16" color="#3B82F6">mdi-arrow-right-bold-circle</v-icon>
                            <span>Estudiantes en <strong>B2</strong> pasan al <strong>siguiente cuatrimestre B1</strong> (solo activos).</span>
                        </div>
                        <div class="tl-renewal-rule">
                            <v-icon size="16" color="#10B981">mdi-school</v-icon>
                            <span>Estudiantes en <strong>B2 del último cuatrimestre</strong> pasan a <strong>graduando</strong> (solo activos).</span>
                        </div>
                        <div class="tl-renewal-rule">
                            <v-icon size="16" color="#F59E0B">mdi-account-clock-outline</v-icon>
                            <span>Inactivos <strong>se quedan</strong> en su periodo para cálculo de deserción.</span>
                        </div>
                    </div>

                    <!-- SUMMARY -->
                    <div class="tl-renewal-summary">
                        <div class="tl-renewal-stat tl-stat-blue">
                            <v-icon size="22" color="#3B82F6">mdi-skip-next</v-icon>
                            <strong>{{ summary.to_next_bim_count }}</strong>
                            <span>siguiente bimestre</span>
                        </div>
                        <div class="tl-renewal-stat tl-stat-indigo">
                            <v-icon size="22" color="#6366F1">mdi-skip-forward</v-icon>
                            <strong>{{ summary.to_next_cuatri_count }}</strong>
                            <span>siguiente cuatrimestre</span>
                        </div>
                        <div class="tl-renewal-stat tl-stat-green">
                            <v-icon size="22" color="#10B981">mdi-school</v-icon>
                            <strong>{{ summary.to_graduate_ok_count }}</strong>
                            <span>graduando (sin pendientes)</span>
                        </div>
                        <div class="tl-renewal-stat tl-stat-red" v-if="summary.to_graduate_warn_count > 0">
                            <v-icon size="22" color="#EF4444">mdi-alert-octagon</v-icon>
                            <strong>{{ summary.to_graduate_warn_count }}</strong>
                            <span>graduando con pendientes</span>
                        </div>
                        <div class="tl-renewal-stat tl-stat-amber" v-if="summary.stays_inactive_count > 0">
                            <v-icon size="22" color="#F59E0B">mdi-account-clock-outline</v-icon>
                            <strong>{{ summary.stays_inactive_count }}</strong>
                            <span>inactivos (se quedan)</span>
                        </div>
                        <div class="tl-renewal-stat tl-stat-muted" v-if="summary.skipped_count > 0">
                            <v-icon size="22" color="#94A3B8">mdi-help-circle-outline</v-icon>
                            <strong>{{ summary.skipped_count }}</strong>
                            <span>omitidos</span>
                        </div>
                    </div>

                    <!-- WARNINGS BANNER -->
                    <div v-if="hasWarnings" class="tl-renewal-warn-banner">
                        <div class="tl-warn-banner-head">
                            <v-icon size="22" color="#EF4444">mdi-alert-octagon</v-icon>
                            <div>
                                <strong>Atención: {{ summary.to_graduate_warn_count }} graduando(s) con asignaturas pendientes</strong>
                                <p>Estos estudiantes serán marcados como "graduando" aunque tengan cursos sin aprobar (excluyendo práctica profesional). Revíselos antes de continuar.</p>
                            </div>
                        </div>
                    </div>

                    <!-- SECTIONS (collapsible) -->
                    <div class="tl-renewal-sections">
                        <!-- Next bimestre -->
                        <div v-if="summary.to_next_bim_count > 0" class="tl-renewal-section tl-section-blue">
                            <div class="tl-renewal-section-head" @click="toggleSection('next_bim')">
                                <v-icon size="18" color="#3B82F6">mdi-skip-next</v-icon>
                                <strong>Siguiente bimestre ({{ summary.to_next_bim_count }})</strong>
                                <v-icon size="16" color="#94A3B8">{{ expandedSections.next_bim ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                            </div>
                            <div v-show="expandedSections.next_bim" class="tl-renewal-section-body">
                                <div v-for="s in preview.to_next_bim" :key="'bim-' + s.userid" class="tl-renewal-row">
                                    <div class="tl-renewal-row-name">{{ s.name }}</div>
                                    <div class="tl-renewal-row-meta">
                                        <span class="tl-pill tl-pill-blue">{{ s.current_subperiod }} → {{ s.next_subperiod }}</span>
                                        <span v-if="s.status === 'inactivo'" class="tl-pill tl-pill-amber">inactivo</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Next cuatrimestre -->
                        <div v-if="summary.to_next_cuatri_count > 0" class="tl-renewal-section tl-section-indigo">
                            <div class="tl-renewal-section-head" @click="toggleSection('next_cuatri')">
                                <v-icon size="18" color="#6366F1">mdi-skip-forward</v-icon>
                                <strong>Siguiente cuatrimestre ({{ summary.to_next_cuatri_count }})</strong>
                                <v-icon size="16" color="#94A3B8">{{ expandedSections.next_cuatri ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                            </div>
                            <div v-show="expandedSections.next_cuatri" class="tl-renewal-section-body">
                                <div v-for="s in preview.to_next_cuatri" :key="'cua-' + s.userid" class="tl-renewal-row">
                                    <div class="tl-renewal-row-name">{{ s.name }}</div>
                                    <div class="tl-renewal-row-meta">
                                        <span class="tl-pill tl-pill-indigo">{{ s.current_period }} → {{ s.next_period }} · {{ s.next_subperiod }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Graduate OK -->
                        <div v-if="summary.to_graduate_ok_count > 0" class="tl-renewal-section tl-section-green">
                            <div class="tl-renewal-section-head" @click="toggleSection('graduate_ok')">
                                <v-icon size="18" color="#10B981">mdi-school</v-icon>
                                <strong>Graduando sin pendientes ({{ summary.to_graduate_ok_count }})</strong>
                                <v-icon size="16" color="#94A3B8">{{ expandedSections.graduate_ok ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                            </div>
                            <div v-show="expandedSections.graduate_ok" class="tl-renewal-section-body">
                                <div v-for="s in preview.to_graduate_ok" :key="'gok-' + s.userid" class="tl-renewal-row">
                                    <div class="tl-renewal-row-name">{{ s.name }}</div>
                                    <div class="tl-renewal-row-meta">
                                        <span class="tl-pill tl-pill-green">→ graduando</span>
                                        <span class="tl-pill tl-pill-muted">{{ s.period }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Graduate WARN (highlighted in red) -->
                        <div v-if="summary.to_graduate_warn_count > 0" class="tl-renewal-section tl-section-red">
                            <div class="tl-renewal-section-head" @click="toggleSection('graduate_warn')">
                                <v-icon size="18" color="#EF4444">mdi-alert-octagon</v-icon>
                                <strong>Graduando CON PENDIENTES ({{ summary.to_graduate_warn_count }})</strong>
                                <v-icon size="16" color="#94A3B8">{{ expandedSections.graduate_warn ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                            </div>
                            <div v-show="expandedSections.graduate_warn" class="tl-renewal-section-body">
                                <div v-for="s in preview.to_graduate_warn" :key="'gwarn-' + s.userid" class="tl-renewal-row tl-renewal-row-warn">
                                    <div class="tl-renewal-row-name">
                                        <v-icon size="14" color="#EF4444">mdi-alert</v-icon>
                                        {{ s.name }}
                                    </div>
                                    <div class="tl-renewal-row-meta">
                                        <span class="tl-pill tl-pill-red">→ graduando</span>
                                        <div class="tl-pending-courses">
                                            <span class="tl-pending-label">Pendientes:</span>
                                            <span v-for="(c, i) in s.pending_courses" :key="'pc-' + s.userid + '-' + i" class="tl-pill tl-pill-warn">
                                                {{ c.name }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Stays inactive (collapsed by default) -->
                        <div v-if="summary.stays_inactive_count > 0" class="tl-renewal-section tl-section-amber">
                            <div class="tl-renewal-section-head" @click="toggleSection('stays_inactive')">
                                <v-icon size="18" color="#F59E0B">mdi-account-clock-outline</v-icon>
                                <strong>Inactivos que se quedan ({{ summary.stays_inactive_count }})</strong>
                                <v-icon size="16" color="#94A3B8">{{ expandedSections.stays_inactive ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                            </div>
                            <div v-show="expandedSections.stays_inactive" class="tl-renewal-section-body">
                                <div v-for="s in preview.stays_inactive" :key="'st-' + s.userid" class="tl-renewal-row">
                                    <div class="tl-renewal-row-name">{{ s.name }}</div>
                                    <div class="tl-renewal-row-meta">
                                        <span class="tl-pill tl-pill-amber">{{ s.period }} · {{ s.subperiod }}</span>
                                        <span class="tl-pill tl-pill-muted">{{ s.reason }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Skipped (collapsed by default) -->
                        <div v-if="summary.skipped_count > 0" class="tl-renewal-section tl-section-muted">
                            <div class="tl-renewal-section-head" @click="toggleSection('skipped')">
                                <v-icon size="18" color="#94A3B8">mdi-help-circle-outline</v-icon>
                                <strong>Omitidos ({{ summary.skipped_count }})</strong>
                                <v-icon size="16" color="#94A3B8">{{ expandedSections.skipped ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                            </div>
                            <div v-show="expandedSections.skipped" class="tl-renewal-section-body">
                                <div v-for="s in preview.skipped" :key="'sk-' + s.userid" class="tl-renewal-row">
                                    <div class="tl-renewal-row-name">{{ s.name }}</div>
                                    <div class="tl-renewal-row-meta">
                                        <span class="tl-pill tl-pill-muted">{{ s.reason }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- CONFIRM CHECKBOX (only when warnings) -->
                    <div v-if="hasWarnings" class="tl-renewal-confirm-check">
                        <label>
                            <input type="checkbox" v-model="confirmWarnings">
                            <span>Confirmo que he revisado los graduandos con asignaturas pendientes y deseo continuar con la renovación.</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- FOOTER -->
            <div class="tl-modal-foot">
                <button class="tl-btn tl-btn-ghost" @click="closeModal" :disabled="executing">Cancelar</button>
                <button
                    class="tl-btn tl-btn-primary"
                    @click="executeRenewal"
                    :disabled="loading || executing || !preview || !hasAnyAction || (hasWarnings && !confirmWarnings)"
                >
                    <v-icon size="18" color="white">mdi-calendar-refresh-outline</v-icon>
                    {{ executing ? 'Renovando…' : 'Confirmar y renovar' }}
                </button>
            </div>
    </v-card>
    </v-dialog>
    `
});
