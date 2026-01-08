// AttendancePanel.js
Vue.component('attendance-panel', {
    props: ['classId', 'config'],
    template: `
        <v-card flat class="ma-0 pa-0">
            <v-card-text>
                <div class="d-flex justify-space-between align-center mb-4">
                    <h2 class="text-h6">Control de Asistencia</h2>
                    <v-btn small text color="primary" @click="fetchSessions" :loading="loading">
                        <v-icon left>mdi-refresh</v-icon> Actualizar
                    </v-btn>
                </div>

                <v-alert v-if="error" type="error" dense dismissible>{{ error }}</v-alert>

                <!-- Loading State -->
                <div v-if="loading" class="text-center py-5">
                    <v-progress-circular indeterminate color="primary"></v-progress-circular>
                    <div class="caption mt-2">Cargando sesiones...</div>
                </div>

                <!-- Empty State -->
                <div v-else-if="sessions.length === 0" class="text-center py-5 grey--text">
                    <v-icon size="48" color="grey lighten-2">mdi-calendar-blank</v-icon>
                    <p>No hay sesiones de asistencia programadas para hoy.</p>
                </div>

                <!-- Sessions List -->
                <v-list v-else two-line>
                    <v-list-item v-for="session in sessions" :key="session.id" class="mb-2 elevation-1 rounded white">
                        <v-list-item-avatar color="primary" class="white--text font-weight-bold">
                            {{ session.date.substr(0, 2) }}
                        </v-list-item-avatar>
                        
                        <v-list-item-content>
                            <v-list-item-title class="font-weight-bold">
                                {{ session.description || 'Sesión Regular' }}
                            </v-list-item-title>
                            <v-list-item-subtitle>
                                <v-icon x-small>mdi-clock-outline</v-icon> {{ session.time }}
                                <span class="mx-2">•</span>
                                <v-chip x-small :color="session.state === 'Pasada' ? 'grey' : 'green'" dark>{{ session.state }}</v-chip>
                            </v-list-item-subtitle>
                        </v-list-item-content>

                        <v-list-item-action>
                            <div class="d-flex">
                                <!-- Project QR Button -->
                                <v-btn 
                                    v-if="session.has_qr"
                                    color="secondary" 
                                    dark 
                                    small 
                                    class="mr-2"
                                    @click="showQR(session)"
                                >
                                    <v-icon left>mdi-qrcode</v-icon> Proyectar QR
                                </v-btn>
                                
                                <!-- Go to Moodle (Manual Mark) -->
                                <v-btn 
                                    icon 
                                    :href="config.wwwroot + '/mod/attendance/take.php?id=' + session.id" 
                                    target="_blank"
                                    title="Pasar lista manual"
                                >
                                    <v-icon color="grey">mdi-open-in-new</v-icon>
                                </v-btn>
                            </div>
                        </v-list-item-action>
                    </v-list-item>
                </v-list>
            </v-card-text>

            <!-- QR Dialog -->
            <v-dialog v-model="qrDialog" max-width="500px" persistent>
                <v-card v-if="currentQR" class="text-center pa-4">
                    <v-card-title class="justify-center text-h5">
                        Código de Asistencia
                    </v-card-title>
                    <v-card-text>
                        <div class="d-flex justify-center my-4" style="background: white; padding: 10px; display: inline-block;">
                            <div v-html="currentQR.html"></div>
                        </div>
                        <div class="text-h4 font-weight-bold primary--text" v-if="currentQR.password">
                            {{ currentQR.password }}
                        </div>
                        <div class="caption mt-2" v-if="currentQR.rotate">
                            El código rota automáticamente.
                        </div>
                    </v-card-text>
                    <v-card-actions class="justify-center">
                        <v-btn color="primary" text @click="qrDialog = false">Cerrar</v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>
        </v-card>
    `,
    data() {
        return {
            loading: false,
            sessions: [],
            error: null,
            qrDialog: false,
            currentQR: null,
            loadingQR: false // internal loading for QR fetch
        };
    },
    mounted() {
        this.fetchSessions();
    },
    methods: {
        async fetchSessions() {
            this.loading = true;
            this.error = null;
            try {
                const params = new URLSearchParams();
                params.append('action', 'local_grupomakro_get_attendance_sessions');
                params.append('classid', this.classId);
                // params.append('sesskey', this.config.sesskey); // Usually needed

                const response = await axios.post(this.config.wwwroot + '/local/grupomakro_core/ajax.php', params);

                if (response.data && response.data.status === 'success') {
                    this.sessions = response.data.sessions;
                } else {
                    this.error = response.data.message || 'Error al cargar sesiones';
                }
            } catch (e) {
                console.error(e);
                this.error = 'Error de conexión';
            } finally {
                this.loading = false;
            }
        },
        async showQR(session) {
            this.loadingQR = true; // Could show a loader toast
            try {
                const params = new URLSearchParams();
                params.append('action', 'local_grupomakro_get_session_qr');
                params.append('sessionid', session.id);

                const response = await axios.post(this.config.wwwroot + '/local/grupomakro_core/ajax.php', params);

                if (response.data && response.data.status === 'success') {
                    this.currentQR = response.data;
                    this.qrDialog = true;
                } else {
                    alert(response.data.message || 'Error al obtener QR');
                }
            } catch (e) {
                console.error(e);
                alert('Error de conexión al obtener QR');
            } finally {
                this.loadingQR = false;
            }
        }
    }
});
