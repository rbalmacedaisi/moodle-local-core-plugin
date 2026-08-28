/**
 * Teacher Dashboard Component
 * Created for Redesigning Teacher Experience
 */

const TeacherDashboard = {
    template: `
        <v-container fluid class="pa-4" style="background-color: var(--gmk-bg); min-height: 100vh;">
            <div class="v-row" v-if="loading" style="display:flex;flex-wrap:wrap;">
                <v-col cols="12" class="text-center py-12">
                    <v-progress-circular indeterminate color="primary" size="64"></v-progress-circular>
                </v-col>
            </div>
            <div class="v-row" v-else style="display:flex;flex-wrap:wrap;">

                <!-- ── Stats row (compact, always full-width across top) ── -->
                <v-col cols="12" sm="4" v-for="(stat, index) in overviewStats" :key="'stat-' + index">
                    <v-card class="rounded-lg" :ripple="!!stat.action"
                        @click="stat.action ? handleStatClick(stat.action) : null"
                        :class="{'cursor-pointer': !!stat.action}">
                        <v-card-text class="d-flex align-center py-3">
                            <v-avatar :color="stat.color + ' lighten-4'" size="44" class="mr-3">
                                <v-icon :color="stat.color" small>{{ stat.icon }}</v-icon>
                            </v-avatar>
                            <div>
                                <div class="text-caption grey--text">{{ stat.label }}</div>
                                <div class="text-h5 font-weight-bold">{{ stat.value }}</div>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>

                <!-- ── LEFT PANEL: class cards (full width mobile, wider on lg/xl) ── -->
                <v-col cols="12" lg="9" xl="9" class="mt-2">
                    <div class="d-flex align-center mb-4">
                        <h2 class="text-h5 font-weight-bold mb-0">{{ lang.my_active_classes || 'Mis Clases Activas' }}</h2>
                        <v-spacer></v-spacer>
                        <!-- Calendar button visible on mobile/tablet only; on desktop it's in the sidebar -->
                        <v-btn outlined color="primary" class="rounded-lg d-flex d-lg-none" @click="showCalendar = true">
                            <v-icon left>mdi-calendar</v-icon> Calendario
                        </v-btn>
                    </div>
                    <!-- Summary banner: one line for every class whose gradebook
                         weights don't total 100% past week 3. Rendered above the
                         cards so the teacher sees it without scrolling. -->
                    <v-alert v-if="classesMissingWeights.length > 0"
                             type="error" dense text prominent class="mb-4 rounded-lg">
                        <div class="text-subtitle-2 font-weight-bold mb-1">
                            {{ classesMissingWeights.length === 1
                                ? 'Una de tus clases no tiene las ponderaciones al 100%'
                                : classesMissingWeights.length + ' de tus clases no tienen las ponderaciones al 100%' }}
                        </div>
                        <div class="text-caption mb-2">
                            Mientras el libro de calificaciones no sume 100%, el sistema no puede calcular
                            la nota final de tus estudiantes ni identificar quiénes tienen derecho a reválida.
                        </div>
                        <div v-for="c in classesMissingWeights" :key="'w-' + c.id" class="mb-1">
                            <v-icon x-small color="red darken-2" class="mr-1">mdi-alert-circle-outline</v-icon>
                            <span class="text-caption font-weight-medium">{{ c.name || c.course_fullname }}</span>
                            <span class="text-caption grey--text text--darken-1">
                                — suma {{ formatWeightPct(c.weights_pct) }}%
                            </span>
                            <a href="#" class="text-caption font-weight-bold ml-1"
                               @click.stop.prevent="openGradebook(c)">Corregir ahora</a>
                        </div>
                    </v-alert>
                    <v-row>
                        <!-- Cards: 1/row on mobile, 2 on sm, 3 on md, 4 on lg+, so
                             wider screens don't leave most of the row empty. -->
                        <v-col cols="12" sm="6" md="4" lg="3" v-for="classItem in dashboardData.active_classes" :key="classItem.id">
                            <v-card class="rounded-xl hover-card overflow-hidden" elevation="2" @click="goToClass(classItem.id)">
                                <v-img :src="getClassImage(classItem)" height="120" class="align-start">
                                    <v-chip dark small :color="classItem.type === 1 ? 'blue darken-2' : 'green darken-2'" class="ma-3 font-weight-bold">
                                        {{ classItem.typelabel }}
                                    </v-chip>
                                </v-img>
                                <v-card-text class="pt-3 pb-2">
                                    <div class="text-overline primary--text font-weight-black mb-0" style="line-height:1.3;word-break:break-word;">{{ classItem.course_shortname }}</div>
                                    <div class="text-subtitle-2 font-weight-bold mb-2" style="min-height:2.8em;line-height:1.5em;word-break:break-word;">
                                        {{ classItem.name || classItem.course_fullname }}
                                    </div>
                                    <div class="d-flex align-center justify-space-between mb-2">
                                        <div>
                                            <div class="d-flex align-center mb-1">
                                                <v-icon x-small color="grey" class="mr-1">mdi-calendar-range</v-icon>
                                                <span class="text-caption grey--text">{{ formatDateSimple(classItem.initdate) }} - {{ formatDateSimple(classItem.enddate) }}</span>
                                            </div>
                                            <div class="d-flex align-center">
                                                <v-icon x-small color="grey" class="mr-1">mdi-clock-outline</v-icon>
                                                <span class="text-caption font-weight-bold">{{ classItem.schedule_text }}</span>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <div class="text-caption grey--text">Estudiantes</div>
                                            <div class="text-h6 font-weight-black blue--text">{{ classItem.student_count || 0 }}</div>
                                        </div>
                                    </div>
                                    <v-divider class="mb-2"></v-divider>
                                    <div class="d-flex align-center">
                                        <v-icon small :color="classItem.next_session ? 'primary' : 'grey'" class="mr-1">
                                            {{ classItem.next_session ? 'mdi-clock-alert' : 'mdi-clock-off' }}
                                        </v-icon>
                                        <div class="text-caption" :class="classItem.next_session ? 'primary--text font-weight-medium' : 'grey--text'">
                                            {{ classItem.next_session ? formatSession(classItem.next_session) : 'Sin fecha programada' }}
                                        </div>
                                    </div>
                                </v-card-text>
                                <!-- Gradebook weights warning. Only rendered past the
                                     grace period (week 3) so an in-progress gradebook
                                     is never flagged. The link opens the gradebook
                                     setup screen; @click.stop keeps the card's
                                     goToClass from firing underneath it. -->
                                <div v-if="classItem.weights_warning"
                                     class="px-3 py-2 red lighten-5"
                                     style="border-top:1px solid rgba(0,0,0,.08)">
                                    <div class="d-flex align-start">
                                        <v-icon small color="red darken-2" class="mr-2 mt-1">mdi-scale-balance</v-icon>
                                        <div class="text-caption red--text text--darken-4" style="line-height:1.45">
                                            <strong>Ponderaciones incompletas ({{ formatWeightPct(classItem.weights_pct) }}%).</strong>
                                            Sin el 100% no se calcula la nota final ni se pueden programar reválidas.
                                            <a href="#" class="font-weight-bold red--text text--darken-4"
                                               @click.stop.prevent="openGradebook(classItem)">Abrir libro de calificaciones</a>
                                        </div>
                                    </div>
                                </div>
                                <v-btn block color="primary" tile height="40" class="font-weight-bold">
                                    Gestionar Clase <v-icon right small>mdi-arrow-right</v-icon>
                                </v-btn>
                            </v-card>
                        </v-col>
                    </v-row>
                </v-col>

                <!-- ── RIGHT PANEL: sidebar (hidden on mobile, 3 cols on lg+) ── -->
                <v-col cols="12" lg="3" xl="3" class="mt-2 d-none d-lg-block">

                    <!-- Calendar button -->
                    <v-btn block outlined color="primary" class="rounded-lg mb-4" @click="showCalendar = true">
                        <v-icon left>mdi-calendar-month</v-icon> Ver Calendario Completo
                    </v-btn>

                    <!-- Upcoming sessions across all classes -->
                    <v-card class="rounded-xl" v-if="upcomingSessions.length > 0">
                        <v-card-title class="text-subtitle-2 font-weight-bold pb-0 pt-3 px-3">
                            <v-icon left color="primary" small>mdi-clock-fast</v-icon>
                            Próximas Sesiones
                        </v-card-title>
                        <v-list dense class="pt-1 pb-2">
                            <v-list-item
                                v-for="(session, i) in upcomingSessions"
                                :key="session.classid + '-' + i"
                                @click="goToClass(session.classid)"
                                class="px-3 py-1"
                                style="cursor:pointer;min-height:auto;"
                            >
                                <v-list-item-content class="py-1">
                                    <div class="d-flex align-center mb-1" style="gap:4px;flex-wrap:nowrap;">
                                        <v-chip x-small :color="session.isToday ? 'primary' : 'grey lighten-2'" :dark="session.isToday" style="flex-shrink:0;">
                                            {{ session.dateLabel }}
                                        </v-chip>
                                        <span class="text-caption grey--text" style="flex-shrink:0;">{{ session.timeLabel }}</span>
                                        <v-spacer></v-spacer>
                                        <v-icon x-small color="grey lighten-1">mdi-chevron-right</v-icon>
                                    </div>
                                    <div class="text-caption font-weight-medium session-name">{{ session.displayName }}</div>
                                </v-list-item-content>
                            </v-list-item>
                        </v-list>
                    </v-card>

                    <!-- No upcoming sessions placeholder -->
                    <v-card class="rounded-xl" v-else>
                        <v-card-text class="text-center grey--text py-6">
                            <v-icon large color="grey lighten-2" class="mb-2">mdi-calendar-check</v-icon>
                            <div class="text-caption">No hay sesiones próximas</div>
                        </v-card-text>
                    </v-card>

                </v-col>

            </div>

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
                        <v-sheet height="600" class="position-relative">
                            <div v-if="calendarEventsLoading" class="d-flex flex-column align-center justify-center" style="position:absolute;top:0;left:0;right:0;bottom:0;background:rgba(255,255,255,0.85);z-index:5;">
                                <v-progress-circular indeterminate color="primary" size="48"></v-progress-circular>
                                <div class="caption grey--text mt-3">Cargando eventos del calendario…</div>
                            </div>
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
    `,
    data() {
        const todayIso = new Date().toISOString().substr(0, 10);
        return {
            loading: true,
            showCalendar: false,
            // v-calendar v-model expects a YYYY-MM-DD string. Empty string made
            // v-calendar skip rendering events until the user clicked "Hoy".
            calendarValue: todayIso,
            calendarView: 'month',
            calendarTitle: '',
            // Event Details Popover state
            showSelectedEvent: false,
            selectedEvent: {},
            selectedElement: null,
            viewNames: { 'month': 'Mes', 'week': 'Semana', 'day': 'Día' },
            dashboardData: {
                active_classes: [],
                pending_tasks: [],
                health_status: []
            },
            // Calendar events are fetched lazily via loadCalendarEvents() so the
            // initial dashboard render isn't blocked by get_class_events().
            calendarEventsData: [],
            calendarEventsLoaded: false,
            calendarEventsLoading: false,
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
        // Classes the backend flagged as "weights don't total 100% and the
        // grace period is over". The backend owns the rule (initdate + 21d and
        // weight-aggregated categories only); the UI just lists what it gets.
        classesMissingWeights() {
            const classes = (this.dashboardData && this.dashboardData.active_classes) || [];
            return classes.filter(c => c && c.weights_warning);
        },
        upcomingSessions() {
            const now = Math.floor(Date.now() / 1000);
            const todayStr = new Date().toDateString();
            return this.dashboardData.active_classes
                .filter(c => c.next_session && parseInt(c.next_session) >= now)
                .sort((a, b) => parseInt(a.next_session) - parseInt(b.next_session))
                .slice(0, 7)
                .map(c => {
                    const ts = parseInt(c.next_session);
                    const date = new Date(ts * 1000);
                    const isToday = date.toDateString() === todayStr;
                    // Prefer short course code; fall back to class name truncated at 40 chars
                    const rawName = c.course_shortname || c.name || '';
                    const displayName = rawName.length > 42 ? rawName.substring(0, 42) + '…' : rawName;
                    return {
                        classid: c.id,
                        shortname: c.course_shortname || c.name,
                        displayName,
                        classname: c.name,
                        timestamp: ts,
                        isToday,
                        dateLabel: isToday ? 'Hoy' : date.toLocaleDateString('es-ES', { weekday: 'short', day: 'numeric', month: 'short' }),
                        timeLabel: date.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' })
                    };
                });
        },
        calendarEvents() {
            const list = Array.isArray(this.calendarEventsData)
                ? this.calendarEventsData
                : [];
            const mapped = [];
            for (let i = 0; i < list.length; i++) {
                const e = list[i];
                const tStart = parseInt(e.timestart);
                const tDur = parseInt(e.timeduration) || 0;
                // Skip events with no valid start — they would render as Invalid Date
                // and v-calendar silently drops them.
                if (!Number.isFinite(tStart) || tStart <= 0) {
                    continue;
                }

                // Use course identifier (shortname if available, otherwise truncated longname)
                const courseIden = e.course_shortname || e.classname || '';
                let displayName = e.name || '';

                // If it's a month view, we want to see the activity clearly.
                // We'll prefix with course code only if it's a session or specifically relevant.
                if (!e.is_grading_task && courseIden && displayName && !displayName.includes(courseIden)) {
                    displayName = `[${courseIden}] ${displayName}`;
                }

                // Determine header title for the popover
                let headerTitle = displayName;
                if (!e.is_grading_task && displayName && (displayName.toLowerCase().includes('asistencia') || displayName.toLowerCase().includes('programado'))) {
                    headerTitle = e.classname || displayName;
                }

                // v-calendar v2's parseTimestamp() rejects ISO 8601 strings with 'T'/'Z'
                // ("2026-05-22T00:50:00.000Z is not a valid timestamp"). It only accepts
                // Date objects, ms-since-epoch numbers, or strings in the format
                // YYYY-MM-DD hh:mm[:ss]. Pass Date objects directly (which ClassSchedule
                // proves works) instead of strings.
                const startDate = new Date(tStart * 1000);
                const endDate = new Date((tStart + tDur) * 1000);

                mapped.push({
                    id: e.id,
                    name: displayName,
                    headerTitle: headerTitle,
                    activityName: e.name || '',
                    courseFull: e.classname || '',
                    courseShort: e.course_shortname || '',
                    start: startDate,
                    end: endDate,
                    classid: e.classid || 0,
                    color: e.color || 'primary',
                    // All our events come from a calendar event with a specific time;
                    // force timed=true so v-calendar renders them in month view as
                    // a colored dot/bar.
                    timed: true,
                    is_grading_task: !!e.is_grading_task
                });
            }
            return mapped;
        }
    },
    watch: {
        // Vuetify's v-calendar sometimes does not pick up late changes to the
        // events prop when the dialog is already mounted but the data fetch
        // resolves afterwards. Calling checkChange() forces a recompute.
        showCalendar(isOpen) {
            if (isOpen) {
                // Lazy-load the calendar events the first time the dialog opens
                // so we don't block the initial dashboard render on a heavy
                // get_class_events() query.
                this.loadCalendarEvents();
                if (this.$refs.calendar) {
                    this.$nextTick(() => {
                        if (this.$refs.calendar && typeof this.$refs.calendar.checkChange === 'function') {
                            this.$refs.calendar.checkChange();
                        }
                    });
                }
            }
        },
        calendarEvents() {
            if (this.$refs.calendar && typeof this.$refs.calendar.checkChange === 'function') {
                this.$nextTick(() => {
                    if (this.$refs.calendar && typeof this.$refs.calendar.checkChange === 'function') {
                        this.$refs.calendar.checkChange();
                    }
                });
            }
        }
    },
    mounted() {
        this.injectStyles();
        this.fetchDashboardData();
    },
    methods: {
        // Weight percentages come from the gradebook as floats; show at most one
        // decimal so "85" doesn't render as "85.00" and "33.33" stays readable.
        formatWeightPct(pct) {
            const n = Number(pct || 0);
            return Number.isInteger(n) ? String(n) : n.toFixed(1);
        },
        // Opens the gradebook setup screen for the class course in a new tab so
        // the teacher doesn't lose the dashboard state.
        openGradebook(classItem) {
            const url = classItem && classItem.gradebook_url;
            if (!url) {
                return;
            }
            window.open(url, '_blank', 'noopener');
        },
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
        async loadCalendarEvents() {
            // Skip if already loaded or already in-flight.
            if (this.calendarEventsLoaded || this.calendarEventsLoading) return;
            this.calendarEventsLoading = true;
            try {
                // Range: today minus 1 month to today plus 6 months — enough for
                // v-calendar to fill its visible window without pulling the
                // entire semester. The AJAX dispatcher wraps the WS handler
                // and returns { status, events: [...] }.
                const initDate = new Date();
                initDate.setMonth(initDate.getMonth() - 1);
                const endDate = new Date();
                endDate.setMonth(endDate.getMonth() + 6);
                const fmt = (d) => {
                    const y = d.getFullYear();
                    const m = String(d.getMonth() + 1).padStart(2, '0');
                    const day = String(d.getDate()).padStart(2, '0');
                    return `${y}-${m}-${day}`;
                };
                const response = await axios.post(window.wsUrl, {
                    action: 'local_grupomakro_calendar_get_calendar_events',
                    args: {
                        userid: window.userId || 0,
                        initDate: fmt(initDate),
                        endDate: fmt(endDate)
                    },
                    ...window.wsStaticParams
                });
                if (response.data && response.data.status === 'success') {
                    const raw = response.data.events;
                    this.calendarEventsData = Array.isArray(raw) ? raw : [];
                    this.calendarEventsLoaded = true;
                    // Force v-calendar to re-render with the freshly loaded events.
                    this.$nextTick(() => {
                        if (this.$refs.calendar && typeof this.$refs.calendar.checkChange === 'function') {
                            this.$refs.calendar.checkChange();
                        }
                    });
                } else {
                    throw new Error((response.data && response.data.message) || 'Error fetching calendar events');
                }
            } catch (error) {
                console.error('Error fetching calendar events:', error);
                this.calendarEventsData = [];
            } finally {
                this.calendarEventsLoading = false;
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
        calendarToday() { this.calendarValue = new Date().toISOString().substr(0, 10); },
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
                .session-name {
                    display: -webkit-box;
                    -webkit-line-clamp: 2;
                    -webkit-box-orient: vertical;
                    overflow: hidden;
                    line-height: 1.35;
                    word-break: break-word;
                }
            `;
            document.head.appendChild(style);
        }
    }
};

window.TeacherDashboard = TeacherDashboard;
