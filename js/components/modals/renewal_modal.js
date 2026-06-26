/**
 * RENEWAL MODAL (FASE 2)
 *
 * Modal para renovar el periodo académico de los estudiantes de una cohorte.
 *
 * Reglas AUTOMÁTICAS (no se pide elegir al usuario):
 *  - Estudiantes en B1 de C[n] -> pasan a B2 de C[n]. TODOS (activos + inactivos).
 *  - Estudiantes en B2 de C[n] (con C[n] != último) -> pasan a B1 de C[n+1]. SOLO ACTIVOS.
 *  - Estudiantes en B2 del último C -> graduando (status='egresado'). SOLO ACTIVOS.
 *  - Inactivos en B2 -> quedan donde están (cálculo de deserción).
 *  - Si un graduando tiene asignaturas pendientes (excluyendo práctica profesional) -> se marca con warning en rojo.
 *
 * **Importante**: el periodo_ingreso (cohorte) NO se modifica durante la
 * renovación. Solo cambian currentperiodid / currentsubperiodid y, en
 * el caso de los graduandos, el status. La cohorte es identidad del
 * estudiante y solo se cambia explícitamente desde el modal de
 * "Reclasificar periodo lectivo".
 *
 * Selección:
 *  - El usuario puede marcar/desmarcar estudiantes individualmente o
 *    seleccionar/deseleccionar secciones enteras (checkbox tri-state
 *    en el header de cada sección).
 *  - "Seleccionar visibles" / "Deseleccionar" arriba para lote.
 *  - El botón confirmar solo se habilita si hay al menos 1 estudiante
 *    seleccionado.
 *
 * Flujo:
 *  1. Al abrirse, pide al backend get_period_renewal_preview
 *  2. Muestra resumen + 4 listas (siguiente bimestre, siguiente cuatrimestre, graduando OK, graduando con warning)
 *  3. Usuario selecciona quiénes renovar
 *  4. Botón "Confirmar y ejecutar" llama execute_period_renewal con
 *     userids = Array.from(selectedUserIds)
 *  5. Si hay warnings de graduandos con pendientes, exige confirm_warnings=true
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
            selectedUserIds: new Set(),  // students the user wants to process
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
        // The WS endpoint URL. Defined as computed so Vue auto-invokes the
        // getter when this.wsUrl is accessed (returning the string). If it
        // were a method, this.wsUrl would return the function reference,
        // producing the malformed URL ".../function () { [native code] }".
        wsUrl() { return window.location.origin + '/webservice/rest/server.php'; },
        summary() { return this.preview && this.preview.summary ? this.preview.summary : null; },
        // Flat list of all students that the preview surfaced, paired
        // with their destination section key. Used by the checkbox
        // helpers below.
        allPreviewStudents() {
            if (!this.preview) return [];
            const sections = [
                ['next_bim',       this.preview.to_next_bim       || []],
                ['next_cuatri',    this.preview.to_next_cuatri    || []],
                ['graduate_ok',    this.preview.to_graduate_ok    || []],
                ['graduate_warn',  this.preview.to_graduate_warn  || []],
                ['stays_inactive', this.preview.stays_inactive     || []],
                ['skipped',        this.preview.skipped           || []],
            ];
            const out = [];
            for (const [key, list] of sections) {
                for (const s of list) {
                    if (s && s.userid) {
                        out.push({ section: key, userid: parseInt(s.userid, 10) });
                    }
                }
            }
            return out;
        },
        // Only the action-bearing students (B1->B2, B2->C+1, graduate)
        // are eligible for selection. stays_inactive and skipped are
        // always processed (or always ignored) by the backend so the
        // user shouldn't be able to include/exclude them.
        eligiblePreviewStudents() {
            return this.allPreviewStudents.filter(x =>
                x.section === 'next_bim' ||
                x.section === 'next_cuatri' ||
                x.section === 'graduate_ok' ||
                x.section === 'graduate_warn'
            );
        },
        selectedCount() { return this.selectedUserIds.size; },
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
        canConfirm() {
            if (this.loading || this.executing) return false;
            if (!this.preview || !this.hasAnyAction) return false;
            if (this.selectedCount === 0) return false;
            if (this.hasWarnings && !this.confirmWarnings) return false;
            return true;
        },
        allEligibleSelected() {
            const eligible = this.eligiblePreviewStudents;
            if (eligible.length === 0) return false;
            for (const x of eligible) {
                if (!this.selectedUserIds.has(x.userid)) return false;
            }
            return true;
        },
        someEligibleSelected() {
            return this.selectedCount > 0 && !this.allEligibleSelected;
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
        buildWsUrl(wsfunction, extraParams) {
            const params = new URLSearchParams();
            params.set('wstoken', userToken);
            params.set('wsfunction', wsfunction);
            params.set('moodlewsrestformat', 'json');
            if (extraParams) {
                for (const key of Object.keys(extraParams)) {
                    const v = extraParams[key];
                    if (Array.isArray(v)) {
                        // Moodle's REST parser requires the `[]` suffix
                        // on repeated params to populate external_multiple_structure
                        // as an array. Without it, a single-element array like
                        // userids=2284 is parsed as the string "2284" and the
                        // WS rejects it with "Only arrays accepted".
                        for (const item of v) {
                            params.append(key + '[]', String(item));
                        }
                    } else if (v !== undefined && v !== null) {
                        params.set(key, String(v));
                    }
                }
            }
            return this.wsUrl + '?' + params.toString();
        },
        setError(msg, e) {
            this.error = msg;
            console.error('[renewal]', msg);
            if (e) {
                console.error('[renewal] error object:', e);
                if (e.stack) console.error('[renewal] stack:', e.stack);
            }
        },
        // --- selection helpers ---
        isUserSelected(userid) {
            return this.selectedUserIds.has(parseInt(userid, 10));
        },
        toggleUser(userid) {
            const id = parseInt(userid, 10);
            if (this.selectedUserIds.has(id)) {
                this.selectedUserIds.delete(id);
            } else {
                this.selectedUserIds.add(id);
            }
            // Force Vue to pick up the change.
            this.selectedUserIds = new Set(this.selectedUserIds);
        },
        // Returns the list of userids in a given section.
        sectionUserids(sectionKey) {
            if (!this.preview) return [];
            const list = this.preview[this._sectionKeyToPreviewKey(sectionKey)] || [];
            return list.map(s => parseInt(s.userid, 10)).filter(Boolean);
        },
        // Maps UI section key to the key used in the preview payload.
        _sectionKeyToPreviewKey(sectionKey) {
            const map = {
                'next_bim':       'to_next_bim',
                'next_cuatri':    'to_next_cuatri',
                'graduate_ok':    'to_graduate_ok',
                'graduate_warn':  'to_graduate_warn',
                'stays_inactive': 'stays_inactive',
                'skipped':        'skipped',
            };
            return map[sectionKey] || sectionKey;
        },
        // Section-level selection: 'none' | 'some' | 'all'
        sectionSelectionState(sectionKey) {
            const ids = this.sectionUserids(sectionKey);
            if (ids.length === 0) return 'none';
            let selected = 0;
            for (const id of ids) {
                if (this.selectedUserIds.has(id)) selected++;
            }
            if (selected === 0)        return 'none';
            if (selected === ids.length) return 'all';
            return 'some';
        },
        toggleSection(sectionKey) {
            const state = this.sectionSelectionState(sectionKey);
            const ids = this.sectionUserids(sectionKey);
            if (state === 'all') {
                for (const id of ids) this.selectedUserIds.delete(id);
            } else {
                for (const id of ids) this.selectedUserIds.add(id);
            }
            this.selectedUserIds = new Set(this.selectedUserIds);
        },
        selectAllEligible() {
            for (const x of this.eligiblePreviewStudents) {
                this.selectedUserIds.add(x.userid);
            }
            this.selectedUserIds = new Set(this.selectedUserIds);
        },
        deselectAll() {
            this.selectedUserIds = new Set();
        },
        // --- API calls ---
        async fetchPreview() {
            this.loading = true;
            this.error = '';
            try {
                const url = this.buildWsUrl('local_grupomakro_get_period_renewal_preview', {
                    learningplanid: this.learningPlanId,
                    intake_period:  this.cohort,
                });
                const resp = await fetch(url, { credentials: 'same-origin' });
                if (!resp.ok) {
                    this.setError('[2] HTTP ' + resp.status + ' ' + resp.statusText);
                    this.loading = false;
                    return;
                }
                const data = await resp.json();
                if (data && data.exception) {
                    this.setError('[4] API: ' + (data.message || data.errorcode || 'exception'));
                    this.loading = false;
                    return;
                }
                this.preview = data;
                // Default: select ALL eligible students so the user can
                // just confirm. They can deselect if they want.
                this.selectedUserIds = new Set();
                this.selectAllEligible();
                this.loading = false;
            } catch (e) {
                this.setError('[1] fetch: ' + (e.message || String(e)), e);
                this.loading = false;
            }
        },
        async executeRenewal() {
            if (!this.canConfirm) return;
            if (this.hasWarnings && !this.confirmWarnings) {
                this.requiresConfirm = true;
                return;
            }
            const userids = Array.from(this.selectedUserIds);
            if (!confirm(
                '¿Confirma la renovación de periodo para ' + userids.length +
                ' estudiante(s)?\n\nEl cohorte (periodo_ingreso) NO se modifica.\n\nEsta acción no se puede deshacer.'
            )) {
                return;
            }
            this.executing = true;
            this.error = '';
            try {
                // Put EVERYTHING in the URL via buildWsUrl so arrays
                // (userids) are emitted as repeated params
                // (userids=1&userids=2&userids=3) which Moodle's REST
                // parser reconstructs into external_multiple_structure.
                // The previous POST-body approach only sent a flat
                // object and dropped the userids array entirely.
                const url = this.buildWsUrl('local_grupomakro_execute_period_renewal', {
                    learningplanid: this.learningPlanId,
                    intake_period:  this.cohort,
                    confirm_warnings: this.confirmWarnings ? 1 : 0,
                    userids:         userids,
                });
                const resp = await fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                });
                if (!resp.ok) {
                    this.setError('[2] HTTP ' + resp.status + ' ' + resp.statusText);
                    this.executing = false;
                    return;
                }
                const data = await resp.json();
                if (data && data.exception) {
                    this.setError('[4] API: ' + (data.message || data.errorcode || 'exception'));
                    this.executing = false;
                    return;
                }
                if (data && data.requires_confirmation) {
                    this.requiresConfirm = true;
                    this.error = data.message || 'Debe confirmar las advertencias';
                    this.executing = false;
                    return;
                }
                this.$emit('done', data);
                this.executing = false;
            } catch (e) {
                this.setError('[1] fetch: ' + (e.message || String(e)), e);
                this.executing = false;
            }
        },
        toggleExpand(key) {
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
                    <!-- SELECTION BAR -->
                    <div class="tl-renewal-selection-bar">
                        <div class="tl-renewal-selection-info">
                            <v-icon size="16" color="#10B981">mdi-check-circle-outline</v-icon>
                            <strong>{{ selectedCount }}</strong>
                            <span>de {{ eligiblePreviewStudents.length }} estudiante(s) elegible(s) seleccionado(s)</span>
                        </div>
                        <div class="tl-renewal-selection-actions">
                            <button
                                class="tl-btn tl-btn-ghost tl-btn-sm"
                                @click="selectAllEligible"
                                :disabled="allEligibleSelected">
                                <v-icon size="14" color="#6366F1">mdi-checkbox-marked-outline</v-icon>
                                Seleccionar todos
                            </button>
                            <button
                                class="tl-btn tl-btn-ghost tl-btn-sm"
                                @click="deselectAll"
                                :disabled="selectedCount === 0">
                                <v-icon size="14" color="#94A3B8">mdi-checkbox-blank-outline</v-icon>
                                Deseleccionar
                            </button>
                        </div>
                    </div>

                    <!-- RULES EXPLANATION -->
                    <div class="tl-renewal-rules">
                        <div class="tl-renewal-rule">
                            <v-icon size="16" color="#3B82F6">mdi-arrow-right-bold-circle</v-icon>
                            <span>Estudiantes en <strong>B1</strong> pasan a <strong>B2</strong> del mismo periodo (todos).</span>
                        </div>
                        <div class="tl-renewal-rule">
                            <v-icon size="16" color="#3B82F6">mdi-arrow-right-bold-circle</v-icon>
                            <span>Estudiantes en <strong>B2</strong> activos pasan al <strong>siguiente cuatrimestre B1</strong>. El cohorte <strong>no se modifica</strong>.</span>
                        </div>
                        <div class="tl-renewal-rule">
                            <v-icon size="16" color="#10B981">mdi-school</v-icon>
                            <span>Estudiantes en <strong>B2 del último cuatrimestre</strong> activos pasan a <strong>egresado</strong>.</span>
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
                                <p>Estos estudiantes serán marcados como "egresado" aunque tengan cursos sin aprobar (excluyendo práctica profesional). Revíselos antes de continuar.</p>
                            </div>
                        </div>
                    </div>

                    <!-- SECTIONS (collapsible) -->
                    <div class="tl-renewal-sections">
                        <!-- Next bimestre -->
                        <div v-if="summary.to_next_bim_count > 0" class="tl-renewal-section tl-section-blue">
                            <div class="tl-renewal-section-head" @click="toggleExpand('next_bim')">
                                <label class="tl-section-checkbox" @click.stop>
                                    <input
                                        type="checkbox"
                                        :checked="sectionSelectionState('next_bim') === 'all'"
                                        :indeterminate.prop="sectionSelectionState('next_bim') === 'some'"
                                        @change="toggleSection('next_bim')" />
                                </label>
                                <v-icon size="18" color="#3B82F6">mdi-skip-next</v-icon>
                                <strong>Siguiente bimestre ({{ summary.to_next_bim_count }})</strong>
                                <span class="tl-section-selected-pill" v-if="sectionSelectionState('next_bim') !== 'none'">
                                    {{ sectionUserids('next_bim').filter(id => selectedUserIds.has(id)).length }} seleccionados
                                </span>
                                <v-icon size="16" color="#94A3B8">{{ expandedSections.next_bim ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                            </div>
                            <div v-show="expandedSections.next_bim" class="tl-renewal-section-body">
                                <div v-for="s in preview.to_next_bim" :key="'bim-' + s.userid" class="tl-renewal-row" :class="{ 'tl-renewal-row-selected': isUserSelected(s.userid) }">
                                    <label class="tl-row-checkbox" @click.stop>
                                        <input
                                            type="checkbox"
                                            :checked="isUserSelected(s.userid)"
                                            @change="toggleUser(s.userid)" />
                                    </label>
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
                            <div class="tl-renewal-section-head" @click="toggleExpand('next_cuatri')">
                                <label class="tl-section-checkbox" @click.stop>
                                    <input
                                        type="checkbox"
                                        :checked="sectionSelectionState('next_cuatri') === 'all'"
                                        :indeterminate.prop="sectionSelectionState('next_cuatri') === 'some'"
                                        @change="toggleSection('next_cuatri')" />
                                </label>
                                <v-icon size="18" color="#6366F1">mdi-skip-forward</v-icon>
                                <strong>Siguiente cuatrimestre ({{ summary.to_next_cuatri_count }})</strong>
                                <span class="tl-section-selected-pill" v-if="sectionSelectionState('next_cuatri') !== 'none'">
                                    {{ sectionUserids('next_cuatri').filter(id => selectedUserIds.has(id)).length }} seleccionados
                                </span>
                                <v-icon size="16" color="#94A3B8">{{ expandedSections.next_cuatri ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                            </div>
                            <div v-show="expandedSections.next_cuatri" class="tl-renewal-section-body">
                                <div v-for="s in preview.to_next_cuatri" :key="'cua-' + s.userid" class="tl-renewal-row" :class="{ 'tl-renewal-row-selected': isUserSelected(s.userid) }">
                                    <label class="tl-row-checkbox" @click.stop>
                                        <input
                                            type="checkbox"
                                            :checked="isUserSelected(s.userid)"
                                            @change="toggleUser(s.userid)" />
                                    </label>
                                    <div class="tl-renewal-row-name">{{ s.name }}</div>
                                    <div class="tl-renewal-row-meta">
                                        <span class="tl-pill tl-pill-indigo">{{ s.current_period }} → {{ s.next_period }} · {{ s.next_subperiod }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Graduate OK -->
                        <div v-if="summary.to_graduate_ok_count > 0" class="tl-renewal-section tl-section-green">
                            <div class="tl-renewal-section-head" @click="toggleExpand('graduate_ok')">
                                <label class="tl-section-checkbox" @click.stop>
                                    <input
                                        type="checkbox"
                                        :checked="sectionSelectionState('graduate_ok') === 'all'"
                                        :indeterminate.prop="sectionSelectionState('graduate_ok') === 'some'"
                                        @change="toggleSection('graduate_ok')" />
                                </label>
                                <v-icon size="18" color="#10B981">mdi-school</v-icon>
                                <strong>Graduando sin pendientes ({{ summary.to_graduate_ok_count }})</strong>
                                <span class="tl-section-selected-pill" v-if="sectionSelectionState('graduate_ok') !== 'none'">
                                    {{ sectionUserids('graduate_ok').filter(id => selectedUserIds.has(id)).length }} seleccionados
                                </span>
                                <v-icon size="16" color="#94A3B8">{{ expandedSections.graduate_ok ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                            </div>
                            <div v-show="expandedSections.graduate_ok" class="tl-renewal-section-body">
                                <div v-for="s in preview.to_graduate_ok" :key="'gok-' + s.userid" class="tl-renewal-row" :class="{ 'tl-renewal-row-selected': isUserSelected(s.userid) }">
                                    <label class="tl-row-checkbox" @click.stop>
                                        <input
                                            type="checkbox"
                                            :checked="isUserSelected(s.userid)"
                                            @change="toggleUser(s.userid)" />
                                    </label>
                                    <div class="tl-renewal-row-name">{{ s.name }}</div>
                                    <div class="tl-renewal-row-meta">
                                        <span class="tl-pill tl-pill-green">→ egresado</span>
                                        <span class="tl-pill tl-pill-muted">{{ s.period }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Graduate WARN (highlighted in red) -->
                        <div v-if="summary.to_graduate_warn_count > 0" class="tl-renewal-section tl-section-red">
                            <div class="tl-renewal-section-head" @click="toggleExpand('graduate_warn')">
                                <label class="tl-section-checkbox" @click.stop>
                                    <input
                                        type="checkbox"
                                        :checked="sectionSelectionState('graduate_warn') === 'all'"
                                        :indeterminate.prop="sectionSelectionState('graduate_warn') === 'some'"
                                        @change="toggleSection('graduate_warn')" />
                                </label>
                                <v-icon size="18" color="#EF4444">mdi-alert-octagon</v-icon>
                                <strong>Graduando CON PENDIENTES ({{ summary.to_graduate_warn_count }})</strong>
                                <span class="tl-section-selected-pill" v-if="sectionSelectionState('graduate_warn') !== 'none'">
                                    {{ sectionUserids('graduate_warn').filter(id => selectedUserIds.has(id)).length }} seleccionados
                                </span>
                                <v-icon size="16" color="#94A3B8">{{ expandedSections.graduate_warn ? 'mdi-chevron-up' : 'mdi-chevron-down' }}</v-icon>
                            </div>
                            <div v-show="expandedSections.graduate_warn" class="tl-renewal-section-body">
                                <div v-for="s in preview.to_graduate_warn" :key="'gwarn-' + s.userid" class="tl-renewal-row tl-renewal-row-warn" :class="{ 'tl-renewal-row-selected': isUserSelected(s.userid) }">
                                    <label class="tl-row-checkbox" @click.stop>
                                        <input
                                            type="checkbox"
                                            :checked="isUserSelected(s.userid)"
                                            @change="toggleUser(s.userid)" />
                                    </label>
                                    <div class="tl-renewal-row-name">
                                        <v-icon size="14" color="#EF4444">mdi-alert</v-icon>
                                        {{ s.name }}
                                    </div>
                                    <div class="tl-renewal-row-meta">
                                        <span class="tl-pill tl-pill-red">→ egresado</span>
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

                        <!-- Stays inactive (collapsed by default, not selectable) -->
                        <div v-if="summary.stays_inactive_count > 0" class="tl-renewal-section tl-section-amber">
                            <div class="tl-renewal-section-head" @click="toggleExpand('stays_inactive')">
                                <v-icon size="18" color="#F59E0B">mdi-account-clock-outline</v-icon>
                                <strong>Inactivos que se quedan ({{ summary.stays_inactive_count }})</strong>
                                <span class="tl-section-info-pill">No seleccionable</span>
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

                        <!-- Skipped (collapsed by default, not selectable) -->
                        <div v-if="summary.skipped_count > 0" class="tl-renewal-section tl-section-muted">
                            <div class="tl-renewal-section-head" @click="toggleExpand('skipped')">
                                <v-icon size="18" color="#94A3B8">mdi-help-circle-outline</v-icon>
                                <strong>Omitidos ({{ summary.skipped_count }})</strong>
                                <span class="tl-section-info-pill">No seleccionable</span>
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
                <div class="tl-modal-foot-info">
                    <v-icon size="14" color="#94A3B8">mdi-information-outline</v-icon>
                    <span v-if="selectedCount > 0">
                        {{ selectedCount }} estudiante(s) seleccionado(s) para renovar. El cohorte <strong>no se modifica</strong>.
                    </span>
                    <span v-else>
                        Selecciona al menos un estudiante para continuar.
                    </span>
                </div>
                <button class="tl-btn tl-btn-ghost" @click="closeModal" :disabled="executing">Cancelar</button>
                <button
                    class="tl-btn tl-btn-primary"
                    @click="executeRenewal"
                    :disabled="!canConfirm"
                >
                    <v-icon size="18" color="white">mdi-calendar-refresh-outline</v-icon>
                    {{ executing ? 'Renovando…' : 'Confirmar y renovar' }}
                </button>
            </div>
    </v-card>
    </v-dialog>
    `
});
