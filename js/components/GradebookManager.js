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
                    <v-btn text dark small class="mr-2" @click="showHelp = true">
                        <v-icon left small>mdi-help-circle</v-icon> Ayuda
                    </v-btn>
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
                        <span :class="totalWeight === 100 ? 'success--text' : 'warning--text'" class="font-weight-bold ml-1">
                            {{ totalWeight.toFixed(2) }}%
                        </span>
                     </div>
                     <v-spacer></v-spacer>
                     <v-btn text color="primary" @click="saveWeights" :loading="saving">
                        <v-icon left>mdi-content-save</v-icon>
                        Guardar Cambios
                    </v-btn>
                </div>

                <v-card-text class="pa-4 flex-grow-1 overflow-y-auto" style="height: 500px;">
                    <v-alert v-if="Math.abs(totalWeight - 100) > 0.01" type="warning" text outlined class="mb-4" dense>
                        <strong>Atención:</strong> La suma de las ponderaciones es {{ totalWeight.toFixed(2) }}%. Puedes guardar de todas formas; ajusta los porcentajes cuando tengas todas las actividades creadas.
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
                                        <td class="text-center">{{ item.grademax }}</td>

                                        <td>
                                            <div class="d-flex align-center">
                                                <v-btn 
                                                    icon 
                                                    x-small 
                                                    :color="item.is_locked ? 'orange darken-2' : 'grey lighten-1'" 
                                                    @click="item.is_locked = !item.is_locked"
                                                    class="mr-1"
                                                >
                                                    <v-icon x-small>{{ item.is_locked ? 'mdi-lock' : 'mdi-lock-open-outline' }}</v-icon>
                                                </v-btn>
                                                <v-text-field
                                                    v-model.number="item.percentage"
                                                    type="number"
                                                    step="0.1"
                                                    dense
                                                    outlined
                                                    hide-details
                                                    style="max-width: 80px;"
                                                    :disabled="item.is_locked"
                                                    @input="onPercentageInput(item)"
                                                ></v-text-field>
                                                <span class="ml-1 grey--text">%</span>
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
                            <v-text-field v-model="newItem.name" label="Nombre (ej. Participación)" outlined dense autofocus hide-details class="mb-2"></v-text-field>
                            <div class="caption grey--text">La nota máxima se establecerá automáticamente en 100.</div>
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

                <!-- Help Dialog -->
                <v-dialog v-model="showHelp" max-width="600px">
                    <v-card>
                        <v-toolbar color="blue darken-3" dark dense flat>
                            <v-toolbar-title class="subtitle-1">
                                <v-icon left>mdi-help-circle</v-icon> Guía de Conceptos
                            </v-toolbar-title>
                            <v-spacer></v-spacer>
                            <v-btn icon @click="showHelp = false"><v-icon>mdi-close</v-icon></v-btn>
                        </v-toolbar>
                        <v-card-text class="pa-4">
                            <div class="mb-4">
                                <div class="font-weight-bold blue--text text--darken-4">Calificación Máxima (Max):</div>
                                <div class="grey--text text--darken-3">Es la escala de la actividad (unificada en 100). Indica el tope de puntos que un alumno puede obtener en esa tarea específica.</div>
                            </div>
                            <div class="mb-4">
                                <div class="font-weight-bold blue--text text--darken-4">Ponderación (%):</div>
                                <div class="grey--text text--darken-3">Define cuánto vale la actividad sobre los 100 puntos totales del curso. Si cambias un porcentaje, las demás se ajustarán solas para que la suma sea siempre 100%.</div>
                            </div>
                        </v-card-text>
                        <v-divider></v-divider>
                        <v-card-actions>
                            <v-spacer></v-spacer>
                            <v-btn color="blue darken-3" text @click="showHelp = false">Entendido</v-btn>
                        </v-card-actions>
                    </v-card>
                </v-dialog>
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
            showHelp: false,
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
                { text: 'Ponderación (%)', value: 'percentage', align: 'start', sortable: false, width: '150px' },
                { text: '', value: 'actions', align: 'end', sortable: false, width: '50px' }
            ]
        };
    },
    mounted() {
        this.injectStyles();
        if (this.value) {
            this.fetchStructure();
        }
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
                    this.items = (response.data.items || []).map(i => ({
                        ...i,
                        weight: parseFloat(i.weight) || 0,
                        percentage: parseFloat(parseFloat(i.percentage || 0).toFixed(2)),
                        hidden: parseInt(i.hidden) || 0,
                        is_locked: false,
                        locked: (i.locked == 1 || i.locked === '1' || i.locked === true),
                        is_protected: (i.itemname && i.itemname.includes('Nota Final Integrada'))
                    }));
                    if (this.items.length === 0 && this.classId) {
                        const fallbackItems = await this.fetchStructureFromGrades();
                        if (fallbackItems.length > 0) {
                            this.items = fallbackItems;
                        }
                    }
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
        async fetchStructureFromGrades() {
            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_get_class_grades',
                    args: { classid: this.classId },
                    sesskey: (window.Y && window.Y.config && window.Y.config.sesskey) ? window.Y.config.sesskey : this.getSesskey()
                });

                if (!response.data || response.data.status !== 'success' || !response.data.data) {
                    return [];
                }

                const cols = Array.isArray(response.data.data.columns) ? response.data.data.columns : [];
                const scoped = cols.filter(c => c && c.itemtype && c.itemtype !== 'course' && c.itemtype !== 'category');

                return scoped.map(c => {
                    const title = c.title || ('item ' + c.id);
                    const moduleGuess = /asistencia/i.test(title) ? 'attendance' : '';
                    return {
                        id: c.id,
                        itemname: title,
                        itemtype: c.itemtype || 'mod',
                        itemmodule: moduleGuess,
                        weight: parseFloat(c.weight) || 0,
                        percentage: 0,
                        grademax: parseFloat(c.max_grade) || 100,
                        hidden: 0,
                        locked: 0,
                        is_locked: false,
                        aggregationcoef2: 0,
                        is_natural: false,
                        is_protected: (title && title.includes('Nota Final Integrada'))
                    };
                });
            } catch (e) {
                console.warn('GradebookManager fallback from class_grades failed', e);
                return [];
            }
        },
        calculateTotal() {
            const lockedItems   = this.items.filter(i => i.is_locked);
            const unlockedItems = this.items.filter(i => !i.is_locked);

            if (lockedItems.length === 0) {
                // No locked items: classic normalization — all items share 100%
                const sumWeights = unlockedItems.reduce((sum, i) => sum + (parseFloat(i.weight) || 0), 0);

                if (sumWeights <= 0) {
                    this.items.forEach(item => { item.percentage = 0; });
                    this.totalWeight = 0;
                    return;
                }

                let runningTotal = 0;
                let lastWeightyItem = null;

                unlockedItems.forEach(item => {
                    const w = parseFloat(item.weight) || 0;
                    if (w > 0) {
                        item.percentage = parseFloat(((w / sumWeights) * 100).toFixed(2));
                        runningTotal += item.percentage;
                        lastWeightyItem = item;
                    } else {
                        item.percentage = 0;
                    }
                });

                // Fix floating-point rounding on the last non-zero item
                if (lastWeightyItem && Math.abs(runningTotal - 100) > 0.001) {
                    lastWeightyItem.percentage = parseFloat((lastWeightyItem.percentage + (100 - runningTotal)).toFixed(2));
                }

                this.totalWeight = 100;
            } else {
                // Locked items exist: their percentages are NEVER changed here.
                // Distribute the remaining budget proportionally among unlocked items.
                const lockedSum = lockedItems.reduce((sum, i) => sum + (parseFloat(i.percentage) || 0), 0);
                const budget    = parseFloat((100 - lockedSum).toFixed(2));

                const unlockedWeightSum = unlockedItems.reduce((sum, i) => sum + (parseFloat(i.weight) || 0), 0);

                if (budget > 0 && unlockedWeightSum > 0) {
                    let runningTotal = 0;
                    let lastWeightyItem = null;

                    unlockedItems.forEach(item => {
                        const w = parseFloat(item.weight) || 0;
                        if (w > 0) {
                            item.percentage = parseFloat(((w / unlockedWeightSum) * budget).toFixed(2));
                            runningTotal += item.percentage;
                            lastWeightyItem = item;
                        } else {
                            item.percentage = 0;
                        }
                    });

                    // Fix rounding on the last unlocked item with weight
                    if (lastWeightyItem && Math.abs(runningTotal - budget) > 0.001) {
                        lastWeightyItem.percentage = parseFloat((lastWeightyItem.percentage + (budget - runningTotal)).toFixed(2));
                    }
                } else {
                    // Locked items consume 100% (or more), or no unlocked item has a weight yet
                    unlockedItems.forEach(item => { item.percentage = 0; });
                }

                // Actual total may differ from 100 — that's shown as a warning
                this.totalWeight = parseFloat(
                    this.items.reduce((sum, i) => sum + (parseFloat(i.percentage) || 0), 0).toFixed(2)
                );
            }
        },
        onPercentageInput(item) {
            const newPercentage = parseFloat(item.percentage) || 0;

            // Sync weight so calculateTotal() can use it for proportional distribution
            item.weight = newPercentage;

            const others       = this.items.filter(i => i.id !== item.id && !i.is_locked);
            const lockedOthers = this.items.filter(i => i.id !== item.id && i.is_locked);
            const lockedSum    = lockedOthers.reduce((sum, i) => sum + (parseFloat(i.percentage) || 0), 0);

            // Budget left for the other (passive) unlocked items
            const targetRemainingWeight = 100 - newPercentage - lockedSum;

            if (others.length > 0 && targetRemainingWeight >= 0) {
                const currentOthersWeightSum = others.reduce((sum, i) => sum + (parseFloat(i.weight) || 0), 0);

                if (currentOthersWeightSum > 0) {
                    const scale = targetRemainingWeight / currentOthersWeightSum;
                    others.forEach(o => {
                        o.weight = parseFloat(((parseFloat(o.weight) || 0) * scale).toFixed(2));
                    });
                } else {
                    const equalShare = others.length > 0 ? targetRemainingWeight / others.length : 0;
                    others.forEach(o => { o.weight = parseFloat(equalShare.toFixed(2)); });
                }

                // Recalculate percentages properly (locked items won't be touched)
                this.calculateTotal();
            } else {
                // Budget negative (locked items + this item exceed 100%) or no other unlocked items.
                // Don't normalize; just reflect the new total so the warning appears.
                this.totalWeight = parseFloat(
                    this.items.reduce((sum, i) => sum + (parseFloat(i.percentage) || 0), 0).toFixed(2)
                );
            }
        },
        async saveWeights() {
            this.saving = true;
            try {
                const updates = this.items.map(i => ({
                    id: i.id,
                    weight: i.weight,
                    hidden: i.hidden
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
