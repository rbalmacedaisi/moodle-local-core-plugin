/**
 * Manage Class Component
 * Created for Redesigning Teacher Experience
 */

const ManageClass = {
    props: {
        classId: {
            type: Number,
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
                        {{ classDetails.type === 1 ? 'Virtual' : 'Presencial' }}
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
                                <v-timeline dense align-top>
                                    <v-timeline-item
                                        v-for="(session, index) in timeline"
                                        :key="session.id"
                                        :color="getSessionColor(session)"
                                        small
                                        fill-dot
                                    >
                                        <v-card class="rounded-lg shadow-sm">
                                            <v-card-title class="text-subtitle-1 font-weight-bold">
                                                Sesión {{ index + 1 }}
                                                <v-spacer></v-spacer>
                                                <span class="text-caption grey--text">{{ formatDate(session.startdate) }}</span>
                                            </v-card-title>
                                            <v-card-text>
                                                <div class="d-flex align-center mb-2" v-if="session.type === 'virtual'">
                                                    <v-icon small color="blue" class="mr-2">mdi-video</v-icon>
                                                    <span class="blue--text">Aula Virtual (BBB)</span>
                                                </div>
                                                <div class="d-flex align-center mb-2" v-else>
                                                    <v-icon small color="green" class="mr-2">mdi-map-marker</v-icon>
                                                    <span>{{ session.room_name || 'Aula Física' }}</span>
                                                </div>
                                            </v-card-text>
                                            <v-card-actions>
                                                <v-btn small depressed color="primary" @click="enterSession(session)">
                                                    {{ session.type === 'virtual' ? 'Entrar al Aula' : 'Tomar Asistencia' }}
                                                </v-btn>
                                                <v-btn small text @click="rescheduleSession(session)">Reprogramar</v-btn>
                                            </v-card-actions>
                                        </v-card>
                                    </v-timeline-item>
                                </v-timeline>
                            </v-card>
                        </v-tab-item>

                        <!-- Roster Tab -->
                        <v-tab-item>
                            <studenttable :class-id="classId"></studenttable>
                        </v-tab-item>

                        <!-- Grades Tab -->
                        <v-tab-item>
                            <v-card class="rounded-lg">
                                <v-card-title class="font-weight-bold">Control de Notas</v-card-title>
                                <v-card-text>
                                    <!-- Simplified grades table will go here -->
                                    <div class="text-center pa-4 grey--text">Módulo de notas simplificado en desarrollo</div>
                                </v-card-text>
                            </v-card>
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
            timeline: []
        };
    },
    mounted() {
        this.fetchClassDetails();
        this.fetchTimeline();
    },
    methods: {
        async fetchClassDetails() {
            // Simplified fetch for class metadata
            // Real implementation will use an API call
        },
        async fetchTimeline() {
            // Fetch sessions for this class
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
            const date = new Date(parseInt(timestamp) * 1000);
            return date.toLocaleDateString(undefined, { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
        },
        enterSession(session) {
            if (session.type === 'virtual') {
                // Logic to open BBB activity
                // window.open(session.bbb_url, '_blank');
            } else {
                // Logic to open attendance manager
            }
        },
        rescheduleSession(session) {
            // Open reschedule dialog
        },
        addActivity(type) {
            // Open activity creation wizard
            console.log('Adding activity:', type);
        }
    }
};

window.ManageClass = ManageClass;
