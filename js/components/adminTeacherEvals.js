// RF-08: panel de Coordinacion Academica para ver evaluaciones docentes.
// SIN ANONIMATO: la tabla muestra el nombre del estudiante.
//
// WS consumido: local_grupomakro_admin_list_teacher_evals
// (admin/wellness/admin_list_teacher_evals.php)
//
// Solo se monta el componente si el usuario tiene la capability
// manage_wellness. La proteccion de servidor ya esta aplicada en el WS,
// asi que cualquier intento de saltarse el chequeo del lado cliente
// devolveria 403.
(function () {
  'use strict';

  if (!window.Vue) {
    console.error('[adminTeacherEvals] Vue no esta cargado; el componente no se montara.');
    return;
  }

  Vue.component('admin-teacher-evals', {
    template: `
      <v-container fluid class="pa-4">
        <v-card class="mb-4" elevation="2">
          <v-card-title class="text-h5">
            <v-icon left color="primary">mdi-school</v-icon>
            Evaluación docente (RF-08)
            <v-spacer />
            <v-btn
              color="primary"
              depressed
              :loading="loading"
              :disabled="loading"
              @click="reload"
              small
            >
              <v-icon left small>mdi-refresh</v-icon>
              Actualizar
            </v-btn>
          </v-card-title>
          <v-card-text>
            <v-row dense>
              <v-col cols="12" md="4">
                <v-text-field
                  v-model="filters.instructorName"
                  label="Filtrar por docente"
                  prepend-inner-icon="mdi-account-search"
                  outlined dense clearable
                  @input="onFilterChange"
                />
              </v-col>
              <v-col cols="12" md="4">
                <v-select
                  v-model="filters.range"
                  :items="rangeOptions"
                  label="Periodo"
                  outlined dense
                  @change="reload"
                />
              </v-col>
              <v-col cols="12" md="4" class="d-flex align-center">
                <span class="caption grey--text text--darken-1">
                  Total de evaluaciones: <strong>{{ filteredEvaluations.length }}</strong>
                </span>
              </v-col>
            </v-row>
          </v-card-text>
        </v-card>


        <!-- ===== Indicadores del periodo ===== -->
        <v-row v-if="kpis" dense class="mb-1">
          <v-col cols="12" sm="6" md="3">
            <v-card outlined class="pa-3 text-center">
              <div class="caption grey--text text-uppercase">Participación</div>
              <div class="text-h4 font-weight-bold" :class="participationColor + '--text'">
                {{ kpis.coverage_rate }}%
              </div>
              <div class="caption grey--text">
                {{ kpis.sent }} de {{ kpis.eligible }} oportunidades
              </div>
            </v-card>
          </v-col>
          <v-col cols="12" sm="6" md="3">
            <v-card outlined class="pa-3 text-center">
              <div class="caption grey--text text-uppercase">Promedio institucional</div>
              <div class="text-h4 font-weight-bold" :class="ratingColor(kpis.avg_overall) + '--text'">
                {{ kpis.avg_overall ? kpis.avg_overall.toFixed(2) : '—' }}
              </div>
              <div class="caption grey--text">
                claridad {{ kpis.avg_clarity ? kpis.avg_clarity.toFixed(1) : '—' }} ·
                puntualidad {{ kpis.avg_punctuality ? kpis.avg_punctuality.toFixed(1) : '—' }}
              </div>
            </v-card>
          </v-col>
          <v-col cols="12" sm="6" md="3">
            <v-card outlined class="pa-3 text-center">
              <div class="caption grey--text text-uppercase">Evaluaciones</div>
              <div class="text-h4 font-weight-bold">{{ kpis.sent }}</div>
              <div class="caption grey--text">
                {{ kpis.with_comments }} con comentario · {{ kpis.dismissed }} descartadas
              </div>
            </v-card>
          </v-col>
          <v-col cols="12" sm="6" md="3">
            <v-card outlined class="pa-3 text-center">
              <div class="caption grey--text text-uppercase">Alcance</div>
              <div class="text-h4 font-weight-bold">{{ kpis.teachers }}</div>
              <div class="caption grey--text">
                docentes · {{ kpis.students }} estudiantes · {{ kpis.classes }} clases
              </div>
            </v-card>
          </v-col>
        </v-row>

        <!-- Sin datos: explicar por que, en vez de dejar tablas vacias -->
        <v-alert
          v-if="kpis && kpis.sent === 0"
          type="info"
          text
          dense
          class="mb-4"
        >
          Todavía no hay ninguna evaluación en este periodo.
          Hubo <strong>{{ kpis.eligible }}</strong> oportunidades elegibles
          (sesiones de clase que un estudiante podía evaluar), así que el circuito
          está activo y esperando respuestas. El popup aparece en el portal del
          estudiante tras el retardo configurado en <em>Bienestar: parámetros</em>.
        </v-alert>

        <!-- Docentes que requieren atencion -->
        <v-alert
          v-if="attentionList.length"
          type="warning"
          text
          dense
          class="mb-4"
        >
          <strong>{{ attentionList.length }}</strong>
          docente(s) con promedio por debajo de
          {{ kpis ? kpis.attention_threshold : 3 }}
          y al menos {{ kpis ? kpis.min_sample : 5 }} respuestas:
          <span v-for="(a, i) in attentionList" :key="a.instructorid">
            <strong>{{ a.teacher_name }}</strong> ({{ a.avg_overall.toFixed(2) }}<span>)</span><span v-if="i < attentionList.length - 1">, </span>
          </span>
        </v-alert>

        <!-- Distribucion y tendencia -->
        <v-row v-if="kpis && kpis.sent > 0" dense class="mb-2">
          <v-col cols="12" md="7">
            <v-card outlined class="pa-3">
              <div class="subtitle-2 mb-2">Distribución de la nota general</div>
              <div v-for="b in distBars" :key="b.score" class="d-flex align-center mb-1">
                <div style="width:28px" class="caption">{{ b.score }}★</div>
                <v-progress-linear
                  :value="b.pct"
                  :color="b.color"
                  height="14"
                  rounded
                  class="flex-grow-1 mx-2"
                ></v-progress-linear>
                <div style="width:92px" class="caption text-right">
                  {{ b.count }} ({{ b.pct }}%)
                </div>
              </div>
              <div class="caption grey--text mt-2">
                Dos docentes con la misma media pueden tener repartos muy distintos;
                aquí se ve si la nota es pareja o está polarizada.
              </div>
            </v-card>
          </v-col>
          <v-col cols="12" md="5">
            <v-card outlined class="pa-3">
              <div class="subtitle-2 mb-2">Evolución mensual</div>
              <v-simple-table dense v-if="trendRows.length">
                <template v-slot:default>
                  <thead>
                    <tr>
                      <th class="text-left">Mes</th>
                      <th class="text-center">Evaluaciones</th>
                      <th class="text-center">Promedio</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="t in trendRows" :key="t.period">
                      <td>{{ t.period }}</td>
                      <td class="text-center">{{ t.total }}</td>
                      <td class="text-center">
                        <v-chip x-small dark :color="ratingColor(t.avg)">{{ t.avg.toFixed(2) }}</v-chip>
                      </td>
                    </tr>
                  </tbody>
                </template>
              </v-simple-table>
              <div v-else class="caption grey--text">Sin datos suficientes todavía.</div>
            </v-card>
          </v-col>
        </v-row>

        <v-card v-if="aggregates.length" class="mb-4" elevation="2">
          <v-card-title class="text-subtitle-1">
            <v-icon left color="primary">mdi-chart-bar</v-icon>
            Promedio por docente
          </v-card-title>
          <v-data-table
            :headers="aggregateHeaders"
            :items="filteredAggregates"
            :items-per-page="10"
            :sort-by="['avg_overall', 'desc']"
            dense
            class="elevation-0"
          >
            <template v-slot:item.teacher_name="{ item }">
              {{ item.teacher_name }}
              <v-tooltip bottom v-if="item.low_sample">
                <template v-slot:activator="{ on, attrs }">
                  <v-icon x-small color="grey" v-bind="attrs" v-on="on" class="ml-1">mdi-alert-circle-outline</v-icon>
                </template>
                <span>Muestra pequeña: el promedio aún no es comparable.</span>
              </v-tooltip>
              <v-icon x-small color="warning" v-if="item.needs_attention" class="ml-1">mdi-flag</v-icon>
            </template>
            <template v-slot:item.dist="{ item }">
              <span class="caption">{{ item.dist }}</span>
            </template>
            <template v-slot:item.last_eval="{ item }">
              <span class="caption">{{ item.last_eval ? formatDate(item.last_eval) : '—' }}</span>
            </template>
            <template v-slot:item.avg_overall="{ item }">
              <v-chip :color="ratingColor(item.avg_overall)" small dark label class="font-weight-bold">
                {{ formatAvg(item.avg_overall) }}
              </v-chip>
            </template>
            <template v-slot:item.avg_clarity="{ item }">
              {{ formatAvg(item.avg_clarity) }}
            </template>
            <template v-slot:item.avg_punctuality="{ item }">
              {{ formatAvg(item.avg_punctuality) }}
            </template>
          </v-data-table>
        </v-card>

        <v-card elevation="2">
          <v-card-title class="text-subtitle-1">
            <v-icon left color="primary">mdi-format-list-bulleted</v-icon>
            Detalle de evaluaciones
            <v-spacer />
            <v-text-field
              v-model="search"
              append-icon="mdi-magnify"
              label="Buscar"
              single-line hide-details dense outlined
              style="max-width: 240px"
            />
          </v-card-title>
          <v-data-table
            :headers="detailHeaders"
            :items="filteredEvaluations"
            :search="search"
            :items-per-page="15"
            :sort-by="['sessiondate', 'desc']"
            class="elevation-0"
          >
            <template v-slot:item.sessiondate="{ item }">
              {{ formatDate(item.sessiondate) }}
            </template>
            <template v-slot:item.rating_overall="{ item }">
              <v-rating
                :value="item.rating_overall"
                color="warning"
                background-color="grey lighten-1"
                length="5"
                size="20"
                readonly dense
              />
            </template>
            <template v-slot:item.rating_clarity="{ item }">
              <span :class="item.rating_clarity ? '' : 'grey--text'">
                {{ item.rating_clarity || '—' }}
              </span>
            </template>
            <template v-slot:item.rating_punctuality="{ item }">
              <span :class="item.rating_punctuality ? '' : 'grey--text'">
                {{ item.rating_punctuality || '—' }}
              </span>
            </template>
            <template v-slot:item.comment="{ item }">
              <span v-if="item.comment" :title="item.comment">{{ truncate(item.comment, 60) }}</span>
              <span v-else class="grey--text text--darken-1 font-italic">sin comentario</span>
            </template>
          </v-data-table>
        </v-card>

        <v-snackbar v-model="snackbar.open" :color="snackbar.color" :timeout="3500" right bottom>
          {{ snackbar.text }}
          <template v-slot:action="{ attrs }">
            <v-btn text v-bind="attrs" @click="snackbar.open = false">Cerrar</v-btn>
          </template>
        </v-snackbar>
      </v-container>
    `,

    data() {
      return {
        loading: false,
        evaluations: [],
        aggregates: [],
        kpis: null,
        trend: [],
        search: '',
        filters: {
          instructorName: '',
          range: '90d'
        },
        rangeOptions: [
          { text: 'Últimos 30 días', value: '30d' },
          { text: 'Últimos 90 días', value: '90d' },
          { text: 'Último año', value: '365d' },
          { text: 'Todo', value: 'all' }
        ],
        snackbar: { open: false, text: '', color: 'success' },
        debounceTimer: null
      };
    },

    computed: {
      aggregateHeaders() {
        return [
          { text: 'Docente', value: 'teacher_name' },
          { text: 'Evaluaciones', value: 'total', align: 'center' },
          { text: 'General', value: 'avg_overall', align: 'center' },
          { text: 'Claridad', value: 'avg_clarity', align: 'center' },
          { text: 'Puntualidad', value: 'avg_punctuality', align: 'center' },
          { text: 'Distribución', value: 'dist', align: 'center', sortable: false, width: 140 },
          { text: 'Comentarios', value: 'with_comments', align: 'center' },
          { text: 'Última', value: 'last_eval', align: 'center', width: 110 }
        ];
      },
      detailHeaders() {
        return [
          { text: 'Fecha', value: 'sessiondate', width: 160 },
          { text: 'Clase', value: 'classname' },
          { text: 'Docente', value: 'teacher_name' },
          { text: 'Estudiante', value: 'student_name' },
          { text: 'General', value: 'rating_overall', align: 'center', width: 130 },
          { text: 'Claridad', value: 'rating_clarity', align: 'center', width: 90 },
          { text: 'Puntualidad', value: 'rating_punctuality', align: 'center', width: 100 },
          { text: 'Comentario', value: 'comment' }
        ];
      },
      // Docentes con media baja Y muestra suficiente. El segundo requisito
      // es el que evita senalar a alguien por una unica mala nota.
      attentionList() {
        return (this.aggregates || []).filter(function (a) { return a.needs_attention; });
      },
      // Reparto de notas 1-5 del instituto, en porcentaje, para la barra.
      distBars() {
        var raw = (this.kpis && this.kpis.dist) ? String(this.kpis.dist).split(',') : [];
        var nums = raw.map(function (n) { return parseInt(n, 10) || 0; });
        var total = nums.reduce(function (a, b) { return a + b; }, 0);
        var colors = ['red darken-2', 'deep-orange', 'amber darken-2', 'light-green darken-1', 'green darken-2'];
        return nums.map(function (n, i) {
          return {
            score: i + 1,
            count: n,
            pct: total > 0 ? Math.round(n * 1000 / total) / 10 : 0,
            color: colors[i]
          };
        });
      },
      // Ultimos 12 meses de la serie, del mas reciente al mas antiguo.
      trendRows() {
        return (this.trend || []).slice(-12).reverse();
      },
      participationColor() {
        if (!this.kpis) return 'grey';
        var r = this.kpis.coverage_rate;
        if (r >= 50) return 'green darken-2';
        if (r >= 20) return 'amber darken-2';
        return 'red darken-2';
      },
      filteredAggregates() {
        const q = (this.filters.instructorName || '').trim().toLowerCase();
        if (!q) return this.aggregates;
        return this.aggregates.filter(function (a) {
          return (a.teacher_name || '').toLowerCase().indexOf(q) !== -1;
        });
      },
      filteredEvaluations() {
        const q = (this.filters.instructorName || '').trim().toLowerCase();
        if (!q) return this.evaluations;
        return this.evaluations.filter(function (e) {
          return (e.teacher_name || '').toLowerCase().indexOf(q) !== -1;
        });
      },
      rangeFromTo() {
        const now = Math.floor(Date.now() / 1000);
        const day = 86400;
        switch (this.filters.range) {
          case '30d': return { from: now - 30 * day, to: now };
          case '90d': return { from: now - 90 * day, to: now };
          case '365d': return { from: now - 365 * day, to: now };
          default: return { from: 0, to: 0 };
        }
      }
    },

    mounted() {
      this.reload();
    },

    methods: {
      onFilterChange() {
        // Debounce para no recomputar en cada keystroke.
        const self = this;
        if (self.debounceTimer) clearTimeout(self.debounceTimer);
        self.debounceTimer = setTimeout(function () {
          // El filtro es client-side, no requiere nueva llamada WS,
          // pero igual avisamos para que el count del header se refresque.
          self.$forceUpdate();
        }, 200);
      },
      reload() {
        const self = this;
        if (!window.axios) {
          self.notify('Axios no esta disponible; recarga la pagina.', 'error');
          return;
        }
        self.loading = true;
        const r = self.rangeFromTo;

        // Se llama por ajax.php con la sesskey de la sesion, igual que el
        // resto de paneles de Bienestar. Antes usaba
        // /webservice/rest/server.php con window.themeToken, un token que
        // esta pagina NUNCA define (solo emite ajaxUrl y sesskey), asi que
        // el panel fallaba siempre con "Ficha (token) no valida" y no
        // llegaba a mostrar un solo dato.
        window.axios.post(ajaxUrl, {
          action: 'local_grupomakro_admin_list_teacher_evals',
          args: {
            instructorid: 0,
            classid: 0,
            from: r.from,
            to: r.to
          }
        }, { params: { sesskey: sesskey }, timeout: 15000 })
          .then(function (resp) {
            const body = resp.data || {};
            if (body.status !== 'success') {
              throw new Error(body.message || 'WS error');
            }
            const d = body.data || {};
            self.evaluations = d.evaluations || [];
            self.aggregates = d.aggregates || [];
            self.kpis = d.kpis || null;
            self.trend = d.trend || [];
            self.notify('Datos actualizados.', 'success');
          })
          .catch(function (err) {
            console.error('[adminTeacherEvals] reload failed:', err);
            self.notify('No se pudieron cargar las evaluaciones.', 'error');
          })
          .finally(function () { self.loading = false; });
      },
      notify(text, color) {
        this.snackbar = { open: true, text: text, color: color || 'info' };
      },
      formatDate(ts) {
        if (!ts) return '';
        const d = new Date(Number(ts) * 1000);
        try {
          return d.toLocaleDateString('es-PA', {
            day: '2-digit', month: '2-digit', year: 'numeric'
          });
        } catch (_e) { return d.toISOString().slice(0, 10); }
      },
      formatAvg(n) {
        const v = Number(n);
        if (!Number.isFinite(v)) return '—';
        return v.toFixed(2);
      },
      ratingColor(v) {
        if (v >= 4) return 'success';
        if (v >= 3) return 'warning';
        return 'error';
      },
      truncate(s, max) {
        if (!s) return '';
        return s.length > max ? (s.slice(0, max - 1) + '…') : s;
      }
    }
  });
})();
