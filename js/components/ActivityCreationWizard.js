/**
 * Activity Creation Wizard Component
 * Created for Redesigning Teacher Experience
 */

const ActivityCreationWizard = {
    props: {
        classId: { type: Number, required: true },
        activityType: { type: String, required: true }, // 'bbb', 'assignment', 'resource'
        customLabel: { type: String, default: '' },
        editMode: { type: Boolean, default: false },
        editData: { type: Object, default: null }
    },
    template: `
        <v-dialog v-model="visible" max-width="600px" persistent>
            <v-card class="rounded-lg">
                <v-card-title class="headline font-weight-bold" :class="$vuetify.theme.dark ? 'grey darken-3' : 'grey lighten-4'">
                    {{ editMode ? 'Editar' : 'Nueva' }} {{ activityLabel }}
                    <v-spacer></v-spacer>
                    <v-btn icon @click="close"><v-icon>mdi-close</v-icon></v-btn>
                </v-card-title>
                <v-card-text class="pa-6">
                    <v-form ref="form" v-model="valid">
                        <v-text-field
                            v-model="formData.name"
                            label="Nombre de la actividad"
                            outlined
                            dense
                            required
                            :rules="[v => !!v || 'El nombre es obligatorio']"
                        ></v-text-field>

                        <v-textarea
                            v-model="formData.intro"
                            label="Descripción / Instrucciones"
                            outlined
                            rows="3"
                        ></v-textarea>

                        <v-row v-if="activityType === 'assignment'">
                            <v-col cols="12">
                                <v-text-field
                                    v-model="formData.duedate"
                                    label="Fecha de entrega"
                                    type="datetime-local"
                                    outlined
                                    dense
                                ></v-text-field>
                            </v-col>
                        </v-row>

                        <v-row v-if="activityType === 'quiz'">
                            <v-col cols="12" sm="6">
                                <v-text-field
                                    v-model="formData.timeopen"
                                    label="Abrir cuestionario"
                                    type="datetime-local"
                                    outlined
                                    dense
                                ></v-text-field>
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-text-field
                                    v-model="formData.timeclose"
                                    label="Cerrar cuestionario"
                                    type="datetime-local"
                                    outlined
                                    dense
                                ></v-text-field>
                            </v-col>
                        </v-row>

                        <v-row v-if="activityType === 'assignment'">
                             <v-col cols="12">
                                <v-select
                                    v-model="formData.gradecat"
                                    :items="gradeCategories"
                                    item-text="fullname"
                                    item-value="id"
                                    label="Categoría de Calificación (Rubro)"
                                    outlined
                                    dense
                                    clearable
                                    placeholder="Seleccione el rubro al que pertenece esta nota"
                                ></v-select>
                             </v-col>
                        </v-row>

                        <!-- Tags Input -->
                        <v-combobox
                            v-model="formData.tags"
                            label="Etiquetas / Agrupación"
                            multiple
                            chips
                            small-chips
                            deletable-chips
                            outlined
                            dense
                            hint="Escriba y presione enter para crear una nueva etiqueta"
                            persistent-hint
                        ></v-combobox>

                        <div v-if="activityType === 'bbb'" class="pa-4 rounded-lg mb-4" :class="$vuetify.theme.dark ? 'blue-grey darken-4' : 'blue lighten-5'">
                            <v-icon small color="blue" class="mr-2">mdi-information-outline</v-icon>
                            <span class="text-caption blue--text" :class="$vuetify.theme.dark ? 'text--lighten-2' : ''">
                                Se configurará automáticamente con los parámetros de este grupo y horario.
                            </span>
                        </div>

                        <div v-if="activityType === 'forum'" class="pa-4 rounded-lg mb-4" :class="$vuetify.theme.dark ? 'deep-purple darken-4' : 'deep-purple lighten-5'">
                            <v-icon small color="deep-purple" class="mr-2">mdi-forum-outline</v-icon>
                            <span class="text-caption deep-purple--text" :class="$vuetify.theme.dark ? 'text--lighten-2' : ''">
                                Se creará un foro de uso general donde todos pueden iniciar discusiones.
                            </span>
                        </div>

                        <!-- Template Option -->
                        <v-checkbox
                            v-if="!editMode"
                            v-model="saveAsTemplate"
                            label="Guardar como plantilla para futuros cursos"
                            hide-details
                            class="mt-0"
                        ></v-checkbox>
                        
                        <v-switch
                            v-if="editMode"
                            v-model="formData.visible"
                            label="Visible para estudiantes"
                            color="success"
                        ></v-switch>
                    </v-form>
                </v-card-text>
                <v-card-actions class="pa-4 pt-0">
                    <v-spacer></v-spacer>
                    <v-btn text @click="close">Cancelar</v-btn>
                    <v-btn color="primary" depressed :loading="saving" @click="saveActivity" :disabled="!valid">
                        {{ editMode ? 'Guardar Cambios' : 'Crear Actividad' }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    `,
    data() {
        return {
            visible: true,
            visible: true,
            valid: false,
            saving: false,
            saveAsTemplate: false,
            formData: {
                name: '',
                intro: '',
                duedate: '',
                timeopen: '',
                timeclose: '',
                attempts: 1,
                gradecat: null,
                tags: [],
                visible: true,
                guest: false
            },
            gradeCategories: []
        };
    },
    mounted() {
        if (this.editMode && this.editData) {
            this.fetchActivityDetails(this.editData.id);
        }
        if (this.activityType === 'assignment') {
            this.fetchGradeCategories();
        }
    },
    computed: {
        activityLabel() {
            if (this.customLabel) return this.customLabel;
            const labels = {
                bbb: 'Sesión Virtual',
                assignment: 'Tarea',
                resource: 'Material',
                quiz: 'Cuestionario',
                forum: 'Foro'
            };
            return labels[this.activityType] || 'Actividad';
        }
    },
    methods: {
        close() {
            this.$emit('close');
        },
        async saveActivity() {
            this.saving = true;
            try {
                const action = this.editMode
                    ? 'local_grupomakro_update_activity'
                    : 'local_grupomakro_create_express_activity';

                const args = this.editMode ? {
                    cmid: this.editData.id,
                    name: this.formData.name,
                    intro: this.formData.intro,
                    tags: this.formData.tags,
                    visible: this.formData.visible,
                    duedate: this.formData.duedate ? Math.floor(new Date(this.formData.duedate).getTime() / 1000) : 0,
                    timeopen: this.formData.timeopen ? Math.floor(new Date(this.formData.timeopen).getTime() / 1000) : 0,
                    timeclose: this.formData.timeclose ? Math.floor(new Date(this.formData.timeclose).getTime() / 1000) : 0,
                    attempts: this.formData.attempts
                } : {
                    classid: this.classId,
                    type: this.activityType,
                    name: this.formData.name,
                    intro: this.formData.intro,
                    duedate: this.formData.duedate ? Math.floor(new Date(this.formData.duedate).getTime() / 1000) : 0,
                    timeopen: this.formData.timeopen ? Math.floor(new Date(this.formData.timeopen).getTime() / 1000) : 0,
                    timeclose: this.formData.timeclose ? Math.floor(new Date(this.formData.timeclose).getTime() / 1000) : 0,
                    save_as_template: this.saveAsTemplate,
                    gradecat: this.formData.gradecat,
                    tags: this.formData.tags,
                    guest: this.formData.guest
                };

                const response = await axios.post(window.wsUrl, {
                    action: action,
                    args: args,
                    ...window.wsStaticParams
                });

                if (response.data.status === 'success') {
                    if (window.M && window.M.util && !this.editMode) {
                        window.M.util.js_pending('assignment_created');
                        // No logic to reload if in simple edit, but maybe refresh list?
                    }
                    this.$emit('success');
                    this.close();
                } else {
                    alert('Error saving activity: ' + response.data.message);
                }
            } catch (error) {
                console.error('Error saving activity:', error);
                alert('Error de red al guardar actividad');
            } finally {
                this.saving = false;
            }
        },
        async fetchActivityDetails(cmid) {
            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_get_activity_details',
                    args: { cmid: cmid },
                    ...window.wsStaticParams
                });
                if (response.data.status === 'success') {
                    const act = response.data.activity;
                    this.formData.name = act.name;
                    this.formData.intro = act.intro;
                    this.formData.tags = act.tags;
                    this.formData.visible = act.visible;

                    if (act.duedate) {
                        this.formData.duedate = new Date(act.duedate * 1000).toISOString().slice(0, 16);
                    }
                    if (act.timeopen) {
                        this.formData.timeopen = new Date(act.timeopen * 1000).toISOString().slice(0, 16);
                    }
                    if (act.timeclose) {
                        this.formData.timeclose = new Date(act.timeclose * 1000).toISOString().slice(0, 16);
                    }
                    this.formData.attempts = act.attempts || 1;
                }
            } catch (e) {
                console.error("Error loading details", e);
            }
        },
        async fetchGradeCategories() {
            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_get_course_grade_categories',
                    args: { classid: this.classId },
                    ...window.wsStaticParams
                });
                if (response.data.status === 'success') {
                    this.gradeCategories = response.data.categories;
                }
            } catch (error) {
                console.error('Error fetching categories:', error);
            }
        }
    }
};

Vue.component('activity-creation-wizard', ActivityCreationWizard);
window.ActivityCreationWizard = ActivityCreationWizard;
