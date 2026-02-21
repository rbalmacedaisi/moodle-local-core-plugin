/**
 * Full Calendar View Component
 * 
 * Integrated with FullCalendar 6 to visualize class schedules in a monthly view.
 * Allows "liberating" (excluding) specific days for automated scheduling.
 */

(function () {
    const { ref, computed, watch, onMounted, nextTick } = Vue;

    window.FullCalendarView = {
        template: `
            <div v-if="isOpen" class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm" @click.self="close">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-5xl h-[90vh] flex flex-col overflow-hidden animate-in zoom-in-95 duration-200">
                    <!-- Header -->
                    <div class="p-4 border-b border-slate-200 bg-slate-50 flex justify-between items-center shrink-0">
                        <div class="flex items-center gap-3">
                            <div class="p-2 bg-blue-100 rounded-lg text-blue-600">
                                <i data-lucide="calendar" class="w-5 h-5"></i>
                            </div>
                            <div>
                                <h3 class="font-bold text-slate-800 leading-none">{{ schedule?.subjectName }}</h3>
                                <p class="text-xs text-slate-500 mt-1 uppercase font-bold tracking-tight">
                                    {{ schedule?.career }} • {{ schedule?.shift }} • Nivel {{ schedule?.levelDisplay }}
                                </p>
                            </div>
                        </div>
                        
                        <div class="flex items-center gap-3">
                            <div class="flex flex-col items-end mr-4">
                                <span class="text-[10px] font-bold text-slate-400 uppercase">Resumen de Sesiones</span>
                                <div class="flex gap-1 mt-1">
                                    <span v-for="sess in schedule?.sessions" :key="sess.day" class="px-1.5 py-0.5 bg-slate-200 text-slate-600 rounded text-[9px] font-bold uppercase">
                                        {{ sess.day?.substring(0,3) }}: {{ sess.start }}-{{ sess.end }}
                                    </span>
                                </div>
                            </div>
                            <button @click="close" class="p-2 hover:bg-slate-200 rounded-full transition-colors">
                                <i data-lucide="x" class="w-5 h-5 text-slate-400"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Calendar Area -->
                    <div class="flex-1 overflow-hidden p-6 bg-white relative">
                        <div id="full-calendar-container" class="h-full"></div>
                        
                        <!-- Legend overlay -->
                        <div class="absolute bottom-10 left-10 flex gap-4 bg-white/90 backdrop-blur pb-2 pr-2 rounded-lg text-[10px] font-bold uppercase z-10 p-2 shadow-sm border border-slate-100">
                            <div class="flex items-center gap-1.5">
                                <div class="w-3 h-3 rounded bg-blue-600"></div>
                                <span class="text-slate-600">Sesión Programada</span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <div class="w-3 h-3 rounded bg-red-100 border border-red-200"></div>
                                <span class="text-slate-600">Día Liberado (Manual)</span>
                            </div>
                            <div class="flex items-center gap-1.5">
                                <div class="w-3 h-3 rounded bg-slate-200"></div>
                                <span class="text-slate-600">Festivo / Cerrado (Global)</span>
                            </div>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="p-4 bg-slate-50 border-t border-slate-200 flex justify-between items-center shrink-0">
                        <p class="text-[11px] text-slate-500">
                            * Los cambios se aplicarán automáticamente al motor de programación al guardar el tablero.
                        </p>
                        <button @click="close" class="px-6 py-2 bg-slate-800 text-white rounded-lg text-sm font-bold shadow-lg hover:shadow-xl transition-all">
                            Cerrar Vista
                        </button>
                    </div>
                </div>
            </div>
        `,
        setup() {
            const isOpen = ref(false);
            const schedule = ref(null);
            const calendar = ref(null);
            const store = window.schedulerStore;

            const close = () => {
                isOpen.value = false;
                if (calendar.value) {
                    calendar.value.destroy();
                    calendar.value = null;
                }
            };

            const open = (sched) => {
                schedule.value = sched;
                isOpen.value = true;
                nextTick(() => {
                    initCalendar();
                });
            };

            const initCalendar = () => {
                const calendarEl = document.getElementById('full-calendar-container');
                if (!calendarEl) return;

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
                    dayMaxEvents: true,
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
                            info.el.style.backgroundColor = '#f1f5f9';
                        }
                    }
                });

                calendar.value.render();
                if (window.lucide) window.lucide.createIcons();
            };

            const generateEvents = () => {
                if (!schedule.value || !store.state.activePeriod) return [];

                const period = store.state.activePeriod;
                const events = [];

                // Dates for the period (we assume block1 or whole period based on subperiod)
                // If subperiod is 1 (P-I) we use block1, if 2 (P-II) block2.
                let startDate = new Date(period.startdate * 1000);
                let endDate = new Date(period.enddate * 1000);

                if (schedule.value.subperiod === 1 && period.block1start) {
                    startDate = new Date(period.block1start * 1000);
                    endDate = new Date(period.block1end * 1000);
                } else if (schedule.value.subperiod === 2 && period.block2start) {
                    startDate = new Date(period.block2start * 1000);
                    endDate = new Date(period.block2end * 1000);
                }

                const dayMap = {
                    'LUNES': 1, 'MARTES': 2, 'MIERCOLES': 3, 'MIÉRCOLES': 3,
                    'JUEVES': 4, 'VIERNES': 5, 'SABADO': 6, 'SÁBADO': 6, 'DOMINGO': 0
                };

                schedule.value.sessions.forEach((session, sessionIdx) => {
                    const targetDay = dayMap[session.day.toUpperCase()];
                    if (targetDay === undefined) return;

                    let current = new Date(startDate);
                    // Adjust current to the first occurrence of targetDay
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
                            id: `sess-${sessionIdx}-${dateStr}`,
                            title: isExcluded ? 'LIBERADO' : (isHoliday ? 'FESTIVO' : schedule.value.subjectName),
                            start: `${dateStr}T${session.start}`,
                            end: `${dateStr}T${session.end}`,
                            sessionIdx: sessionIdx,
                            dateStr: dateStr,
                            backgroundColor: isExcluded ? '#fee2e2' : (isHoliday ? '#e2e8f0' : '#2563eb'),
                            borderColor: isExcluded ? '#fecaca' : (isHoliday ? '#cbd5e1' : '#1d4ed8'),
                            textColor: isExcluded ? '#991b1b' : (isHoliday ? '#64748b' : '#ffffff'),
                            extendedProps: {
                                isExcluded: isExcluded,
                                isHoliday: isHoliday,
                                sessionIdx: sessionIdx
                            }
                        });

                        current.setDate(current.getDate() + 7); // Next week
                    }
                });

                return events;
            };

            const handleEventClick = (info) => {
                const props = info.event.extendedProps;
                if (props.isHoliday) return; // Can't toggle holidays

                const sessIdx = props.sessionIdx;
                const dateStr = info.event.startStr.split('T')[0];
                const session = schedule.value.sessions[sessIdx];

                if (!session.excluded_dates) session.excluded_dates = [];

                if (props.isExcluded) {
                    // Re-include
                    session.excluded_dates = session.excluded_dates.filter(d => d !== dateStr);
                } else {
                    // Exclude
                    session.excluded_dates.push(dateStr);
                }

                // Refresh calendar
                calendar.value.removeAllEvents();
                calendar.value.addEventSource(generateEvents());
            };

            // Expose globally to be called from planning_board
            window.openCalendarView = open;

            return {
                isOpen,
                schedule,
                close,
                store
            };
        }
    };
})();
