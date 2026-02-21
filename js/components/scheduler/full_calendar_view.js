/**
 * Full Calendar View Component - Tab Version
 * 
 * Integrated with FullCalendar 6 to visualize ALL class schedules in a monthly view.
 * Allows "liberating" (excluding) specific days for automated scheduling.
 */

(function () {
    const { ref, computed, watch, onMounted, nextTick, onBeforeUnmount } = Vue;

    window.FullCalendarView = {
        template: `
            <div class="flex flex-col h-[700px] bg-white">
                <!-- Toolbar / Legend -->
                <div class="p-4 border-b border-slate-100 flex justify-between items-center bg-slate-50/50 shrink-0">
                    <div class="flex items-center gap-6">
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 rounded bg-blue-600"></div>
                            <span class="text-[10px] font-bold text-slate-600 uppercase tracking-wider">Sesión Programada</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 rounded bg-red-100 border border-red-200"></div>
                            <span class="text-[10px] font-bold text-slate-600 uppercase tracking-wider">Día Liberado</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-3 h-3 rounded bg-slate-200"></div>
                            <span class="text-[10px] font-bold text-slate-600 uppercase tracking-wider">Festivo / Cerrado</span>
                        </div>
                    </div>
                    
                    <div class="text-[11px] text-slate-400 italic">
                        * Haz clic en una sesión azul para liberarla. Haz clic en una sesión roja para reactivarla.
                    </div>
                </div>

                <!-- Calendar Area -->
                <div class="flex-1 overflow-hidden p-6 relative">
                    <div id="full-calendar-container" class="h-full"></div>
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

            // Watch for changes in schedules to refresh calendar
            watch(() => store.state.generatedSchedules, () => {
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
                    dayMaxEvents: 3,
                    height: '100%',
                    selectable: false,
                    eventClick: handleEventClick,
                    dayCellDidMount: (info) => {
                        // Mark holidays
                        const dateStr = FullCalendar.formatDate(info.date, { year: 'numeric', month: '2-digit', day: '2-digit' });
                        const isHoliday = store.state.context.holidays.some(h => {
                            const hDate = new Date(h.date * 1000);
                            return FullCalendar.formatDate(hDate, { year: 'numeric', month: '2-digit', day: '2-digit' }) === dateStr;
                        });
                        if (isHoliday) {
                            info.el.style.backgroundColor = '#f8fafc';
                        }
                    }
                });

                calendar.value.render();
            };

            const generateEvents = () => {
                const schedules = store.state.generatedSchedules || [];
                const activePeriod = store.state.activePeriod;
                if (!activePeriod || !schedules.length) return [];

                const period = store.state.context.period || {};
                const config = (typeof store.state.context.configSettings === 'string' && store.state.context.configSettings)
                    ? JSON.parse(store.state.context.configSettings)
                    : (store.state.context.configSettings || {});

                const events = [];

                const dayMap = {
                    'LUNES': 1, 'MARTES': 2, 'MIERCOLES': 3, 'MIÉRCOLES': 3,
                    'JUEVES': 4, 'VIERNES': 5, 'SABADO': 6, 'SÁBADO': 6, 'DOMINGO': 0
                };

                schedules.forEach((sched, schedIdx) => {
                    if (!sched.sessions) return;

                    // Apply filters (Career and Shift)
                    const careerFilter = store.state.careerFilter;
                    const shiftFilter = store.state.shiftFilter;

                    if (careerFilter) {
                        const inList = sched.careerList && sched.careerList.includes(careerFilter);
                        const inString = sched.career && sched.career.includes(careerFilter);
                        if (!inList && !inString) return;
                    }

                    if (shiftFilter && sched.shift !== shiftFilter) return;

                    // Range for this schedule based on subperiod
                    let startDate = period.start ? new Date(period.start) : new Date();
                    let endDate = period.end ? new Date(period.end) : new Date();

                    if (sched.subperiod === 1 && config.block1start) {
                        startDate = new Date(config.block1start);
                        endDate = new Date(config.block1end);
                    } else if (sched.subperiod === 2 && config.block2start) {
                        startDate = new Date(config.block2start);
                        endDate = new Date(config.block2end);
                    }

                    sched.sessions.forEach((session, sessionIdx) => {
                        const targetDay = dayMap[session.day.toUpperCase()];
                        if (targetDay === undefined) return;

                        let current = new Date(startDate);
                        while (current.getDay() !== targetDay) {
                            current.setDate(current.getDate() + 1);
                        }

                        while (current <= endDate) {
                            const dateStr = FullCalendar.formatDate(current, { year: 'numeric', month: '2-digit', day: '2-digit' });

                            const isExcluded = session.excluded_dates && session.excluded_dates.includes(dateStr);
                            const isHoliday = store.state.context.holidays.some(h => {
                                const hDate = new Date(h.date * 1000);
                                return FullCalendar.formatDate(hDate, { year: 'numeric', month: '2-digit', day: '2-digit' }) === dateStr;
                            });

                            events.push({
                                id: `sess-${schedIdx}-${sessionIdx}-${dateStr}`,
                                title: isExcluded ? `[LIBERADO] ${sched.subjectName}` : (isHoliday ? `[FESTIVO] ${sched.subjectName}` : sched.subjectName),
                                start: `${dateStr}T${session.start}`,
                                end: `${dateStr}T${session.end}`,
                                backgroundColor: isExcluded ? '#fee2e2' : (isHoliday ? '#e2e8f0' : '#2563eb'),
                                borderColor: isExcluded ? '#fecaca' : (isHoliday ? '#cbd5e1' : '#1d4ed8'),
                                textColor: isExcluded ? '#991b1b' : (isHoliday ? '#64748b' : '#ffffff'),
                                extendedProps: {
                                    isExcluded: isExcluded,
                                    isHoliday: isHoliday,
                                    schedIdx: schedIdx,
                                    sessionIdx: sessionIdx,
                                    dateStr: dateStr
                                }
                            });

                            current.setDate(current.getDate() + 7);
                        }
                    });
                });

                return events;
            };

            const handleEventClick = (info) => {
                const props = info.event.extendedProps;
                if (props.isHoliday) return;

                const sched = store.state.generatedSchedules[props.schedIdx];
                const session = sched.sessions[props.sessionIdx];
                const dateStr = props.dateStr;

                if (!session.excluded_dates) session.excluded_dates = [];

                if (props.isExcluded) {
                    session.excluded_dates = session.excluded_dates.filter(d => d !== dateStr);
                } else {
                    session.excluded_dates.push(dateStr);
                }

                // Force refresh
                calendar.value.removeAllEvents();
                calendar.value.addEventSource(generateEvents());
            };

            return {
                store
            };
        }
    };
})();
