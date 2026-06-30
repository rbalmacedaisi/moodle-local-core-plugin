Vue.component('create-extemp-revalidation-modal', {
    template: `
        <v-dialog :value="value" @input="$emit('input', $event)" persistent max-width="780" scrollable>
            <v-card>
                <v-card-title class="text-h6">
                    <v-icon left color="amber darken-2">mdi-clock-alert-outline</v-icon>
                    Solicitud de reválida extemporánea
                    <v-spacer></v-spacer>
                    <v-btn icon @click="close"><v-icon>mdi-close</v-icon></v-btn>
                </v-card-title>
                <v-card-text style="max-height: 70vh;">
                    <v-stepper v-model="step" vertical flat>
                        <!-- Step 1: Class -->
                        <v-stepper-step :complete="step > 1" step="1">
                            Seleccione la clase
                            <small v-if="selectedClass">Clase: {{ selectedClass.name }}</small>
                        </v-stepper-step>
                        <v-stepper-content step="1">
                            <v-autocomplete
                                v-model="selectedClassId"
                                :items="classOptions"
                                :loading="loadingClasses"
                                :search-input.sync="classSearch"
                                item-text="label"
                                item-value="value"
                                cache-items
                                clearable
                                label="Buscar clase por nombre, asignatura o docente"
                                placeholder="Escriba para buscar..."
                                hide-details
                                outlined
                                dense
                                @update:search-input="onClassSearchInput"
                            >
                                <template v-slot:item="{ item }">
                                    <v-list-item-content>
                                        <v-list-item-title>{{ item.raw.name }}</v-list-item-title>
                                        <v-list-item-subtitle>
                                            {{ item.raw.coursename }} · {{ item.raw.instructor_name }}
                                            <span v-if="item.raw.periodname"> · {{ item.raw.periodname }}</span>
                                        </v-list-item-subtitle>
                                    </v-list-item-content>
                                    <v-list-item-action>
                                        <v-chip x-small color="amber darken-2" class="white--text" v-if="item.raw.eligible_count > 0">
                                            {{ item.raw.eligible_count }} elegible(s)
                                        </v-chip>
                                    </v-list-item-action>
                                </template>
                            </v-autocomplete>
                            <div class="d-flex mt-3">
                                <v-spacer></v-spacer>
                                <v-btn color="primary" :disabled="!selectedClassId" @click="step = 2">
                                    Siguiente
                                    <v-icon right>mdi-arrow-right</v-icon>
                                </v-btn>
                            </div>
                        </v-stepper-content>

                        <!-- Step 2: Student -->
                        <v-stepper-step :complete="step > 2" step="2">
                            Seleccione el estudiante
                            <small v-if="selectedStudent">Estudiante: {{ selectedStudent.fullname }} — {{ selectedStudent.is_eligible ? 'Elegible' : 'No elegible' }}</small>
                        </v-stepper-step>
                        <v-stepper-content step="2">
                            <v-text-field
                                v-model="studentSearch"
                                @input="onStudentSearchInput"
                                label="Filtrar estudiantes"
                                prepend-inner-icon="mdi-magnify"
                                clearable hide-details dense outlined
                                class="mb-2"
                            ></v-text-field>
                            <v-list dense max-height="320" class="overflow-y-auto">
                                <v-list-item v-for="s in filteredStudents" :key="s.userid"
                                    @click="pickStudent(s)"
                                    :class="{'rd-picked': selectedStudent && selectedStudent.userid === s.userid}"
                                    style="border-bottom:1px solid #eee;">
                                    <v-list-item-content>
                                        <v-list-item-title>
                                            {{ s.fullname }}
                                            <v-chip v-if="s.is_eligible" x-small color="green" class="ml-2 white--text">Elegible</v-chip>
                                            <v-chip v-else x-small color="grey lighten-1" class="ml-2">No elegible</v-chip>
                                            <v-chip v-if="s.existing_revalidation_id && s.existing_revalidation_status === 'consolidated'"
                                                x-small color="red" class="ml-2 white--text">Consolidada</v-chip>
                                            <v-chip v-else-if="s.existing_revalidation_id"
                                                x-small color="amber darken-2" class="ml-2 white--text">Ya tiene solicitud</v-chip>
                                        </v-list-item-title>
                                        <v-list-item-subtitle>
                                            Nota: {{ s.final_grade === null ? '—' : s.final_grade }} · PH: {{ s.practicalhours }} · {{ s.progress_label }}
                                            <span v-if="!s.is_eligible && s.ineligibility_reason"> · <em>{{ s.ineligibility_reason }}</em></span>
                                        </v-list-item-subtitle>
                                    </v-list-item-content>
                                </v-list-item>
                            </v-list>
                            <div class="d-flex mt-3">
                                <v-btn text @click="step = 1">
                                    <v-icon left>mdi-arrow-left</v-icon>
                                    Atrás
                                </v-btn>
                                <v-spacer></v-spacer>
                                <v-btn color="primary"
                                    :disabled="!selectedStudent || !selectedStudent.is_eligible || (selectedStudent.existing_revalidation_id && selectedStudent.existing_revalidation_status === 'consolidated')"
                                    @click="step = 3">
                                    Siguiente
                                    <v-icon right>mdi-arrow-right</v-icon>
                                </v-btn>
                            </div>
                        </v-stepper-content>

                        <!-- Step 3: Reason + confirm -->
                        <v-stepper-step :complete="step > 3" step="3">Datos de la solicitud</v-stepper-step>
                        <v-stepper-content step="3">
                            <div v-if="selectedStudent">
                                <v-alert type="success" dense text class="mb-3">
                                    <strong>{{ selectedStudent.fullname }}</strong>
                                    cumple los criterios de elegibilidad (nota final {{ selectedStudent.final_grade }}, sin horas prácticas).
                                </v-alert>
                            </div>
                            <v-textarea
                                v-model="reason"
                                label="Motivo de la solicitud extemporánea *"
                                placeholder="Explique brevemente por qué esta solicitud se realiza fuera de la ventana ordinaria."
                                :counter="500"
                                :rules="[v => !v || (v.length >= 20) || 'Mínimo 20 caracteres', v => !v || (v.length <= 500) || 'Máximo 500 caracteres']"
                                outlined
                                rows="3"
                                auto-grow
                            ></v-textarea>
                            <v-text-field
                                v-model="sessionDateLocal"
                                label="Fecha de la sesión (opcional)"
                                type="datetime-local"
                                outlined dense
                                hint="Si se deja vacía, se usa la próxima semana con el mismo día/hora de la clase."
                                persistent-hint
                            ></v-text-field>
                            <div class="d-flex mt-3">
                                <v-btn text @click="step = 2">
                                    <v-icon left>mdi-arrow-left</v-icon>
                                    Atrás
                                </v-btn>
                                <v-spacer></v-spacer>
                                <v-btn color="amber darken-2" class="white--text"
                                    :disabled="!canSubmit"
                                    :loading="submitting"
                                    @click="submit">
                                    <v-icon left>mdi-check</v-icon>
                                    Crear solicitud
                                </v-btn>
                            </div>
                        </v-stepper-content>
                    </v-stepper>
                </v-card-text>
            </v-card>
        </v-dialog>
    `,
    props: {
        value: { type: Boolean, default: false }
    },
    data() {
        return {
            step: 1,
            classSearch: '',
            classOptions: [],
            loadingClasses: false,
            classSearchTimer: null,
            selectedClassId: null,
            selectedClass: null,
            studentSearch: '',
            studentSearchTimer: null,
            students: [],
            loadingStudents: false,
            selectedStudent: null,
            reason: '',
            sessionDateLocal: '',
            submitting: false
        };
    },
    computed: {
        filteredStudents() {
            const q = (this.studentSearch || '').toLowerCase().trim();
            if (!q) return this.students;
            return this.students.filter(s => {
                return (s.fullname || '').toLowerCase().includes(q)
                    || (s.idnumber || '').toLowerCase().includes(q)
                    || (s.email || '').toLowerCase().includes(q);
            });
        },
        canSubmit() {
            return this.reason && this.reason.length >= 20 && this.reason.length <= 500 && this.selectedStudent;
        }
    },
    watch: {
        value(v) {
            if (v) {
                this.reset();
            }
        },
        selectedClassId: {
            handler(v) {
                if (!v) {
                    this.selectedClass = null;
                    this.students = [];
                    return;
                }
                const found = this.classOptions.find(o => o.value === v);
                if (found) {
                    this.selectedClass = found;
                    this.fetchStudents();
                }
            }
        }
    },
    methods: {
        close() { this.$emit('input', false); },
        reset() {
            this.step = 1;
            this.classSearch = '';
            this.selectedClassId = null;
            this.selectedClass = null;
            this.studentSearch = '';
            this.students = [];
            this.selectedStudent = null;
            this.reason = '';
            this.sessionDateLocal = '';
        },
        onClassSearchInput(v) {
            this.classSearch = v || '';
            clearTimeout(this.classSearchTimer);
            this.classSearchTimer = setTimeout(() => this.fetchClasses(), 250);
        },
        async fetchClasses() {
            this.loadingClasses = true;
            try {
                const r = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_get_classes_for_search',
                    args: { query: this.classSearch, limit: 20, only_with_eligible: false },
                    sesskey: window.Y ? window.Y.config.sesskey : sesskey
                });
                if (r.data && r.data.status === 'success') {
                    const list = r.data.data.classes || [];
                    this.classOptions = list.map(c => ({
                        value: c.id,
                        label: `${c.name} — ${c.coursename} (${c.instructor_name})`,
                        ...c
                    }));
                }
            } catch (e) {
                console.error('[extemp modal] fetchClasses:', e);
            } finally {
                this.loadingClasses = false;
            }
        },
        watch_selectedClassId: {
            handler(v) {
                if (!v) {
                    this.selectedClass = null;
                    this.students = [];
                    return;
                }
                const found = this.classOptions.find(o => o.value === v);
                if (found) {
                    this.selectedClass = found;
                    this.fetchStudents();
                }
            }
        },
        onStudentSearchInput() {
            clearTimeout(this.studentSearchTimer);
            // Just a UI filter; we already fetched all students of the class.
        },
        async fetchStudents() {
            if (!this.selectedClassId) return;
            this.loadingStudents = true;
            try {
                const r = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_get_eligible_students_for_extemporaneous',
                    args: { classid: this.selectedClassId, search: '', only_eligible: false },
                    sesskey: window.Y ? window.Y.config.sesskey : sesskey
                });
                if (r.data && r.data.status === 'success') {
                    this.students = r.data.data.students || [];
                } else {
                    throw new Error((r.data && r.data.message) || 'No se pudieron cargar los estudiantes');
                }
            } catch (e) {
                console.error('[extemp modal] fetchStudents:', e);
                Swal.fire('Error', e.message || 'No se pudieron cargar los estudiantes de la clase.', 'error');
                this.students = [];
            } finally {
                this.loadingStudents = false;
            }
        },
        pickStudent(s) {
            this.selectedStudent = s;
        },
        async submit() {
            if (!this.canSubmit || !this.selectedStudent) return;
            this.submitting = true;
            try {
                let overrideStart = 0;
                if (this.sessionDateLocal) {
                    const ts = new Date(this.sessionDateLocal).getTime();
                    if (!isNaN(ts)) overrideStart = Math.floor(ts / 1000);
                }
                const r = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_create_extemporaneous_revalidation',
                    args: {
                        classid: this.selectedClassId,
                        userid: this.selectedStudent.userid,
                        reason: this.reason,
                        override_session_start: overrideStart
                    },
                    sesskey: window.Y ? window.Y.config.sesskey : sesskey
                });
                if (r.data && r.data.status === 'success' && r.data.data && r.data.data.ok) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Solicitud creada',
                        html: 'Factura generada en Odoo: <strong>' + (r.data.data.record.invoice_number || '—') + '</strong>',
                        timer: 3500,
                        showConfirmButton: false
                    });
                    this.$emit('created', r.data.data.record);
                    this.close();
                } else {
                    const err = (r.data && r.data.data && r.data.data.error) || (r.data && r.data.message) || 'No se pudo crear la solicitud';
                    Swal.fire('Error', err, 'error');
                }
            } catch (e) {
                console.error('[extemp modal] submit:', e);
                Swal.fire('Error', e.message || 'No se pudo crear la solicitud extemporánea.', 'error');
            } finally {
                this.submitting = false;
            }
        }
    },
    created() {
        this.fetchClasses();
    }
});