/**
 * Teacher Dashboard Component
 * Created for Redesigning Teacher Experience
 */

const TeacherDashboard = {
    template: `
        <v-app>
        <v-container fluid class="pa-4" style="background-color: var(--gmk-bg); min-height: 100vh;">
            <v-row v-if="loading">
                <v-col cols="12" class="text-center">
                    <v-progress-circular indeterminate color="primary"></v-progress-circular>
                </v-col>
            </v-row>
            <v-row v-else>
                <!-- Overview Stats -->
                <v-col cols="12" md="4" v-for="(stat, index) in overviewStats" :key="'stat-' + index">
                    <v-card class="rounded-lg shadow-sm" :ripple="!!stat.action" @click="stat.action ? handleStatClick(stat.action) : null" :class="{'cursor-pointer': !!stat.action}">
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
                                    <div class="text-h6 font-weight-bold mb-2 line-clamp-2" style="height: 3.2em; line-height: 1.6em;">
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
                    <v-card-text class="pa-4">
                        <v-sheet height="600">
                            <v-calendar
                                ref="calendar"
                                v-model="calendarValue"
                                color="primary"
                                :events="calendarEvents"
                                :type="calendarView"
                                @change="onCalendarChange"
                                @click:event="showEvent"
                                @click:more="viewDay"
                                @click:date="viewDay"
                                first-time="06:00"
                                interval-count="18"
                                interval-minutes="60"
                                locale="es"
                            >
                                <template v-slot:event="{ event }">
                                    <div class="px-1 white--text" style="font-size: 0.72rem; line-height: 1.2; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; border-radius: 4px;">
                                        <div class="font-weight-bold truncate">
                                            <span v-if="event.courseIcon">{{ event.courseIcon }}</span>
                                            {{ event.name }}
                                        </div>
                                        <div v-if="calendarView !== 'month'" class="caption">
                                            {{ formatEventTime(event.start) }} - {{ formatEventTime(event.end) }}
                                        </div>
                                    </div>
                                </template>
                            </v-calendar>
                            <v-menu
                                v-model="showSelectedEvent"
                                :close-on-content-click="false"
                                :activator="selectedElement"
                                offset-y
                                max-width="350px"
                                content-class="event-details-menu"
                            >
                                <v-card color="grey lighten-4" min-width="300px" flat>
                                    <v-toolbar :color="selectedEvent.color" dark dense flat>
                                        <v-toolbar-title class="subtitle-2 font-weight-bold pl-0">{{ selectedEvent.headerTitle }}</v-toolbar-title>
                                        <v-spacer></v-spacer>
                                        <v-btn icon small @click="showSelectedEvent = false"><v-icon>mdi-close</v-icon></v-btn>
                                    </v-toolbar>
                                    <v-card-text class="pa-3">
                                        <div v-if="selectedEvent.courseFull" class="mb-2">
                                            <div class="caption grey--text font-weight-bold">CURSO:</div>
                                            <div class="body-2">{{ selectedEvent.courseFull }}</div>
                                        </div>
                                        <div v-if="selectedEvent.activityName" class="mb-2">
                                            <div class="caption grey--text font-weight-bold">ACTIVIDAD:</div>
                                            <div class="body-2">{{ selectedEvent.activityName }}</div>
                                        </div>
                                        <div class="d-flex align-center mb-2">
                                            <v-icon small class="mr-2">mdi-clock-outline</v-icon>
                                            <span class="caption font-weight-medium">
                                                {{ selectedEvent.start ? formatEventTime(selectedEvent.start) : '' }} 
                                                <span v-if="selectedEvent.timed">- {{ selectedEvent.end ? formatEventTime(selectedEvent.end) : '' }}</span>
                                            </span>
                                        </div>
                                        <div v-if="selectedEvent.classid" class="mt-3">
                                            <v-btn block small color="primary" class="rounded-lg" @click="goToClass(selectedEvent.classid)">
                                                Gestionar Clase
                                            </v-btn>
                                        </div>
                                    </v-card-text>
                                </v-card>
                            </v-menu>
                        </v-sheet>
                    </v-card-text>
                </v-card>
            </v-dialog>
        </v-container>
        </v-app>
    `,
    data() {
        return {
            loading: true,
            showCalendar: false,
            calendarValue: '',
            calendarView: 'month',
            calendarTitle: '',
            // Event Details Popover state
            showSelectedEvent: false,
            selectedEvent: {},
            selectedElement: null,
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
            return this.dashboardData.calendar_events.map(e => {
                const tStart = parseInt(e.timestart);
                const tDur = parseInt(e.timeduration) || 0;

                // Use course identifier (shortname if available, otherwise truncated longname)
                const courseIden = e.course_shortname || e.classname;
                let displayName = e.name;

                // If it's a month view, we want to see the activity clearly. 
                // We'll prefix with course code only if it's a session or specifically relevant.
                if (!e.is_grading_task && courseIden && !e.name.includes(courseIden)) {
                    displayName = `[${courseIden}] ${e.name}`;
                }

                // Determine header title for the popover
                let headerTitle = e.name;
                if (!e.is_grading_task && (e.name.toLowerCase().includes('asistencia') || e.name.toLowerCase().includes('programado'))) {
                    headerTitle = e.classname;
                }

                return {
                    id: e.id,
                    name: displayName,
                    headerTitle: headerTitle,
                    activityName: e.name, // Full activity name
                    courseFull: e.classname, // Full class/course name
                    courseShort: e.course_shortname,
                    start: new Date(tStart * 1000),
                    end: new Date((tStart + tDur) * 1000),
                    classid: e.classid || 0,
                    color: e.color || 'primary',
                    timed: tDur > 0,
                    is_grading_task: !!e.is_grading_task
                };
            });
        }
    },
    mounted() {
        this.injectStyles();
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
                    if (this.$refs.calendar) {
                        this.$refs.calendar.checkChange();
                    }
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
            this.overviewStats[2].action = 'grading';
        },
        handleStatClick(action) {
            if (action === 'grading') {
                this.$emit('change-page', { page: 'grading' });
            }
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
        viewDay({ date }) {
            this.calendarValue = date;
            this.calendarView = 'day';
        },
        showEvent({ nativeEvent, event }) {
            const open = () => {
                this.selectedEvent = event;
                this.selectedElement = nativeEvent.target;
                setTimeout(() => {
                    this.showSelectedEvent = true;
                }, 10);
            };

            if (this.showSelectedEvent) {
                this.showSelectedEvent = false;
                setTimeout(open, 10);
            } else {
                open();
            }

            nativeEvent.stopPropagation();
        },
        formatEventTime(date) {
            // date is a Date object
            if (!date) return '';
            return date.toLocaleTimeString("es-ES", {
                hour: "2-digit",
                minute: "2-digit",
                hour12: true,
            });
        },
        getClassImage(item) {
            // Placeholder logic for class images
            return 'https://images.unsplash.com/photo-1509062522246-3755977927d7?q=80&w=400';
        },
        goToClass(classId) {
            // Logic to navigate to ManageClass.js
            this.$emit('change-page', { page: 'manage-class', id: classId });
        },
        injectStyles() {
            if (document.getElementById('teacher-dashboard-styles')) return;
            const style = document.createElement('style');
            style.id = 'teacher-dashboard-styles';
            style.textContent = `
                .event-details-menu {
                    z-index: 10000 !important;
                }
            `;
            document.head.appendChild(style);
        }
    }
};

window.TeacherDashboard = TeacherDashboard;
