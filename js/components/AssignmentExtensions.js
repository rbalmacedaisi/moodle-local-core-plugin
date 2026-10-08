/**
 * AssignmentExtensions component
 *
 * Modal que el docente abre desde TeacherDashboard.js para asignar plazos
 * individuales de entrega a uno o varios estudiantes de una clase. Permite:
 *  - Elegir la actividad (Assign) de la clase.
 *  - Ver la fecha de entrega por defecto y las prorrogas vigentes.
 *  - Seleccionar uno o varios estudiantes y fijarles un nuevo deadline.
 *  - Anadir una razon comun.
 *  - Aplicar el batch con feedback por resultado.
 *  - Borrar prorrogas vigentes.
 */

const AssignmentExtensions = {
    template: `
        <v-dialog v-model="open" max-width="980" transition="dialog-bottom-transition" scrollable>
            <v-card class="rounded-xl">
                <v-toolbar flat color="primary" dark class="px-4">
                    <v-icon left>mdi-calendar-clock</v-icon>
                    <v-toolbar-title class="font-weight-bold">
                        Excepciones de entrega
                    </v-toolbar-title>
                    <v-spacer></v-spacer>
                    <span v-if="classInfo && classInfo.name" class="text-caption mr-3">
                        {{ classInfo.name }}
                    </span>
                    <v-btn icon @click="close"><v-icon>mdi-close</v-icon></v-btn>
                </v-toolbar>

                <v-card-text class="pa-5">
                    <!-- Step 1: Activity picker (solo si el modal NO fue
                         abierto desde una actividad especifica). Si assignId
                         viene como prop, mostramos solo el nombre y ocultamos
                         el dropdown para forzar el contexto de esa actividad. -->
                    <div v-if="!assignId" class="d-flex align-center mb-4">
                        <v-icon color="primary" class="mr-2">mdi-book-open-page-variant</v-icon>
                        <v-select
                            v-model="selectedAssignId"
                            :items="assignments"
                            item-title="name"
                            item-value="assignid"
                            label="Actividad"
                            placeholder="Selecciona una actividad para la que quieres asignar excepciones"
                            :loading="loading.assignments"
                            :disabled="loading.assignments || assignments.length === 0"
                            outlined dense
                            hide-details
                            class="flex-grow-1"
                            @update:model-value="onActivityChange"
                        ></v-select>
                        <v-tooltip v-if="assignments.length === 0 && !loading.assignments" bottom>
                            <template v-slot:activator="{ on, attrs }">
                                <v-icon v-bind="attrs" v-on="on" color="grey lighten-1" class="ml-2">mdi-information-outline</v-icon>
                            </template>
                            <span>Esta clase no tiene actividades tipo Assign visibles.</span>
                        </v-tooltip>
                    </div>

                    <!-- Si el modal se abrio con assignId pre-seleccionado, mostramos
                         un chip de solo-lectura con el nombre de la actividad y
                         omitimos el dropdown de Step 1. -->
                    <div v-else class="d-flex align-center mb-4">
                        <v-icon color="primary" class="mr-2">mdi-book-open-page-variant</v-icon>
                        <v-chip small color="primary" dark class="mr-2">
                            <v-icon x-small left>mdi-book-open-page-variant</v-icon>
                            {{ assignmentName || ('Actividad #' + assignId) }}
                        </v-chip>
                        <span class="caption grey--text">
                            (Las pr\u00f3rrogas aplican solo a esta actividad.)
                        </span>
                    </div>

                    <!-- Default duedate + override count -->
                    <div v-if="selectedAssignId" class="mb-4 grey--text text--darken-2">
                        <v-row dense>
                            <v-col cols="12" sm="6">
                                <v-chip small :color="defaultDueDate ? 'primary' : 'grey lighten-2'" class="mr-2">
                                    <v-icon x-small left>mdi-calendar</v-icon>
                                    Entrega por defecto: {{ defaultDueDate ? formatDate(defaultDueDate) : 'sin fecha global' }}
                                </v-chip>
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-chip small :color="overrides.length > 0 ? 'orange darken-2' : 'grey lighten-2'" class="mr-2">
                                    <v-icon x-small left>mdi-clock-fast</v-icon>
                                    {{ overrides.length }} prorroga(s) vigente(s)
                                </v-chip>
                            </v-col>
                        </v-row>
                    </div>

                    <!-- Step 2: Students + due date picker -->
                    <div v-if="selectedAssignId" class="mb-3">
                        <div class="d-flex align-center mb-2">
                            <h3 class="text-subtitle-2 font-weight-bold mb-0">
                                <v-icon small color="primary" class="mr-1">mdi-account-multiple</v-icon>
                                Estudiantes matriculados
                            </h3>
                            <v-spacer></v-spacer>
                            <v-btn x-small text color="primary" @click="applyToAll">
                                <v-icon x-small left>mdi-calendar-edit</v-icon>
                                Aplicar a todos con esta fecha
                            </v-btn>
                            <v-btn x-small text color="error" @click="clearAll" :disabled="!hasAnyOverrideDirty">
                                <v-icon x-small left>mdi-close</v-icon>
                                Limpiar mis cambios
                            </v-btn>
                        </div>

                        <v-data-table
                            v-model="selectedStudentIds"
                            :headers="studentHeaders"
                            :items="students"
                            item-value="userid"
                            show-select
                            dense
                            :loading="loading.students"
                            class="elevation-1 rounded-lg"
                            :items-per-page="-1"
                            hide-default-footer
                            :search="search"
                        >
                            <template v-slot:top>
                                <v-text-field
                                    v-model="search"
                                    append-icon="mdi-magnify"
                                    label="Buscar estudiante"
                                    single-line
                                    hide-details
                                    dense
                                    class="ma-2"
                                ></v-text-field>
                            </template>

                            <template v-slot:item.current_duedate="{ item }">
                                <span :class="item.override_duedate ? 'orange--text text--darken-2 font-weight-bold' : 'grey--text'">
                                    {{ item.override_duedate ? formatDate(item.override_duedate) : (defaultDueDate ? formatDate(defaultDueDate) : '—') }}
                                </span>
                            </template>

                            <template v-slot:item.new_duedate="{ item }">
                                <v-text-field
                                    type="datetime-local"
                                    v-model="newDueDates[item.userid]"
                                    :placeholder="defaultDueDate ? formatDateTimeLocal(defaultDueDate) : ''"
                                    dense hide-details
                                    :disabled="applying"
                                    @input="onNewDateInput(item.userid)"
                                    style="max-width: 220px;"
                                ></v-text-field>
                            </template>

                            <template v-slot:item.actions="{ item }">
                                <v-btn
                                    x-small
                                    color="error"
                                    text
                                    :disabled="applying || !item.override_duedate"
                                    @click="deleteOne(item)"
                                >
                                    <v-icon x-small>mdi-trash-can</v-icon>
                                </v-btn>
                            </template>
                        </v-data-table>
                    </div>

                    <!-- Common reason -->
                    <div v-if="selectedAssignId && students.length > 0" class="mt-4">
                        <v-textarea
                            v-model="commonReason"
                            label="Razon comun (opcional, se aplica a todos los estudiantes de este lote)"
                            outlined dense
                            rows="2"
                            auto-grow
                            counter="200"
                            maxlength="200"
                            hide-details
                        ></v-textarea>
                    </div>
                </v-card-text>

                <v-divider></v-divider>

                <v-card-actions class="px-5 py-3">
                    <div v-if="lastResult" class="text-caption mr-auto" :class="lastResult.status === 'success' ? 'success--text' : 'error--text'">
                        <v-icon x-small :color="lastResult.status === 'success' ? 'success' : 'error'" class="mr-1">
                            {{ lastResult.status === 'success' ? 'mdi-check-circle' : 'mdi-alert-circle' }}
                        </v-icon>
                        {{ lastResult.message }}
                    </div>
                    <v-spacer></v-spacer>
                    <v-btn text @click="close" :disabled="applying">Cancelar</v-btn>
                    <v-btn
                        color="primary"
                        :loading="applying"
                        :disabled="applying || !selectedAssignId || pendingCount === 0"
                        @click="apply"
                    >
                        <v-icon left small>mdi-check-bold</v-icon>
                        Aplicar {{ pendingCount }} prorroga(s)
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    `,
    props: {
        modelValue: { type: Boolean, default: false },
        classId: { type: [Number, String], default: null },
        classInfo: { type: Object, default: () => ({}) },
        // Cuando el modal se abre desde la lista de actividades individuales
        // (ManageClass > tab "Actividades"), pre-seleccionamos esta actividad
        // y ocultamos el dropdown del step 1.
        assignId: { type: [Number, String], default: null },
        assignmentName: { type: String, default: '' },
    },
    data() {
        return {
            open: false,
            search: '',
            selectedAssignId: null,
            selectedStudentIds: [],
            assignments: [],
            students: [],
            newDueDates: {}, // {userid: 'YYYY-MM-DDTHH:MM'}
            overrides: [], // overrides vigentes del selectedAssignId
            history: [],
            defaultDueDate: null,
            loading: { assignments: false, students: false },
            applying: false,
            commonReason: '',
            lastResult: null,
            studentHeaders: [
                { text: 'Estudiante', value: 'user_name' },
                { text: 'Email', value: 'user_email' },
                { text: 'Fecha actual', value: 'current_duedate', sortable: false },
                { text: 'Nueva fecha', value: 'new_duedate', sortable: false, width: '240px' },
                { text: '', value: 'actions', sortable: false, align: 'end', width: '60px' },
            ],
        };
    },
    computed: {
        pendingCount() {
            return Object.keys(this.newDueDates).filter((uid) => {
                const v = this.newDueDates[uid];
                if (!v) return false;
                const ts = this.parseLocalDateTime(v);
                return ts && ts > Math.floor(Date.now() / 1000);
            }).length;
        },
        hasAnyOverrideDirty() {
            return this.pendingCount > 0;
        },
    },
    watch: {
        modelValue(v) { this.open = v; if (v) this.bootstrap(); },
        open(v) { if (!v) this.$emit('update:modelValue', false); },
    },
    created() {
        // Si el padre ya pasa modelValue=true al montar (caso normal cuando
        // se abre el modal desde una actividad especifica: el padre setea
        // extensionsOpen=true ANTES de montar el componente), el watch de
        // modelValue NO se dispara porque Vue 2 no hace immediate por default.
        // Forzamos la apertura inicial aqui. Usamos $nextTick para esperar
        // a que Vue termine de procesar todas las prop bindings del primer
        // render antes de abrir el dialog (asi evitamos una condicion de
        // carrera donde modelValue es false en created() pero true despues).
        console.log('[GMK DEBUG] AssignmentExtensions created. modelValue =', this.modelValue, 'assignId =', this.assignId);
        this.$nextTick(() => {
            console.log('[GMK DEBUG] nextTick fired. modelValue =', this.modelValue);
            if (this.modelValue) {
                this.open = true;
                this.bootstrap();
            }
        });
    },
    methods: {
        async bootstrap() {
            this.lastResult = null;
            // Si el padre paso assignId como prop, ese es el contexto y no
            // se debe permitir cambiarlo (es la unica actividad de esta modal).
            // Si no, selectedAssignId queda null hasta que el usuario elija.
            this.selectedAssignId = this.assignId ? parseInt(this.assignId, 10) : null;
            this.students = [];
            this.newDueDates = {};
            this.overrides = [];
            this.history = [];
            this.defaultDueDate = null;
            this.search = '';
            this.commonReason = '';
            this.selectedStudentIds = [];

            if (!this.classId) return;

            // Si el padre ya paso assignId, NO cargamos la lista de
            // assignments: la actividad es fija. Saltamos directo a
            // onActivityChange() para que cargue estudiantes y overrides.
            if (this.selectedAssignId) {
                this.loading.assignments = false;
                this.assignments = [];
                await this.onActivityChange();
                return;
            }

            this.loading.assignments = true;
            try {
                const response = await axios.post(wsUrl, {
                    action: 'local_grupomakro_list_course_assignments',
                    args: { courseid: parseInt(this.classId, 10) },
                    ...wsStaticParams
                });
                if (response.data && response.data.status === 'success') {
                    this.assignments = response.data.assignments || [];
                } else {
                    this.lastResult = { status: 'error', message: 'No se pudieron cargar las actividades.' };
                    this.assignments = [];
                }
            } catch (e) {
                console.error('AssignmentExtensions.bootstrap error:', e);
                this.lastResult = { status: 'error', message: 'Error cargando actividades: ' + (e.message || e) };
            } finally {
                this.loading.assignments = false;
            }
        },
        async onActivityChange() {
            if (!this.selectedAssignId) return;
            this.lastResult = null;
            this.newDueDates = {};
            this.selectedStudentIds = [];

            // Carga paralelo: students + overrides/history. Las respuestas de
            // ajax.php vienen envueltas en {data: {...}} (mismo patron que el
            // resto del codigo, ej. get_dashboard_data, FailedSubjectsReport).
            this.loading.students = true;
            try {
                const [studentsResp, listResp] = await Promise.all([
                    axios.post(wsUrl, {
                        action: 'local_grupomakro_list_course_students_for_overrides',
                        args: { courseid: parseInt(this.classId, 10), assignid: parseInt(this.selectedAssignId, 10) },
                        ...wsStaticParams
                    }),
                    axios.post(wsUrl, {
                        action: 'local_grupomakro_list_assignment_extensions',
                        args: { assignid: parseInt(this.selectedAssignId, 10) },
                        ...wsStaticParams
                    })
                ]);
                if (studentsResp.data && studentsResp.data.status === 'success') {
                    this.students = (studentsResp.data.data && studentsResp.data.data.students) || [];
                } else {
                    // Mostrar el error real del backend (permisos, contexto, etc).
                    const errMsg = (studentsResp.data && studentsResp.data.message)
                        || (studentsResp.data && studentsResp.data.errorcode)
                        || 'No se pudieron cargar los estudiantes.';
                    console.error('[GMK DEBUG] studentsResp error:', studentsResp.data);
                    this.lastResult = { status: 'error', message: errMsg };
                }
                if (listResp.data && listResp.data.status === 'success') {
                    const ld = listResp.data.data || {};
                    this.overrides = ld.overrides || [];
                    this.history = ld.history || [];
                    this.defaultDueDate = (ld.default_duedate || 0) > 0 ? ld.default_duedate : null;
                }
            } catch (e) {
                console.error('AssignmentExtensions.onActivityChange error:', e);
                this.lastResult = { status: 'error', message: 'Error cargando datos: ' + (e.message || e) };
            } finally {
                this.loading.students = false;
            }
        },
        onNewDateInput(userid) {
            // trigger reactivity: just ensure entry exists
            if (!(userid in this.newDueDates)) {
                this.$set(this.newDueDates, userid, '');
            }
        },
        applyToAll() {
            // Use the first non-empty date, or default + 7d as suggestion
            let suggestion = '';
            for (const uid of Object.keys(this.newDueDates)) {
                const v = this.newDueDates[uid];
                if (v) { suggestion = v; break; }
            }
            if (!suggestion && this.defaultDueDate) {
                suggestion = this.formatDateTimeLocal(this.defaultDueDate);
            }
            if (!suggestion) {
                suggestion = this.formatDateTimeLocal(Math.floor(Date.now() / 1000) + 7 * 86400);
            }
            // Apply to all students
            for (const s of this.students) {
                this.$set(this.newDueDates, s.userid, suggestion);
            }
        },
        clearAll() {
            this.newDueDates = {};
        },
        parseLocalDateTime(s) {
            if (!s) return null;
            // s = 'YYYY-MM-DDTHH:MM' (datetime-local)
            const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(s);
            if (!m) return null;
            const ts = new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], 0).getTime();
            return Math.floor(ts / 1000);
        },
        formatDate(unix) {
            if (!unix) return '';
            const d = new Date(unix * 1000);
            return d.toLocaleDateString('es-PA', { day: '2-digit', month: '2-digit', year: 'numeric' }) +
                ' ' + d.toLocaleTimeString('es-PA', { hour: '2-digit', minute: '2-digit' });
        },
        formatDateTimeLocal(unix) {
            if (!unix) return '';
            const d = new Date(unix * 1000);
            const pad = (n) => String(n).padStart(2, '0');
            return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
                + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
        },
        async apply() {
            if (!this.selectedAssignId || this.pendingCount === 0) return;
            this.applying = true;
            this.lastResult = null;
            try {
                // Solo los pendientes (no vacios, en el futuro)
                const entries = Object.entries(this.newDueDates)
                    .map(([uid, v]) => ({ uid: parseInt(uid, 10), ts: this.parseLocalDateTime(v) }))
                    .filter((e) => e.ts && e.ts > Math.floor(Date.now() / 1000));

                const results = await Promise.all(entries.map((e) => axios.post(wsUrl, {
                    action: 'local_grupomakro_set_assignment_user_override',
                    args: {
                        assignid: parseInt(this.selectedAssignId, 10),
                        userid: e.uid,
                        duedate: e.ts,
                        reason: this.commonReason || ''
                    },
                    ...wsStaticParams
                }).then((r) => ({ userid: e.uid, ok: r.data && r.data.status === 'success', msg: r.data && r.data.message })).catch((err) => ({ userid: e.uid, ok: false, msg: err.message }))));

                const oks = results.filter((r) => r.ok).length;
                const fails = results.length - oks;
                if (fails === 0) {
                    this.lastResult = { status: 'success', message: `${oks} prorroga(s) aplicada(s) correctamente.` };
                } else {
                    this.lastResult = { status: 'error', message: `${oks} OK, ${fails} con error. Revisa los logs.` };
                }
                // Recarga overrides vigentes
                await this.onActivityChange();
            } catch (e) {
                this.lastResult = { status: 'error', message: 'Error aplicando batch: ' + (e.message || e) };
            } finally {
                this.applying = false;
            }
        },
        async deleteOne(student) {
            if (!this.selectedAssignId) return;
            this.applying = true;
            try {
                const resp = await axios.post(wsUrl, {
                    action: 'local_grupomakro_delete_assignment_user_override',
                    args: { assignid: parseInt(this.selectedAssignId, 10), userid: student.userid },
                    ...wsStaticParams
                });
                if (resp.data && resp.data.status === 'success') {
                    this.lastResult = { status: 'success', message: 'Prorroga borrada para ' + student.user_name };
                    // Quita el row del newDueDates por si estaba alli
                    if (this.newDueDates[student.userid] !== undefined) {
                        this.$delete(this.newDueDates, student.userid);
                    }
                    await this.onActivityChange();
                } else {
                    this.lastResult = { status: 'error', message: (resp.data && resp.data.message) || 'Error borrando.' };
                }
            } catch (e) {
                this.lastResult = { status: 'error', message: 'Error borrando: ' + (e.message || e) };
            } finally {
                this.applying = false;
            }
        },
        close() {
            this.open = false;
            this.$emit('update:modelValue', false);
        },
    },
};

// Auto-register so TeacherDashboard (and any other consumer) can render
// <assignment-extensions> in their templates without extra glue.
if (typeof window !== 'undefined' && window.Vue) {
    window.Vue.component('assignment-extensions', AssignmentExtensions);
}
window.AssignmentExtensions = AssignmentExtensions;