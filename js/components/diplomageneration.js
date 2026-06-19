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
                                    <v-col v-for="p in planCounts" :key="p.id" cols="6" sm="4" md="3" lg="2">
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
                                            <div class="pa-6 text-center grey--text">{{ strings.no_graduands }}</div>
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
                generating: false
            };
        },
        computed: {
            strings() { return window.strings || {}; },
            planItems() {
                return [{ text: this.strings.all_careers, value: null }].concat(
                    this.plans.map(p => ({ text: p.name, value: p.id }))
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
                    { text: 'Periodo', value: 'plan.periodname', sortable: false }
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
            async loadGraduands() {
                try {
                    const payload = {
                        action: 'local_grupomakro_diploma_list_graduands',
                        learningplanid: this.selectedPlanId || 0,
                        search: this.search || ''
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
                const confirm = await Swal.fire({
                    title: (this.strings.generate_for || 'Generar diploma para {n} estudiante(s)').replace('{n}', this.selected.length),
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
                        Swal.fire({ icon: 'success', title: this.strings.generation_done || 'Completado', text: res.data.message });
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
                try {
                    const res = await this.http.post('/', {
                        action: 'local_grupomakro_diploma_download_generation',
                        id: item.id
                    });
                    if (res.data && res.data.status === 'success') {
                        const doc = res.data.document;
                        const binary = atob(doc.contentbase64);
                        const len = binary.length;
                        const bytes = new Uint8Array(len);
                        for (let i = 0; i < len; i++) { bytes[i] = binary.charCodeAt(i); }
                        const blob = new Blob([bytes], { type: doc.mimetype });
                        const url = URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = doc.filename || ('diploma_' + item.id + '.pdf');
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        URL.revokeObjectURL(url);
                    } else {
                        throw new Error((res.data && res.data.message) || 'Error');
                    }
                } catch (e) {
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
