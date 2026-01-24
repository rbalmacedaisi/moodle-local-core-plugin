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
                            
                            <v-btn small text color="secondary" class="mr-2" disabled>
                                <v-icon left small>mdi-bank</v-icon> Banco (Pronto)
                            </v-btn>
                            <v-btn color="primary" depressed @click="showAddQuestionDialog = true">
                                <v-icon left>mdi-plus</v-icon> Nueva Pregunta
                            </v-btn>
                        </v-toolbar>

                        <v-card-text class="pa-0">
                            <v-skeleton-loader v-if="loading" type="list-item@3" class="pa-4"></v-skeleton-loader>
                            
                            <div v-else-if="questions.length === 0" class="text-center py-10 grey--text">
                                <v-icon size="64" color="grey lighten-3">mdi-clipboard-text-outline</v-icon>
                                <div class="mt-2 body-1">No hay preguntas en este cuestionario.</div>
                                <v-btn text color="primary" class="mt-2 font-weight-bold" @click="showAddQuestionDialog = true">
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
                                                <span v-html="q.questiontext" class="text-truncate d-inline-block" style="max-width: 600px; vertical-align: middle;"></span>
                                            </v-list-item-subtitle>
                                        </v-list-item-content>
                                        
                                        <v-list-item-action class="flex-row">
                                            <v-tooltip bottom>
                                                <template v-slot:activator="{ on, attrs }">
                                                    <v-btn icon color="grey" class="mr-1" v-bind="attrs" v-on="on" disabled>
                                                        <v-icon>mdi-pencil</v-icon>
                                                    </v-btn>
                                                </template>
                                                <span>Editar (Pronto)</span>
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
            <v-dialog v-model="showAddQuestionDialog" max-width="600px">
                <v-card>
                    <v-card-title>Crear Nueva Pregunta</v-card-title>
                    <v-card-text>
                        <v-select label="Tipo de Pregunta" :items="questionTypes" v-model="newQuestion.type" outlined></v-select>
                        
                        <v-text-field label="Nombre de la Pregunta" v-model="newQuestion.name" outlined dense></v-text-field>
                        <v-textarea label="Enunciado de la Pregunta" v-model="newQuestion.text" outlined rows="3"></v-textarea>
                        
                        <v-row>
                            <v-col cols="6">
                                <v-text-field label="Puntuación por defecto" v-model="newQuestion.defaultmark" type="number" outlined dense></v-text-field>
                            </v-col>
                        </v-row>

                        <v-divider class="mb-4"></v-divider>

                        <!-- True/False Specific -->
                        <div v-if="newQuestion.type === 'truefalse'">
                           <h3>Respuesta Correcta</h3>
                           <v-radio-group v-model="newQuestion.correctAnswer" row>
                                <v-radio label="Verdadero" value="1"></v-radio>
                                <v-radio label="Falso" value="0"></v-radio>
                           </v-radio-group>
                        </div>
                        
                        <!-- Essay / Description -->
                        <div v-else-if="newQuestion.type === 'essay' || newQuestion.type === 'description'">
                            <v-alert type="info" text dense v-if="newQuestion.type === 'essay'">
                                El alumno deberá escribir una respuesta libre. Se calificará manualmente.
                            </v-alert>
                             <v-alert type="info" text dense v-if="newQuestion.type === 'description'">
                                Solo muestra texto/imagen. No requiere respuesta.
                            </v-alert>
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

                        <!-- Numerical Specific -->
                        <div v-else-if="newQuestion.type === 'numerical'">
                            <v-alert type="info" dense text small>
                                La respuesta correcta debe tener una calificación del 100%. Puede añadir rangos de tolerancia.
                            </v-alert>
                            <div class="d-flex justify-space-between align-center mb-2">
                                <h3>Respuestas Numéricas</h3>
                                <v-btn small text color="primary" @click="addAnswerChoice"><v-icon left>mdi-plus</v-icon> Agregar Respuesta</v-btn>
                            </div>
                            <v-card outlined v-for="(ans, i) in newQuestion.answers" :key="i" class="mb-2 pa-2">
                                <v-row dense align="center">
                                    <v-col cols="12" md="4">
                                        <v-text-field label="Valor Correcto" v-model="newQuestion.answers[i].text" type="number" step="any" hide-details dense></v-text-field>
                                    </v-col>
                                    <v-col cols="6" md="3">
                                        <v-text-field label="Tolerancia (±)" v-model="newQuestion.answers[i].tolerance" type="number" step="any" hide-details dense></v-text-field>
                                    </v-col>
                                    <v-col cols="6" md="3">
                                        <v-select label="Calificación" :items="gradeOptions" v-model="newQuestion.answers[i].fraction" hide-details dense></v-select>
                                    </v-col>
                                    <v-col cols="6" md="2" class="text-right">
                                        <v-btn icon color="red" small @click="removeAnswerChoice(i)"><v-icon>mdi-delete</v-icon></v-btn>
                                    </v-col>
                                </v-row>
                            </v-card>
                            <div class="mt-4">
                                <h4>Unidades (Opcional)</h4>
                                <v-row dense>
                                    <v-col cols="6">
                                        <v-text-field label="Unidad (ej: kg)" v-model="newQuestion.unit" dense outlined></v-text-field>
                                    </v-col>
                                    <v-col cols="6">
                                        <v-select label="Penalización por unidad" v-model="newQuestion.unitpenalty" :items="[0, 0.1, 0.2, 0.5, 1]" dense outlined></v-select>
                                    </v-col>
                                </v-row>
                            </div>
                        </div>

                        <!-- Gap Select / DD to Text -->
                        <div v-else-if="newQuestion.type === 'gapselect' || newQuestion.type === 'ddwtos'">
                            <v-alert colored-border border="left" color="info" class="mb-4 elevation-1" text>
                                <div class="d-flex align-baseline">
                                    <v-icon color="info" small class="mr-2">mdi-help-circle-outline</v-icon>
                                    <span class="text-caption">Escriba su texto y use el botón <strong>[[ + ]]</strong> para insertar huecos. Luego defina qué palabra va en cada hueco.</span>
                                </div>
                            </v-alert>

                            <!-- Text Editor with Tool -->
                            <div class="mb-4">
                                <div class="d-flex justify-space-between align-center mb-1">
                                    <span class="caption font-weight-bold grey--text">TEXTO DEL ENUNCIADO</span>
                                    <v-btn x-small color="primary" depressed @click="insertGap">
                                        <v-icon x-small left>mdi-plus-box</v-icon> Insertar Hueco [[{{ newQuestion.answers.length + 1 }}]]
                                    </v-btn>
                                </div>
                                <v-textarea
                                    v-model="newQuestion.questiontext"
                                    outlined
                                    dense
                                    rows="4"
                                    hide-details
                                    placeholder="Ejemplo: El cielo es [[1]] y el sol es [[2]]."
                                    id="question-text-area"
                                ></v-textarea>
                            </div>

                            <!-- Live Preview -->
                            <div class="pa-4 mb-6 rounded-lg border shadow-sm" :class="$vuetify.theme.dark ? 'grey darken-4' : 'grey lighten-4'">
                                <div class="caption grey--text mb-2 font-weight-bold">PREVISUALIZACIÓN</div>
                                <div class="text-body-1" v-html="renderLivePreview()"></div>
                            </div>

                            <div class="d-flex justify-space-between align-center mb-4">
                                <h3 class="text-subtitle-2 font-weight-bold grey--text text-uppercase">Opciones de Respuesta</h3>
                                <v-btn small text color="primary" @click="addAnswerChoice">
                                    <v-icon left>mdi-plus</v-icon> Nueva Opción
                                </v-btn>
                            </div>

                            <v-card v-for="(ans, i) in newQuestion.answers" :key="i" flat class="mb-3 border rounded-lg overflow-hidden">
                                <v-row no-gutters align="center">
                                    <v-col cols="1" class="primary white--text d-flex align-center justify-center font-weight-bold" style="min-height: 56px;">
                                        [[{{ i + 1 }}]]
                                    </v-col>
                                    <v-col cols="7" class="pa-2">
                                        <v-text-field label="Palabra / Frase" v-model="newQuestion.answers[i].text" hide-details dense flat solo background-color="transparent"></v-text-field>
                                    </v-col>
                                    <v-col cols="3" class="pa-2 border-left">
                                        <v-select label="Grupo" v-model="newQuestion.answers[i].group" :items="[1,2,3,4,5]" hide-details dense flat solo background-color="transparent"></v-select>
                                    </v-col>
                                    <v-col cols="1" class="text-center">
                                        <v-btn icon color="red lighten-3" small @click="removeAnswerChoice(i)">
                                            <v-icon small>mdi-delete</v-icon>
                                        </v-btn>
                                    </v-col>
                                </v-row>
                            </v-card>

                            <v-checkbox v-model="newQuestion.shuffleanswers" label="Barajar opciones al azar" dense color="primary"></v-checkbox>
                        </div>

                        <!-- Multianswer (Cloze) -->
                        <div v-else-if="newQuestion.type === 'multianswer'">
                            <v-alert type="info" border="left" colored-border color="deep-purple accent-4" elevation="2">
                                <div class="font-weight-bold mb-1">Instrucciones Cloze:</div>
                                <div class="caption">
                                    Escriba el texto y use códigos para los huecos. Ejemplos:<br>
                                    <code>{1:SHORTANSWER:=Respuesta}</code> - Respuesta Corta<br>
                                    <code>{1:MULTICHOICE:=Correcta#Bien~Incorrecta#Mal}</code> - Opción Múltiple<br>
                                    <code>{1:NUMERICAL:=15:0.1}</code> - Numérica (Valor:Tol)
                                </div>
                            </v-alert>
                            <!-- No extra fields needed, logic is in questiontext -->
                        </div>

                        <!-- Random Short-Answer Match -->
                        <div v-else-if="newQuestion.type === 'randomsamatch'">
                            <v-alert type="info" dense text>
                                Moodle seleccionará aleatoriamente preguntas de <b>Respuesta Corta</b> de la categoría actual para crear un emparejamiento.
                            </v-alert>
                            <v-text-field label="Número de preguntas" v-model="newQuestion.choose" type="number" min="2" outlined dense></v-text-field>
                            <v-checkbox v-model="newQuestion.subcats" label="Incluir subcategorías" dense></v-checkbox>
                        </div>

                        <!-- Drag & Drop (Image or Markers) -->
                        <div v-else-if="newQuestion.type === 'ddimageortext' || newQuestion.type === 'ddmarker'">
                            <v-alert type="info" dense text small class="mb-2">
                                Suba una imagen y <b>arrastre los elementos</b> sobre ella para definir las zonas.
                            </v-alert>
                            
                            <!-- Image Upload -->
                            <v-file-input 
                                label="Imagen de Fondo" 
                                outlined 
                                dense 
                                accept="image/*" 
                                v-model="newQuestion.ddfile"
                                @change="onFileChange" 
                                show-size
                                prepend-icon="mdi-image"
                            ></v-file-input>
                            
                            <!-- Main Visual Editor Area -->
                            <div v-if="newQuestion.ddbase64" class="mb-4">
                                <div 
                                    class="d-flex justify-space-between align-center px-4 py-2 grey lighten-4 rounded-t"
                                    style="border: 1px solid #e0e0e0; border-bottom: none;"
                                >
                                    <div class="subtitle-2">Editor Visual</div>
                                    <v-btn small text color="primary" @click="addDropZone">
                                        <v-icon left>mdi-plus</v-icon> Agregar Zona
                                    </v-btn>
                                </div>
                                
                                <div 
                                    ref="ddArea"
                                    style="position: relative; overflow: hidden; border: 1px solid #e0e0e0;"
                                    @mousemove="onDragMove"
                                    @mouseup="stopDrag"
                                    @mouseleave="stopDrag"
                                    class="rounded-b"
                                >
                                    <!-- Background Image -->
                                    <img 
                                        :src="newQuestion.ddbase64" 
                                        style="max-width: 100%; display: block;" 
                                        ondragstart="return false;"
                                    >

                                    <!-- Draggable Drop Zones -->
                                    <div 
                                        v-for="(drop, i) in newQuestion.drops" 
                                        :key="'drop'+i"
                                        class="elevation-3 rounded white d-flex align-center justify-center px-2 py-1 font-weight-bold"
                                        :style="{
                                            position: 'absolute', 
                                            left: drop.x + 'px', 
                                            top: drop.y + 'px', 
                                            cursor: 'move',
                                            border: '2px solid #1976D2',
                                            zIndex: 10,
                                            userSelect: 'none',
                                            minWidth: '100px',
                                            backgroundColor: 'rgba(255, 255, 255, 0.9)'
                                        }"
                                        @mousedown.prevent="startDrag($event, i)"
                                    >
                                        <span class="mr-2">{{ i + 1 }}</span>
                                        <v-icon small color="red" @click.stop="newQuestion.drops.splice(i,1)" class="ml-1" style="cursor: pointer;">mdi-delete</v-icon>
                                    </div>
                                </div>
                                <div class="caption grey--text mt-1 text-center">
                                    Arrastre los marcadores a la posición deseada.
                                </div>
                            </div>
                            <v-alert v-else type="warning" dense text icon="mdi-image-off">
                                Seleccione una imagen para habilitar el editor visual.
                            </v-alert>

                            <!-- Configuration Panel for Elements -->
                            <v-expansion-panels flat class="mt-2">
                                <v-expansion-panel>
                                    <v-expansion-panel-header color="grey lighten-5">
                                        Configuración de Elementos y Zonas
                                    </v-expansion-panel-header>
                                    <v-expansion-panel-content class="pt-4">
                                        
                                        <!-- Draggable Items Defs -->
                                        <div class="subtitle-2 mb-2">Elementos Disponibles (Textos/Imágenes)</div>
                                        <v-card outlined v-for="(item, i) in newQuestion.draggables" :key="'drag'+i" class="mb-2 pa-2">
                                            <v-row dense align="center">
                                                <v-col cols="1" class="text-center font-weight-bold blue--text">{{ i + 1 }}</v-col>
                                                <v-col cols="11" md="8">
                                                    <v-text-field label="Texto / Etiqueta" v-model="item.text" hide-details dense></v-text-field>
                                                </v-col>
                                                <v-col cols="6" md="2">
                                                    <v-select label="Grupo" v-model="item.group" :items="[1,2,3]" hide-details dense></v-select>
                                                </v-col>
                                                <v-col cols="6" md="1" class="text-right">
                                                     <v-btn icon color="red" x-small @click="newQuestion.draggables.splice(i,1)"><v-icon>mdi-delete</v-icon></v-btn>
                                                </v-col>
                                            </v-row>
                                        </v-card>
                                        <v-btn small text color="primary" @click="newQuestion.draggables.push({type:'text', text:'', group:1})" class="mb-4">
                                            <v-icon left>mdi-plus</v-icon> Agregar Elemento
                                        </v-btn>

                                        <v-divider class="mb-4"></v-divider>

                                        <!-- Zone Assignments -->
                                        <div class="subtitle-2 mb-2">Asignación de Zonas (Marcadores en la imagen)</div>
                                        <v-simple-table dense>
                                            <template v-slot:default>
                                                <thead><tr><th>Zona</th><th>Elemento Correcto</th><th>Posición (X, Y)</th></tr></thead>
                                                <tbody>
                                                    <tr v-for="(drop, i) in newQuestion.drops" :key="'d'+i">
                                                        <td><b>{{ i + 1 }}</b></td>
                                                        <td>
                                                            <v-select 
                                                                v-model="drop.choice" 
                                                                :items="newQuestion.draggables.map((d, idx) => ({text: (idx+1) + '. ' + d.text, value: idx+1}))" 
                                                                hide-details dense flat solo
                                                            ></v-select>
                                                        </td>
                                                        <td class="caption grey--text">{{ Math.round(drop.x) }}, {{ Math.round(drop.y) }}</td>
                                                    </tr>
                                                </tbody>
                                            </template>
                                        </v-simple-table>

                                    </v-expansion-panel-content>
                                </v-expansion-panel>
                            </v-expansion-panels>
                        </div>

                        <!-- Calculated Types -->
                        <div v-else-if="newQuestion.type.startsWith('calculated')">
                            <!-- Visual Header -->
                             <div class="d-flex align-center mb-2 pa-2 blue lighten-5 rounded border">
                                <v-icon color="blue" class="mr-2">mdi-calculator</v-icon>
                                <div>
                                    <div class="subtitle-2 blue--text text--darken-2">Editor Matemático</div>
                                    <div class="caption">Escriba la fórmula usando variables entre llaves, ej: <code>{a} + {b}</code></div>
                                </div>
                            </div>

                            <div v-if="newQuestion.type !== 'calculatedmulti'">
                                <v-text-field 
                                    label="Fórmula de Respuesta" 
                                    v-model="newQuestion.answers[0].text" 
                                    outlined 
                                    class="text-h6"
                                    placeholder="Ej: {base} * {altura} / 2"
                                ></v-text-field>
                                
                                <v-expansion-panels flat class="mb-4">
                                     <v-expansion-panel>
                                        <v-expansion-panel-header>Opciones Avanzadas (Tolerancia/Unidades)</v-expansion-panel-header>
                                        <v-expansion-panel-content>
                                            <v-row dense>
                                                <v-col cols="6"><v-text-field label="Tolerancia" v-model="newQuestion.answers[0].tolerance" type="number" step="0.01" dense outlined></v-text-field></v-col>
                                                <v-col cols="6"><v-text-field label="Unidad" v-model="newQuestion.unit" dense outlined></v-text-field></v-col>
                                            </v-row>
                                        </v-expansion-panel-content>
                                     </v-expansion-panel>
                                </v-expansion-panels>
                            </div>
                            
                            <!-- Simplified Dataset UI -->
                            <div class="subtitle-2 mt-2 mb-1">Variables Detectadas</div>
                            <div v-if="newQuestion.dataset.length === 0" class="caption grey--text mb-2">
                                Escriba variables como <code>{x}</code> en la fórmula para configurarlas aquí.
                            </div>
                            <v-card outlined v-for="(ds, i) in newQuestion.dataset" :key="'ds'+i" class="mb-2 pa-2 d-flex align-center">
                                <v-chip label color="blue lighten-4" class="mr-2 font-weight-bold" small>{{ ds.name }}</v-chip>
                                <span class="caption mr-2">Rango:</span>
                                <v-text-field v-model="ds.min" type="number" dense hide-details class="d-inline-block mr-2" style="max-width: 80px;" outlined></v-text-field>
                                <span class="caption mr-2">a</span>
                                <v-text-field v-model="ds.max" type="number" dense hide-details class="d-inline-block mr-2" style="max-width: 80px;" outlined></v-text-field>
                                <v-spacer></v-spacer>
                                <v-btn icon color="red" x-small @click="newQuestion.dataset.splice(i,1)"><v-icon>mdi-close</v-icon></v-btn>
                            </v-card>
                        </div>

                        <!-- Fallback for complex types -->
                        <!-- Multiple Choice UI -->
                        <div v-else-if="newQuestion.type === 'multichoice'">
                            <v-row>
                                <v-col cols="12" md="6">
                                    <v-switch 
                                        label="¿Se permite una o varias respuestas?" 
                                        v-model="newQuestion.single" 
                                        :true-value="true" 
                                        :false-value="false"
                                        inset
                                        dense
                                    >
                                        <template v-slot:label>
                                            {{ newQuestion.single ? 'Solo una respuesta' : 'Se permiten varias respuestas' }}
                                        </template>
                                    </v-switch>
                                </v-col>
                                <v-col cols="12" md="6">
                                    <v-switch 
                                        label="Barajar respuestas" 
                                        v-model="newQuestion.shuffleanswers" 
                                        inset
                                        dense
                                    ></v-switch>
                                </v-col>
                            </v-row>

                            <div class="d-flex align-center justify-space-between mb-2">
                                <div class="subtitle-2">Opciones de Respuesta</div>
                                <v-btn small text color="primary" @click="newQuestion.answers.push({text: '', fraction: 0.0, feedback: ''})">
                                    <v-icon left>mdi-plus</v-icon> Agregar Opción
                                </v-btn>
                            </div>
                            
                            <v-card v-for="(answer, i) in newQuestion.answers" :key="i" outlined class="mb-3 pa-3">
                                <v-row dense align="start">
                                    <v-col cols="12" md="7">
                                        <v-text-field 
                                            :label="'Opción ' + (i+1)" 
                                            v-model="answer.text" 
                                            placeholder="Texto de la opción" 
                                            outlined dense
                                            hide-details="auto"
                                        >
                                            <template v-slot:prepend-inner>
                                                 <v-icon v-if="answer.fraction > 0" color="success">mdi-check-circle-outline</v-icon>
                                                 <v-icon v-else color="grey lighten-1">mdi-circle-outline</v-icon>
                                            </template>
                                        </v-text-field>
                                        <v-text-field 
                                            label="Retroalimentación (Opcional)" 
                                            v-model="answer.feedback" 
                                            dense filled class="mt-2 rounded-lg"
                                            hide-details
                                            placeholder="Comentario si elige esta opción"
                                        ></v-text-field>
                                    </v-col>
                                    <v-col cols="8" md="4">
                                        <v-select 
                                            label="Calificación" 
                                            v-model="answer.fraction" 
                                            :items="gradeOptions" 
                                            outlined dense
                                        >
                                            <template v-slot:selection="{ item }">
                                                <span :class="item.value > 0 ? 'green--text' : 'red--text'">{{ item.text }}</span>
                                            </template>
                                        </v-select>
                                    </v-col>
                                    <v-col cols="4" md="1" class="text-right">
                                        <v-btn icon color="red lighten-2" @click="newQuestion.answers.splice(i, 1)" :disabled="newQuestion.answers.length <= 2">
                                            <v-icon>mdi-delete</v-icon>
                                        </v-btn>
                                    </v-col>
                                </v-row>
                            </v-card>
                        </div>
                        
                        <!-- Short Answer UI -->
                        <div v-else-if="newQuestion.type === 'shortanswer'">
                            <v-alert type="info" text class="mb-4" dense icon="mdi-text-short-title" border="left" colored-border>
                                Defina las respuestas correctas. Puede usar <code>*</code> como comodín.
                            </v-alert>

                            <v-select
                                label="¿Sensible a mayúsculas/minúsculas?"
                                v-model="newQuestion.usecase"
                                :items="[{text: 'No, es igual (a = A)', value: 0}, {text: 'Sí, debe coincidir exactamente (a != A)', value: 1}]"
                                outlined dense
                                class="mb-4"
                            ></v-select>

                            <div class="d-flex align-center justify-space-between mb-2">
                                <div class="subtitle-2">Respuestas Aceptadas</div>
                                <v-btn small text color="primary" @click="newQuestion.answers.push({text: '', fraction: 1.0, feedback: ''})">
                                    <v-icon left>mdi-plus</v-icon> Agregar Respuesta
                                </v-btn>
                            </div>
                            
                            <v-card v-for="(answer, i) in newQuestion.answers" :key="i" outlined class="mb-3 pa-3">
                                <v-row dense align="start">
                                    <v-col cols="12" md="7">
                                        <v-text-field 
                                            label="Respuesta" 
                                            v-model="answer.text" 
                                            placeholder="Ej: París" 
                                            outlined dense
                                            hide-details="auto"
                                            :prepend-inner-icon="answer.fraction == 1 ? 'mdi-check-circle-outline' : 'mdi-circle-outline'"
                                            :color="answer.fraction == 1 ? 'success' : ''"
                                        ></v-text-field>
                                        <v-text-field 
                                            label="Retroalimentación (Opcional)" 
                                            v-model="answer.feedback" 
                                            dense filled class="mt-2 rounded-lg"
                                            hide-details
                                            placeholder="Comentario para el estudiante si elige esta respuesta"
                                        ></v-text-field>
                                    </v-col>
                                    <v-col cols="8" md="4">
                                        <v-select 
                                            label="Calificación" 
                                            v-model="answer.fraction" 
                                            :items="gradeOptions" 
                                            outlined dense
                                        >
                                            <template v-slot:selection="{ item }">
                                                <span :class="item.value > 0 ? 'green--text' : 'red--text'">{{ item.text }}</span>
                                            </template>
                                        </v-select>
                                    </v-col>
                                    <v-col cols="4" md="1" class="text-right">
                                        <v-btn icon color="red lighten-2" @click="newQuestion.answers.splice(i, 1)" :disabled="newQuestion.answers.length <= 1">
                                            <v-icon>mdi-delete</v-icon>
                                        </v-btn>
                                    </v-col>
                                </v-row>
                            </v-card>
                        </div>

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
            <!-- Cloze Wizard Dialog -->
            <v-dialog v-model="clozeDialog" max-width="500px">
                <v-card>
                    <v-card-title>Configurar Hueco</v-card-title>
                    <v-card-text>
                        <v-select 
                            label="Tipo de Pregunta" 
                            v-model="clozeWizard.type" 
                            :items="[
                                {text: 'Respuesta Corta (Texto exacto)', value: 'SHORTANSWER'},
                                {text: 'Opción Múltiple (Desplegable)', value: 'MULTICHOICE'},
                                {text: 'Opción Múltiple (Radio Vertical)', value: 'MULTICHOICE_V'},
                                {text: 'Numérica', value: 'NUMERICAL'}
                            ]" 
                            outlined dense
                        ></v-select>

                        <v-text-field label="Puntuación" v-model="clozeWizard.mark" type="number" outlined dense></v-text-field>

                        <div v-if="clozeWizard.type === 'SHORTANSWER' || clozeWizard.type === 'NUMERICAL'">
                            <v-text-field 
                                label="Respuesta Correcta" 
                                v-model="clozeWizard.correct" 
                                outlined 
                                dense
                                hint="Lo que el estudiante debe escribir"
                            ></v-text-field>
                        </div>

                        <div v-if="clozeWizard.type.startsWith('MULTICHOICE')">
                            <v-text-field 
                                label="Respuesta Correcta" 
                                v-model="clozeWizard.correct" 
                                outlined 
                                dense
                                class="mb-2"
                                prepend-inner-icon="mdi-check"
                                color="green"
                            ></v-text-field>
                            <label class="caption">Distractores (Opciones Incorrectas)</label>
                            <v-card outlined class="pa-2 mb-2">
                                <div v-for="(dist, i) in clozeWizard.distractors" :key="i" class="d-flex mb-1">
                                    <v-text-field v-model="clozeWizard.distractors[i]" dense hide-details placeholder="Incorrecta"></v-text-field>
                                    <v-btn icon small color="red" @click="clozeWizard.distractors.splice(i,1)"><v-icon>mdi-close</v-icon></v-btn>
                                </div>
                                <v-btn x-small text color="primary" @click="clozeWizard.distractors.push('')">Agregar Opción</v-btn>
                            </v-card>
                        </div>

                        <div class="grey--text caption mt-2">
                            Se generará el código: <code>{{ previewClozeCode }}</code>
                        </div>
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn text @click="clozeDialog = false">Cancelar</v-btn>
                        <v-btn color="deep-purple" dark @click="insertCloze">Insertar</v-btn>
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
            type: 'truefalse',
            name: '',
            text: '',
            defaultmark: 1,
            correctAnswer: '1',
            answers: [
                { text: '', fraction: 1.0, tolerance: 0, group: 1 },
                { text: '', fraction: 0.0, tolerance: 0, group: 1 }
            ],
            single: true,
            subquestions: [
                { text: '', answer: '' },
                { text: '', answer: '' }
            ],
            shuffleanswers: true,
            usecase: 0,
            unit: '',
            unitpenalty: 0.1
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
            selectionBefore: '', // To know where to insert
            selectionEnd: 0
        }
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
        }
    },
    mounted() {
        this.fetchQuestions();
    },
    methods: {
        goHome() {
            window.location.href = '/local/grupomakro_core/pages/teacher_dashboard.php';
        },
        async fetchQuestions() {
            this.loading = true;
            try {
                // Use Moodle wwwroot passed in config
                const params = new URLSearchParams();
                params.append('action', 'local_grupomakro_get_quiz_questions');

                // Prioritize prop cmid, fallback to config if exists (for standalone usage)
                const activeCmid = this.cmid || this.config.cmid;

                if (!activeCmid) {
                    // console.error('QuizEditor: No CMID provided');
                    return;
                }

                params.append('cmid', activeCmid);
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
        addAnswerChoice() {
            this.newQuestion.answers.push({ text: '', fraction: 0.0, tolerance: 0, group: 1 });
        },
        removeAnswerChoice(index) {
            this.newQuestion.answers.splice(index, 1);
        },
        addSubQuestion() {
            this.newQuestion.subquestions.push({ text: '', answer: '' });
        },
        removeSubQuestion(index) {
            this.newQuestion.subquestions.splice(index, 1);
        },
        resetNewQuestion() {
            this.newQuestion = {
                type: 'truefalse',
                name: '',
                text: '',
                defaultmark: 1,
                correctAnswer: '1',
                answers: [
                    { text: '', fraction: 1.0, tolerance: 0, group: 1 },
                    { text: '', fraction: 0.0, tolerance: 0, group: 1 }
                ],
                single: true,
                subquestions: [
                    { text: '', answer: '' },
                    { text: '', answer: '' }
                ],
                shuffleanswers: true,
                unit: '',
                unitpenalty: 0.1,
                toggle: false,
                choose: 2,
                subcats: 1,
                // DD Fields
                ddfile: null, // For file upload object
                ddbase64: '', // For preview
                draggables: [
                    { type: 'text', text: '', group: 1, infinite: true },
                    { type: 'text', text: '', group: 1, infinite: true }
                ],
                drops: [
                    { choice: 1, label: '', x: 0, y: 0 },
                    { choice: 2, label: '', x: 0, y: 0 }
                ],
                // Derived/Calculated
                dataset: [],
                formulas: []
            };
        },
        questionTypeLabel(type) {
            const t = this.questionTypes.find(x => x.value === type);
            return t ? t.text : type;
        },
        addDropZone() {
            this.newQuestion.drops.push({ choice: 1, label: '', x: 50, y: 50 });
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
        openClozeWizard() {
            // Get selection from textarea
            const textarea = this.$refs.questionTextarea.$el.querySelector('textarea');
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const selectedText = textarea.value.substring(start, end);

            if (!selectedText) {
                alert('Seleccione primero el texto que desea convertir en hueco.');
                return;
            }

            // Init Wizard
            this.clozeWizard.type = 'SHORTANSWER';
            this.clozeWizard.correct = selectedText;
            this.clozeWizard.mark = 1;
            this.clozeWizard.distractors = [''];
            this.clozeWizard.selectionStart = start;
            this.clozeWizard.selectionEnd = end;

            this.clozeDialog = true;
        },
        insertCloze() {
            const code = this.previewClozeCode;
            const textarea = this.$refs.questionTextarea.$el.querySelector('textarea');

            // Insert code replacing selection
            const fullText = this.newQuestion.text;
            const before = fullText.substring(0, this.clozeWizard.selectionStart);
            const after = fullText.substring(this.clozeWizard.selectionEnd);

            this.newQuestion.text = before + code + after;
            this.clozeDialog = false;
        },
        onFileChange(file) {
            if (!file) {
                this.newQuestion.ddbase64 = '';
                return;
            }
            reader.onerror = (error) => {
                console.error('Error: ', error);
            };
        },
        insertGap() {
            const textarea = document.getElementById('question-text-area');
            if (!textarea) return;

            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const text = this.newQuestion.questiontext;

            this.addAnswerChoice();
            const gapNumber = this.newQuestion.answers.length;
            const marker = `[[${gapNumber}]]`;

            this.newQuestion.questiontext = text.substring(0, start) + marker + text.substring(end);

            // Refocus and place cursor after marker
            this.$nextTick(() => {
                textarea.focus();
                const newPos = start + marker.length;
                textarea.setSelectionRange(newPos, newPos);
            });
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

            // Replace [[n]] with a visual element
            html = html.replace(/\[\[(\d+)\]\]/g, (match, number) => {
                const index = parseInt(number) - 1;
                const opt = this.newQuestion.answers[index];
                const text = (opt && opt.text) ? opt.text : `Hueco ${number}`;
                const color = this.newQuestion.type === 'ddwtos' ? 'primary' : 'grey lighten-2';
                const textColor = this.newQuestion.type === 'ddwtos' ? 'white--text' : '';

                return `<span class="px-2 py-1 mx-1 rounded ${color} ${textColor} font-weight-bold shadow-sm" style="border: 1px dashed #ccc; font-size: 0.85em;">
                    ${text}
                </span>`;
            });

            return html;
        },

        async saveQuestion() {
            this.saving = true;
            try {
                const params = new FormData();
                params.append('action', 'local_grupomakro_add_question');
                params.append('sesskey', this.config.sesskey);
                params.append('courseid', this.courseId);
                params.append('cmid', this.cmid || this.config.cmid);

                // Append File if exists
                if (this.newQuestion.ddfile) {
                    params.append('bgimage', this.newQuestion.ddfile);
                }

                params.append('question_data', JSON.stringify(this.newQuestion));

                const response = await axios.post(this.config.wwwroot + '/local/grupomakro_core/ajax.php', params);

                if (response.data && response.data.status === 'success') {
                    this.showAddQuestionDialog = false;
                    this.fetchQuestions();
                    this.resetNewQuestion();
                } else {
                    console.error('Save failed. Response:', response);
                    console.error('Data:', response.data);

                    let msg = (response.data && response.data.message) ? response.data.message : 'Unknown Error';
                    let debugInfo = (response.data && response.data.debug) ? response.data.debug : '';
                    let raw = '';

                    if (typeof response.data !== 'object') {
                        raw = String(response.data);
                        msg = 'Respuesta inesperada del servidor (no JSON)';
                    }

                    this.errorDetails = `Mensaje: ${msg}\n\nDebug Info:\n${debugInfo}\n\nRaw Response (inicio):\n${raw.substring(0, 500)}`;
                    this.errorDialog = true;
                }
            } catch (error) {
                console.error(error);
                this.errorDetails = `Connection/JS Error:\n${error.toString()}`;
                this.errorDialog = true;
            } finally {
                this.saving = false;
            }
        },
        removeQuestion(q) {
            if (confirm('¿Eliminar pregunta del cuestionario? (No se borra del banco)')) {
                // To implement
            }
        },
        updateOrder() {
            // Reorder logic
        }
    }
};

if (typeof window !== 'undefined') {
    window.QuizEditor = QuizEditor; // Export Component

    // Standalone intialization (legacy support)
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
