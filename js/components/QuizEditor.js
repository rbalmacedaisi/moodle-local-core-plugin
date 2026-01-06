const QuizEditor = {
    template: `
        <v-card>
            <v-card-title>
                Gestor de Preguntas
                <v-spacer></v-spacer>
            </v-card-title>
            <v-card-text>
                <v-alert type="info" text>
                    Esta es una versión simplificada del editor de cuestionarios.
                </v-alert>
                
                <v-row>
                    <v-col cols="12" md="8">
                        <h3>Preguntas del Cuestionario</h3>
                        <v-skeleton-loader v-if="loading" type="list-item@3"></v-skeleton-loader>
                        <div v-else-if="questions.length === 0" class="text-center py-5 grey--text">
                            No hay preguntas en este cuestionario.
                        </div>
                        <v-list v-else>
                            <draggable v-model="questions" @end="updateOrder">
                                <v-list-item v-for="(q, index) in questions" :key="q.id">
                                    <v-list-item-avatar color="primary" class="white--text">
                                        {{ index + 1 }}
                                    </v-list-item-avatar>
                                    <v-list-item-content>
                                        <v-list-item-title>{{ q.name }}</v-list-item-title>
                                        <v-list-item-subtitle>{{ q.questiontext }} ({{ q.qtype }})</v-list-item-subtitle>
                                    </v-list-item-content>
                                    <v-list-item-action>
                                        <v-btn icon color="red" @click="removeQuestion(q)"><v-icon>mdi-delete</v-icon></v-btn>
                                    </v-list-item-action>
                                </v-list-item>
                            </draggable>
                        </v-list>
                    </v-col>
                    
                    <v-col cols="12" md="4">
                        <v-card outlined>
                            <v-card-title>Agregar Pregunta</v-card-title>
                            <v-card-text>
                                <v-btn block color="primary" class="mb-2" @click="showAddQuestionDialog = true">
                                    <v-icon left>mdi-plus</v-icon> Crear Nueva Pregunta
                                </v-btn>
                                 <v-btn block text color="secondary" class="mb-2" href="#" disabled>
                                    <v-icon left>mdi-bank</v-icon> Banco de Preguntas (Pronto)
                                </v-btn>
                            </v-card-text>
                        </v-card>
                    </v-col>
                </v-row>
            </v-card-text>

            <!-- Add Question Dialog -->
            <v-dialog v-model="showAddQuestionDialog" max-width="600px">
                <v-card>
                    <v-card-title>Crear Nueva Pregunta</v-card-title>
                    <v-card-text>
                        <v-select label="Tipo de Pregunta" :items="questionTypes" v-model="newQuestion.type"></v-select>
                        <v-text-field label="Nombre de la Pregunta" v-model="newQuestion.name"></v-text-field>
                        <v-textarea label="Enunciado de la Pregunta" v-model="newQuestion.text"></v-textarea>
                        
                        <div v-if="newQuestion.type === 'truefalse'">
                           <v-radio-group v-model="newQuestion.correctAnswer" label="Respuesta Correcta">
                                <v-radio label="Verdadero" value="1"></v-radio>
                                <v-radio label="Falso" value="0"></v-radio>
                           </v-radio-group>
                        </div>
                        <!-- Add other types logic here -->
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn text @click="showAddQuestionDialog = false">Cancelar</v-btn>
                        <v-btn color="primary" @click="saveQuestion" :loading="saving">Guardar</v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>
        </v-card>
    `,
    props: ['config'],
    data: () => ({
        loading: false,
        saving: false,
        questions: [],
        showAddQuestionDialog: false,
        questionTypes: [
            { text: 'Verdadero/Falso', value: 'truefalse' },
            { text: 'Opción Múltiple', value: 'multichoice' },
            { text: 'Respuesta Corta', value: 'shortanswer' },
            { text: 'Ensayo', value: 'essay' }
        ],
        newQuestion: {
            type: 'truefalse',
            name: '',
            text: '',
            correctAnswer: '1'
        }
    }),
    mounted() {
        this.fetchQuestions();
    },
    methods: {
        async fetchQuestions() {
            this.loading = true;
            try {
                // Use Moodle wwwroot passed in config
                const params = new URLSearchParams();
                params.append('action', 'local_grupomakro_get_quiz_questions');
                params.append('cmid', this.config.cmid);
                // Moodle requires sesskey usually for ajax, pass it if needed, though ajax.php seems open-ish or uses require_login
                if (this.config.sesskey) {
                    params.append('sesskey', this.config.sesskey);
                }

                const response = await axios.post(this.config.wwwroot + '/local/grupomakro_core/ajax.php', params);

                if (response.data && response.data.status === 'success') {
                    this.questions = response.data.questions;
                } else {
                    console.error('Error fetching questions:', response.data);
                }
            } catch (error) {
                console.error(error);
            } finally {
                this.loading = false;
            }
        },
        async saveQuestion() {
            this.saving = true;
            try {
                // Logic to save
                // await axios.post(...)
                alert('Funcionalidad de guardar en desarrollo');
                this.showAddQuestionDialog = false;
            } catch (error) {
                console.error(error);
            } finally {
                this.saving = false;
            }
        },
        removeQuestion(q) {
            confirm('¿Eliminar pregunta del cuestionario? (No se borra del banco)');
        },
        updateOrder() {
            // Reorder logic
        }
    }
};

if (typeof window !== 'undefined') {
    window.QuizEditorApp = {
        init: function (config) {
            new Vue({
                el: '#quiz-editor-app',
                vuetify: new Vuetify(),
                data: {
                    initialConfig: config
                },
                components: {
                    'quiz-editor': QuizEditor
                }
            });
        }
    };
}
