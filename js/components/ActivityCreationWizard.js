/**
 * Activity Creation Wizard Component
 * Created for Redesigning Teacher Experience
 */

const ActivityCreationWizard = {
    props: {
        classId: { type: Number, required: true },
        activityType: { type: String, required: true } // 'bbb', 'assignment', 'resource'
    },
    template: `
        <v-dialog v-model="visible" max-width="600px" persistent>
            <v-card class="rounded-lg">
                <v-card-title class="headline font-weight-bold grey lighten-4">
                    Nueva {{ activityLabel }}
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
                            <v-col cols="12" sm="6">
                                <v-text-field
                                    v-model="formData.duedate"
                                    label="Fecha de entrega"
                                    type="datetime-local"
                                    outlined
                                    dense
                                ></v-text-field>
                            </v-col>
                            <v-col cols="12" sm="6">
                                <v-file-input
                                    label="Archivos adjuntos"
                                    outlined
                                    dense
                                    multiple
                                    prepend-icon=""
                                    append-icon="mdi-paperclip"
                                ></v-file-input>
                            </v-col>
                        </v-row>

                        <div v-if="activityType === 'bbb'" class="blue lighten-5 pa-4 rounded-lg mb-4">
                            <v-icon small color="blue" class="mr-2">mdi-information-outline</v-icon>
                            <span class="text-caption blue--text">
                                Se configurará automáticamente con los parámetros de este grupo y horario.
                            </span>
                        </div>

                        <!-- Template Option -->
                        <v-checkbox
                            v-model="saveAsTemplate"
                            label="Guardar como plantilla para futuros cursos"
                            hide-details
                            class="mt-0"
                        ></v-checkbox>
                    </v-form>
                </v-card-text>
                <v-card-actions class="pa-4 pt-0">
                    <v-spacer></v-spacer>
                    <v-btn text @click="close">Cancelar</v-btn>
                    <v-btn color="primary" depressed :loading="saving" @click="saveActivity" :disabled="!valid">
                        Crear Actividad
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    `,
    data() {
        return {
            visible: true,
            valid: false,
            saving: false,
            saveAsTemplate: false,
            formData: {
                name: '',
                intro: '',
                duedate: ''
            }
        };
    },
    computed: {
        activityLabel() {
            const labels = { bbb: 'Sesión Virtual', assignment: 'Tarea', resource: 'Material' };
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
                // Mock logic for calling the backend wrapper
                // Real implementation will call local_grupomakro_create_express_activity
                console.log('Saving activity:', this.formData, 'Type:', this.activityType);

                // Simulate delay
                await new Promise(r => setTimeout(r, 1000));

                this.$emit('success');
                this.close();
            } catch (error) {
                console.error('Error saving activity:', error);
            } finally {
                this.saving = false;
            }
        }
    }
};

window.ActivityCreationWizard = ActivityCreationWizard;
