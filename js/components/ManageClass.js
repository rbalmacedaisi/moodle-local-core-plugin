/**
 * Manage Class Component
 * Created for Redesigning Teacher Experience
 */

const ManageClass = {
    props: {
        classId: {
            type: [Number, String],
            required: true
        }
    },
    template: `
        <v-container fluid class="pa-4 pt-0 grey lighten-5 h-100">
            <!-- Header section -->
            <v-row class="white py-4 border-bottom sticky-header">
                <v-col cols="12" class="d-flex align-center">
                    <v-btn icon @click="$emit('back')" class="mr-2">
                        <v-icon>mdi-arrow-left</v-icon>
                    </v-btn>
                    <div>
                        <div class="text-caption grey--text">{{ classDetails.course_shortname }}</div>
                        <h1 class="text-h5 font-weight-bold mb-0">{{ classDetails.name }}</h1>
                    </div>
                    <v-spacer></v-spacer>
                    <v-chip dark :color="classDetails.type === 1 ? 'blue' : 'green'">
                        {{ classDetails.typelabel || (classDetails.type === 1 ? 'Virtual' : 'Presencial') }}
                    </v-chip>
                </v-col>
            </v-row>

            <!-- Navigation Tabs -->
            <v-row>
                <v-col cols="12" class="py-0">
                    <v-tabs v-model="activeTab" background-color="white" color="primary" grow>
                        <v-tab v-for="tab in tabs" :key="tab.id">
                            <v-icon left small v-if="tab.icon">{{ tab.icon }}</v-icon>
                            {{ tab.name }}
                        </v-tab>
                    </v-tabs>
                </v-col>
            </v-row>

            <!-- Tab Content -->
            <v-row class="mt-4">
                <v-col cols="12">
                    <v-tabs-items v-model="activeTab" class="transparent">
                        
                        <!-- Timeline Tab -->
                        <v-tab-item>
                            <v-card flat class="transparent">
                                <v-timeline dense align-top class="mx-4">
                                    <v-timeline-item
                                        v-for="(session, index) in timeline"
                                        :key="session.id"
                                        :color="session.type === 'virtual' ? 'blue' : 'green'"
                                        small
                                        fill-dot
                                    >
                                        <v-card class="rounded-xl shadow-sm elevation-1 mb-2">
                                            <v-card-title class="text-subtitle-1 font-weight-bold pb-1">
                                                Sesión {{ index + 1 }}
                                                <v-spacer></v-spacer>
                                                <v-chip x-small outlined :color="session.type === 'virtual' ? 'blue' : 'green'">
                                                    {{ session.type === 'virtual' ? 'VIRTUAL' : 'PRESENCIAL' }}
                                                </v-chip>
                                            </v-card-title>
                                            <v-card-text>
                                                <div class="d-flex align-center grey--text text--darken-2">
                                                    <v-icon small class="mr-2">mdi-calendar-clock</v-icon>
                                                    <span class="font-weight-medium">{{ formatDate(session.startdate) }}</span>
                                                </div>
                                            </v-card-text>
                                            <v-divider></v-divider>
                                            <v-card-actions class="grey lighten-5">
                                                <v-btn small depressed :color="session.type === 'virtual' ? 'blue' : 'green'" dark class="rounded-lg px-4" @click="enterSession(session)">
                                                    <v-icon left x-small>{{ session.type === 'virtual' ? 'mdi-video' : 'mdi-qrcode' }}</v-icon>
                                                    {{ session.type === 'virtual' ? 'Entrar' : 'Asistencia' }}
                                                </v-btn>
                                                <v-spacer></v-spacer>
                                                <v-btn small text color="grey darken-1" @click="rescheduleSession(session)">
                                                    <v-icon left x-small>mdi-pencil</v-icon> Editar
                                                </v-btn>
                                            </v-card-actions>
                                        </v-card>
                                    </v-timeline-item>
                                </v-timeline>
                                <v-alert v-if="timeline.length === 0" type="info" text class="ma-4 rounded-xl">
                                    No hay sesiones programadas para esta clase.
                                </v-alert>
                            </v-card>
                        </v-tab-item>

                        <!-- Roster Tab -->
                        <v-tab-item>
                            <studenttable :class-id="classId"></studenttable>
                        </v-tab-item>

                        <!-- Grades Tab -->
                        <v-tab-item>
                            <grades-grid :class-id="classId"></grades-grid>
                        </v-tab-item>

                    </v-tabs-items>
                </v-col>
            </v-row>

            <!-- Floating Action Button for adding activities -->
            <v-speed-dial v-model="fab" bottom right fixed direction="top" transition="slide-y-reverse-transition">
                <template v-slot:activator>
                    <v-btn v-model="fab" color="primary" dark fab large>
                        <v-icon v-if="fab">mdi-close</v-icon>
                        <v-icon v-else>mdi-plus</v-icon>
                    </v-btn>
                </template>
                <v-btn fab dark small color="indigo" @click="addActivity('bbb')">
                    <v-icon>mdi-video</v-icon>
                </v-btn>
                <v-btn fab dark small color="green" @click="addActivity('assignment')">
                    <v-icon>mdi-file-document-edit</v-icon>
                </v-btn>
                <v-btn fab dark small color="orange" @click="addActivity('resource')">
                    <v-icon>mdi-file-download</v-icon>
                </v-btn>
            </v-speed-dial>
            </v-speed-dial>
            
            <!-- Activity Creation Wizard -->
            <activity-creation-wizard 
                v-if="showActivityWizard" 
                :class-id="parseInt(classId)" 
                :activity-type="newActivityType"
                @close="showActivityWizard = false"
                @success="onActivityCreated"
            ></activity-creation-wizard>

        </v-container>
    `,
    data() {
        return {
            activeTab: 0,
            fab: false,
            classDetails: {
                name: '',
                course_shortname: '',
                type: 0
            },
            tabs: [
                { id: 0, name: 'Timeline', icon: 'mdi-timeline-clock' },
                { id: 1, name: 'Estudiantes', icon: 'mdi-account-group' },
                { id: 2, name: 'Notas', icon: 'mdi-star' }
            ],
            timeline: [],
            showActivityWizard: false,
            newActivityType: ''
        };
    },
    mounted() {
        this.fetchClassDetails();
        this.fetchTimeline();
    },
    methods: {
        async fetchClassDetails() {
            try {
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_get_teacher_dashboard_data',
                    args: { userid: window.userId },
                    ...window.wsStaticParams
                });
                if (response.data.status === 'success') {
                    const cls = response.data.data.active_classes.find(c => c.id === this.classId);
                    if (cls) this.classDetails = cls;
                }
            } catch (error) {
                console.error('Error fetching class details:', error);
            }
        },
        async fetchTimeline() {
            try {
                // For now leveraging the consolidated dashboard data or a specific session API
                // In a real prod environment, we might have local_grupomakro_get_class_sessions
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_get_class_details', // Assuming this exists or using dashboard
                    args: { classid: this.classId },
                    ...window.wsStaticParams
                });
                if (response.data.status === 'success') {
                    this.timeline = response.data.data.sessions;
                }
            } catch (error) {
                console.error('Error fetching timeline:', error);
            }
        },
        getSessionColor(session) {
            const now = new Date();
            const sessionDate = new Date(parseInt(session.startdate) * 1000);
            if (sessionDate < now) return 'grey lighten-1';
            if (this.isNextSession(session)) return 'primary';
            return 'grey lighten-3';
        },
        isNextSession(session) {
            // Simple logic for highlighting the upcoming session
            return false;
        },
        formatDate(timestamp) {
            if (!timestamp) return 'No programada';
            const date = new Date(parseInt(timestamp) * 1000);
            return date.toLocaleDateString(undefined, {
                weekday: 'long',
                day: 'numeric',
                month: 'long',
                hour: '2-digit',
                minute: '2-digit'
            });
        },
        enterSession(session) {
            if (session.type === 'virtual') {
                if (session.join_url) {
                    window.open(session.join_url, '_blank');
                } else {
                    alert('El enlace a la sesión virtual no está disponible.');
                }
            } else {
                // Logic to open attendance manager
            }
        },
        rescheduleSession(session) {
            // Open reschedule dialog
        },
        addActivity(type) {
            this.newActivityType = type;
            this.showActivityWizard = true;
        },
        onActivityCreated() {
            this.fetchTimeline(); // Reload timeline to show new activity if applicable
        }
    }
};

window.ManageClass = ManageClass;
