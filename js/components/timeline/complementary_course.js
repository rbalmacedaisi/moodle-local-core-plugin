// Curso complementario: carga un curso de los planes complementarios (ingles conversacional, natacion, seminarios...) a una
// cohorte elegida (ingreso, jornada) como grupo adicional del tablero de horarios. Esos planes no entran en la proyeccion,
// por eso no aparecen como asignatura que "les toca".
// Datos: WS local_grupomakro_planning_save_config, seccion complement_list (solo lectura). Enviar al tablero usa extra_add
// (el mismo grupo adicional de "Proximo periodo"): ahi se le pone dia, hora, aula y docente, y se publica.
Vue.component('complementary-course', {
    props: {
        value: { type: Boolean, default: false },
        periodid: { type: Number, required: true },
        periodName: { type: String, default: '' },
    },
    data: function () {
        return { loading: false, error: '', courses: [], courseid: 0, course: null, students: [], listing: false,
            jornada: 'Sabatina', sameJornada: true, ingreso: '', showAll: false, q: '', picks: {}, note: '',
            saving: false, msg: '', msgOk: true,
            statusLabel: { disponible: 'disponible', reprobado: 'reprobado (repite)', revalida: 'reválida pendiente',
                cursando: 'lo está cursando', 'en grupo adicional': 'ya en otro grupo adicional', aprobado: 'ya aprobado' } };
    },
    template: `
    <v-dialog :value="value" @input="v => { if (!v && !saving) $emit('input', false); }" max-width="1000" scrollable>
        <v-card>
            <v-card-title class="text-subtitle-1 font-weight-bold">Curso complementario · {{ periodName }}</v-card-title>
            <v-card-text>
                <div class="tl-cfg-help mb-2">Cursos de los planes complementarios (no salen en la proyección porque no son de nivel).
                    Elija el curso y la cohorte: se marcan los que nunca lo han cursado; los que lo reprobaron se pueden marcar a mano.
                    Quedan fuera los que ya lo aprobaron, lo están cursando o ya están en otro grupo de ese curso. Al enviar se crea un
                    <b>grupo adicional</b> en el Tablero de horarios: ahí se le pone día, hora, aula y docente, y se publica.</div>
                <div v-if="loading" class="tl-cfg-help">Cargando…</div>
                <div v-else-if="error" class="tl-cfg-msg tl-cfg-msg-error">{{ error }}</div>
                <template v-else>
                    <div class="d-flex flex-wrap align-center mb-2" style="gap:8px">
                        <select class="tl-cfg-input" style="min-width:320px" v-model.number="courseid" @change="loadStudents">
                            <option :value="0">— Elija el curso —</option>
                            <option v-for="c in courses" :key="c.courseid" :value="c.courseid">{{ c.name }}</option>
                        </select>
                        <label class="tl-cfg-help">Jornada del grupo
                            <select class="tl-cfg-input" v-model="jornada" @change="autoPick">
                                <option>Diurna</option><option>Nocturna</option><option>Sabatina</option>
                            </select></label>
                        <label class="tl-cfg-help">Ingreso
                            <select class="tl-cfg-input" v-model="ingreso" @change="autoPick">
                                <option value="">Todos</option>
                                <option v-for="i in ingresos" :key="i" :value="i">{{ i || 'Sin ingreso' }}</option>
                            </select></label>
                        <label class="tl-cfg-help"><input type="checkbox" v-model="sameJornada" @change="autoPick"> solo estudiantes de esa jornada</label>
                        <label class="tl-cfg-help"><input type="checkbox" v-model="showAll"> ver también los que no se pueden cargar</label>
                        <input v-model="q" class="tl-cfg-input" style="width:200px" placeholder="Buscar estudiante o cédula…">
                    </div>
                    <div v-if="course" class="tl-cfg-help mb-1">Plan(es): {{ course.plans.join(' / ') }} · {{ counts }}</div>
                    <div v-if="listing" class="tl-cfg-help">Buscando estudiantes…</div>
                    <table v-else-if="course" class="tl-proj-enrtable">
                        <thead><tr><th><input type="checkbox" :checked="allVisiblePicked" :disabled="!visibleSelectable.length || saving" @change="toggleAll($event.target.checked)"></th>
                            <th>Estudiante</th><th>Carrera</th><th>Ingreso</th><th>Jornada</th><th>Situación</th></tr></thead>
                        <tbody>
                            <tr v-for="s in rows" :key="s.userid">
                                <td><input type="checkbox" :checked="!!picks[s.userid]" :disabled="!s.selectable || saving" @change="pick(s, $event.target.checked)"></td>
                                <td><b>{{ s.name }}</b><div class="tl-cfg-help">{{ s.doc }}</div></td>
                                <td class="tl-cfg-help">{{ s.careers.join(' · ') || 'solo plan complementario' }}</td>
                                <td>{{ s.ingreso || '—' }}</td>
                                <td>{{ s.jornada || '—' }}</td>
                                <td><span class="tl-plan-tag" :class="s.status === 'disponible' ? 'tl-plan-tag-nivel' : 'tl-plan-tag-muted'">{{ statusLabel[s.status] || s.status }}</span>
                                    <div v-if="s.where" class="tl-cfg-help">{{ s.where }}</div></td>
                            </tr>
                            <tr v-if="!rows.length"><td colspan="6" class="tl-cfg-help">Nadie con este filtro.</td></tr>
                        </tbody>
                    </table>
                    <div v-if="course" class="mt-3">
                        <textarea v-model="note" class="tl-cfg-input" rows="2" style="width:100%"
                                  placeholder="Motivo (obligatorio), p. ej. Inglés conversacional para el ingreso 2026-I sabatino"></textarea>
                    </div>
                    <div v-if="msg" class="tl-cfg-msg mt-2" :class="msgOk ? 'tl-cfg-msg-ok' : 'tl-cfg-msg-error'">{{ msg }}</div>
                </template>
            </v-card-text>
            <v-card-actions>
                <span class="tl-cfg-help">{{ picked.length }} marcado(s)<template v-if="picked.length > 80"> · máximo 80 por grupo</template></span>
                <v-spacer></v-spacer>
                <v-btn text :disabled="saving" @click="$emit('input', false)">Cerrar</v-btn>
                <v-btn color="primary" depressed :loading="saving" :disabled="!course || !picked.length || picked.length > 80 || !note.trim()" @click="send">Enviar al tablero</v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
    `,
    computed: {
        ingresos: function () {
            var seen = {};
            this.students.forEach(function (s) { seen[s.ingreso] = true; });
            return Object.keys(seen).sort(function (a, b) {
                var ya = /^\d{4}/.test(a), yb = /^\d{4}/.test(b);
                if (ya !== yb) return ya ? -1 : 1;
                return b.localeCompare(a);
            });
        },
        // Cohorte elegida (sin contar el buscador ni "ver todos").
        cohort: function () {
            var self = this;
            return this.students.filter(function (s) {
                return (self.ingreso === '' || s.ingreso === self.ingreso) && (!self.sameJornada || s.jornada === self.jornada);
            });
        },
        rows: function () {
            var self = this, q = this.q.trim().toLowerCase();
            return this.cohort.filter(function (s) {
                return (self.showAll || s.selectable || self.picks[s.userid])
                    && (!q || s.name.toLowerCase().indexOf(q) >= 0 || String(s.doc).toLowerCase().indexOf(q) >= 0);
            });
        },
        visibleSelectable: function () { return this.rows.filter(function (s) { return s.selectable; }); },
        allVisiblePicked: function () {
            var self = this;
            return this.visibleSelectable.length > 0 && this.visibleSelectable.every(function (s) { return self.picks[s.userid]; });
        },
        picked: function () {
            var self = this;
            return this.students.filter(function (s) { return s.selectable && self.picks[s.userid]; }).map(function (s) { return s.userid; });
        },
        counts: function () {
            var c = {};
            this.cohort.forEach(function (s) { c[s.status] = (c[s.status] || 0) + 1; });
            var self = this;
            return this.cohort.length + ' en la cohorte: ' + Object.keys(c).map(function (k) { return c[k] + ' ' + (self.statusLabel[k] || k); }).join(', ');
        },
    },
    watch: {
        value: function (v) { if (v) this.open(); },
    },
    methods: {
        ws: async function (section, data) {
            var body = new URLSearchParams({ wstoken: window.userToken, moodlewsrestformat: 'json',
                wsfunction: 'local_grupomakro_planning_save_config', section: section, data: JSON.stringify(data) });
            var r = await window.axios.post(window.location.origin + '/webservice/rest/server.php', body);
            if (r.data && r.data.exception) throw new Error(r.data.message || r.data.errorcode);
            return r.data;
        },
        open: async function () {
            this.msg = ''; this.note = '';
            if (this.courses.length) return;
            this.loading = true; this.error = '';
            try {
                var r = await this.ws('complement_list', { periodid: this.periodid });
                if (!r.ok) throw new Error(r.message);
                var d = typeof r.saved === 'string' ? JSON.parse(r.saved) : r.saved;
                this.courses = d.courses || [];
            } catch (e) { this.error = 'No se pudieron cargar los cursos: ' + (e.message || e); }
            finally { this.loading = false; }
        },
        loadStudents: async function () {
            this.course = null; this.students = []; this.picks = {}; this.msg = '';
            if (!this.courseid) return;
            this.listing = true;
            try {
                var r = await this.ws('complement_list', { periodid: this.periodid, courseid: this.courseid });
                if (!r.ok) throw new Error(r.message);
                var d = typeof r.saved === 'string' ? JSON.parse(r.saved) : r.saved;
                this.course = d.course; this.students = d.students || [];
                this.autoPick();
            } catch (e) { this.msg = 'No se pudieron cargar los estudiantes: ' + (e.message || e); this.msgOk = false; }
            finally { this.listing = false; }
        },
        // Al cambiar la cohorte se marcan sus "disponible" (los reprobados se marcan a mano). Con "Todos" no se marca nada.
        autoPick: function () {
            var p = {};
            if (this.ingreso !== '') {
                this.cohort.forEach(function (s) { if (s.status === 'disponible') p[s.userid] = true; });
            }
            this.picks = p;
        },
        pick: function (s, on) { if (on) this.$set(this.picks, s.userid, true); else this.$delete(this.picks, s.userid); },
        toggleAll: function (on) {
            var self = this;
            this.visibleSelectable.forEach(function (s) { self.pick(s, on); });
        },
        send: async function () {
            var ids = this.picked;
            if (!window.confirm('¿Enviar al Tablero de horarios un grupo ' + this.jornada + ' de ' + this.course.name + ' con ' + ids.length
                    + ' estudiante(s)? Todavía no se matricula a nadie: eso pasa al publicarlo desde el tablero.')) return;
            this.saving = true; this.msg = '';
            try {
                var r = await this.ws('extra_add', { periodid: this.periodid, note: this.note.trim(),
                    items: [{ courseid: this.courseid, jornada: this.jornada, isolated_plan: 0, userids: ids }] });
                this.msg = r.message; this.msgOk = !!r.ok;
                if (r.ok) {
                    this.$emit('done', r.message);
                    await this.loadStudents();
                    this.msg = r.message; this.msgOk = true;
                }
            } catch (e) { this.msg = 'Error al guardar: ' + (e.message || e); this.msgOk = false; }
            finally { this.saving = false; }
        },
    },
});
