/**
 * Import Progress Component
 * Handles batched processing of grades via AJAX
 */

Vue.component('import-progress', {
    props: {
        filename: { type: String, required: true },
        totalRows: { type: Number, required: true },
        chunkSize: { type: Number, default: 50 }
    },
    data() {
        return {
            offset: 0,
            processedCount: 0,
            isProcessing: false,
            results: [],
            error: null,
            finished: false,
            logs: []
        };
    },
    computed: {
        progress() {
            if (this.totalRows === 0) return 100;
            return Math.min(Math.round((this.processedCount / this.totalRows) * 100), 100);
        },
        successCount() {
            return this.results.filter(r => r.status === 'OK' || r.status === 'success').length;
        },
        errorCount() {
            return this.results.filter(r => r.status === 'ERROR' || r.status === 'error').length;
        }
    },
    methods: {
        async startImport() {
            this.isProcessing = true;
            this.offset = 0;
            this.processedCount = 0;
            this.results = [];
            this.logs = [];
            this.finished = false;

            await this.processNextChunk();
        },
        async processNextChunk() {
            if (!this.isProcessing) return;

            try {
                const response = await axios.post('../ajax.php', new URLSearchParams({
                    action: 'local_grupomakro_import_grade_chunk',
                    filename: this.filename,
                    offset: this.offset,
                    limit: this.chunkSize
                }));

                const data = response.data;
                if (data.status === 'success') {
                    // Update results
                    this.results = [...this.results, ...data.results];
                    this.processedCount += data.progress.processed;
                    this.offset += data.progress.processed;

                    // Add current results to logs for real-time feedback
                    data.results.forEach(r => {
                        this.logs.unshift({
                            time: new Date().toLocaleTimeString(),
                            msg: `Fila ${r.row}: ${r.username} (${r.course}) -> ${r.status}${r.error ? ': ' + r.error : ''}`,
                            type: r.status === 'OK' ? 'success' : 'error'
                        });
                        // Keep log manageable
                        if (this.logs.length > 50) this.logs.pop();
                    });

                    if (data.progress.finished) {
                        this.finishImport();
                    } else {
                        // Small delay to prevent Hammering the server too hard
                        setTimeout(() => this.processNextChunk(), 500);
                    }
                } else {
                    throw new Exception(data.message || 'Error desconocido del servidor');
                }
            } catch (e) {
                this.error = "Error durante el proceso: " + (e.response?.data?.message || e.message);
                this.isProcessing = false;
            }
        },
        async finishImport() {
            this.isProcessing = false;
            this.finished = true;

            // Cleanup temp file
            try {
                await axios.post('../ajax.php', new URLSearchParams({
                    action: 'local_grupomakro_import_grade_cleanup',
                    filename: this.filename
                }));
            } catch (e) {
                console.error("No se pudo limpiar el archivo temporal", e);
            }
        }
    },
    mounted() {
        this.startImport();
    },
    template: `
    <v-card class="pa-4 mt-4" elevation="2">
        <v-card-title class="headline">
            <v-icon left color="primary">mdi-file-import</v-icon>
            Procesando {{ totalRows }} registros
        </v-card-title>
        
        <v-card-text>
            <div class="mb-4">
                <v-progress-linear
                    v-model="progress"
                    height="25"
                    color="primary"
                    striped
                    rounded
                    active
                >
                    <template v-slot:default="{ value }">
                        <strong>{{ Math.ceil(value) }}% ({{ processedCount }} / {{ totalRows }})</strong>
                    </template>
                </v-progress-linear>
            </div>

            <v-row v-if="finished || isProcessing">
                <v-col cols="4">
                    <v-alert dense outlined type="info">Total: {{ totalRows }}</v-alert>
                </v-col>
                <v-col cols="4">
                    <v-alert dense outlined type="success">Éxitos: {{ successCount }}</v-alert>
                </v-col>
                <v-col cols="4">
                    <v-alert dense outlined type="error">Errores: {{ errorCount }}</v-alert>
                </v-col>
            </v-row>

            <v-alert v-if="error" type="error" border="left" class="mt-4">
                {{ error }}
                <v-btn small text @click="startImport" class="ml-4">Reintentar desde el fallo</v-btn>
            </v-alert>

            <v-expand-transition>
                <div v-if="finished" class="text-center mt-6">
                    <v-icon size="100" color="success">mdi-check-circle-outline</v-icon>
                    <h2 class="success--text mt-2">¡Sincronización Finalizada!</h2>
                    <p class="grey--text">Todos los registros del archivo han sido procesados.</p>
                    <v-btn color="primary" href="grade_report.php" large class="mt-2">
                        <v-icon left>mdi-file-search</v-icon>
                        Ver Reporte de Discrepancias
                    </v-btn>
                </div>
            </v-expand-transition>

            <div class="mt-6">
                <h3 class="subtitle-1 mb-2">Registro de actividad reciente:</h3>
                <v-sheet elevation="1" class="pa-2 grey lighten-4" style="max-height: 300px; overflow-y: auto; font-family: monospace; font-size: 0.85rem;">
                    <div v-for="(log, i) in logs" :key="i" :class="log.type + '--text mb-1'">
                        [{{ log.time }}] {{ log.msg }}
                    </div>
                </v-sheet>
            </div>
        </v-card-text>
    </v-card>
    `
});

// Polyfill/Helper for initialization
window.initImportProgress = function () {
    new Vue({
        el: '#import-progress-app',
        vuetify: new Vuetify(),
    });
};
