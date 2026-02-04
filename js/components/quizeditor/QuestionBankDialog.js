const QuestionBankDialog = {
    template: `
        <v-dialog v-model="value" max-width="800px" scrollable @input="$emit('input', $event)">
            <v-card class="rounded-xl overflow-hidden">
                <v-toolbar flat color="indigo" dark>
                    <v-icon left>mdi-bank</v-icon>
                    <v-toolbar-title class="font-weight-bold">Banco de Preguntas</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon @click="$emit('input', false)"><v-icon>mdi-close</v-icon></v-btn>
                </v-toolbar>

                <v-card-text class="pa-0" style="height: 600px;">
                    <v-container v-if="!bankLoaded" fill-height>
                        <v-row align="center" justify="center">
                            <v-col class="text-center">
                                <v-alert colored-border border="left" color="indigo" elevation="2" class="text-left ma-4">
                                    <div class="headline indigo--text mb-2 font-weight-bold">¿Qué es el Banco de Preguntas?</div>
                                    <p class="body-1 mb-4">Esta sección te permitirá <strong>reutilizar</strong> preguntas que hayas creado anteriormente en este u otros cursos.</p>
                                    
                                    <v-row dense>
                                        <v-col cols="12" md="6">
                                            <v-card flat class="blue lighten-5 pa-3 rounded-lg mb-2" height="100%">
                                                <div class="subtitle-2 blue--text"><v-icon small color="blue" class="mr-1">mdi-sync</v-icon> Reciclaje</div>
                                                <div class="caption">No necesitas volver a escribir una pregunta si ya la tienes guardada.</div>
                                            </v-card>
                                        </v-col>
                                        <v-col cols="12" md="6">
                                            <v-card flat class="green lighten-5 pa-3 rounded-lg mb-2" height="100%">
                                                <div class="subtitle-2 green--text"><v-icon small color="green" class="mr-1">mdi-shuffle</v-icon> Exámenes Aleatorios</div>
                                                <div class="caption">Permite que Moodle elija preguntas al azar de una categoría.</div>
                                            </v-card>
                                        </v-col>
                                    </v-row>

                                    <div class="mt-4 body-2 grey--text text--darken-1">
                                        Estamos habilitando la conexión con tu base de datos de Moodle para que puedas navegar por tus carpetas de preguntas.
                                    </div>
                                </v-alert>
                                
                                <v-btn color="indigo" dark large depressed class="mt-4 rounded-lg" @click="loadBankCategories" :loading="loadingBank">
                                    <v-icon left>mdi-earth</v-icon> Explorar mi Banco de Moodle
                                </v-btn>
                            </v-col>
                        </v-row>
                    </v-container>

                    <div v-else class="d-flex" style="height: 100%;">
                        <!-- Categories Sidebar -->
                        <div class="border-right" style="width: 300px; background: #fafafa; overflow-y: auto;">
                            <v-subheader class="font-weight-bold grey--text">CATEGORÍAS</v-subheader>
                            <v-list dense flat bg-transparent>
                                <v-list-item v-for="cat in bankCategories" :key="cat.id" @click="selectCategory(cat)" :input-value="selectedCategory && selectedCategory.id === cat.id" color="indigo">
                                    <v-list-item-icon class="mr-2">
                                        <v-icon small>mdi-folder-outline</v-icon>
                                    </v-list-item-icon>
                                    <v-list-item-content>
                                        <v-list-item-title class="caption">{{ cat.name }} ({{ cat.questioncount }})</v-list-item-title>
                                    </v-list-item-content>
                                </v-list-item>
                            </v-list>
                        </div>

                        <!-- Questions List -->
                        <div class="flex-grow-1" style="overflow-y: auto;">
                            <div v-if="!selectedCategory" class="fill-height d-flex align-center justify-center grey--text flex-column">
                                <v-icon size="48" color="grey lighten-3">mdi-arrow-left-bold-outline</v-icon>
                                <div class="mt-2">Seleccione una categoría a la izquierda</div>
                            </div>
                            <div v-else class="pa-4">
                                <v-toolbar flat dense color="transparent" class="mb-4">
                                    <v-toolbar-title class="subtitle-1 font-weight-bold">{{ selectedCategory.name }}</v-toolbar-title>
                                    <v-spacer></v-spacer>
                                    <v-text-field v-model="bankSearch" append-icon="mdi-magnify" label="Buscar en esta categoría" single-line hide-details dense outlined class="rounded-lg"></v-text-field>
                                </v-toolbar>

                                <v-divider></v-divider>

                                <v-skeleton-loader v-if="loadingBankQuestions" type="list-item-avatar-two-line@3"></v-skeleton-loader>
                                
                                <v-list v-else-if="bankQuestions.length > 0" two-line>
                                    <v-list-item v-for="bq in filteredBankQuestions" :key="bq.id" class="border rounded-lg mb-2 hover-bg">
                                        <v-list-item-avatar color="blue lighten-5" class="blue--text caption">
                                            {{ bq.qtype.substring(0,2).toUpperCase() }}
                                        </v-list-item-avatar>
                                        <v-list-item-content>
                                            <v-list-item-title class="font-weight-bold">{{ bq.name }}</v-list-item-title>
                                            <v-list-item-subtitle class="caption" v-html="bq.questiontext"></v-list-item-subtitle>
                                        </v-list-item-content>
                                        <v-list-item-action>
                                            <v-btn small color="success" depressed @click="addExistingQuestion(bq.id)" :loading="addingFromBank === bq.id">
                                                <v-icon left small>mdi-plus</v-icon> Añadir
                                            </v-btn>
                                        </v-list-item-action>
                                    </v-list-item>
                                </v-list>
                                <div v-else class="text-center py-10 grey--text">
                                    No hay preguntas compatibles en esta categoría.
                                </div>
                            </div>
                        </div>
                    </div>
                </v-card-text>
            </v-card>
        </v-dialog>
    `,
    props: ['value', 'config', 'cmid'],
    data: () => ({
        loadingBank: false,
        bankLoaded: false,
        bankCategories: [],
        selectedCategory: null,
        bankQuestions: [],
        loadingBankQuestions: false,
        bankSearch: '',
        addingFromBank: null
    }),
    computed: {
        filteredBankQuestions() {
            if (!this.bankSearch) return this.bankQuestions;
            const s = this.bankSearch.toLowerCase();
            return this.bankQuestions.filter(q =>
                q.name.toLowerCase().includes(s) ||
                (q.questiontext && q.questiontext.toLowerCase().includes(s))
            );
        }
    },
    methods: {
        async loadBankCategories() {
            this.loadingBank = true;
            try {
                const params = new URLSearchParams();
                params.append('action', 'local_grupomakro_get_categories');
                params.append('cmid', this.cmid || this.config.cmid);
                if (this.config.sesskey) params.append('sesskey', this.config.sesskey);

                const response = await axios.post(this.config.wwwroot + '/local/grupomakro_core/ajax.php', params);
                if (response.data && response.data.status === 'success') {
                    this.bankCategories = response.data.categories;
                    this.bankLoaded = true;
                }
            } catch (e) {
                console.error(e);
            } finally {
                this.loadingBank = false;
            }
        },
        async selectCategory(cat) {
            this.selectedCategory = cat;
            this.loadingBankQuestions = true;
            try {
                const params = new URLSearchParams();
                params.append('action', 'local_grupomakro_get_bank_questions');
                params.append('categoryid', cat.id);
                if (this.config.sesskey) params.append('sesskey', this.config.sesskey);

                const response = await axios.post(this.config.wwwroot + '/local/grupomakro_core/ajax.php', params);
                if (response.data && response.data.status === 'success') {
                    this.bankQuestions = response.data.questions;
                }
            } catch (e) {
                console.error(e);
            } finally {
                this.loadingBankQuestions = false;
            }
        },
        async addExistingQuestion(qid) {
            this.addingFromBank = qid;
            try {
                const params = new URLSearchParams();
                params.append('action', 'local_grupomakro_add_bank_question');
                params.append('questionid', qid);
                params.append('cmid', this.cmid || this.config.cmid);
                if (this.config.sesskey) params.append('sesskey', this.config.sesskey);

                const response = await axios.post(this.config.wwwroot + '/local/grupomakro_core/ajax.php', params);
                if (response.data && response.data.status === 'success') {
                    this.$emit('question-added');
                } else {
                    alert('Error: ' + (response.data.message || 'Error desconocido'));
                }
            } catch (e) {
                console.error(e);
            } finally {
                this.addingFromBank = null;
            }
        }
    }
};

window.QuestionBankDialog = QuestionBankDialog;
