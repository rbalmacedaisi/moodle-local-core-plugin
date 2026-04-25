// Subjects Panel - Compact course list with semaphore for Timeline Planning
Vue.component('subjects-panel', {
    template: 
    '<div class="subjects-panel" v-if="visible">' +
    '  <div class="panel-header">' +
    '    <h3>{{ panelTitle }}</h3>' +
    '    <span class="badge-count">{{ filteredCourses.length }}</span>' +
    '    <button class="btn-close-panel" @click="$emit(\'close\')">×</button>' +
    '  </div>' +
    '  <div class="panel-search">' +
    '    <input type="text" v-model="searchQuery" placeholder="Buscar..." class="search-input" />' +
    '  </div>' +
    '  <div class="semaphore-filter">' +
    '    <button v-for="s in ['+']" :key="s" class="sem-btn" :class="activeFilter === s ? \'active\' : \'\'" @click="activeFilter = activeFilter === s ? \'\' : s">' +
    '      <span class="sem-dot" :class="s"></span>' +
    '    </button>' +
    '  </div>' +
    '  <div class="panel-content">' +
    '    <div v-if="loading" class="loading-state">' +
    '      <v-progress-circular indeterminate color="primary" size="20"></v-progress-circular>' +
    '    </div>' +
    '    <div v-else-if="error" class="error-state">{{ error }}</div>' +
    '    <div v-else class="subjects-list">' +
    '      <div v-for="course in filteredCourses" :key="course.id" ' +
    '           class="subject-row"' +
    '           :class="{ required: course.isrequired, dragging: isDragging(course), \'has-projection\': hasProjection(course) }"' +
    '           draggable="true"' +
    '           @dragstart="onDragStart($event, course)"' +
    '           @dragend="onDragEnd($event)">' +
    '        <div class="sem-indicator" :class="getSemaphore(course)">' +
    '          <span class="sem-dot"></span>' +
    '        </div>' +
    '        <div class="subject-info">' +
    '          <span class="subject-name" :title="course.fullname">{{ course.fullname }}</span>' +
    '          <span class="subject-meta">{{ course.subperiod_name || \'Sin bimestre\' }}</span>' +
    '        </div>' +
    '        <div class="subject-stats">' +
    '          <span v-if="getSemaphore(course) === \'green\'" class="stat approved">' +
    '            <v-icon small color="green">mdi-check-circle</v-icon>' +
    '            {{ course.status.approved_count }}' +
    '          </span>' +
    '          <span v-else-if="getSemaphore(course) === \'red\'" class="stat failed">' +
    '            <v-icon small color="red">mdi-close-circle</v-icon>' +
    '            {{ course.status.failed_count }}' +
    '          </span>' +
    '          <span v-else-if="getSemaphore(course) === \'orange\'" class="stat mixed">' +
    '            <v-icon small color="orange">mdi-alert-circle</v-icon>' +
    '            {{ course.status.approved_count }}/{{ course.status.failed_count }}' +
    '          </span>' +
    '          <span v-else class="stat pending">' +
    '            <v-icon small color="blue">mdi-clock-outline</v-icon>' +
    '            {{ course.status.pending_count || \'-\' }}' +
    '          </span>' +
    '        </div>' +
    '        <div class="drag-handle">' +
    '          <v-icon small>mdi-drag-vertical</v-icon>' +
    '        </div>' +
    '      </div>' +
    '      <div v-if="filteredCourses.length === 0 && !loading" class="no-data">' +
    '        Sin asignaturas' +
    '      </div>' +
    '    </div>' +
    '  </div>' +
    '</div>',
    
    props: {
        learningPlanId: { type: Number, required: true },
        cohort: { type: String, default: '2026' },
        jornada: { type: String, default: 'ALL' },
        visible: { type: Boolean, default: false }
    },
    
    computed: {
        panelTitle: function() {
            return 'Asignaturas' + (this.cohort ? ' - ' + this.cohort : '');
        },
        filteredCourses: function() {
            var self = this;
            var courses = this.courses || [];
            
            if (this.searchQuery) {
                var q = this.searchQuery.toLowerCase();
                courses = courses.filter(function(c) {
                    return c.fullname.toLowerCase().indexOf(q) !== -1 ||
                           (c.shortname && c.shortname.toLowerCase().indexOf(q) !== -1);
                });
            }
            
            if (this.activeFilter) {
                courses = courses.filter(function(c) {
                    return self.getSemaphore(c) === self.activeFilter;
                });
            }
            
            return courses;
        }
    },
    
    watch: {
        visible: function(newVal) {
            if (newVal && !(this.courses && this.courses.length)) {
                this.loadCourses();
            }
        },
        jornada: function() {
            if (this.visible) {
                this.loadCourses();
            }
        }
    },
    
    methods: {
        loadCourses: function() {
            var self = this;
            this.loading = true;
            this.error = null;
            
            var wsUrl = window.location.origin + '/webservice/rest/server.php';
            var token = window.userToken;
            var params = 'wstoken=' + token + 
                         '&wsfunction=local_grupomakro_get_courses_with_projections' +
                         '&moodlewsrestformat=json' +
                         '&learningplanid=' + this.learningPlanId +
                         '&cohort=' + this.cohort +
                         '&jornada=' + (this.jornada || 'ALL');
            
            fetch(wsUrl + '?' + params)
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (data.exception) {
                        self.error = 'Error: ' + (data.message || 'Error desconocido');
                        self.loading = false;
                        return;
                    }
                    self.courses = data.courses || [];
                    self.loading = false;
                })
                .catch(function(err) {
                    self.error = 'Error de conexión';
                    self.loading = false;
                    console.error('Error loading courses:', err);
                });
        },
        
        getSemaphore: function(course) {
            return course.status ? course.status.semaphore : 'blue';
        },
        
        hasProjection: function(course) {
            return course.projections && course.projections.length > 0;
        },
        
        isDragging: function(course) {
            return this.draggedCourseData && this.draggedCourseData.id === course.id;
        },
        
        onDragStart: function(event, course) {
            this.draggedCourseData = course;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('application/json', JSON.stringify({
                id: course.id,
                courseid: course.courseid,
                name: course.fullname,
                position: course.position,
                subperiodid: course.subperiodid,
                subperiod_name: course.subperiod_name,
                periodid: course.periodid
            }));
            $(event.target).addClass('is-dragging');
        },
        
        onDragEnd: function(event) {
            $(event.target).removeClass('is-dragging');
            this.draggedCourseData = null;
        },
        
        refresh: function() {
            this.loadCourses();
        }
    }
});