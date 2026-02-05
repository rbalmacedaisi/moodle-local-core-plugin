/**
 * GradebookManager.js
 * Modal component to manage grade weights and manual grade items.
 */

const GradebookManager = {
    props: {
        classId: { type: [Number, String], required: true },
        value: { type: Boolean, default: false } // v-model for visibility
    },
    template: `
        <v-dialog :value="value" @input="$emit('input', $event)" :max-width="isFullscreen ? '100%' : '1100px'" :fullscreen="isFullscreen" scrollable>
            <v-card class="d-flex flex-column grey lighten-5" style="min-height: 600px;">
                <v-toolbar color="primary" dark dense flat>
                    <v-toolbar-title>Gestor de Calificaciones</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-tooltip bottom>
                        <template v-slot:activator="{ on, attrs }">
                            <v-btn icon dark v-bind="attrs" v-on="on" @click="isFullscreen = !isFullscreen">
                                <v-icon>{{ isFullscreen ? 'mdi-fullscreen-exit' : 'mdi-fullscreen' }}</v-icon>
                            </v-btn>
                        </template>
                        <span>{{ isFullscreen ? 'Pantalla Normal' : 'Pantalla Completa' }}</span>
                    </v-tooltip>
                    <v-btn icon dark @click="close">
                        <v-icon>mdi-close</v-icon>
                    </v-btn>
                </v-toolbar>

                <div class="d-flex align-center pa-2 white elevation-1">
                     <div class="subtitle-2 ml-2 grey--text">
                        Total Ponderación: 
                        <span :class="totalWeight === 100 ? 'success--text' : 'error--text'" class="font-weight-bold ml-1">
                            {{ totalWeight.toFixed(2) }}%
                        </span>
                     </div>
                     <v-divider vertical class="mx-3"></v-divider>
                     <v-btn text small color="secondary" @click="redistributeWeights">
                        <v-icon left small>mdi-equalizer</v-icon>
                        Redistribuir Pesos
                     </v-btn>
                     <v-spacer></v-spacer>
                     <v-btn text color="primary" @click="saveWeights" :loading="saving">
                        <v-icon left>mdi-content-save</v-icon>
                        Guardar Cambios
                    </v-btn>
                </div>

                <v-card-text class="pa-4 flex-grow-1 overflow-y-auto" style="height: 500px;">
                    <v-alert v-if="Math.abs(totalWeight - 100) > 0.01" type="error" text outlined class="mb-4" dense>
                        <strong>Atención:</strong> La suma de las ponderaciones es {{ totalWeight.toFixed(2) }}%. Debe ser 100%.
                    </v-alert>

                    <v-card outlined class="mb-4">
                        <v-card-title class="subtitle-1">
                            Ítems de Calificación
                            <v-spacer></v-spacer>
                            <v-btn color="secondary" small @click="showAddDialog = true">
                                <v-icon left>mdi-plus</v-icon> Item Manual
                            </v-btn>
                        </v-card-title>

                        <v-data-table
                            :headers="headers"
                            :items="items"
                            :loading="loading"
                            hide-default-footer
                            disable-pagination
                            class="elevation-0"
                            dense
                        >
                            <!-- Body slot for drag and drop -->
                            <template v-slot:body="{ items }">
                                <tbody>
                                    <tr 
                                        v-for="(item, index) in items" 
                                        :key="item.id"
                                        draggable="true"
                                        @dragstart="onDragStart($event, index)"
                                        @dragover.prevent
                                        @dragenter.prevent
                                        @drop="onDrop($event, index)"
                                        class="draggable-row"
                                    >
                                        <td>
                                            <div class="d-flex align-center">
                                                <v-icon small class="cursor-drag mr-2">mdi-drag</v-icon>
                                                <v-tooltip bottom>
                                                    <template v-slot:activator="{ on, attrs }">
                                                        <v-btn icon x-small v-bind="attrs" v-on="on" @click="item.hidden = item.hidden ? 0 : 1" class="mr-2">
                                                            <v-icon small :color="item.hidden ? 'grey' : 'primary'">
                                                                {{ item.hidden ? 'mdi-eye-off' : 'mdi-eye' }}
                                                            </v-icon>
                                                        </v-btn>
                                                    </template>
                                                    <span>{{ item.hidden ? 'Oculto para estudiantes' : 'Visible para estudiantes' }}</span>
                                                </v-tooltip>
                                                <span :class="item.hidden ? 'grey--text text--lighten-1 italic' : ''">
                                                    {{ item.itemname }}
                                                </span>
                                            </div>
                                        </td>
                                        <td>
                                            <v-chip small :color="getTypeColor(item)" dark label class="font-weight-bold">
                                                {{ getTypeLabel(item) }}
                                            </v-chip>
                                        </td>
                                        <td class="text-center font-weight-bold grey--text">{{ item.grademax }}</td>
                                        <td>
                                            <div class="d-flex align-center justify-center">
                                                <v-text-field
                                                    v-model.number="item.raw_weight"
                                                    type="number"
                                                    step="0.01"
                                                    dense
                                                    outlined
                                                    hide-details
                                                    style="max-width: 85px;"
                                                    class="text-center"
                                                    :background-color="item.weightoverride ? 'orange lighten-5' : 'grey lighten-4'"
                                                    @input="onWeightInput(item)"
                                                ></v-text-field>
                                                <v-tooltip bottom>
                                                    <template v-slot:activator="{ on, attrs }">
                                                        <v-btn icon x-small v-bind="attrs" v-on="on" @click="toggleOverride(item)" class="ml-1">
                                                            <v-icon small :color="item.weightoverride ? 'orange' : 'grey lighten-1'">
                                                                {{ item.weightoverride ? 'mdi-lock' : 'mdi-lock-open-variant-outline' }}
                                                            </v-icon>
                                                        </v-btn>
                                                    </template>
                                                    <div>
                                                        <strong>{{ item.weightoverride ? 'Peso Manual Fijado' : 'Peso Automático' }}</strong><br>
                                                        {{ item.weightoverride ? 'Haga clic para volver al cálculo automático de Moodle.' : 'Haga clic para bloquear este valor.' }}
                                                    </div>
                                                </v-tooltip>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex align-center">
                                                <v-progress-linear
                                                    :value="item.weight"
                                                    color="primary"
                                                    height="20"
                                                    rounded
                                                    class="flex-grow-1"
                                                >
                                                    <template v-slot:default="{ value }">
                                                        <span class="white--text text-caption font-weight-bold">{{ item.weight.toFixed(2) }}%</span>
                                                    </template>
                                                </v-progress-linear>
                                            </div>
                                        </td>
                                        <td class="text-end">
                                            <v-btn v-if="item.itemtype === 'manual' && !item.is_protected" icon color="red" small @click="deleteItem(item)">
                                                <v-icon small>mdi-delete</v-icon>
                                            </v-btn>
                                        </td>
                                    </tr>
                                </tbody>
                            </template>
                        </v-data-table>
                    </v-card>
                </v-card-text>

                <!-- Add Manual Item Dialog -->
                <v-dialog v-model="showAddDialog" max-width="400px">
                    <v-card>
                        <v-card-title class="subtitle-1">Nuevo ítem manual</v-card-title>
                        <v-card-text>
                            <v-text-field v-model="newItem.name" label="Nombre (ej. Participación)" outlined dense autofocus></v-text-field>
                            <v-text-field v-model.number="newItem.maxmark" label="Nota Máxima" type="number" outlined dense></v-text-field>
                        </v-card-text>
                        <v-card-actions>
                            <v-spacer></v-spacer>
                            <v-btn text small @click="showAddDialog = false">Cancelar</v-btn>
                            <v-btn color="primary" small @click="addManualItem" :disabled="!newItem.name">Crear</v-btn>
                        </v-card-actions>
                    </v-card>
                </v-dialog>

                <!-- Snackbar for messages -->
                <v-snackbar v-model="snackbar.show" :color="snackbar.color" top right>
                    {{ snackbar.message }}
                    <template v-slot:action="{ attrs }">
                        <v-btn text v-bind="attrs" @click="snackbar.show = false">X</v-btn>
                    </template>
                </v-snackbar>
            </v-card>
        </v-dialog>
    `,
    data() {
        return {
            isFullscreen: false,
            loading: false,
            saving: false,
            items: [],
            totalWeight: 0,
            showAddDialog: false,
            draggedIndex: null,
            newItem: {
                name: '',
                maxmark: 100
            },
            snackbar: {
                show: false,
                message: '',
                color: 'success'
            },
            headers: [
                { text: 'Actividad', value: 'itemname', sortable: false },
                { text: 'Tipo', value: 'itemtype', sortable: false, width: '120px' },
                { text: 'Max', value: 'grademax', align: 'center', sortable: false, width: '80px' },
                { text: 'Valor Pesado', value: 'raw_weight', align: 'center', sortable: false, width: '140px' },
                { text: 'Equivalencia %', value: 'weight', align: 'start', sortable: false, width: '180px' },
                { text: '', value: 'actions', align: 'end', sortable: false, width: '50px' }
            ]
        };
    },
    mounted() {
        this.injectStyles();
        this.fetchStructure();
    },
    watch: {
        value(val) {
            if (val) {
                this.fetchStructure();
            }
        }
    },
    methods: {
        injectStyles() {
            const styleId = 'gradebook-manager-styles';
            if (document.getElementById(styleId)) return;
            const style = document.createElement('style');
            style.id = styleId;
            style.textContent = `
                .cursor-drag { cursor: grab !important; }
                .cursor-drag:active { cursor: grabbing !important; }
                .draggable-row:hover { background-color: rgba(0,0,0,0.03); }
                .italic { font-style: italic; }
            `;
            document.head.appendChild(style);
        },
        close() {
            this.$emit('input', false);
            this.$emit('closed'); // Trigger refresh in parent
        },
        onDragStart(event, index) {
            this.draggedIndex = index;
            event.dataTransfer.effectAllowed = 'move';
        },
        onDrop(event, index) {
            if (this.draggedIndex === null || this.draggedIndex === index) return;

            const movingItem = this.items.splice(this.draggedIndex, 1)[0];
            this.items.splice(index, 0, movingItem);
            this.draggedIndex = null;
        },
        async fetchStructure() {
            this.loading = true;
            try {
                const sesskey = this.getSesskey();
                console.log('Fetching gradebook structure. Class:', this.classId, 'Sesskey:', sesskey);

                const params = new URLSearchParams();
                params.append('action', 'local_grupomakro_get_gradebook_structure');
                params.append('classid', this.classId);
                params.append('sesskey', sesskey);

                // Use M.cfg.wwwroot directly or fallback
                const baseUrl = (M && M.cfg && M.cfg.wwwroot) ? M.cfg.wwwroot : '';
                const url = baseUrl + '/local/grupomakro_core/ajax.php';

                const response = await axios.post(url, params);

                if (response.data.status === 'success') {
                    this.items = response.data.items.map(i => ({
                        ...i,
                        raw_weight: parseFloat(parseFloat(i.raw_weight || 0).toFixed(2)),
                        weight: parseFloat(parseFloat(i.weight || 0).toFixed(2)),
                        hidden: parseInt(i.hidden) || 0,
                        weightoverride: parseInt(i.weightoverride) || 0,
                        locked: (i.locked == 1 || i.locked === '1' || i.locked === true),
                        is_protected: (i.itemname && i.itemname.includes('Nota Final Integrada'))
                    }));
                    this.calculateTotal();
                } else {
                    console.error('Server error:', response.data);
                    this.showSnackbar(response.data.message || 'Error cargando estructura', 'error');
                }
            } catch (error) {
                console.error('AJAX Error:', error);
                this.showSnackbar('Error de conexión: ' + error.toString(), 'error');
            } finally {
                this.loading = false;
            }
        },
        calculateTotal() {
            this.totalWeight = this.items.reduce((sum, item) => sum + (parseFloat(item.weight) || 0), 0);
        },
        async saveWeights() {
            this.saving = true;
            try {
                const updates = this.items.map(i => ({
                    id: i.id,
                    weight: i.raw_weight, // Send back the raw value for Moodle
                    hidden: i.hidden,
                    weightoverride: i.weightoverride
                }));

                const sortOrder = this.items.map(i => i.id);

                const params = new URLSearchParams();
                params.append('action', 'local_grupomakro_update_grade_weights');
                params.append('classid', this.classId);
                params.append('weights', JSON.stringify(updates));
                params.append('sortorder', JSON.stringify(sortOrder));
                params.append('sesskey', this.getSesskey());

                const baseUrl = (M && M.cfg && M.cfg.wwwroot) ? M.cfg.wwwroot : '';
                const url = baseUrl + '/local/grupomakro_core/ajax.php';

                const response = await axios.post(url, params);

                if (response.data.status === 'success') {
                    this.showSnackbar('Cambios guardados y calificaciones recalculadas');
                } else {
                    this.showSnackbar(response.data.message || 'Error guardando', 'error');
                }
            } catch (error) {
                console.error(error);
                this.showSnackbar('Error de conexión', 'error');
            } finally {
                this.saving = false;
            }
        },
        onWeightInput(item) {
            item.weightoverride = 1;
            this.normalizeFrontend();
        },
        toggleOverride(item) {
            item.weightoverride = item.weightoverride ? 0 : 1;
            this.normalizeFrontend();
        },
        redistributeWeights() {
            if (!confirm('¿Deseas resetear los pesos a una distribución automática equitativa?')) return;
            this.items.forEach(it => {
                it.raw_weight = 1.0;
                it.weightoverride = 0;
            });
            this.normalizeFrontend();
        },
        normalizeFrontend() {
            // Replicate gmk_normalize_grade_weights logic
            const visibleItems = this.items; // Actually all items in this view count
            if (visibleItems.length === 0) return;

            let sumMax = 0;
            let sumWeights = 0;
            visibleItems.forEach(it => {
                sumMax += parseFloat(it.grademax) || 0;
                if (it.weightoverride) {
                    sumWeights += parseFloat(it.raw_weight) || 0;
                }
            });

            // Case A: Everything is automatic
            if (sumWeights <= 0 && sumMax > 0) {
                let runningSum = 0;
                visibleItems.forEach((it, idx) => {
                    const val = (it.grademax / sumMax) * 100;
                    if (idx === visibleItems.length - 1) {
                        it.weight = 100 - runningSum;
                    } else {
                        it.weight = Math.round(val * 100) / 100;
                        runningSum += it.weight;
                    }
                });
            } else {
                // Case B: Some manual weights override
                let effectiveSum = 0;
                visibleItems.forEach(it => {
                    const w = it.weightoverride ? (parseFloat(it.raw_weight) || 0) : 1.0;
                    it._tempWeight = w;
                    effectiveSum += w;
                });

                if (effectiveSum > 0) {
                    let runningSum = 0;
                    visibleItems.forEach((it, idx) => {
                        const val = (it._tempWeight / effectiveSum) * 100;
                        if (idx === visibleItems.length - 1) {
                            it.weight = 100 - runningSum;
                        } else {
                            it.weight = Math.round(val * 100) / 100;
                            runningSum += it.weight;
                        }
                        delete it._tempWeight;
                    });
                }
            }
            this.calculateTotal();
        },
        async addManualItem() {
            try {
                const params = new URLSearchParams();
                params.append('action', 'local_grupomakro_create_manual_grade_item');
                params.append('classid', this.classId);
                params.append('name', this.newItem.name);
                params.append('maxmark', this.newItem.maxmark);
                params.append('sesskey', this.getSesskey());

                const baseUrl = (M && M.cfg && M.cfg.wwwroot) ? M.cfg.wwwroot : '';
                const url = baseUrl + '/local/grupomakro_core/ajax.php';

                const response = await axios.post(url, params);

                if (response.data.status === 'success') {
                    this.showAddDialog = false;
                    this.newItem.name = '';
                    this.newItem.maxmark = 100;
                    this.showSnackbar('Ítem manual creado');
                    this.fetchStructure();
                } else {
                    this.showSnackbar(response.data.message || 'Error creando ítem', 'error');
                }
            } catch (error) {
                console.error(error);
                this.showSnackbar('Error creando ítem', 'error');
            }
        },
        async deleteItem(item) {
            if (!confirm(`¿Estás seguro de eliminar la columna "${item.itemname}"? Se perderán las notas asociadas.`)) return;

            try {
                const params = new URLSearchParams();
                params.append('action', 'local_grupomakro_delete_grade_item');
                params.append('itemid', item.id);
                params.append('sesskey', this.getSesskey());

                const baseUrl = (M && M.cfg && M.cfg.wwwroot) ? M.cfg.wwwroot : '';
                const url = baseUrl + '/local/grupomakro_core/ajax.php';

                const response = await axios.post(url, params);

                if (response.data.status === 'success') {
                    this.showSnackbar('Ítem eliminado');
                    this.fetchStructure();
                } else {
                    this.showSnackbar(response.data.message || 'Error eliminando', 'error');
                }
            } catch (error) {
                console.error(error);
                this.showSnackbar('Error eliminando', 'error');
            }
        },
        getSesskey() {
            if (window.Y && window.Y.config && window.Y.config.sesskey) return window.Y.config.sesskey;
            if (M && M.cfg && M.cfg.sesskey) return M.cfg.sesskey;
            if (window.userToken) return window.userToken;
            return '';
        },
        getTypeColor(item) {
            if (item.itemtype === 'manual') return 'purple';
            if (item.itemmodule === 'quiz') return 'pink';
            if (item.itemmodule === 'assign') return 'blue';
            return 'grey';
        },
        getTypeLabel(item) {
            if (item.itemtype === 'manual') return 'Manual';
            if (item.itemmodule === 'quiz') return 'Cuestionario';
            if (item.itemmodule === 'assign') return 'Tarea';
            return item.itemmodule || item.itemtype;
        },
        showSnackbar(text, color = 'success') {
            this.snackbar.message = text;
            this.snackbar.color = color;
            this.snackbar.show = true;
        }
    }
};

// Register component if needed, or Parent will register it locally
if (typeof Vue !== 'undefined') {
    Vue.component('gradebook-manager', GradebookManager);
}
