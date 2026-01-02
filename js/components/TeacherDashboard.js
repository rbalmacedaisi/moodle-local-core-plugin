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
                        <h2 class="text-h5 font-weight-bold mb-0">{{ lang.my_active_classes || 'Mis Clases Activas' }}</h2>
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
                                        {{ classItem.typelabel }}
                                    </v-chip>
                                </v-img>
                                <v-card-text class="pt-4">
                                    <div class="text-overline primary--text font-weight-black mb-1">{{ classItem.course_shortname }}</div>
                                    <div class="text-h6 font-weight-bold grey--text text--darken-4 mb-2 line-clamp-2" style="height: 3.2em; line-height: 1.6em;">
                                        {{ classItem.name || classItem.course_fullname }}
                                    </div>
                                    
                                    <v-row no-gutters class="mb-4">
                                        <v-col cols="6">
                                            <div class="d-flex align-center mb-1">
                                                <v-icon x-small color="grey lighten-1" class="mr-1">mdi-calendar-range</v-icon>
                                                <span class="text-caption grey--text">{{ formatDateSimple(classItem.initdate) }} - {{ formatDateSimple(classItem.enddate) }}</span>
                                            </div>
                                            <div class="d-flex align-center">
                                                <v-icon x-small color="grey lighten-1" class="mr-1">mdi-clock-outline</v-icon>
                                                <span class="text-caption font-weight-bold">{{ classItem.schedule_text }}</span>
                                            </div>
                                        </v-col>
                                        <v-col cols="6" class="text-right">
                                            <div class="text-caption grey--text mb-1">{{ lang.active_students || lang.active_users || 'Estudiantes' }}</div>
                                            <div class="text-h6 font-weight-black blue--text">
                                                <v-icon left small color="blue">mdi-account-group</v-icon>
                                                {{ classItem.student_count || 0 }}
                                            </div>
                                        </v-col>
                                    </v-row>

                                    <v-divider class="mb-3"></v-divider>
                                    
                                    <div class="d-flex align-center">
                                        <v-icon small :color="classItem.next_session ? 'primary' : 'grey'" class="mr-2">
                                            {{ classItem.next_session ? 'mdi-clock-alert' : 'mdi-clock-off' }}
                                        </v-icon>
                                        <div>
                                            <div class="text-caption grey--text lh-1">{{ lang.next_session || 'Siguiente Sesión' }}</div>
                                            <div class="font-weight-medium" :class="classItem.next_session ? 'primary--text' : 'grey--text'">
                                                {{ classItem.next_session ? formatSession(classItem.next_session) : 'Sin fecha programada' }}
                                            </div>
                                        </div>
                                    </div>
                                </v-card-text>
                                <v-btn block color="primary" tile height="48" class="font-weight-bold mt-2">
                                    Gestionar Clase <v-icon right small>mdi-arrow-right</v-icon>
                                </v-btn>
                            </v-card>
                        </v-col>
                    </v-row>
                </v-col>
            </v-row>

            <!-- Calendar Dialog -->
            <v-dialog v-model="showCalendar" max-width="900px" scrollable transition="dialog-bottom-transition">
                <v-card class="rounded-xl overflow-hidden">
                    <v-toolbar flat color="primary" dark>
                        <v-btn icon @click="calendarPrev"><v-icon>mdi-chevron-left</v-icon></v-btn>
                        <v-btn text @click="calendarToday" class="d-none d-sm-inline-flex">Hoy</v-btn>
                        <v-btn icon @click="calendarNext"><v-icon>mdi-chevron-right</v-icon></v-btn>
                        
                        <v-toolbar-title class="ml-2">{{ calendarTitle }}</v-toolbar-title>
                        
                        <v-spacer></v-spacer>
                        
                        <v-menu offset-y>
                            <template v-slot:activator="{ on, attrs }">
                                <v-btn outlined small v-bind="attrs" v-on="on" class="mr-2">
                                    {{ viewNames[calendarView] }} <v-icon right>mdi-menu-down</v-icon>
                                </v-btn>
                            </template>
                            <v-list dense>
                                <v-list-item @click="calendarView = 'day'"><v-list-item-title>Día</v-list-item-title></v-list-item>
                                <v-list-item @click="calendarView = 'week'"><v-list-item-title>Semana</v-list-item-title></v-list-item>
                                <v-list-item @click="calendarView = 'month'"><v-list-item-title>Mes</v-list-item-title></v-list-item>
                            </v-list>
                        </v-menu>

                        <v-btn icon @click="showCalendar = false"><v-icon>mdi-close</v-icon></v-btn>
                    </v-toolbar>
                    <v-card-text class="pa-4 bg-white">
                        <v-sheet height="600">
                            <v-calendar
                                ref="calendar"
                                v-model="calendarValue"
                                color="primary"
                                :events="calendarEvents"
                                :type="calendarView"
                                @change="onCalendarChange"
                                @click:date="onClickDate"
                                @click:more="onClickDate"
                                :first-interval="6"
                                :interval-count="18"
                                locale="es"
                            ></v-calendar>
                        </v-sheet>
                    </v-card-text>
                </v-card>
            </v-dialog>
            
            <!-- Agenda Dialog (Daily List) -->
            <v-dialog v-model="showAgendaDialog" max-width="500px">
                <v-card class="rounded-lg">
                    <v-card-title class="grey lighten-5">
                        <span class="text-subtitle-1 font-weight-bold">Agenda: {{ selectedDateFormatted }}</span>
                        <v-spacer></v-spacer>
                        <v-btn icon @click="showAgendaDialog = false"><v-icon>mdi-close</v-icon></v-btn>
                    </v-card-title>
                    <v-card-text class="pa-0">
                         <v-list v-if="selectedDateEvents.length > 0" two-line>
                            <template v-for="(event, i) in selectedDateEvents">
                                <v-list-item :key="i" @click="goToClass(event.classid)">
                                    <v-list-item-avatar>
                                        <v-icon class="blue lighten-1" dark small>mdi-calendar-check</v-icon>
                                    </v-list-item-avatar>
                                    <v-list-item-content>
                                        <v-list-item-title class="font-weight-medium">{{ event.name }}</v-list-item-title>
                                        <v-list-item-subtitle>{{ event.startTime }} - {{ event.endTime }}</v-list-item-subtitle>
                                    </v-list-item-content>
                                    <v-list-item-action>
                                        <v-icon small color="grey">mdi-chevron-right</v-icon>
                                    </v-list-item-action>
                                </v-list-item>
                                <v-divider v-if="i < selectedDateEvents.length - 1" :key="'div-' + i"></v-divider>
                            </template>
                        </v-list>
                        <div v-else class="text-center pa-6 grey--text">
                            <v-icon large color="grey lighten-2" class="mb-2">mdi-calendar-blank</v-icon>
                            <div>No hay eventos programados para este día.</div>
                        </div>
                    </v-card-text>
                </v-card>
            </v-dialog>
        </v-container>
    `,
    data() {
        return {
            loading: true,
            showCalendar: false,
            calendarValue: '',
            calendarView: 'month',
            calendarTitle: '',
            showAgendaDialog: false,
            selectedDateEvents: [],
            selectedDateFormatted: '',
            viewNames: { 'month': 'Mes', 'week': 'Semana', 'day': 'Día' },
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
        lang() {
            return window.strings || {};
        },
        calendarEvents() {
            const events = this.dashboardData.calendar_events.map(e => {
                const start = new Date(e.timestart * 1000);
                const end = new Date((e.timestart + (e.timeduration || 3600)) * 1000);
                return {
                    name: e.name,
                    start: start,
                    end: end,
                    classid: e.classid || 0,
                    color: 'blue',
                    timed: true,
                    // Debug string
                    _debug: `${start.toLocaleString()} - ${end.toLocaleString()}`
                };
            });
            console.log('Calendar Events DEBUG:', events);
            return events;
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
            this.overviewStats[0].label = this.lang.active_courses || 'Cursos Activos';
            this.overviewStats[0].value = this.dashboardData.active_classes.length;

            this.overviewStats[1].label = this.lang.active_students || 'Estudiantes';
            this.overviewStats[1].value = this.dashboardData.active_classes.reduce((acc, curr) => acc + (curr.student_count || 0), 0);

            this.overviewStats[2].label = this.lang.pending_tasks || 'Tareas Pendientes';
            this.overviewStats[2].value = this.dashboardData.pending_tasks.reduce((acc, curr) => acc + (curr.count || 0), 0);
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
            return date.toLocaleDateString('es-ES', {
                day: 'numeric',
                month: 'short',
                hour: '2-digit',
                minute: '2-digit'
            });
        },
        formatDateSimple(timestamp) {
            if (!timestamp) return 'S/F';
            const date = new Date(parseInt(timestamp) * 1000);
            return date.toLocaleDateString('es-ES', { day: '2-digit', month: '2-digit' });
        },
        calendarNext() { this.$refs.calendar.next(); },
        calendarPrev() { this.$refs.calendar.prev(); },
        calendarToday() { this.calendarValue = ''; },
        onCalendarChange({ start, end }) {
            // Updated to handle range properly in title
            // Note: start and end are objects with date info
            if (this.calendarView === 'month') {
                this.calendarTitle = new Date(start.date).toLocaleDateString('es-ES', { month: 'long', year: 'numeric' });
            } else if (this.calendarView === 'week' || this.calendarView === 'day') {
                // For week/day, maybe show start - end or just month/year of start
                this.calendarTitle = new Date(start.date).toLocaleDateString('es-ES', { month: 'long', year: 'numeric' });
            }
        },
        onClickEvent({ event }) {
            // event.start is a Date object. Use local date string.
            const date = this.getLocalDateString(event.start);
            this.openAgenda(date);
        },
        onClickDate({ date }) {
            // date is already YYYY-MM-DD string
            this.openAgenda(date);
        },
        getLocalDateString(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        },
        openAgenda(dateString) {
            // format: YYYY-MM-DD
            // Create a date object just for formatting the title (handling timezone offset correctly by treating it as local)
            const [y, m, d] = dateString.split('-').map(Number);
            const localDateForTitle = new Date(y, m - 1, d);

            this.selectedDateFormatted = localDateForTitle.toLocaleDateString('es-ES', { weekday: 'long', day: 'numeric', month: 'long' });

            // Filter events for this day using LOCAL time comparison
            this.selectedDateEvents = this.calendarEvents.filter(e => {
                const eDate = this.getLocalDateString(e.start);
                return eDate === dateString;
            }).map(e => ({
                ...e,
                startTime: e.start.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' }),
                endTime: e.end.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' })
            }));

            this.showAgendaDialog = true;
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
