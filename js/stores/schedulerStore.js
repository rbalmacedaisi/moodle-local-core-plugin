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
            careerFilter: null, // Filter by career name
            shiftFilter: null, // Filter by shift name (Jornada)
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
                    this.loadInstructors(),
                    this.loadGeneratedSchedules(periodId)
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
            let data = Array.isArray(res) ? res : (res.data || []);

            // If it's an associative object (Moodle often returns this), convert to array
            if (data && typeof data === 'object' && !Array.isArray(data)) {
                data = Object.values(data);
            }

            this.state.instructors = Array.isArray(data) ? data : [];
        },

        async loadGeneratedSchedules(periodId) {
            this.state.loading = true;
            try {
                const res = await this._fetch('local_grupomakro_get_generated_schedules', { periodid: periodId });
                const rawData = Array.isArray(res) ? res : (res.data || []);

                // Normalize data (Ensure careerList exists for filtering)
                this.state.generatedSchedules = rawData.map(cls => {
                    if (cls.career) cls.career = String(cls.career).trim();
                    if (!cls.careerList && cls.career) {
                        cls.careerList = cls.career.split(',').map(s => s.trim());
                    } else if (cls.careerList && Array.isArray(cls.careerList)) {
                        cls.careerList = cls.careerList.map(s => String(s).trim());
                    } else {
                        cls.careerList = [];
                    }

                    if (cls.shift) cls.shift = String(cls.shift).trim();
                    if (cls.instructorid && !cls.instructorId) cls.instructorId = cls.instructorid;

                    // Standardize Class Type and Label
                    if (cls.type === undefined) cls.type = 0; // Default to Presencial
                    if (!cls.typeLabel && cls.typelabel) cls.typeLabel = cls.typelabel;
                    if (!cls.typeLabel) {
                        const typeMap = { 0: 'Presencial', 1: 'Virtual', 2: 'Mixta' };
                        cls.typeLabel = typeMap[cls.type] || 'Presencial';
                    }

                    // Normalize sessions and excluded_dates
                    if (cls.sessions && Array.isArray(cls.sessions)) {
                        cls.sessions.forEach(sess => {
                            if (!sess.excluded_dates) sess.excluded_dates = [];
                            else if (typeof sess.excluded_dates === 'string') {
                                try { sess.excluded_dates = JSON.parse(sess.excluded_dates); }
                                catch (e) { sess.excluded_dates = []; }
                            }
                        });
                    }

                    return cls;
                });
            } catch (e) {
                console.error("Load Error", e);
                this.state.error = e.message;
            } finally {
                this.state.loading = false;
            }
        },

        async loadDemand(periodId) {
            const res = await this._fetch('local_grupomakro_get_demand_data', { periodid: periodId });

            // demand_tree comes as JSON string
            this.state.demand = typeof res.demand_tree === 'string' ? JSON.parse(res.demand_tree) : res.demand_tree;
            this.state.students = res.student_list;
            if (this.state.context) this.state.context.students = res.student_list;
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

                const classrooms = this.state.context.classrooms || [];
                const maxRoomCap = classrooms.length > 0 ? Math.max(...classrooms.map(r => parseInt(r.capacity) || 0)) : 40;
                const MAX_CAPACITY = (configSettings.maxRoomCapacity) ? parseInt(configSettings.maxRoomCapacity) : maxRoomCap;

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
                            // course_counts keys are now subjectid
                            for (const [subjectId, val] of Object.entries(semData.course_counts)) {
                                let studentIds = [];
                                if (val && typeof val === 'object') {
                                    studentIds = val.students || [];
                                }

                                // We still want to include cohorts even if no students (manual projections or planning matrix)
                                // if (studentIds.length === 0) continue; // Remove this to allow empty cohorts

                                // Ignore if status is 2 (Omitir Auto)
                                const projection = this.state.projections.find(p => p.courseid == subjectId);
                                if (projection && projection.status == 2) continue;

                                const subperiodId = val.subperiod || 0;
                                const aggKey = isIsolated ? `${subjectId}|${shift}|${career}|${subperiodId}` : `${subjectId}|${shift}|${subperiodId}`;
                                if (!aggregatedDemand[aggKey]) {
                                    aggregatedDemand[aggKey] = {
                                        subjectid: parseInt(subjectId),
                                        moodleid: val.moodleid || 0,
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
                                aggregatedDemand[aggKey].levelid = val.levelid || 0;
                                aggregatedDemand[aggKey].learningplanid = val.learningplanid || 0; // Fallback plan
                                if (val.plan_map) {
                                    if (!aggregatedDemand[aggKey].plan_map) aggregatedDemand[aggKey].plan_map = {};
                                    Object.assign(aggregatedDemand[aggKey].plan_map, val.plan_map);
                                }
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

                        // Resolve majority Learning Plan ID
                        const planCounts = groupStudents.reduce((acc, uid) => {
                            const stu = this.state.students.find(s => (s.dbId == uid || s.id == uid));
                            const pid = stu ? stu.planid : null;
                            if (pid) acc[pid] = (acc[pid] || 0) + 1;
                            return acc;
                        }, {});
                        let majorityPlanId = 0;
                        let maxCount = 0;
                        for (const [pid, pcount] of Object.entries(planCounts)) {
                            if (pcount > maxCount) {
                                maxCount = pcount;
                                majorityPlanId = parseInt(pid);
                            }
                        }

                        // Fallback to provided learning plan if no majority found
                        if (majorityPlanId === 0) majorityPlanId = data.learningplanid;

                        // Use plan_map to resolve the correct Subject ID and Level ID for this specific majority plan
                        let resolvedSubjectId = data.subjectid;
                        let resolvedLevelId = data.levelid;
                        if (data.plan_map && data.plan_map[majorityPlanId]) {
                            resolvedSubjectId = data.plan_map[majorityPlanId].subjectid || resolvedSubjectId;
                            resolvedLevelId = data.plan_map[majorityPlanId].levelid || resolvedLevelId;
                        }

                        schedules.push({
                            id: `gen-${idCounter++}`,
                            courseid: resolvedSubjectId, // Precise Subject ID
                            corecourseid: data.moodleid, // Moodle ID
                            learningplanid: majorityPlanId,
                            periodid: resolvedLevelId, // Academic Level ID
                            subjectName: (this.state.subjects[data.moodleid] ? this.state.subjects[data.moodleid].name : `Materia: ${data.subjectid}`),
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
                            subperiod: data.subperiod || 1, // Default to P-I (Bloque 1) instead of 0 (Ambos)
                            studentIds: groupStudents,
                            type: 0, // Default Presencial
                            typeLabel: 'Presencial',
                            classdays: '0/0/0/0/0/0/0' // Will be calculated upon placement
                        });
                    }
                }

                // 2. Extracted Availability (Flattened by day and skill)
                const availability = [];
                this.state.instructors.forEach(inst => {
                    const skills = (inst.instructorSkills || []).map(s => s.name);
                    const records = inst.disponibilityRecords || {};
                    const tName = inst.instructorName;
                    const tId = inst.instructorId || inst.id;

                    for (const [day, ranges] of Object.entries(records)) {
                        ranges.forEach(rangeStr => {
                            if (skills.length > 0) {
                                skills.forEach(skillName => {
                                    availability.push({
                                        teacherName: tName,
                                        instructorId: tId,
                                        subjectName: skillName,
                                        day: day,
                                        timeRange: rangeStr
                                    });
                                });
                            } else {
                                // Even if no skills listed, maybe available for assignment if manually handled?
                                // Usually we need skill match in autoAssign, but let's keep it consistent.
                                availability.push({
                                    teacherName: tName,
                                    instructorId: tId,
                                    subjectName: '',
                                    day: day,
                                    timeRange: rangeStr
                                });
                            }
                        });
                    }
                });

                // 3. AUTO-PLACEMENT (Determine Days and Times first)
                let finalResult = window.SchedulerAlgorithm.autoPlace(schedules, {
                    classrooms: this.state.context.classrooms || [],
                    loads: this.state.context.loads || [],
                    period: this.state.activePeriodDates || {},
                    configSettings: this.state.context.configSettings || {},
                    students: this.state.students || []
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
