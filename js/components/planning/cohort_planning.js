/**
 * Tablero de planificacion por cohortes (fase 1: demanda, solo lectura).
 *
 * Datos: WS local_grupomakro_planning_get_demand (classes/external/admin/cohort_planning.php).
 */
(function () {
    'use strict';

    var CAT = {
        nivel: { label: 'Nivel', color: 'indigo', help: 'Le toca por su plan en este bimestre.' },
        atrasada: { label: 'Atrasada', color: 'orange darken-2', help: 'Es de un bimestre anterior y nunca la cursó.' },
        repite: { label: 'Repite', color: 'red darken-1', help: 'La reprobó y todavía no la aprueba.' },
        adelanto: { label: 'Adelanto', color: 'teal', help: 'Es de los próximos bimestres y cumple prelaciones. Sirve para completar carga o grupos.' }
    };
    var FLAG = {
        repitente: { label: 'Repitente', color: 'red', help: 'Tiene al menos una asignatura reprobada pendiente.' },
        carga_baja_b1: { label: 'Carga baja B1', color: 'deep-orange', help: 'Le tocan menos asignaturas que el mínimo en el bloque 1 y tiene más pendientes.' },
        carga_baja_b2: { label: 'Carga baja B2', color: 'deep-orange', help: 'Le tocan menos asignaturas que el mínimo en el bloque 2 y tiene más pendientes.' },
        periodo_no_vigente: { label: 'Periodo vencido', color: 'blue-grey', help: 'Su periodo académico registrado está vencido o vacío. Se ubicó en este periodo por la serie de su ingreso (I-III-V o II-IV-VI).' },
        sin_ingreso: { label: 'Sin ingreso', color: 'blue-grey', help: 'No tiene periodo de ingreso válido. Su nivel se estimó como el registrado + 1.' },
        sin_jornada: { label: 'Sin jornada', color: 'blue-grey', help: 'No tiene jornada en su perfil; no se puede asignar a un grupo.' },
        suspendido: { label: 'Suspendido', color: 'grey', help: 'Su cuenta de Moodle está suspendida.' },
        atrasado_vs_cohorte: { label: 'Atrás de su cohorte', color: 'amber darken-2', help: 'Está en un nivel menor que la mayoría de su cohorte.' },
        adelantado_vs_cohorte: { label: 'Adelante de su cohorte', color: 'cyan darken-1', help: 'Está en un nivel mayor que la mayoría de su cohorte.' }
    };
    var ISSUE = {
        estructura_invalida: 'Asignatura mal configurada en el plan',
        sin_periodo_ni_ingreso: 'Sin periodo académico ni periodo de ingreso',
        plan_sin_asignaturas: 'Plan sin asignaturas'
    };
    var STEPS = [
        { n: 1, title: 'Demanda', sub: 'Qué le toca a cada estudiante' },
        { n: 2, title: 'Grupos', sub: 'Agrupación automática 12–30' },
        { n: 3, title: 'Alertas', sub: 'Quién quedó sin cargar' },
        { n: 4, title: 'Horarios', sub: 'Proyección de horarios' },
        { n: 5, title: 'Revisión', sub: 'Simulación de lo que se crea' },
        { n: 6, title: 'Publicar', sub: 'Crear las clases' }
    ];

    function fmtDate(ts) {
        if (!ts) { return '-'; }
        var d = new Date(ts * 1000);
        return d.toLocaleDateString('es-PA', { day: '2-digit', month: 'short', year: 'numeric' });
    }

    Vue.component('cohort-planning', {
        props: { config: { type: Object, required: true } },
        data: function () {
            return {
                loading: false, error: '', periods: [], careers: [], periodId: this.config.periodid || 0,
                careerId: this.config.careerid || 0, result: null, tab: 0, block: 1, jornada: '', search: '',
                studentFilter: '', helpDialog: false, studentDialog: false, current: null,
                minGroup: 12, maxGroup: 30, steps: STEPS, CAT: CAT, FLAG: FLAG, ISSUE: ISSUE,
                expandedDemand: [], expandedCohort: []
            };
        },
        computed: {
            wsUrl: function () { return window.location.origin + '/webservice/rest/server.php'; },
            period: function () {
                var id = this.result ? this.result.period.id : this.periodId;
                return this.periods.filter(function (p) { return p.id === id; })[0] || null;
            },
            students: function () { return this.result ? Object.values(this.result.students) : []; },
            byKey: function () { return this.result ? this.result.students : {}; },
            jornadas: function () {
                var s = {};
                (this.result ? this.result.demand : []).forEach(function (d) { s[d.jornada || 'Sin jornada'] = true; });
                return Object.keys(s).sort();
            },
            kpis: function () {
                var t = this.result ? this.result.totals : {};
                var issues = this.result ? this.result.issues.length : 0;
                return [
                    { key: '', label: 'Estudiantes del periodo', value: t.estudiantes, icon: 'mdi-account-group', color: 'indigo',
                      help: 'Estudiantes activos que cursan este periodo académico (excluye cursos complementarios).' },
                    { key: 'cohortes', label: 'Cohortes', value: t.cohortes, icon: 'mdi-account-multiple-outline', color: 'blue',
                      help: 'Grupos homogéneos: misma carrera, mismo periodo de ingreso y misma jornada. Avanzan juntos.' },
                    { key: 'repitente', label: 'Repitentes', value: t.repitentes, icon: 'mdi-refresh', color: 'red',
                      help: 'Estudiantes con al menos una asignatura reprobada que todavía no aprueban.' },
                    { key: 'carga_baja', label: 'Carga baja', value: t.carga_baja, icon: 'mdi-scale-unbalanced', color: 'deep-orange',
                      help: 'Les tocan menos de 3 asignaturas en algún bloque teniendo más pendientes. El agrupador intentará completarles carga.' },
                    { key: 'bloqueado', label: 'Con prelación pendiente', value: t.con_bloqueos, icon: 'mdi-lock-outline', color: 'amber darken-3',
                      help: 'Tienen asignaturas que les tocan pero cuya prelación no está aprobada.' },
                    { key: 'egresa', label: 'Terminan plan', value: t.por_egresar, icon: 'mdi-school-outline', color: 'green',
                      help: 'Están en el último bimestre de su plan al inicio del periodo.' },
                    { key: 'revision', label: 'Casos a revisar', value: issues, icon: 'mdi-alert-circle-outline', color: 'blue-grey',
                      help: 'Datos que impiden planificar bien: asignaturas mal configuradas, estudiantes sin periodo ni ingreso.' }
                ];
            },
            demandRows: function () {
                var self = this;
                if (!this.result) { return []; }
                var q = this.search.toLowerCase();
                return this.result.demand.filter(function (d) {
                    if (d.block !== self.block) { return false; }
                    if (self.jornada && (d.jornada || 'Sin jornada') !== self.jornada) { return false; }
                    if (q && d.name.toLowerCase().indexOf(q) === -1) { return false; }
                    return (d.ready + d.by_category.adelanto + d.blocked) > 0;
                }).map(function (d, i) {
                    return Object.assign({ id: d.block + '|' + d.courseid + '|' + d.jornada }, d);
                });
            },
            demandSummary: function () {
                var self = this, ok = 0, small = 0, tiny = 0;
                this.demandRows.forEach(function (d) {
                    if (d.ready >= self.minGroup) { ok++; } else if (d.ready + d.by_category.adelanto >= self.minGroup) { small++; } else { tiny++; }
                });
                return { ok: ok, small: small, tiny: tiny };
            },
            cohortRows: function () {
                var q = this.search.toLowerCase();
                return (this.result ? this.result.cohorts : []).filter(function (c) {
                    return !q || (c.plan + ' ' + c.ingreso + ' ' + c.jornada).toLowerCase().indexOf(q) !== -1;
                }).map(function (c) { return Object.assign({ id: c.key }, c); });
            },
            studentRows: function () {
                var q = this.search.toLowerCase(), f = this.studentFilter;
                return this.students.filter(function (s) {
                    if (f === 'repitente' && !s.repite.length) { return false; }
                    if (f === 'carga_baja' && s.flags.indexOf('carga_baja_b1') === -1 && s.flags.indexOf('carga_baja_b2') === -1) { return false; }
                    if (f === 'bloqueado' && !s.blocked.length) { return false; }
                    if (f === 'egresa' && !s.finished) { return false; }
                    if (q && (s.name + ' ' + s.documento + ' ' + s.plan + ' ' + s.email).toLowerCase().indexOf(q) === -1) { return false; }
                    return true;
                });
            },
            repeaters: function () {
                var out = [];
                this.students.forEach(function (s) {
                    s.subjects.forEach(function (sub) {
                        if (sub.category === 'repite') { out.push({ id: s.userid + '-' + s.planid + '-' + sub.courseid, s: s, sub: sub }); }
                    });
                });
                var q = this.search.toLowerCase();
                return out.filter(function (r) {
                    return !q || (r.s.name + ' ' + r.s.documento + ' ' + r.sub.name + ' ' + r.s.plan).toLowerCase().indexOf(q) !== -1;
                });
            },
            reviewRows: function () {
                var self = this, out = [];
                (this.result ? this.result.issues : []).forEach(function (i, n) {
                    out.push({ id: 'i' + n, type: ISSUE[i.type] || i.type, who: i.name || '', detail: i.detail || '', planid: i.planid, userid: i.userid });
                });
                this.students.forEach(function (s) {
                    ['periodo_no_vigente', 'sin_ingreso', 'sin_jornada', 'suspendido'].forEach(function (f) {
                        if (s.flags.indexOf(f) !== -1) {
                            out.push({ id: s.userid + f, type: FLAG[f].label, who: s.name, detail: FLAG[f].help, planid: s.planid, userid: s.userid, student: s });
                        }
                    });
                });
                return out;
            }
        },
        mounted: function () { this.load(); },
        methods: {
            fmtDate: fmtDate,
            load: function () {
                var self = this;
                self.loading = true; self.error = '';
                window.axios.get(this.wsUrl, { params: {
                    wstoken: window.userToken, moodlewsrestformat: 'json', wsfunction: 'local_grupomakro_planning_get_demand',
                    periodid: this.periodId || 0, planids: this.careerId ? String(this.careerId) : ''
                } }).then(function (r) {
                    if (r.data && r.data.exception) { throw new Error(r.data.message || r.data.errorcode); }
                    var d = JSON.parse(r.data.data);
                    self.periods = d.periods; self.careers = d.careers; self.periodId = d.selected_period;
                    if (d.result && d.result.error) { self.result = null; self.error = d.result.error; return; }
                    self.result = d.result;
                }).catch(function (e) {
                    self.error = 'No se pudo cargar la demanda: ' + (e.message || e);
                }).then(function () { self.loading = false; });
            },
            readyColor: function (n) {
                return n >= this.minGroup ? 'green' : (n >= this.minGroup / 2 ? 'amber darken-2' : 'red');
            },
            readyHelp: function (d) {
                if (d.ready >= this.minGroup) {
                    var groups = Math.ceil(d.ready / this.maxGroup);
                    return 'Alcanza para ' + groups + ' grupo' + (groups > 1 ? 's' : '') + ' (mínimo ' + this.minGroup + ', máximo ' + this.maxGroup + ').';
                }
                if (d.ready + d.by_category.adelanto >= this.minGroup) {
                    return 'No alcanza el mínimo solo, pero sumando estudiantes que pueden adelantarla sí llega a ' + this.minGroup + '.';
                }
                return 'No alcanza el mínimo de ' + this.minGroup + '. Habrá que unir jornadas o carreras, abrir por excepción o diferir.';
            },
            setFilter: function (key) {
                if (key === 'cohortes') { this.tab = 1; return; }
                if (key === 'revision') { this.tab = 4; return; }
                if (key === 'repitente') { this.tab = 3; return; }
                this.studentFilter = key; this.tab = 2;
            },
            openStudent: function (s) { this.current = s; this.studentDialog = true; },
            studentsOf: function (d) {
                var self = this;
                return d.students.map(function (x) { return Object.assign({ cat: x.category, prereq: x.prereq }, self.byKey[x.key]); });
            },
            membersOf: function (c) {
                var self = this;
                return this.students.filter(function (s) { return s.cohort === c.key; });
            },
            shortPlan: function (name) {
                var n = String(name || '').replace(/^T[ÉE]CNICO SUPERIOR EN\s+/i, '').replace(/^CURSO DE\s+/i, '');
                n = n.charAt(0) + n.slice(1).toLowerCase();
                return n.length > 34 ? n.slice(0, 32) + '…' : n;
            },
            plansOf: function (d) {
                var self = this;
                return Object.keys(d.plans).map(function (pid) {
                    var c = self.careers.filter(function (x) { return String(x.id) === String(pid); })[0];
                    return self.shortPlan(c ? c.name : 'Plan ' + pid) + ' (' + d.plans[pid] + ')';
                });
            },
            subjectsBy: function (s, block) {
                return s.subjects.filter(function (x) { return x.block === block; });
            },
            waLink: function (phone) {
                var p = String(phone || '').replace(/\D/g, '');
                if (!p) { return ''; }
                if (p.length === 8) { p = '507' + p; }
                return 'https://wa.me/' + p;
            },
            profile: function (s) { return this.config.profileUrl + '?id=' + s.userid; }
        },
        template: `
<div class="pa-4" style="font-family: Inter, Roboto, sans-serif; max-width: 1500px; margin: 0 auto;">
  <!-- Encabezado -->
  <div class="d-flex flex-wrap align-center mb-3" style="gap: 12px;">
    <div>
      <div class="text-h5 font-weight-bold">Planificación de grupos y horarios</div>
      <div class="text-body-2 grey--text text--darken-1">Calcula, a partir del plan de cada estudiante, qué asignaturas le tocan en el periodo y cuántos estudiantes hay por asignatura.</div>
    </div>
    <v-spacer></v-spacer>
    <v-btn text small :href="config.timelineUrl"><v-icon left small>mdi-timeline-outline</v-icon>Línea de tiempo</v-btn>
    <v-btn color="primary" outlined small @click="helpDialog = true"><v-icon left small>mdi-help-circle-outline</v-icon>¿Cómo funciona?</v-btn>
  </div>

  <!-- Pasos -->
  <v-card outlined class="mb-4">
    <div class="d-flex flex-wrap">
      <div v-for="s in steps" :key="s.n" class="pa-3 d-flex align-center" style="flex: 1 1 160px; min-width: 150px;"
           :style="s.n === 1 ? 'background:#EEF2FF;border-bottom:3px solid #4F46E5' : 'opacity:.55'">
        <v-avatar size="28" :color="s.n === 1 ? 'primary' : 'grey lighten-1'" class="mr-2"><span class="white--text text-caption font-weight-bold">{{ s.n }}</span></v-avatar>
        <div>
          <div class="text-body-2 font-weight-bold">{{ s.title }}</div>
          <div class="text-caption grey--text text--darken-1">{{ s.n === 1 ? s.sub : 'Próxima fase' }}</div>
        </div>
      </div>
    </div>
  </v-card>

  <!-- Filtros -->
  <div class="d-flex flex-wrap align-center mb-2" style="gap: 12px;">
    <v-select dense outlined hide-details style="max-width: 240px" label="Periodo académico" v-model="periodId"
      :items="periods" item-value="id" :item-text="p => p.name + (p.has_calendar ? '' : ' (sin calendario)')"
      :item-disabled="p => !p.has_calendar" @change="load"></v-select>
    <v-select dense outlined hide-details style="max-width: 380px" label="Carrera" v-model="careerId"
      :items="[{ id: 0, name: 'Todas las carreras' }].concat(careers)" item-value="id" item-text="name" @change="load"></v-select>
    <div v-if="period" class="text-caption grey--text text--darken-2">
      <v-icon x-small>mdi-calendar</v-icon> Bloque 1 desde {{ fmtDate(period.block1start) }} · Bloque 2 desde {{ fmtDate(period.block2start) }}
    </div>
    <v-spacer></v-spacer>
    <v-btn small text @click="load" :loading="loading"><v-icon left small>mdi-refresh</v-icon>Recalcular</v-btn>
  </div>

  <v-alert dense text type="info" class="mb-3" icon="mdi-shield-check-outline">
    Esta pantalla <b>solo lee</b> datos: no crea, cambia ni borra nada. Los números se recalculan cada vez que la abres.
  </v-alert>
  <v-alert v-if="error" type="warning" outlined class="mb-3">{{ error }}</v-alert>
  <v-progress-linear v-if="loading" indeterminate color="primary" class="mb-3"></v-progress-linear>

  <template v-if="result">
    <!-- Tarjetas -->
    <div class="d-flex flex-wrap mb-4" style="gap: 10px;">
      <v-card v-for="k in kpis" :key="k.label" outlined class="pa-3" style="flex: 1 1 170px; cursor: pointer;" @click="setFilter(k.key)">
        <div class="d-flex align-center">
          <v-icon :color="k.color" class="mr-2">{{ k.icon }}</v-icon>
          <span class="text-caption text-uppercase grey--text text--darken-2" style="letter-spacing:.04em">{{ k.label }}</span>
          <v-spacer></v-spacer>
          <v-tooltip bottom max-width="300"><template v-slot:activator="{ on }"><v-icon small v-on="on" color="grey" @click.stop>mdi-information-outline</v-icon></template><span>{{ k.help }}</span></v-tooltip>
        </div>
        <div class="text-h4 font-weight-bold mt-1" :class="k.color.split(' ')[0] + '--text'">{{ k.value || 0 }}</div>
      </v-card>
    </div>

    <v-card outlined>
      <v-tabs v-model="tab" show-arrows>
        <v-tab><v-icon left small>mdi-book-open-variant</v-icon>Demanda por asignatura</v-tab>
        <v-tab><v-icon left small>mdi-account-multiple-outline</v-icon>Cohortes</v-tab>
        <v-tab><v-icon left small>mdi-account-search-outline</v-icon>Estudiantes</v-tab>
        <v-tab><v-icon left small>mdi-refresh</v-icon>Repitentes ({{ result.totals.repitentes }})</v-tab>
        <v-tab><v-icon left small>mdi-alert-circle-outline</v-icon>Casos a revisar ({{ reviewRows.length }})</v-tab>
      </v-tabs>
      <v-divider></v-divider>
      <div class="pa-3">
        <v-text-field v-model="search" dense outlined hide-details clearable prepend-inner-icon="mdi-magnify"
          placeholder="Buscar por asignatura, estudiante, cédula o carrera" class="mb-3" style="max-width: 460px"></v-text-field>

        <v-tabs-items v-model="tab">
          <!-- DEMANDA -->
          <v-tab-item>
            <div class="d-flex flex-wrap align-center mb-3" style="gap: 12px;">
              <v-btn-toggle v-model="block" mandatory dense color="primary">
                <v-btn :value="1" small>Bloque 1</v-btn><v-btn :value="2" small>Bloque 2</v-btn>
              </v-btn-toggle>
              <v-chip-group v-model="jornada" active-class="primary--text">
                <v-chip small :value="''" filter outlined>Todas las jornadas</v-chip>
                <v-chip v-for="j in jornadas" :key="j" small :value="j" filter outlined>{{ j }}</v-chip>
              </v-chip-group>
              <v-spacer></v-spacer>
              <span class="text-caption"><v-icon x-small color="green">mdi-circle</v-icon> {{ demandSummary.ok }} alcanzan {{ minGroup }}
                · <v-icon x-small color="amber darken-2">mdi-circle</v-icon> {{ demandSummary.small }} alcanzan con adelantos
                · <v-icon x-small color="red">mdi-circle</v-icon> {{ demandSummary.tiny }} no alcanzan</span>
            </div>
            <v-alert dense text color="blue-grey" class="text-caption mb-3">
              <b>Cómo leer la tabla:</b> "Listos" son los estudiantes a quienes les toca la asignatura (nivel, atrasada o repite) y tienen la prelación cumplida.
              "Pueden adelantar" son estudiantes de niveles anteriores que podrían completar el grupo. Abre una fila para ver los estudiantes.
            </v-alert>
            <v-data-table :items="demandRows" item-key="id" show-expand single-expand :expanded.sync="expandedDemand" dense
              :headers="[
                { text: 'Asignatura', value: 'name' }, { text: 'Jornada', value: 'jornada' },
                { text: 'Listos', value: 'ready', align: 'center' }, { text: 'Nivel', value: 'by_category.nivel', align: 'center' },
                { text: 'Atrasada', value: 'by_category.atrasada', align: 'center' }, { text: 'Repite', value: 'by_category.repite', align: 'center' },
                { text: 'Pueden adelantar', value: 'by_category.adelanto', align: 'center' },
                { text: 'Prelación pendiente', value: 'blocked', align: 'center' }, { text: 'Carreras', value: 'plans', sortable: false },
                { text: '', value: 'data-table-expand' }]"
              :items-per-page="25" :footer-props="{ 'items-per-page-options': [25, 50, 100, -1] }" sort-by="ready" sort-desc>
              <template v-slot:item.jornada="{ item }">{{ item.jornada || 'Sin jornada' }}</template>
              <template v-slot:item.ready="{ item }">
                <v-tooltip bottom max-width="280"><template v-slot:activator="{ on }">
                  <v-chip small :color="readyColor(item.ready)" dark v-on="on" class="font-weight-bold">{{ item.ready }}</v-chip>
                </template><span>{{ readyHelp(item) }}</span></v-tooltip>
              </template>
              <template v-slot:item.blocked="{ item }"><span :class="item.blocked ? 'amber--text text--darken-3 font-weight-bold' : 'grey--text'">{{ item.blocked }}</span></template>
              <template v-slot:item.plans="{ item }"><span class="text-caption">{{ plansOf(item).join(' · ') }}</span></template>
              <template v-slot:expanded-item="{ headers, item }">
                <td :colspan="headers.length" class="pa-3" style="background:#FAFAFB">
                  <v-simple-table dense>
                    <thead><tr><th>Estudiante</th><th>Cédula</th><th>Carrera</th><th>Nivel</th><th>Categoría</th><th>Prelación</th><th>Contacto</th></tr></thead>
                    <tbody>
                      <tr v-for="s in studentsOf(item)" :key="s.userid + '-' + s.planid">
                        <td><a @click="openStudent(s)">{{ s.name }}</a></td><td>{{ s.documento }}</td><td class="text-caption">{{ shortPlan(s.plan) }}</td>
                        <td>{{ s.level1 }}</td>
                        <td><v-chip x-small :color="CAT[s.cat].color" dark>{{ CAT[s.cat].label }}</v-chip></td>
                        <td><span v-if="s.prereq === 'ok'" class="green--text">Cumplida</span>
                            <span v-else-if="s.prereq === 'condicionada'" class="amber--text text--darken-3">Condicionada</span>
                            <span v-else class="red--text">Pendiente</span></td>
                        <td class="text-caption"><a :href="'mailto:' + s.email">{{ s.email }}</a><span v-if="s.phone"> · <a :href="waLink(s.phone)" target="_blank">{{ s.phone }}</a></span></td>
                      </tr>
                    </tbody>
                  </v-simple-table>
                </td>
              </template>
            </v-data-table>
          </v-tab-item>

          <!-- COHORTES -->
          <v-tab-item>
            <v-alert dense text color="blue-grey" class="text-caption mb-3">
              Una <b>cohorte</b> son los estudiantes de la misma carrera que ingresaron en el mismo periodo y estudian en la misma jornada.
              Avanzan juntos y ven las mismas asignaturas. "Desfasados" son los que no están en el nivel de la mayoría (repitentes, reingresos, adelantados).
            </v-alert>
            <v-data-table :items="cohortRows" item-key="id" show-expand single-expand :expanded.sync="expandedCohort" dense
              :headers="[{ text: 'Carrera', value: 'plan' }, { text: 'Ingreso', value: 'ingreso' }, { text: 'Jornada', value: 'jornada' },
                { text: 'Estudiantes', value: 'size', align: 'center' }, { text: 'Nivel en bloque 1', value: 'level1', align: 'center' },
                { text: 'Desfasados', value: 'desfasados', align: 'center' }, { text: '', value: 'data-table-expand' }]"
              :items-per-page="25" sort-by="size" sort-desc>
              <template v-slot:item.ingreso="{ item }">{{ item.ingreso || 'Sin ingreso' }}</template>
              <template v-slot:item.desfasados="{ item }"><span :class="item.desfasados ? 'amber--text text--darken-3 font-weight-bold' : 'grey--text'">{{ item.desfasados }}</span></template>
              <template v-slot:expanded-item="{ headers, item }">
                <td :colspan="headers.length" class="pa-3" style="background:#FAFAFB">
                  <v-chip v-for="s in membersOf(item)" :key="s.userid" small class="ma-1" outlined @click="openStudent(s)"
                    :color="s.flags.indexOf('atrasado_vs_cohorte') !== -1 || s.flags.indexOf('adelantado_vs_cohorte') !== -1 ? 'amber darken-3' : ''">
                    {{ s.name }} · {{ s.level1 }}</v-chip>
                </td>
              </template>
            </v-data-table>
          </v-tab-item>

          <!-- ESTUDIANTES -->
          <v-tab-item>
            <div class="d-flex align-center mb-2" style="gap: 8px">
              <v-chip-group v-model="studentFilter" active-class="primary--text">
                <v-chip small :value="''" filter outlined>Todos</v-chip>
                <v-chip small value="repitente" filter outlined>Repitentes</v-chip>
                <v-chip small value="carga_baja" filter outlined>Carga baja</v-chip>
                <v-chip small value="bloqueado" filter outlined>Prelación pendiente</v-chip>
                <v-chip small value="egresa" filter outlined>Terminan plan</v-chip>
              </v-chip-group>
            </div>
            <v-data-table :items="studentRows" item-key="lluid" dense :items-per-page="25"
              :headers="[{ text: 'Estudiante', value: 'name' }, { text: 'Cédula', value: 'documento' }, { text: 'Carrera', value: 'plan' },
                { text: 'Ingreso', value: 'ingreso' }, { text: 'Jornada', value: 'jornada' }, { text: 'Nivel B1 → B2', value: 'ord1' },
                { text: 'Carga B1', value: 'load.1', align: 'center' }, { text: 'Carga B2', value: 'load.2', align: 'center' },
                { text: 'Avisos', value: 'flags', sortable: false }, { text: 'Contacto', value: 'email', sortable: false }]">
              <template v-slot:item.name="{ item }"><a @click="openStudent(item)">{{ item.name }}</a></template>
              <template v-slot:item.plan="{ item }"><span class="text-caption">{{ shortPlan(item.plan) }}</span></template>
              <template v-slot:item.ord1="{ item }">{{ item.level1 }} → {{ item.finished ? 'fin' : item.level2 }}</template>
              <template v-slot:item.load.1="{ item }"><span :class="item.load[1] < 3 && !item.finished ? 'deep-orange--text font-weight-bold' : ''">{{ item.load[1] }}</span><span class="grey--text text-caption"> /{{ item.normal_load[1] }}</span></template>
              <template v-slot:item.load.2="{ item }"><span :class="item.load[2] < 3 && !item.finished ? 'deep-orange--text font-weight-bold' : ''">{{ item.load[2] }}</span><span class="grey--text text-caption"> /{{ item.normal_load[2] }}</span></template>
              <template v-slot:item.flags="{ item }">
                <v-chip v-if="item.repite.length" x-small color="red" dark class="mr-1">Repite {{ item.repite.length }}</v-chip>
                <v-tooltip v-for="f in item.flags" :key="f" bottom max-width="280" v-if="FLAG[f]"><template v-slot:activator="{ on }">
                  <v-chip x-small outlined :color="FLAG[f].color" class="mr-1" v-on="on">{{ FLAG[f].label }}</v-chip>
                </template><span>{{ FLAG[f].help }}</span></v-tooltip>
              </template>
              <template v-slot:item.email="{ item }"><span class="text-caption"><a :href="'mailto:' + item.email">{{ item.email }}</a><br><a v-if="item.phone" :href="waLink(item.phone)" target="_blank">{{ item.phone }}</a></span></template>
            </v-data-table>
          </v-tab-item>

          <!-- REPITENTES -->
          <v-tab-item>
            <v-alert dense text color="red" class="text-caption mb-3">
              Estudiantes que reprobaron una asignatura y todavía no la aprueban. En la siguiente fase el sistema los ubicará automáticamente
              en un grupo de otra cohorte que vea esa asignatura (o en un grupo de repitentes) y generará una alerta si no hay dónde.
              Aquí puedes contactarlos desde ya. <b>{{ result.totals.repitentes }} estudiantes · {{ repeaters.length }} asignaturas reprobadas.</b>
            </v-alert>
            <v-data-table :items="repeaters" item-key="id" dense :items-per-page="25"
              :headers="[{ text: 'Estudiante', value: 's.name' }, { text: 'Cédula', value: 's.documento' }, { text: 'Carrera', value: 's.plan' },
                { text: 'Jornada', value: 's.jornada' }, { text: 'Asignatura reprobada', value: 'sub.name' }, { text: 'Nivel de la asignatura', value: 'sub.level' },
                { text: 'Bloque sugerido', value: 'sub.block', align: 'center' }, { text: 'Prelación', value: 'sub.prereq' },
                { text: 'Correo', value: 's.email', sortable: false }, { text: 'Teléfono', value: 's.phone', sortable: false }]">
              <template v-slot:item.s.name="{ item }"><a @click="openStudent(item.s)">{{ item.s.name }}</a></template>
              <template v-slot:item.s.plan="{ item }"><span class="text-caption">{{ shortPlan(item.s.plan) }}</span></template>
              <template v-slot:item.sub.prereq="{ item }">
                <span v-if="item.sub.prereq === 'ok'" class="green--text">Cumplida</span>
                <v-tooltip v-else bottom max-width="300"><template v-slot:activator="{ on }"><span v-on="on" :class="item.sub.prereq === 'no' ? 'red--text' : 'amber--text text--darken-3'">{{ item.sub.prereq === 'no' ? 'Pendiente' : 'Condicionada' }}</span></template>
                  <span>{{ (item.sub.missing.concat(item.sub.conditional)).join(', ') }}</span></v-tooltip>
              </template>
              <template v-slot:item.s.email="{ item }"><a class="text-caption" :href="'mailto:' + item.s.email">{{ item.s.email }}</a></template>
              <template v-slot:item.s.phone="{ item }"><a v-if="item.s.phone" class="text-caption" :href="waLink(item.s.phone)" target="_blank"><v-icon x-small color="green">mdi-whatsapp</v-icon> {{ item.s.phone }}</a></template>
            </v-data-table>
          </v-tab-item>

          <!-- REVISION -->
          <v-tab-item>
            <v-alert dense text color="blue-grey" class="text-caption mb-3">
              Situaciones de datos que conviene corregir antes de agrupar. Ninguna se corrige sola: esta pantalla solo las muestra.
            </v-alert>
            <v-data-table :items="reviewRows" item-key="id" dense :items-per-page="25" group-by="type"
              :headers="[{ text: 'Tipo', value: 'type' }, { text: 'Estudiante / asignatura', value: 'who' }, { text: 'Detalle', value: 'detail' }]">
              <template v-slot:item.who="{ item }"><a v-if="item.student" @click="openStudent(item.student)">{{ item.who }}</a><span v-else>{{ item.who }}</span></template>
              <template v-slot:item.detail="{ item }"><span class="text-caption">{{ item.detail }}</span></template>
            </v-data-table>
          </v-tab-item>
        </v-tabs-items>
      </div>
    </v-card>
  </template>

  <!-- Detalle de estudiante -->
  <v-dialog v-model="studentDialog" max-width="820">
    <v-card v-if="current">
      <v-card-title class="d-flex">
        <div>
          <div class="text-h6">{{ current.name }}</div>
          <div class="text-caption grey--text text--darken-1">{{ current.documento }} · {{ current.plan }} · {{ current.jornada || 'Sin jornada' }} · Ingreso {{ current.ingreso || '-' }}</div>
        </div>
        <v-spacer></v-spacer>
        <v-btn icon @click="studentDialog = false"><v-icon>mdi-close</v-icon></v-btn>
      </v-card-title>
      <v-card-text>
        <div class="mb-3">
          <v-btn x-small outlined class="mr-2" :href="'mailto:' + current.email"><v-icon x-small left>mdi-email-outline</v-icon>{{ current.email }}</v-btn>
          <v-btn v-if="current.phone" x-small outlined color="green" class="mr-2" :href="waLink(current.phone)" target="_blank"><v-icon x-small left>mdi-whatsapp</v-icon>{{ current.phone }}</v-btn>
          <v-btn x-small text :href="profile(current)" target="_blank">Ver perfil</v-btn>
        </div>
        <div class="mb-3 text-body-2">
          Nivel registrado hoy: <b>{{ current.registered }}</b> · En el bloque 1: <b>{{ current.level1 }}</b> · En el bloque 2: <b>{{ current.finished ? 'termina plan' : current.level2 }}</b>
          · Asignaturas pendientes del plan: <b>{{ current.pending_total }}</b>
        </div>
        <div class="mb-2"><v-chip v-for="f in current.flags" :key="f" x-small outlined :color="FLAG[f] ? FLAG[f].color : ''" class="mr-1">{{ FLAG[f] ? FLAG[f].label : f }}</v-chip></div>
        <div v-for="b in [1, 2]" :key="b" class="mb-3">
          <div class="text-subtitle-2 mb-1">Bloque {{ b }} <span class="grey--text text-caption">· carga {{ current.load[b] }} (normal del plan: {{ current.normal_load[b] }})</span></div>
          <v-simple-table dense>
            <tbody>
              <tr v-for="x in subjectsBy(current, b)" :key="x.courseid">
                <td>{{ x.name }}</td><td class="text-caption">{{ x.level }}</td>
                <td><v-chip x-small :color="CAT[x.category].color" dark>{{ CAT[x.category].label }}</v-chip></td>
                <td class="text-caption">
                  <span v-if="x.prereq === 'ok'" class="green--text">Prelación cumplida</span>
                  <span v-else-if="x.prereq === 'condicionada'" class="amber--text text--darken-3">Condicionada a: {{ x.conditional.join(', ') }}</span>
                  <span v-else class="red--text">Falta aprobar: {{ x.missing.join(', ') }}</span>
                </td>
              </tr>
              <tr v-if="!subjectsBy(current, b).length"><td class="grey--text text-caption">Nada para este bloque.</td></tr>
            </tbody>
          </v-simple-table>
        </div>
        <div v-if="current.other.length" class="text-caption grey--text text--darken-1">
          No se planifican porque están en curso, en reválida o en migración: {{ current.other.map(o => o.name).join(', ') }}.
        </div>
      </v-card-text>
    </v-card>
  </v-dialog>

  <!-- Ayuda -->
  <v-dialog v-model="helpDialog" max-width="760">
    <v-card>
      <v-card-title>¿Cómo funciona la planificación?</v-card-title>
      <v-card-text class="text-body-2">
        <p><b>1. Demanda (esta fase).</b> Para cada estudiante activo el sistema calcula su nivel al inicio del periodo con la misma regla del avance automático
        de cuatrimestre y bimestre, y mira su plan: qué asignaturas le tocan en cada bloque, cuáles tiene aprobadas, en curso o reprobadas y si cumple las prelaciones.</p>
        <p><b>2. Grupos.</b> Con esa demanda armará grupos de {{ minGroup }} a {{ maxGroup }} estudiantes, mezclando niveles y carreras cuando sea la misma asignatura
        y respetando la jornada. Intentará que nadie quede con 1 o 2 asignaturas si tiene más pendientes.</p>
        <p><b>3. Alertas.</b> Quien deba ver una asignatura y no quede en un grupo aparecerá como alerta, con el motivo y la acción sugerida.</p>
        <p><b>4–6. Horarios, revisión y publicación.</b> Se proyectan los horarios con aulas, festivos y cargas horarias; antes de publicar verás exactamente qué se crea.
        La publicación solo agrega clases nuevas, nunca borra ni cambia clases existentes.</p>
        <v-divider class="my-3"></v-divider>
        <div class="text-subtitle-2 mb-1">Glosario</div>
        <ul>
          <li><b>Bloque</b>: cada mitad del periodo académico (un bimestre de unas 8 semanas).</li>
          <li><b>Nivel</b>: cuatrimestre y bimestre del plan, por ejemplo C2.B1 = cuatrimestre 2, bimestre 1.</li>
          <li><b>Cohorte</b>: misma carrera, mismo periodo de ingreso y misma jornada.</li>
          <li><b>Prelación</b>: asignatura que se debe aprobar antes. "Condicionada" = se está cursando o se cursa en el bloque 1; queda sujeta a que la apruebe.</li>
          <li v-for="(c, k) in CAT" :key="k"><b>{{ c.label }}</b>: {{ c.help }}</li>
        </ul>
      </v-card-text>
      <v-card-actions><v-spacer></v-spacer><v-btn text color="primary" @click="helpDialog = false">Entendido</v-btn></v-card-actions>
    </v-card>
  </v-dialog>
</div>`
    });
})();
