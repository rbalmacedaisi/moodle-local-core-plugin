<?php
require_once(__DIR__ . '/../../../config.php');
require_login();
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

if (!is_siteadmin()) {
    throw new moodle_exception('nopermissions', 'error', '', 'Bulk enroll');
}

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/bulk_enroll.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Matrícula masiva a plan de aprendizaje');
$PAGE->set_heading('Matrícula masiva a plan de aprendizaje');
$PAGE->set_pagelayout('base');

// get_logged_user_token() already returns json_encode()'d value — do NOT re-encode.
$token  = get_logged_user_token();
$wsUrl  = $CFG->wwwroot . '/webservice/rest/server.php';

// Load all plans with their periods and subperiods for the dropdowns.
$plans = $DB->get_records('local_learning_plans', null, 'name ASC', 'id, name, shortname, hasperiod');
$planData = [];
foreach ($plans as $plan) {
    $periods = $DB->get_records('local_learning_periods', ['learningplanid' => $plan->id], 'id ASC', 'id, name, hassubperiods');
    $planPeriods = [];
    foreach ($periods as $period) {
        $subperiods = [];
        if ($period->hassubperiods) {
            $subs = $DB->get_records('local_learning_subperiods', ['periodid' => $period->id], 'position ASC', 'id, name, position');
            $subperiods = array_values(array_map(fn($s) => ['id' => (int)$s->id, 'name' => $s->name], $subs));
        }
        $planPeriods[] = ['id' => (int)$period->id, 'name' => $period->name, 'subperiods' => $subperiods];
    }
    $planData[] = [
        'id'       => (int)$plan->id,
        'name'     => $plan->name,
        'shortname'=> $plan->shortname,
        'periods'  => $planPeriods,
    ];
}

$plansJson = json_encode($planData);
$wsUrlJson = json_encode($wsUrl);

echo $OUTPUT->header();
?>
<link href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">

<div id="bulk-enroll-app">
  <v-app class="transparent">
    <v-main>
      <v-container fluid class="px-4">

        <v-row>
          <!-- ═══════════════════ ORIGEN ═══════════════════ -->
          <v-col cols="12" md="5">
            <v-card outlined>
              <v-card-title class="subtitle-1 font-weight-bold">
                <v-icon left color="primary">mdi-filter-variant</v-icon>
                Origen — estudiantes a mover
              </v-card-title>
              <v-card-text>
                <v-autocomplete
                  v-model="src.planId"
                  :items="plans"
                  item-value="id"
                  item-text="name"
                  label="Plan de aprendizaje"
                  outlined dense clearable
                  @change="onSrcPlanChange"
                ></v-autocomplete>

                <v-select
                  v-model="src.periodId"
                  :items="srcPeriods"
                  item-value="id"
                  item-text="name"
                  label="Cuatrimestre (opcional)"
                  outlined dense clearable
                  :disabled="!src.planId"
                  @change="onSrcPeriodChange"
                ></v-select>

                <v-select
                  v-model="src.subperiodId"
                  :items="srcSubperiods"
                  item-value="id"
                  item-text="name"
                  label="Bimestre (opcional)"
                  outlined dense clearable
                  :disabled="!src.periodId || srcSubperiods.length === 0"
                ></v-select>

                <v-btn
                  color="primary" block
                  :loading="loadingStudents"
                  @click="fetchStudents"
                >
                  <v-icon left>mdi-magnify</v-icon>
                  Buscar estudiantes
                </v-btn>
              </v-card-text>
            </v-card>
          </v-col>

          <!-- ═══════════════════ DESTINO ═══════════════════ -->
          <v-col cols="12" md="7">
            <v-card outlined>
              <v-card-title class="subtitle-1 font-weight-bold">
                <v-icon left color="success">mdi-arrow-right-circle</v-icon>
                Destino — plan al que se matricularán
              </v-card-title>
              <v-card-text>
                <v-row>
                  <v-col cols="12" sm="6">
                    <v-autocomplete
                      v-model="dst.planId"
                      :items="plans"
                      item-value="id"
                      item-text="name"
                      label="Plan destino *"
                      outlined dense clearable
                      @change="onDstPlanChange"
                    ></v-autocomplete>
                  </v-col>
                  <v-col cols="12" sm="6">
                    <v-select
                      v-model="dst.periodId"
                      :items="dstPeriods"
                      item-value="id"
                      item-text="name"
                      label="Cuatrimestre destino (opcional)"
                      outlined dense clearable
                      :disabled="!dst.planId"
                    ></v-select>
                  </v-col>
                  <v-col cols="12" sm="6">
                    <v-text-field
                      v-model="dst.groupname"
                      label="Nombre de grupo (opcional)"
                      outlined dense
                    ></v-text-field>
                  </v-col>
                  <v-col cols="12" sm="6" class="d-flex align-center">
                    <v-btn
                      color="success" block large
                      :disabled="!dst.planId || selectedIds.length === 0"
                      :loading="enrolling"
                      @click="bulkEnroll"
                    >
                      <v-icon left>mdi-account-multiple-plus</v-icon>
                      Matricular {{ selectedIds.length > 0 ? selectedIds.length : '' }} seleccionados
                    </v-btn>
                  </v-col>
                </v-row>
              </v-card-text>
            </v-card>
          </v-col>
        </v-row>

        <!-- ═══════════════════ LISTA DE ESTUDIANTES ═══════════════════ -->
        <v-row v-if="students.length > 0 || loadingStudents" class="mt-2">
          <v-col cols="12">
            <v-card outlined>
              <v-card-title class="subtitle-1 font-weight-bold">
                <v-icon left color="blue-grey">mdi-account-group</v-icon>
                Estudiantes encontrados ({{ students.length }})
                <v-spacer></v-spacer>
                <v-text-field
                  v-model="searchText"
                  prepend-inner-icon="mdi-magnify"
                  placeholder="Buscar por nombre o email…"
                  dense outlined hide-details clearable
                  style="max-width:300px"
                ></v-text-field>
                <v-btn text small class="ml-3" @click="selectAll">
                  <v-icon left small>mdi-checkbox-multiple-marked</v-icon>Seleccionar todos
                </v-btn>
                <v-btn text small @click="deselectAll">
                  <v-icon left small>mdi-checkbox-multiple-blank-outline</v-icon>Deseleccionar
                </v-btn>
              </v-card-title>

              <v-progress-linear v-if="loadingStudents" indeterminate color="primary"></v-progress-linear>

              <v-card-text class="pa-0">
                <!-- Group headers -->
                <div v-for="group in filteredGroups" :key="group.key">
                  <v-list-item
                    class="blue-grey lighten-5"
                    style="border-top:1px solid #cfd8dc; min-height:40px"
                  >
                    <v-list-item-action class="my-0 mr-2">
                      <v-checkbox
                        :input-value="isGroupAllSelected(group)"
                        :indeterminate="isGroupPartialSelected(group)"
                        dense hide-details
                        @change="toggleGroup(group, $event)"
                      ></v-checkbox>
                    </v-list-item-action>
                    <v-list-item-content>
                      <v-list-item-title class="caption font-weight-bold text-uppercase blue-grey--text text--darken-2">
                        {{ group.label }}
                        <v-chip x-small class="ml-2">{{ group.students.length }}</v-chip>
                      </v-list-item-title>
                    </v-list-item-content>
                  </v-list-item>

                  <v-list dense class="py-0">
                    <v-list-item
                      v-for="st in group.students"
                      :key="st.userid"
                      class="pl-8"
                      style="min-height:36px; border-bottom:1px solid #eceff1"
                    >
                      <v-list-item-action class="my-0 mr-2">
                        <v-checkbox
                          v-model="selected"
                          :value="st.userid"
                          dense hide-details
                        ></v-checkbox>
                      </v-list-item-action>
                      <v-list-item-content>
                        <v-list-item-title class="body-2">
                          {{ st.fullname }}
                          <span class="grey--text ml-1">({{ st.email }})</span>
                        </v-list-item-title>
                      </v-list-item-content>
                      <v-list-item-action v-if="st.groupname">
                        <v-chip x-small outlined>{{ st.groupname }}</v-chip>
                      </v-list-item-action>
                    </v-list-item>
                  </v-list>
                </div>

                <div v-if="!loadingStudents && filteredGroups.length === 0" class="pa-4 text-center grey--text">
                  No se encontraron estudiantes con los filtros seleccionados.
                </div>
              </v-card-text>
            </v-card>
          </v-col>
        </v-row>

        <!-- ═══════════════════ RESULTADOS ═══════════════════ -->
        <v-row v-if="results.length > 0" class="mt-2">
          <v-col cols="12">
            <v-card outlined>
              <v-card-title class="subtitle-1 font-weight-bold">
                <v-icon left>mdi-clipboard-check-outline</v-icon>
                Resultados de la matrícula
                <v-spacer></v-spacer>
                <v-chip small color="success" class="mr-1">{{ enrolledCount }} matriculados</v-chip>
                <v-chip small color="warning" class="mr-1">{{ skippedCount }} omitidos</v-chip>
                <v-chip small color="error" v-if="errorCount > 0">{{ errorCount }} errores</v-chip>
              </v-card-title>
              <v-card-text class="pa-0">
                <v-list dense>
                  <v-list-item
                    v-for="r in results"
                    :key="r.userid"
                    style="min-height:36px; border-bottom:1px solid #eceff1"
                  >
                    <v-list-item-icon class="my-auto mr-2">
                      <v-icon small :color="r.status==='ok' ? 'success' : r.status==='skipped' ? 'warning' : 'error'">
                        {{ r.status==='ok' ? 'mdi-check-circle' : r.status==='skipped' ? 'mdi-alert-circle' : 'mdi-close-circle' }}
                      </v-icon>
                    </v-list-item-icon>
                    <v-list-item-content>
                      <v-list-item-title class="body-2">
                        <strong>{{ r.fullname }}</strong>
                        <span class="ml-2 grey--text caption">{{ r.message }}</span>
                      </v-list-item-title>
                    </v-list-item-content>
                  </v-list-item>
                </v-list>
              </v-card-text>
            </v-card>
          </v-col>
        </v-row>

        <!-- Snackbar de error global -->
        <v-snackbar v-model="snackbar.show" :color="snackbar.color" top timeout="5000">
          {{ snackbar.text }}
          <template v-slot:action="{ attrs }">
            <v-btn text v-bind="attrs" @click="snackbar.show = false">Cerrar</v-btn>
          </template>
        </v-snackbar>

      </v-container>
    </v-main>
  </v-app>
</div>

<script src="https://cdn.jsdelivr.net/npm/vue@2.x/dist/vue.js"></script>
<script src="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.js"></script>
<script src="https://unpkg.com/axios/dist/axios.min.js"></script>

<script>
const _TOKEN  = <?= $token ?>;
const _WSURL  = <?= $wsUrlJson ?>;
const _PLANS  = <?= $plansJson ?>;

new Vue({
  el: '#bulk-enroll-app',
  vuetify: new Vuetify({ theme: { dark: false } }),

  data: () => ({
    plans: _PLANS,

    src: { planId: null, periodId: null, subperiodId: null },
    dst: { planId: null, periodId: null, groupname: '' },

    students: [],
    selected: [],
    searchText: '',

    loadingStudents: false,
    enrolling: false,

    results: [],
    enrolledCount: 0,
    skippedCount: 0,
    errorCount: 0,

    snackbar: { show: false, text: '', color: 'error' },
  }),

  computed: {
    srcPeriods() {
      if (!this.src.planId) return [];
      return (this.plans.find(p => p.id === this.src.planId) || {}).periods || [];
    },
    srcSubperiods() {
      if (!this.src.periodId) return [];
      const period = this.srcPeriods.find(p => p.id === this.src.periodId);
      return period ? (period.subperiods || []) : [];
    },
    dstPeriods() {
      if (!this.dst.planId) return [];
      return (this.plans.find(p => p.id === this.dst.planId) || {}).periods || [];
    },

    filteredStudents() {
      if (!this.searchText) return this.students;
      const q = this.searchText.toLowerCase();
      return this.students.filter(s =>
        s.fullname.toLowerCase().includes(q) || s.email.toLowerCase().includes(q)
      );
    },

    filteredGroups() {
      const grouped = {};
      for (const st of this.filteredStudents) {
        const key   = st.groupkey;
        const parts = [st.planname, st.periodname, st.subperiodname].filter(Boolean);
        const label = parts.join(' — ');
        if (!grouped[key]) grouped[key] = { key, label, students: [] };
        grouped[key].students.push(st);
      }
      return Object.values(grouped);
    },

    selectedIds() {
      return this.selected;
    },
  },

  methods: {
    onSrcPlanChange() {
      this.src.periodId    = null;
      this.src.subperiodId = null;
      this.students = [];
      this.selected = [];
    },
    onSrcPeriodChange() {
      this.src.subperiodId = null;
    },
    onDstPlanChange() {
      this.dst.periodId = null;
    },

    async fetchStudents() {
      this.loadingStudents = true;
      this.students = [];
      this.selected = [];
      try {
        const { data } = await axios.get(_WSURL, { params: {
          wstoken: _TOKEN,
          moodlewsrestformat: 'json',
          wsfunction: 'local_grupomakro_get_lp_students',
          planid:      this.src.planId      || 0,
          periodid:    this.src.periodId    || 0,
          subperiodid: this.src.subperiodId || 0,
        }});
        if (data.exception) throw new Error(data.message || data.exception);
        if (data.status === -1) throw new Error(data.message || 'Error al obtener estudiantes.');
        this.students = JSON.parse(data.students || '[]');
      } catch (e) {
        this.notify(e.message || 'Error al cargar estudiantes.', 'error');
      } finally {
        this.loadingStudents = false;
      }
    },

    async bulkEnroll() {
      if (!this.dst.planId) { this.notify('Selecciona un plan destino.', 'warning'); return; }
      if (this.selected.length === 0) { this.notify('Selecciona al menos un estudiante.', 'warning'); return; }
      this.enrolling = true;
      this.results   = [];
      try {
        // Build params with indexed array notation for Moodle WS.
        const params = new URLSearchParams();
        params.append('wstoken', _TOKEN);
        params.append('moodlewsrestformat', 'json');
        params.append('wsfunction', 'local_grupomakro_bulk_enroll');
        params.append('targetplanid', this.dst.planId);
        params.append('periodid',     this.dst.periodId || 0);
        params.append('groupname',    this.dst.groupname || '');
        this.selected.forEach((uid, i) => params.append(`userids[${i}]`, uid));

        const { data } = await axios.post(_WSURL, params);
        if (data.exception) throw new Error(data.message || data.exception);
        this.results       = JSON.parse(data.results || '[]');
        this.enrolledCount = data.enrolled || 0;
        this.skippedCount  = data.skipped  || 0;
        this.errorCount    = data.errors   || 0;
        this.notify(
          `Completado: ${this.enrolledCount} matriculados, ${this.skippedCount} omitidos, ${this.errorCount} errores.`,
          this.errorCount > 0 ? 'warning' : 'success'
        );
        // Refresh student list so already-enrolled ones can be identified.
        await this.fetchStudents();
      } catch (e) {
        this.notify(e.message || 'Error al matricular.', 'error');
      } finally {
        this.enrolling = false;
      }
    },

    selectAll()   { this.selected = this.filteredStudents.map(s => s.userid); },
    deselectAll() { this.selected = []; },

    isGroupAllSelected(group) {
      return group.students.every(s => this.selected.includes(s.userid));
    },
    isGroupPartialSelected(group) {
      const n = group.students.filter(s => this.selected.includes(s.userid)).length;
      return n > 0 && n < group.students.length;
    },
    toggleGroup(group, checked) {
      const ids = group.students.map(s => s.userid);
      if (checked) {
        this.selected = [...new Set([...this.selected, ...ids])];
      } else {
        this.selected = this.selected.filter(id => !ids.includes(id));
      }
    },

    notify(text, color = 'error') {
      this.snackbar = { show: true, text, color };
    },
  },
});
</script>

<style>
#bulk-enroll-app .v-application { background: transparent !important; }
#bulk-enroll-app .v-input input:focus,
#bulk-enroll-app .v-input input:active { box-shadow: none !important; }
</style>
<?php
echo $OUTPUT->footer();
