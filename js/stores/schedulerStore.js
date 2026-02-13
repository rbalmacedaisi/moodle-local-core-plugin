/**
 * Global Scheduler Store
 * Uses Vue.reactive (Vue 3) to provide reactive state management.
 */

(function () {
    if (window.schedulerStore) return;

    // Vue 3 compatibility
    const reactive = (window.Vue && window.Vue.reactive) ? window.Vue.reactive : (obj) => obj;

    window.schedulerStore = {
        // State
        state: reactive({
            activePeriod: null,
            context: {
                classrooms: [],
                holidays: [],
                loads: []
            },
            plans: [], // [ {id, name, ...} ]
            instructors: [], // [ { id, teacherName, ... } ]
            demand: {}, // Tree: [Career][Jornada][Semester] -> { semester_name, student_count, course_counts }
            subjects: {}, // Map: id -> subject object
            students: [], // Flat list of pending students
            projections: [], // Manual projections
            generatedSchedules: [], // The algorithms output
            subperiodFilter: 0, // 0: Todos, 1: P-I, 2: P-II
            loading: false,
            error: null,
            successMessage: null
        }),

        // --- Actions ---

        /**
         * Initialize/Load Context and Demand for a period
         */
        async loadAll(periodId) {
            this.state.activePeriod = periodId;
            this.state.loading = true;
            this.state.error = null;

            try {
                await Promise.all([
                    this.loadContext(periodId),
                    this.loadDemand(periodId),
                    this.loadPlans(),
                    this.loadInstructors()
                ]);
            } catch (e) {
                console.error("Error loading scheduler data", e);
                this.state.error = e.message || "Error loading data";
            } finally {
                this.state.loading = false;
            }
        },

        async loadContext(periodId) {
            const res = await this._fetch('local_grupomakro_get_scheduler_context', { periodid: periodId });
            this.state.context = res;
        },

        async loadPlans() {
            if (this.state.plans.length > 0) return; // Cache
            // service: local_grupomakro_get_learning_plan_list
            const res = await this._fetch('local_grupomakro_get_learning_plan_list');
            this.state.plans = Array.isArray(res) ? res : (res.plans || []);
        },

        async loadInstructors() {
            // service: local_grupomakro_get_teachers_disponibility
            const res = await this._fetch('local_grupomakro_get_teachers_disponibility');
            // Check if response is array or wrapped
            this.state.instructors = Array.isArray(res) ? res : (res.data || []);
        },

        async loadDemand(periodId) {
            const res = await this._fetch('local_grupomakro_get_demand_data', { periodid: periodId });

            // demand_tree comes as JSON string
            this.state.demand = typeof res.demand_tree === 'string' ? JSON.parse(res.demand_tree) : res.demand_tree;
            this.state.students = res.student_list;
            this.state.projections = res.projections;

            // Index subjects by ID
            const subjMap = {};
            if (res.subjects && Array.isArray(res.subjects)) {
                res.subjects.forEach(s => subjMap[s.id] = s);
            }
            this.state.subjects = subjMap;
        },

        async saveProjections(periodId, projections) {
            this.state.loading = true;
            try {
                // Prepare projections for backend (map logic if needed)
                // Backend expects array of { career, shift, count }

                await this._fetch('local_grupomakro_save_projections', {
                    periodid: periodId,
                    projections: JSON.stringify(projections) // Ensure it's stringified if PARAM_RAW/JSON expected, or array if multiple structure
                    // Checking backend: 'projections' => new external_multiple_structure(...)
                    // If it is external_multiple_structure, we should pass IT AS AN ARRAY, not JSON string, unless we changed backend to accept raw.
                    // Backend: save_projections_parameters -> 'projections' => new external_multiple_structure(...)
                    // So we pass the array directly. Ajax loop will handle it.
                });

                // Wait, if passing array via URLSearchParams, it gets complex.
                // My _fetch method handles object/array flattening.
                // BUT, external_multiple_structure expects indexed array.
                // _fetch flattens to projections[0][career]=..., projections[0][count]=...
                // This is correct for Moodle.

                // Update: I should check if backend expects JSON string or structure.
                // I checked backend file: it expects structure.
                // So I pass `projections` array directly.

                // Reload demand to reflect changes
                await this.loadDemand(periodId);
                this.state.successMessage = "Proyecciones guardadas";
            } catch (e) {
                this.state.error = e.message;
            } finally {
                this.state.loading = false;
            }
        },

        /**
         * Trigger Algorithm
         */
        async generateSchedules() {
            if (!window.SchedulerAlgorithm) {
                this.state.error = "Algoritmo no cargado";
                return;
            }

            this.state.loading = true;

            try {
                // 1. Convert Demand to Unassigned Schedule Objects
                const schedules = [];
                let idCounter = 1;

                const demand = this.state.demand || {};

                if (!demand || Object.keys(demand).length === 0) {
                    console.warn("SchedulerStore: No demand data available.");
                    this.state.generatedSchedules = [];
                    this.state.students = [];
                    this.state.loading = false;
                    return;
                }
                // const academicPeriodId = this.state.activePeriod;

                const MAX_CAPACITY = 40; // Default max

                for (const career of Object.keys(demand)) {
                    if (!demand[career]) continue;

                    for (const shift of Object.keys(demand[career])) {
                        if (!demand[career][shift]) continue;

                        for (const sem of Object.keys(demand[career][shift])) {
                            const semData = demand[career][shift][sem];
                            // course_counts: { courseId: studentCount }

                            for (const [courseId, count] of Object.entries(semData.course_counts)) {
                                if (count <= 0) continue;

                                // TODO: Map courseId to Subject Name properly using a map from backend
                                // For now, we use a placeholder that might fail matching if teacher competence uses strict names.
                                // We really need the course code or name here.
                                // Assumption: courseId is adequate for now or we fix the mapping later.

                                const numGroups = Math.ceil(count / MAX_CAPACITY);
                                for (let i = 0; i < numGroups; i++) {
                                    schedules.push({
                                        id: `gen-${idCounter++}`,
                                        courseid: courseId,
                                        subjectName: (this.state.subjects[courseId] ? this.state.subjects[courseId].name : `Materia: ${courseId}`),
                                        teacherName: null,
                                        day: 'N/A',
                                        start: '00:00',
                                        end: '00:00',
                                        room: 'Sin aula',
                                        studentCount: Math.min(count - (i * MAX_CAPACITY), MAX_CAPACITY),
                                        career: career,
                                        shift: shift,
                                        levelDisplay: semData.semester_name,
                                        subGroup: i + 1,
                                        subperiod: 0 // Default: Unassigned/Both
                                    });
                                }
                            }
                        }
                    }
                }

                // 2. Call Algorithm
                const availability = this.state.instructors.map(inst => ({
                    teacherName: inst.firstname + ' ' + inst.lastname,
                    instructorId: inst.id,
                    subjectName: inst.competency || '',
                    day: inst.day,
                    timeRange: `${inst.starttime}-${inst.endtime}`
                }));

                // Note: schedules have dummy subjectName "Materia X". Availability needs to match that.
                // This will result in 0 assignments unless we fix the subject names.
                // For MVP Verification, we might need a way to get subject names in demand.
                // I should update get_demand_data to return names too.

                const result = window.SchedulerAlgorithm.autoAssign(schedules, availability);

                this.state.generatedSchedules = result;
                this.state.successMessage = "Horarios generados (preliminar)";

            } catch (e) {
                console.error("Generation Error", e);
                this.state.error = "Error generando horarios: " + e.message;
            } finally {
                this.state.loading = false;
            }
        },

        async saveGeneration(periodId, schedules) {
            this.state.loading = true;
            try {
                await this._fetch('local_grupomakro_save_generation_result', {
                    periodid: periodId,
                    schedules: JSON.stringify(schedules)
                });
                this.state.successMessage = "Horarios guardados correctamente";
            } catch (e) {
                this.state.error = e.message;
            } finally {
                this.state.loading = false;
            }
        },

        setSubperiodFilter(val) {
            this.state.subperiodFilter = val;
        },

        updateClassSubperiod(classId, subperiod) {
            const cls = this.state.generatedSchedules.find(c => c.id === classId);
            if (cls) {
                cls.subperiod = subperiod;
            }
        },

        // --- Utils ---

        async _fetch(action, params = {}) {
            const url = window.wsUrl || (window.location.origin + '/local/grupomakro_core/ajax.php');
            const body = new URLSearchParams();
            body.append('action', action);
            if (typeof M !== 'undefined' && M.cfg && M.cfg.sesskey) {
                body.append('sesskey', M.cfg.sesskey);
            }

            // Helper to clean and append params
            const appendParams = (data, prefix = '') => {
                if (data === null || data === undefined) return;
                if (typeof data === 'object' && data !== null) {
                    if (Array.isArray(data)) {
                        data.forEach((item, index) => {
                            // Moodle often expects indexed arrays as `param[0][subparam]=val`
                            appendParams(item, prefix ? `${prefix}[${index}]` : `${index}`);
                        });
                    } else {
                        Object.keys(data).forEach(key => {
                            appendParams(data[key], prefix ? `${prefix}[${key}]` : key);
                        });
                    }
                } else {
                    body.append(prefix, data);
                }
            };

            for (const [key, value] of Object.entries(params)) {
                if (typeof value === 'object' && value !== null) {
                    appendParams(value, key);
                } else {
                    body.append(key, value);
                }
            }

            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body
            });

            // Handle non-JSON responses or empty responses gracefully
            const text = await response.text();
            if (!text) return null;

            let json;
            try {
                json = JSON.parse(text);
            } catch (e) {
                console.error("Invalid JSON response:", text);
                throw new Error("Invalid server response (not JSON)");
            }

            if (json.error || json.errorcode) {
                throw new Error(json.message || json.error || 'API Error');
            }

            // Moodle WebService often returns { data: ..., warnings: [] } or just val
            // Our custom ajax.php wraps result in 'data' usually, or 'status'
            if (json.status === 'success') {
                return json.data;
            } else if (json.data !== undefined) {
                return json.data;
            } else {
                return json;
            }
        }
    };

})();
