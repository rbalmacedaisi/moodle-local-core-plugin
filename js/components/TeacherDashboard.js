/**
 * Teacher Dashboard Component
 * Created for Redesigning Teacher Experience
 */

const TeacherDashboard = {
    template: `
        <v-container fluid class="pa-4 grey lighten-5">
            <v-row v-if="loading">
                <v-col cols="12" class="text-center">
                    <v-progress-circular indeterminate color="primary"></v-progress-circular>
                </v-col>
            </v-row>
            <v-row v-else>
                <!-- Overview Stats -->
                <v-col cols="12" md="4" v-for="(stat, index) in overviewStats" :key="'stat-' + index">
                    <v-card class="rounded-lg shadow-sm">
                        <v-card-text class="d-flex align-center">
                            <v-avatar :color="stat.color + ' lighten-4'" size="48" class="mr-4">
                                <v-icon :color="stat.color">{{ stat.icon }}</v-icon>
                            </v-avatar>
                            <div>
                                <div class="text-caption grey--text text--darken-1">{{ stat.label }}</div>
                                <div class="text-h5 font-weight-bold">{{ stat.value }}</div>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>

                <!-- Active Classes (Cards) -->
                <v-col cols="12" class="mt-4">
                    <div class="d-flex align-center mb-4">
                        <h2 class="text-h5 font-weight-bold mb-0">Mis Clases Activas</h2>
                        <v-spacer></v-spacer>
                        <v-btn text color="primary">Ver Calendario</v-btn>
                    </div>
                    <v-row>
                        <v-col cols="12" sm="6" lg="4" v-for="classItem in dashboardData.active_classes" :key="classItem.id">
                            <v-card class="rounded-lg hover-card" @click="goToClass(classItem.id)">
                                <v-img :src="getClassImage(classItem)" height="120" color="primary">
                                    <v-overlay absolute opacity="0.4">
                                        <v-chip dark small :color="classItem.type === 1 ? 'blue' : 'green'" class="ma-2">
                                            {{ classItem.type === 1 ? 'Virtual' : 'Presencial' }}
                                        </v-chip>
                                    </v-overlay>
                                </v-img>
                                <v-card-text>
                                    <div class="text-overline primary--text">{{ classItem.course_shortname }}</div>
                                    <div class="text-h6 font-weight-bold mb-2">{{ classItem.name }}</div>
                                    <div class="d-flex align-center text-caption grey--text mb-2">
                                        <v-icon x-small class="mr-1">mdi-calendar-clock</v-icon>
                                        Siguiente: {{ formatSession(classItem.next_session) }}
                                    </div>
                                    <v-divider class="my-2"></v-divider>
                                    <v-row dense>
                                        <v-col cols="6">
                                            <div class="text-caption grey--text">Pendientes</div>
                                            <div class="font-weight-bold text-center red--text">
                                                {{ getPendingCount(classItem.id) }}
                                            </div>
                                        </v-col>
                                        <v-col cols="6">
                                            <div class="text-caption grey--text">Estado</div>
                                            <div class="d-flex justify-center">
                                                <v-icon :color="getHealthColor(classItem.id)">mdi-circle-medium</v-icon>
                                            </div>
                                        </v-col>
                                    </v-row>
                                </v-card-text>
                                <v-card-actions>
                                    <v-btn block color="primary" depressed class="rounded-lg">Gestionar Clase</v-btn>
                                </v-card-actions>
                            </v-card>
                        </v-col>
                    </v-row>
                </v-col>
            </v-row>
        </v-container>
    `,
    data() {
        return {
            loading: true,
            dashboardData: {
                active_classes: [],
                calendar_events: [],
                pending_tasks: [],
                health_status: []
            },
            overviewStats: [
                { label: 'Cursos Activos', value: 0, icon: 'mdi-book-open-page-variant', color: 'blue' },
                { label: 'Estudiantes', value: 0, icon: 'mdi-account-group', color: 'orange' },
                { label: 'Tareas Pendientes', value: 0, icon: 'mdi-alert-circle-outline', color: 'red' }
            ]
        };
    },
    mounted() {
        this.fetchDashboardData();
    },
    methods: {
        async fetchDashboardData() {
            this.loading = true;
            try {
                // Call Moodle AJAX service (consolidated method)
                const response = await axios.post(wsUrl, {
                    action: 'local_grupomakro_get_teacher_dashboard_data',
                    args: { userid: window.userId }, // window.userId needs to be available
                    ...wsStaticParams
                });

                if (response.data.status === 'success') {
                    this.dashboardData = response.data.data;
                    this.updateStats();
                } else {
                    console.error('Error fetching dashboard data:', response.data.message);
                }
            } catch (error) {
                console.error('Network error fetching dashboard data:', error);
            } finally {
                this.loading = false;
            }
        },
        updateStats() {
            this.overviewStats[0].value = this.dashboardData.active_classes.length;
            this.overviewStats[1].value = 'TBD'; // Requires backend enhancement
            this.overviewStats[2].value = this.dashboardData.pending_tasks.reduce((acc, curr) => acc + curr.count, 0);
        },
        getPendingCount(classId) {
            const task = this.dashboardData.pending_tasks.find(t => t.classid === classId);
            return task ? task.count : 0;
        },
        getHealthColor(classId) {
            const status = this.dashboardData.health_status.find(h => h.classid === classId);
            return status ? status.level : 'grey';
        },
        formatSession(timestamp) {
            if (!timestamp) return 'No programada';
            const date = new Date(parseInt(timestamp) * 1000);
            return date.toLocaleString();
        },
        getClassImage(item) {
            // Placeholder logic for class images
            return 'https://images.unsplash.com/photo-1509062522246-3755977927d7?q=80&w=400';
        },
        goToClass(classId) {
            // Logic to navigate to ManageClass.js
            this.$emit('change-page', { page: 'manage-class', id: classId });
        }
    }
};

window.TeacherDashboard = TeacherDashboard;
