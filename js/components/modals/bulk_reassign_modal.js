/**
 * BULK ACADEMIC PERIOD REASSIGN MODAL
 *
 * Reclasifica el PERIODO LECTIVO (academic period, gmk_academic_periods)
 * de uno o varios estudiantes en lote, sin tocar su cuatrimestre/bimestre
 * (currentperiodid/currentsubperiodid permanecen iguales).
 *
 * Reemplaza la versión anterior que reclasificaba periodo_ingreso
 * (intake period). El campo destino es ahora un periodo lectivo real
 * (2024-I, 2024-II, ...) en lugar de una cohorte.
 *
 * API:
 *   <bulk-reassign-modal
 *     v-if="showModal"
 *     :learning-plan-id="2"
 *     :career-name="'Lic. en Enfermería'"
 *     @close="showModal = false"
 *     @done="onBulkReassignDone">
 *   </bulk-reassign-modal>
 *
 * Endpoints Moodle:
 *   - local_grupomakro_get_students_by_academic_period
 *   - local_grupomakro_bulk_update_students_academic_period
 */

Vue.component('bulk-reassign-modal', {
    props: {
        learningPlanId:  { type: [String, Number], required: true },
        // Source cohort (periodo de ingreso) — when set, the modal only
        // lists students in that cohort. The user is reclasifying their
        // academic period (periodo lectivo), not the cohort itself.
        cohort:           { type: String,   default: '' },
        careerName:      { type: String,   default: 'Carrera' },
    },
    data() {
        return {
            loading:        false,
            saving:         false,
            error:          '',
            groups:         [],     // [{period_id, period_name, period_status, students: [...]}]
            availablePeriods: [],   // [{id, name, status}] — academic periods, not cohorts
            total:          0,
            selectedUserIds: new Set(),
            search:         '',
            destPeriodId:   '',     // academic period id (int) for destination
            expandedGroups: new Set(),
            onlyActive:     true,   // filtro: solo mostrar estudiantes activos (default ON)
        };
    },
    computed: {
        // The WS endpoint URL. Defined as computed so Vue auto-invokes the
        // getter when this.wsUrl is accessed (returning the string). If it
        // were a method, this.wsUrl would return the function reference,
        // producing the malformed URL ".../function () { [native code] }".
        wsUrl() { return window.location.origin + '/webservice/rest/server.php'; },
        show() { return true; }, // controlled by v-if
        filteredGroups() {
            const q = this.search.trim().toLowerCase();
            if (!q) return this.groups;
            return this.groups.map(g => {
                const matches = g.students.filter(s =>
                    s.fullname.toLowerCase().includes(q) ||
                    (s.email || '').toLowerCase().includes(q) ||
                    (s.username || '').toLowerCase().includes(q) ||
                    (s.phone || '').toLowerCase().includes(q)
                );
                return { ...g, students: matches };
            }).filter(g => g.students.length > 0);
        },
        visibleTotal() {
            return this.filteredGroups.reduce((sum, g) => sum + g.students.length, 0);
        },
        selectedCount() { return this.selectedUserIds.size; },
        allVisibleSelected() {
            const allIds = this.filteredGroups.flatMap(g => g.students.map(s => s.userid));
            if (allIds.length === 0) return false;
            return allIds.every(id => this.selectedUserIds.has(id));
        },
        canConfirm() {
            return this.selectedCount > 0
                && this.destPeriodId !== ''
                && !this.saving;
        },
    },
    watch: {
        // Refetch whenever the active-only filter changes.
        onlyActive() { this.fetchStudents(); },
    },
    mounted() {
        this.fetchStudents();
    },
    methods: {
        getInitials(s) {
            const a = (s.firstname || '').trim();
            const b = (s.lastname || '').trim();
            if (a && b) return (a[0] + b[0]).toUpperCase();
            if (a) return a.substring(0, 2).toUpperCase();
            if (s.fullname) return s.fullname.substring(0, 2).toUpperCase();
            return 'ST';
        },
        // Status values come from local_learning_users via the WS function
        // and are in Spanish: 'activo', 'suspendido', 'retirado', 'egresado'.
        normalizeStatus(status) {
            const s = String(status == null ? '' : status).toLowerCase().trim();
            if (s === 'active' || s === 'activo' || s === '1' || s === 1) return 'activo';
            if (s === 'suspendido' || s === 'suspended') return 'suspendido';
            if (s === 'retirado' || s === 'withdrawn' || s === 'retired') return 'retirado';
            if (s === 'egresado' || s === 'graduated') return 'egresado';
            return s || 'desconocido';
        },
        getStatusClass(status) {
            const n = this.normalizeStatus(status);
            if (n === 'activo') return 'tl-status-ok';
            if (n === 'suspendido') return 'tl-status-warn';
            return 'tl-status-off';
        },
        getStatusLabel(status) {
            const n = this.normalizeStatus(status);
            if (n === 'activo') return 'Activo';
            if (n === 'suspendido') return 'Suspendido';
            if (n === 'retirado') return 'Retirado';
            if (n === 'egresado') return 'Egresado';
            return 'Inactivo';
        },
        getGroupColor(g) {
            // Deterministic color from period name
            const palette = ['tl-sem-blue', 'tl-sem-green', 'tl-sem-orange', 'tl-sem-red', 'tl-sem-muted'];
            const key = (g.period_name || '').split('').reduce((a, c) => a + c.charCodeAt(0), 0);
            return palette[key % palette.length];
        },
        // Helper: builds a fetch URL with wstoken as a query param. Native
        // URLSearchParams replaces axios's paramsSerializer (which had been
        // throwing "e.indexOf is not a function" from somewhere inside axios).
        buildWsUrl(wsfunction, extraParams) {
            const params = new URLSearchParams();
            params.set('wstoken', userToken);
            params.set('wsfunction', wsfunction);
            params.set('moodlewsrestformat', 'json');
            if (extraParams) {
                for (const key of Object.keys(extraParams)) {
                    const v = extraParams[key];
                    if (Array.isArray(v)) {
                        for (const item of v) {
                            params.append(key, String(item));
                        }
                    } else if (v !== undefined && v !== null) {
                        params.set(key, String(v));
                    }
                }
            }
            return this.wsUrl + '?' + params.toString();
        },
        async fetchStudents() {
            this.loading = true;
            this.error = '';
            this.selectedUserIds = new Set();

            let resp, data;
            // Granular try-catch: each step labelled so the error overlay tells
            // us exactly which phase broke ([1] fetch, [2] HTTP, [3] JSON, [4] data).
            try {
                const url = this.buildWsUrl('local_grupomakro_get_students_by_academic_period', {
                    learningplanid: parseInt(this.learningPlanId, 10),
                    intake_period:  this.cohort,
                    only_active: this.onlyActive ? 1 : 0,
                });
                resp = await fetch(url, { credentials: 'same-origin' });
            } catch (e) {
                this.setError('[1] fetch: ' + (e.message || String(e)), e);
                this.loading = false;
                return;
            }

            if (!resp.ok) {
                this.setError('[2] HTTP ' + resp.status + ' ' + resp.statusText);
                this.loading = false;
                return;
            }

            try {
                data = await resp.json();
            } catch (e) {
                this.setError('[3] JSON parse: ' + (e.message || String(e)), e);
                this.loading = false;
                return;
            }

            if (data && data.exception) {
                this.setError('[4] API: ' + (data.message || data.errorcode || 'exception'));
                this.loading = false;
                return;
            }

            // Object.freeze() tells Vue 2 NOT to make these properties reactive.
            // The loaded data is read-only and a large cohort (76+ students ×
            // 14 fields) would otherwise consume ~10x memory for Vue observers.
            this.groups = Object.freeze(data.groups || []);
            this.availablePeriods = Object.freeze(data.available_periods || []);
            this.total = data.total || 0;
            // Don't auto-expand: rendering the student rows × Vuetify
            // components overflows the browser tab. User clicks the
            // group header to expand.
            this.expandedGroups = new Set();
            this.loading = false;
        },
        setError(msg, e) {
            this.error = msg;
            console.error('[bulk_reassign]', msg);
            if (e) {
                console.error('[bulk_reassign] error object:', e);
                if (e.stack) console.error('[bulk_reassign] stack:', e.stack);
            }
        },
        toggleUser(userid) {
            if (this.selectedUserIds.has(userid)) this.selectedUserIds.delete(userid);
            else this.selectedUserIds.add(userid);
            this.selectedUserIds = new Set(this.selectedUserIds);
        },
        toggleGroup(g) {
            const ids = g.students.map(s => s.userid);
            const allSelected = ids.every(id => this.selectedUserIds.has(id));
            if (allSelected) {
                ids.forEach(id => this.selectedUserIds.delete(id));
            } else {
                ids.forEach(id => this.selectedUserIds.add(id));
            }
            this.selectedUserIds = new Set(this.selectedUserIds);
        },
        toggleAllVisible() {
            const allIds = this.filteredGroups.flatMap(g => g.students.map(s => s.userid));
            if (this.allVisibleSelected) {
                allIds.forEach(id => this.selectedUserIds.delete(id));
            } else {
                allIds.forEach(id => this.selectedUserIds.add(id));
            }
            this.selectedUserIds = new Set(this.selectedUserIds);
        },
        deselectAll() {
            this.selectedUserIds = new Set();
        },
        toggleGroupExpanded(g) {
            const k = g.period_id;
            if (this.expandedGroups.has(k)) this.expandedGroups.delete(k);
            else this.expandedGroups.add(k);
            this.expandedGroups = new Set(this.expandedGroups);
        },
        close() {
            this.$emit('close');
        },
        async confirm() {
            if (!this.canConfirm) return;
            this.saving = true;
            this.error = '';
            try {
                const userids = Array.from(this.selectedUserIds);
                const url = this.buildWsUrl('local_grupomakro_bulk_update_students_academic_period', {
                    userids: userids,
                    new_academic_periodid: parseInt(this.destPeriodId, 10),
                });
                const resp = await fetch(url, {
                    method: 'POST',
                    credentials: 'same-origin',
                });
                if (!resp.ok) {
                    this.setError('[2] HTTP ' + resp.status + ' ' + resp.statusText);
                    this.saving = false;
                    return;
                }
                const data = await resp.json();
                if (data && data.exception) {
                    this.setError('[4] API: ' + (data.message || data.errorcode || 'exception'));
                    this.saving = false;
                    return;
                }
                this.$emit('done', {
                    updatedCount: data.updated_count,
                    failedCount:  data.failed_count,
                    destPeriod:   data.new_period_name,
                    message:      data.message,
                });
                // Refetch so the moved students disappear from the table.
                this.fetchStudents();
            } catch (e) {
                this.setError('[1] fetch: ' + (e.message || String(e)), e);
            } finally {
                this.saving = false;
            }
        },
    },
    template: `
<v-dialog :value="show" persistent max-width="900" scrollable>
    <v-card class="tl-modal" tile>
        <!-- Header -->
        <div class="tl-modal-head">
            <div class="tl-modal-head-info">
                <div class="tl-modal-avatar">
                    <v-icon dark size="22">mdi-school</v-icon>
                </div>
                <div>
                    <h3 class="tl-modal-title">Reclasificar periodo lectivo</h3>
                    <div class="tl-modal-sub">
                        <span class="tl-modal-chip">
                            <v-icon size="12" color="#6366F1">mdi-book-open-variant</v-icon>
                            {{ careerName }}
                        </span>
                        <span v-if="cohort" class="tl-modal-chip" style="background:#FEF3C7;color:#92400E;">
                            <v-icon size="12" color="#F59E0B">mdi-calendar-multiple</v-icon>
                            Cohorte: {{ cohort }}
                        </span>
                        <span class="tl-modal-chip" style="background:#DBEAFE;color:#1E40AF;">
                            <v-icon size="12" color="#3B82F6">mdi-account-group</v-icon>
                            {{ total }} estudiante{{ total !== 1 ? 's' : '' }}
                        </span>
                    </div>
                </div>
            </div>
            <button class="tl-modal-close" @click="close" aria-label="Cerrar">
                <v-icon size="20" color="#475569">mdi-close</v-icon>
            </button>
        </div>

        <!-- KPIs -->
        <div class="tl-modal-kpis">
            <div class="tl-modal-kpi">
                <v-icon size="18" color="#6366F1">mdi-account-group</v-icon>
                <div>
                    <div class="tl-modal-kpi-num">{{ total }}</div>
                    <div class="tl-modal-kpi-lbl">En plan</div>
                </div>
            </div>
            <div class="tl-modal-kpi">
                <v-icon size="18" color="#10B981">mdi-check-circle</v-icon>
                <div>
                    <div class="tl-modal-kpi-num">{{ selectedCount }}</div>
                    <div class="tl-modal-kpi-lbl">Seleccionados</div>
                </div>
            </div>
            <div class="tl-modal-kpi">
                <v-icon size="18" color="#3B82F6">mdi-eye</v-icon>
                <div>
                    <div class="tl-modal-kpi-num">{{ visibleTotal }}</div>
                    <div class="tl-modal-kpi-lbl">Visibles</div>
                </div>
            </div>
            <div class="tl-modal-kpi">
                <v-icon size="18" color="#475569">mdi-shape</v-icon>
                <div>
                    <div class="tl-modal-kpi-num">{{ groups.length }}</div>
                    <div class="tl-modal-kpi-lbl">Periodos</div>
                </div>
            </div>
        </div>

        <!-- Toolbar (filter + select all) -->
        <div class="tl-modal-toolbar">
            <div style="display:flex;align-items:center;gap:10px;">
                <label class="tl-filter-toggle" style="display:flex;align-items:center;gap:6px;cursor:pointer;padding:6px 10px;border-radius:6px;background:#F0FDF4;border:1px solid #BBF7D0;">
                    <input type="checkbox" v-model="onlyActive" style="margin:0;" />
                    <span style="font-size:13px;color:#166534;font-weight:500;">Solo activos</span>
                </label>
                <div class="tl-modal-search" style="flex:1;">
                    <v-icon size="16" color="#94A3B8">mdi-magnify</v-icon>
                    <input
                        v-model="search"
                        class="tl-modal-search-input"
                        placeholder="Buscar por nombre, cédula, email o teléfono..."
                        type="text" />
                    <button
                        v-if="search"
                        class="tl-modal-search-clear"
                        @click="search = ''"
                        aria-label="Limpiar búsqueda">
                        <v-icon size="14" color="#94A3B8">mdi-close</v-icon>
                    </button>
                </div>
                <v-btn
                    small
                    :color="allVisibleSelected ? '#6366F1' : 'white'"
                    :class="['tl-btn-action', allVisibleSelected ? 'tl-btn-primary' : '']"
                    @click="toggleAllVisible"
                    :disabled="visibleTotal === 0"
                    style="height:32px;">
                    <v-icon size="14" left>
                        {{ allVisibleSelected ? 'mdi-checkbox-marked' : 'mdi-checkbox-blank-outline' }}
                    </v-icon>
                    {{ allVisibleSelected ? 'Quitar selección' : 'Seleccionar visibles' }}
                </v-btn>
                <v-btn
                    v-if="selectedCount > 0"
                    small
                    text
                    color="#EF4444"
                    @click="deselectAll"
                    style="height:32px;text-transform:none;">
                    <v-icon size="14" left>mdi-close-circle</v-icon>
                    Limpiar
                </v-btn>
            </div>
        </div>

        <!-- Body -->
        <v-card-text class="tl-modal-table-wrap" style="padding:0;">
            <div v-if="loading" style="padding:60px 24px;text-align:center;color:#94A3B8;">
                <v-progress-circular indeterminate color="#6366F1" size="40" width="3" />
                <p style="margin:12px 0 0;font-size:13px;">Cargando estudiantes del plan {{ careerName }}...</p>
            </div>

            <div v-else-if="error && groups.length === 0" class="tl-panel-error">
                <v-icon size="40" color="#EF4444">mdi-alert-circle</v-icon>
                <p>{{ error }}</p>
                <button class="tl-btn-secondary" @click="fetchStudents">Reintentar</button>
            </div>

            <div v-else-if="groups.length === 0" class="tl-panel-empty">
                <div class="tl-empty-icon">
                    <v-icon size="40" color="#94A3B8">mdi-account-off</v-icon>
                </div>
                <p class="tl-empty-title">No hay estudiantes</p>
                <p class="tl-empty-sub">
                    {{ onlyActive
                        ? 'No hay estudiantes activos en este plan. Desactivá el filtro "Solo activos" para ver todos.'
                        : 'Este plan no tiene estudiantes matriculados.' }}
                </p>
            </div>

            <div v-else>
                <div v-if="error" class="v-alert v-alert--prominent tl-alert"
                     style="background:#FEE2E2;color:#B91C1C;border-radius:0;margin:0;padding:10px 16px;font-size:13px;">
                    <v-icon color="#B91C1C" size="18" left>mdi-alert-circle</v-icon>
                    {{ error }}
                </div>

                <div v-for="g in filteredGroups" :key="g.period_id" class="tl-bulk-group">
                    <div class="tl-bulk-group-head" @click="toggleGroupExpanded(g)">
                        <button
                            class="tl-bulk-group-check"
                            @click.stop="toggleGroup(g)"
                            :aria-label="'Seleccionar grupo'">
                            <v-icon size="18" :color="g.students.every(s => selectedUserIds.has(s.userid)) ? '#6366F1' : '#94A3B8'">
                                {{ g.students.every(s => selectedUserIds.has(s.userid)) ? 'mdi-checkbox-marked-circle' : 'mdi-checkbox-blank-circle-outline' }}
                            </v-icon>
                        </button>
                        <div :class="['tl-subj-sem', getGroupColor(g)]" style="width:6px;"></div>
                        <div class="tl-bulk-group-title">
                            <div class="tl-bulk-group-name">
                                <span class="tl-bulk-group-period">{{ g.period_name }}</span>
                                <span v-if="g.period_status === 1" class="tl-badge-active-mini" style="font-size:10px;color:#10B981;font-weight:600;margin-left:6px;">ACTIVO</span>
                                <span v-else-if="g.period_id !== 0" class="tl-badge-closed-mini" style="font-size:10px;color:#94A3B8;font-weight:600;margin-left:6px;">CERRADO</span>
                            </div>
                            <div class="tl-bulk-group-sub">
                                {{ g.students.length }} estudiante{{ g.students.length !== 1 ? 's' : '' }}
                            </div>
                        </div>
                        <v-icon size="18" :style="{ color: '#94A3B8', transform: expandedGroups.has(g.period_id) ? 'rotate(180deg)' : 'none', transition: 'transform 200ms' }">
                            mdi-chevron-down
                        </v-icon>
                    </div>
                    <div v-if="expandedGroups.has(g.period_id)" class="tl-bulk-group-body">
                        <div
                            v-for="s in g.students"
                            :key="s.userid"
                            class="tl-bulk-student"
                            :class="{ 'tl-bulk-student-selected': selectedUserIds.has(s.userid) }"
                            @click="toggleUser(s.userid)">
                            <span class="tl-bulk-student-check" :class="{ 'tl-bulk-student-check-on': selectedUserIds.has(s.userid) }">{{ selectedUserIds.has(s.userid) ? '☑' : '☐' }}</span>
                            <div class="tl-modal-avatar-sm">{{ getInitials(s) }}</div>
                            <div class="tl-bulk-student-info">
                                <div class="tl-modal-student-name">{{ s.fullname }}</div>
                                <div class="tl-modal-student-sub">
                                    <span v-if="s.username" class="tl-modal-id" style="font-size:10px;">{{ s.username }}</span>
                                    <span v-if="s.email" style="margin-left:6px;color:#94A3B8;">{{ s.email }}</span>
                                </div>
                            </div>
                            <span :class="['tl-status-pill', getStatusClass(s.status)]">
                                <span class="tl-status-dot"></span>
                                {{ getStatusLabel(s.status) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </v-card-text>

        <!-- Footer -->
        <div class="tl-modal-foot">
            <div class="tl-modal-foot-info">
                <v-icon size="14" color="#94A3B8">mdi-information</v-icon>
                <span>
                    {{ selectedCount }} de {{ total }} seleccionados
                </span>
            </div>
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <div class="tl-bulk-dest">
                    <label class="tl-bulk-dest-lbl">Nuevo periodo lectivo:</label>
                    <select v-model="destPeriodId" class="tl-bulk-dest-select" :disabled="saving || availablePeriods.length === 0">
                        <option value="" disabled>Seleccione periodo</option>
                        <option v-for="p in availablePeriods" :key="p.id" :value="p.id">{{ p.name }}{{ p.status === 1 ? ' (Activo)' : ' (Cerrado)' }}</option>
                    </select>
                </div>
                <v-btn text small @click="close" :disabled="saving" style="text-transform:none;color:#475569;">Cancelar</v-btn>
                <v-btn
                    :disabled="!canConfirm"
                    :loading="saving"
                    color="#6366F1"
                    dark
                    depressed
                    @click="confirm"
                    class="tl-btn-primary"
                    style="text-transform:none;">
                    <v-icon size="16" left>mdi-calendar-refresh</v-icon>
                    Reclasificar {{ selectedCount > 0 ? '(' + selectedCount + ')' : '' }}
                </v-btn>
            </div>
        </div>
    </v-card>
</v-dialog>
`
});