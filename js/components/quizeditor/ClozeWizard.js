const ClozeWizard = {
    template: `
        <v-dialog v-model="value" max-width="500px" @input="$emit('input', $event)">
            <v-card class="rounded-xl overflow-hidden">
                <v-toolbar flat color="deep-purple" dark dense>
                    <v-toolbar-title class="subtitle-1 font-weight-bold">Configurar Hueco</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon @click="$emit('input', false)"><v-icon>mdi-close</v-icon></v-btn>
                </v-toolbar>
                <v-card-text class="pa-6">
                    <v-select 
                        label="Tipo de Pregunta" 
                        v-model="wizard.type" 
                        :items="[
                            {text: 'Respuesta Corta (Texto exacto)', value: 'SHORTANSWER'},
                            {text: 'Opción Múltiple (Desplegable)', value: 'MULTICHOICE'},
                            {text: 'Opción Múltiple (Radio Vertical)', value: 'MULTICHOICE_V'},
                            {text: 'Numérica', value: 'NUMERICAL'}
                        ]" 
                        outlined dense class="rounded-lg mb-4"
                    ></v-select>

                    <v-text-field label="Puntuación" v-model="wizard.mark" type="number" outlined dense class="rounded-lg"></v-text-field>

                    <div v-if="wizard.type === 'SHORTANSWER' || wizard.type === 'NUMERICAL'">
                        <v-text-field 
                            label="Respuesta Correcta" 
                            v-model="wizard.correct" 
                            outlined 
                            dense
                            hide-details
                            class="rounded-lg mb-4"
                            placeholder="Escribe la respuesta esperada"
                        ></v-text-field>
                    </div>

                    <div v-if="wizard.type.startsWith('MULTICHOICE')">
                        <v-text-field 
                            label="Respuesta Correcta" 
                            v-model="wizard.correct" 
                            outlined 
                            dense
                            class="mb-2 rounded-lg"
                            prepend-inner-icon="mdi-check"
                            color="green"
                            hide-details
                        ></v-text-field>
                        <div class="d-flex align-center mt-4 mb-2">
                            <span class="caption font-weight-bold grey--text">DISTRACTORES (OPCIONES INCORRECTAS)</span>
                        </div>
                        <v-card outlined class="rounded-lg pa-3 mb-2 bg-light">
                            <div v-for="(dist, i) in wizard.distractors" :key="i" class="d-flex mb-2 align-center">
                                <v-text-field v-model="wizard.distractors[i]" dense outlined hide-details placeholder="Incorrecta" class="white rounded-lg"></v-text-field>
                                <v-btn icon small color="red lighten-4" @click="wizard.distractors.splice(i,1)" class="ml-1"><v-icon small>mdi-close</v-icon></v-btn>
                            </div>
                            <v-btn block small depressed color="deep-purple lighten-5" class="deep-purple--text font-weight-bold rounded-lg mt-2" @click="wizard.distractors.push('')">
                                <v-icon left x-small>mdi-plus</v-icon> Agregar Opción
                            </v-btn>
                        </v-card>
                    </div>

                    <v-alert colored-border border="left" color="deep-purple" class="mt-4 grey lighten-5 caption pa-2 mb-0">
                        Se generará el código: <code class="pa-1 rounded">{{ previewCode }}</code>
                    </v-alert>
                </v-card-text>
                <v-card-actions class="pa-4 bg-light">
                    <v-spacer></v-spacer>
                    <v-btn text @click="$emit('input', false)" class="rounded-lg">Cancelar</v-btn>
                    <v-btn color="deep-purple" dark depressed @click="$emit('insert')" class="rounded-lg px-6">Insertar</v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>
    `,
    props: ['value', 'wizard'],
    computed: {
        previewCode() {
            if (!this.wizard) return '';
            let code = `{${this.wizard.mark}:${this.wizard.type}:`;
            code += `=${this.wizard.correct}`;
            if (this.wizard.distractors && this.wizard.distractors.length > 0) {
                const clean = this.wizard.distractors.filter(d => d.trim() !== '');
                if (clean.length > 0) code += `~${clean.join('~')}`;
            }
            code += `}`;
            return code;
        }
    }
};

window.ClozeWizard = ClozeWizard;
