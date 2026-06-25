/* global Vue, axios, Swal, strings */
(function () {
    'use strict';

    function axiosInstance() {
        const inst = axios.create({
            baseURL: (window.location.origin || '') + '/local/grupomakro_core/ajax.php',
            headers: { 'Content-Type': 'application/json' }
        });
        inst.interceptors.request.use((cfg) => {
            cfg.headers['X-Requested-With'] = 'XMLHttpRequest';
            return cfg;
        });
        return inst;
    }

    Vue.component('diplomageneration', {
        template: `
            <div>
            <v-container fluid style="max-width: 100% !important;" class="pa-0">
                <v-row class="ma-0">
                    <v-col cols="12" class="py-2">
                        <div class="d-flex align-center" style="gap: 12px; flex-wrap: wrap;">
                            <h2 class="mb-0">{{ strings.diploma_generation || 'Generación de Diplomas' }}</h2>
                            <v-spacer></v-spacer>
                            <v-btn small color="primary" dark @click="goTemplates">
                                <v-icon left small>mdi-palette-swatch</v-icon>
                                {{ strings.diploma_templates || 'Plantillas' }}
                            </v-btn>
                        </div>
                    </v-col>
                </v-row>

                <!-- Tab switch -->
                <v-tabs v-model="tab" background-color="transparent" color="primary" class="mb-3">
                    <v-tab key="graduands">
                        <v-icon left>mdi-account-school-outline</v-icon>
                        {{ strings.list_only_pending || 'Estudiantes elegibles' }}
                    </v-tab>
                    <v-tab key="generated">
                        <v-icon left>mdi-check-decagram</v-icon>
                        {{ strings.list_generated || 'Diplomas generados' }}
                    </v-tab>
                </v-tabs>

                <v-tabs-items v-model="tab">
                    <!-- GRADUANDS TAB -->
                    <v-tab-item key="graduands">
                        <v-row class="ma-0">
                            <!-- Career summary cards -->
                            <v-col cols="12" class="py-1">
                                <v-row dense>
                                    <v-col v-for="p in visiblePlanCounts" :key="p.id" cols="6" sm="4" md="3" lg="2">
                                        <v-card outlined class="pa-3 dpl-card-summary dpl-career-card" :class="p.count > 0 ? 'danger' : 'success'" @click="filterPlan(p.id)" style="cursor: pointer;">
                                            <div style="font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 1px; font-weight: 600;">
                                                {{ strings.career || 'Carrera' }}
                                            </div>
                                            <div style="font-size: 14px; font-weight: 600; margin-top: 4px; line-height: 1.2;">{{ p.name }}</div>
                                            <div style="font-size: 26px; font-weight: 700; margin-top: 8px;" :class="p.count > 0 ? 'red--text' : 'green--text'">
                                                {{ p.count }}
                                            </div>
                                            <div style="font-size: 11px; color: #6b7280;">{{ strings.eligible_count ? '' : 'pendientes' }}</div>
                                        </v-card>
                                    </v-col>
                                </v-row>
                            </v-col>

                            <!-- Filters + Template picker + Actions -->
                            <v-col cols="12" class="py-1">
                                <v-card outlined class="pa-3">
                                    <v-row dense align="center">
                                        <v-col cols="12" md="4">
                                            <v-select v-model="selectedPlanId" :items="planItems" :label="strings.filter_career" outlined dense clearable hide-details></v-select>
                                        </v-col>
                                        <v-col cols="12" md="4">
                                            <v-select v-model="selectedTemplateId" :items="templateItems" :label="strings.select_template" outlined dense hide-details></v-select>
                                        </v-col>
                                        <v-col cols="12" md="4">
                                            <v-text-field v-model="search" :label="strings.search_student" append-icon="mdi-magnify" outlined dense clearable hide-details></v-text-field>
                                        </v-col>
                                    </v-row>
                                    <v-row dense align="center" class="mt-1">
                                        <v-col cols="12" md="4" class="d-flex align-center" style="gap: 8px;">
                                            <v-checkbox v-model="selectAll" :label="'Todos'" color="primary" dense hide-details @change="toggleAll"></v-checkbox>
                                            <v-chip small color="primary">{{ selected.length }} {{ strings.selected_count || 'seleccionados' }}</v-chip>
                                        </v-col>
                                        <v-col cols="12" md="8" class="d-flex justify-end" style="gap: 8px;">
                                            <v-btn small color="primary" @click="generate" :loading="generating" :disabled="!selected.length || !selectedTemplateId">
                                                <v-icon left small>mdi-cog</v-icon>
                                                {{ strings.generate_selected }}
                                            </v-btn>
                                        </v-col>
                                    </v-row>
                                </v-card>
                            </v-col>

                            <!-- Graduands table -->
                            <v-col cols="12" class="py-1">
                                <v-card outlined>
                                    <v-card-text class="py-2 d-flex align-center" style="gap: 16px; flex-wrap: wrap;">
                                        <v-switch
                                            v-model="onlyEligible"
                                            color="primary"
                                            dense
                                            hide-details
                                            inset
                                            @change="loadGraduands"
                                            label="Solo elegibles (cumplan todos los requisitos)"
                                        ></v-switch>
                                        <v-switch
                                            v-model="includeNoRequirementPlans"
                                            color="primary"
                                            dense
                                            hide-details
                                            inset
                                            @change="onIncludeNoRequirementPlansChange"
                                            label="Incluir planes sin asignaturas obligatorias"
                                        ></v-switch>
                                        <v-spacer></v-spacer>
                                        <span class="caption grey--text">
                                            {{ graduands.length }} estudiante(s) en la lista ·
                                            clic en una fila para ver el detalle
                                        </span>
                                    </v-card-text>
                                    <v-divider></v-divider>
                                    <v-data-table
                                        v-model="selected"
                                        :headers="graduandHeaders"
                                        :items="graduands"
                                        item-key="rowKey"
                                        :search="search"
                                        :items-per-page="20"
                                        :footer-props="{itemsPerPageOptions: [10,20,50,-1]}"
                                        show-select
                                        no-data-text=""
                                    >
                                        <template slot="no-data">
                                            <div class="pa-6 text-center grey--text">
                                                <div>{{ strings.no_graduands }}</div>
                                                <div v-if="!onlyEligible" class="caption mt-2">
                                                    Prueba desactivando el filtro "Solo elegibles".
                                                </div>
                                            </div>
                                        </template>
                                        <template slot="item.student_name" slot-scope="props">
                                            <div class="font-weight-medium">{{ props.item.user.fullname }}</div>
                                            <div class="caption grey--text">{{ props.item.user.idnumber || props.item.user.username }}</div>
                                        </template>
                                        <template slot="item.user.documentnumber" slot-scope="props">
                                            <span>{{ props.item.user.documentnumber || '—' }}</span>
                                        </template>
                                        <template slot="item.plan.name" slot-scope="props">
                                            <v-chip small color="primary" outlined>{{ props.item.plan.name }}</v-chip>
                                        </template>
                                        <template slot="item.eligibility_status" slot-scope="props">
                                            <span>
                                                <v-chip v-if="props.item.eligibility && props.item.eligibility.has_diploma" small color="grey" text-color="white">Ya emitido</v-chip>
                                                <v-chip v-else-if="props.item.eligibility && props.item.eligibility.is_eligible" small color="green" text-color="white">Apto</v-chip>
                                                <v-chip v-else small color="orange" text-color="white">
                                                    {{ props.item.eligibility.passed_count }}/{{ props.item.eligibility.required_count }}
                                                </v-chip>
                                            </span>
                                        </template>
                                        <template slot="item.actions" slot-scope="props">
                                            <v-btn
                                                small
                                                color="primary"
                                                outlined
                                                :loading="detailLoading && detailUserId === props.item.user.id && detailPlanId === props.item.plan.id"
                                                @click.stop="onGraduandClick(props.item)"
                                            >
                                                <v-icon left small>mdi-clipboard-check-outline</v-icon>
                                                Ver detalle
                                            </v-btn>
                                        </template>
                                    </v-data-table>
                                </v-card>
                            </v-col>
                        </v-row>
                    </v-tab-item>

                    <!-- GENERATED TAB -->
                    <v-tab-item key="generated">
                        <v-row class="ma-0">
                            <v-col cols="12" class="py-1">
                                <v-card outlined class="pa-3">
                                    <v-row dense>
                                        <v-col cols="12" md="3">
                                            <v-select v-model="genFilter.templateid" :items="templateItemsWithAll" :label="strings.template_used" outlined dense hide-details clearable></v-select>
                                        </v-col>
                                        <v-col cols="12" md="3">
                                            <v-select v-model="genFilter.status" :items="statusItems" :label="strings.filter_status" outlined dense hide-details></v-select>
                                        </v-col>
                                        <v-col cols="6" md="6">
                                            <v-text-field v-model="genFilter.search" :label="strings.search_student" append-icon="mdi-magnify" outlined dense clearable hide-details @keyup.enter="loadGenerations"></v-text-field>
                                        </v-col>
                                    </v-row>
                                </v-card>
                            </v-col>
                            <v-col cols="12" class="py-1">
                                <v-card outlined>
                                    <v-data-table
                                        :headers="genHeaders"
                                        :items="generations"
                                        :items-per-page="20"
                                        :footer-props="{itemsPerPageOptions: [10,20,50,-1]}"
                                        no-data-text=""
                                    >
                                        <template slot="no-data">
                                            <div class="pa-6 text-center grey--text">Sin registros.</div>
                                        </template>
                                        <template slot="item.student_name" slot-scope="props">
                                            <div class="font-weight-medium">{{ props.item.student_name }}</div>
                                            <div class="caption grey--text">{{ props.item.student_idnumber || '—' }} • {{ props.item.student_document || '—' }}</div>
                                        </template>
                                        <template slot="item.status" slot-scope="props">
                                            <v-chip small :color="props.item.status === 'generated' ? 'green' : 'red'" text-color="white">
                                                {{ props.item.status === 'generated' ? strings.status_generated : strings.status_revoked }}
                                            </v-chip>
                                        </template>
                                        <template slot="item.issued_at" slot-scope="props">
                                            <span>{{ formatDate(props.item.issued_at) }}</span>
                                        </template>
                                        <template slot="item.actions" slot-scope="props">
                                            <v-btn icon small :href="props.item.verification_url" target="_blank" title="Verificar">
                                                <v-icon small color="primary">mdi-shield-check</v-icon>
                                            </v-btn>
                                            <v-btn icon small @click="downloadOne(props.item)" title="Descargar">
                                                <v-icon small color="primary">mdi-download</v-icon>
                                            </v-btn>
                                            <v-btn v-if="props.item.status === 'generated'" icon small @click="confirmRevoke(props.item)" title="Revocar">
                                                <v-icon small color="error">mdi-cancel</v-icon>
                                            </v-btn>
                                        </template>
                                    </v-data-table>
                                </v-card>
                            </v-col>
                        </v-row>
                    </v-tab-item>
                </v-tabs-items>
            </v-container>

            <!-- Eligibility detail popup (checklist of pending / completed requirements) -->
            <v-dialog v-model="detailDialog" max-width="640" scrollable>
                <v-card v-if="detail">
                    <v-card-title class="d-flex align-center" style="gap: 12px;">
                        <v-icon :color="detail.eligibility.is_eligible ? 'green' : 'orange'">
                            {{ detail.eligibility.is_eligible ? 'mdi-check-decagram' : 'mdi-alert-circle' }}
                        </v-icon>
                        <span>Detalle de elegibilidad</span>
                        <v-spacer></v-spacer>
                        <v-btn icon @click="detailDialog = false"><v-icon>mdi-close</v-icon></v-btn>
                    </v-card-title>
                    <v-divider></v-divider>
                    <v-card-text style="max-height: 60vh;">
                        <div class="mb-3">
                            <div class="title">{{ detail.user.fullname }}</div>
                            <div class="caption grey--text">
                                {{ detail.user.idnumber || detail.user.username }}
                                &middot; {{ detail.plan.name }}
                            </div>
                        </div>

                        <v-alert
                            :type="detail.eligibility.is_eligible ? 'success' : 'warning'"
                            :icon="detail.eligibility.is_eligible ? 'mdi-check-circle' : 'mdi-alert'"
                            class="mb-4"
                            border="left"
                        >
                            <div class="font-weight-medium">{{ detail.eligibility.reason }}</div>
                            <div v-if="detail.eligibility.required_count > 0" class="mt-1">
                                <strong>{{ detail.eligibility.passed_count }}</strong>
                                de
                                <strong>{{ detail.eligibility.required_count }}</strong>
                                asignaturas obligatorias aprobadas
                                ({{ detail.eligibility.progress_percent }}%)
                            </div>
                        </v-alert>

                        <v-progress-linear
                            v-if="detail.eligibility.required_count > 0"
                            :value="detail.eligibility.progress_percent"
                            :color="detail.eligibility.is_eligible ? 'green' : 'orange'"
                            height="10"
                            rounded
                            class="mb-4"
                        ></v-progress-linear>

                        <!-- Checklist of passed requirements -->
                        <div v-if="detail.eligibility.passed_requirements && detail.eligibility.passed_requirements.length" class="mb-4">
                            <div class="subtitle-2 green--text text--darken-2 mb-2 d-flex align-center" style="gap: 6px;">
                                <v-icon color="green">mdi-check-circle</v-icon>
                                <span>Cumplidas ({{ detail.eligibility.passed_requirements.length }})</span>
                            </div>
                            <v-list dense class="dpl-checklist dpl-checklist-passed">
                                <v-list-item
                                    v-for="(name, idx) in detail.eligibility.passed_requirements"
                                    :key="'p_' + idx"
                                    class="dpl-checklist-item"
                                >
                                    <v-list-item-icon class="mr-2">
                                        <v-icon color="green">mdi-check-bold</v-icon>
                                    </v-list-item-icon>
                                    <v-list-item-content>
                                        <v-list-item-title class="text--primary">{{ name }}</v-list-item-title>
                                    </v-list-item-content>
                                </v-list-item>
                            </v-list>
                        </div>

                        <!-- Checklist of pending requirements -->
                        <div v-if="detail.eligibility.missing_requirements && detail.eligibility.missing_requirements.length">
                            <div class="subtitle-2 orange--text text--darken-2 mb-2 d-flex align-center" style="gap: 6px;">
                                <v-icon color="orange">mdi-close-circle</v-icon>
                                <span>Pendientes ({{ detail.eligibility.missing_requirements.length }})</span>
                            </div>
                            <v-list dense class="dpl-checklist dpl-checklist-pending">
                                <v-list-item
                                    v-for="(name, idx) in detail.eligibility.missing_requirements"
                                    :key="'m_' + idx"
                                    class="dpl-checklist-item"
                                >
                                    <v-list-item-icon class="mr-2">
                                        <v-icon color="orange">mdi-circle-outline</v-icon>
                                    </v-list-item-icon>
                                    <v-list-item-content>
                                        <v-list-item-title class="text--primary">{{ name }}</v-list-item-title>
                                    </v-list-item-content>
                                </v-list-item>
                            </v-list>
                        </div>

                        <v-alert
                            v-if="detail.eligibility.required_count === 0"
                            type="info"
                            icon="mdi-information"
                            class="mt-3"
                            border="left"
                        >
                            Este plan no tiene asignaturas obligatorias definidas, por lo que el estudiante se considera apto automáticamente.
                        </v-alert>
                    </v-card-text>
                    <v-divider></v-divider>
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn text @click="detailDialog = false">{{ strings.close || 'Cerrar' }}</v-btn>
                        <v-btn
                            color="primary"
                            :disabled="detail.eligibility.has_diploma"
                            @click="selectFromDetail"
                        >
                            <v-icon left small>mdi-check</v-icon>
                            {{ detail.eligibility.has_diploma ? 'Ya emitido' : 'Seleccionar para generar' }}
                        </v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>
            </div>
        `,
        data() {
            return {
                http: axiosInstance(),
                tab: 0,
                plans: [],
                planCounts: [],
                templates: [],
                graduands: [],
                generations: [],
                selectedPlanId: null,
                selectedTemplateId: null,
                search: '',
                genFilter: { templateid: null, status: '', search: '' },
                selected: [],
                selectAll: false,
                generating: false,
                // Toggle: when true, only show students who meet ALL graduation
                // requirements. When false, show every enrolled student
                // (with their eligibility status). Default ON so the
                // table feels familiar; the user can switch it off to
                // see and select any student for an override generation.
                onlyEligible: true,
                // Toggle: when false (default), plans with zero required
                // courses (e.g. 'Cursos Complementarios TPC') are hidden
                // from the plan dropdown and the summary cards. Plans with
                // no required courses have no concept of graduation (every
                // student is auto-apto) so they are excluded by default to
                // avoid confusion. The admin can flip this switch to opt-in
                // to seeing those plans if they really need them.
                includeNoRequirementPlans: false,
                // Detail popup: holds the eligibility breakdown for the
                // student the admin clicked on in the table.
                detailDialog: false,
                detailLoading: false,
                detailError: '',
                detail: null,
                detailUserId: 0,
                detailPlanId: 0
            };
        },
        computed: {
            strings() { return window.strings || {}; },
            planItems() {
                // Hide plans with no required courses unless the admin
                // explicitly opts in via the toggle.
                const visible = this.plans.filter(p =>
                    this.includeNoRequirementPlans || p.has_required_courses
                );
                return [{ text: this.strings.all_careers, value: null }].concat(
                    visible.map(p => ({ text: p.name, value: p.id }))
                );
            },
            /**
             * Summary cards shown above the filters. Same filtering rule
             * as planItems but uses the count endpoint so each card shows
             * the eligible-graduand count for that plan.
             */
            visiblePlanCounts() {
                return this.planCounts.filter(p =>
                    this.includeNoRequirementPlans || p.has_required_courses
                );
            },
            templateItems() {
                return this.templates.map(t => ({ text: t.name, value: t.id }));
            },
            templateItemsWithAll() {
                return [{ text: this.strings.all_status || 'Todos', value: null }].concat(this.templateItems);
            },
            statusItems() {
                return [
                    { text: this.strings.all_status, value: '' },
                    { text: this.strings.status_generated, value: 'generated' },
                    { text: this.strings.status_revoked, value: 'revoked' }
                ];
            },
            graduandHeaders() {
                return [
                    { text: this.strings.name || 'Estudiante', value: 'student_name', sortable: true },
                    { text: this.strings.document || 'Documento', value: 'user.documentnumber', sortable: false },
                    { text: this.strings.career || 'Carrera', value: 'plan.name', sortable: true },
                    { text: 'Periodo', value: 'plan.periodname', sortable: false },
                    { text: 'Estado', value: 'eligibility_status', sortable: false, align: 'center', width: 130 },
                    { text: 'Acciones', value: 'actions', sortable: false, align: 'end', width: 160 }
                ];
            },
            genHeaders() {
                return [
                    { text: this.strings.generated_for, value: 'student_name', sortable: true },
                    { text: this.strings.template_used, value: 'template_name', sortable: true },
                    { text: 'N° Diploma', value: 'diploma_number', sortable: true },
                    { text: this.strings.generated_at, value: 'issued_at', sortable: true },
                    { text: this.strings.status_generated, value: 'status', sortable: true },
                    { text: '', value: 'actions', sortable: false, align: 'end' }
                ];
            }
        },
        watch: {
            selectedPlanId() { this.loadGraduands(); },
            search() { this.loadGraduands(); },
            graduands() {
                // Pre-assign rowKey for v-data-table.
                this.graduands.forEach((g, idx) => { g.rowKey = g.user.id + '_' + g.plan.id; });
                // Clear selection of items that are no longer in the list.
                const validKeys = new Set(this.graduands.map(g => g.rowKey));
                this.selected = this.selected.filter(s => validKeys.has(s.rowKey));
            },
            'genFilter.templateid'() { this.loadGenerations(); },
            'genFilter.status'(v) { if (v !== undefined) this.loadGenerations(); }
        },
        async mounted() {
            await Promise.all([this.loadPlans(), this.loadTemplates(), this.loadPlanCounts()]);
            await this.loadGraduands();
            await this.loadGenerations();
        },
        methods: {
            goTemplates() {
                window.location.href = (window.location.origin || '') + '/local/grupomakro_core/pages/diplomatemplates.php';
            },
            /**
             * Open the eligibility detail dialog for a graduand row.
             * Always available (works for eligible AND non-eligible students),
             * so admins can see WHY a particular student is or is not
             * eligible without leaving the page.
             */
            async onGraduandClick(row) {
                if (!row || !row.user || !row.plan) { return; }
                this.detailDialog = true;
                this.detail = null;
                this.detailLoading = true;
                this.detailUserId = row.user.id;
                this.detailPlanId = row.plan.id;
                this.detailError = '';
                try {
                    const res = await this.http.post('/', {
                        action: 'local_grupomakro_diploma_graduand_eligibility_detail',
                        userid: row.user.id,
                        learningplanid: row.plan.id
                    });
                    if (res.data && res.data.status === 'success') {
                        this.detail = res.data.detail;
                    } else {
                        throw new Error((res.data && res.data.message) || 'Error');
                    }
                } catch (e) {
                    this.detailError = (e && e.message) || 'No se pudo cargar el detalle';
                } finally {
                    this.detailLoading = false;
                    this.detailUserId = 0;
                    this.detailPlanId = 0;
                }
            },
            /**
             * From the detail popup, mark the student as selected in the
             * main table (and close the popup) so the admin can continue
             * straight into the "Generate diplomas" flow.
             */
            selectFromDetail() {
                if (!this.detail) { return; }
                const u = this.detail.user;
                const p = this.detail.plan;
                const rowKey = u.id + '_' + p.id;
                const row = (this.graduands || []).find(g => g.rowKey === rowKey);
                if (row && !this.selected.find(s => s.rowKey === rowKey)) {
                    this.selected.push(row);
                } else if (!row) {
                    // The student isn't in the current filtered table; add
                    // a synthetic entry so the rest of the flow can pick
                    // them up.
                    this.selected.push({
                        rowKey: rowKey,
                        user: u,
                        plan: p,
                        eligibility: this.detail.eligibility,
                        student_name: u.fullname
                    });
                }
                this.detailDialog = false;
            },
            async loadPlans() {
                try {
                    const res = await this.http.post('/', { action: 'local_grupomakro_diploma_list_plans' });
                    if (res.data && res.data.status === 'success') {
                        this.plans = res.data.plans || [];
                    }
                } catch (e) { console.warn(e); }
            },
            async loadTemplates() {
                try {
                    const res = await this.http.post('/', { action: 'local_grupomakro_diploma_list_templates' });
                    if (res.data && res.data.status === 'success') {
                        this.templates = (res.data.templates || []).filter(t => t.active);
                    }
                } catch (e) { console.warn(e); }
            },
            async loadPlanCounts() {
                try {
                    const res = await this.http.post('/', { action: 'local_grupomakro_diploma_count_eligible' });
                    if (res.data && res.data.status === 'success') {
                        this.planCounts = res.data.counts || [];
                    }
                } catch (e) { console.warn(e); }
            },
            filterPlan(planId) {
                this.selectedPlanId = this.selectedPlanId === planId ? null : planId;
            },
            /**
             * When the "include plans with no required courses" toggle
             * changes, the visible plan list may shrink. If the admin
             * happens to have selected such a plan, we need to clear the
             * filter (otherwise the dropdown would silently show an empty
             * selection) and reload the graduands list so the table
             * reflects the new state.
             */
            onIncludeNoRequirementPlansChange() {
                if (this.selectedPlanId) {
                    const sel = this.plans.find(p => p.id === this.selectedPlanId);
                    if (sel && !sel.has_required_courses && !this.includeNoRequirementPlans) {
                        this.selectedPlanId = null;
                    }
                }
                this.loadGraduands();
            },
            async loadGraduands() {
                try {
                    const payload = {
                        action: 'local_grupomakro_diploma_list_graduands',
                        learningplanid: this.selectedPlanId || 0,
                        search: this.search || '',
                        onlyeligible: this.onlyEligible ? 1 : 0
                    };
                    const res = await this.http.post('/', payload);
                    if (res.data && res.data.status === 'success') {
                        this.graduands = (res.data.graduands || []).map(g => ({
                            ...g,
                            student_name: g.user.fullname,
                            rowKey: g.user.id + '_' + g.plan.id
                        }));
                    }
                } catch (e) { console.warn(e); }
            },
            async loadGenerations() {
                try {
                    const payload = {
                        action: 'local_grupomakro_diploma_list_generations',
                        templateid: this.genFilter.templateid || 0,
                        status: this.genFilter.status || '',
                        search: this.genFilter.search || ''
                    };
                    const res = await this.http.post('/', payload);
                    if (res.data && res.data.status === 'success') {
                        this.generations = res.data.records || [];
                    }
                } catch (e) { console.warn(e); }
            },
            toggleAll() {
                if (this.selectAll) {
                    this.selected = [...this.graduands];
                } else {
                    this.selected = [];
                }
            },
            async generate() {
                if (!this.selectedTemplateId) {
                    Swal.fire({ icon: 'warning', title: this.strings.no_template_selected });
                    return;
                }
                if (!this.selected.length) {
                    Swal.fire({ icon: 'warning', title: this.strings.no_students_selected });
                    return;
                }
                // Warn the admin if any selected student does NOT meet the
                // graduation requirements. The backend will still accept
                // the request (override flow), but the admin should
                // explicitly confirm.
                const noteligible = (this.selected || []).filter(s => {
                    return s.eligibility && !s.eligibility.is_eligible;
                });
                if (noteligible.length > 0) {
                    const list = noteligible.slice(0, 5).map(s =>
                        '• ' + (s.user ? s.user.fullname : '?') +
                        (s.eligibility && s.eligibility.required_count
                            ? ' (' + s.eligibility.passed_count + '/' + s.eligibility.required_count + ')'
                            : '')
                    ).join('\n');
                    const more = noteligible.length > 5 ? '\ny ' + (noteligible.length - 5) + ' más…' : '';
                    const proceed = await Swal.fire({
                        title: 'Hay ' + noteligible.length + ' estudiante(s) que NO cumplen requisitos',
                        html: '<div style="text-align:left;">' +
                              '<p>Los siguientes seleccionados aún no aprueban todas las asignaturas obligatorias:</p>' +
                              '<pre style="white-space:pre-wrap;font-family:inherit;">' + list + more + '</pre>' +
                              '<p>¿Deseas generar el diploma de todos modos?</p>' +
                              '</div>',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Sí, generar de todos modos',
                        cancelButtonText: 'Cancelar'
                    });
                    if (!proceed.isConfirmed) { return; }
                }
                const confirm = await Swal.fire({
                    // Build the title by interpolating the count into the
                    // lang string ourselves. The lang string template uses
                    // the Moodle placeholder {$a} which is NOT substituted
                    // by get_string() when called without args from PHP, so
                    // we do it here with a plain string replace.
                    title: (this.strings.generate_for || 'Generar diploma para {n} estudiante(s)')
                        .replace('{$a}', this.selected.length)
                        .replace('{n}', this.selected.length),
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: this.strings.generate_selected,
                    cancelButtonText: this.strings.cancel
                });
                if (!confirm.isConfirmed) return;

                this.generating = true;
                Swal.fire({
                    title: this.strings.generation_in_progress,
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading()
                });

                try {
                    const items = this.selected.map(s => ({ userid: s.user.id, learningplanid: s.plan.id }));
                    const res = await this.http.post('/', {
                        action: 'local_grupomakro_diploma_generate_diplomas',
                        templateid: this.selectedTemplateId,
                        items: JSON.stringify(items)
                    });
                    Swal.close();
                    if (res.data && res.data.status === 'success') {
                        // Use the formatted message from the backend (placeholders
                        // already replaced via get_string); do NOT use
                        // this.strings.generation_done as title because it is
                        // the raw template 'Se generaron {$a->success}...'.
                        const text = res.data.message || 'Operación completada';
                        Swal.fire({ icon: 'success', title: 'Completado', text: text });
                        this.selected = [];
                        await this.loadGraduands();
                        await this.loadPlanCounts();
                        await this.loadGenerations();
                    } else {
                        throw new Error((res.data && res.data.message) || 'Error');
                    }
                } catch (e) {
                    Swal.close();
                    Swal.fire({ icon: 'error', title: 'Error', text: e.message || e });
                } finally {
                    this.generating = false;
                }
            },
            async downloadOne(item) {
                // Show a Swal loader while the browser downloads the PDF.
                // We use a direct redirect to pages/diploma_image.php?gen=X
                // so the browser handles the streaming natively (no base64
                // roundtrip through JSON), which is much faster than the
                // previous AJAX+atob+Blob approach.
                let swalShown = false;
                try {
                    Swal.fire({
                        title: 'Preparando diploma…',
                        html: 'El archivo PDF se está descargando.',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        didOpen: () => {
                            swalShown = true;
                            Swal.showLoading();
                        }
                    });
                    // Build the URL with sesskey for CSRF.
                    const sesskey = (window.M && window.M.cfg && window.M.cfg.sesskey) || '';
                    const url = (window.location.origin || '') +
                        '/local/grupomakro_core/pages/diploma_image.php' +
                        '?gen=' + encodeURIComponent(item.id) +
                        '&download=1' +
                        (sesskey ? '&sesskey=' + encodeURIComponent(sesskey) : '');
                    // Trigger the download. Using a hidden iframe avoids
                    // navigating the page away.
                    const iframe = document.createElement('iframe');
                    iframe.style.display = 'none';
                    iframe.src = url;
                    document.body.appendChild(iframe);
                    // Close the loader shortly after the iframe starts
                    // loading (the browser keeps the download in the
                    // background even after we close it).
                    setTimeout(() => {
                        if (swalShown) { Swal.close(); swalShown = false; }
                        try { document.body.removeChild(iframe); } catch (e) { /* noop */ }
                    }, 1500);
                } catch (e) {
                    if (swalShown) { Swal.close(); }
                    Swal.fire({ icon: 'error', title: 'Error', text: e.message || e });
                }
            },
            confirmRevoke(item) {
                Swal.fire({
                    title: this.strings.revoke,
                    text: this.strings.revoke_confirm,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: this.strings.revoke,
                    cancelButtonText: this.strings.cancel
                }).then(r => {
                    if (r.isConfirmed) this.revoke(item);
                });
            },
            async revoke(item) {
                try {
                    const res = await this.http.post('/', {
                        action: 'local_grupomakro_diploma_revoke_generation',
                        id: item.id,
                        reason: 'Revocado por administrador'
                    });
                    if (res.data && res.data.status === 'success') {
                        Swal.fire({ icon: 'success', title: res.data.message });
                        await this.loadGenerations();
                        await this.loadPlanCounts();
                    } else {
                        throw new Error((res.data && res.data.message) || 'Error');
                    }
                } catch (e) {
                    Swal.fire({ icon: 'error', title: 'Error', text: e.message || e });
                }
            },
            formatDate(ts) {
                if (!ts) return '';
                try {
                    const d = new Date(ts * 1000);
                    return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                } catch (e) { return ''; }
            }
        }
    });

    // Mount the Vue app on the #gmk-app element emitted by diplomageneration.php.
    // The component is registered above so <diplomageneration> resolves once mounted.
    function mountDiplomaGeneration() {
        var root = document.getElementById('gmk-app');
        if (!root) { return; }
        if (root.__vue_app__) { return; }
        var app = new Vue({
            el: root,
            vuetify: new Vuetify({ theme: { dark: false } })
        });
        root.__vue_app__ = app;
        if (window.console && console.log) {
            console.log('[grupomakro_core] diplomageneration mounted');
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mountDiplomaGeneration);
    } else {
        mountDiplomaGeneration();
    }
})();
