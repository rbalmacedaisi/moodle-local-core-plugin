/**
 * Full Calendar View Component - Tab Version
 * 
 * Integrated with FullCalendar 6 to visualize ALL class schedules in a monthly view.
 * Allows "liberating" (excluding) specific days for automated scheduling.
 */

(function () {
    const { ref, computed, watch, onMounted, nextTick, onBeforeUnmount } = Vue;

    // Inject styles for fullcalendar customization
    if (!document.getElementById('gmk-fullcalendar-styles')) {
        const style = document.createElement('style');
        style.id = 'gmk-fullcalendar-styles';
        style.textContent = `
            .fc .fc-toolbar-title { font-size: 1.1rem !important; font-weight: 800 !important; color: #1e293b; text-transform: capitalize; }
            .fc .fc-button-primary { background-color: #f1f5f9 !important; border-color: #e2e8f0 !important; color: #475569 !important; font-weight: 800 !important; font-size: 0.75rem !important; text-transform: uppercase; padding: 0.4rem 0.8rem !important; }
            .fc .fc-button-primary:hover { background-color: #e2e8f0 !important; }
            .fc .fc-button-active { background-color: #3b82f6 !important; color: white !important; border-color: #2563eb !important; }
            .fc .fc-daygrid-day-number { font-size: 0.8rem; font-weight: 700; color: #64748b; padding: 8px !important; }
            .fc .fc-col-header-cell-cushion { font-size: 0.7rem; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; padding: 10px !important; }
            .fc-theme-standard td, .fc-theme-standard th { border-color: #f1f5f9 !important; }
            .fc .fc-day-today { background: rgba(59, 130, 246, 0.04) !important; }
            .fc-event { border: none !important; border-radius: 6px !important; padding: 2px 4px !important; box-shadow: 0 1px 2px rgba(0,0,0,0.05) !important; transition: transform 0.15s ease; cursor: pointer; }
            .fc-event:hover { transform: translateY(-1px); }
        `;
        document.head.appendChild(style);
    }

    window.FullCalendarView = {
        template: `
            <div class="flex flex-col h-full bg-slate-50 overflow-hidden rounded-xl border border-slate-200 shadow-sm">
                <!-- Premium Header -->
                <div class="px-6 py-4 bg-white border-b border-slate-200 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                    <div>
                        <h3 class="text-lg font-extrabold text-slate-800 flex items-center gap-2">
                             <div class="p-2 bg-blue-100 rounded-lg"><i data-lucide="calendar" class="w-5 h-5 text-blue-600"></i></div>
                             Vista Mensual de Planificación
                        </h3>
                        <p class="text-xs text-slate-500 mt-1 font-medium">Gestiona exclusiones y visualiza la carga horaria global del mes.</p>
                    </div>

                    <!-- Modern Legend -->
                    <div class="flex flex-wrap items-center gap-4 bg-slate-50 px-4 py-2 rounded-xl border border-slate-100">
                        <div class="flex items-center gap-2">
                            <div class="w-2.5 h-2.5 rounded-full bg-blue-500 shadow-sm"></div>
                               <span class="text-[10px] font-bold text-slate-600 uppercase">Programada</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-2.5 h-2.5 rounded-full bg-red-400 shadow-sm"></div>
                            <span class="text-[10px] font-bold text-slate-600 uppercase">Liberada</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-2.5 h-2.5 rounded-full bg-slate-400 shadow-sm"></div>
                            <span class="text-[10px] font-bold text-slate-600 uppercase">Festivo</span>
                        </div>
                        <div class="flex items-center gap-2 border-l border-slate-200 pl-4">
                            <i data-lucide="info" class="w-3.5 h-3.5 text-slate-400"></i>
                            <span class="text-[10px] text-slate-400 italic">Clic para alternar estado</span>
                        </div>
                    </div>
                </div>

                <!-- Calendar Content Outer -->
                <div class="flex-1 bg-white p-4 overflow-hidden flex flex-col min-h-0">
                    <div id="full-calendar-container" class="h-full min-h-[500px]"></div>
                </div>
            </div>
        `,
        setup() {
            const calendar = ref(null);
            const store = window.schedulerStore;

            onMounted(() => {
                nextTick(() => {
                    initCalendar();
                });
            });

            onBeforeUnmount(() => {
                if (calendar.value) {
                    calendar.value.destroy();
                    calendar.value = null;
                }
            });

            // Watch for changes in anything that affects events
            watch([
                () => store.state.generatedSchedules,
                () => store.state.context.period,
                () => store.state.activePeriod,
                () => store.state.careerFilter,
                () => store.state.shiftFilter
            ], () => {
                if (calendar.value) {
                    calendar.value.removeAllEvents();
                    calendar.value.addEventSource(generateEvents());
                }
            }, { deep: true });

            const initCalendar = () => {
                const calendarEl = document.getElementById('full-calendar-container');
                if (!calendarEl || typeof FullCalendar === 'undefined') {
                    console.error("FullCalendar library not loaded!");
                    return;
                }

                const events = generateEvents();

                calendar.value = new FullCalendar.Calendar(calendarEl, {
                    initialView: 'dayGridMonth',
                    headerToolbar: {
                        left: 'prev,next today',
                        center: 'title',
                        right: ''
                    },
                    locale: 'es',
                    events: events,
                    dayMaxEvents: 5,
                    height: '100%',
                    selectable: false,
                    eventClick: handleEventClick,
                    dayCellDidMount: (info) => {
                        const d = info.date;
                        const dateStr = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
                        const isHoliday = store.state.context.holidays.some(h => {
                            const hDate = new Date(h.date * 1000);
                            return `${hDate.getFullYear()}-${String(hDate.getMonth() + 1).padStart(2, '0')}-${String(hDate.getDate()).padStart(2, '0')}` === dateStr;
                        });
                        if (isHoliday) {
                            info.el.style.backgroundColor = '#f8fafc';
                        }
                    },
                    eventContent: (arg) => {
                        const props = arg.event.extendedProps;
                        const isExcluded = props.isExcluded;
                        const isHoliday = props.isHoliday;

                        let bgColor = isExcluded ? (isHoliday ? 'bg-slate-100' : 'bg-red-50') : 'bg-blue-600';
                        let borderColor = isExcluded ? (isHoliday ? 'border-slate-200' : 'border-red-200') : 'border-blue-700';
                        let textColor = isExcluded ? (isHoliday ? 'text-slate-500' : 'text-red-700') : 'text-white';
                        let dotColor = isExcluded ? (isHoliday ? 'bg-slate-400' : 'bg-red-500') : 'bg-blue-200';

                        let statusBadge = '';
                        if (isHoliday) {
                            statusBadge = '<span class="text-[7px] font-black uppercase text-slate-600 bg-white px-1 rounded border border-slate-100 shadow-sm">FESTIVO</span>';
                        } else if (isExcluded) {
                            statusBadge = '<span class="text-[7px] font-black uppercase text-red-600 bg-white px-1 rounded border border-red-100 shadow-sm">LIBERADO</span>';
                        }

                        return {
                            html: `
                                <div class="p-1 px-1.5 rounded flex flex-col gap-0.5 border ${borderColor} ${bgColor} overflow-hidden shadow-sm">
                                    <div class="flex items-center gap-1.5 overflow-hidden">
                                        <div class="w-1.5 h-1.5 rounded-full ${dotColor} shrink-0"></div>
                                        <span class="text-[9px] font-extrabold truncate ${textColor} uppercase">${arg.event.title}</span>
                                    </div>
                                    <div class="flex justify-between items-center px-0.5">
                                        <span class="text-[8px] font-medium opacity-80 ${textColor}">${arg.event.extendedProps.timeStr || ''}</span>
                                        ${statusBadge}
                                    </div>
                                </div>
                            `
                        };
                    }
                });

                calendar.value.render();
            };

            const generateEvents = () => {
                const schedules = store.state.generatedSchedules || [];
                const activePeriod = store.state.activePeriod;
                if (!activePeriod || !schedules.length) {
                    console.log("DEBUG Calendar: No activePeriod or no schedules", { activePeriod, count: schedules.length });
                    return [];
                }

                // Normalization helper for accents in day names
                const normalizeDay = (day) => {
                    if (!day) return '';
                    return day.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toUpperCase();
                };

                const dayMap = { 'LUNES': 1, 'MARTES': 2, 'MIERCOLES': 3, 'JUEVES': 4, 'VIERNES': 5, 'SABADO': 6, 'DOMINGO': 0 };
                const config = (typeof store.state.context.configSettings === 'string' && store.state.context.configSettings)
                    ? JSON.parse(store.state.context.configSettings)
                    : (store.state.context.configSettings || {});
                const period = store.state.context.period || {};
                const events = [];

                console.log("DEBUG Calendar: Generating events for", schedules.length, "schedules. Period:", period);

                // Robust filtering matching PlanningBoard.js
                const careerFilter = store.state.careerFilter;
                const shiftFilter = store.state.shiftFilter;

                schedules.forEach((sched, schedIdx) => {
                    if (!sched.sessions || sched.sessions.length === 0) {
                        // Fallback: If sessions not present, use the root day/start/end (backward compatibility)
                        if (sched.day && sched.day !== 'N/A') {
                            sched.sessions = [{
                                day: sched.day,
                                start: sched.start,
                                end: sched.end,
                                roomName: sched.room,
                                excluded_dates: sched.excluded_dates || []
                            }];
                        } else {
                            return;
                        }
                    }

                    // Career filter (Robust check)
                    if (careerFilter) {
                        const inList = sched.careerList && sched.careerList.includes(careerFilter);
                        const inString = sched.career && sched.career.includes(careerFilter);
                        if (!inList && !inString) return;
                    }

                    // Shift filter
                    if (shiftFilter && sched.shift !== shiftFilter) return;

                    // Filter by subperiod
                    if (store.state.subperiodFilter !== 0 && sched.subperiod !== 0) {
                        if (sched.subperiod !== store.state.subperiodFilter) return;
                    }

                    // Range for this schedule based on subperiod
                    let startDate = period.start ? new Date(period.start + 'T00:00:00') : new Date();
                    let endDate = period.end ? new Date(period.end + 'T23:59:59') : new Date();

                    if (sched.subperiod === 1 && config.block1start) {
                        startDate = new Date(config.block1start + 'T00:00:00');
                        endDate = new Date(config.block1end + 'T23:59:59');
                    } else if (sched.subperiod === 2 && config.block2start) {
                        startDate = new Date(config.block2start + 'T00:00:00');
                        endDate = new Date(config.block2end + 'T23:59:59');
                    }

                    sched.sessions.forEach((session, sessionIdx) => {
                        const targetDayStr = normalizeDay(session.day);
                        const targetDay = dayMap[targetDayStr];

                        if (targetDay === undefined) {
                            console.warn(`DEBUG Calendar: Day not found in map: "${session.day}" (normalized: "${targetDayStr}")`);
                            return;
                        }

                        let current = new Date(startDate);
                        // Find first occurrence
                        let safety = 0;
                        while (current.getDay() !== targetDay && safety < 8) {
                            current.setDate(current.getDate() + 1);
                            safety++;
                        }

                        while (current <= endDate) {
                            const dateStr = current.toISOString().split('T')[0];

                            // Check exclusions
                            const isExcluded = session.excluded_dates && session.excluded_dates.includes(dateStr);
                            // HOLIDAY CHECK: Automatically mark as excluded if it's a holiday
                            const isHoliday = store.state.context.holidays.some(h => {
                                const hDate = new Date(h.date * 1000);
                                const hStr = hDate.getFullYear() + '-' +
                                    String(hDate.getMonth() + 1).padStart(2, '0') + '-' +
                                    String(hDate.getDate()).padStart(2, '0');
                                return hStr === dateStr;
                            });

                            // FINAL EXCLUSION STATUS: Manual exclusion OR holiday
                            const isEffectiveExcluded = isExcluded || isHoliday;

                            events.push({
                                id: `sess-${schedIdx}-${sessionIdx}-${dateStr}`,
                                title: sched.subjectName,
                                start: `${dateStr}T${session.start}`,
                                end: `${dateStr}T${session.end}`,
                                color: sched.subperiod === 2 ? '#0d9488' : '#2563eb', // Teal for P-II, Blue for P-I
                                extendedProps: {
                                    isExcluded: isEffectiveExcluded,
                                    isHoliday: isHoliday,
                                    isManualExcluded: isExcluded,
                                    schedIdx: schedIdx,
                                    sessionIdx: sessionIdx,
                                    dateStr: dateStr,
                                    teacher: sched.teacherName,
                                    room: session.roomName || sched.room,
                                    career: sched.career,
                                    level: sched.levelDisplay,
                                    typeLabel: sched.typeLabel,
                                    timeStr: `${session.start} - ${session.end}`
                                }
                            });

                            current.setDate(current.getDate() + 7);
                        }
                    });
                });

                console.log(`DEBUG Calendar: Created ${events.length} events.`);

                // Automatically move to the start date of the period in the first load
                if (calendar.value && events.length > 0 && period.start) {
                    const currentViewDate = calendar.value.getDate();
                    const periodStartDate = new Date(period.start + 'T00:00:00');
                    if (currentViewDate.getMonth() !== periodStartDate.getMonth() || currentViewDate.getFullYear() !== periodStartDate.getFullYear()) {
                        calendar.value.gotoDate(period.start);
                    }
                }

                return events;
            };

            const handleEventClick = (info) => {
                const props = info.event.extendedProps;
                if (props.isHoliday) return;

                const sched = store.state.generatedSchedules[props.schedIdx];
                const session = sched.sessions[props.sessionIdx];
                const dateStr = props.dateStr;

                if (!session.excluded_dates) session.excluded_dates = [];

                if (props.isManualExcluded) {
                    session.excluded_dates = session.excluded_dates.filter(d => d !== dateStr);
                } else {
                    session.excluded_dates.push(dateStr);
                }

                // Force refresh
                if (calendar.value) {
                    calendar.value.removeAllEvents();
                    calendar.value.addEventSource(generateEvents());
                }
            };

            return {
                store
            };
        }
    };
})();
