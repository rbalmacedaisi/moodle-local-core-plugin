// CSS for the attendance matrix is injected at mount time. Vue strips
// <style> blocks from inline templates with only a "side-effects in
// templates" warning, leaving the matrix table without its sticky headers,
// borders and per-state coloring. Injecting once into <head> keeps the
// styles active even when this component is mounted multiple times.
(function injectAttendanceMatrixStyles() {
    if (typeof document === 'undefined') return;
    if (document.getElementById('attendance-matrix-styles')) return;
    const style = document.createElement('style');
    style.id = 'attendance-matrix-styles';
    style.textContent = `
.att-matrix-table { border-collapse: collapse; min-width: 100%; }
.att-matrix-table th, .att-matrix-table td { border: 1px solid #e0e0e0; padding: 4px 6px; white-space: nowrap; }
.att-sticky-col { position: sticky; left: 0; z-index: 2; background: #fff; }
.att-header-student { background: #f5f5f5 !important; font-weight: 600; font-size: 12px; min-width: 210px; text-align: left; }
.att-session-header { text-align: center; font-size: 11px; font-weight: 600; width: 70px; min-width: 70px; max-width: 70px; vertical-align: top; padding: 4px 2px; }
.att-session-taken { background: #1565c0 !important; color: #fff !important; }
.att-session-future { background: #eeeeee !important; color: #757575 !important; }
.att-session-pending { background: #fff3e0 !important; color: #e65100 !important; }
.att-session-revalida { background: #fff8e1 !important; color: #6d4c00 !important; border-top: 3px solid #ff8f00 !important; }
.att-session-revalida.att-session-taken { background: #fff8e1 !important; color: #6d4c00 !important; }
.att-session-date { font-size: 12px; font-weight: 700; }
.att-session-time { font-size: 10px; font-weight: 400; opacity: 0.85; }
.att-student-cell { min-width: 210px; background: #fff; }
.att-cell { text-align: center; width: 70px; min-width: 70px; max-width: 70px; }
.att-cell-untaken { background: #fafafa; }
.att-cell-editable { cursor: pointer; }
.att-cell-editable:hover { outline: 2px solid #1565c0; outline-offset: -2px; }
.att-summary-header { background: #e3f2fd !important; text-align: center; font-size: 11px; font-weight: 600; width: 80px; min-width: 80px; max-width: 80px; position: sticky; right: 0; z-index: 2; }
.att-summary-cell { text-align: center; background: #e3f2fd; position: sticky; right: 0; z-index: 1; }
.att-student-row:hover td { background: #f5f5f5 !important; }
.att-student-row:hover .att-summary-cell { background: #bbdefb !important; }
.att-legend-dot { display: inline-block; width: 12px; height: 12px; border-radius: 2px; }
.att-dot-taken { background: #1565c0; }
.att-dot-pending { background: #ff8f00; }
.att-dot-future { background: #bdbdbd; }
.att-dot-revalida { background: #fff8e1; border: 1px solid #ff8f00; }
`;
    document.head.appendChild(style);
})();

Vue.component('attendance-matrix', {
    template: `
        <v-card flat class="attendance-matrix-card rounded-lg border">
            <v-card-title class="d-flex align-center py-2 px-4">
                <div class="text-h6 font-weight-bold">
                    <v-icon left>mdi-calendar-check</v-icon>
                    Registro de Asistencias
                </div>
                <v-spacer></v-spacer>
                <div v-if="!loading && sessions.length" class="caption grey--text mr-3">
                    {{ takenCount }} sesiones tomadas de {{ sessions.length }} programadas<span v-if="revalidaCount > 0"> · {{ revalidaCount }} excluida(s) como reválida</span>
                </div>
                <v-chip v-if="canMark && selectable.length" x-small label color="blue lighten-5"
                    class="mr-3 blue--text text--darken-3">
                    <v-icon x-small left color="blue darken-2">mdi-cursor-default-click</v-icon>
                    Clic en una marca para registrar retraso o inasistencia
                </v-chip>
                <v-btn icon small @click="fetchMatrix" :loading="loading">
                    <v-icon>mdi-refresh</v-icon>
                </v-btn>
            </v-card-title>

            <v-divider></v-divider>

            <v-card-text class="pa-0">
                <div v-if="loading && !students.length" class="text-center pa-10">
                    <v-progress-circular indeterminate color="primary"></v-progress-circular>
                    <p class="mt-2 grey--text">Cargando asistencias...</p>
                </div>

                <div v-else-if="error" class="red--text text-center pa-10">
                    <v-icon color="red" size="48">mdi-alert-circle-outline</v-icon>
                    <p class="mt-2 text-body-1">{{ error }}</p>
                    <v-btn depressed color="primary" @click="fetchMatrix">Reintentar</v-btn>
                </div>

                <div v-else-if="!sessions.length && !loading" class="text-center pa-10">
                    <v-icon color="grey lighten-1" size="48">mdi-calendar-blank-outline</v-icon>
                    <p class="mt-2 grey--text">No hay sesiones de asistencia registradas para esta clase.</p>
                </div>

                <div v-else class="att-matrix-container" style="overflow-x:auto;overflow-y:auto;width:100%;max-height:520px;">
                    <table class="att-matrix-table">
                        <thead>
                            <tr>
                                <th class="att-sticky-col att-header-student">
                                    <div class="d-flex align-center">
                                        <v-icon small class="mr-1">mdi-account-group</v-icon>
                                        Estudiante
                                        <span class="ml-1 caption">({{ students.length }})</span>
                                    </div>
                                </th>
                                <th v-for="sess in sessions" :key="sess.id"
                                    class="att-session-header"
                                    :class="{
                                        'att-session-revalida': isRevalida(sess),
                                        'att-session-taken': sess.taken && !isRevalida(sess),
                                        'att-session-future': sess.future && !sess.taken && !isRevalida(sess),
                                        'att-session-pending': !sess.taken && !sess.future && !isRevalida(sess)
                                    }"
                                    :title="sess.description || sess.date">
                                    <div class="att-session-date">{{ sess.dateshort }}</div>
                                    <div class="att-session-time">{{ sess.time }}</div>
                                    <v-tooltip v-if="isRevalida(sess)" bottom>
                                        <template v-slot:activator="{ on, attrs }">
                                            <v-icon v-bind="attrs" v-on="on" x-small class="mt-1" color="amber darken-3" style="cursor:help">mdi-school-outline</v-icon>
                                        </template>
                                        <span>Esta sesión es de reválida. No se contabiliza en el % de asistencia ni en las faltas.</span>
                                    </v-tooltip>
                                    <v-icon v-else-if="sess.future && !sess.taken" x-small class="mt-1" color="grey darken-1">mdi-clock-outline</v-icon>
                                    <v-icon v-else-if="!sess.taken && !sess.future" x-small class="mt-1" color="orange darken-2">mdi-alert</v-icon>
                                </th>
                                <th class="att-summary-header">
                                    <div>% Asist.</div>
                                    <div class="caption font-weight-regular">(tomadas)</div>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="student in students" :key="student.id" class="att-student-row">
                                <td class="att-sticky-col att-student-cell">
                                    <div class="d-flex align-center">
                                        <v-avatar size="28" color="primary lighten-4" class="mr-2 flex-shrink-0">
                                            <span class="primary--text font-weight-bold" style="font-size:11px">{{ student.fullname.charAt(0) }}</span>
                                        </v-avatar>
                                        <div style="line-height:1.2;min-width:0">
                                            <div class="text-body-2 font-weight-medium text-truncate" style="max-width:170px">{{ student.fullname }}</div>
                                            <div class="text-caption grey--text text-truncate" style="max-width:170px">{{ student.email }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td v-for="sess in sessions" :key="sess.id" class="att-cell"
                                    :class="{ 'att-cell-untaken': !sess.taken, 'att-cell-editable': canEditCell(sess) }">
                                    <!-- Editable cell: the teacher may set absence or
                                         late, never present. Undo only appears on marks
                                         they made themselves. -->
                                    <v-menu v-if="canEditCell(sess)" offset-y :close-on-content-click="true">
                                        <template v-slot:activator="{ on, attrs }">
                                            <div v-bind="attrs" v-on="on" style="cursor:pointer">
                                                <v-chip v-if="getLog(student.id, sess.id)"
                                                    x-small
                                                    :color="getLog(student.id, sess.id).grade > 0 ? 'success' : 'error'"
                                                    dark
                                                    :title="getLog(student.id, sess.id).description">
                                                    {{ getLog(student.id, sess.id).acronym }}
                                                </v-chip>
                                                <v-chip v-else x-small color="grey lighten-1" title="Sin registrar">–</v-chip>
                                            </div>
                                        </template>
                                        <v-list dense>
                                            <v-subheader class="text-caption">
                                                {{ student.fullname }} · {{ sess.dateshort }}
                                            </v-subheader>
                                            <v-list-item v-for="st in selectable" :key="st.id"
                                                @click="markCell(student, sess, st)"
                                                :disabled="saving">
                                                <v-list-item-icon class="mr-2">
                                                    <v-chip x-small :color="st.grade > 0 ? 'warning' : 'error'" dark>
                                                        {{ st.acronym }}
                                                    </v-chip>
                                                </v-list-item-icon>
                                                <v-list-item-content>
                                                    <v-list-item-title class="text-body-2">{{ st.description }}</v-list-item-title>
                                                </v-list-item-content>
                                            </v-list-item>
                                            <v-divider v-if="canUndoCell(student, sess)"></v-divider>
                                            <v-list-item v-if="canUndoCell(student, sess)"
                                                @click="undoCell(student, sess)" :disabled="saving">
                                                <v-list-item-icon class="mr-2">
                                                    <v-icon small color="grey darken-1">mdi-undo</v-icon>
                                                </v-list-item-icon>
                                                <v-list-item-content>
                                                    <v-list-item-title class="text-body-2">Deshacer mi marca</v-list-item-title>
                                                    <v-list-item-subtitle class="text-caption">
                                                        Restaura lo que había antes
                                                    </v-list-item-subtitle>
                                                </v-list-item-content>
                                            </v-list-item>
                                            <v-divider></v-divider>
                                            <v-list-item disabled dense>
                                                <v-list-item-content class="py-1">
                                                    <v-list-item-subtitle class="text-caption" style="white-space:normal">
                                                        La asistencia presente solo se registra por QR o
                                                        por la sesión virtual.
                                                    </v-list-item-subtitle>
                                                </v-list-item-content>
                                            </v-list-item>
                                        </v-list>
                                    </v-menu>
                                    <!-- Read-only cell -->
                                    <template v-else-if="sess.taken">
                                        <v-chip v-if="getLog(student.id, sess.id)"
                                            x-small
                                            :color="getLog(student.id, sess.id).grade > 0 ? 'success' : 'error'"
                                            dark
                                            :title="getLog(student.id, sess.id).description">
                                            {{ getLog(student.id, sess.id).acronym }}
                                        </v-chip>
                                        <v-chip v-else x-small color="grey lighten-1" title="Sin registrar">–</v-chip>
                                    </template>
                                    <span v-else class="grey--text text--lighten-2" style="font-size:11px">·</span>
                                </td>
                                <td class="att-summary-cell">
                                    <v-chip x-small
                                        :color="attendancePctColor(student.id)"
                                        dark>
                                        {{ attendancePct(student.id) }}%
                                    </v-chip>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Legend -->
                <div v-if="statuses.length && sessions.length" class="d-flex align-center flex-wrap pa-3 pt-2 grey lighten-5" style="border-top:1px solid #e0e0e0;gap:8px">
                    <span class="caption font-weight-bold grey--text text--darken-2 mr-1">Estado:</span>
                    <div v-for="st in statuses" :key="st.id" class="d-flex align-center mr-3">
                        <v-chip x-small :color="st.grade > 0 ? 'success' : 'error'" dark class="mr-1">{{ st.acronym }}</v-chip>
                        <span class="caption grey--text">{{ st.description }}</span>
                    </div>
                    <div class="d-flex align-center mr-3">
                        <v-chip x-small color="grey lighten-1" class="mr-1">–</v-chip>
                        <span class="caption grey--text">Sin registrar</span>
                    </div>
                    <v-spacer></v-spacer>
                    <div class="d-flex align-center" style="gap:10px">
                        <div class="d-flex align-center">
                            <span class="att-legend-dot att-dot-taken mr-1"></span>
                            <span class="caption grey--text">Tomada</span>
                        </div>
                        <div class="d-flex align-center">
                            <span class="att-legend-dot att-dot-pending mr-1"></span>
                            <span class="caption grey--text">Pendiente</span>
                        </div>
                        <div class="d-flex align-center">
                            <span class="att-legend-dot att-dot-future mr-1"></span>
                            <span class="caption grey--text">Futura</span>
                        </div>
                        <div class="d-flex align-center" v-if="revalidaCount > 0">
                            <v-icon x-small color="amber darken-3" class="mr-1">mdi-school-outline</v-icon>
                            <span class="caption grey--text">Reválida ({{ revalidaCount }})</span>
                        </div>
                    </div>
                </div>
</v-card-text>
        </v-card>
    `,

    props: {
        classId: { type: Number, default: 0 },
        active:  { type: Boolean, default: false },
    },

    data() {
        return {
            sessions:  [],
            students:  [],
            statuses:  [],
            selectable: [],   // states the teacher may set (never "present")
            canMark:   false, // capability, resolved by the backend
            matrix:    {},
            loading:   false,
            saving:    false,
            error:     null,
            takenCount: 0,
        };
    },

    watch: {
        active(val) {
            if (val && !this.sessions.length && !this.loading) {
                this.fetchMatrix();
            }
        },
        classId() {
            this.sessions = [];
            this.students = [];
            this.statuses = [];
            this.selectable = [];
            this.matrix   = {};
            this.error    = null;
            if (this.active) this.fetchMatrix();
        },
    },

    computed: {
        takenSessions() {
            return this.sessions.filter((s) => s.taken);
        },
        revalidaCount() {
            return this.sessions.filter((s) => this.isRevalida(s)).length;
        },
    },

    methods: {
        // A cell is editable when the teacher has the capability, the session
        // already happened and it is not a revalidation session (those don't
        // count towards attendance at all).
        canEditCell(sess) {
            if (!this.canMark || !this.selectable.length) return false;
            if (!sess || sess.future) return false;
            return !this.isRevalida(sess);
        },
        // Undo is offered only for marks this same teacher made.
        canUndoCell(student, sess) {
            const log = this.getLog(student.id, sess.id);
            return !!(log && log.mine);
        },
        async markCell(student, sess, status) {
            if (this.saving) return;
            this.saving = true;
            try {
                const params = new URLSearchParams({
                    action: 'local_grupomakro_teacher_mark_attendance',
                    classid: this.classId,
                    sessionid: sess.id,
                    studentid: student.id,
                    statusid: status.id,
                    sesskey: window.M && window.M.cfg ? window.M.cfg.sesskey : (window.Y ? window.Y.config.sesskey : '')
                });
                const r = await axios.post(window.wsUrl || (window.M.cfg.wwwroot + '/local/grupomakro_core/ajax.php'), params);
                if (r.data && r.data.status === 'success') {
                    await this.fetchMatrix();
                } else {
                    alert((r.data && r.data.message) || 'No se pudo registrar la marca.');
                }
            } catch (e) {
                console.error('[AttendanceMatrix] markCell', e);
                alert('No se pudo registrar la marca. Intente nuevamente.');
            } finally {
                this.saving = false;
            }
        },
        async undoCell(student, sess) {
            if (this.saving) return;
            this.saving = true;
            try {
                const params = new URLSearchParams({
                    action: 'local_grupomakro_teacher_undo_attendance',
                    classid: this.classId,
                    sessionid: sess.id,
                    studentid: student.id,
                    sesskey: window.M && window.M.cfg ? window.M.cfg.sesskey : (window.Y ? window.Y.config.sesskey : '')
                });
                const r = await axios.post(window.wsUrl || (window.M.cfg.wwwroot + '/local/grupomakro_core/ajax.php'), params);
                if (r.data && r.data.status === 'success') {
                    await this.fetchMatrix();
                } else {
                    alert((r.data && r.data.message) || 'No se pudo deshacer la marca.');
                }
            } catch (e) {
                console.error('[AttendanceMatrix] undoCell', e);
                alert('No se pudo deshacer la marca. Intente nuevamente.');
            } finally {
                this.saving = false;
            }
        },
        isRevalida(sess) {
            if (!sess) return false;
            const v = sess.is_revalida;
            return v === 1 || v === '1' || v === true;
        },
        async fetchMatrix() {
            if (!this.classId) return;
            this.loading = true;
            this.error   = null;
            try {
                const url = window.wsUrl || (window.location.origin + '/local/grupomakro_core/ajax.php');
                const params = new URLSearchParams({
                    action:  'local_grupomakro_get_class_attendance_matrix',
                    sesskey: (window.M && window.M.cfg && window.M.cfg.sesskey) ? window.M.cfg.sesskey : '',
                    classid: String(this.classId),
                });
                const resp = await window.axios.post(url, params);
                const data = resp && resp.data ? resp.data : {};
                if (data.status !== 'success') {
                    this.error = data.message || 'Error al cargar asistencias.';
                    return;
                }
                this.sessions   = Array.isArray(data.sessions) ? data.sessions : [];
                this.students   = Array.isArray(data.students) ? data.students : [];
                this.statuses   = Array.isArray(data.statuses) ? data.statuses : [];
                this.selectable = Array.isArray(data.selectable) ? data.selectable : [];
                this.canMark    = !!data.can_mark;
                this.takenCount = Number(data.taken_count || 0);
                // Normalize matrix: backend sends object {uid: {sid: {...}}}
                const raw = data.matrix || {};
                const normalized = {};
                Object.keys(raw).forEach((uid) => {
                    normalized[uid] = raw[uid] || {};
                });
                this.matrix = normalized;
            } catch (err) {
                this.error = 'Error de conexión al cargar asistencias.';
                console.error('[AttendanceMatrix] fetchMatrix error:', err);
            } finally {
                this.loading = false;
            }
        },

        getLog(userId, sessionId) {
            const uKey = String(userId);
            const sKey = String(sessionId);
            return (this.matrix[uKey] && this.matrix[uKey][sKey]) ? this.matrix[uKey][sKey] : null;
        },

        attendancePct(userId) {
            const taken = this.takenSessions;
            if (!taken.length) return 0;
            let present = 0;
            taken.forEach((s) => {
                const log = this.getLog(userId, s.id);
                if (log && log.grade > 0) present++;
            });
            return Math.round((present / taken.length) * 100);
        },

        attendancePctColor(userId) {
            const pct = this.attendancePct(userId);
            if (pct >= 75) return 'success';
            if (pct >= 50) return 'orange darken-1';
            return 'error';
        },
    },

    mounted() {
        if (this.active && this.classId) {
            this.fetchMatrix();
        }
    },
});
