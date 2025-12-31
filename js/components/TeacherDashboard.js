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
                        <v-btn outlined color="primary" class="rounded-lg shadow-sm" @click="showCalendar = true">
                            <v-icon left>mdi-calendar</v-icon> Ver Calendario Completo
                        </v-btn>
                    </div>
                    <v-row>
                        <v-col cols="12" sm="6" lg="4" v-for="classItem in dashboardData.active_classes" :key="classItem.id">
                            <v-card class="rounded-xl hover-card overflow-hidden" elevation="2" @click="goToClass(classItem.id)">
                                <v-img :src="getClassImage(classItem)" height="140" class="align-start">
                                    <v-chip dark small :color="classItem.type === 1 ? 'blue darken-2' : 'green darken-2'" class="ma-3 font-weight-bold">
                                        {{ classItem.type === 1 ? 'VIRTUAL' : 'PRESENCIAL' }}
                                    </v-chip>
                                </v-img>
                                <v-card-text class="pt-4">
                                    <div class="text-overline primary--text font-weight-black mb-1">{{ classItem.course_shortname }}</div>
                                    <div class="text-h6 font-weight-bold grey--text text--darken-4 mb-2 line-clamp-1">{{ classItem.name }}</div>
                                    
                                    <v-list-item class="px-0 mb-3" dense>
                                        <v-list-item-avatar size="36" color="blue lighten-5" class="mr-3">
                                            <v-icon color="blue" small>mdi-clock-outline</v-icon>
                                        </v-list-item-avatar>
                                        <v-list-item-content>
                                            <v-list-item-subtitle class="text-caption">Siguiente sesión</v-list-item-subtitle>
                                            <v-list-item-title class="font-weight-medium">
                                                {{ classItem.next_session ? formatSession(classItem.next_session) : 'Sin sesiones próximas' }}
                                            </v-list-item-title>
                                        </v-list-item-content>
                                    </v-list-item>

                                    <v-divider class="mb-4"></v-divider>
                                    
                                    <v-row dense align="center">
                                        <v-col cols="6">
                                            <div class="text-caption grey--text mb-1">Tareas Entregadas</div>
                                            <div class="d-flex align-center">
                                                <v-icon size="18" :color="getPendingCount(classItem.id) > 0 ? 'red' : 'green'" class="mr-1">
                                                    {{ getPendingCount(classItem.id) > 0 ? 'mdi-alert-circle' : 'mdi-check-circle' }}
                                                </v-icon>
                                                <span class="font-weight-black" :class="getPendingCount(classItem.id) > 0 ? 'red--text' : 'green--text'">
                                                    {{ getPendingCount(classItem.id) }}
                                                </span>
                                            </div>
                                        </v-col>
                                        <v-col cols="6" class="text-right">
                                            <div class="text-caption grey--text mb-1">Estado de Salud</div>
                                            <v-chip small :color="getHealthColor(classItem.id) + ' lighten-4'" :text-color="getHealthColor(classItem.id) + ' darken-4'" class="font-weight-bold">
                                                {{ getHealthLabel(classItem.id) }}
                                            </v-chip>
                                        </v-col>
                                    </v-row>
                                </v-card-text>
                                <v-btn block color="primary" tile height="48" class="font-weight-bold">
                                    Gestionar Clase <v-icon right small>mdi-arrow-right</v-icon>
                                </v-btn>
                            </v-card>
                        </v-col>
                    </v-row>
                </v-col>
            </v-row>

            <!-- Calendar Dialog -->
            <v-dialog v-model="showCalendar" max-width="800px" scrollable transition="dialog-bottom-transition">
                <v-card class="rounded-xl overflow-hidden">
                    <v-toolbar flat color="primary" dark>
                        <v-icon left>mdi-calendar-month</v-icon>
                        <v-toolbar-title>Calendario Escolar</v-toolbar-title>
                        <v-spacer></v-spacer>
                        <v-btn icon @click="showCalendar = false"><v-icon>mdi-close</v-icon></v-btn>
                    </v-toolbar>
                    <v-card-text class="pa-4 bg-white">
                        <v-sheet height="500">
                            <v-calendar
                                ref="calendar"
                                color="primary"
                                :events="calendarEvents"
                                type="month"
                            ></v-calendar>
                        </v-sheet>
                    </v-card-text>
                </v-card>
            </v-dialog>
        </v-container>
    `,
    data() {
        return {
            loading: true,
            showCalendar: false,
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
    computed: {
        calendarEvents() {
            return this.dashboardData.calendar_events.map(e => ({
                name: e.name,
                start: new Date(e.timestart * 1000),
                color: 'blue'
            }));
        }
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
                    args: { userid: window.userId || 0 },
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
            this.overviewStats[1].value = '---';
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
        getHealthLabel(classId) {
            const colors = { 'green': 'Excelente', 'yellow': 'Atención', 'red': 'Crítico', 'grey': 'S/D' };
            return colors[this.getHealthColor(classId)] || 'Estable';
        },
        formatSession(timestamp) {
            if (!timestamp) return 'No programada';
            const date = new Date(parseInt(timestamp) * 1000);
            return date.toLocaleDateString(undefined, {
                weekday: 'short',
                day: 'numeric',
                month: 'short',
                hour: '2-digit',
                minute: '2-digit'
            });
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
