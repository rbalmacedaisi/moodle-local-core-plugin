/**
 * Scheduler Algorithm Utilities
 * 
 * Ported from React codebase (teacherAssignment.js).
 * Handles automatic teacher assignment and suggestion generation.
 * 
 * Structure: Global Object window.SchedulerAlgorithm
 */

(function () {
    if (window.SchedulerAlgorithm) return;

    // --- Helpers ---

    const toMins = (t) => {
        if (!t) return 0;
        const [h, m] = t.split(':').map(Number);
        return (h || 0) * 60 + (m || 0);
    };

    const formatTime = (totalMins) => {
        const h = Math.floor(totalMins / 60);
        const m = totalMins % 60;
        return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
    };

    const parseTimeRange = (range) => {
        // Expected formats: "7:30pm-9:30pm", "07:30-09:30", "7:30 am a 9:30 am"
        // Normalize to [startMins, endMins]
        if (!range) return null;

        const parts = range.toLowerCase().split(/[-–—]| a /).map(p => p.trim());
        if (parts.length !== 2) return null;

        const parsePart = (p) => {
            const isPM = p.includes('pm') || p.includes('tarde') || p.includes('noche');
            const isAM = p.includes('am') || p.includes('mañana');
            let timeStr = p.replace(/[a-z\s]/g, '');
            if (!timeStr.includes(':')) timeStr += ':00';

            let [h, m] = timeStr.split(':').map(Number);
            if (isPM && h < 12) h += 12;
            if (isAM && h === 12) h = 0;
            return h * 60 + (m || 0);
        };

        return [parsePart(parts[0]), parsePart(parts[1])];
    };

    const isOverlap = (s1, s2) => {
        if (s1.day !== s2.day) return false;
        const start1 = toMins(s1.start);
        const end1 = toMins(s1.end);
        const start2 = toMins(s2.start);
        const end2 = toMins(s2.end);

        // Subperiod overlap logic
        const subOverlap = (s1.subperiod === 0) || (s2.subperiod === 0) || (s1.subperiod === s2.subperiod);
        if (!subOverlap) return false;

        return Math.max(start1, start2) < Math.min(end1, end2);
    };

    const getEffectiveWeeks = (period) => {
        if (!period || !period.start || !period.end) return 16;
        const start = new Date(period.start);
        const end = new Date(period.end);
        const diffDays = (end - start) / (1000 * 60 * 60 * 24);
        const weeks = Math.floor(diffDays / 7);
        // Reserve 1 week for reválidas/final exams as per logic
        return Math.max(1, weeks - 1);
    };

    // --- Main Functions ---

    const autoAssign = (schedules, availability) => {
        // 1. Pre-process availability to include parsed time ranges
        const teacherPool = availability.map(a => ({
            ...a,
            timeRangeMins: parseTimeRange(a.timeRange)
        })).filter(a => a.timeRangeMins);

        // 2. Clone schedules to avoid mutation
        // JSON parse/stringify is a safe deep clone for data objects
        const nextSchedules = JSON.parse(JSON.stringify(schedules));

        // 3. Keep track of teacher busy slots
        // teacherName -> [ { day, start, end } ]
        const teacherBusy = {};

        // Initial busy slots from already assigned teachers in schedules (if any)
        nextSchedules.forEach(s => {
            if (s.teacherName && s.day !== 'N/A') {
                if (!teacherBusy[s.teacherName]) teacherBusy[s.teacherName] = [];
                teacherBusy[s.teacherName].push({
                    day: s.day,
                    start: s.start,
                    end: s.end,
                    subperiod: s.subperiod || 0
                });
            }
        });

        // 4. Iterate through unassigned schedules
        nextSchedules.forEach(s => {
            if (s.teacherName || s.day === 'N/A') return;

            const sStart = toMins(s.start);
            const sEnd = toMins(s.end);

            // Find eligible teachers
            const eligible = teacherPool.filter(a => {
                // Match subject (case insensitive, trimmed)
                const subjMatch = a.subjectName.toLowerCase().trim() === s.subjectName.toLowerCase().trim();
                if (!subjMatch) return false;

                // Match day
                const dayMatch = a.day.toLowerCase().trim() === s.day.toLowerCase().trim();
                if (!dayMatch) return false;

                // Match time: Teacher range must contain schedule range
                const [tStart, tEnd] = a.timeRangeMins;
                return tStart <= sStart && tEnd >= sEnd;
            });

            // Try to assign one that isn't busy
            for (const auth of eligible) {
                const isBusy = (teacherBusy[auth.teacherName] || []).some(busy => {
                    const bStart = toMins(busy.start);
                    const bEnd = toMins(busy.end);
                    const subOverlap = (busy.subperiod === s.subperiod) || (busy.subperiod === 0) || (s.subperiod === 0);
                    return busy.day === s.day && subOverlap && Math.max(sStart, bStart) < Math.min(sEnd, bEnd);
                });

                if (!isBusy) {
                    // Assign!
                    s.teacherName = auth.teacherName;
                    s.instructorId = auth.instructorId; // Keep ID if available

                    if (!teacherBusy[auth.teacherName]) teacherBusy[auth.teacherName] = [];
                    teacherBusy[auth.teacherName].push({
                        day: s.day,
                        start: s.start,
                        end: s.end,
                        subperiod: s.subperiod || 0
                    });
                    break; // Move to next schedule
                }
            }
        });

        return nextSchedules;
    };

    /**
     * Automatic Placement Algorithm
     * Finds Day, Time, and Room for unassigned schedules using date-level granularity.
     */
    const autoPlace = (schedules, context) => {
        const nextSchedules = JSON.parse(JSON.stringify(schedules));
        const intervalMins = context.configSettings?.intervalMinutes || 10;

        // 1. Prepare Calendars (Dates per Day)
        const holidaySet = new Set((context.configSettings?.holidays || []).map(h => h.date));
        const dayMap = ['Domingo', 'Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado'];
        const allDatesByDay = { 'Lunes': [], 'Martes': [], 'Miercoles': [], 'Jueves': [], 'Viernes': [], 'Sabado': [], 'Domingo': [] };

        if (context.period?.start && context.period?.end) {
            let tempD = new Date(context.period.start);
            const tempEnd = new Date(context.period.end);
            while (tempD <= tempEnd) {
                const dStr = tempD.toISOString().split('T')[0];
                const dIdx = tempD.getUTCDay(); // UTC to avoid timezone issues with pure dates
                if (!holidaySet.has(dStr)) {
                    allDatesByDay[dayMap[dIdx]].push(dStr);
                }
                tempD.setUTCDate(tempD.getUTCDate() + 1);
            }
        }

        const effectiveWeeks = getEffectiveWeeks(context.period);
        const shiftWindows = context.configSettings?.shiftWindows || {
            'Diurna': { start: '07:00', end: '18:00' },
            'Nocturna': { start: '18:00', end: '22:00' },
            'Sabatina': { start: '07:00', end: '17:00' }
        };

        const lunchStart = toMins(context.configSettings?.lunchStart || '12:00');
        const lunchEnd = toMins(context.configSettings?.lunchEnd || '13:00');

        // Usage maps: key -> DateStr -> [ {start, end, subperiod} ]
        const roomUsage = new Map();
        const teacherUsage = new Map();
        const groupUsage = new Map();

        const markBusyGranular = (usageMap, key, dates, s) => {
            if (!key) return;
            if (!usageMap.has(key)) usageMap.set(key, new Map());
            const entityMap = usageMap.get(key);
            dates.forEach(date => {
                if (!entityMap.has(date)) entityMap.set(date, []);
                entityMap.get(date).push({
                    start: toMins(s.start),
                    end: toMins(s.end),
                    subperiod: s.subperiod || 0
                });
            });
        };

        const checkBusyGranular = (usageMap, key, dates, subperiod, start, end) => {
            if (!key || !usageMap.has(key)) return false;
            const entityMap = usageMap.get(key);
            return dates.some(date => {
                const slots = entityMap.get(date) || [];
                return slots.some(busy => {
                    const subOverlap = (busy.subperiod === 0) || (subperiod === 0) || (busy.subperiod === subperiod);
                    return subOverlap && Math.max(start, busy.start) < Math.min(end, busy.end);
                });
            });
        };

        // 2. Initial occupancy from already assigned schedules (if granular data is present)
        nextSchedules.forEach(s => {
            if (s.day !== 'N/A' && s.assignedDates) {
                markBusyGranular(roomUsage, s.room, s.assignedDates, s);
                markBusyGranular(teacherUsage, s.teacherName, s);
                const groupKey = `${s.career}|${s.levelDisplay}|${s.shift}`;
                markBusyGranular(groupUsage, groupKey, s.assignedDates, s);
            }
        });

        // 3. Sort unassigned tasks (Larger student counts first)
        const unassigned = nextSchedules.filter(s => s.day === 'N/A');
        unassigned.sort((a, b) => (b.studentCount || 0) - (a.studentCount || 0));

        // 4. Process unassigned
        unassigned.forEach(s => {
            if (s.studentCount < 12 && !s.isQuorumException) {
                s.warning = "Quórum Insuficiente (<12)";
                return;
            }

            // Duration and Intensity logic
            let durationMins = 120;
            let maxSessions = null;
            const loadData = (context.loads || []).find(l => l.subjectName === s.subjectName);
            if (loadData) {
                if (loadData.intensity) {
                    durationMins = Math.round(loadData.intensity * 60);
                    // If intensive, calculate total sessions needed
                    if (loadData.totalHours) maxSessions = Math.ceil(loadData.totalHours / loadData.intensity);
                } else if (loadData.totalHours) {
                    durationMins = Math.round((loadData.totalHours / effectiveWeeks) * 60);
                }
            }
            s.durationMins = durationMins;

            const win = shiftWindows[s.shift] || shiftWindows['Diurna'];
            const winStart = toMins(win.start);
            const winEnd = toMins(win.end);
            const winDays = (s.shift === 'Sabatina') ? ['Sabado'] : ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes'];

            const groupKey = `${s.career}|${s.levelDisplay}|${s.shift}`;
            const validRooms = (context.classrooms || [])
                .filter(r => r.capacity >= s.studentCount)
                .sort((a, b) => a.capacity - b.capacity);

            let placed = false;
            for (const room of validRooms) {
                if (placed) break;
                for (const day of winDays) {
                    if (placed) break;

                    const availableDates = allDatesByDay[day] || [];
                    if (availableDates.length === 0) continue;

                    for (let t = winStart; t <= winEnd - durationMins; t += intervalMins) {
                        const tEnd = t + durationMins;

                        // Lunch check
                        if (Math.max(t, lunchStart) < Math.min(tEnd, lunchEnd)) continue;

                        // Granular overlap check
                        // If it's intensive, we only check for the first maxSessions available dates
                        // If it's normal, we check if it can be placed in ALL available dates for that weekday
                        let targetDates = availableDates;
                        if (maxSessions) {
                            // Find which dates are actually free for this block
                            const freeDates = availableDates.filter(d => {
                                const roomOk = !checkBusyGranular(roomUsage, room.name, [d], s.subperiod, t, tEnd);
                                const teacherOk = !s.teacherName || !checkBusyGranular(teacherUsage, s.teacherName, [d], s.subperiod, t, tEnd);
                                const groupOk = !checkBusyGranular(groupUsage, groupKey, [d], s.subperiod, t, tEnd);
                                return roomOk && teacherOk && groupOk;
                            });

                            if (freeDates.length >= maxSessions) {
                                targetDates = freeDates.slice(0, maxSessions);
                            } else {
                                continue; // Not enough consecutive sessions for intensive course
                            }
                        } else {
                            // Standard weekly repeat: check ALL dates
                            const roomOk = !checkBusyGranular(roomUsage, room.name, availableDates, s.subperiod, t, tEnd);
                            const teacherOk = !s.teacherName || !checkBusyGranular(teacherUsage, s.teacherName, availableDates, s.subperiod, t, tEnd);
                            const groupOk = !checkBusyGranular(groupUsage, groupKey, availableDates, s.subperiod, t, tEnd);
                            if (!roomOk || !teacherOk || !groupOk) continue;
                        }

                        // PLACE!
                        s.day = day;
                        s.start = formatTime(t);
                        s.end = formatTime(tEnd);
                        s.room = room.name;
                        s.assignedDates = targetDates;

                        // Mark as busy for next items
                        markBusyGranular(roomUsage, room.name, targetDates, s);
                        if (s.teacherName) markBusyGranular(teacherUsage, s.teacherName, targetDates, s);
                        markBusyGranular(groupUsage, groupKey, targetDates, s);

                        placed = true;
                        break;
                    }
                }
            }

            if (!placed) {
                s.warning = "No se encontró espacio disponible (Verifique capacidad o choques)";
            }
        });

        return nextSchedules;
    };

    const getSuggestions = (schedules, availability) => {
        const suggestions = [];
        const teacherPool = availability.map(a => ({
            ...a,
            timeRangeMins: parseTimeRange(a.timeRange)
        })).filter(a => a.timeRangeMins);

        const teacherBusy = {};
        schedules.forEach(s => {
            if (s.teacherName && s.day !== 'N/A') {
                if (!teacherBusy[s.teacherName]) teacherBusy[s.teacherName] = [];
                teacherBusy[s.teacherName].push({
                    day: s.day,
                    start: s.start,
                    end: s.end,
                    subperiod: s.subperiod || 0
                });
            }
        });

        schedules.forEach(s => {
            // Suggest for unassigned OR assigned without teacher OR assigned with teacher conflict
            // Find all potential slots for this subject
            const matchTeachers = teacherPool.filter(a =>
                a.subjectName.toLowerCase().trim() === s.subjectName.toLowerCase().trim()
            );

            matchTeachers.forEach(auth => {
                const [tStartMins, tEndMins] = auth.timeRangeMins;
                const durationMins = s.durationMins || 120;
                const tDay = auth.day;

                // Proposal: Start of teacher's avail block
                const proposedStart = formatTime(tStartMins);
                //const proposedEnd = formatTime(tStartMins + durationMins);

                // Ideally iterate through blocks of durationMins within [tStartMins, tEndMins]
                // For now, simpler port: check if (tStart + duration) fits and is free

                // Check if this specific proposal overlaps with teacher's busy slots
                const isBusy = (teacherBusy[auth.teacherName] || []).some(busy => {
                    const bStart = toMins(busy.start);
                    const bEnd = toMins(busy.end);
                    const subOverlap = (busy.subperiod === s.subperiod) || (busy.subperiod === 0) || (s.subperiod === 0);
                    return busy.day === tDay && subOverlap && Math.max(tStartMins, bStart) < Math.min(tStartMins + durationMins, bEnd);
                });

                if (!isBusy) {
                    // If it's already exactly where it is and has the teacher, don't suggest
                    if (s.day === tDay && toMins(s.start) === tStartMins && s.teacherName === auth.teacherName) {
                        return;
                    }

                    const suggestionId = `sug-${s.id}-${auth.teacherName}-${tDay}-${proposedStart}`;
                    suggestions.push({
                        id: suggestionId,
                        scheduleId: s.id,
                        subjectName: s.subjectName,
                        career: s.career,
                        shift: s.shift,
                        originalDay: s.day,
                        originalTime: s.day === 'N/A' ? 'Sin hora' : `${s.start}-${s.end}`,
                        proposedDay: tDay,
                        proposedStart: proposedStart,
                        proposedEnd: formatTime(tStartMins + durationMins),
                        teacherName: auth.teacherName,
                        type: s.day === 'N/A' ? 'assignment' : 'movement'
                    });
                }
            });
        });

        // Deduplicate suggestions by ID
        const uniqueSuggestions = [];
        const seenSugs = new Set();
        suggestions.forEach(sug => {
            if (!seenSugs.has(sug.id)) {
                uniqueSuggestions.push(sug);
                seenSugs.add(sug.id);
            }
        });

        return uniqueSuggestions.slice(0, 20);
    };

    /**
     * granular Conflict Detection
     * @param {Object} schedule - The schedule being validated/moved
     * @param {Array} allSchedules - Current state of all schedules
     * @param {Object} context - { classrooms, holidays }
     */
    const detectConflicts = (schedule, allSchedules, context) => {
        const issues = [];
        if (!schedule || schedule.day === 'N/A') return issues;

        const sStart = toMins(schedule.start);
        const sEnd = toMins(schedule.end);
        const sDates = new Set(schedule.assignedDates || []);

        // Helper overlap check
        const checkOverlap = (other) => {
            if (other.id === schedule.id) return false; // Don't check against self

            // Granular Date Check: Do they share at least one date?
            const oDates = other.assignedDates || [];
            const hasDateOverlap = oDates.some(d => sDates.has(d));

            // Fallback to day-only check if assignedDates is missing in either (for compatibility)
            if (!schedule.assignedDates || !other.assignedDates) {
                if (other.day !== schedule.day) return false;
            } else if (!hasDateOverlap) {
                return false;
            }

            // Subperiod logic: 
            // 0 = Full Semester (overlaps everything)
            // 1 = P-I (overlaps 0 and 1)
            // 2 = P-II (overlaps 0 and 2)
            const subOverlap = (other.subperiod === 0) || (schedule.subperiod === 0) || (other.subperiod === schedule.subperiod);
            if (!subOverlap) return false;

            const oStart = toMins(other.start);
            const oEnd = toMins(other.end);
            return Math.max(sStart, oStart) < Math.min(sEnd, oEnd);
        };

        // 1. Teacher Conflict
        if (schedule.teacherName) {
            const teacherConflict = allSchedules.find(s =>
                s.teacherName === schedule.teacherName && checkOverlap(s)
            );
            if (teacherConflict) {
                issues.push({
                    type: 'teacher',
                    message: `Docente ${schedule.teacherName} ocupado en ${teacherConflict.day} ${teacherConflict.start}`,
                    relatedId: teacherConflict.id
                });
            }
        }

        // 2. Room Conflict (and Capacity)
        if (schedule.room && schedule.room !== 'Sin aula') {
            const roomConflict = allSchedules.find(s =>
                s.room === schedule.room && checkOverlap(s)
            );
            if (roomConflict) {
                issues.push({
                    type: 'room',
                    message: `Aula ${schedule.room} ocupada por ${roomConflict.subjectName}`,
                    relatedId: roomConflict.id
                });
            }

            // Capacity Check
            if (context && context.classrooms) {
                const roomData = context.classrooms.find(r => r.name === schedule.room);
                if (roomData && schedule.studentCount > roomData.capacity) {
                    issues.push({
                        type: 'capacity',
                        message: `Sobrecapacidad: ${schedule.studentCount} alumnos vs ${roomData.capacity} cupos`
                    });
                }
            }
        }

        // 3. Group/Student Conflict
        if (schedule.career && schedule.levelDisplay && schedule.shift) {
            const groupConflict = allSchedules.find(s =>
                s.career === schedule.career &&
                s.levelDisplay === schedule.levelDisplay &&
                s.shift === schedule.shift &&
                checkOverlap(s)
            );

            if (groupConflict) {
                issues.push({
                    type: 'group',
                    message: `Choque de horario para el grupo ${schedule.career} - ${schedule.levelDisplay}`,
                    relatedId: groupConflict.id
                });
            }
        }

        return issues;
    };

    // Export to window
    window.SchedulerAlgorithm = {
        autoAssign,
        autoPlace,
        getSuggestions,
        detectConflicts,
        toMins,
        formatTime,
        parseTimeRange,
        getEffectiveWeeks
    };

})();
