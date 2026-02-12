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
        return Math.max(start1, start2) < Math.min(end1, end2);
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
                    end: s.end
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
                    return busy.day === s.day && Math.max(sStart, bStart) < Math.min(sEnd, bEnd);
                });

                if (!isBusy) {
                    // Assign!
                    s.teacherName = auth.teacherName;
                    s.instructorId = auth.instructorId; // Keep ID if available

                    if (!teacherBusy[auth.teacherName]) teacherBusy[auth.teacherName] = [];
                    teacherBusy[auth.teacherName].push({
                        day: s.day,
                        start: s.start,
                        end: s.end
                    });
                    break; // Move to next schedule
                }
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
                    end: s.end
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
                    return busy.day === tDay && Math.max(tStartMins, bStart) < Math.min(tStartMins + durationMins, bEnd);
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

    // Export to window
    window.SchedulerAlgorithm = {
        autoAssign,
        getSuggestions,
        toMins,
        formatTime,
        parseTimeRange
    };

})();
