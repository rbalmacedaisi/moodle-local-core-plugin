/**
 * statusChangeWizard
 *
 * Multi-step Vuetify dialog launched from the academicpanel "Renovar / Aplazar
 * / Retirar" menu. Walks the operator through:
 *   1) Diagnóstico    — student state, active courses, pending invoices.
 *   2) Acción         — radio: aplazar / retirar (retirar only if not blocked).
 *   3) Periodo        — for aplazar: pick the target academic period.
 *   4) Motivo         — required reason text + chip suggestions.
 *   5) Confirmación   — final summary before submit.
 *
 * Expects `window.wsUrl`, `M.cfg.sesskey`, `M.cfg.wwwroot` available globally.
 *
 * Emits:
 *   - 'close'    : ask parent to unmount / hide.
 *   - 'executed' : payload {action, userid, response} so parent can refresh.
 */
Vue.component('status-change-wizard', {
    props: {
        value: { type: Boolean, default: false },
        userid: { type: Number, required: true },
        studentName: { type: String, default: '' },
        initialAction: { type: String, default: '' }, // 'aplazar' | 'retirar' | ''
    },
    template: `
        <v-dialog :value="value" @input="$emit('input', $event)" persistent max-width="820" scrollable>
            <v-card>
                <v-card-title class="white--text" :class="headerColor">
                    <v-icon left color="white">{{ headerIcon }}</v-icon>
                    {{ headerTitle }}
                    <v-spacer></v-spacer>
                    <v-btn icon dark @click="close"><v-icon>mdi-close</v-icon></v-btn>
                </v-card-title>

                <v-card-text style="max-height: 75vh;">
                    <v-stepper v-model="step" vertical flat>
                        <!-- Step 1: Diagnóstico -->
                        <v-stepper-step :complete="step > 1" step="1" :color="step === 1 ? 'amber darken-2' : ''">
                            Diagnóstico
                            <small v-if="preview">Estado actual: {{ preview?.academicstatus }}</small>
                        </v-stepper-step>
                        <v-stepper-content step="1">
                            <div v-if="loadingPreview" class="text-center pa-6">
                                <v-progress-circular indeterminate color="amber darken-2"></v-progress-circular>
                                <div class="caption mt-2">Cargando diagnóstico del estudiante...</div>
                            </div>
                            <template v-else-if="preview">
                                <div class="text-h6 mb-3">{{ preview?.fullname }}
                                    <span class="caption ml-2 grey--text">({{ preview?.username }})</span>
                                </div>

                                <!-- Asignaturas activas -->
                                <v-alert v-if="activeCoursesCount > 0" type="warning" dense class="mb-3" border="left">
                                    <div class="font-weight-bold mb-1">
                                        <v-icon left>mdi-book-alert</v-icon>
                                        {{ activeCoursesCount }} asignatura(s) activa(s) serán des-matriculadas
                                    </div>
                                    <div class="text-caption">
                                        El estudiante perderá el progreso académico de los cursos en los que está cursando actualmente.
                                    </div>
                                    <v-list dense class="mt-2" style="max-height: 180px; overflow-y: auto;">
                                        <v-list-item v-for="ac in activeCourses" :key="ac.gcpid">
                                            <v-list-item-content>
                                                <v-list-item-title class="text-body-2">{{ ac.name }}</v-list-item-title>
                                                <v-list-item-subtitle class="text-caption">
                                                    Plan: {{ ac.plan_name }}
                                                    <span v-if="ac.progress !== null"> · Progreso: {{ ac.progress }}%</span>
                                                </v-list-item-subtitle>
                                            </v-list-item-content>
                                        </v-list-item>
                                    </v-list>
                                </v-alert>
                                <v-alert v-else type="success" dense class="mb-3" border="left">
                                    El estudiante no tiene asignaturas activas.
                                </v-alert>

                                <!-- Facturas pendientes -->
                                <v-alert v-if="preview?.pending_invoices_unavailable" type="grey" dense class="mb-3" border="left" icon="mdi-cloud-off-outline">
                                    <div class="text-caption">No se pudo contactar a Odoo para obtener el detalle de facturas pendientes. La información financiera puede estar desactualizada.</div>
                                </v-alert>
                                <v-alert v-else-if="preview?.pending_invoices?.length === 0" type="success" dense class="mb-3" border="left">
                                    El estudiante está al día con sus facturas.
                                </v-alert>
                                <v-alert v-else :type="hasOverdueInvoices ? 'error' : 'warning'" dense class="mb-3" border="left">
                                    <div class="font-weight-bold mb-1">
                                        <v-icon left>mdi-receipt-text-remove</v-icon>
                                        {{ preview?.pending_invoices?.length }} factura(s) pendiente(s)
                                        <span v-if="hasOverdueInvoices">— {{ overdueCount }} en mora</span>
                                    </div>
                                    <v-list dense style="max-height: 180px; overflow-y: auto;">
                                        <v-list-item v-for="inv in preview.pending_invoices" :key="inv.id">
                                            <v-list-item-content>
                                                <v-list-item-title class="text-body-2">
                                                    {{ inv.number || ('Factura #' + inv.id) }}
                                                    <v-chip x-small :color="inv.is_overdue ? 'red' : 'amber'" dark class="ml-2">
                                                        {{ inv.is_overdue ? 'EN MORA' : 'PENDIENTE' }}
                                                    </v-chip>
                                                </v-list-item-title>
                                                <v-list-item-subtitle class="text-caption">
                                                    Vence: {{ inv.invoice_date_due || '--' }}
                                                    · Saldo: {{ formatMoney(inv.amount_residual, inv.currency) }}
                                                </v-list-item-subtitle>
                                            </v-list-item-content>
                                        </v-list-item>
                                    </v-list>
                                </v-alert>

                                <div class="d-flex mt-3">
                                    <v-spacer></v-spacer>
                                    <v-btn color="amber darken-2" dark @click="step = 2" :disabled="!canContinueFromDiagnostic">
                                        Siguiente
                                        <v-icon right>mdi-arrow-right</v-icon>
                                    </v-btn>
                                </div>
                            </template>
                            <v-alert v-else-if="previewError" type="error" dense class="mt-2">{{ previewError }}</v-alert>
                        </v-stepper-content>

                        <!-- Step 2: Acción -->
                        <v-stepper-step :complete="step > 2" step="2">Acción</v-stepper-step>
                        <v-stepper-content step="2">
                            <v-radio-group v-model="form.action" column>
                                <v-radio value="aplazar" :disabled="hasOverdueInvoices" color="amber darken-2">
                                    <template v-slot:label>
                                        <div>
                                            <div class="font-weight-bold">Aplazar</div>
                                            <div class="caption grey--text">
                                                Marca al estudiante como aplazado y reagenda sus facturas para el periodo destino.
                                                <span v-if="hasOverdueInvoices" class="error--text"> · No disponible (hay facturas en mora)</span>
                                            </div>
                                        </div>
                                    </template>
                                </v-radio>
                                <v-radio value="retirar" color="red darken-2">
                                    <template v-slot:label>
                                        <div>
                                            <div class="font-weight-bold">Retirar</div>
                                            <div class="caption grey--text">
                                                Da de baja al estudiante. Pierde todas sus matrículas activas.
                                            </div>
                                        </div>
                                    </template>
                                </v-radio>
                            </v-radio-group>
                            <div class="d-flex mt-3">
                                <v-btn text @click="step = 1">Atrás</v-btn>
                                <v-spacer></v-spacer>
                                <v-btn color="amber darken-2" dark @click="step = 3" :disabled="!form.action">
                                    Siguiente
                                    <v-icon right>mdi-arrow-right</v-icon>
                                </v-btn>
                            </div>
                        </v-stepper-content>

                        <!-- Step 3: Periodo destino (solo aplazar) -->
                        <v-stepper-step v-if="form.action === 'aplazar'" :complete="step > 3" step="3">Periodo destino</v-stepper-step>
                        <v-stepper-content v-if="form.action === 'aplazar'" step="3">
                            <v-select
                                v-model="form.target_period_id"
                                :items="preview ? preview.target_periods : []"
                                item-text="name"
                                item-value="id"
                                label="Periodo lectivo al que se aplazará el estudiante"
                                :hint="suggestedPeriodHint"
                                persistent-hint
                                dense
                                outlined
                            ></v-select>
                            <div class="d-flex mt-3">
                                <v-btn text @click="step = 2">Atrás</v-btn>
                                <v-spacer></v-spacer>
                                <v-btn color="amber darken-2" dark @click="step = 4" :disabled="!form.target_period_id">
                                    Siguiente
                                    <v-icon right>mdi-arrow-right</v-icon>
                                </v-btn>
                            </div>
                        </v-stepper-content>

                        <!-- Step 4: Motivo -->
                        <v-stepper-step :complete="step > (form.action === 'aplazar' ? 4 : 3)" :step="form.action === 'aplazar' ? 4 : 3">Motivo</v-stepper-step>
                        <v-stepper-content :step="form.action === 'aplazar' ? 4 : 3">
                            <div class="mb-2">
                                <span class="caption grey--text">Sugerencias:</span>
                                <v-chip v-for="s in reasonSuggestions" :key="s" small class="mr-2 mb-2"
                                        @click="form.reason = (form.reason ? form.reason + '. ' : '') + s">
                                    {{ s }}
                                </v-chip>
                            </div>
                            <v-textarea
                                v-model="form.reason"
                                label="Motivo (mínimo 10 caracteres)"
                                :rules="[v => (v && v.length >= 10) || 'Mínimo 10 caracteres']"
                                rows="3"
                                outlined
                                counter
                                autofocus
                            ></v-textarea>
                            <div class="d-flex mt-3">
                                <v-btn text @click="step = form.action === 'aplazar' ? 3 : 2">Atrás</v-btn>
                                <v-spacer></v-spacer>
                                <v-btn color="amber darken-2" dark @click="step = confirmStep"
                                       :disabled="!form.reason || form.reason.length < 10">
                                    Siguiente
                                    <v-icon right>mdi-arrow-right</v-icon>
                                </v-btn>
                            </div>
                        </v-stepper-content>

                        <!-- Step 5: Confirmación -->
                        <v-stepper-step :complete="false" :step="confirmStep">Confirmar</v-stepper-step>
                        <v-stepper-content :step="confirmStep">
                            <v-list dense>
                                <v-list-item v-if="preview">
                                    <v-list-item-content>
                                        <v-list-item-title class="caption grey--text">Estudiante</v-list-item-title>
                                        <v-list-item-subtitle class="font-weight-bold">{{ preview?.fullname }}</v-list-item-subtitle>
                                    </v-list-item-content>
                                </v-list-item>
                                <v-list-item>
                                    <v-list-item-content>
                                        <v-list-item-title class="caption grey--text">Acción</v-list-item-title>
                                        <v-list-item-subtitle>
                                            <v-chip :color="form.action === 'retirar' ? 'red' : 'amber'" dark small>
                                                {{ form.action === 'retirar' ? 'RETIRAR' : 'APLAZAR' }}
                                            </v-chip>
                                        </v-list-item-subtitle>
                                    </v-list-item-content>
                                </v-list-item>
                                <v-list-item v-if="form.action === 'aplazar'">
                                    <v-list-item-content>
                                        <v-list-item-title class="caption grey--text">Periodo destino</v-list-item-title>
                                        <v-list-item-subtitle>{{ selectedPeriodName }}</v-list-item-subtitle>
                                    </v-list-item-content>
                                </v-list-item>
                                <v-list-item>
                                    <v-list-item-content>
                                        <v-list-item-title class="caption grey--text">Motivo</v-list-item-title>
                                        <v-list-item-subtitle>{{ form.reason }}</v-list-item-subtitle>
                                    </v-list-item-content>
                                </v-list-item>
                                <v-list-item v-if="activeCoursesCount > 0">
                                    <v-list-item-content>
                                        <v-list-item-title class="caption grey--text">Asignaturas a des-matricular</v-list-item-title>
                                        <v-list-item-subtitle>{{ activeCoursesCount }} curso(s)</v-list-item-subtitle>
                                    </v-list-item-content>
                                </v-list-item>
                            </v-list>

                            <v-alert type="warning" dense class="mt-3" border="left">
                                <div class="font-weight-bold">Esta acción no se puede deshacer.</div>
                                <div class="text-caption">
                                    Se registrará en el historial académico del estudiante con tu nombre de usuario.
                                </div>
                            </v-alert>

                            <v-alert v-if="executeError" type="error" dense class="mt-3">
                                {{ executeError }}
                            </v-alert>

                            <div class="d-flex mt-3">
                                <v-btn text @click="step = form.action === 'aplazar' ? 4 : 3"
                                       :disabled="executing">Atrás</v-btn>
                                <v-spacer></v-spacer>
                                <v-btn :color="form.action === 'retirar' ? 'red' : 'amber darken-2'" dark
                                       @click="execute" :loading="executing">
                                    <v-icon left>mdi-check-bold</v-icon>
                                    Confirmar {{ form.action === 'retirar' ? 'retiro' : 'aplazo' }}
                                </v-btn>
                            </div>
                        </v-stepper-content>
                    </v-stepper>
                </v-card-text>
            </v-card>
        </v-dialog>
    `,
    data() {
        return {
            step: 1,
            loadingPreview: false,
            preview: null,
            previewError: '',
            executing: false,
            executeError: '',
            form: {
                action: '',
                target_period_id: null,
                reason: '',
            },
            reasonSuggestions: [
                'Problemas económicos',
                'Problemas de salud',
                'Cambio de carrera',
                'Traslado de ciudad',
                'Decisión personal',
                'Otro',
            ],
        };
    },
    computed: {
        confirmStep() {
            return this.form.action === 'aplazar' ? 5 : 4;
        },
        activeCourses() {
            if (!this.preview) return [];
            const all = [];
            for (const c of this.preview?.carrers) {
                for (const ac of (c.active_courses || [])) {
                    all.push(Object.assign({}, ac, { plan_name: c.plan_name }));
                }
            }
            return all;
        },
        activeCoursesCount() {
            return this.activeCourses.length;
        },
        hasOverdueInvoices() {
            return this.preview && this.preview.pending_invoices &&
                this.preview.pending_invoices.some(inv => inv.is_overdue);
        },
        overdueCount() {
            return this.preview ? this.preview.pending_invoices.filter(inv => inv.is_overdue).length : 0;
        },
        canContinueFromDiagnostic() {
            if (!this.preview) return false;
            // Si está al día, habilitar todo; si tiene mora, sólo retirar.
            if (!this.hasOverdueInvoices) return true;
            return this.form.action !== '';
        },
        selectedPeriodName() {
            if (!this.preview || !this.form.target_period_id) return '--';
            const p = (this.preview?.target_periods || []).find(p => p.id === this.form.target_period_id);
            return p ? p.name : ('Periodo #' + this.form.target_period_id);
        },
        suggestedPeriodHint() {
            if (!this.preview || !this.form.target_period_id) return '';
            const p = (this.preview?.target_periods || []).find(p => p.id === this.form.target_period_id);
            if (!p || !p.startdate) return '';
            const start = new Date(p.startdate * 1000);
            return 'Inicio: ' + start.toISOString().substring(0, 10);
        },
        headerTitle() {
            if (!this.form.action) return 'Cambiar estado académico';
            return this.form.action === 'retirar' ? 'Retirar estudiante' : 'Aplazar estudiante';
        },
        headerIcon() {
            if (!this.form.action) return 'mdi-account-cog';
            return this.form.action === 'retirar' ? 'mdi-account-cancel' : 'mdi-pause-circle';
        },
        headerColor() {
            if (!this.form.action) return 'teal darken-1';
            return this.form.action === 'retirar' ? 'red darken-2' : 'amber darken-2';
        },
    },
    watch: {
        value(v) {
            if (v) {
                this.open();
            }
        },
        initialAction: {
            immediate: false,
            handler(v) {
                if (v && (v === 'aplazar' || v === 'retirar')) {
                    this.form.action = v;
                }
            },
        },
    },
    mounted() {
        if (this.value) {
            this.open();
        }
        if (this.initialAction) {
            this.form.action = this.initialAction;
        }
    },
    methods: {
        open() {
            this.step = 1;
            this.previewError = '';
            this.executeError = '';
            this.form.reason = '';
            // action can be pre-set from menu (aplazar/retirar), or empty (let user pick).
            if (this.initialAction) {
                this.form.action = this.initialAction;
            }
            this.loadPreview();
        },
        close() {
            this.$emit('input', false);
            this.$emit('close');
        },
        async loadPreview() {
            this.loadingPreview = true;
            this.previewError = '';
            try {
                const params = new URLSearchParams();
                params.append('action', 'local_grupomakro_get_status_change_preview');
                params.append('sesskey', M.cfg.sesskey);
                params.append('userid', this.userid);
                const resp = await axios.post(`${M.cfg.wwwroot}/local/grupomakro_core/ajax.php`, params);
                if (resp.data && resp.data.status === 'success') {
                    this.preview = resp.data.data;
                    // Auto-pick suggested period for reactivation or first active one.
                    if (this.form.action === 'aplazar' && !this.form.target_period_id) {
                        const inProgress = (this.preview?.target_periods || []).find(p => {
                            if (!p.startdate || !p.enddate) return false;
                            const now = Math.floor(Date.now() / 1000);
                            return p.startdate <= now && now <= p.enddate;
                        });
                        if (inProgress) {
                            this.form.target_period_id = inProgress.id;
                        } else if ((this.preview?.target_periods || []).length > 0) {
                            this.form.target_period_id = (this.preview?.target_periods || [])[0].id;
                        }
                    }
                } else {
                    this.previewError = (resp.data && resp.data.message) || 'No se pudo cargar el diagnóstico.';
                }
            } catch (e) {
                this.previewError = 'Error de conexión: ' + (e.message || e);
            } finally {
                this.loadingPreview = false;
            }
        },
        async execute() {
            this.executing = true;
            this.executeError = '';
            try {
                const params = new URLSearchParams();
                params.append('action', 'local_grupomakro_execute_status_change');
                params.append('sesskey', M.cfg.sesskey);
                params.append('userid', this.userid);
                params.append('action_name', this.form.action);
                params.append('reason', this.form.reason);
                if (this.form.action === 'aplazar' && this.form.target_period_id) {
                    params.append('target_period_id', this.form.target_period_id);
                }
                const resp = await axios.post(`${M.cfg.wwwroot}/local/grupomakro_core/ajax.php`, params);
                if (resp.data && resp.data.status === 'success') {
                    this.$emit('executed', {
                        action: this.form.action,
                        userid: this.userid,
                        response: resp.data,
                    });
                    this.close();
                } else {
                    this.executeError = (resp.data && resp.data.message) || 'No se pudo aplicar el cambio.';
                }
            } catch (e) {
                this.executeError = 'Error de conexión: ' + (e.message || e);
            } finally {
                this.executing = false;
            }
        },
        formatMoney(amount, currency) {
            if (amount === null || amount === undefined) return '--';
            try {
                return new Intl.NumberFormat('es-PA', {
                    style: 'currency',
                    currency: currency || 'USD',
                }).format(amount);
            } catch (e) {
                return (currency || 'USD') + ' ' + amount.toFixed(2);
            }
        },
    },
});