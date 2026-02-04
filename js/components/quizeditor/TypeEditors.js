/**
 * Drag and Drop over Text (ddwtos) and Gap Select (gapselect) Editor
 */
const DragDropTextEditor = {
    template: `
        <div class="gmk-gap-editor mb-6">
            <v-card outlined class="rounded-xl pa-4 bg-light shadow-sm">
                <div class="d-flex align-center mb-3">
                    <v-icon color="indigo" class="mr-2">mdi-text-box-search-outline</v-icon>
                    <span class="subtitle-2 font-weight-bold">Configuración de Huecos</span>
                    <v-spacer></v-spacer>
                    <v-btn small depressed color="indigo lighten-5" class="indigo--text rounded-lg" @click="$emit('insert-gap')">
                        <v-icon left small>mdi-plus-circle</v-icon> Insertar Hueco
                    </v-btn>
                </div>

                <div class="live-preview-box mb-4 pa-4 rounded-lg border bg-white" v-html="previewHtml"></div>

                <div class="tokens-container d-flex flex-wrap gap-2">
                    <v-chip 
                        v-for="(token, idx) in tokens" 
                        :key="idx"
                        small
                        :color="token.type === 'gap' ? getGapColor(token.gapIndex) : 'grey lighten-4'"
                        :text-color="token.type === 'gap' ? 'white' : 'black'"
                        class="ma-1 rounded-lg"
                        style="height: auto; padding: 4px 8px;"
                    >
                        <span v-if="token.type === 'text'" class="body-2" @click="$emit('convert-to-gap', idx)" style="cursor: pointer;">
                            <span v-html="formatToken(token.value)"></span>
                        </span>
                        <v-btn v-else x-small icon color="white" class="mr-1" @click="$emit('revert-to-text', idx)">
                            <v-icon x-small>mdi-close-circle</v-icon>
                        </v-btn>
                        <strong v-if="token.type === 'gap'">[[{{token.gapIndex}}]] {{ getGapShortText(token.gapIndex) }}</strong>
                    </v-chip>
                </div>
            </v-card>

            <div class="mt-4 answers-grid">
                <v-subheader class="px-0 font-weight-bold grey--text text--darken-2">OPCIONES DISPONIBLES</v-subheader>
                <div v-for="(ans, i) in answers" :key="'gapans'+i" class="mb-3 pa-3 border rounded-xl bg-white shadow-sm hover-elevate transition-all">
                    <v-row dense align="center">
                        <v-col cols="1" class="text-center">
                            <v-avatar :color="getGroupColorHex(ans.group || 1)" size="28" class="white--text font-weight-bold subtitle-2 shadow-sm">
                                {{ i + 1 }}
                            </v-avatar>
                        </v-col>
                        <v-col cols="7">
                            <v-text-field 
                                label="Texto de la opción" 
                                v-model="ans.text" 
                                hide-details dense outlined 
                                class="rounded-lg custom-field"
                                placeholder="Escribe la respuesta..."
                            ></v-text-field>
                        </v-col>
                        <v-col cols="3">
                            <v-select 
                                label="Grupo / Color" 
                                v-model="ans.group" 
                                :items="[
                                    {text: 'Grupo 1 (Azul)', value: 1},
                                    {text: 'Grupo 2 (Verde)', value: 2},
                                    {text: 'Grupo 3 (Rojo)', value: 3},
                                    {text: 'Grupo 4 (Naranja)', value: 4},
                                    {text: 'Grupo 5 (Púrpura)', value: 5}
                                ]" 
                                hide-details dense outlined class="rounded-lg"
                            ></v-select>
                        </v-col>
                        <v-col cols="1" class="text-right">
                            <v-btn icon color="red lighten-4" @click="$emit('remove-answer', i)"><v-icon small>mdi-delete</v-icon></v-btn>
                        </v-col>
                    </v-row>
                </div>
                <v-btn block depressed color="blue lighten-5" class="blue--text font-weight-bold rounded-lg py-6 mt-2" @click="$emit('add-answer')">
                    <v-icon left>mdi-plus-circle-outline</v-icon> Agregar Opción de Respuesta
                </v-btn>
            </div>
        </div>
    `,
    props: ['tokens', 'answers', 'previewHtml'],
    methods: {
        formatToken(val) { return val.replace(/\n/g, '<br>'); },
        getGapColor(idx) {
            const group = this.answers[idx - 1]?.group || 1;
            return this.getGroupColorClass(group);
        },
        getGroupColorClass(group) {
            const classes = { 1: 'blue', 2: 'green', 3: 'red', 4: 'orange', 5: 'purple' };
            return classes[group] || 'blue';
        },
        getGroupColorHex(group) {
            const colors = { 1: '#1976D2', 2: '#4CAF50', 3: '#FF5252', 4: '#FB8C00', 5: '#9C27B0' };
            return colors[group] || '#1976D2';
        },
        getGapShortText(idx) {
            const ans = this.answers[idx - 1];
            return (ans && ans.text) ? ans.text : `[${idx}]`;
        }
    }
};

/**
 * Cloze Editor component
 */
const ClozeEditor = {
    template: `
        <div>
            <v-alert type="info" text border="left" class="mb-4 rounded-xl">
                <div class="d-flex align-center">
                    <div>
                        Escribe tu enunciado y usa el botón <b>"Insertar Hueco"</b> para crear campos de respuesta dinámicos.
                    </div>
                    <v-spacer></v-spacer>
                    <v-btn small text color="primary" @click="showHelp = !showHelp">Ver Tutorial</v-btn>
                </div>
            </v-alert>

            <v-expand-transition>
                <div v-show="showHelp" class="mb-4">
                    <v-card outlined class="rounded-xl pa-4 blue lighten-5 border-blue">
                        <div class="subtitle-2 font-weight-bold mb-2">💡 ¿Cómo funciona?</div>
                        <ol class="caption">
                            <li>Escribe el texto normal.</li>
                            <li>Selecciona una palabra o pulsa <b>"Insertar Hueco"</b>.</li>
                            <li>Configura si es opción múltiple o texto corto.</li>
                            <li>Moodle generará el código <code>{...}</code> automáticamente.</li>
                        </ol>
                    </v-card>
                </div>
            </v-expand-transition>

            <v-card outlined class="rounded-xl pa-4 bg-light mb-4">
                <div class="d-flex align-center mb-2">
                    <v-icon x-small color="grey" class="mr-1">mdi-code-braces</v-icon>
                    <span class="caption font-weight-bold grey--text">EDITOR VISUAL DE CLOZE</span>
                    <v-spacer></v-spacer>
                    <v-btn small depressed color="deep-purple" dark class="rounded-lg" @click="$emit('open-wizard')">
                        <v-icon left small>mdi-plus-circle</v-icon> Insertar Hueco
                    </v-btn>
                </div>

                <div class="cloze-tokens-container d-flex flex-wrap gap-2 pa-3 bg-white rounded-lg border min-height-100">
                    <v-chip 
                        v-for="(token, idx) in tokens" 
                        :key="idx"
                        small
                        :color="token.type === 'gap' ? 'deep-purple lighten-5' : 'transparent'"
                        :text-color="token.type === 'gap' ? 'deep-purple darken-2' : 'black'"
                        class="ma-1 rounded-lg"
                        style="height: auto; padding: 4px 8px;"
                        @click="token.type === 'gap' ? $emit('open-wizard', idx) : null"
                    >
                        <span v-if="token.type === 'text'" class="body-2" @click="$emit('convert-to-gap', idx)" style="cursor: pointer;">
                            <span v-html="formatToken(token.value)"></span>
                        </span>
                        <v-btn v-else x-small icon color="deep-purple" class="mr-1" @click.stop="$emit('revert-to-text', idx)">
                            <v-icon x-small>mdi-close-circle</v-icon>
                        </v-btn>
                        <strong v-if="token.type === 'gap'">{{ token.display }}</strong>
                    </v-chip>
                </div>
            </v-card>
        </div>
    `,
    props: ['tokens'],
    data: () => ({ showHelp: false }),
    methods: {
        formatToken(val) { return val ? val.replace(/\n/g, '<br>') : ''; }
    }
};

window.DragDropTextEditor = DragDropTextEditor;
window.ClozeEditor = ClozeEditor;
