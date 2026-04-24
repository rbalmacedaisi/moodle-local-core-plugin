// Courses Panel Component
define(['jquery', 'core/ajax'], function($, Ajax) {
    return {
        template: 
        '<div class="courses-panel" v-if="visible">\
          <div class="panel-header">\
            <h3>Asignaturas</h3>\
            <button class="btn-close-panel" @click="$emit(\'close\')">×</button>\
          </div>\
          <div class="panel-search">\
            <input type="text" v-model="searchQuery" placeholder="Buscar asignatura..." class="search-input" />\
          </div>\
          <div class="panel-content">\
            <div v-if="loading" class="loading-state">\
              <v-progress-circular indeterminate color="primary"></v-progress-circular>\
            </div>\
            <div v-else-if="error" class="error-state">{{ error }}</div>\
            <div v-else class="courses-container">\
              <div v-for="course in filteredCourses" :key="course.id" \
                   class="course-card"\
                   :class="{ required: course.isrequired, dragging: isDragging(course) }"\
                   draggable="true"\
                   @dragstart="onDragStart($event, course)"\
                   @dragend="onDragEnd($event)">\
                <div class="course-card-header">\
                  <span class="course-position">{{ course.position }}</span>\
                  <span class="course-name" :title="course.fullname">{{ course.fullname }}</span>\
                  <span v-if="course.isrequired" class="required-badge">Req</span>\
                </div>\
                <div class="course-card-meta">\
                  <span class="meta-item">{{ course.period_name }}</span>\
                  <span class="meta-sep">·</span>\
                  <span class="meta-item">{{ course.subperiod_name }}</span>\
                </div>\
                <div class="course-card-stats">\
                  <div class="stat-item">\
                    <v-icon small>mdi-account-group</v-icon>\
                    <span class="stat-num">{{ course.enrolled_count }}</span>\
                    <span class="stat-label">inscritos</span>\
                  </div>\
                  <div class="stat-item pending" :class="{ active: course.pending_count > 0 }">\
                    <v-icon small>mdi-clock-alert</v-icon>\
                    <span class="stat-num">{{ course.pending_count }}</span>\
                    <span class="stat-label">pendientes</span>\
                  </div>\
                  <div class="stat-item credits" v-if="course.credits">\
                    <v-icon small>mdi-school</v-icon>\
                    <span class="stat-num">{{ course.credits }}</span>\
                    <span class="stat-label">créd</span>\
                  </div>\
                </div>\
              </div>\
              <div v-if="filteredCourses.length === 0 && !loading" class="no-courses">\
                No hay asignaturas\
              </div>\
            </div>\
          </div>\
        </div>',
        
        props: {
            learningPlanId: {
                type: Number,
                required: true
            },
            visible: {
                type: Boolean,
                default: false
            }
        },
        
        data: function() {
            return {
                courses: [],
                loading: false,
                error: null,
                draggedCourseData: null,
                searchQuery: ''
            };
        },
        
        computed: {
            filteredCourses: function() {
                if (!this.searchQuery) return this.courses;
                var q = this.searchQuery.toLowerCase();
                return this.courses.filter(function(c) {
                    return c.fullname.toLowerCase().indexOf(q) !== -1 ||
                           (c.shortname && c.shortname.toLowerCase().indexOf(q) !== -1);
                });
            }
        },
        
        watch: {
            learningPlanId: function(newId) {
                if (newId) {
                    this.loadCourses();
                }
            },
            visible: function(newVal) {
                if (newVal && !this.courses.length) {
                    this.loadCourses();
                }
            }
        },
        
        mounted: function() {
            if (this.learningPlanId && this.visible) {
                this.loadCourses();
            }
        },
        
        methods: {
            loadCourses: function() {
                var self = this;
                this.loading = true;
                this.error = null;
                
                Ajax.call([{
                    methodname: 'local_grupomakro_get_courses_by_learning_plan',
                    args: {
                        learningplanid: self.learningPlanId
                    },
                    done: function(response) {
                        self.courses = response.courses || [];
                        self.loading = false;
                    },
                    fail: function(ex) {
                        self.error = 'Error cargando asignaturas';
                        self.loading = false;
                        console.error('Error loading courses:', ex);
                    }
                }]);
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
    };
});

// Register the component
Vue.component('courses-panel', function(resolve) {
    require(['local_grupomakro_core/components/panels/coursespanel'], function(module) {
        resolve(module);
    });
});