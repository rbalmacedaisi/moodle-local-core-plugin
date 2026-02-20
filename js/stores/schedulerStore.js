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
            // Ensure period dates are available for duration calculation
            if (res.period) {
                this.state.activePeriodDates = res.period;
            }
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
                const configSettings = this.state.context.configSettings || {};
                const isolatedCareers = new Set(configSettings.isolatedCareers || []);

                if (!demand || Object.keys(demand).length === 0) {
                    console.warn("SchedulerStore: No demand data available.");
                    this.state.generatedSchedules = [];
                    this.state.students = [];
                    this.state.loading = false;
                    return;
                }

                const MAX_CAPACITY = (configSettings.maxRoomCapacity) ? parseInt(configSettings.maxRoomCapacity) : 40;

                // --- Demand Aggregation ---
                // Key: courseId | shift [| career if isolated]
                const aggregatedDemand = {};

                for (const career of Object.keys(demand)) {
                    if (!demand[career]) continue;
                    const isIsolated = isolatedCareers.has(career);

                    for (const shift of Object.keys(demand[career])) {
                        if (!demand[career][shift]) continue;

                        for (const sem of Object.keys(demand[career][shift])) {
                            const semData = demand[career][shift][sem];
                            // course_counts: { courseId: { count, students: [] } }
                            for (const [courseId, val] of Object.entries(semData.course_counts)) {
                                let studentIds = [];
                                if (val && typeof val === 'object') {
                                    studentIds = val.students || [];
                                }

                                if (studentIds.length === 0) continue;

                                // Ignore if status is 2 (Omitir Auto)
                                const projection = this.state.projections.find(p => p.courseid == courseId);
                                if (projection && projection.status == 2) continue;

                                const subperiodId = val.subperiod || 0;
                                const aggKey = isIsolated ? `${courseId}|${shift}|${career}|${subperiodId}` : `${courseId}|${shift}|${subperiodId}`;
                                if (!aggregatedDemand[aggKey]) {
                                    aggregatedDemand[aggKey] = {
                                        courseid: courseId,
                                        shift: shift,
                                        subperiod: subperiodId,
                                        students: [],
                                        careers: new Set(),
                                        levels: new Set()
                                    };
                                }
                                aggregatedDemand[aggKey].students.push(...studentIds);
                                aggregatedDemand[aggKey].careers.add(career);
                                aggregatedDemand[aggKey].levels.add(semData.semester_name);
                            }
                        }
                    }
                }

                // --- Object Creation ---
                for (const [aggKey, data] of Object.entries(aggregatedDemand)) {
                    const totalStudents = data.students;
                    const count = totalStudents.length;
                    const numGroups = Math.ceil(count / MAX_CAPACITY);
                    // Equitable distribution: divide total students by number of groups
                    const baseCount = Math.floor(count / numGroups);
                    const remainder = count % numGroups;

                    let offset = 0;
                    for (let i = 0; i < numGroups; i++) {
                        // Distribute the remainder (extra students) among the first few groups
                        const currentGroupSize = (i < remainder) ? (baseCount + 1) : baseCount;
                        const groupStudents = totalStudents.slice(offset, offset + currentGroupSize);
                        const groupCount = groupStudents.length;
                        offset += currentGroupSize;

                        schedules.push({
                            id: `gen-${idCounter++}`,
                            courseid: data.courseid,
                            subjectName: (this.state.subjects[data.courseid] ? this.state.subjects[data.courseid].name : `Materia: ${data.courseid}`),
                            teacherName: null,
                            day: 'N/A',
                            start: '00:00',
                            end: '00:00',
                            room: 'Sin aula',
                            studentCount: groupCount,
                            career: Array.from(data.careers).join(', '),
                            careerList: Array.from(data.careers), // For algorithm use
                            shift: data.shift,
                            levelDisplay: Array.from(data.levels).join(', '),
                            levelList: Array.from(data.levels), // For algorithm use
                            subGroup: i + 1,
                            subperiod: data.subperiod,
                            studentIds: groupStudents
                        });
                    }
                }

                // 2. Extracted Availability
                const availability = this.state.instructors.map(inst => ({
                    teacherName: inst.firstname + ' ' + inst.lastname,
                    instructorId: inst.id,
                    subjectName: inst.competency || '',
                    day: inst.day,
                    timeRange: `${inst.starttime}-${inst.endtime}`
                }));

                // 3. AUTO-PLACEMENT (Determine Days and Times first)
                let finalResult = window.SchedulerAlgorithm.autoPlace(schedules, {
                    classrooms: this.state.context.classrooms || [],
                    loads: this.state.context.loads || [],
                    period: this.state.activePeriodDates || {},
                    configSettings: this.state.context.configSettings || {}
                });

                // 4. AUTO-ASSIGN TEACHERS to the placed schedules
                finalResult = window.SchedulerAlgorithm.autoAssign(finalResult, availability);

                this.state.generatedSchedules = finalResult;
                this.state.successMessage = "Horarios generados y ubicados automáticamente";

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

        async saveConfigSettings(periodId, settings) {
            this.state.loading = true;
            try {
                // The backend requires holidays and loads to be passed too, otherwise they might get wiped depending on implementation
                // Alternatively, we pass them as they are in the context
                await this._fetch('local_grupomakro_save_scheduler_config', {
                    periodid: periodId,
                    holidays: this.state.context.holidays || [],
                    loads: this.state.context.loads || [],
                    configsettings: JSON.stringify(settings)
                });

                // Keep local state in sync
                this.state.context.configSettings = settings;
                this.state.successMessage = "Configuración guardada correctamente";
            } catch (e) {
                console.error("Save config error:", e);
                this.state.error = "Error al guardar configuración: " + (e.message || "Desconocido");
            } finally {
                this.state.loading = false;
            }
        },

        async uploadSubjectLoads(file) {
            if (!window.XLSX) {
                this.state.error = "Librería XLSX no cargada";
                return;
            }

            this.state.loading = true;
            try {
                const data = await file.arrayBuffer();
                const workbook = window.XLSX.read(data);
                const worksheet = workbook.Sheets[workbook.SheetNames[0]];
                const jsonData = window.XLSX.utils.sheet_to_json(worksheet);

                const loads = jsonData.map(row => {
                    const subjectName = row['Asignatura'] || row['ASIGNATURA'] || row['Materia'];
                    const totalHours = row['Horas'] || row['HORAS'] || row['Total'];
                    const intensity = row['Intensidad'] || row['INTENSIDAD'] || row['Sesión'];

                    if (subjectName && totalHours) {
                        return {
                            subjectName: String(subjectName).trim(),
                            totalHours: parseFloat(totalHours),
                            intensity: intensity ? parseFloat(intensity) : null
                        };
                    }
                    return null;
                }).filter(l => l !== null);

                // Save to local context for immediate use
                this.state.context.loads = loads;

                // Persist via AJAX if possible (Need to implement backend endpoint save_scheduler_loads)
                // For now, we keep it in state context.
                this.state.successMessage = `Cargadas ${loads.length} asignaturas`;
            } catch (e) {
                console.error("Excel Load Error", e);
                this.state.error = "Error procesando el archivo de cargas";
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

            if (json.error || json.errorcode || json.status === 'error') {
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
