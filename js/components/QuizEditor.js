const QuizEditor = {
    template: `
        <v-app style="background: transparent; min-height: auto;">
            <!-- Main Content Area -->
            <v-main class="pa-0" style="background: transparent;">
                <v-container class="pa-4 mx-auto" style="max-width: 1200px;">
                    
                    <!-- Editor Card -->
                    <v-card class="rounded-lg elevation-1 mb-4">
                        <v-toolbar flat color="white" class="border-bottom px-4">
                            <v-btn icon class="mr-2" @click="$emit('back')">
                                <v-icon>mdi-arrow-left</v-icon>
                            </v-btn>
                            <v-icon color="primary" class="mr-3" large>mdi-file-document-edit-outline</v-icon>
                            <div>
                                <v-toolbar-title class="subtitle-1 font-weight-bold text-uppercase grey--text text--darken-2">
                                    Gestor de Preguntas
                                </v-toolbar-title>
                                <div class="caption grey--text">Administre las preguntas de este cuestionario</div>
                            </div>
                            <v-spacer></v-spacer>
                            
                             <v-btn small text color="primary" class="mr-2" @click="showBankDialog = true">
                                <v-icon left small>mdi-bank</v-icon> Banco
                            </v-btn>

                            <v-btn color="primary" depressed @click="resetNewQuestion(); showAddQuestionDialog = true">
                                <v-icon left>mdi-plus</v-icon> Nueva Pregunta
                            </v-btn>
                        </v-toolbar>

                        <v-card-text class="pa-0">
                            <v-skeleton-loader v-if="loading" type="list-item@3" class="pa-4"></v-skeleton-loader>
                            
                            <div v-else-if="questions.length === 0" class="text-center py-10 grey--text">
                                <v-icon size="64" color="grey lighten-3">mdi-clipboard-text-outline</v-icon>
                                <div class="mt-2 body-1">No hay preguntas en este cuestionario.</div>
                                <v-btn text color="primary" class="mt-2 font-weight-bold" @click="resetNewQuestion(); showAddQuestionDialog = true">
                                    Comenzar ahora
                                </v-btn>
                            </div>
                            
                            <v-list v-else two-line class="pa-0">
                                <draggable v-model="questions" @end="updateOrder">
                                    <v-list-item v-for="(q, index) in questions" :key="q.id" class="px-4 py-2 border-bottom hover-bg">
                                        <v-list-item-avatar color="blue lighten-5" class="blue--text font-weight-bold rounded-lg" size="40">
                                            {{ index + 1 }}
                                        </v-list-item-avatar>
                                        
                                        <v-list-item-content>
                                            <v-list-item-title class="font-weight-bold text-subtitle-1 mb-1">{{ q.name }}</v-list-item-title>
                                            <v-list-item-subtitle class="grey--text text--darken-1">
                                                <v-chip x-small label color="blue lighten-5" text-color="blue" class="mr-2 font-weight-bold">{{ questionTypeLabel(q.qtype) }}</v-chip>
                                                <v-chip v-if="q.maxmark !== undefined" x-small outlined color="grey" class="mr-2">{{ q.maxmark }} pts</v-chip>
                                                <span v-html="q.questiontext" class="text-truncate d-inline-block" style="max-width: 500px; vertical-align: middle;"></span>
                                            </v-list-item-subtitle>
                                        </v-list-item-content>
                                        
                                        <v-list-item-action class="flex-row">
                                            <v-tooltip bottom>
                                                <template v-slot:activator="{ on, attrs }">
                                                    <v-btn icon color="primary" class="mr-1" v-bind="attrs" v-on="on" @click="loadQuestionDetails(q.id)">
                                                        <v-icon>mdi-pencil</v-icon>
                                                    </v-btn>
                                                </template>
                                                <span>Editar Pregunta</span>
                                            </v-tooltip>
                                            <v-tooltip bottom>
                                                <template v-slot:activator="{ on, attrs }">
                                                    <v-btn icon color="red lighten-2" v-bind="attrs" v-on="on" @click="removeQuestion(q)">
                                                        <v-icon>mdi-delete</v-icon>
                                                    </v-btn>
                                                </template>
                                                <span>Eliminar</span>
                                            </v-tooltip>
                                        </v-list-item-action>
                                    </v-list-item>
                                </draggable>
                            </v-list>
                        </v-card-text>
                    </v-card>
                </v-container>
            </v-main>
            
            <!-- Add Question Dialog -->
            <v-dialog v-model="showAddQuestionDialog" max-width="900px" persistent>
                <v-card class="rounded-xl overflow-hidden">
                    <v-toolbar flat dark color="primary">
                        <v-icon left>{{ newQuestion.id ? 'mdi-pencil' : 'mdi-plus-circle' }}</v-icon>
                        <v-toolbar-title class="font-weight-bold">{{ newQuestion.id ? 'Editar Pregunta' : 'Configurar Nueva Pregunta' }}</v-toolbar-title>
                        <v-spacer></v-spacer>
                        <v-btn icon @click="showAddQuestionDialog = false"><v-icon>mdi-close</v-icon></v-btn>
                    </v-toolbar>

                    <v-card-text class="pa-6">
                        <v-row>
                            <v-col cols="12" md="8">
                                <v-text-field label="Nombre identificador" v-model="newQuestion.name" outlined dense placeholder="Ej: Pregunta sobre fotosíntesis" prepend-inner-icon="mdi-tag-outline"></v-text-field>
                            </v-col>
                            <v-col cols="12" md="4">
                                <v-select label="Tipo de Pregunta" :items="questionTypes" v-model="newQuestion.type" outlined dense prepend-inner-icon="mdi-format-list-bulleted-type"></v-select>
                            </v-col>
                            <v-col cols="12" class="pt-0">
                                <v-switch 
                                    v-model="newQuestion.save_to_course" 
                                    label="Guardar en el Banco de Preguntas de la Asignatura" 
                                    color="success"
                                    hide-details
                                    dense
                                    class="mt-minus-2"
                                ></v-switch>
                                <div class="caption grey--text ml-8 mt-n1">Las preguntas guardadas aquí podrán ser reutilizadas en el futuro desde la sección "BANCO".</div>
                            </v-col>
                        </v-row>
                        
                        <!-- Common Question Text (Enunciado) -->
                        <div v-if="newQuestion.type !== 'multianswer' && newQuestion.type !== 'gapselect' && newQuestion.type !== 'ddwtos'">
                            <div class="caption grey--text font-weight-bold mb-2">ENUNCIADO DE LA PREGUNTA</div>
                            <v-textarea 
                                v-model="newQuestion.questiontext" 
                                outlined 
                                rows="3" 
                                placeholder="Escribe aquí la pregunta que verá el estudiante..."
                                class="rounded-lg"
                            ></v-textarea>
                        </div>
                        
                        <v-row class="mb-4">
                            <v-col cols="12" md="4">
                                <v-text-field label="Puntuación" v-model="newQuestion.defaultmark" type="number" outlined dense prepend-inner-icon="mdi-star-outline"></v-text-field>
                            </v-col>
                        </v-row>

                        <v-divider class="mb-6"></v-divider>

                        <!-- True/False Specific -->
                        <div v-if="newQuestion.type === 'truefalse'">
                            <v-alert colored-border border="left" color="primary" class="mb-6 elevation-1" text>
                                <div class="d-flex align-center">
                                    <v-icon color="primary" class="mr-3">mdi-check-circle-outline</v-icon>
                                    <span class="text-body-2">Pregunta de respuesta binaria. Seleccione cuál es la opción correcta.</span>
                                </div>
                            </v-alert>

                            <div class="subtitle-2 mb-3 grey--text text-uppercase font-weight-bold">Respuesta Correcta</div>
                            <v-btn-toggle
                                v-model="newQuestion.correctAnswer"
                                mandatory
                                color="primary"
                                class="d-flex mb-6"
                            >
                                <v-btn value="1" x-large class="flex-grow-1 py-8 rounded-l-lg" outlined>
                                    <v-icon left>mdi-check-bold</v-icon> VERDADERO
                                </v-btn>
                                <v-btn value="0" x-large class="flex-grow-1 py-8 rounded-r-lg" outlined>
                                    <v-icon left>mdi-close-thick</v-icon> FALSO
                                </v-btn>
                            </v-btn-toggle>
                        </div>
                        
                        <!-- Essay / Description -->
                        <div v-else-if="newQuestion.type === 'essay' || newQuestion.type === 'description'">
                            <v-alert colored-border border="left" :color="newQuestion.type === 'essay' ? 'amber' : 'blue'" class="mb-6 elevation-1" text>
                                <div class="d-flex align-center">
                                    <v-icon :color="newQuestion.type === 'essay' ? 'amber' : 'blue'" class="mr-3">
                                        {{ newQuestion.type === 'essay' ? 'mdi-file-document-edit-outline' : 'mdi-information-outline' }}
                                    </v-icon>
                                    <span class="text-body-2">
                                        {{ newQuestion.type === 'essay' ? 'El estudiante deberá redactar una respuesta libre extensa. Este tipo de pregunta requiere calificación manual por parte del profesor.' : 'Este elemento no requiere respuesta; se utiliza para proporcionar información, textos largos o instrucciones adicionales entre preguntas.' }}
                                    </span>
                                </div>
                            </v-alert>
                            
                            <v-card v-if="newQuestion.type === 'essay'" outlined class="pa-4 rounded-xl grey lighten-5">
                                <v-row>
                                    <v-col cols="12" md="6">
                                        <v-select
                                            label="Formato de respuesta"
                                            v-model="newQuestion.responseformat"
                                            :items="[{text: 'Editor HTML', value: 'editor'}, {text: 'Texto plano', value: 'plain'}]"
                                            outlined dense hide-details
                                        ></v-select>
                                    </v-col>
                                    <v-col cols="12" md="6">
                                        <v-select
                                            label="Tamaño del área de texto"
                                            v-model="newQuestion.responserequired"
                                            :items="[{text: '15 líneas', value: 15}, {text: '30 líneas', value: 30}, {text: '45 líneas', value: 45}]"
                                            outlined dense hide-details
                                        ></v-select>
                                    </v-col>
                                </v-row>
                            </v-card>
                        </div>

                        <!-- Match Specific -->
                        <div v-else-if="newQuestion.type === 'match'">
                            <v-alert colored-border border="left" color="primary" class="mb-6 elevation-1" text>
                                <div class="d-flex align-center">
                                    <v-icon color="primary" class="mr-3">mdi-lightbulb-on-outline</v-icon>
                                    <span class="text-body-2">Define parejas de conceptos. El estudiante deberá asociar cada pregunta con su respuesta correcta.</span>
                                </div>
                            </v-alert>

                            <v-row v-for="(subq, i) in newQuestion.subquestions" :key="i" class="mb-4 align-center">
                                <v-col cols="5">
                                    <v-text-field 
                                        label="Pregunta / Concepto" 
                                        v-model="subq.text" 
                                        outlined 
                                        dense 
                                        hide-details
                                        background-color="grey lighten-5"
                                        class="rounded-lg shadow-sm"
                                    ></v-text-field>
                                </v-col>
                                <v-col cols="2" class="text-center">
                                    <v-icon color="grey lighten-1">mdi-swap-horizontal</v-icon>
                                </v-col>
                                <v-col cols="5" class="d-flex align-center">
                                    <v-text-field 
                                        label="Respuesta" 
                                        v-model="subq.answer" 
                                        outlined 
                                        dense 
                                        hide-details
                                        background-color="grey lighten-5"
                                        class="rounded-lg shadow-sm mr-2"
                                    ></v-text-field>
                                    <v-btn icon color="red lighten-1" @click="removeSubQuestion(i)" v-if="newQuestion.subquestions.length > 2">
                                        <v-icon small>mdi-delete-outline</v-icon>
                                    </v-btn>
                                </v-col>
                            </v-row>

                            <v-btn block color="primary lighten-5" class="primary--text py-6 rounded-lg border-dashed" depressed @click="addSubQuestion">
                                <v-icon left>mdi-plus-circle-outline</v-icon>
                                Añadir nueva pareja
                            </v-btn>

                            <v-divider class="my-6"></v-divider>
                            <v-row>
                                <v-col cols="12" class="py-0">
                                    <v-checkbox 
                                        v-model="newQuestion.shuffleanswers" 
                                        label="Barajar respuestas (Recomendado)" 
                                        dense 
                                        color="primary"
                                        hint="Las respuestas se presentarán en orden aleatorio para cada estudiante"
                                        persistent-hint
                                    ></v-checkbox>
                                </v-col>
                            </v-row>
                        </div>






                        <!-- Fallback for complex types -->
                        <!-- Multiple Choice UI -->
                        <div v-else-if="newQuestion.type === 'multichoice'">
                            <v-row class="mb-4">
                                <v-col cols="12" md="6">
                                    <v-card outlined class="pa-4 rounded-lg h-100">
                                        <div class="caption grey--text font-weight-bold mb-2">MODO DE RESPUESTA</div>
                                        <v-radio-group v-model="newQuestion.single" hide-details class="mt-0">
                                            <v-radio :value="true" label="Solo una respuesta correcta"></v-radio>
                                            <v-radio :value="false" label="Se permiten varias respuestas"></v-radio>
                                        </v-radio-group>
                                    </v-card>
                                </v-col>
                                <v-col cols="12" md="6">
                                    <v-card outlined class="pa-4 rounded-lg h-100 d-flex align-center">
                                        <v-switch 
                                            label="Barajar opciones al azar" 
                                            v-model="newQuestion.shuffleanswers" 
                                            inset
                                            hide-details
                                            color="primary"
                                        ></v-switch>
                                    </v-card>
                                </v-col>
                            </v-row>

                            <div class="d-flex align-center justify-space-between mb-4">
                                <div class="subtitle-2 grey--text text-uppercase font-weight-bold">Opciones de Respuesta</div>
                                <v-btn small depressed color="primary lighten-5" class="primary--text" @click="addAnswerChoice">
                                    <v-icon left small>mdi-plus-circle</v-icon> Nueva Opción
                                </v-btn>
                            </div>
                            
                            <v-card v-for="(answer, i) in newQuestion.answers" :key="i" flat class="mb-4 border rounded-xl overflow-hidden shadow-sm">
                                <v-row no-gutters>
                                    <v-col cols="1" class="d-flex align-center justify-center border-right" :class="answer.fraction > 0 ? 'success lighten-5' : 'grey lighten-5'">
                                        <v-icon :color="answer.fraction > 0 ? 'success' : 'grey lighten-1'">
                                            {{ answer.fraction > 0 ? 'mdi-check-circle' : 'mdi-circle-outline' }}
                                        </v-icon>
                                    </v-col>
                                    <v-col cols="11" class="pa-4">
                                        <v-row dense>
                                            <v-col cols="12" md="8">
                                                <v-text-field 
                                                    v-model="answer.text" 
                                                    placeholder="Escriba el contenido de la opción..." 
                                                    outlined dense
                                                    hide-details="auto"
                                                    label="Enunciado de la opción"
                                                ></v-text-field>
                                            </v-col>
                                            <v-col cols="12" md="3">
                                                <v-select 
                                                    label="Calificación / Peso" 
                                                    v-model="answer.fraction" 
                                                    :items="gradeOptions" 
                                                    outlined dense
                                                    hide-details
                                                    class="rounded-lg"
                                                ></v-select>
                                            </v-col>
                                            <v-col cols="12" md="1" class="text-right">
                                                <v-btn icon color="red lighten-3" @click="newQuestion.answers.splice(i, 1)" :disabled="newQuestion.answers.length <= 2">
                                                    <v-icon>mdi-delete-outline</v-icon>
                                                </v-btn>
                                            </v-col>
                                            <v-col cols="12" class="mt-2">
                                                <v-text-field 
                                                    label="Retroalimentación específica" 
                                                    v-model="answer.feedback" 
                                                    dense rounded filled 
                                                    hide-details
                                                    placeholder="Comentario que verá el estudiante al elegir esta opción"
                                                    prepend-inner-icon="mdi-comment-outline"
                                                ></v-text-field>
                                            </v-col>
                                        </v-row>
                                    </v-col>
                                </v-row>
                            </v-card>
                        </div>
                        
                        <!-- Short Answer UI -->
                        <div v-else-if="newQuestion.type === 'shortanswer'">
                            <v-alert colored-border border="left" color="primary" class="mb-4 elevation-1" text>
                                <div class="d-flex align-center">
                                    <v-icon color="primary" class="mr-3">mdi-text-short-title</v-icon>
                                    <span class="text-body-2">El estudiante escribe una palabra o frase corta. Puedes definir múltiples respuestas aceptables.</span>
                                </div>
                            </v-alert>

                            <v-card outlined class="pa-4 mb-6 rounded-xl grey lighten-5">
                                <v-select
                                    label="Sensibilidad a Mayúsculas"
                                    v-model="newQuestion.usecase"
                                    :items="[{text: 'No, es igual (a = A)', value: 0}, {text: 'Sí, debe coincidor (A != a)', value: 1}]"
                                    outlined dense hide-details
                                    prepend-inner-icon="mdi-format-letter-case"
                                ></v-select>
                            </v-card>

                            <div class="d-flex align-center justify-space-between mb-4">
                                <div class="subtitle-2 grey--text text-uppercase font-weight-bold">Respuestas Válidas</div>
                                <v-btn small depressed color="primary lighten-5" class="primary--text" @click="newQuestion.answers.push({text: '', fraction: 1.0, feedback: ''})">
                                    <v-icon left small>mdi-plus-circle</v-icon> Añadir Variante
                                </v-btn>
                            </div>
                            
                            <v-card v-for="(answer, i) in newQuestion.answers" :key="i" flat class="mb-3 border rounded-xl overflow-hidden shadow-sm">
                                <v-row no-gutters>
                                    <v-col cols="1" class="d-flex align-center justify-center border-right" :class="answer.fraction == 1 ? 'success lighten-5' : 'grey lighten-5'">
                                        <v-icon :color="answer.fraction == 1 ? 'success' : 'grey lighten-1'">
                                            {{ answer.fraction == 1 ? 'mdi-check-decagram' : 'mdi-check-circle-outline' }}
                                        </v-icon>
                                    </v-col>
                                    <v-col cols="11" class="pa-4">
                                        <v-row dense align="center">
                                            <v-col cols="12" md="8">
                                                <v-text-field 
                                                    v-model="answer.text" 
                                                    label="Respuesta esperada" 
                                                    outlined dense hide-details
                                                    placeholder="Ej: La fotosíntesis"
                                                ></v-text-field>
                                            </v-col>
                                            <v-col cols="10" md="3">
                                                <v-select 
                                                    label="Calificación" 
                                                    v-model="answer.fraction" 
                                                    :items="gradeOptions" 
                                                    outlined dense hide-details
                                                ></v-select>
                                            </v-col>
                                            <v-col cols="2" md="1" class="text-right">
                                                <v-btn icon color="red lighten-3" @click="newQuestion.answers.splice(i, 1)" :disabled="newQuestion.answers.length <= 1">
                                                    <v-icon>mdi-delete-outline</v-icon>
                                                </v-btn>
                                            </v-col>
                                        </v-row>
                                    </v-col>
                                </v-row>
                            </v-card>
                        </div>
                        <!-- Numerical Specific -->
                        <div v-else-if="newQuestion.type === 'numerical'">
                            <v-alert colored-border border="left" color="primary" class="mb-6 elevation-1" text>
                                <div class="d-flex align-center">
                                    <v-icon color="primary" class="mr-3">mdi-numeric</v-icon>
                                    <span class="text-body-2">Respuestas numéricas exactas con margen de tolerancia opcional.</span>
                                </div>
                            </v-alert>

                            <div class="d-flex align-center justify-space-between mb-4">
                                <div class="subtitle-2 grey--text text-uppercase font-weight-bold">Respuestas Aceptadas</div>
                                <v-btn small depressed color="primary lighten-5" class="primary--text" @click="addAnswerChoice">
                                    <v-icon left small>mdi-plus-circle</v-icon> Añadir Valor
                                </v-btn>
                            </div>

                            <v-card v-for="(answer, i) in newQuestion.answers" :key="i" flat class="mb-3 border rounded-xl overflow-hidden shadow-sm">
                                <v-row no-gutters>
                                    <v-col cols="1" class="d-flex align-center justify-center border-right" :class="answer.fraction == 1 ? 'success lighten-5' : 'grey lighten-5'">
                                        <v-icon :color="answer.fraction == 1 ? 'success' : 'grey lighten-1'">
                                            {{ answer.fraction == 1 ? 'mdi-check-decagram' : 'mdi-numeric' }}
                                        </v-icon>
                                    </v-col>
                                    <v-col cols="11" class="pa-4">
                                        <v-row dense align="center">
                                            <v-col cols="12" md="4">
                                                <v-text-field label="Valor" v-model="answer.text" type="number" outlined dense hide-details></v-text-field>
                                            </v-col>
                                            <v-col cols="6" md="3">
                                                <v-text-field label="Tolerancia (±)" v-model="answer.tolerance" type="number" outlined dense hide-details></v-text-field>
                                            </v-col>
                                            <v-col cols="6" md="4">
                                                <v-select label="Calificación" v-model="answer.fraction" :items="gradeOptions" outlined dense hide-details></v-select>
                                            </v-col>
                                            <v-col cols="12" md="1" class="text-right">
                                                <v-btn icon color="red lighten-3" @click="removeAnswerChoice(i)" :disabled="newQuestion.answers.length <= 1">
                                                    <v-icon>mdi-delete-outline</v-icon>
                                                </v-btn>
                                            </v-col>
                                        </v-row>
                                    </v-col>
                                </v-row>
                            </v-card>

                            <v-card outlined class="pa-4 mt-6 rounded-xl grey lighten-5">
                                <div class="caption grey--text font-weight-bold mb-3">CONFIGURACIÓN DE UNIDADES</div>
                                <v-row dense>
                                    <v-col cols="6">
                                        <v-text-field label="Unidad (ej: metros, kg)" v-model="newQuestion.unit" outlined dense hide-details background-color="white"></v-text-field>
                                    </v-col>
                                    <v-col cols="6">
                                        <v-select label="Penalización" v-model="newQuestion.unitpenalty" :items="[0, 0.1, 0.2, 0.33, 0.5]" outlined dense hide-details background-color="white"></v-select>
                                    </v-col>
                                </v-row>
                            </v-card>
                        </div>

                        <!-- Calculated Types (Visual Formula Builder) -->
                        <div v-else-if="newQuestion.type && newQuestion.type.startsWith('calculated')">
                            <v-alert colored-border border="left" color="blue" class="mb-4 elevation-1" text v-if="newQuestion.answers && newQuestion.answers.length > 0">
                                <div class="d-flex align-center">
                                    <v-icon color="blue" class="mr-3">mdi-auto-fix</v-icon>
                                    <div class="d-flex flex-column">
                                        <span class="text-body-2"><strong>Constructor Visual:</strong> Crea fórmulas complejas haciendo clic en operadores y variables.</span>
                                        <div class="mt-1">
                                            <v-btn x-small color="indigo" text class="pa-0 font-weight-bold" @click="showCalculatedHelp = true">
                                                <v-icon left x-small>mdi-information-outline</v-icon> ¿Cómo funcionan las preguntas calculadas?
                                            </v-btn>
                                        </div>
                                    </div>
                                </div>
                            </v-alert>



                            <!-- Formula Display & Controls -->
                            <template v-if="newQuestion.answers && newQuestion.answers.length > 0">
                                <div v-for="(ans, ansIdx) in newQuestion.answers" :key="'formula'+ansIdx" class="mb-6">
                                    <v-card outlined class="pa-4 rounded-xl blue lighten-5 border-blue shadow-sm">
                                        <div class="caption blue--text font-weight-bold mb-2 text-uppercase d-flex justify-space-between align-center">
                                            <span>{{ questionTypeLabel(newQuestion.type) }} {{ newQuestion.type === 'calculatedmulti' ? 'Opción ' + (ansIdx + 1) : '' }}</span>
                                            <div v-if="newQuestion.type === 'calculatedmulti'">
                                                <v-btn x-small icon color="red" class="mr-2" @click="newQuestion.answers.splice(ansIdx, 1)" :disabled="newQuestion.answers.length <= 2">
                                                    <v-icon>mdi-delete</v-icon>
                                                </v-btn>
                                            </div>
                                            <v-btn x-small text color="red" @click="ans.text = ''">Limpiar</v-btn>
                                        </div>
                                        
                                        <div class="formula-preview-area pa-3 white rounded-lg border shadow-inner mb-4 min-height-60 d-flex flex-wrap align-center bg-white" 
                                             style="min-height: 80px; gap: 8px; box-shadow: inset 0 2px 4px rgba(0,0,0,0.05) !important;">
                                            <template v-if="ans.text">
                                                <v-chip v-for="(part, pi) in parseFormula(ans.text)" 
                                                        :key="pi" 
                                                        small 
                                                        :color="part.type === 'variable' ? 'blue' : (part.type === 'constant' ? 'indigo lighten-4' : 'grey lighten-2')"
                                                        :dark="part.type === 'variable'"
                                                        label
                                                        class="font-weight-bold"
                                                        :class="part.type === 'constant' ? 'indigo--text' : ''"
                                                >
                                                    {{ part.value }}
                                                </v-chip>
                                            </template>
                                            <span v-else class="grey--text subtitle-1 italic">Construye la fórmula para esta opción...</span>
                                        </div>

                                        <!-- Operators Toolbar -->
                                        <div class="d-flex flex-wrap gap-2 justify-center mb-4" style="gap: 8px;">
                                            <v-btn v-for="op in ['+', '-', '*', '/', '(', ')']" :key="op" 
                                                   small depressed color="white" class="font-weight-bold border" 
                                                   @click="addToFormula(op, ansIdx)">{{ op }}</v-btn>
                                            <v-btn small depressed color="white" class="font-weight-bold border px-4" @click="addToFormula('sqrt(', ansIdx)">√</v-btn>
                                            <v-btn small depressed color="white" class="font-weight-bold border px-4" @click="addToFormula('pow(', ansIdx)">^</v-btn>
                                            <v-btn small depressed color="white" class="font-weight-bold border px-4" @click="addToFormula(',', ansIdx)"> , </v-btn>
                                        </div>

                                        <!-- Constant Input -->
                                        <div class="d-flex align-center justify-center mb-4" style="gap: 8px;">
                                            <v-text-field
                                                v-model="formulaConstant"
                                                label="Añadir Número"
                                                type="number"
                                                outlined
                                                dense
                                                hide-details
                                                class="rounded-lg bg-white"
                                                style="max-width: 120px;"
                                                @keyup.enter="if(formulaConstant !== '') { addToFormula(formulaConstant, ansIdx); formulaConstant = ''; }"
                                            ></v-text-field>
                                            <v-btn 
                                                depressed 
                                                color="indigo" 
                                                dark 
                                                small 
                                                class="rounded-lg"
                                                @click="if(formulaConstant !== '') { addToFormula(formulaConstant, ansIdx); formulaConstant = ''; }"
                                            >
                                                <v-icon left small>mdi-plus-circle</v-icon> Insertar
                                            </v-btn>
                                        </div>

                                        <!-- Variable Shortcuts -->
                                        <div v-if="newQuestion.dataset && newQuestion.dataset.length > 0" class="d-flex flex-wrap gap-2 justify-center pt-2" style="gap: 8px; border-top: 1px dashed #bbdefb;">
                                            <v-btn v-for="ds in newQuestion.dataset" :key="ds.name" 
                                                   x-small depressed color="blue" dark class="rounded-pill px-3" 
                                                   @click="addToFormula('{' + ds.name + '}', ansIdx)">
                                                <v-icon left x-small>mdi-variable</v-icon>
                                                {{ ds.name }}</v-btn>
                                        </div>

                                        <v-row v-if="newQuestion.type === 'calculatedmulti'" class="mt-4 pt-4 border-top">
                                            <v-col cols="12" md="6">
                                                <v-select label="Calificación" v-model="ans.fraction" :items="gradeOptions" outlined dense hide-details></v-select>
                                            </v-col>
                                            <v-col cols="12" md="6">
                                                <v-text-field label="Retroalimentación" v-model="ans.feedback" outlined dense hide-details prepend-inner-icon="mdi-comment-outline"></v-text-field>
                                            </v-col>
                                        </v-row>
                                    </v-card>
                                </div>

                                <v-btn v-if="newQuestion.type === 'calculatedmulti'" block depressed color="blue lighten-5" class="blue--text mb-6 rounded-xl border-dashed" @click="addAnswerChoice">
                                    <v-icon left>mdi-plus-circle</v-icon> Añadir Opción
                                </v-btn>
                            </template>

                            <!-- Variable Manager -->
                            <v-card outlined class="pa-4 rounded-xl border-dashed">
                                <div class="subtitle-2 blue--text mb-4 d-flex align-center justify-space-between">
                                    <div class="d-flex align-center">
                                        <v-icon left color="blue" small>mdi-database-edit</v-icon>
                                        Gestor de Variables (Dataset)
                                    </div>
                                    <v-dialog v-model="showAddVariableDialog" max-width="400">
                                        <template v-slot:activator="{ on, attrs }">
                                            <v-btn v-bind="attrs" v-on="on" x-small fab color="blue" dark>
                                                <v-icon>mdi-plus</v-icon>
                                            </v-btn>
                                        </template>
                                        <v-card class="rounded-xl">
                                            <v-card-title class="blue white--text">Nueva Variable</v-card-title>
                                            <v-card-text class="pa-4">
                                                <v-text-field v-model="newVarName" label="Nombre (ej: base)" outlined dense hide-details class="mb-4" autofocus @keyup.enter="addVariable"></v-text-field>
                                                <v-row dense>
                                                    <v-col cols="6"><v-text-field v-model="newVarMin" label="Mín" type="number" outlined dense></v-text-field></v-col>
                                                    <v-col cols="6"><v-text-field v-model="newVarMax" label="Máx" type="number" outlined dense></v-text-field></v-col>
                                                </v-row>
                                            </v-card-text>
                                            <v-card-actions>
                                                <v-spacer></v-spacer>
                                                <v-btn text @click="showAddVariableDialog = false">Cancelar</v-btn>
                                                <v-btn color="blue" dark @click="addVariable">Añadir</v-btn>
                                            </v-card-actions>
                                        </v-card>
                                    </v-dialog>
                                </div>
                                
                                <v-row v-if="newQuestion.dataset && newQuestion.dataset.length > 0">
                                    <v-col v-for="(ds, i) in newQuestion.dataset" :key="'ds'+i" cols="12" md="6">
                                        <v-card outlined class="pa-3 rounded-lg border bg-white shadow-sm hover-shadow">
                                            <div class="d-flex justify-space-between align-center mb-2">
                                                <v-chip label color="blue lighten-5" text-color="blue" small class="font-weight-bold">
                                                    <v-icon left x-small>mdi-variable</v-icon>{{ ds.name }}
                                                </v-chip>
                                                <v-btn icon x-small color="red lighten-3" @click="newQuestion.dataset.splice(i,1)"><v-icon>mdi-close</v-icon></v-btn>
                                            </div>
                                            <v-row dense>
                                                <v-col cols="6">
                                                    <v-text-field label="Mín" v-model="ds.min" type="number" dense outlined hide-details class="custom-small-input"></v-text-field>
                                                </v-col>
                                                <v-col cols="6">
                                                    <v-text-field label="Máx" v-model="ds.max" type="number" dense outlined hide-details class="custom-small-input"></v-text-field>
                                                </v-col>
                                            </v-row>
                                        </v-card>
                                    </v-col>
                                </v-row>
                                <div v-else class="text-center py-6 grey--text rounded-lg border-dashed">
                                    <v-icon color="grey lighten-2" size="40">mdi-variable-off</v-icon>
                                    <div class="caption">Haz clic en + para crear una variable</div>
                                </div>
                            </v-card>

                            <!-- Units and Others -->
                            <v-row class="mt-4">
                                <v-col cols="12" md="6">
                                    <v-text-field v-model="newQuestion.unit" label="Unidad (opcional)" outlined dense placeholder="ej: m/s, kg" prepend-inner-icon="mdi-ruler"></v-text-field>
                                </v-col>
                                <v-col cols="12" md="6">
                                    <v-select label="Tolerancia" v-model="newQuestion.tolerance" :items="[0.001, 0.01, 0.05, 0.1]" outlined dense prepend-inner-icon="mdi-plus-minus"></v-select>
                                </v-col>
                            </v-row>
                            <!-- Advanced Tolerance Parameters (Inside calculated block) -->
                            <v-expansion-panels flat class="mt-6 border rounded-xl overflow-hidden">
                                <v-expansion-panel>
                                    <v-expansion-panel-header color="grey lighten-5">Parámetros Avanzados de Tolerancia</v-expansion-panel-header>
                                    <v-expansion-panel-content class="pt-4">
                                        <v-row dense v-if="newQuestion.answers && newQuestion.answers.length > 0">
                                            <v-col cols="12" md="6">
                                                <v-text-field label="Margen de error (±)" v-model="newQuestion.answers[0].tolerance" type="number" step="0.01" outlined dense></v-text-field>
                                            </v-col>
                                            <v-col cols="12" md="6">
                                                <v-select label="Tipo de Tolerancia" :items="['Relativa', 'Nominal', 'Geométrica']" outlined dense></v-select>
                                            </v-col>
                                        </v-row>
                                    </v-expansion-panel-content>
                                </v-expansion-panel>
                            </v-expansion-panels>
                        </div>

                        <!-- Multianswer (Cloze) -->
                        <div v-else-if="newQuestion.type === 'multianswer'">
                            <v-alert colored-border border="left" color="deep-purple" class="mb-4 elevation-1" text>
                                <div class="d-flex align-center">
                                    <v-icon color="deep-purple" class="mr-3">mdi-puzzle-outline</v-icon>
                                    <div class="d-flex flex-column">
                                        <span class="text-body-2"><strong>Examen de Huecos:</strong> Crea textos con preguntas integradas. Selecciona una palabra y haz clic en el botón morado.</span>
                                        <div class="mt-1">
                                            <v-btn x-small color="deep-purple" text class="pa-0 font-weight-bold" @click="showClozeHelp = true">
                                                <v-icon left x-small>mdi-information-outline</v-icon> ¿Qué es una pregunta Cloze?
                                            </v-btn>
                                        </div>
                                    </div>
                                </div>
                            </v-alert>

                            <!-- Cloze Help Modal -->
                            <v-dialog v-model="showClozeHelp" max-width="600px" scrollable>
                                <v-card class="rounded-xl">
                                    <v-toolbar flat color="deep-purple" dark>
                                        <v-icon left>mdi-puzzle</v-icon>
                                        <v-toolbar-title>Respuestas Anidadas (Cloze)</v-toolbar-title>
                                        <v-spacer></v-spacer>
                                        <v-btn icon @click="showClozeHelp = false"><v-icon>mdi-close</v-icon></v-btn>
                                    </v-toolbar>
                                    
                                    <v-card-text class="pa-6">
                                        <div class="mb-6">
                                            <div class="subtitle-2 font-weight-bold deep-purple--text mb-2 text-uppercase">1. ¿Qué es este tipo de pregunta?</div>
                                            <p class="body-2 grey--text text--darken-2">
                                                Te permite crear un párrafo o párrafo donde las preguntas están <strong>dentro del mismo texto</strong>. Es ideal para evaluar comprensión lectora o gramática.
                                            </p>
                                        </div>

                                        <v-card flat class="mb-6 pa-4 deep-purple lighten-5 rounded-lg border-purple">
                                            <div class="subtitle-2 font-weight-bold deep-purple--text mb-2">Ejemplo de Resultado Final:</div>
                                            <div class="body-2 p-2 white rounded border">
                                                Panamá es un país ubicado en <span class="blue--text px-1">[Centroamérica ▼]</span> y su moneda es el <span class="blue--text px-1">[.....]</span>.
                                            </div>
                                        </v-card>

                                        <div class="mb-6">
                                            <div class="subtitle-2 font-weight-bold deep-purple--text mb-2 text-uppercase">2. ¿Cómo usar el Asistente?</div>
                                            <ol class="body-2 grey--text text--darken-2 pl-4">
                                                <li class="mb-2">Escribe tu texto completo en el recuadro grande.</li>
                                                <li class="mb-2"><strong>Sombra con el ratón</strong> la palabra que quieres convertir en hueco.</li>
                                                <li>Haz clic en <strong>"Insertar Hueco Inteligente"</strong> para definir la respuesta correcta y las incorrectas.</li>
                                            </ol>
                                        </div>

                                        <div class="pa-4 grey lighten-4 rounded-lg">
                                            <div class="d-flex align-center mb-1">
                                                <v-icon color="deep-purple" class="mr-2" small>mdi-flash</v-icon>
                                                <span class="body-2 font-weight-bold">Sin Códigos:</span>
                                            </div>
                                            <p class="caption mb-0">
                                                Normalmente el Cloze requiere aprenderse códigos difíciles como <code>{1:MC:....}</code>. Nuestro asistente crea esos códigos por ti automáticamente.
                                            </p>
                                        </div>
                                    </v-card-text>

                                    <v-divider></v-divider>
                                    <v-card-actions class="pa-4">
                                        <v-spacer></v-spacer>
                                        <v-btn depressed color="deep-purple" dark class="rounded-lg px-6" @click="showClozeHelp = false">Entendido</v-btn>
                                    </v-card-actions>
                                </v-card>
                            </v-dialog>

                            <div class="mb-4">
                                <div class="d-flex justify-space-between align-center mb-1">
                                    <span class="caption font-weight-bold grey--text text-uppercase">CONTENIDO DEL TEXTO</span>
                                    <v-btn small color="deep-purple" dark depressed rounded @click="openClozeWizard">
                                        <v-icon left small>mdi-auto-fix</v-icon> Insertar Hueco Inteligente
                                    </v-btn>
                                </div>
                                <v-textarea
                                    v-model="newQuestion.questiontext"
                                    outlined
                                    dense
                                    rows="6"
                                    hide-details
                                    placeholder="Escribe tu texto aquí. Selecciona un texto y presiona el botón morado..."
                                    id="cloze-textarea"
                                    class="rounded-xl custom-editor shadow-sm mb-4"
                                    background-color="white"
                                ></v-textarea>

                                <!-- Cloze Word Selector -->
                                <div class="pa-4 mb-6 rounded-xl border shadow-sm" :class="$vuetify.theme.dark ? 'grey darken-4' : 'grey lighten-5'">
                                    <div class="d-flex justify-space-between align-center mb-2">
                                        <div class="d-flex align-center">
                                            <v-icon color="deep-purple" small class="mr-2">mdi-gesture-tap</v-icon>
                                            <span class="caption deep-purple--text font-weight-bold uppercase">SELECTOR VISUAL CLOZE (Haz clic en palabras)</span>
                                        </div>
                                        <v-chip x-small color="deep-purple" outlined>No-Code Assistant</v-chip>
                                    </div>
                                    <div class="text-body-1 word-selector-area">
                                        <transition-group name="list" tag="div">
                                            <template v-for="(token, idx) in tokenizedText">
                                                <span v-if="token.type === 'text'" 
                                                      :key="'w'+idx" 
                                                      class="token-word" 
                                                      @click="convertToClozeGap(idx)"
                                                      v-html="formatToken(token.value)"></span>
                                                <v-chip v-else 
                                                        :key="'g'+idx" 
                                                        small 
                                                        label 
                                                        class="mx-1 px-2 token-gap shadow-sm deep-purple white--text font-weight-bold" 
                                                        close
                                                        close-icon="mdi-close-circle"
                                                        @click:close="revertClozeToText(idx)"
                                                        @click="openClozeWizard(idx)">
                                                    <v-icon left x-small color="white">mdi-puzzle</v-icon>
                                                    {{ token.display }}
                                                </v-chip>
                                            </template>
                                        </transition-group>
                                    </div>
                                    <div class="mt-2 caption grey--text italic">
                                        Haz clic en las palabras de arriba para convertirlas automáticamente en huecos Cloze.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Random Short-Answer Match -->
                        <div v-else-if="newQuestion.type === 'randomsamatch'">
                            <v-alert colored-border border="left" color="teal" class="mb-4 elevation-1" text>
                                <div class="d-flex align-center">
                                    <v-icon color="teal" class="mr-3">mdi-shuffle-variant</v-icon>
                                    <div class="d-flex flex-column">
                                        <span class="text-body-2"><strong>Generador Automático:</strong> Crea un examen de emparejar usando tus preguntas de "Respuesta Corta".</span>
                                        <div class="mt-1">
                                            <v-btn x-small color="teal" text class="pa-0 font-weight-bold" @click="showRandomSAMatchHelp = true">
                                                <v-icon left x-small>mdi-information-outline</v-icon> ¿Cómo funciona este tipo de pregunta?
                                            </v-btn>
                                        </div>
                                    </div>
                                </div>
                            </v-alert>

                            <!-- RandomSAMatch Help Modal -->
                            <v-dialog v-model="showRandomSAMatchHelp" max-width="600px" scrollable>
                                <v-card class="rounded-xl">
                                    <v-toolbar flat color="teal" dark>
                                        <v-icon left>mdi-shuffle-variant</v-icon>
                                        <v-toolbar-title>Emparejamiento Aleatorio</v-toolbar-title>
                                        <v-spacer></v-spacer>
                                        <v-btn icon @click="showRandomSAMatchHelp = false"><v-icon>mdi-close</v-icon></v-btn>
                                    </v-toolbar>
                                    
                                    <v-card-text class="pa-6">
                                        <div class="mb-6">
                                            <div class="subtitle-2 font-weight-bold teal--text mb-2 text-uppercase">1. ¿Qué es este tipo de pregunta?</div>
                                            <p class="body-2 grey--text text--darken-2">
                                                Es un <strong>"recolector" de preguntas</strong>. En lugar de escribir el contenido aquí, Moodle busca preguntas de <strong>Respuesta Corta</strong> que ya tengas creadas en este curso y las une para formar un examen de "unir con flechas" (dropdowns).
                                            </p>
                                        </div>

                                        <div class="mb-6 pa-4 teal lighten-5 rounded-lg border-teal">
                                            <div class="caption font-weight-bold teal--text mb-1 text-uppercase">Requisito Indispensable</div>
                                            <p class="body-2 mb-0">
                                                Debes tener al menos <strong>2 o 3 preguntas de "Respuesta Corta"</strong> guardadas previamente en esta misma categoría. Si no hay preguntas, este examen saldrá vacío.
                                            </p>
                                        </div>

                                        <div class="mb-6">
                                            <div class="subtitle-2 font-weight-bold teal--text mb-2 text-uppercase">2. ¿Por qué es útil?</div>
                                            <p class="body-2 grey--text text--darken-2">
                                                Si tienes un banco de 20 preguntas de geografía, puedes configurar este "Emparejamiento Aleatorio" para que elija 5 al azar. Cada estudiante verá un examen de emparejar totalmente distinto.
                                            </p>
                                        </div>

                                        <div class="pa-4 grey lighten-4 rounded-lg">
                                            <div class="d-flex align-center mb-1">
                                                <v-icon color="teal" class="mr-2" small>mdi-lightbulb-on</v-icon>
                                                <span class="body-2 font-weight-bold">Tip de Uso:</span>
                                            </div>
                                            <p class="caption mb-0">
                                                Ideal para repasos rápidos o exámenes finales donde quieres que el sistema mezcle automáticamente conceptos que ya evaluaste por separado.
                                            </p>
                                        </div>
                                    </v-card-text>

                                    <v-divider></v-divider>
                                    <v-card-actions class="pa-4">
                                        <v-spacer></v-spacer>
                                        <v-btn depressed color="teal" dark class="rounded-lg px-6" @click="showRandomSAMatchHelp = false">Entendido</v-btn>
                                    </v-card-actions>
                                </v-card>
                            </v-dialog>

                            <v-card outlined class="pa-4 rounded-xl grey lighten-5">
                                <v-row dense>
                                    <v-col cols="12" md="6">
                                        <v-text-field label="Cantidad de preguntas a incluir" v-model="newQuestion.choose" type="number" min="2" outlined dense hide-details prepend-inner-icon="mdi-format-list-numbered"></v-text-field>
                                    </v-col>
                                    <v-col cols="12" md="6" class="d-flex align-center">
                                        <v-checkbox v-model="newQuestion.subcats" label="Incluir subcategorías" dense hide-details color="teal"></v-checkbox>
                                    </v-col>
                                </v-row>
                            </v-card>
                        </div>

                        <!-- Drag & Drop (Image or Markers) -->
                        <div v-else-if="newQuestion.type === 'ddimageortext' || newQuestion.type === 'ddmarker'">
                            <v-alert colored-border border="left" color="indigo" class="mb-4 elevation-1" text>
                                <div class="d-flex align-center">
                                    <v-icon color="indigo" class="mr-3">mdi-image-move</v-icon>
                                    <span class="text-body-2">Define zonas interactivas sobre una imagen. Los estudiantes deberán arrastrar textos o imágenes a las posiciones correctas.</span>
                                </div>
                            </v-alert>

                            <!-- Image Upload Section -->
                            <v-card outlined class="pa-4 mb-6 rounded-xl grey lighten-5">
                                <v-file-input 
                                    label="Subir Imagen de Fondo" 
                                    outlined dense 
                                    accept="image/*" 
                                    v-model="newQuestion.ddfile"
                                    @change="onFileChange" 
                                    show-size
                                    prepend-inner-icon="mdi-camera"
                                    prepend-icon=""
                                    background-color="white"
                                    hide-details
                                ></v-file-input>
                            </v-card>
                            
                            <!-- Visual Editor Container -->
                            <div v-if="newQuestion.ddbase64" class="mb-6">
                                <v-card outlined class="rounded-xl overflow-hidden border-indigo shadow-sm">
                                    <v-toolbar flat color="indigo lighten-5" dense>
                                        <v-toolbar-title class="caption font-weight-bold indigo--text">EDITOR VISUAL DE POSICIONAMIENTO</v-toolbar-title>
                                        <v-spacer></v-spacer>
                                        <v-btn small depressed color="indigo" dark @click="addDropZone">
                                            <v-icon left x-small>mdi-plus-box</v-icon> Nueva Zona
                                        </v-btn>
                                    </v-toolbar>
                                    
                                    <div 
                                        ref="ddArea"
                                        style="position: relative; overflow: auto; min-height: 200px; background-color: #f0f0f0;"
                                        class="pa-2 d-flex justify-center"
                                        @mousemove="onDragMove"
                                        @mouseup="stopDrag"
                                        @mouseleave="stopDrag"
                                    >
                                        <div style="position: relative; display: inline-block;">
                                            <!-- Background Image -->
                                            <img 
                                                :src="newQuestion.ddbase64" 
                                                style="max-width: 100%; display: block;" 
                                                ondragstart="return false;"
                                                class="rounded-lg shadow"
                                            >

                                            <!-- Draggable Markers -->
                                            <div 
                                                v-for="(drop, i) in newQuestion.drops" 
                                                :key="'drop'+i"
                                                class="elevation-4 rounded-pill d-flex align-center justify-center px-4 py-2 white--text font-weight-bold"
                                                :class="getDraggableColorClass(drop.choice)"
                                                :style="{
                                                    position: 'absolute', 
                                                    left: drop.x + 'px', 
                                                    top: drop.y + 'px', 
                                                    cursor: 'move',
                                                    zIndex: 100,
                                                    userSelect: 'none',
                                                    border: '2px solid white',
                                                    fontSize: '12px',
                                                    transform: 'translate(-50%, -50%)'
                                                }"
                                                @mousedown.prevent="startDrag($event, i)"
                                            >
                                                {{ i + 1 }}
                                                <v-icon x-small color="white" class="ml-2" @click.stop="removeDropZone(i)">mdi-close-circle</v-icon>
                                            </div>
                                        </div>
                                    </div>
                                    <v-divider></v-divider>
                                    <div class="pa-2 caption grey--text text-center italic">
                                        <v-icon x-small grey>mdi-gesture-tap-hold</v-icon> Arrastra los números sobre la imagen para asignar las zonas correctas.
                                    </div>
                                </v-card>
                            </div>

                            <!-- Config Panel -->
                            <v-expansion-panels flat class="rounded-xl overflow-hidden border">
                                <v-expansion-panel>
                                    <v-expansion-panel-header color="grey lighten-5">
                                        <span class="subtitle-2">Configuración de Marcadores</span>
                                    </v-expansion-panel-header>
                                    <v-expansion-panel-content class="pt-4">
                                        <div v-for="(item, i) in newQuestion.draggables" :key="'drag'+i" class="mb-4 pa-3 border rounded-lg">
                                            <v-row dense align="center">
                                                <v-col cols="1" class="text-center subtitle-2 indigo--text">
                                                    <v-avatar :color="getGroupColorHex(item.group || 1)" size="24" class="white--text caption">
                                                        {{ i + 1 }}
                                                    </v-avatar>
                                                </v-col>
                                                <v-col cols="7">
                                                    <v-text-field label="Etiqueta / Texto" v-model="item.text" hide-details dense outlined class="rounded-lg"></v-text-field>
                                                </v-col>
                                                <v-col cols="3">
                                                    <v-select 
                                                        label="Color / Grupo" 
                                                        v-model="item.group" 
                                                        :items="[
                                                            {text: 'Grupo 1 (Azul)', value: 1},
                                                            {text: 'Grupo 2 (Verde)', value: 2},
                                                            {text: 'Grupo 3 (Rojo)', value: 3},
                                                            {text: 'Grupo 4 (Naranja)', value: 4},
                                                            {text: 'Grupo 5 (Purpura)', value: 5}
                                                        ]" 
                                                        hide-details dense outlined class="rounded-lg"
                                                    ></v-select>
                                                </v-col>
                                                <v-col cols="1" class="text-right">
                                                    <v-btn icon color="red lighten-4" @click="removeDraggable(i)"><v-icon small>mdi-delete</v-icon></v-btn>
                                                </v-col>
                                            </v-row>
                                        </div>
                                        <v-btn block depressed color="indigo lighten-5" class="indigo--text" @click="addDraggableElement">
                                            <v-icon left small>mdi-plus-circle</v-icon> Nuevo Elemento
                                        </v-btn>
                                    </v-expansion-panel-content>
                                </v-expansion-panel>
                            </v-expansion-panels>
                        </div>

                        <!-- Drag and Drop Over Text Editor / Gap Select -->
                        <drag-drop-text-editor 
                            v-else-if="newQuestion.type === 'gapselect' || newQuestion.type === 'ddwtos'"
                            :tokens="tokens"
                            :answers="newQuestion.answers"
                            :questiontext.sync="newQuestion.questiontext"
                            @insert-gap="insertGap"
                            @convert-to-gap="convertToGap"
                            @revert-to-text="revertToText"
                            @add-answer="addAnswer"
                            @remove-answer="removeAnswer"
                        ></drag-drop-text-editor>


                        <!-- Fallback for complex types -->
                        <div v-else>
                            <v-alert type="warning" text border="left">
                                La configuración visual avanzada para <b>{{ questionTypeLabel(newQuestion.type) }}</b> está en desarrollo.<br>
                                Se creará con configuración básica por defecto.
                            </v-alert>
                        </div>

                    </v-card-text>
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn text @click="showAddQuestionDialog = false">Cancelar</v-btn>
                        <v-btn color="primary" @click="saveQuestion" :loading="saving">Guardar</v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>

            <!-- Help Modal (Moved to Root) -->
            <v-dialog v-model="showCalculatedHelp" max-width="600px" scrollable>
                                <v-card class="rounded-xl">
                                    <v-toolbar flat color="indigo" dark>
                                        <v-icon left>mdi-calculator-variant</v-icon>
                                        <v-toolbar-title>Mecánica de Preguntas Calculadas</v-toolbar-title>
                                        <v-spacer></v-spacer>
                                        <v-btn icon @click="showCalculatedHelp = false"><v-icon>mdi-close</v-icon></v-btn>
                                    </v-toolbar>
                                    
                                    <v-card-text class="pa-6">
                                        <div class="mb-6">
                                            <div class="subtitle-2 font-weight-bold indigo--text mb-2 text-uppercase">1. La Lógica General</div>
                                            <p class="body-2 grey--text text--darken-2">
                                                En lugar de una respuesta fija, escribes una <strong>fórmula</strong> usando variables entre llaves como <code>{base}</code>.
                                                Moodle las reemplaza por números reales aleatorios para cada estudiante.
                                            </p>
                                        </div>

                                        <!-- Comparison Section -->
                                        <div class="mb-6">
                                            <div class="subtitle-2 font-weight-bold indigo--text mb-3 text-uppercase">2. ¿Cuál tipo elegir?</div>
                                            
                                            <v-card outlined class="mb-3 pa-3 rounded-lg border-blue">
                                                <div class="d-flex align-start">
                                                    <v-icon color="blue" class="mr-3 mt-1">mdi-numeric</v-icon>
                                                    <div>
                                                        <div class="body-2 font-weight-bold">Calculada Normal / Simple</div>
                                                        <div class="caption grey--text text--darken-1">
                                                            El alumno debe <strong>escribir el número</strong>. Ideal para evaluar el cálculo final y procesos matemáticos abiertos.
                                                        </div>
                                                    </div>
                                                </div>
                                            </v-card>

                                        <v-card outlined class="pa-3 rounded-lg border-orange">
                                                <div class="d-flex align-start">
                                                    <v-icon color="orange darken-2" class="mr-3 mt-1">mdi-format-list-bulleted-type</v-icon>
                                                    <div>
                                                        <div class="body-2 font-weight-bold">Calculada de Opción Múltiple</div>
                                                        <div class="caption grey--text text--darken-1">
                                                            Tú escribes una fórmula para cada opción (A, B, C). **Moodle calcula los números de todas las opciones** y el estudiante elige el resultado correcto.
                                                        </div>
                                                    </div>
                                                </div>
                                            </v-card>
                                        </div>

                                        <div class="mb-6 pa-4 amber lighten-5 rounded-lg border-amber">
                                            <div class="subtitle-2 font-weight-bold amber--text text--darken-4 mb-1">
                                                <v-icon small color="amber darken-4" class="mr-1">mdi-lightbulb-on</v-icon> ¿Por qué usar Opción Múltiple?
                                            </div>
                                            <p class="caption mb-0 grey--text text--darken-3">
                                                Te permite poner <strong>"distractores inteligentes"</strong>. Por ejemplo, puedes poner una opción cuya fórmula sea un error común. Moodle calculará el número exacto de ese error para que el alumno deba razonar cuál es la fórmula correcta.
                                            </p>
                                        </div>

                                        <div class="mb-2">
                                            <div class="subtitle-2 font-weight-bold indigo--text mb-2 text-uppercase">3. Diferencia en la Vista del Alumno</div>
                                            <v-simple-table dense class="grey lighten-5 rounded-lg border mb-2">
                                                <template v-slot:default>
                                                    <thead>
                                                        <tr>
                                                            <th class="caption font-weight-bold text-uppercase">Tipo</th>
                                                            <th class="caption font-weight-bold text-uppercase">El Alumno Ve...</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <tr>
                                                            <td class="caption font-weight-bold">Normal / Simple</td>
                                                            <td class="caption italic">Un cuadro vacío para <strong>escribir</strong> el número.</td>
                                                        </tr>
                                                        <tr>
                                                            <td class="caption font-weight-bold">Opción Múltiple</td>
                                                            <td class="caption italic">Una lista (A, B, C) con los <strong>números ya calculados</strong>.</td>
                                                        </tr>
                                                    </tbody>
                                                </template>
                                            </v-simple-table>
                                            <div class="caption indigo--text font-weight-bold">
                                                * En ambos casos se usan cálculos, la diferencia es cómo responde el alumno.
                                            </div>
                                        </div>

                                        <div class="mb-2">
                                            <div class="subtitle-2 font-weight-bold indigo--text mb-2 text-uppercase">4. Cómo escribir el enunciado</div>
                                            <v-card outlined class="pa-3 rounded-lg border-indigo grey lighten-5">
                                                <div class="d-flex align-start">
                                                    <v-icon color="indigo" class="mr-2 mt-1" small>mdi-format-text</v-icon>
                                                    <div>
                                                        <p class="body-2 mb-2">Debes poner las variables entre llaves <code>{}</code> dentro del texto de la pregunta para que aparezcan los números.</p>
                                                        <div class="caption font-weight-bold mb-1">Ejemplo Correcto:</div>
                                                        <div class="white pa-2 rounded border mb-2 body-2 font-italic">
                                                            "Calcula el área de un triángulo de base <strong>{b}</strong> m y altura <strong>{h}</strong> m."
                                                        </div>
                                                        <p class="caption mb-0 grey--text text--darken-3">
                                                            Cuando el alumno entre, verá: "Calcula el área de un triángulo de base <strong>5.4</strong> m y altura <strong>8.2</strong> m." (los números cambian para cada uno).
                                                        </p>
                                                    </div>
                                                </div>
                                            </v-card>
                                        </div>
                                    </v-card-text>

                                    <v-divider></v-divider>
                                    <v-card-actions class="pa-4">
                                        <v-spacer></v-spacer>
                                        <v-btn depressed color="indigo" dark class="rounded-lg px-6" @click="showCalculatedHelp = false">Entendido</v-btn>
                                    </v-card-actions>
                                </v-card>
                            </v-dialog>


            <!-- Error Dialog -->
            <v-dialog v-model="errorDialog" max-width="600px">
                <v-card>
                    <v-card-title class="headline error--text">Error al Guardar</v-card-title>
                    <v-card-text>
                        <p>Ocurrió un error al procesar su solicitud. Por favor, copie el siguiente detalle y repórtelo al soporte técnico:</p>
                        <v-textarea
                            v-model="errorDetails"
                            outlined
                            readonly
                            rows="10"
                            class="font-family-monospace"
                            style="font-family: monospace; font-size: 12px;"
                        ></v-textarea>
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn color="primary" text @click="errorDialog = false">Cerrar</v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>

            <!-- Cloze Wizard component -->
            <cloze-wizard 
                v-model="clozeDialog" 
                :wizard="clozeWizard" 
                @insert="insertCloze"
            ></cloze-wizard>

            <!-- Question Bank Dialog -->
                <question-bank-dialog 
                    v-model="showBankDialog" 
                    :config="config" 
                    :cmid="cmid"
                    @question-added="fetchQuestions"
                ></question-bank-dialog>
        </v-app>
    `,
    props: ['config', 'cmid'],
    data: () => ({
        loading: false,
        saving: false,
        questions: [],
        showAddQuestionDialog: false,
        questionTypes: [
            { text: 'Opción múltiple', value: 'multichoice' },
            { text: 'Verdadero/Falso', value: 'truefalse' },
            { text: 'Emparejamiento', value: 'match' },
            { text: 'Respuesta corta', value: 'shortanswer' },
            { text: 'Numérica', value: 'numerical' },
            { text: 'Ensayo', value: 'essay' },
            { text: 'Arrastrar y soltar marcadores', value: 'ddmarker' },
            { text: 'Arrastrar y soltar sobre texto', value: 'ddwtos' },
            { text: 'Arrastrar y soltar sobre una imagen', value: 'ddimageortext' },
            { text: 'Calculada', value: 'calculated' },
            { text: 'Calculada opción múltiple', value: 'calculatedmulti' },
            { text: 'Calculada simple', value: 'calculatedsimple' },
            { text: 'Elige la palabra perdida', value: 'gapselect' },
            { text: 'Emparejamiento aleatorio', value: 'randomsamatch' },
            { text: 'Respuestas anidadas (Cloze)', value: 'multianswer' },
            { text: 'Descripción', value: 'description' }
        ],
        newQuestion: {
            type: 'multichoice',
            name: '',
            questiontext: '',
            defaultmark: 1,
            correctAnswer: '1',
            answers: [], // Start empty, will be populated by type or user
            single: true,
            subquestions: [
                { text: '', answer: '' },
                { text: '', answer: '' }
            ],
            shuffleanswers: true,
            usecase: 0,
            unit: '',
            unitpenalty: 0.1,
            tolerance: 0.01,
            responseformat: 'editor',
            responserequired: 15,
            choose: 4,
            subcats: 0,
            ddfile: null,
            ddbase64: '',
            draggables: [
                { type: 'text', text: '', group: 1, infinite: true }
            ],
            drops: [],
            dataset: [],
            formulas: [],
            save_to_course: true
        },
        gradeOptions: [
            { text: 'Ninguna (0%)', value: 0.0 },
            { text: '100%', value: 1.0 },
            { text: '90%', value: 0.9 },
            { text: '80%', value: 0.8 },
            { text: '75%', value: 0.75 },
            { text: '70%', value: 0.7 },
            { text: '66.66667%', value: 0.6666667 },
            { text: '60%', value: 0.6 },
            { text: '50%', value: 0.5 },
            { text: '40%', value: 0.4 },
            { text: '33.33333%', value: 0.3333333 },
            { text: '25%', value: 0.25 },
            { text: '20%', value: 0.2 },
            { text: '-50%', value: -0.5 },
            { text: '-100%', value: -1.0 }
        ],
        errorDialog: false,
        errorDetails: '',
        draggingIndex: -1,
        dragOffset: { x: 0, y: 0 },

        // Cloze Wizard Data
        clozeDialog: false,
        clozeWizard: {
            type: 'SHORTANSWER',
            mark: 1,
            correct: '',
            distractors: [''],
            selectionStart: 0,
            selectionEnd: 0,
            editingTokenIdx: -1
        },
        showAddVariableDialog: false,
        newVarName: '',
        newVarMin: 1,
        newVarMax: 10,
        formulaConstant: '',
        showClozeHelp: false,
        showBankDialog: false,
        showCalculatedHelp: false
    }),
    computed: {
        previewClozeCode() {
            const w = this.clozeWizard;
            let code = `{${w.mark}:${w.type}:=`;

            if (w.type === 'SHORTANSWER' || w.type === 'NUMERICAL') {
                code += w.correct;
            } else if (w.type.startsWith('MULTICHOICE')) {
                code += w.correct; // Correct option
                // Distractors
                w.distractors.forEach(d => {
                    if (d) code += `~${d}`;
                });
            }
            code += `}`;
            return code;
        },
        tokenizedText() {
            if (!this.newQuestion.questiontext) return [];

            const isCloze = this.newQuestion.type === 'multianswer';
            // Regex to match [[n]] gaps OR {cloze} tags
            const regex = isCloze ? /(\{[^{}]+\})/g : /(\[\[\d+\]\])/g;

            // We split while keeping the separators (if regex has capturing groups)
            const parts = this.newQuestion.questiontext.split(regex);
            let tokens = [];

            parts.forEach(part => {
                if (part === undefined || part === null) return;
                if (part === '') return;

                if (isCloze) {
                    const match = part.match(/^\{(\d+):([A-Z_]+):(.*)\}$/);
                    if (match) {
                        const optionsStr = match[3];
                        const options = optionsStr.split('~');
                        const correct = options.find(o => o.startsWith('=')) || options[0];
                        const cleanText = correct.replace(/^=/, '').split('#')[0];
                        tokens.push({ type: 'gap', value: part, display: cleanText, isCloze: true });
                        return;
                    }
                } else {
                    const match = part.match(/^\[\[(\d+)\]\]$/);
                    if (match) {
                        tokens.push({ type: 'gap', value: part, gapIndex: parseInt(match[1]), isCloze: false });
                        return;
                    }
                }

                // For text parts, we WANT to preserve everything including spaces
                // but we split into "clickable" chunks. 
                // We split by whitespace but keep the whitespace as tokens too.
                const subParts = part.split(/(\s+)/g);
                subParts.forEach(sp => {
                    if (sp === '') return;
                    if (sp.match(/^\s+$/)) {
                        tokens.push({ type: 'text', value: sp, isWhitespace: true });
                    } else {
                        tokens.push({ type: 'text', value: sp, isWhitespace: false });
                    }
                });
            });
            return tokens;
        },
        tokens() { return this.tokenizedText; },
        clozeTokens() { return this.tokenizedText; }
    },
    watch: {
        'newQuestion.type'(newType) {
            // When switching type, if answers are empty or standard empty ones, adjust
            if (newType === 'ddwtos' || newType === 'gapselect') {
                const onlyEmpty = this.newQuestion.answers.every(a => !a.text || a.text.trim() === '');
                if (this.newQuestion.answers.length > 0 && onlyEmpty) {
                    this.newQuestion.answers = [];
                }
            } else if (newType === 'multichoice' || newType === 'truefalse') {
                if (this.newQuestion.answers.length === 0) {
                    this.addAnswerChoice();
                    this.addAnswerChoice();
                }
            } else if (newType.startsWith('calculated')) {
                const minAnswers = (newType === 'calculatedmulti') ? 2 : 1;
                const maxAnswers = (newType === 'calculatedmulti') ? 100 : 1;

                // Add missing answers
                while (this.newQuestion.answers.length < minAnswers) {
                    this.addAnswerChoice();
                }

                // Trim excess answers for single-answer calculated types
                if (newType === 'calculated' || newType === 'calculatedsimple') {
                    if (this.newQuestion.answers.length > 1) {
                        this.newQuestion.answers = this.newQuestion.answers.slice(0, 1);
                    }
                }
            }
        }
    },
    mounted() {
        this.fetchQuestions();
    },
    methods: {
        goHome() {
            window.location.href = '/local/grupomakro_core/pages/teacher_dashboard.php';
        },
        parseFormula(formula) {
            if (!formula) return [];
            // Regex to match variables {name}, numbers, or operators/functions
            const parts = formula.match(/\{[a-zA-Z0-9_]+\}|[0-9]+(\.[0-9]+)?|[a-zA-Z_]+\(|[+\-*/().,^]|[^+\-*/().,^ \t\n]+/g) || [];
            return parts.map(p => {
                if (p.startsWith('{') && p.endsWith('}')) {
                    return { type: 'variable', value: p };
                } else if (!isNaN(p)) {
                    return { type: 'constant', value: p };
                } else {
                    return { type: 'operator', value: p };
                }
            });
        },
        addToFormula(value, ansIdx) {
            if (!this.newQuestion.answers[ansIdx]) return;
            if (this.newQuestion.answers[ansIdx].text === undefined) {
                this.$set(this.newQuestion.answers[ansIdx], 'text', '');
            }
            this.newQuestion.answers[ansIdx].text += value;
        },
        addVariable() {
            if (!this.newVarName) return;
            if (!this.newQuestion.dataset) this.$set(this.newQuestion, 'dataset', []);

            this.newQuestion.dataset.push({
                name: this.newVarName,
                min: parseFloat(this.newVarMin) || 1,
                max: parseFloat(this.newVarMax) || 10
            });

            this.newVarName = '';
            this.showAddVariableDialog = false;
        },
        async fetchQuestions() {
            this.loading = true;
            try {
                const params = new URLSearchParams();
                params.append('action', 'local_grupomakro_get_quiz_questions');
                const activeCmid = this.cmid || this.config.cmid;
                if (!activeCmid) return;
                params.append('cmid', activeCmid);
                if (this.config.sesskey) params.append('sesskey', this.config.sesskey);

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
        async syncQuizGrades() {
            this.loading = true;
            try {
                const params = new URLSearchParams();
                params.append('action', 'local_grupomakro_sync_quiz_grades');
                params.append('cmid', this.cmid || this.config.cmid);
                if (this.config.sesskey) params.append('sesskey', this.config.sesskey);

                const response = await axios.post(this.config.wwwroot + '/local/grupomakro_core/ajax.php', params);

                if (response.data && response.data.status === 'success') {
                    this.fetchQuestions();
                    alert('Calificaciones sincronizadas correctamente.');
                } else {
                    alert('Error: ' + (response.data.message || 'Error desconocido'));
                }
            } catch (error) {
                console.error(error);
                alert('Error de conexión.');
            } finally {
                this.loading = false;
            }
        },
        async loadQuestionDetails(qid) {
            this.loading = true;
            try {
                const params = new URLSearchParams();
                params.append('action', 'local_grupomakro_get_question_details');
                params.append('questionid', qid);
                if (this.config.sesskey) params.append('sesskey', this.config.sesskey);

                const response = await axios.post(this.config.wwwroot + '/local/grupomakro_core/ajax.php', params);

                if (response.data && response.data.status === 'success') {
                    // Populate newQuestion with details
                    const q = response.data.question;
                    this.resetNewQuestion(); // Clear first

                    // Map results to newQuestion state
                    this.newQuestion.id = q.id;
                    this.newQuestion.name = q.name;
                    this.newQuestion.questiontext = q.questiontext;
                    this.newQuestion.defaultmark = q.defaultmark;

                    if (q.answers) this.newQuestion.answers = q.answers;
                    this.newQuestion.type = q.type; // Set type AFTER answers to avoid watcher clearing them
                    if (q.subquestions) this.newQuestion.subquestions = q.subquestions;
                    if (q.draggables) this.newQuestion.draggables = q.draggables;
                    if (q.drops) this.newQuestion.drops = q.drops;
                    if (q.dataset) this.newQuestion.dataset = q.dataset;
                    if (q.ddbase64) this.newQuestion.ddbase64 = q.ddbase64;
                    if (q.save_to_course !== undefined) this.newQuestion.save_to_course = q.save_to_course;

                    this.showAddQuestionDialog = true;
                } else {
                    alert('Error al cargar detalles: ' + (response.data.message || 'Error desconocido'));
                }
            } catch (error) {
                console.error(error);
                alert('Error de conexión al cargar la pregunta.');
            } finally {
                this.loading = false;
            }
        },
        // Gap Select / DDWTOS Methods
        convertToGap(idx) {
            const tokens = this.tokens; // Use the computed property
            const token = tokens[idx];
            if (!token || token.type !== 'text' || token.isWhitespace) {
                console.warn(`GMK_DEBUG: Ignoring click on token at ${idx}`, token);
                return;
            }

            const cleanWord = token.value.trim();
            const newTokens = [...tokens];

            // Reuse answer if it already exists to avoid redundant options
            let targetIndex = -1;
            if (this.newQuestion.answers) {
                targetIndex = this.newQuestion.answers.findIndex(a => a.text.trim() === cleanWord);
            }

            let newIndex;
            if (targetIndex !== -1) {
                newIndex = targetIndex + 1;
                console.log(`GMK_DEBUG: Reusing answer at index ${newIndex} for word: "${cleanWord}"`);
            } else {
                if (!this.newQuestion.answers) this.$set(this.newQuestion, 'answers', []);
                this.newQuestion.answers.push({ text: cleanWord, fraction: 0.0, group: 1 });
                newIndex = this.newQuestion.answers.length;
                console.log(`GMK_DEBUG: Created new answer at index ${newIndex} for word: "${cleanWord}"`);
            }

            // 2. Update specifically this token
            const marker = `[[${newIndex}]]`;
            newTokens[idx] = { type: 'gap', value: marker, gapIndex: newIndex };

            // Rebuild questiontext
            this.newQuestion.questiontext = newTokens.map(t => t.value).join('');
            console.log("GMK_DEBUG: New questiontext: " + this.newQuestion.questiontext);
        },
        revertToText(idx) {
            const tokens = this.tokenizedText;
            const token = tokens[idx];
            if (!token || token.type !== 'gap' || token.isCloze) return;

            const newIndex = token.gapIndex;
            const answer = this.newQuestion.answers[newIndex - 1];
            const originalText = answer ? (answer.text || `[[${newIndex}]]`) : `[[${newIndex}]]`;

            const newTokens = [...tokens];
            newTokens[idx] = { type: 'text', value: originalText };

            // CRITICAL: We don't shift indices or delete answers to avoid data corruption
            this.newQuestion.questiontext = newTokens.map(t => t.value).join('');
        },
        insertGap(text) {
            let cleanText = text || '';
            // Try to find the textarea within the DragDropTextEditor
            const textarea = document.getElementById('question-text-area');
            const innerTextarea = textarea ? textarea.querySelector('textarea') : null;

            if (innerTextarea) {
                const start = innerTextarea.selectionStart;
                const end = innerTextarea.selectionEnd;
                const fullText = this.newQuestion.questiontext;

                if (!cleanText && start !== end) {
                    cleanText = fullText.substring(start, end).trim();
                }

                // Reuse logic
                let targetIndex = -1;
                if (cleanText && this.newQuestion.answers) {
                    targetIndex = this.newQuestion.answers.findIndex(a => a.text.trim() === cleanText);
                }

                let newIndex;
                if (targetIndex !== -1) {
                    newIndex = targetIndex + 1;
                } else {
                    this.addAnswerChoice();
                    newIndex = this.newQuestion.answers.length;
                    this.newQuestion.answers[newIndex - 1].text = cleanText;
                }

                const marker = `[[${newIndex}]]`;
                this.newQuestion.questiontext = fullText.substring(0, start) + marker + fullText.substring(end);

                this.$nextTick(() => {
                    innerTextarea.focus();
                    const newPos = start + marker.length;
                    innerTextarea.setSelectionRange(newPos, newPos);
                });
            } else {
                // Fallback
                this.addAnswerChoice();
                const newIndex = this.newQuestion.answers.length;
                this.newQuestion.answers[newIndex - 1].text = cleanText;
                this.newQuestion.questiontext += ` [[${newIndex}]]`;
            }
        },
        addAnswer() {
            this.addAnswerChoice();
        },
        removeAnswer(index) {
            this.removeAnswerChoice(index);
        },
        addAnswerChoice() {
            if (!this.newQuestion.answers) this.$set(this.newQuestion, 'answers', []);
            this.newQuestion.answers.push({ text: '', fraction: 0.0, tolerance: 0, group: 1 });
        },
        removeAnswerChoice(index) {
            this.newQuestion.answers.splice(index, 1);
        },
        addSubQuestion() {
            if (!this.newQuestion.subquestions) this.$set(this.newQuestion, 'subquestions', []);
            this.newQuestion.subquestions.push({ text: '', answer: '' });
        },
        removeSubQuestion(index) {
            this.newQuestion.subquestions.splice(index, 1);
        },
        resetNewQuestion() {
            this.newQuestion = {
                id: null,
                type: 'multichoice',
                name: '',
                questiontext: '',
                defaultmark: 1,
                correctAnswer: '1',
                answers: [], // Start empty
                single: true,
                subquestions: [
                    { text: '', answer: '' },
                    { text: '', answer: '' }
                ],
                shuffleanswers: true,
                usecase: 0,
                unit: '',
                unitpenalty: 0.1,
                tolerance: 0.01,
                responseformat: 'editor',
                responserequired: 15,
                choose: 4,
                subcats: 0,
                ddfile: null,
                ddbase64: '',
                draggables: [
                    { type: 'text', text: '', group: 1, infinite: true }
                ],
                drops: [],
                dataset: [],
                formulas: [],
                save_to_course: true
            };
        },
        questionTypeLabel(type) {
            const t = this.questionTypes.find(x => x.value === type);
            return t ? t.text : type;
        },
        addDropZone() {
            if (!this.newQuestion.drops) this.$set(this.newQuestion, 'drops', []);
            if (!this.newQuestion.draggables) this.$set(this.newQuestion, 'draggables', []);

            const nextChoice = this.newQuestion.drops.length + 1;

            // Smart Sync: If there is no draggable for this choice, create it automatically
            if (this.newQuestion.draggables.length < nextChoice) {
                this.addDraggableElement();
                // Set a default text for the new draggable
                this.newQuestion.draggables[nextChoice - 1].text = 'Marcador ' + nextChoice;
            }

            this.newQuestion.drops.push({ choice: nextChoice, label: '', x: 50, y: 50 });
        },
        addDraggableElement() {
            if (!this.newQuestion.draggables) this.$set(this.newQuestion, 'draggables', []);
            this.newQuestion.draggables.push({ type: 'text', text: '', group: 1, infinite: true });
        },
        removeDropZone(index) {
            const drop = this.newQuestion.drops[index];
            const choice = drop.choice;
            this.newQuestion.drops.splice(index, 1);

            // Smart Sync: If no other drop uses this choice, remove the draggable too
            const stillUsed = this.newQuestion.drops.some(d => d.choice === choice);
            if (!stillUsed) {
                this.removeDraggable(choice - 1);
            }
        },
        removeDraggable(index) {
            const choiceToRemove = index + 1;
            this.newQuestion.draggables.splice(index, 1);

            // Smart Sync: Remove all drops that pointed to this choice
            this.newQuestion.drops = this.newQuestion.drops.filter(d => d.choice !== choiceToRemove);

            // Smart Sync: Shift all higher choices down by 1 to maintain index consistency
            this.newQuestion.drops.forEach(d => {
                if (d.choice > choiceToRemove) {
                    d.choice--;
                }
            });
        },
        startDrag(event, index) {
            this.draggingIndex = index;
            const drop = this.newQuestion.drops[index];
            this.dragOffset = {
                x: event.clientX - drop.x, // This logic assumes absolute positioning relative to viewport, usually we need container offset
                // Better approach:
                // We track movement delta.
            };

            // Simpler approach: Get initial mouse pos relative to item corner?
            // Let's use the container bounding rect.
            const rect = this.$refs.ddArea.getBoundingClientRect();
            // Mouse X relative to container
            const mouseX = event.clientX - rect.left;
            const mouseY = event.clientY - rect.top;

            this.dragOffset = {
                x: mouseX - drop.x,
                y: mouseY - drop.y
            };

            document.addEventListener('mousemove', this.onDragMove);
            document.addEventListener('mouseup', this.stopDrag);
        },
        onDragMove(event) {
            if (this.draggingIndex === -1) return;

            const rect = this.$refs.ddArea.getBoundingClientRect();
            let x = event.clientX - rect.left - this.dragOffset.x;
            let y = event.clientY - rect.top - this.dragOffset.y;

            // Constrain
            x = Math.max(0, Math.min(x, rect.width - 30)); // -30 width of marker approx
            y = Math.max(0, Math.min(y, rect.height - 30));

            this.newQuestion.drops[this.draggingIndex].x = x;
            this.newQuestion.drops[this.draggingIndex].y = y;
        },
        stopDrag() {
            this.draggingIndex = -1;
            document.removeEventListener('mousemove', this.onDragMove);
            document.removeEventListener('mouseup', this.stopDrag);
        },
        openClozeWizard(tokenIdx = -1) {
            if (tokenIdx !== -1) {
                // Editing existing token
                const token = this.tokenizedText[tokenIdx];
                if (!token || !token.isCloze) return;

                // Parse Cloze code {1:TYPE:=Correct~Distractor1...}
                const match = token.value.match(/^\{(\d+):([A-Z_]+):(.*)\}$/);
                if (match) {
                    const options = match[3].split('~');
                    const correctToken = options.find(o => o.startsWith('='));
                    const correct = correctToken ? correctToken.replace(/^=/, '').split('#')[0] : options[0].split('#')[0];
                    const distractors = options.filter(o => !o.startsWith('=')).map(o => o.split('#')[0]);

                    this.clozeWizard.mark = parseInt(match[1]);
                    this.clozeWizard.type = match[2];
                    this.clozeWizard.correct = correct;
                    this.clozeWizard.distractors = distractors.length > 0 ? distractors : [''];
                    this.clozeWizard.editingTokenIdx = tokenIdx;
                }
            } else {
                // New selection from textarea
                const textarea = document.getElementById('cloze-textarea');
                if (!textarea) return;

                const start = textarea.selectionStart;
                const end = textarea.selectionEnd;
                const selectedText = textarea.value.substring(start, end);

                this.clozeWizard.type = 'SHORTANSWER';
                this.clozeWizard.correct = selectedText || '';
                this.clozeWizard.mark = 1;
                this.clozeWizard.distractors = [''];
                this.clozeWizard.selectionStart = start;
                this.clozeWizard.selectionEnd = end;
                this.clozeWizard.editingTokenIdx = -1;
            }

            this.clozeDialog = true;
        },
        convertToClozeGap(tokenIdx) {
            const token = this.tokenizedText[tokenIdx];
            if (token.type !== 'text' || token.value.trim().length === 0) return;

            const newTokens = [...this.tokenizedText];
            const cleanWord = token.value.trim();

            // Build simple SHORTANSWER cloze code
            const clozeCode = `{1:SHORTANSWER:=${cleanWord}}`;
            newTokens[tokenIdx] = { type: 'gap', value: clozeCode, display: cleanWord, isCloze: true };

            this.newQuestion.questiontext = newTokens.map(t => t.value).join('');
        },
        revertClozeToText(tokenIdx) {
            const token = this.tokenizedText[tokenIdx];
            if (token.type !== 'gap' || !token.isCloze) return;

            const newTokens = [...this.tokenizedText];
            newTokens[tokenIdx] = { type: 'text', value: token.display };

            this.newQuestion.questiontext = newTokens.map(t => t.value).join('');
        },
        insertCloze() {
            const code = this.previewClozeCode;
            const fullText = this.newQuestion.questiontext;

            if (this.clozeWizard.editingTokenIdx !== -1) {
                // Replacing an existing token from the visual selector
                const newTokens = [...this.tokenizedText];
                const cleanWord = this.clozeWizard.correct;
                newTokens[this.clozeWizard.editingTokenIdx] = { type: 'gap', value: code, display: cleanWord, isCloze: true };
                this.newQuestion.questiontext = newTokens.map(t => t.value).join('');
            } else {
                // Replacing a manual selection in the textarea
                const before = fullText.substring(0, this.clozeWizard.selectionStart);
                const after = fullText.substring(this.clozeWizard.selectionEnd);
                this.newQuestion.questiontext = before + code + after;
            }

            this.clozeDialog = false;
        },
        onFileChange(file) {
            if (!file) {
                this.newQuestion.ddbase64 = '';
                return;
            }
            const reader = new FileReader();
            reader.readAsDataURL(file);
            reader.onload = () => {
                this.newQuestion.ddbase64 = reader.result;
            };
            reader.onerror = (error) => {
                console.error('Error: ', error);
            };
        },
        getGroupColorHex(group) {
            const colors = {
                1: '#1976D2',
                2: '#4CAF50',
                3: '#FF5252',
                4: '#FB8C00',
                5: '#9C27B0'
            };
            return colors[group] || '#1976D2';
        },
        getDraggableColorClass(choice) {
            if (!this.newQuestion.draggables) return 'primary';
            const drag = this.newQuestion.draggables[choice - 1];
            const group = (drag && drag.group) ? drag.group : 1;
            return `gmk-group-${group}`;
        },
        renderLivePreview() {
            if (!this.newQuestion.questiontext) return '<span class="grey--text italic">Escribe algo en el enunciado para ver la previsualización...</span>';

            let html = this.newQuestion.questiontext
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;")
                .replace(/\n/g, '<br>');

            html = html.replace(/\[\[(\d+)\]\]/g, (match, number) => {
                const index = parseInt(number) - 1;
                const opt = this.newQuestion.answers[index];
                const text = (opt && opt.text) ? opt.text : `Hueco ${number}`;
                const group = (opt && opt.group) ? opt.group : 1;
                const bgColor = this.newQuestion.type === 'ddwtos' ? this.getGroupColorHex(group) : '#e0e0e0';
                const textColor = this.newQuestion.type === 'ddwtos' ? 'white' : 'black';

                return `<span class="px-2 py-1 mx-1 rounded font-weight-bold shadow-sm" style="background-color: ${bgColor}; color: ${textColor}; border: 1px dashed #ccc; font-size: 0.85em;">
                    ${text}
                </span>`;
            });

            return html;
        },
        formatToken(val) {
            if (!val) return '';
            return val.replace(/\n/g, '<br>');
        },
        questionTypeLabel(type) {
            const t = this.questionTypes.find(x => x.value === type);
            return t ? t.text : type;
        },
        async saveQuestion() {
            this.saving = true;
            try {
                const fd = new FormData();
                fd.append('action', 'local_grupomakro_add_question');
                fd.append('courseid', this.courseId);
                fd.append('cmid', this.cmid || this.config.cmid);
                fd.append('sesskey', this.config.sesskey);
                console.log("GMK_QUIZ_DEBUG: Sending question data:", JSON.parse(JSON.stringify(this.newQuestion)));
                fd.append('question_data', JSON.stringify(this.newQuestion));
                if (this.newQuestion.ddfile) {
                    fd.append('bgimage', this.newQuestion.ddfile);
                }
                const response = await axios.post(this.config.wwwroot + '/local/grupomakro_core/ajax.php', fd);

                if (response.data && response.data.status === 'success') {
                    this.showAddQuestionDialog = false;
                    this.fetchQuestions();
                    this.resetNewQuestion();
                } else {
                    let msg = (response.data && response.data.message) ? response.data.message : 'Unknown Error';
                    this.errorDetails = `Error: ${msg}`;
                    this.errorDialog = true;
                }
            } catch (error) {
                this.errorDetails = `Error: ${error.toString()}`;
                this.errorDialog = true;
            } finally {
                this.saving = false;
            }
        },
        async removeQuestion(q) {
            if (confirm(`¿Eliminar la pregunta "${q.name}" del cuestionario? (Permanecerá en el banco de preguntas)`)) {
                this.loading = true;
                try {
                    const params = new URLSearchParams();
                    params.append('action', 'local_grupomakro_remove_quiz_question');
                    params.append('cmid', this.cmid || this.config.cmid);
                    params.append('slot', q.slot);
                    if (this.config.sesskey) params.append('sesskey', this.config.sesskey);

                    const response = await axios.post(this.config.wwwroot + '/local/grupomakro_core/ajax.php', params);

                    if (response.data && response.data.status === 'success') {
                        this.fetchQuestions();
                    } else {
                        alert('Error al eliminar: ' + (response.data.message || 'Error desconocido'));
                    }
                } catch (error) {
                    console.error(error);
                    alert('Error de conexión al eliminar la pregunta.');
                } finally {
                    this.loading = false;
                }
            }
        },
        updateOrder() {
            // Reorder logic
        }
    },
    components: {
        draggable: typeof vuedraggable !== 'undefined' ? (vuedraggable.default || vuedraggable) : null,
        'question-bank-dialog': window.QuestionBankDialog,
        'drag-drop-text-editor': window.DragDropTextEditor,
        'cloze-editor': window.ClozeEditor,
        'cloze-wizard': window.ClozeWizard
    },
    created() {
        if (this.config) {
            this.courseId = this.config.courseid;
            this.fetchQuestions();
        }
    }
};

if (typeof window !== 'undefined') {
    window.QuizEditor = QuizEditor;
    window.QuizEditorApp = {
        init: function (config) {
            new Vue({
                el: '#quiz-editor-app',
                vuetify: new Vuetify(),
                data: { initialConfig: config },
                components: { 'quiz-editor': QuizEditor }
            });
        }
    };
}

