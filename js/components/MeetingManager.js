/**
 * Meeting Manager Component for Admins
 */
const MeetingManager = {
    template: `
        <v-container fluid class="pa-4">
            <v-card class="mb-4">
                <v-card-title class="headline blue white--text">
                    <v-icon left dark>mdi-video-account</v-icon>
                    Sesiones de BigBlueButton (Invitados)
                    <v-spacer></v-spacer>
                    <v-btn color="white" light depressed @click="openCreateDialog">
                        <v-icon left>mdi-plus</v-icon> Crear Nueva Sesión
                    </v-btn>
                </v-card-title>
                
                <v-card-text class="pt-4">
                    <v-data-table
                        :headers="headers"
                        :items="meetings"
                        :loading="loading"
                        no-data-text="No hay sesiones de invitados creadas."
                    >
                        <template v-slot:item.start_time="{ item }">
                            {{ formatDate(item.start_time) }}
                        </template>
                        <template v-slot:item.guest_url="{ item }">
                            <v-btn small text color="primary" @click="copyToClipboard(item.guest_url)">
                                <v-icon left small>mdi-content-copy</v-icon> Copiar Link
                            </v-btn>
                        </template>
                         <template v-slot:item.actions="{ item }">
                            <v-btn icon small color="indigo" title="Ver grabaciones" @click="openRecordings(item)">
                                <v-icon>mdi-play-box-multiple</v-icon>
                            </v-btn>
                            <v-btn icon small color="red" title="Eliminar sesión" @click="deleteMeeting(item)">
                                <v-icon>mdi-delete</v-icon>
                            </v-btn>
                        </template>
                    </v-data-table>
                </v-card-text>
            </v-card>

            <!-- Create Dialog -->
            <v-dialog v-model="createDialog" max-width="500px">
                <v-card>
                    <v-card-title>Crear Sesión Pública</v-card-title>
                    <v-card-text>
                        <v-form v-model="valid">
                            <v-text-field
                                v-model="newMeeting.name"
                                label="Nombre de la Sesión"
                                :rules="[v => !!v || 'Nombre es requerido']"
                                required
                                outlined
                            ></v-text-field>
                            <v-textarea
                                v-model="newMeeting.intro"
                                label="Descripción (Opcional)"
                                outlined
                                rows="3"
                            ></v-textarea>
                            <div class="caption grey--text mb-2">
                                * La sesión se creará en la página principal del sitio.
                            </div>
                        </v-form>
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn text @click="createDialog = false">Cancelar</v-btn>
                        <v-btn color="primary" :loading="saving" :disabled="!valid" @click="createMeeting">Crear</v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>

            <!-- Recordings Dialog -->
            <v-dialog v-model="recordingsDialog" max-width="720px">
                <v-card>
                    <v-card-title class="indigo white--text">
                        <v-icon left dark>mdi-play-box-multiple</v-icon>
                        Grabaciones — {{ recordingsMeetingName }}
                    </v-card-title>
                    <v-card-text class="pt-4">
                        <div v-if="recordingsLoading" class="text-center py-6">
                            <v-progress-circular indeterminate color="indigo"></v-progress-circular>
                            <div class="caption grey--text mt-2">Cargando grabaciones...</div>
                        </div>
                        <div v-else-if="recordings.length === 0" class="text-center py-6 grey--text">
                            <v-icon size="48" color="grey lighten-1">mdi-video-off-outline</v-icon>
                            <div class="mt-2">No hay grabaciones disponibles para esta sesión.</div>
                            <div class="caption">Las grabaciones aparecen luego de finalizar la reunión y ser procesadas por el servidor.</div>
                        </div>
                        <v-list v-else two-line>
                            <v-list-item v-for="rec in recordings" :key="rec.id">
                                <v-list-item-content>
                                    <v-list-item-title>{{ rec.name }}</v-list-item-title>
                                    <v-list-item-subtitle>
                                        {{ formatDate(rec.date) }}
                                        <span v-if="rec.duration"> · {{ rec.duration }} min</span>
                                        <v-chip v-if="!rec.published" x-small color="orange" dark class="ml-2">No publicada</v-chip>
                                    </v-list-item-subtitle>
                                </v-list-item-content>
                                <v-list-item-action>
                                    <div>
                                        <v-btn
                                            v-for="pb in rec.playbacks"
                                            :key="pb.type"
                                            small text color="indigo"
                                            :href="pb.url" target="_blank" rel="noopener"
                                            class="ml-1"
                                        >
                                            <v-icon left small>mdi-play-circle</v-icon> Ver {{ playbackLabel(pb.type) }}
                                        </v-btn>
                                    </div>
                                </v-list-item-action>
                            </v-list-item>
                        </v-list>
                    </v-card-text>
                    <v-card-actions>
                        <v-spacer></v-spacer>
                        <v-btn text @click="recordingsDialog = false">Cerrar</v-btn>
                    </v-card-actions>
                </v-card>
            </v-dialog>

             <v-snackbar v-model="snackbar" :timeout="3000" :color="snackbarColor">
                {{ snackbarText }}
                <template v-slot:action="{ attrs }">
                    <v-btn text v-bind="attrs" @click="snackbar = false">Cerrar</v-btn>
                </template>
            </v-snackbar>
        </v-container>
    `,
    data() {
        return {
            loading: false,
            meetings: [],
            headers: [
                { text: 'Nombre de la Sesión', value: 'name' },
                { text: 'Fecha de Creación', value: 'timecreated' },
                { text: 'Link de Invitado', value: 'guest_url', sortable: false },
                { text: 'Acciones', value: 'actions', sortable: false, align: 'end' }
            ],
            createDialog: false,
            recordingsDialog: false,
            recordingsLoading: false,
            recordings: [],
            recordingsMeetingName: '',
            valid: false,
            saving: false,
            newMeeting: {
                name: '',
                intro: ''
            },
            snackbar: false,
            snackbarText: '',
            snackbarColor: 'success'
        };
    },
    mounted() {
        this.fetchMeetings();
    },
    methods: {
        openCreateDialog() {
            this.createDialog = true;
        },
        playbackLabel(type) {
            const labels = {
                presentation: '',
                video: '(video)',
                podcast: '(audio)',
                screenshare: '(pantalla)',
                notes: '(notas)',
                statistics: '(estadísticas)'
            };
            return labels[type] !== undefined ? labels[type] : '(' + type + ')';
        },
        async openRecordings(item) {
            this.recordingsMeetingName = item.name;
            this.recordings = [];
            this.recordingsDialog = true;
            this.recordingsLoading = true;
            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_get_meeting_recordings',
                    cmid: item.cmid,
                    ...window.wsStaticParams
                });
                if (response.data.status === 'success') {
                    this.recordings = response.data.recordings || [];
                } else {
                    this.showSnackbar(response.data.message || 'No se pudieron cargar las grabaciones', 'error');
                }
            } catch (error) {
                console.error('Error fetching recordings', error);
                this.showSnackbar('Error al cargar grabaciones', 'error');
            } finally {
                this.recordingsLoading = false;
            }
        },
        formatDate(timestamp) {
            if (!timestamp) return '-';
            return new Date(timestamp * 1000).toLocaleString();
        },
        copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                this.showSnackbar('Enlace copiado al portapapeles', 'success');
            }).catch(err => {
                this.showSnackbar('Error al copiar enlace', 'error');
            });
        },
        showSnackbar(text, color) {
            this.snackbarText = text;
            this.snackbarColor = color;
            this.snackbar = true;
        },
        async fetchMeetings() {
            this.loading = true;
            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_get_guest_meetings',
                    ...window.wsStaticParams
                });
                if (response.data.status === 'success') {
                    this.meetings = response.data.meetings;
                }
            } catch (error) {
                console.error("Error fetching meetings", error);
                this.showSnackbar('Error al cargar sesiones', 'error');
            } finally {
                this.loading = false;
            }
        },
        async createMeeting() {
            this.saving = true;
            try {
                // We assume Front Page Course ID = 1 for "Generic" meetings
                // If not 1, we might need to find it or ask user. Standard Moodle Site Course is 1.
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_create_express_activity',
                    args: {
                        classid: -1, // Special flag for System/FrontPage or we pass courseid directly?
                        // Actually create_express_activity expects classid (gmk_class id). 
                        // We do NOT have a class for admin meetings. 
                        // We need a NEW endpoint or Modify existing to handle "No Class" scenario.
                        // Let's modify create_express_activity logic to handle classid=0 or -1 as "System/Front Page"
                        type: 'bbb',
                        name: this.newMeeting.name,
                        intro: this.newMeeting.intro,
                        guest: true
                    },
                    ...window.wsStaticParams
                });

                if (response.data.status === 'success') {
                    this.showSnackbar('Sesión creada exitosamente', 'success');
                    this.createDialog = false;
                    this.newMeeting.name = '';
                    this.newMeeting.intro = '';
                    this.fetchMeetings();
                } else {
                    this.showSnackbar(response.data.message || 'Error: ' + JSON.stringify(response.data), 'error');
                }
            } catch (error) {
                this.showSnackbar('Error de conexión', 'error');
            } finally {
                this.saving = false;
            }
        },
        async deleteMeeting(item) {
            if (!confirm(`¿Estás seguro de eliminar la sesión "${item.name}"?`)) return;

            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_delete_guest_meeting',
                    args: { cmid: item.cmid },
                    ...window.wsStaticParams
                });
                if (response.data.status === 'success') {
                    this.showSnackbar('Sesión eliminada', 'success');
                    this.fetchMeetings();
                } else {
                    this.showSnackbar('No se pudo eliminar', 'error');
                }
            } catch (error) {
                this.showSnackbar('Error al eliminar', 'error');
            }
        }
    }
};

Vue.component('meeting-manager', MeetingManager);
