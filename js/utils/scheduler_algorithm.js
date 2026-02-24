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
        const parts = range.toLowerCase().split(/[-–—,]| a /).map(p => p.trim());
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

        // Normalize loads to use consistent camelCase property names
        // DB returns snake_case (subjectname, total_hours), client sends camelCase (subjectName, totalHours)
        const normalizedLoads = (context.loads || []).map(l => ({
            subjectName: l.subjectName || l.subjectname || '',
            totalHours: parseFloat(l.totalHours || l.total_hours || 0),
            intensity: parseFloat(l.intensity || 0)
        }));

        // Unify holiday source: priority to context.holidays (live table)
        const holidays = context.holidays || context.configSettings?.holidays || [];
        const holidaySet = new Set(holidays.map(h => h.formatted_date || h.date));
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
        const studentNameMap = new Map();
        if (context.students) context.students.forEach(s => studentNameMap.set(s.id, s.name));
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

        const checkBusyGranular = (usageMap, key, dates, subperiod, start, end, interval = 0) => {
            if (!key || !usageMap.has(key)) return null;
            const entityMap = usageMap.get(key);
            let conflictSubject = null;
            dates.some(date => {
                const slots = entityMap.get(date) || [];
                const conflict = slots.find(busy => {
                    const subOverlap = (busy.subperiod === 0) || (subperiod === 0) || (busy.subperiod === subperiod);
                    // Strict overlap check: classes can start exactly when the previous ends (no padding)
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

        const checkStudentsBusy = (studentIds, dates, subperiod, start, end, interval = 0) => {
            if (!studentIds || studentIds.length === 0) return null;
            let firstSubject = null;
            let busyNames = [];
            studentIds.forEach(sid => {
                const subject = checkBusyGranular(studentUsage, sid, dates, subperiod, start, end, interval);
                if (subject) {
                    if (!firstSubject) firstSubject = subject;
                    const sName = studentNameMap.get(sid) || `ID:${sid}`;
                    if (busyNames.length < 3) busyNames.push(sName);
                }
            });

            if (firstSubject) {
                let msg = `${firstSubject}`;
                if (busyNames.length > 0) {
                    msg += `: ${busyNames.join(', ')}`;
                    if (busyNames.length < studentIds.length && busyNames.length >= 3) msg += '...';
                }
                return msg;
            }
            return null;
        };

        // Pre-initialize: Compute assignedDates for already placed schedules
        // This is crucial for consistency between manual dragging/loading and auto-placement
        nextSchedules.forEach(s => {
            if (s.day !== 'N/A' && s.start && s.end) {
                const availableDates = allDatesByDay[s.day] || [];
                const subRange = (s.subperiod && context.period?.subperiods) ? context.period.subperiods[s.subperiod] : null;
                let targetDates = availableDates;
                if (subRange) {
                    targetDates = availableDates.filter(d => d >= subRange.start && d <= subRange.end);
                }

                // Handle Intensive Courses (maxSessions)
                let maxSessions = null;
                const loadData = normalizedLoads.find(l => l.subjectName === s.subjectName);
                if (loadData && loadData.intensity && loadData.totalHours) {
                    maxSessions = Math.ceil(loadData.totalHours / loadData.intensity);
                }

                if (maxSessions && targetDates.length > maxSessions) {
                    s.assignedDates = targetDates.slice(0, maxSessions);
                } else {
                    s.assignedDates = targetDates;
                }

                markBusyGranular(roomUsage, s.room, s.assignedDates, s);
                if (s.teacherName) markBusyGranular(teacherUsage, s.teacherName, s.assignedDates, s);
                if (s.studentIds) s.studentIds.forEach(sid => markBusyGranular(studentUsage, sid, s.assignedDates, s));
            }
        });

        // Pre-initialize External: Process classes from overlapping periods
        if (context.overlappingClasses && Array.isArray(context.overlappingClasses)) {
            context.overlappingClasses.forEach(s => {
                if (s.day !== 'N/A' && s.start && s.end) {
                    // Use sessions if available, fallback to assignedDates
                    let dates = s.assignedDates || [];
                    if (s.sessions && Array.isArray(s.sessions)) {
                        dates = [];
                        s.sessions.forEach(sess => {
                            const sessDates = allDatesByDay[sess.day] || [];
                            const subRange = (s.subperiod && context.period?.subperiods) ? context.period.subperiods[s.subperiod] : null;
                            let targetDates = sessDates;
                            if (subRange) {
                                targetDates = sessDates.filter(d => d >= subRange.start && d <= subRange.end);
                            }
                            // Exclude specific dates
                            if (sess.excluded_dates) {
                                targetDates = targetDates.filter(d => !sess.excluded_dates.includes(d));
                            }
                            dates.push(...targetDates);
                        });
                    }

                    markBusyGranular(roomUsage, s.room, dates, s);
                    if (s.teacherName) markBusyGranular(teacherUsage, s.teacherName, dates, s);
                    if (s.studentIds) s.studentIds.forEach(sid => markBusyGranular(studentUsage, sid, dates, s));
                }
            });
        }

        const unassigned = nextSchedules.filter(s => s.day === 'N/A');

        // --- IMPROVED SORTING (Clustering Heuristic) ---
        // Sort by Career + Subperiod + StudentCount
        // This keeps Cohorts together, allowing the algorithm to pack a single group's classes
        // into the same days/rooms before moving to the next group, which is more "efficient"
        // for students and improves Mon-Wed saturation.
        unassigned.sort((a, b) => {
            // First by Career name (lexicographical)
            const careerA = String(a.career || '');
            const careerB = String(b.career || '');
            if (careerA !== careerB) return careerA.localeCompare(careerB);

            // Then by Subperiod (P-I vs P-II blocks)
            if (a.subperiod !== b.subperiod) return a.subperiod - b.subperiod;

            // Finally by StudentCount (Descending - largest first within the group)
            return (b.studentCount || 0) - (a.studentCount || 0);
        });

        unassigned.forEach(s => {
            if (s.studentCount < 12 && !s.isQuorumException) {
                s.warning = "Quórum Insuficiente (<12)";
                return;
            }

            let durationMins = 120;
            let maxSessions = null;
            let dynamicDuration = false; // Flag: recalculate duration per-day based on actual sessions
            const loadData = normalizedLoads.find(l => l.subjectName === s.subjectName);
            if (loadData) {
                if (loadData.intensity) {
                    durationMins = Math.round(loadData.intensity * 60);
                    if (loadData.totalHours) maxSessions = Math.ceil(loadData.totalHours / loadData.intensity);
                } else if (loadData.totalHours) {
                    // Will be recalculated inside the day loop using actual available dates
                    dynamicDuration = true;
                }
            }
            s.durationMins = durationMins;

            const win = shiftWindows[s.shift] || shiftWindows['Diurna'];
            const winStart = toMins(win.start);
            const winEnd = toMins(win.end);

            // --- TIERED DAY PRIORITY ---
            // The algorithm tries day by day in order. To prioritize Mon-Wed, 
            // we ensure the search array starts with L, M, X.
            let winDays = ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes'];
            if (s.shift === 'Sabatina') {
                winDays = ['Sabado'];
            } else if (win && win.days && Array.isArray(win.days)) {
                winDays = win.days;
            } else {
                // Explicitly enforce Mon-Wed priority for standard shifts
                winDays = ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes'];
            }

            const validRooms = (context.classrooms || [])
                .filter(r => r.active != 0 && r.capacity >= s.studentCount)
                .sort((a, b) => {
                    // Stable sort by capacity: prefer smallest room that fits to free up larger rooms
                    const diff = a.capacity - b.capacity;
                    if (diff !== 0) return diff;
                    // Then by name to keep it consistent
                    return String(a.name).localeCompare(String(b.name));
                });

            let placed = false;
            let auditLog = [];
            let stats = {
                slotsTested: 0,
                daysTested: 0,
                studentConflictCount: 0,
                teacherConflictCount: 0,
                roomBusyCount: 0,
                lunchConflictCount: 0,
                studentConflicts: new Map(),
                teacherConflicts: new Map(),
                lastRoomConflict: null
            };

            for (const day of winDays) {
                if (placed) break;
                const availableDates = allDatesByDay[day] || [];
                if (availableDates.length === 0) continue;
                stats.daysTested++;

                // Filter dates by subperiod BEFORE calculating duration
                let targetDates = availableDates;
                const subRange = (s.subperiod && context.period?.subperiods) ? context.period.subperiods[s.subperiod] : null;
                if (subRange) {
                    targetDates = availableDates.filter(d => d >= subRange.start && d <= subRange.end);
                }

                if (targetDates.length === 0) continue;

                // Recalculate duration based on actual available sessions for THIS day
                if (dynamicDuration && loadData.totalHours) {
                    durationMins = Math.round((loadData.totalHours / targetDates.length) * 60);
                    s.durationMins = durationMins;
                }

                for (let t = winStart; t <= winEnd - durationMins; t += intervalMins) {
                    if (placed) break;
                    const tEnd = t + durationMins;
                    const timeStr = formatTime(t);
                    stats.slotsTested++;

                    if (Math.max(t, lunchStart) < Math.min(tEnd, lunchEnd)) {
                        stats.lunchConflictCount++;
                        // Avoid adding too much to log to prevent memory blowup
                        if (auditLog.length < 50) auditLog.push({ day, time: timeStr, status: 'Lunch', detail: 'Coincide con almuerzo' });
                        continue;
                    }

                    // Pre-check dates availability for this specific day/time
                    // This is important if maxSessions is used
                    const checkRoomAvailability = (roomName) => {
                        const freeDates = targetDates.filter(d => !checkBusyGranular(roomUsage, roomName, [d], s.subperiod, t, tEnd, intervalMins));
                        return freeDates;
                    };

                    // --- HUMAN CONFLICT CHECK (Student/Teacher) ---
                    const stConflict = checkStudentsBusy(s.studentIds, targetDates, s.subperiod, t, tEnd, intervalMins);
                    if (stConflict) {
                        stats.studentConflictCount++;
                        stats.studentConflicts.set(stConflict, (stats.studentConflicts.get(stConflict) || 0) + 1);
                        auditLog.push({ day, time: timeStr, status: 'Conflict', detail: `Alumnos ocupados (+${intervalMins}m receso) (${stConflict})` });
                        continue;
                    }

                    const tConflict = s.teacherName ? checkBusyGranular(teacherUsage, s.teacherName, targetDates, s.subperiod, t, tEnd, intervalMins) : null;
                    if (tConflict) {
                        stats.teacherConflictCount++;
                        stats.teacherConflicts.set(tConflict, (stats.teacherConflicts.get(tConflict) || 0) + 1);
                        auditLog.push({ day, time: timeStr, status: 'Conflict', detail: `Docente ocupado (+${intervalMins}m receso) (${tConflict})` });
                        continue;
                    }

                    // Mechanical Conflict Check
                    let roomRejectionDetail = "";
                    for (const room of validRooms) {
                        if (placed) break;

                        if (maxSessions) {
                            // Calculate sessions actually free for THIS room at THIS time
                            const freeDates = targetDates.filter(d => !checkBusyGranular(roomUsage, room.name, [d], s.subperiod, t, tEnd, 0));

                            if (freeDates.length >= maxSessions) {
                                const selectedDates = freeDates.slice(0, maxSessions);
                                s.day = day; s.start = formatTime(t); s.end = formatTime(tEnd); s.room = room.name; s.assignedDates = selectedDates;
                                markBusyGranular(roomUsage, room.name, selectedDates, s);
                                if (s.teacherName) markBusyGranular(teacherUsage, s.teacherName, selectedDates, s);
                                if (s.studentIds) s.studentIds.forEach(sid => markBusyGranular(studentUsage, sid, selectedDates, s));
                                placed = true;
                            } else {
                                stats.roomBusyCount++;
                                roomRejectionDetail = `Sesiones insuficientes (${freeDates.length}/${maxSessions})`;
                            }
                        } else {
                            const rConflict = checkBusyGranular(roomUsage, room.name, targetDates, s.subperiod, t, tEnd, 0);
                            if (rConflict) {
                                stats.roomBusyCount++;
                                roomRejectionDetail = `Aulas ocupadas (${room.name}: ${rConflict})`;
                                continue;
                            }

                            s.day = day; s.start = formatTime(t); s.end = formatTime(tEnd); s.room = room.name; s.assignedDates = targetDates;
                            markBusyGranular(roomUsage, room.name, targetDates, s);
                            if (s.teacherName) markBusyGranular(teacherUsage, s.teacherName, targetDates, s);
                            if (s.studentIds) s.studentIds.forEach(sid => markBusyGranular(studentUsage, sid, targetDates, s));
                            placed = true;
                        }
                    }
                    if (!placed) {
                        auditLog.push({ day, time: timeStr, status: 'RoomBusy', detail: roomRejectionDetail || 'Sin aula libre' });
                    }
                }
            }

            s.auditLog = auditLog;

            if (!placed) {
                if (validRooms.length === 0) {
                    s.warning = `Sin aulas de capacidad ${s.studentCount}`;
                } else if (stats.slotsTested === 0) {
                    s.warning = "Fuera de ventana horaria permitida";
                } else {
                    const topStudentConflict = [...stats.studentConflicts.entries()].sort((a, b) => b[1] - a[1])[0];
                    const topTeacherConflict = [...stats.teacherConflicts.entries()].sort((a, b) => b[1] - a[1])[0];

                    if (topStudentConflict && stats.studentConflictCount > (stats.slotsTested * 0.4)) {
                        s.warning = `Choque Alumnos: ${topStudentConflict[0]} (${topStudentConflict[1]} veces)`;
                    } else if (topTeacherConflict && stats.teacherConflictCount > (stats.slotsTested * 0.4)) {
                        s.warning = `Choque Docente: ${topTeacherConflict[0]}`;
                    } else {
                        s.warning = `Tras ${stats.slotsTested} intentos: ${stats.roomBusyCount} aulas ocupadas, ${stats.studentConflictCount} choques alumnos`;
                    }
                    s.isConflict = true;
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

    const removeTildes = (str) => {
        if (!str) return "";
        return str.normalize("NFD").replace(/[\u0300-\u036f]/g, "");
    };

    const checkTeacherSkills = (instructor, subjectName) => {
        if (!instructor || !instructor.instructorSkills || !subjectName) return true;

        const cleanSubject = removeTildes(subjectName.toLowerCase());
        return instructor.instructorSkills.some(skill => {
            const cleanSkill = removeTildes(skill.name.toLowerCase());
            return cleanSubject.includes(cleanSkill) || cleanSkill.includes(cleanSubject);
        });
    };

    const checkTeacherAvailability = (instructor, day, startMins, endMins) => {
        if (!instructor || !day) return true;

        // Normalize day name to handle tildes (e.g., Miércoles vs Miercoles)
        const cleanDay = removeTildes(day);

        // Find the record in availabilityRecords using normalized names
        // locallib.php returns disponibilityRecords indexed by Spanish day names (with tildes potentially)
        const records = instructor.disponibilityRecords || {};
        const targetKey = Object.keys(records).find(k => removeTildes(k) === cleanDay);

        if (!targetKey) return true;

        const dayRanges = records[targetKey] || [];
        if (dayRanges.length === 0) return false;

        return dayRanges.some(rangeStr => {
            const parts = rangeStr.split(',').map(p => p.trim());
            if (parts.length !== 2) return false;
            const startRange = toMins(parts[0]);
            const endRange = toMins(parts[1]);
            return startMins >= startRange && endMins <= endRange;
        });
    };

    const detectConflicts = (schedule, allSchedules, context) => {
        const issues = [];
        if (!schedule || schedule.day === 'N/A') return issues;
        const sStart = toMins(schedule.start);
        const sEnd = toMins(schedule.end);
        const sDates = new Set(schedule.assignedDates || []);

        const intervalMins = context.configSettings?.intervalMinutes || 10;

        const checkOverlap = (other) => {
            if (other.id === schedule.id) return false;
            const oDates = other.assignedDates || [];
            if (schedule.assignedDates && other.assignedDates) {
                if (!oDates.some(d => sDates.has(d))) return false;
            } else if (other.day !== schedule.day) return false;

            const subOverlap = (other.subperiod === 0) || (schedule.subperiod === 0) || (other.subperiod === schedule.subperiod);
            if (!subOverlap) return false;
            // Include interval padding to enforce recess in conflict detection
            return Math.max(sStart, toMins(other.start) - intervalMins) < Math.min(sEnd, toMins(other.end) + intervalMins);
        };

        // --- Teacher Specific Conflicts ---
        if (schedule.teacherName) {
            // Existing: busy at same time
            const tC = allSchedules.find(s => s.teacherName === schedule.teacherName && checkOverlap(s));
            if (tC) issues.push({ type: 'teacher', message: `Docente ocupado en ${tC.day} ${tC.start}${tC.isExternal ? ' (Externo)' : ''}`, relatedId: tC.id });

            // NEW: Availability and Competency Check
            if (context?.instructors) {
                const instructor = context.instructors.find(i =>
                    i.instructorName === schedule.teacherName ||
                    i.id == schedule.instructorId ||
                    i.instructorId == schedule.instructorId
                );
                if (instructor) {
                    if (!checkTeacherAvailability(instructor, schedule.day, sStart, sEnd)) {
                        issues.push({ type: 'availability', message: `Docente no disponible los ${schedule.day} en este horario` });
                    }
                    if (!checkTeacherSkills(instructor, schedule.subjectName)) {
                        issues.push({ type: 'competency', message: `Docente no cuenta con la competencia para: ${schedule.subjectName}` });
                    }
                }
            }
        }

        if (schedule.room && schedule.room !== 'Sin aula') {
            const rC = allSchedules.find(s => s.room === schedule.room && checkOverlap(s));
            if (rC) issues.push({ type: 'room', message: `Aula ocupada por ${rC.subjectName}${rC.isExternal ? ' (Externo)' : ''}`, relatedId: rC.id });
            if (context?.classrooms) {
                const rD = context.classrooms.find(r => r.name === schedule.room);
                if (rD && schedule.studentCount > rD.capacity) issues.push({ type: 'capacity', message: `Sobrecapacidad (${schedule.studentCount}/${rD.capacity})` });
            }
        }
        if (schedule.studentIds?.length > 0) {
            let conflictSubject = null;
            let conflictingStudents = [];

            allSchedules.some(s => {
                const subOverlap = (s.subperiod === 0) || (schedule.subperiod === 0) || (s.subperiod === schedule.subperiod);
                if (!subOverlap || s.id === schedule.id || s.day !== schedule.day) return false;
                if (Math.max(sStart, toMins(s.start) - intervalMins) >= Math.min(sEnd, toMins(s.end) + intervalMins)) return false;

                const overlaps = schedule.studentIds.filter(sid => (s.studentIds || []).includes(sid));
                if (overlaps.length > 0) {
                    conflictSubject = s.subjectName + (s.isExternal ? ' (Externo)' : '');
                    const nameMap = new Map();
                    if (context?.students) context.students.forEach(st => nameMap.set(st.id, st.name));
                    conflictingStudents = overlaps.slice(0, 3).map(sid => nameMap.get(sid) || `ID:${sid}`);
                    if (overlaps.length > 3) conflictingStudents.push('...');
                    return true;
                }
                return false;
            });

            if (conflictSubject) {
                let msg = `Choque alumnos con ${conflictSubject}`;
                if (conflictingStudents.length > 0) msg += ` (${conflictingStudents.join(', ')})`;
                issues.push({ type: 'group', message: msg });
            }
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
        getEffectiveWeeks,
        checkTeacherAvailability,
        checkTeacherSkills
    };
})();
