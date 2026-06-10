// Subjects Panel - Modern, clean, drag-friendly subject board
Vue.component('subjects-panel', {
    template: `
    <transition name="tl-slide">
    <aside v-if="visible" class="tl-panel">
        <!-- HEADER -->
        <header class="tl-panel-head">
            <div class="tl-panel-head-top">
                <div>
                    <h3 class="tl-panel-title">
                        <v-icon size="18" color="white">mdi-book-open-variant</v-icon>
                        Tablero de Asignaturas
                    </h3>
                    <div class="tl-panel-sub">
                        Cohorte <strong>{{ cohort }}</strong>
                        <span v-if="jornada && jornada !== 'ALL'"> • {{ jornada }}</span>
                    </div>
                </div>
                <button class="tl-panel-close" @click="$emit('close')" title="Cerrar">
                    <v-icon size="18" color="white">mdi-close</v-icon>
                </button>
            </div>

            <!-- KPI ROW -->
            <div class="tl-panel-kpis">
                <div class="tl-panel-kpi">
                    <div class="tl-panel-kpi-num">{{ counts.total }}</div>
                    <div class="tl-panel-kpi-lbl">Total</div>
                </div>
                <div class="tl-panel-kpi tl-panel-kpi-success">
                    <v-icon size="12" color="#10B981">mdi-check-circle</v-icon>
                    <div class="tl-panel-kpi-num">{{ counts.green }}</div>
                </div>
                <div class="tl-panel-kpi tl-panel-kpi-warning">
                    <v-icon size="12" color="#F59E0B">mdi-alert</v-icon>
                    <div class="tl-panel-kpi-num">{{ counts.orange }}</div>
                </div>
                <div class="tl-panel-kpi tl-panel-kpi-error">
                    <v-icon size="12" color="#EF4444">mdi-close-circle</v-icon>
                    <div class="tl-panel-kpi-num">{{ counts.red }}</div>
                </div>
                <div class="tl-panel-kpi tl-panel-kpi-info">
                    <v-icon size="12" color="#3B82F6">mdi-clock-outline</v-icon>
                    <div class="tl-panel-kpi-num">{{ counts.blue }}</div>
                </div>
            </div>
        </header>

        <!-- SEARCH + FILTERS -->
        <div class="tl-panel-toolbar">
            <div class="tl-panel-search">
                <v-icon size="14" color="#94A3B8">mdi-magnify</v-icon>
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Buscar asignatura..."
                    class="tl-panel-search-input"
                />
                <button v-if="searchQuery" class="tl-panel-search-clear" @click="searchQuery = ''">
                    <v-icon size="12" color="#94A3B8">mdi-close</v-icon>
                </button>
            </div>
            <div class="tl-panel-filters">
                <button
                    v-for="s in filters"
                    :key="s.key"
                    class="tl-filter-btn"
                    :class="['tl-filter-' + s.key, { 'tl-filter-active': activeFilter === s.key }]"
                    @click="activeFilter = activeFilter === s.key ? '' : s.key"
                    :title="s.title"
                >
                    <span class="tl-filter-dot" :class="'tl-dot-' + s.key"></span>
                </button>
            </div>
        </div>

        <!-- CONTENT -->
        <div class="tl-panel-content" v-if="!loading && !error">
            <div v-if="filteredCourses.length === 0" class="tl-panel-empty">
                <v-icon size="40" color="#CBD5E1">mdi-book-off-outline</v-icon>
                <p>Sin asignaturas</p>
            </div>
            <div
                v-for="course in filteredCourses"
                :key="course.id"
                class="tl-subj"
                :class="{
                    'tl-subj-required': course.isrequired,
                    'tl-subj-dragging': isDragging(course),
                    'tl-subj-projected': hasProjection(course)
                }"
                draggable="true"
                @dragstart="onDragStart($event, course)"
                @dragend="onDragEnd($event)"
            >
                <!-- Semaphore dot -->
                <div class="tl-subj-sem" :class="'tl-sem-' + getSemaphore(course)">
                    <span class="tl-subj-sem-dot"></span>
                </div>

                <!-- Body -->
                <div class="tl-subj-body">
                    <div class="tl-subj-name-row">
                        <span class="tl-subj-name" :title="course.fullname">{{ course.fullname }}</span>
                        <span v-if="course.isrequired" class="tl-subj-badge">REQ</span>
                        <span v-if="hasProjection(course)" class="tl-subj-badge tl-subj-badge-proj" title="Tiene proyección">
                            <v-icon size="9" color="white">mdi-calendar-check</v-icon>
                        </span>
                    </div>
                    <div class="tl-subj-meta">
                        <span class="tl-subj-meta-item">
                            <v-icon size="10" color="#94A3B8">mdi-counter</v-icon>
                            {{ course.position || '—' }}
                        </span>
                        <span class="tl-subj-meta-item" v-if="course.credits">
                            <v-icon size="10" color="#94A3B8">mdi-star-outline</v-icon>
                            {{ course.credits }} cr
                        </span>
                        <span class="tl-subj-meta-item" v-if="course.subperiod_name">
                            <v-icon size="10" color="#94A3B8">mdi-calendar-blank-outline</v-icon>
                            {{ course.subperiod_name }}
                        </span>
                    </div>
                </div>

                <!-- Stats pill -->
                <div class="tl-subj-stats" :class="'tl-stats-' + getSemaphore(course)">
                    <template v-if="getSemaphore(course) === 'green'">
                        <v-icon size="11" color="#10B981">mdi-check-circle</v-icon>
                        <span class="tl-subj-stats-num">{{ course.status.approved_count }}</span>
                    </template>
                    <template v-else-if="getSemaphore(course) === 'red'">
                        <v-icon size="11" color="#EF4444">mdi-close-circle</v-icon>
                        <span class="tl-subj-stats-num">{{ course.status.failed_count }}</span>
                    </template>
                    <template v-else-if="getSemaphore(course) === 'orange'">
                        <v-icon size="11" color="#10B981">mdi-check</v-icon>
                        <span class="tl-subj-stats-num" style="color:#10B981;">{{ course.status.approved_count }}</span>
                        <v-icon size="11" color="#EF4444" class="ml-1">mdi-close</v-icon>
                        <span class="tl-subj-stats-num" style="color:#EF4444;">{{ course.status.failed_count }}</span>
                    </template>
                    <template v-else>
                        <v-icon size="11" color="#3B82F6">mdi-clock-outline</v-icon>
                        <span class="tl-subj-stats-num">{{ course.status.pending_count || 0 }}</span>
                    </template>
                </div>

                <!-- Drag handle -->
                <div class="tl-subj-handle">
                    <v-icon size="14" color="#CBD5E1">mdi-drag-vertical</v-icon>
                </div>
            </div>
        </div>

        <!-- LOADING -->
        <div v-else-if="loading" class="tl-panel-content">
            <div v-for="n in 5" :key="n" class="tl-subj-skel">
                <div class="tl-skel" style="width: 8px; height: 32px; border-radius: 4px;"></div>
                <div class="flex-grow-1">
                    <div class="tl-skel mb-1" style="height: 14px; width: 80%;"></div>
                    <div class="tl-skel" style="height: 10px; width: 50%;"></div>
                </div>
            </div>
        </div>

        <!-- ERROR -->
        <div v-else class="tl-panel-error">
            <v-icon size="32" color="#EF4444">mdi-alert-circle-outline</v-icon>
            <p>{{ error }}</p>
            <button class="tl-btn-secondary" @click="loadCourses">Reintentar</button>
        </div>
    </aside>
    </transition>
    `,
    props: {
        learningPlanId: { type: Number, required: true },
        cohort: { type: String, default: '2026' },
        jornada: { type: String, default: 'ALL' },
        visible: { type: Boolean, default: false },
    },
    data() {
        return {
            courses: [],
            loading: false,
            error: null,
            draggedCourseData: null,
            searchQuery: '',
            activeFilter: '',
            filters: [
                { key: 'green',  title: 'Aprobadas' },
                { key: 'orange', title: 'Mixtas' },
                { key: 'red',    title: 'Reprobadas' },
                { key: 'blue',   title: 'Pendientes' },
            ],
        };
    },
    computed: {
        filteredCourses() {
            let courses = this.courses || [];
            if (this.searchQuery) {
                const q = this.searchQuery.toLowerCase();
                courses = courses.filter(c =>
                    (c.fullname || '').toLowerCase().includes(q) ||
                    (c.shortname || '').toLowerCase().includes(q)
                );
            }
            if (this.activeFilter) {
                courses = courses.filter(c => this.getSemaphore(c) === this.activeFilter);
            }
            return courses;
        },
        counts() {
            const c = { total: 0, green: 0, orange: 0, red: 0, blue: 0 };
            (this.courses || []).forEach(course => {
                c.total++;
                c[this.getSemaphore(course)]++;
            });
            return c;
        },
    },
    watch: {
        visible(v) { if (v && !(this.courses && this.courses.length)) this.loadCourses(); },
        jornada()  { if (this.visible) this.loadCourses(); },
        cohort()   { if (this.visible) { this.courses = []; this.loadCourses(); } },
    },
    methods: {
        loadCourses() {
            this.loading = true;
            this.error = null;
            const wsUrl = window.location.origin + '/webservice/rest/server.php';
            const params =
                'wstoken=' + window.userToken +
                '&wsfunction=local_grupomakro_get_courses_with_projections' +
                '&moodlewsrestformat=json' +
                '&learningplanid=' + this.learningPlanId +
                '&cohort=' + encodeURIComponent(this.cohort) +
                '&jornada=' + encodeURIComponent(this.jornada || 'ALL');

            fetch(wsUrl + '?' + params)
                .then(r => r.json())
                .then(data => {
                    if (data.exception) {
                        this.error = data.message || 'Error desconocido';
                        this.loading = false;
                        return;
                    }
                    this.courses = data.courses || [];
                    this.loading = false;
                })
                .catch(err => {
                    this.error = 'Error de conexión al cargar asignaturas.';
                    this.loading = false;
                    console.error('Error loading courses:', err);
                });
        },
        getSemaphore(course) {
            return course.status ? course.status.semaphore : 'blue';
        },
        hasProjection(course) {
            return course.projections && course.projections.length > 0;
        },
        isDragging(course) {
            return this.draggedCourseData && this.draggedCourseData.id === course.id;
        },
        onDragStart(event, course) {
            this.draggedCourseData = course;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('application/json', JSON.stringify({
                id: course.id,
                courseid: course.courseid,
                name: course.fullname,
                position: course.position,
                subperiodid: course.subperiodid,
                subperiod_name: course.subperiod_name,
                periodid: course.periodid,
            }));
            event.target.classList.add('tl-subj-dragging-active');
        },
        onDragEnd(event) {
            event.target.classList.remove('tl-subj-dragging-active');
            this.draggedCourseData = null;
        },
        refresh() { this.loadCourses(); },
    },
});
