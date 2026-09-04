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
          { text: 'Puntualidad', value: 'avg_punctuality', align: 'center' }
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
        const params = new URLSearchParams();
        params.append('wstoken', window.themeToken);
        params.append('wsfunction', 'local_grupomakro_admin_list_teacher_evals');
        params.append('moodlewsrestformat', 'json');
        params.append('instructorid', '0');
        params.append('classid', '0');
        params.append('from', String(r.from));
        params.append('to', String(r.to));

        const url = window.location.origin + '/webservice/rest/server.php';
        window.axios.post(url, params, { timeout: 15000 })
          .then(function (resp) {
            if (resp.data && resp.data.exception) {
              throw new Error(resp.data.message || resp.data.errorcode || 'WS error');
            }
            self.evaluations = (resp.data && resp.data.evaluations) || [];
            self.aggregates = (resp.data && resp.data.aggregates) || [];
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
