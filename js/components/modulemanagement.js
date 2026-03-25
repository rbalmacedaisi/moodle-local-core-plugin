/**
 * Module Management component — admin page for managing independent study modules.
 * Lists all module classes grouped by period; shows enrolled students with deadline info.
 */
Vue.component('modulemanagement', {
    data() {
        return {
            wwwroot: (typeof wwwroot !== 'undefined' ? wwwroot : ''),

            // Periods
            periods: [],
            selectedPeriodId: 0,

            // Modules table
            modules: [],
            loadingModules: false,
            moduleSearch: '',
            moduleHeaders: [
                { text: 'Asignatura',    value: 'coursename',           sortable: true  },
                { text: 'Período',       value: 'periodcode',           sortable: true  },
                { text: 'Grupo Moodle',  value: 'name',                 sortable: false },
                { text: 'Plazo (días)',  value: 'module_deadline_days', sortable: true, align: 'center' },
                { text: 'Inscritos',     value: 'enrolled_count',       sortable: true, align: 'center' },
                { text: 'Acciones',      value: '_actions',             sortable: false, align: 'center' },
            ],

            // Students dialog
            studentsDialog:    false,
            selectedModule:    null,
            students:          [],
            loadingStudents:   false,
            studentHeaders: [
                { text: 'Estudiante',       value: '_fullname',    sortable: true  },
                { text: 'Inscripción',      value: 'enrolldate',   sortable: true  },
                { text: 'Fecha límite',     value: 'duedate',      sortable: true  },
                { text: 'Días restantes',   value: '_daysremaining', sortable: true, align: 'center' },
                { text: 'Estado',           value: 'status',       sortable: true, align: 'center'  },
                { text: 'Acciones',         value: '_actions',     sortable: false, align: 'center' },
            ],

            // Extend deadline dialog
            extendDialog:         false,
            selectedEnrollment:   null,
            extendDays:           15,
            savingExtend:         false,

            // Update enrollment loading map { enrollmentid: true }
            updatingMap: {},
        };
    },

    computed: {
        periodItems() {
            const all = [{ id: 0, label: 'Todos los períodos' }];
            return all.concat(
                this.periods.map(p => ({ id: p.id, label: p.code || p.name }))
            );
        },
    },

    mounted() {
        this.loadPeriods();
    },

    methods: {
        // ── Data loading ──────────────────────────────────────────────────────
        async loadPeriods() {
            try {
                const res = await window.axios.get(ajaxUrl, { params: {
                    action: 'local_grupomakro_get_academic_periods',
                    sesskey,
                }});
                const data = (res.data || {}).data || [];
                this.periods = Array.isArray(data) ? data : [];
                // Default to "all periods" so newly created modules are always visible
                this.loadModules();
            } catch (e) {
                console.error('Error loading periods:', e);
                this.loadModules();
            }
        },

        async loadModules() {
            this.loadingModules = true;
            this.modules = [];
            try {
                const res = await window.axios.get(ajaxUrl, { params: {
                    action:   'local_grupomakro_get_module_list',
                    sesskey,
                    periodId: this.selectedPeriodId || 0,
                }});
                const data = (res.data || {}).data || [];
                this.modules = Array.isArray(data) ? data : [];
            } catch (e) {
                console.error('Error loading modules:', e);
            } finally {
                this.loadingModules = false;
            }
        },

        async openStudents(module) {
            this.selectedModule  = module;
            this.students        = [];
            this.studentsDialog  = true;
            this.loadingStudents = true;
            try {
                const res = await window.axios.get(ajaxUrl, { params: {
                    action:  'local_grupomakro_get_module_students',
                    sesskey,
                    classId: module.id,
                }});
                const raw = (res.data || {}).data || [];
                const now = Math.floor(Date.now() / 1000);
                this.students = (Array.isArray(raw) ? raw : []).map(s => ({
                    ...s,
                    _fullname:      s.lastname + ', ' + s.firstname,
                    _daysremaining: Math.max(0, Math.ceil((s.duedate - now) / 86400)),
                }));
            } catch (e) {
                console.error('Error loading module students:', e);
            } finally {
                this.loadingStudents = false;
            }
        },

        // ── Enrollment updates ────────────────────────────────────────────────
        openExtendDialog(enrollment) {
            this.selectedEnrollment = enrollment;
            this.extendDays         = 15;
            this.extendDialog       = true;
        },

        async confirmExtend() {
            if (!this.selectedEnrollment || this.extendDays < 1) return;
            this.savingExtend = true;
            try {
                const res = await window.axios.get(ajaxUrl, { params: {
                    action:       'local_grupomakro_update_module_enrollment',
                    sesskey,
                    enrollmentId: this.selectedEnrollment.id,
                    updateAction: 'extend',
                    days:         this.extendDays,
                }});
                const payload = res.data || {};
                if (payload.status === 'success') {
                    const d = payload.data || {};
                    // Refresh the row
                    const idx = this.students.findIndex(s => s.id === this.selectedEnrollment.id);
                    if (idx !== -1) {
                        const now = Math.floor(Date.now() / 1000);
                        this.$set(this.students, idx, {
                            ...this.students[idx],
                            duedate:        d.duedate,
                            _daysremaining: Math.max(0, Math.ceil((d.duedate - now) / 86400)),
                        });
                    }
                    this.extendDialog = false;
                    this.showMessage('success', d.message || 'Plazo extendido correctamente.');
                } else {
                    this.showMessage('error', ((payload.data || payload).message) || 'No se pudo extender el plazo.');
                }
            } catch (e) {
                console.error('Error extending deadline:', e);
                this.showMessage('error', 'Error al extender el plazo.');
            } finally {
                this.savingExtend = false;
            }
        },

        async removeEnrollment(enrollment) {
            const confirmed = await window.Swal.fire({
                title:             'Desvincular estudiante',
                html:              `¿Desvincular a <b>${enrollment._fullname}</b> de este módulo?<br><small class="red--text">Se eliminará la inscripción y se removerá del grupo Moodle. Esta acción no se puede deshacer.</small>`,
                icon:              'warning',
                showCancelButton:  true,
                confirmButtonText: 'Sí, desvincular',
                cancelButtonText:  'Cancelar',
                confirmButtonColor:'#D32F2F',
            });
            if (!confirmed.isConfirmed) return;

            this.$set(this.updatingMap, enrollment.id, true);
            try {
                const res = await window.axios.get(ajaxUrl, { params: {
                    action:       'local_grupomakro_update_module_enrollment',
                    sesskey,
                    enrollmentId: enrollment.id,
                    updateAction: 'remove',
                }});
                const payload = res.data || {};
                if (payload.status === 'success') {
                    const d = payload.data || {};
                    // Remove row from students list
                    this.students = this.students.filter(s => s.id !== enrollment.id);
                    // Decrement count on parent module if was active
                    if (d.was_active) {
                        const mIdx = this.modules.findIndex(m => m.id === (this.selectedModule || {}).id);
                        if (mIdx !== -1) {
                            this.$set(this.modules, mIdx, {
                                ...this.modules[mIdx],
                                enrolled_count: Math.max(0, (this.modules[mIdx].enrolled_count || 1) - 1),
                            });
                        }
                    }
                    this.showMessage('success', d.message || 'Estudiante desvinculado.');
                } else {
                    this.showMessage('error', ((payload.data || payload).message) || 'No se pudo desvincular.');
                }
            } catch (e) {
                console.error('Error removing enrollment:', e);
                this.showMessage('error', 'Error al desvincular al estudiante.');
            } finally {
                this.$delete(this.updatingMap, enrollment.id);
            }
        },

        async markCompleted(enrollment) {
            const confirmed = await window.Swal.fire({
                title:             'Marcar como completado',
                html:              `¿Marcar a <b>${enrollment._fullname}</b> como completado en este módulo?`,
                icon:              'question',
                showCancelButton:  true,
                confirmButtonText: 'Sí, completado',
                cancelButtonText:  'Cancelar',
                confirmButtonColor:'#4CAF50',
            });
            if (!confirmed.isConfirmed) return;

            this.$set(this.updatingMap, enrollment.id, true);
            try {
                const res = await window.axios.get(ajaxUrl, { params: {
                    action:       'local_grupomakro_update_module_enrollment',
                    sesskey,
                    enrollmentId: enrollment.id,
                    updateAction: 'complete',
                }});
                const payload = res.data || {};
                if (payload.status === 'success') {
                    const idx = this.students.findIndex(s => s.id === enrollment.id);
                    if (idx !== -1) {
                        this.$set(this.students, idx, { ...this.students[idx], status: 'completed' });
                    }
                    // Refresh enrolled count on parent module
                    const mIdx = this.modules.findIndex(m => m.id === (this.selectedModule || {}).id);
                    if (mIdx !== -1) {
                        this.$set(this.modules, mIdx, {
                            ...this.modules[mIdx],
                            enrolled_count: Math.max(0, (this.modules[mIdx].enrolled_count || 1) - 1),
                        });
                    }
                    this.showMessage('success', 'Inscripción marcada como completada.');
                } else {
                    this.showMessage('error', ((payload.data || payload).message) || 'No se pudo actualizar.');
                }
            } catch (e) {
                console.error('Error marking complete:', e);
                this.showMessage('error', 'Error al actualizar la inscripción.');
            } finally {
                this.$delete(this.updatingMap, enrollment.id);
            }
        },

        // ── Helpers ───────────────────────────────────────────────────────────
        formatDate(ts) {
            if (!ts) return '—';
            return new Date(ts * 1000).toLocaleDateString('es-PA', {
                day: '2-digit', month: '2-digit', year: 'numeric',
            });
        },

        daysColor(days) {
            if (days <= 0)  return 'grey';
            if (days <= 7)  return 'red darken-1';
            if (days <= 15) return 'orange darken-1';
            return 'green darken-1';
        },

        statusLabel(status) {
            const map = { active: 'Activo', completed: 'Completado', expired: 'Vencido' };
            return map[status] || status;
        },

        statusColor(status) {
            const map = { active: 'teal', completed: 'green', expired: 'red' };
            return map[status] || 'grey';
        },

        showMessage(type, text) {
            window.Swal.fire({ icon: type, text, toast: true, position: 'top-end',
                showConfirmButton: false, timer: 4000 });
        },
    },

    template: `
<v-container fluid class="pa-4">

  <!-- Header -->
  <v-row class="mb-2" align="center">
    <v-col>
      <div class="d-flex align-center">
        <v-icon large color="teal darken-2" class="mr-2">mdi-book-education-outline</v-icon>
        <span class="text-h5 font-weight-bold">Gestión de Módulos Independientes</span>
      </div>
      <div class="text-caption grey--text mt-1">
        Administre los módulos de estudio autónomo: consulte inscritos, extienda plazos y marque completados.
      </div>
    </v-col>
  </v-row>

  <!-- Filters -->
  <v-row align="center" class="mb-2">
    <v-col cols="12" sm="4" md="3">
      <v-select
        v-model="selectedPeriodId"
        :items="periodItems"
        item-text="label"
        item-value="id"
        label="Período académico"
        outlined
        dense
        hide-details
        @change="loadModules"
      ></v-select>
    </v-col>
    <v-col cols="12" sm="5" md="4">
      <v-text-field
        v-model="moduleSearch"
        prepend-inner-icon="mdi-magnify"
        label="Buscar asignatura..."
        outlined
        dense
        hide-details
        clearable
      ></v-text-field>
    </v-col>
    <v-col class="text-right">
      <v-btn small outlined color="teal darken-2" @click="loadModules" :loading="loadingModules">
        <v-icon small left>mdi-refresh</v-icon> Actualizar
      </v-btn>
    </v-col>
  </v-row>

  <!-- Modules table -->
  <v-card outlined>
    <v-data-table
      :headers="moduleHeaders"
      :items="modules"
      :loading="loadingModules"
      :search="moduleSearch"
      loading-text="Cargando módulos..."
      no-data-text="No hay módulos registrados para el período seleccionado."
      :footer-props="{ 'items-per-page-options': [10, 25, 50] }"
      dense
    >
      <!-- Asignatura -->
      <template v-slot:item.coursename="{ item }">
        <span class="font-weight-medium">{{ item.coursename }}</span>
      </template>

      <!-- Período -->
      <template v-slot:item.periodcode="{ item }">
        <v-chip x-small outlined color="teal">{{ item.periodcode }}</v-chip>
      </template>

      <!-- Grupo Moodle — link to group members page -->
      <template v-slot:item.name="{ item }">
        <a
          v-if="item.groupid"
          :href="wwwroot + '/group/members.php?group=' + item.groupid"
          target="_blank"
          class="teal--text text--darken-2"
          style="text-decoration:none; display:inline-flex; align-items:center; gap:4px;"
          title="Ver grupo en Moodle"
        >
          <v-icon x-small color="teal darken-2">mdi-open-in-new</v-icon>
          {{ item.name }}
        </a>
        <span v-else>{{ item.name }}</span>
      </template>

      <!-- Plazo días -->
      <template v-slot:item.module_deadline_days="{ item }">
        <span>{{ item.module_deadline_days }} días</span>
      </template>

      <!-- Inscritos -->
      <template v-slot:item.enrolled_count="{ item }">
        <v-chip x-small :color="item.enrolled_count > 0 ? 'teal darken-2' : 'grey'" dark>
          {{ item.enrolled_count }}
        </v-chip>
      </template>

      <!-- Acciones -->
      <template v-slot:item._actions="{ item }">
        <v-btn x-small color="teal darken-2" dark @click="openStudents(item)" title="Ver estudiantes inscritos">
          <v-icon x-small left>mdi-account-group</v-icon> Estudiantes
        </v-btn>
      </template>
    </v-data-table>
  </v-card>

  <!-- ── Students dialog ───────────────────────────────────────────────── -->
  <v-dialog v-model="studentsDialog" max-width="900" scrollable>
    <v-card>
      <v-card-title class="teal darken-2 white--text">
        <v-icon left dark>mdi-account-group</v-icon>
        <span v-if="selectedModule">
          Estudiantes — {{ selectedModule.coursename }}
          <v-chip x-small class="ml-2" outlined dark>{{ selectedModule.periodcode }}</v-chip>
        </span>
        <v-spacer></v-spacer>
        <v-btn icon dark @click="studentsDialog = false"><v-icon>mdi-close</v-icon></v-btn>
      </v-card-title>

      <v-card-text class="pa-0">
        <v-data-table
          :headers="studentHeaders"
          :items="students"
          :loading="loadingStudents"
          loading-text="Cargando estudiantes..."
          no-data-text="No hay estudiantes inscritos en este módulo."
          :footer-props="{ 'items-per-page-options': [10, 25, 50] }"
          dense
        >
          <!-- Student name -->
          <template v-slot:item._fullname="{ item }">
            <span class="font-weight-medium">{{ item._fullname }}</span>
            <div class="text-caption grey--text">{{ item.email }}</div>
          </template>

          <!-- Enrollment date -->
          <template v-slot:item.enrolldate="{ item }">
            {{ formatDate(item.enrolldate) }}
          </template>

          <!-- Due date -->
          <template v-slot:item.duedate="{ item }">
            {{ formatDate(item.duedate) }}
          </template>

          <!-- Days remaining -->
          <template v-slot:item._daysremaining="{ item }">
            <v-chip x-small :color="daysColor(item._daysremaining)" dark v-if="item.status === 'active'">
              {{ item._daysremaining }} días
            </v-chip>
            <span v-else class="grey--text">—</span>
          </template>

          <!-- Status chip -->
          <template v-slot:item.status="{ item }">
            <v-chip x-small :color="statusColor(item.status)" dark>{{ statusLabel(item.status) }}</v-chip>
          </template>

          <!-- Row actions -->
          <template v-slot:item._actions="{ item }">
            <div style="white-space:nowrap">
              <template v-if="item.status === 'active'">
                <v-btn x-small outlined color="orange darken-2"
                  class="mr-1"
                  :disabled="!!updatingMap[item.id]"
                  @click="openExtendDialog(item)"
                  title="Extender plazo"
                >
                  <v-icon x-small left>mdi-clock-plus-outline</v-icon> Extender
                </v-btn>
                <v-btn x-small outlined color="green darken-2"
                  class="mr-1"
                  :loading="!!updatingMap[item.id]"
                  @click="markCompleted(item)"
                  title="Marcar como completado"
                >
                  <v-icon x-small left>mdi-check-circle-outline</v-icon> Completado
                </v-btn>
              </template>
              <v-btn x-small outlined color="red darken-2"
                :loading="!!updatingMap[item.id]"
                :disabled="!!updatingMap[item.id]"
                @click="removeEnrollment(item)"
                title="Desvincular del módulo"
              >
                <v-icon x-small left>mdi-account-remove-outline</v-icon> Desvincular
              </v-btn>
            </div>
          </template>
        </v-data-table>
      </v-card-text>
    </v-card>
  </v-dialog>

  <!-- ── Extend deadline dialog ─────────────────────────────────────────── -->
  <v-dialog v-model="extendDialog" max-width="420">
    <v-card>
      <v-card-title>Extender plazo</v-card-title>
      <v-card-text>
        <div class="mb-3" v-if="selectedEnrollment">
          Estudiante: <strong>{{ selectedEnrollment._fullname }}</strong><br>
          Fecha límite actual: <strong>{{ formatDate(selectedEnrollment.duedate) }}</strong>
        </div>
        <v-text-field
          v-model.number="extendDays"
          label="Días adicionales"
          type="number"
          min="1"
          max="365"
          outlined
          dense
          suffix="días"
        ></v-text-field>
      </v-card-text>
      <v-card-actions>
        <v-spacer></v-spacer>
        <v-btn text @click="extendDialog = false" :disabled="savingExtend">Cancelar</v-btn>
        <v-btn color="orange darken-2" dark :loading="savingExtend" @click="confirmExtend">
          Extender plazo
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-dialog>

</v-container>
`,
});
