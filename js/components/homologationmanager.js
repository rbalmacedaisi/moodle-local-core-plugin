/* eslint-disable */
// Homologation Manager component.
// Define origin->destination course homologation rules, preview the pending
// homologations across students enrolled in both plans (using resolved grades),
// and apply them in bulk.
Vue.component('homologationmanager', {
    template: `
    <v-container fluid class="pa-4" style="max-width:1280px;">
        <h2 class="mb-1"><v-icon left color="deep-purple darken-2">mdi-swap-horizontal-bold</v-icon>Gestor de Homologaciones</h2>
        <p class="grey--text text--darken-1 mb-4">
            Define reglas por asignatura (plan origen → asignatura origen → plan destino → asignatura destino),
            previsualiza las homologaciones que proceden y aplícalas. La nota se toma de la calificación real
            (la que muestra el panel), y se omiten los destinos ya aprobados u homologados.
        </p>

        <!-- Definir regla -->
        <v-card outlined class="mb-4">
            <v-card-title class="subtitle-1 font-weight-bold py-2">
                <v-icon left small>mdi-plus-box</v-icon>Definir regla
            </v-card-title>
            <v-divider></v-divider>
            <v-card-text>
                <v-row dense>
                    <v-col cols="12" md="6">
                        <div class="caption font-weight-bold text-uppercase grey--text">Origen</div>
                        <v-select :items="planItems" v-model="form.originPlanId" label="Plan de origen"
                            dense outlined hide-details class="mb-2" @change="form.originCourseId=null"></v-select>
                        <v-select :items="originCourseItems" v-model="form.originCourseId" label="Asignatura de origen"
                            dense outlined hide-details :disabled="!form.originPlanId" no-data-text="Selecciona un plan"></v-select>
                    </v-col>
                    <v-col cols="12" md="6">
                        <div class="caption font-weight-bold text-uppercase grey--text">Destino</div>
                        <v-select :items="planItems" v-model="form.destPlanId" label="Plan de destino"
                            dense outlined hide-details class="mb-2" @change="form.destCourseId=null"></v-select>
                        <v-select :items="destCourseItems" v-model="form.destCourseId" label="Asignatura de destino"
                            dense outlined hide-details :disabled="!form.destPlanId" no-data-text="Selecciona un plan"></v-select>
                    </v-col>
                </v-row>
                <v-row dense class="mt-2" align="center">
                    <v-col cols="12" md="4">
                        <v-select :items="typeOptions" v-model="form.type" label="Tipo de homologación"
                            dense outlined hide-details></v-select>
                    </v-col>
                    <v-col cols="12" md="8" class="text-md-right">
                        <v-btn color="deep-purple darken-2" dark :loading="savingRule" :disabled="!canAddRule" @click="addRule">
                            <v-icon left>mdi-plus</v-icon>Agregar regla
                        </v-btn>
                    </v-col>
                </v-row>
            </v-card-text>
        </v-card>

        <!-- Reglas definidas -->
        <v-card outlined class="mb-4">
            <v-card-title class="subtitle-1 font-weight-bold py-2">
                <v-icon left small>mdi-table</v-icon>Reglas definidas (cuadro)
                <v-chip x-small class="ml-2" color="deep-purple lighten-4">{{ rules.length }}</v-chip>
                <v-spacer></v-spacer>
                <v-btn small color="primary" :loading="previewing" :disabled="rules.length===0" @click="runPreview">
                    <v-icon left small>mdi-eye</v-icon>Previsualizar homologaciones
                </v-btn>
            </v-card-title>
            <v-divider></v-divider>
            <v-data-table :headers="ruleHeaders" :items="rules" :items-per-page="10" dense
                :loading="loadingData" no-data-text="Aún no hay reglas. Agrega la primera arriba.">
                <template v-slot:item.origin="{ item }">
                    <div class="font-weight-medium">{{ item.origin_coursename }}</div>
                    <div class="caption grey--text">{{ item.origin_planname }}</div>
                </template>
                <template v-slot:item.arrow="{ item }"><v-icon small color="deep-purple">mdi-arrow-right-bold</v-icon></template>
                <template v-slot:item.dest="{ item }">
                    <div class="font-weight-medium">{{ item.dest_coursename }}</div>
                    <div class="caption grey--text">{{ item.dest_planname }}</div>
                </template>
                <template v-slot:item.homologation_type="{ item }">
                    <v-chip x-small label>{{ typeLabel(item.homologation_type) }}</v-chip>
                </template>
                <template v-slot:item.actions="{ item }">
                    <v-btn icon x-small color="error" @click="deleteRule(item)"><v-icon small>mdi-delete</v-icon></v-btn>
                </template>
            </v-data-table>
        </v-card>

        <!-- Previsualizacion -->
        <v-card outlined class="mb-4" v-if="previewSummary">
            <v-card-title class="subtitle-1 font-weight-bold py-2">
                <v-icon left small>mdi-eye-check</v-icon>Previsualización
                <v-spacer></v-spacer>
                <v-btn color="success" :loading="applying" :disabled="previewRows.length===0" @click="applyAll">
                    <v-icon left>mdi-check-bold</v-icon>Confirmar y aplicar ({{ previewRows.length }})
                </v-btn>
            </v-card-title>
            <v-divider></v-divider>
            <v-card-text>
                <div class="mb-3">
                    <v-chip class="ma-1" color="success" dark small>Pendientes: {{ previewSummary.pending }}</v-chip>
                    <v-chip class="ma-1" small>Destino ya aprobado/homologado: {{ previewSummary.dest_already_done }}</v-chip>
                    <v-chip class="ma-1" small>Origen no aprobado: {{ previewSummary.origin_not_approved }}</v-chip>
                    <v-chip class="ma-1" small outlined>Revisados: {{ previewSummary.students_scanned }}</v-chip>
                </div>
                <v-text-field v-model="previewSearch" prepend-inner-icon="mdi-magnify" label="Buscar estudiante / asignatura"
                    dense outlined hide-details clearable class="mb-2"></v-text-field>
                <v-data-table :headers="previewHeaders" :items="previewRows" :search="previewSearch"
                    :items-per-page="15" dense no-data-text="No hay homologaciones pendientes para las reglas definidas.">
                    <template v-slot:item.origin="{ item }">
                        {{ item.origin_coursename }}
                        <div class="caption grey--text">Plan {{ item.origin_planid }}</div>
                    </template>
                    <template v-slot:item.origin_grade="{ item }">
                        <v-chip x-small color="blue lighten-4">{{ Number(item.origin_grade).toFixed(2) }}</v-chip>
                    </template>
                    <template v-slot:item.dest_coursename="{ item }">
                        {{ item.dest_coursename }}
                        <div class="caption grey--text">Plan {{ item.dest_planid }}</div>
                    </template>
                    <template v-slot:item.result_status="{ item }">
                        <v-chip x-small :color="item.result_status===4 ? 'green lighten-4':'red lighten-4'">
                            {{ item.result_status===4 ? 'Aprobada' : 'Reprobada' }}
                        </v-chip>
                    </template>
                </v-data-table>
            </v-card-text>
        </v-card>

        <!-- Resultado -->
        <v-card outlined class="mb-4" v-if="applySummary">
            <v-card-title class="subtitle-1 font-weight-bold py-2">
                <v-icon left small color="success">mdi-check-decagram</v-icon>Resultado de la aplicación
            </v-card-title>
            <v-divider></v-divider>
            <v-card-text>
                <v-chip class="ma-1" color="success" dark>Aplicadas: {{ applySummary.applied }}</v-chip>
                <v-chip class="ma-1" :color="applySummary.errors>0 ? 'error':'grey lighten-2'" :dark="applySummary.errors>0">
                    Errores: {{ applySummary.errors }}
                </v-chip>
                <v-data-table v-if="applyResults.length" :headers="resultHeaders" :items="applyResults"
                    :items-per-page="15" dense class="mt-3">
                    <template v-slot:item.status="{ item }">
                        <v-icon small :color="item.status==='ok' ? 'success':'error'">
                            {{ item.status==='ok' ? 'mdi-check' : 'mdi-alert' }}
                        </v-icon>
                    </template>
                    <template v-slot:item.grade="{ item }">{{ Number(item.grade).toFixed(2) }}</template>
                </v-data-table>
            </v-card-text>
        </v-card>
    </v-container>
    `,
    data() {
        return {
            plans: [],
            coursesByPlan: {},
            rules: [],
            form: { originPlanId: null, originCourseId: null, destPlanId: null, destCourseId: null, type: 'homologacion' },
            typeOptions: [
                { text: 'Homologación', value: 'homologacion' },
                { text: 'Suficiencia', value: 'suficiencia' },
                { text: 'Migración', value: 'migracion' },
                { text: 'Práctica Profesional', value: 'practica' },
            ],
            ruleHeaders: [
                { text: 'Origen', value: 'origin', sortable: false },
                { text: '', value: 'arrow', sortable: false, width: 40 },
                { text: 'Destino', value: 'dest', sortable: false },
                { text: 'Tipo', value: 'homologation_type', sortable: false, width: 130 },
                { text: '', value: 'actions', sortable: false, width: 60 },
            ],
            previewHeaders: [
                { text: 'Estudiante', value: 'fullname' },
                { text: 'Identificación', value: 'idnumber' },
                { text: 'Asignatura origen', value: 'origin', sortable: false },
                { text: 'Nota', value: 'origin_grade' },
                { text: 'Asignatura destino', value: 'dest_coursename', sortable: false },
                { text: 'Estado resultante', value: 'result_status' },
            ],
            resultHeaders: [
                { text: '', value: 'status', sortable: false, width: 40 },
                { text: 'Estudiante', value: 'fullname' },
                { text: 'Asignatura destino', value: 'dest_coursename' },
                { text: 'Nota', value: 'grade' },
                { text: 'Mensaje', value: 'message' },
            ],
            previewRows: [],
            previewSummary: null,
            previewSearch: '',
            applyResults: [],
            applySummary: null,
            loadingData: false,
            savingRule: false,
            previewing: false,
            applying: false,
        };
    },
    computed: {
        planItems() { return this.plans.map(p => ({ text: p.name, value: p.id })); },
        originCourseItems() {
            const list = this.coursesByPlan[this.form.originPlanId] || [];
            return list.map(c => ({ text: c.name, value: c.id }));
        },
        destCourseItems() {
            const list = this.coursesByPlan[this.form.destPlanId] || [];
            return list.map(c => ({ text: c.name, value: c.id }));
        },
        canAddRule() {
            return this.form.originPlanId && this.form.originCourseId && this.form.destPlanId && this.form.destCourseId;
        },
    },
    mounted() {
        this.loadFormData();
        this.loadRules();
    },
    methods: {
        async ws(action, params = {}) {
            const url = M.cfg.wwwroot + '/local/grupomakro_core/ajax.php';
            const res = await axios.get(url, { params: Object.assign({ action: action, sesskey: M.cfg.sesskey }, params) });
            return res.data || {};
        },
        typeLabel(t) {
            const m = { homologacion: 'Homologación', suficiencia: 'Suficiencia', migracion: 'Migración', practica: 'Práctica Prof.' };
            return m[t] || t;
        },
        toast(icon, title) {
            if (window.Swal) {
                window.Swal.fire({ toast: true, position: 'top-end', icon: icon, title: title, showConfirmButton: false, timer: 2600 });
            }
        },
        async loadFormData() {
            this.loadingData = true;
            try {
                const r = await this.ws('local_grupomakro_homolmgr_get_form_data');
                const d = (r.data) ? r.data : r;
                this.plans = d.plans || [];
                this.coursesByPlan = d.courses || {};
            } catch (e) {
                console.error(e); this.toast('error', 'No se pudo cargar planes/asignaturas.');
            } finally {
                this.loadingData = false;
            }
        },
        async loadRules() {
            try {
                const r = await this.ws('local_grupomakro_homolmgr_list_rules');
                const d = (r.data) ? r.data : r;
                this.rules = d.rules || [];
            } catch (e) { console.error(e); }
        },
        async addRule() {
            if (!this.canAddRule) return;
            this.savingRule = true;
            try {
                const r = await this.ws('local_grupomakro_homolmgr_save_rule', {
                    originPlanId: this.form.originPlanId,
                    originCourseId: this.form.originCourseId,
                    destPlanId: this.form.destPlanId,
                    destCourseId: this.form.destCourseId,
                    type: this.form.type,
                });
                const d = (r.data) ? r.data : r;
                if (d.status === 'ok') {
                    this.toast('success', 'Regla agregada.');
                    this.form.originCourseId = null;
                    this.form.destCourseId = null;
                    await this.loadRules();
                } else {
                    this.toast('error', d.message || 'No se pudo guardar la regla.');
                }
            } catch (e) { console.error(e); this.toast('error', 'Error al guardar la regla.'); }
            finally { this.savingRule = false; }
        },
        async deleteRule(item) {
            if (window.Swal) {
                const res = await window.Swal.fire({
                    icon: 'warning', title: '¿Eliminar regla?',
                    html: '<b>' + item.origin_coursename + '</b> → <b>' + item.dest_coursename + '</b>',
                    showCancelButton: true, confirmButtonColor: '#d33', confirmButtonText: 'Eliminar', cancelButtonText: 'Cancelar',
                });
                if (!res.isConfirmed) return;
            }
            try {
                await this.ws('local_grupomakro_homolmgr_delete_rule', { id: item.id });
                await this.loadRules();
                this.toast('success', 'Regla eliminada.');
            } catch (e) { console.error(e); this.toast('error', 'No se pudo eliminar.'); }
        },
        async runPreview() {
            this.previewing = true;
            this.applySummary = null;
            this.applyResults = [];
            try {
                const r = await this.ws('local_grupomakro_homolmgr_preview');
                const d = (r.data) ? r.data : r;
                this.previewRows = d.rows || [];
                this.previewSummary = d.summary || null;
                if (this.previewRows.length === 0) {
                    this.toast('info', 'No hay homologaciones pendientes.');
                }
            } catch (e) { console.error(e); this.toast('error', 'Error al previsualizar.'); }
            finally { this.previewing = false; }
        },
        async applyAll() {
            if (this.previewRows.length === 0) return;
            if (window.Swal) {
                const res = await window.Swal.fire({
                    icon: 'question', title: '¿Aplicar homologaciones?',
                    html: 'Se aplicarán <b>' + this.previewRows.length + '</b> homologaciones. Esta acción registra notas y es auditada (reversible por estudiante desde el panel).',
                    showCancelButton: true, confirmButtonColor: '#2e7d32', confirmButtonText: 'Sí, aplicar', cancelButtonText: 'Cancelar',
                });
                if (!res.isConfirmed) return;
            }
            this.applying = true;
            try {
                const r = await this.ws('local_grupomakro_homolmgr_apply');
                const d = (r.data) ? r.data : r;
                this.applySummary = { applied: d.applied || 0, errors: d.errors || 0 };
                this.applyResults = d.results || [];
                this.toast(d.errors > 0 ? 'warning' : 'success', 'Aplicadas ' + (d.applied || 0) + ', errores ' + (d.errors || 0) + '.');
                // Refresh preview so applied ones drop off.
                await this.runPreview();
            } catch (e) { console.error(e); this.toast('error', 'Error al aplicar.'); }
            finally { this.applying = false; }
        },
    },
});
