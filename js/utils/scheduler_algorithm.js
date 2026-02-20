/**
 * Scheduler Algorithm Utilities
 * 
 * Ported from React codebase (teacherAssignment.js).
 * Handles automatic placement, teacher assignment and conflict detection.
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

    const getEffectiveWeeks = (period) => {
        if (!period || !period.start || !period.end) return 16;
        const start = new Date(period.start);
        const end = new Date(period.end);
        const diffDays = (end - start) / (1000 * 60 * 60 * 24);
        const weeks = Math.floor(diffDays / 7);
        return Math.max(1, weeks - 1);
    };

    // --- Core Algorithm Functions ---

    const autoAssign = (schedules, availability) => {
        const teacherPool = availability.map(a => ({
            ...a,
            timeRangeMins: parseTimeRange(a.timeRange)
        })).filter(a => a.timeRangeMins);

        const nextSchedules = JSON.parse(JSON.stringify(schedules));
        const teacherBusy = {};

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

        nextSchedules.forEach(s => {
            if (s.teacherName || s.day === 'N/A') return;
            const sStart = toMins(s.start);
            const sEnd = toMins(s.end);
            const eligible = teacherPool.filter(a =>
                a.subjectName.toLowerCase().trim() === s.subjectName.toLowerCase().trim() &&
                a.day.toLowerCase().trim() === s.day.toLowerCase().trim() &&
                a.timeRangeMins[0] <= sStart && a.timeRangeMins[1] >= sEnd
            );

            for (const auth of eligible) {
                const isBusy = (teacherBusy[auth.teacherName] || []).some(busy => {
                    const subOverlap = (busy.subperiod === s.subperiod) || (busy.subperiod === 0) || (s.subperiod === 0);
                    return busy.day === s.day && subOverlap && Math.max(sStart, toMins(busy.start)) < Math.min(sEnd, toMins(busy.end));
                });
                if (!isBusy) {
                    s.teacherName = auth.teacherName;
                    s.instructorId = auth.instructorId;
                    if (!teacherBusy[auth.teacherName]) teacherBusy[auth.teacherName] = [];
                    teacherBusy[auth.teacherName].push({ day: s.day, start: s.start, end: s.end, subperiod: s.subperiod || 0 });
                    break;
                }
            }
        });
        return nextSchedules;
    };

    const autoPlace = (schedules, context) => {
        const nextSchedules = JSON.parse(JSON.stringify(schedules));
        const intervalMins = context.configSettings?.intervalMinutes || 10;
        const holidaySet = new Set((context.configSettings?.holidays || []).map(h => h.formatted_date || h.date));
        const dayMap = ['Domingo', 'Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado'];
        const allDatesByDay = { 'Lunes': [], 'Martes': [], 'Miercoles': [], 'Jueves': [], 'Viernes': [], 'Sabado': [], 'Domingo': [] };

        if (context.period?.start && context.period?.end) {
            let tempD = new Date(context.period.start);
            const tempEnd = new Date(context.period.end);
            while (tempD <= tempEnd) {
                const dStr = tempD.toISOString().split('T')[0];
                const dIdx = tempD.getUTCDay();
                if (!holidaySet.has(dStr)) allDatesByDay[dayMap[dIdx]].push(dStr);
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

        const roomUsage = new Map();
        const teacherUsage = new Map();
        const studentUsage = new Map();

        const markBusyGranular = (usageMap, key, dates, s) => {
            if (!key) return;
            if (!usageMap.has(key)) usageMap.set(key, new Map());
            const entityMap = usageMap.get(key);
            dates.forEach(date => {
                if (!entityMap.has(date)) entityMap.set(date, []);
                entityMap.get(date).push({
                    start: toMins(s.start),
                    end: toMins(s.end),
                    subperiod: s.subperiod || 0,
                    subjectName: s.subjectName
                });
            });
        };

        const checkBusyGranular = (usageMap, key, dates, subperiod, start, end) => {
            if (!key || !usageMap.has(key)) return null;
            const entityMap = usageMap.get(key);
            let conflictSubject = null;
            dates.some(date => {
                const slots = entityMap.get(date) || [];
                const conflict = slots.find(busy => {
                    const subOverlap = (busy.subperiod === 0) || (subperiod === 0) || (busy.subperiod === subperiod);
                    return subOverlap && Math.max(start, busy.start) < Math.min(end, busy.end);
                });
                if (conflict) {
                    conflictSubject = conflict.subjectName;
                    return true;
                }
                return false;
            });
            return conflictSubject;
        };

        const checkStudentsBusy = (studentIds, dates, subperiod, start, end) => {
            if (!studentIds || studentIds.length === 0) return null;
            let conflictMsg = null;
            studentIds.some(sid => {
                const subject = checkBusyGranular(studentUsage, sid, dates, subperiod, start, end);
                if (subject) {
                    conflictMsg = subject;
                    return true;
                }
                return false;
            });
            return conflictMsg;
        };

        nextSchedules.forEach(s => {
            if (s.day !== 'N/A' && s.assignedDates) {
                markBusyGranular(roomUsage, s.room, s.assignedDates, s);
                if (s.teacherName) markBusyGranular(teacherUsage, s.teacherName, s.assignedDates, s);
                if (s.studentIds) s.studentIds.forEach(sid => markBusyGranular(studentUsage, sid, s.assignedDates, s));
            }
        });

        const unassigned = nextSchedules.filter(s => s.day === 'N/A');
        unassigned.sort((a, b) => (b.studentCount || 0) - (a.studentCount || 0));

        unassigned.forEach(s => {
            if (s.studentCount < 12 && !s.isQuorumException) {
                s.warning = "Quórum Insuficiente (<12)";
                return;
            }

            let durationMins = 120;
            let maxSessions = null;
            const loadData = (context.loads || []).find(l => l.subjectName === s.subjectName);
            if (loadData) {
                if (loadData.intensity) {
                    durationMins = Math.round(loadData.intensity * 60);
                    if (loadData.totalHours) maxSessions = Math.ceil(loadData.totalHours / loadData.intensity);
                } else if (loadData.totalHours) {
                    durationMins = Math.round((loadData.totalHours / effectiveWeeks) * 60);
                }
            }
            s.durationMins = durationMins;

            const win = shiftWindows[s.shift] || shiftWindows['Diurna'];
            const winStart = toMins(win.start);
            const winEnd = toMins(win.end);
            let winDays = ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes'];
            if (s.shift === 'Sabatina') winDays = ['Sabado'];
            else if (win && win.days) winDays = win.days;

            const validRooms = (context.classrooms || [])
                .filter(r => r.active != 0 && r.capacity >= s.studentCount)
                .sort((a, b) => {
                    const diff = a.capacity - b.capacity;
                    if (Math.abs(diff) <= 5) return Math.random() - 0.5;
                    return diff;
                });

            let placed = false;
            let failureReasons = new Set();
            let testedSlots = 0;

            for (const day of winDays) {
                if (placed) break;
                const availableDates = allDatesByDay[day] || [];
                if (availableDates.length === 0) continue;

                for (let t = winStart; t <= winEnd - durationMins; t += intervalMins) {
                    if (placed) break;
                    const tEnd = t + durationMins;
                    if (Math.max(t, lunchStart) < Math.min(tEnd, lunchEnd)) {
                        failureReasons.add("Horario coincide con almuerzo");
                        continue;
                    }

                    testedSlots++;
                    for (const room of validRooms) {
                        if (placed) break;
                        let targetDates = availableDates;
                        const subRange = (s.subperiod && context.period?.subperiods) ? context.period.subperiods[s.subperiod] : null;
                        if (subRange) {
                            const subStart = subRange.start;
                            const subEnd = subRange.end;
                            targetDates = availableDates.filter(d => d >= subStart && d <= subEnd);
                        }

                        if (targetDates.length === 0) {
                            failureReasons.add("Sin fechas en subperiodo");
                            continue;
                        }

                        if (maxSessions) {
                            const freeDates = targetDates.filter(d => {
                                const rOkName = checkBusyGranular(roomUsage, room.name, [d], s.subperiod, t, tEnd);
                                const tOkName = s.teacherName ? checkBusyGranular(teacherUsage, s.teacherName, [d], s.subperiod, t, tEnd) : null;
                                const sOkName = checkStudentsBusy(s.studentIds, [d], s.subperiod, t, tEnd);

                                if (rOkName) failureReasons.add(`Aula ocupada: ${rOkName}`);
                                if (tOkName) failureReasons.add(`Docente ocupado: ${tOkName}`);
                                if (sOkName) failureReasons.add(`Choque Alumnos: ${sOkName}`);

                                return !rOkName && !tOkName && !sOkName;
                            });
                            if (freeDates.length >= maxSessions) {
                                const selectedDates = freeDates.slice(0, maxSessions);
                                s.day = day; s.start = formatTime(t); s.end = formatTime(tEnd); s.room = room.name; s.assignedDates = selectedDates;
                                markBusyGranular(roomUsage, room.name, selectedDates, s);
                                if (s.teacherName) markBusyGranular(teacherUsage, s.teacherName, selectedDates, s);
                                if (s.studentIds) s.studentIds.forEach(sid => markBusyGranular(studentUsage, sid, selectedDates, s));
                                placed = true;
                            } else if (freeDates.length > 0) {
                                failureReasons.add("Días insuficientes para curso intensivo");
                            }
                        } else {
                            const rConflict = checkBusyGranular(roomUsage, room.name, targetDates, s.subperiod, t, tEnd);
                            if (rConflict) { failureReasons.add(`Aula ${room.name} ocupada (${rConflict})`); continue; }

                            const tConflict = s.teacherName ? checkBusyGranular(teacherUsage, s.teacherName, targetDates, s.subperiod, t, tEnd) : null;
                            if (tConflict) { failureReasons.add(`Docente ${s.teacherName} ocupado (${tConflict})`); continue; }

                            const stConflict = checkStudentsBusy(s.studentIds, targetDates, s.subperiod, t, tEnd);
                            if (stConflict) { failureReasons.add(`Choque Alumnos: ${stConflict}`); continue; }

                            s.day = day; s.start = formatTime(t); s.end = formatTime(tEnd); s.room = room.name; s.assignedDates = targetDates;
                            markBusyGranular(roomUsage, room.name, targetDates, s);
                            if (s.teacherName) markBusyGranular(teacherUsage, s.teacherName, targetDates, s);
                            if (s.studentIds) s.studentIds.forEach(sid => markBusyGranular(studentUsage, sid, targetDates, s));
                            placed = true;
                        }
                    }
                }
            }

            if (!placed) {
                if (validRooms.length === 0) s.warning = `Sin aulas de capacidad ${s.studentCount}`;
                else if (testedSlots === 0) s.warning = "Fuera de ventana horaria";
                else {
                    const resArr = Array.from(failureReasons);
                    const stuConflict = resArr.find(r => r.startsWith("Choque Alumnos:"));
                    if (stuConflict) {
                        s.warning = stuConflict;
                    } else if (resArr.some(r => r.includes("Docente"))) {
                        s.warning = "Conflicto: Docente ocupado";
                    } else {
                        s.warning = "Sin espacio disponible (Todo ocupado)";
                    }
                }
            }
        });
        return nextSchedules;
    };

    const getSuggestions = (schedules, availability) => {
        const teacherPool = availability.map(a => ({
            ...a,
            timeRangeMins: parseTimeRange(a.timeRange)
        })).filter(a => a.timeRangeMins);

        const nextSchedules = JSON.parse(JSON.stringify(schedules));
        const teacherBusy = {};
        nextSchedules.forEach(s => {
            if (s.teacherName && s.day !== 'N/A') {
                if (!teacherBusy[s.teacherName]) teacherBusy[s.teacherName] = [];
                teacherBusy[s.teacherName].push({ day: s.day, start: s.start, end: s.end, subperiod: s.subperiod || 0 });
            }
        });

        const suggestions = [];
        nextSchedules.forEach(s => {
            const matchTeachers = teacherPool.filter(a => a.subjectName.toLowerCase().trim() === s.subjectName.toLowerCase().trim());
            matchTeachers.forEach(auth => {
                const [tStart, tEnd] = auth.timeRangeMins;
                const duration = s.durationMins || 120;
                const isBusy = (teacherBusy[auth.teacherName] || []).some(busy => {
                    const subOverlap = (busy.subperiod === s.subperiod) || (busy.subperiod === 0) || (s.subperiod === 0);
                    return busy.day === auth.day && subOverlap && Math.max(tStart, toMins(busy.start)) < Math.min(tStart + duration, toMins(busy.end));
                });
                if (!isBusy) {
                    if (s.day === auth.day && toMins(s.start) === tStart && s.teacherName === auth.teacherName) return;
                    suggestions.push({
                        id: `sug-${s.id}-${auth.teacherName}-${auth.day}-${tStart}`,
                        scheduleId: s.id,
                        subjectName: s.subjectName,
                        career: s.career,
                        proposedDay: auth.day,
                        proposedStart: formatTime(tStart),
                        proposedEnd: formatTime(tStart + duration),
                        teacherName: auth.teacherName,
                        type: s.day === 'N/A' ? 'assignment' : 'movement'
                    });
                }
            });
        });
        return suggestions.slice(0, 20);
    };

    const detectConflicts = (schedule, allSchedules, context) => {
        const issues = [];
        if (!schedule || schedule.day === 'N/A') return issues;
        const sStart = toMins(schedule.start);
        const sEnd = toMins(schedule.end);
        const sDates = new Set(schedule.assignedDates || []);

        const checkOverlap = (other) => {
            if (other.id === schedule.id) return false;
            const oDates = other.assignedDates || [];
            if (schedule.assignedDates && other.assignedDates) {
                if (!oDates.some(d => sDates.has(d))) return false;
            } else if (other.day !== schedule.day) return false;

            const subOverlap = (other.subperiod === 0) || (schedule.subperiod === 0) || (other.subperiod === schedule.subperiod);
            if (!subOverlap) return false;
            return Math.max(sStart, toMins(other.start)) < Math.min(sEnd, toMins(other.end));
        };

        if (schedule.teacherName) {
            const tC = allSchedules.find(s => s.teacherName === schedule.teacherName && checkOverlap(s));
            if (tC) issues.push({ type: 'teacher', message: `Docente ocupado en ${tC.day} ${tC.start}`, relatedId: tC.id });
        }
        if (schedule.room && schedule.room !== 'Sin aula') {
            const rC = allSchedules.find(s => s.room === schedule.room && checkOverlap(s));
            if (rC) issues.push({ type: 'room', message: `Aula ocupada por ${rC.subjectName}`, relatedId: rC.id });
            if (context?.classrooms) {
                const rD = context.classrooms.find(r => r.name === schedule.room);
                if (rD && schedule.studentCount > rD.capacity) issues.push({ type: 'capacity', message: `Sobrecapacidad (${schedule.studentCount}/${rD.capacity})` });
            }
        }
        if (schedule.studentIds?.length > 0) {
            const sC = allSchedules.find(s => {
                const subOverlap = (s.subperiod === 0) || (schedule.subperiod === 0) || (s.subperiod === schedule.subperiod);
                if (!subOverlap || s.id === schedule.id || s.day !== schedule.day) return false;
                if (Math.max(sStart, toMins(s.start)) >= Math.min(sEnd, toMins(s.end))) return false;
                return schedule.studentIds.some(sid => (s.studentIds || []).includes(sid));
            });
            if (sC) issues.push({ type: 'group', message: `Choque de alumnos con ${sC.subjectName}`, relatedId: sC.id });
        }
        return issues;
    };

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
