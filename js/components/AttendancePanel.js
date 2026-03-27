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

                <div v-if="loading" class="text-center py-5">
                    <v-progress-circular indeterminate color="primary"></v-progress-circular>
                    <div class="caption mt-2">Cargando sesiones...</div>
                </div>

                <div v-else-if="sessions.length === 0" class="text-center py-5 grey--text">
                    <v-icon size="48" color="grey lighten-2">mdi-calendar-blank</v-icon>
                    <p>No hay sesiones de asistencia programadas para hoy.</p>
                </div>

                <v-list v-else two-line>
                    <v-list-item v-for="session in sessions" :key="session.id" class="mb-2 elevation-1 rounded white">
                        <v-list-item-avatar color="primary" class="white--text font-weight-bold">
                            {{ session.date.substr(0, 2) }}
                        </v-list-item-avatar>

                        <v-list-item-content>
                            <v-list-item-title class="font-weight-bold">
                                {{ session.description || 'Sesion Regular' }}
                            </v-list-item-title>
                            <v-list-item-subtitle>
                                <v-icon x-small>mdi-clock-outline</v-icon> {{ session.time }}
                                <span class="mx-2">•</span>
                                <v-chip x-small :color="session.state === 'Pasada' ? 'grey' : 'green'" dark>{{ session.state }}</v-chip>
                            </v-list-item-subtitle>
                        </v-list-item-content>

                        <v-list-item-action>
                            <div class="d-flex">
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

            <v-dialog v-model="qrDialog" max-width="500px" persistent>
                <v-card v-if="currentQR" class="text-center pa-4">
                    <v-card-title class="justify-center text-h5">
                        Codigo de Asistencia
                    </v-card-title>
                    <v-card-text>
                        <div class="d-flex justify-center my-4" style="background: white; padding: 10px; display: inline-block;">
                            <div v-html="currentQR.html"></div>
                        </div>
                        <div class="text-h4 font-weight-bold primary--text" v-if="currentQR.password">
                            {{ currentQR.password }}
                        </div>
                        <div class="caption mt-2" v-if="currentQR.rotate">
                            El codigo se actualiza automaticamente.
                            <div class="mt-2" v-if="qrSecondsLeft > 0">
                                <v-progress-linear
                                    :value="(qrSecondsLeft / qrTotalSeconds) * 100"
                                    color="primary"
                                    height="8"
                                    rounded
                                ></v-progress-linear>
                                <div class="caption mt-1">Actualizando en {{ qrSecondsLeft }}s</div>
                            </div>
                        </div>
                    </v-card-text>
                    <v-card-actions class="justify-center">
                        <v-btn color="primary" text @click="closeQRDialog">Cerrar</v-btn>
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
            currentSession: null,
            loadingQR: false,
            qrTimer: null,
            qrTotalSeconds: 180,
            qrSecondsLeft: 0
        };
    },
    beforeDestroy() {
        if (this.qrTimer) {
            clearInterval(this.qrTimer);
            this.qrTimer = null;
        }
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

                const response = await axios.post(this.config.wwwroot + '/local/grupomakro_core/ajax.php', params);

                if (response.data && response.data.status === 'success') {
                    this.sessions = response.data.sessions;
                } else {
                    this.error = response.data.message || 'Error al cargar sesiones';
                }
            } catch (e) {
                console.error(e);
                this.error = 'Error de conexion';
            } finally {
                this.loading = false;
            }
        },
        async showQR(session) {
            this.currentSession = session;
            this.loadingQR = true;
            try {
                const params = new URLSearchParams();
                params.append('action', 'local_grupomakro_get_session_qr');
                params.append('sessionid', session.id);

                const response = await axios.post(this.config.wwwroot + '/local/grupomakro_core/ajax.php', params);

                if (response.data && response.data.status === 'success') {
                    this.currentQR = response.data;
                    this.currentSession = session;
                    this.qrDialog = true;
                    if (this.currentQR.rotate) {
                        this.startQRRotation(this.currentQR.rotate_interval);
                    } else if (this.qrTimer) {
                        clearInterval(this.qrTimer);
                        this.qrTimer = null;
                    }
                } else {
                    alert(response.data.message || 'Error al obtener QR');
                }
            } catch (e) {
                console.error(e);
                alert('Error de conexion al obtener QR');
            } finally {
                this.loadingQR = false;
            }
        },
        startQRRotation(intervalSeconds = 180) {
            if (this.qrTimer) {
                clearInterval(this.qrTimer);
            }

            const parsed = parseInt(intervalSeconds, 10);
            this.qrTotalSeconds = (!isNaN(parsed) && parsed > 1) ? parsed : 180;
            this.qrSecondsLeft = this.qrTotalSeconds;

            this.qrTimer = setInterval(() => {
                this.qrSecondsLeft--;
                if (this.qrSecondsLeft <= 0) {
                    clearInterval(this.qrTimer);
                    this.qrTimer = null;
                    if (this.qrDialog && this.currentSession) {
                        this.showQR(this.currentSession);
                    }
                }
            }, 1000);
        },
        closeQRDialog() {
            this.qrDialog = false;
            this.currentSession = null;
            if (this.qrTimer) {
                clearInterval(this.qrTimer);
                this.qrTimer = null;
            }
        }
    }
});
