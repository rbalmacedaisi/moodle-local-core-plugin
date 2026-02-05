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
                                <div class="d-flex align-center">
                                    <v-text-field
                                        v-model.number="item.weight"
                                        type="number"
                                        dense
                                        outlined
                                        hide-details
                                        style="max-width: 80px"
                                        class="mr-1"
                                        @input="calculateTotal"
                                        :disabled="item.locked"
                                    ></v-text-field>
                                    <span class="grey--text">%</span>
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
                                <v-btn v-if="item.itemtype === 'manual'" icon color="red" small @click="deleteItem(item)">
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
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_get_gradebook_structure',
                    classid: this.classId,
                    sesskey: M.cfg.sesskey
                });

                if (response.data.status === 'success') {
                    // Convert weights to proper percentages (0.1 -> 10) if Moodle stores them as 0-1, 
                    // BUT Moodle aggregationcoef usually depends on aggregation method.
                    // Assuming "Weighted Mean" where 1.0 = 100% or 100.0 = 100%. 
                    // Let's assume input comes as 0.25 (25%) based on common Moodle behavior, 
                    // or user enters 25 directly? 
                    // Standard Moodle 'aggregationcoef' for 'Simple Weighted Mean' is usually 1.0 (auto) or explicit.
                    // For 'Weighted Mean', it is the weight factor.
                    // Let's try to detect scale. If sum > 1.05 and < 105, assume 100 based?

                    // Actually, let's treat input as "Raw Weight" and validate against 100.
                    // We will save what the user enters.

                    this.items = response.data.items.map(i => ({
                        ...i,
                        weight: parseFloat(i.weight) // Keep original from server
                    }));
                    this.calculateTotal();
                }
            } catch (error) {
                console.error(error);
                this.showSnackbar('Error cargando estructura', 'error');
            } finally {
                this.loading = false;
            }
        },
        calculateTotal() {
            this.totalWeight = this.items.reduce((sum, item) => sum + (parseFloat(item.weight) || 0), 0);
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
        async saveWeights() {
            this.saving = true;
            try {
                // Determine updates
                const updates = this.items.map(i => ({
                    id: i.id,
                    weight: i.weight
                }));

                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_update_grade_weights',
                    classid: this.classId,
                    sesskey: M.cfg.sesskey,
                    weights: JSON.stringify(updates)
                });

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
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_create_manual_grade_item',
                    classid: this.classId,
                    sesskey: M.cfg.sesskey,
                    name: this.newItem.name,
                    maxmark: this.newItem.maxmark
                });

                if (response.data.status === 'success') {
                    this.showAddDialog = false;
                    this.newItem.name = '';
                    this.newItem.maxmark = 100;
                    this.showSnackbar('Ítem manual creado');
                    this.fetchStructure(); // Reload list
                }
            } catch (error) {
                console.error(error);
                this.showSnackbar('Error creando ítem', 'error');
            }
        },
        async deleteItem(item) {
            if (!confirm(`¿Estás seguro de eliminar la columna "${item.itemname}"? Se perderán las notas asociadas.`)) return;

            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_delete_grade_item',
                    itemid: item.id,
                    sesskey: M.cfg.sesskey
                });

                if (response.data.status === 'success') {
                    this.showSnackbar('Ítem eliminado');
                    this.fetchStructure();
                }
            } catch (error) {
                console.error(error);
                this.showSnackbar('Error eliminando', 'error');
            }
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
