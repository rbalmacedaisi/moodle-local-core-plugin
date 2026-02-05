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
        <v-dialog :value="value" @input="$emit('input', $event)" max-width="900px" scrollable>
            <v-card class="d-flex flex-column grey lighten-5" style="min-height: 600px;">
                <v-toolbar color="primary" dark dense flat>
                    <v-toolbar-title>Gestor de Calificaciones</v-toolbar-title>
                    <v-spacer></v-spacer>
                    <v-btn icon dark @click="close">
                        <v-icon>mdi-close</v-icon>
                    </v-btn>
                </v-toolbar>

                <div class="d-flex align-center pa-2 white elevation-1">
                     <v-spacer></v-spacer>
                     <v-btn text color="primary" @click="saveWeights" :loading="saving">
                        <v-icon left>mdi-content-save</v-icon>
                        Guardar Cambios
                    </v-btn>
                </div>

                <v-card-text class="pa-4 flex-grow-1 overflow-y-auto" style="height: 500px;">
                    <v-alert v-if="totalWeight !== 100" type="error" text outlined class="mb-4" dense>
                        <strong>Atención:</strong> La suma de las ponderaciones es {{ totalWeight.toFixed(2) }}%. Debe ser 100%.
                    </v-alert>
                    <v-alert v-else type="success" text outlined class="mb-4" dense>
                        Ponderación correcta (100%).
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
                            <!-- Weight Input Slot -->
                            <template v-slot:item.weight="{ item }">
                                <div class="d-flex align-center justify-center">
                                    <v-text-field
                                        v-model.number="item.weight"
                                        type="number"
                                        step="0.01"
                                        dense
                                        outlined
                                        hide-details
                                        style="max-width: 100px; font-size: 14px;"
                                        class="mr-2 centered-input"
                                        @input="calculateTotal"
                                        :disabled="item.locked"
                                    ></v-text-field>
                                    <span class="grey--text subheading font-weight-bold">%</span>
                                </div>
                            </template>

                            <!-- Type Badge Slot -->
                            <template v-slot:item.itemtype="{ item }">
                                <v-chip small :color="getTypeColor(item)" dark label class="font-weight-bold">
                                    {{ getTypeLabel(item) }}
                                </v-chip>
                            </template>

                            <!-- Actions Slot (Delete Manual Items) -->
                            <template v-slot:item.actions="{ item }">
                                <v-btn v-if="item.itemtype === 'manual' && !item.is_protected" icon color="red" small @click="deleteItem(item)">
                                    <v-icon small>mdi-delete</v-icon>
                                </v-btn>
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
            loading: false,
            saving: false,
            items: [],
            totalWeight: 0,
            showAddDialog: false,
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
                { text: 'Tipo', value: 'itemtype', sortable: false, width: '100px' },
                { text: 'Max', value: 'grademax', align: 'center', sortable: false, width: '70px' },
                { text: 'Ponderación', value: 'weight', align: 'center', sortable: false, width: '120px' },
                { text: '', value: 'actions', align: 'end', sortable: false, width: '50px' }
            ]
        };
    },
    mounted() {
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
        close() {
            this.$emit('input', false);
            this.$emit('closed'); // Trigger refresh in parent
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
                        weight: parseFloat(i.weight), // Keep original
                        locked: (i.locked == 1 || i.locked === '1' || i.locked === true), // Strict boolean cast
                        // "Nota Final Integrada" or specific critical items should not be deletable even if manual
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
                    weight: i.weight
                }));

                const params = new URLSearchParams();
                params.append('action', 'local_grupomakro_update_grade_weights');
                params.append('classid', this.classId);
                params.append('weights', JSON.stringify(updates));
                params.append('sesskey', this.getSesskey());

                const baseUrl = (M && M.cfg && M.cfg.wwwroot) ? M.cfg.wwwroot : '';
                const url = baseUrl + '/local/grupomakro_core/ajax.php';

                const response = await axios.post(url, params);

                if (response.data.status === 'success') {
                    this.showSnackbar('Cambios guardados correctamente');
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
