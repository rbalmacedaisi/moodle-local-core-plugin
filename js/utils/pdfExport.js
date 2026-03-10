/**
 * PDF Export Utilities
 * 
 * Ported from React codebase (pdfExport.js).
 * Handles PDF generation for Group and Teacher schedules.
 * 
 * Dependencies: jsPDF, jspdf-autotable (Must be loaded mostly via script tags in PHP)
 * Structure: Global Object window.SchedulerPDF
 */

(function () {
    if (window.SchedulerPDF) return;

    // Helper to get jsPDF instance
    const getJsPDF = () => {
        const jspdf = window.jspdf ? window.jspdf.jsPDF : window.jsPDF;
        if (!jspdf) {
            console.error("jsPDF library not found!");
            alert("Error: Librería PDF no cargada.");
            return null;
        }
        return new jspdf({
            orientation: 'landscape',
            unit: 'mm',
            format: 'a4'
        });
    };

    const generateGroupSchedulesPDF = (cohorts, academicPeriod, hiddenSchedules = new Set(), subperiod = 0) => {
        const doc = getJsPDF();
        if (!doc) return;

        const subperiodLabel = subperiod === 1 ? ' (P-I)' : (subperiod === 2 ? ' (P-II)' : '');

        const days = ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado', 'Domingo'];

        // Format academicPeriod if it's an object
        const periodLabel = typeof academicPeriod === 'object' && academicPeriod !== null
            ? `${academicPeriod.start} - ${academicPeriod.end}`
            : (academicPeriod || '');

        cohorts.forEach((cohort, index) => {
            if (index > 0) doc.addPage();

            // --- HEADER ---
            doc.setFontSize(14);
            doc.setFont('helvetica', 'bold');
            doc.text('INSTITUTO SUPERIOR DE INGENIERÍA', doc.internal.pageSize.getWidth() / 2, 15, { align: 'center' });

            doc.line(20, 18, doc.internal.pageSize.getWidth() - 20, 18);
            doc.setFontSize(12);
            doc.text('HORARIO' + subperiodLabel, doc.internal.pageSize.getWidth() / 2, 23, { align: 'center' });
            doc.line(20, 25, doc.internal.pageSize.getWidth() - 20, 25);

            // --- METADATA BAR ---
            doc.setFontSize(8);
            doc.setFont('helvetica', 'normal');
            doc.text(`Código: 62`, 25, 30);
            doc.text(`Versión: 0.4`, 100, 30);
            doc.text(`Fecha versión: 07/01/2019`, 180, 30);
            doc.text(`Página: ${index + 1} de ${cohorts.length}`, doc.internal.pageSize.getWidth() - 50, 30);
            doc.line(20, 32, doc.internal.pageSize.getWidth() - 20, 32);

            // --- GROUP DETAILS ---
            const startY = 38;

            // Clean group name: remove trailing "- Bimestre ..."
            let groupName = cohort.key || '';
            if (groupName.includes(' - Bimestre')) {
                groupName = groupName.split(' - Bimestre')[0];
            }

            const totalStudents = (cohort.studentsList || cohort.schedules?.[0]?.studentList || []).length;

            doc.autoTable({
                startY: startY,
                head: [],
                body: [
                    ['Programa:', cohort.career || '', 'Sede - jornada:', `Principal - ${cohort.shift || ''}`],
                    ['Grupo:', groupName, 'Estudiantes:', totalStudents.toString()]
                ],
                theme: 'grid',
                styles: { fontSize: 7, cellPadding: 1, textColor: [0, 0, 0] },
                columnStyles: {
                    0: { fontStyle: 'bold', fillColor: [240, 240, 240], cellWidth: 35 },
                    1: { cellWidth: 93 },
                    2: { fontStyle: 'bold', fillColor: [240, 240, 240], cellWidth: 35 },
                    3: { cellWidth: 94 }
                },
                margin: { left: 20, right: 20 }
            });

            // --- SCHEDULE MATRIX ---
            const matrixStartY = doc.lastAutoTable.finalY + 3; // Reduced spacing

            // Filter classes: remove hidden ones AND filter by subperiod
            const visibleClasses = (cohort.schedules || []).filter(s => {
                // Filter by subperiod
                if (subperiod !== 0 && s.subperiod !== 0 && s.subperiod !== subperiod) return false;

                const isHiddenByCohort = hiddenSchedules.has && hiddenSchedules.has(`${cohort.key}|${s.id}`);
                // Simple check if hiddenSchedules is Set
                if (hiddenSchedules instanceof Set) {
                    if (hiddenSchedules.has(`${cohort.key}|${s.id}`)) return false;
                    const periodMatch = cohort.key.match(/\[(.*?)\]/);
                    if (periodMatch && hiddenSchedules.has(`${periodMatch[1]}|${s.id}`)) return false;
                }
                return true;
            });

            const rowData = days.map(day => {
                const dayClasses = visibleClasses.filter(s => s.day === day)
                    .sort((a, b) => (a.start || "").localeCompare(b.start || ""));

                if (dayClasses.length === 0) return '';

                // Group by subject in the cell too
                const subjectGroups = dayClasses.reduce((acc, sch) => {
                    const name = sch.subjectName || '';
                    if (!acc[name]) acc[name] = [];
                    acc[name].push(sch);
                    return acc;
                }, {});

                return Object.entries(subjectGroups).map(([name, sessions]) => {
                    return sessions.map(s => {
                        // Determine session-specific date range
                        let sessionRange = periodLabel;
                        if (s.assignedDates && s.assignedDates.length > 0) {
                            const sortedDates = [...s.assignedDates].sort();
                            sessionRange = `${sortedDates[0]} - ${sortedDates[sortedDates.length - 1]}`;
                        } else if (s.startDate && s.endDate) {
                            sessionRange = `${s.startDate} - ${s.endDate}`;
                        }

                        return `Horario: ${s.start} - ${s.end}\nSede-Aula: ${s.room || s.classroomName || 'Sin aula'}\nAsignatura: ${name}\nDocente: ${s.teacherName || 'Por asignar'}\nEstudiantes: ${s.studentCount || 0}\nDesde-Hasta: ${sessionRange}`;
                    }).join('\n\n');
                }).join('\n------------------\n');
            });

            const tableWidth = doc.internal.pageSize.getWidth() - 40;
            const colWidth = tableWidth / 7;

            doc.autoTable({
                startY: matrixStartY,
                head: [days],
                body: [rowData],
                theme: 'grid',
                styles: {
                    fontSize: 6,
                    cellPadding: 1.5,
                    textColor: [50, 50, 50],
                    valign: 'top',
                    overflow: 'linebreak'
                },
                headStyles: {
                    fillColor: [240, 240, 240],
                    textColor: [0, 0, 0],
                    fontStyle: 'bold',
                    halign: 'center',
                    lineWidth: 0.1,
                    fontSize: 7
                },
                margin: { left: 20, right: 20 },
                columnStyles: {
                    0: { cellWidth: colWidth },
                    1: { cellWidth: colWidth },
                    2: { cellWidth: colWidth },
                    3: { cellWidth: colWidth },
                    4: { cellWidth: colWidth },
                    5: { cellWidth: colWidth },
                    6: { cellWidth: colWidth }
                }
            });

        });

        doc.save(`Horarios_Grupos_${new Date().toISOString().split('T')[0]}.pdf`);
    };

    const generateTeacherSchedulesPDF = (schedules, academicPeriod, subperiod = 0) => {
        const doc = getJsPDF();
        if (!doc) return;

        const subperiodLabel = subperiod === 1 ? ' (P-I)' : (subperiod === 2 ? ' (P-II)' : '');

        // Filter schedules by subperiod before grouping
        const filteredSchedules = subperiod === 0 ? schedules : schedules.filter(s => s.subperiod === 0 || s.subperiod === subperiod);


        const days = ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado', 'Domingo'];
        const periodLabel = typeof academicPeriod === 'object' && academicPeriod !== null
            ? `${academicPeriod.start} - ${academicPeriod.end}`
            : (academicPeriod || '');

        // 1. Filter and Group by Teacher
        const byTeacher = filteredSchedules.reduce((acc, s) => {
            if (!s.teacherName || s.day === 'N/A' || s.teacherName.toLowerCase().includes('por asignar')) return acc;
            if (!acc[s.teacherName]) acc[s.teacherName] = [];
            acc[s.teacherName].push(s);
            return acc;
        }, {});

        const teacherNames = Object.keys(byTeacher).sort();

        if (teacherNames.length === 0) {
            alert("No hay docentes con horarios asignados para exportar.");
            return;
        }

        teacherNames.forEach((teacherName, index) => {
            if (index > 0) doc.addPage();

            // --- HEADER ---
            doc.setFontSize(14);
            doc.setFont('helvetica', 'bold');
            doc.text('INSTITUTO SUPERIOR DE INGENIERÍA', doc.internal.pageSize.getWidth() / 2, 15, { align: 'center' });

            doc.line(20, 18, doc.internal.pageSize.getWidth() - 20, 18);
            doc.setFontSize(12);
            doc.text('HORARIO DOCENTE' + subperiodLabel, doc.internal.pageSize.getWidth() / 2, 23, { align: 'center' });
            doc.line(20, 25, doc.internal.pageSize.getWidth() - 20, 25);

            // --- METADATA BAR ---
            doc.setFontSize(8);
            doc.setFont('helvetica', 'normal');
            doc.text(`Código: 62`, 25, 30);
            doc.text(`Versión: 0.4`, 100, 30);
            doc.text(`Docente: ${teacherName}`, 140, 30);
            doc.text(`Página: ${index + 1} de ${teacherNames.length}`, doc.internal.pageSize.getWidth() - 50, 30);
            doc.line(20, 32, doc.internal.pageSize.getWidth() - 20, 32);

            // --- DETAILS ---
            doc.autoTable({
                startY: 38,
                body: [
                    ['Docente:', teacherName, 'Periodo:', periodLabel]
                ],
                theme: 'grid',
                styles: { fontSize: 8, cellPadding: 2, textColor: [0, 0, 0] },
                columnStyles: {
                    0: { fontStyle: 'bold', fillColor: [240, 240, 240], cellWidth: 30 },
                    1: { cellWidth: 100 },
                    2: { fontStyle: 'bold', fillColor: [240, 240, 240], cellWidth: 30 },
                    3: { cellWidth: 100 }
                },
                margin: { left: 20, right: 20 }
            });

            // --- MATRIX ---
            const teacherSchedules = byTeacher[teacherName];
            const rowData = days.map(day => {
                const dayClasses = teacherSchedules.filter(s => s.day === day || s.day === day.replace('é', 'e'))
                    .sort((a, b) => (a.start || "").localeCompare(b.start || ""));

                if (dayClasses.length === 0) return '';

                return dayClasses.map(s => {
                    let sessionRange = periodLabel;
                    if (s.assignedDates && s.assignedDates.length > 0) {
                        const sortedDates = [...s.assignedDates].sort();
                        sessionRange = `${sortedDates[0]} - ${sortedDates[sortedDates.length - 1]}`;
                    }
                    return `Horario: ${s.start} - ${s.end}\nAsignatura: ${s.subjectName}\nGrupo: ${s.levelDisplay} (G-${s.subGroup})\nAula: ${s.room || s.classroomName || 'Sin Aula'}\nDesde-Hasta: ${sessionRange}`;
                }).join('\n\n------------------\n\n');
            });

            const tableWidth = doc.internal.pageSize.getWidth() - 40;
            const colWidth = tableWidth / 7;

            doc.autoTable({
                startY: doc.lastAutoTable.finalY + 5,
                head: [days],
                body: [rowData],
                theme: 'grid',
                styles: {
                    fontSize: 6,
                    cellPadding: 2,
                    textColor: [50, 50, 50],
                    valign: 'top',
                    overflow: 'linebreak'
                },
                headStyles: {
                    fillColor: [240, 240, 240],
                    textColor: [0, 0, 0],
                    fontStyle: 'bold',
                    halign: 'center'
                },
                margin: { left: 20, right: 20 },
                columnStyles: {
                    0: { cellWidth: colWidth }, 1: { cellWidth: colWidth }, 2: { cellWidth: colWidth },
                    3: { cellWidth: colWidth }, 4: { cellWidth: colWidth }, 5: { cellWidth: colWidth },
                    6: { cellWidth: colWidth }
                }
            });
        });

        doc.save(`Horarios_Docentes_${new Date().toISOString().split('T')[0]}.pdf`);
    };

    const generateIntakePeriodPDF = (groupedSchedules, academicPeriod, subperiod = 0, allStudents = []) => {
        const doc = getJsPDF();
        if (!doc) return;

        // ── Paleta de colores ────────────────────────────────────────────────────
        const C = {
            navy:       [30,  64,  175],   // encabezado principal
            navyDark:   [23,  48,  135],   // franja decorativa
            navyLight:  [219, 234, 254],   // fondo cabecera tabla
            accent:     [99,  102, 241],   // acento violeta-índigo
            amber:      [217, 119,   6],   // "sin asignar"
            amberLight: [254, 243, 199],   // fondo filas sin asignar
            teal:       [13,  148, 136],   // badge de noches
            tealLight:  [204, 251, 241],
            slate:      [71,   85, 105],   // texto secundario
            slateLight: [248, 250, 252],   // fondo alternado
            white:      [255, 255, 255],
            border:     [226, 232, 240],
        };

        // ── Helpers ───────────────────────────────────────────────────────────────
        const W  = doc.internal.pageSize.getWidth();
        const H  = doc.internal.pageSize.getHeight();
        const normalizeDay = s => s ? s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim() : '';
        const today = new Date().toLocaleDateString('es-PA', { day: '2-digit', month: 'long', year: 'numeric' });
        const subperiodLabel = subperiod === 1 ? 'Bloque 1' : subperiod === 2 ? 'Bloque 2' : 'Semestral';
        const periodLabel = typeof academicPeriod === 'object' && academicPeriod !== null
            ? (academicPeriod.name || `${academicPeriod.start} – ${academicPeriod.end}`)
            : (academicPeriod || 'Todos los períodos');

        const groups = Object.keys(groupedSchedules).sort();
        if (groups.length === 0) {
            alert('No hay horarios asignados para exportar bajo esta vista.');
            return;
        }

        const DAYS     = ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado'];
        const DAY_DISP = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

        // ── Dibujar encabezado global (llamado en cada página) ───────────────────
        const drawPageHeader = (groupData, pageNum, totalPages) => {
            const entryPeriod = groupData.entryPeriod || 'Sin Definir';
            const career      = groupData.career      || '';
            const shift       = groupData.shift       || '';

            // Banda superior sólida
            doc.setFillColor(...C.navy);
            doc.rect(0, 0, W, 26, 'F');

            // Línea decorativa izquierda (acento violeta)
            doc.setFillColor(...C.accent);
            doc.rect(0, 0, 4, 26, 'F');

            // Título institución
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(11);
            doc.setTextColor(...C.white);
            doc.text('INSTITUTO SUPERIOR DE INGENIERÍA', 10, 10);

            // Subtítulo reporte
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(8);
            doc.setTextColor(199, 210, 254);
            doc.text('Distribución de Horarios por Período de Ingreso · Carrera · Jornada', 10, 17);

            // Info periodo académico (derecha)
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(7.5);
            doc.setTextColor(...C.white);
            doc.text(`${periodLabel}  ·  ${subperiodLabel}`, W - 10, 10, { align: 'right' });
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(6.5);
            doc.setTextColor(199, 210, 254);
            doc.text(today, W - 10, 17, { align: 'right' });

            // Paginación
            doc.setFont('helvetica', 'normal');
            doc.setFontSize(6.5);
            doc.setTextColor(199, 210, 254);
            doc.text(`Pag. ${pageNum} / ${totalPages}`, W - 10, 23, { align: 'right' });

            // ── Tarjeta: período | carrera | jornada ──────────────────────────────
            doc.setFillColor(241, 245, 249); // slate-100
            doc.roundedRect(8, 29, W - 16, 20, 2, 2, 'F');
            doc.setDrawColor(...C.border);
            doc.setLineWidth(0.3);
            doc.roundedRect(8, 29, W - 16, 20, 2, 2, 'S');

            // ── Fila 1: Período de ingreso (izquierda) + Stats (derecha) ─────────
            // Badge 1: Período de ingreso
            doc.setFillColor(...C.navy);
            doc.roundedRect(12, 31, 36, 5.5, 1, 1, 'F');
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(5.5);
            doc.setTextColor(...C.white);
            doc.text('PERIODO DE INGRESO', 30, 34.6, { align: 'center' });

            doc.setFont('helvetica', 'bold');
            doc.setFontSize(9.5);
            doc.setTextColor(30, 41, 59);
            doc.text(entryPeriod, 51, 35);

            // Stats (derecha, fila 1) — 4 stats con 28mm de separación
            const placedCount   = groupData.classes.filter(c => c.day && c.day !== 'N/A').length;
            const unplacedCount = groupData.classes.filter(c => !c.day || c.day === 'N/A').length;
            const totalStu      = groupData.totalPeriodStudents || 0;
            const totalHrs      = (groupData.totalHours || 0).toFixed(1);

            const stats = [
                { label: 'Asignat. programadas', value: String(placedCount)   },
                { label: 'Asignat. pendientes',  value: String(unplacedCount) },
                { label: 'Estudiantes activos',  value: String(totalStu)      },
                { label: 'Horas programadas',    value: `${totalHrs}h`        },
            ];
            // Anclar los stats desde W-12, separación de 28mm hacia la izquierda
            stats.forEach((s, i) => {
                const x = W - 12 - (stats.length - 1 - i) * 28;
                doc.setFont('helvetica', 'bold');
                doc.setFontSize(9);
                doc.setTextColor(...C.navy);
                doc.text(s.value, x, 34.5, { align: 'right' });
                doc.setFont('helvetica', 'normal');
                doc.setFontSize(5.5);
                doc.setTextColor(...C.slate);
                doc.text(s.label, x, 38.5, { align: 'right' });
            });

            // ── Fila 2: Carrera (izquierda) + Jornada (junto a carrera) ──────────
            // Badge 2: Carrera
            doc.setFillColor(...C.accent);
            doc.roundedRect(12, 39, 20, 5, 1, 1, 'F');
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(5.5);
            doc.setTextColor(...C.white);
            doc.text('CARRERA', 22, 42.5, { align: 'center' });

            doc.setFont('helvetica', 'normal');
            doc.setFontSize(8);
            doc.setTextColor(30, 41, 59);
            // Truncar carrera si es muy larga (máx ~150mm disponibles)
            const careerText = doc.splitTextToSize(career, 150)[0] || career;
            doc.text(careerText, 35, 42.5);

            // Badge 3: Jornada — colocado a la derecha del texto de carrera
            const shiftColor = shift.toLowerCase().includes('noche')
                ? [99, 102, 241]      // indigo
                : [13, 148, 136];     // teal
            const careerTextW = Math.min(doc.getStringUnitWidth(careerText) * 8 / doc.internal.scaleFactor, 150);
            const shiftBadgeX = 35 + careerTextW + 4;
            doc.setFillColor(...shiftColor);
            doc.roundedRect(shiftBadgeX, 39, 22, 5, 1, 1, 'F');
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(5.5);
            doc.setTextColor(...C.white);
            doc.text('JORNADA', shiftBadgeX + 11, 42.5, { align: 'center' });

            doc.setFont('helvetica', 'bold');
            doc.setFontSize(7.5);
            doc.setTextColor(30, 41, 59);
            doc.text(shift, shiftBadgeX + 25, 42.5);
        };

        // ── Pie de página ────────────────────────────────────────────────────────
        const drawFooter = () => {
            doc.setDrawColor(...C.border);
            doc.setLineWidth(0.3);
            doc.line(8, H - 12, W - 8, H - 12);
            doc.setFont('helvetica', 'italic');
            doc.setFontSize(6);
            doc.setTextColor(148, 163, 184); // slate-400
            doc.text('Generado automáticamente · Tablero de Planificación de Horarios', 8, H - 8);
            doc.text(today, W - 8, H - 8, { align: 'right' });
        };

        // ── Helper: color de celda según jornada ─────────────────────────────────
        const shiftFill = shift => {
            if (!shift) return C.slateLight;
            const s = shift.toLowerCase();
            if (s.includes('noche'))   return [238, 242, 255]; // indigo-50
            if (s.includes('mañana') || s.includes('matutina')) return [239, 246, 255]; // blue-50
            if (s.includes('sabat'))  return [240, 253, 250]; // teal-50
            return C.slateLight;
        };

        // ════════════════════════════════════════════════════════════════════════
        // GENERAR UNA PÁGINA POR GRUPO
        // ════════════════════════════════════════════════════════════════════════
        groups.forEach((groupKey, index) => {
            if (index > 0) doc.addPage();

            const groupData   = groupedSchedules[groupKey];
            const totalPages  = groups.length;

            drawPageHeader(groupData, index + 1, totalPages);

            // ── Tabla de horarios semanal ─────────────────────────────────────────
            const TABLE_START_Y = 52;
            const tableWidth    = W - 16;
            const colW          = tableWidth / DAYS.length;

            // Construir datos por día
            const dayDataMap = DAYS.map(day => {
                const normDay    = normalizeDay(day);
                const dayClasses = groupData.classes
                    .filter(s => s.day && s.day !== 'N/A' && normalizeDay(s.day) === normDay)
                    .sort((a, b) => (a.start || '').localeCompare(b.start || ''));
                if (dayClasses.length === 0) return { blocks: [], fill: C.slateLight };

                const blocks = dayClasses.map(s => {
                    const totalCnt = s.studentIds ? s.studentIds.length : (s.studentCount || 0);
                    let periodCnt = totalCnt;
                    if (allStudents.length > 0 && s.studentIds && s.studentIds.length > 0) {
                        const sidSet = new Set(s.studentIds.map(id => String(id)));
                        periodCnt = allStudents.filter(st =>
                            (sidSet.has(String(st.dbId)) || sidSet.has(String(st.id))) &&
                            (st.entry_period || 'Sin Definir') === groupData.entryPeriod
                        ).length;
                    }
                    const estLabel = allStudents.length > 0
                        ? `Est: ${periodCnt}/${totalCnt}`
                        : `Est: ${totalCnt}`;
                    return {
                        lines: [
                            `${s.start} - ${s.end}`,
                            (s.isExternal ? '[Ext] ' : '') + (s.subjectName || ''),
                            `Doc: ${s.teacherName || 'Sin docente'}`,
                            `Aula: ${s.room || 'Sin aula'}  |  ${estLabel}`,
                        ],
                        shift: s.shift,
                        isExternal: !!s.isExternal,
                    };
                });

                const fill = blocks.length === 1 ? shiftFill(blocks[0].shift) : C.white;
                return { blocks, fill };
            });

            // ── Dibujar tabla manualmente (todo en una sola hoja) ────────────────
            const FONT_SIZE  = 5.5;
            const LINE_H     = 2.6;
            const BLOCK_PAD  = 1.0;
            const BLOCK_GAP  = 0.7;
            const CELL_PAD_X = 1.5;
            const CELL_PAD_T = 1.0;
            const HEAD_H     = 7;
            const MIN_BODY_H = 14;
            const tableX     = 8;

            // Estimar altura de cada bloque
            const charsPerLine = Math.max(1, Math.floor((colW - CELL_PAD_X * 2 - 2) / 1.55));
            const measureBlock = (lines) => {
                let totalLines = 0;
                lines.forEach(line => {
                    totalLines += Math.max(1, Math.ceil((line || '').length / charsPerLine));
                });
                return BLOCK_PAD * 2 + totalLines * LINE_H;
            };

            // Calcular bodyH real: la columna más alta, sin tope
            const bodyHeights = dayDataMap.map(d =>
                d.blocks.length === 0
                    ? MIN_BODY_H
                    : d.blocks.reduce((s, b) => s + measureBlock(b.lines) + BLOCK_GAP, -BLOCK_GAP) + CELL_PAD_T * 2
            );
            const bodyH = Math.max(...bodyHeights, MIN_BODY_H);

            // ── Fila encabezado de días ───────────────────────────────────────────
            doc.setFillColor(...C.navy);
            doc.rect(tableX, TABLE_START_Y, tableWidth, HEAD_H, 'F');
            doc.setDrawColor(...C.border);
            doc.setLineWidth(0.3);
            doc.rect(tableX, TABLE_START_Y, tableWidth, HEAD_H, 'S');
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(8);
            doc.setTextColor(...C.white);
            DAYS.forEach((_, i) => {
                const cx = tableX + i * colW + colW / 2;
                doc.text(DAY_DISP[i], cx, TABLE_START_Y + HEAD_H - 2, { align: 'center' });
                if (i > 0) {
                    doc.setDrawColor(...C.border);
                    doc.line(tableX + i * colW, TABLE_START_Y, tableX + i * colW, TABLE_START_Y + HEAD_H);
                }
            });

            // ── Fila body ─────────────────────────────────────────────────────────
            const bodyY = TABLE_START_Y + HEAD_H;
            DAYS.forEach((_, i) => {
                const cellX = tableX + i * colW;
                doc.setFillColor(...C.white);
                doc.rect(cellX, bodyY, colW, bodyH, 'F');
                doc.setDrawColor(...C.border);
                doc.setLineWidth(0.3);
                doc.rect(cellX, bodyY, colW, bodyH, 'S');
            });

            // ── Contenido bloques ─────────────────────────────────────────────────
            const blockBgs = [[239, 246, 255], [248, 250, 252]];
            doc.setFontSize(FONT_SIZE);

            dayDataMap.forEach((dayData, i) => {
                if (!dayData.blocks || dayData.blocks.length === 0) return;
                const cellX = tableX + i * colW;
                let cursorY = bodyY + CELL_PAD_T;

                dayData.blocks.forEach((block, bi) => {
                    const blockH = measureBlock(block.lines);

                    // Fondo del bloque
                    if (block.isExternal) {
                        doc.setFillColor(254, 243, 199);
                        doc.rect(cellX + 1, cursorY, colW - 2, blockH, 'F');
                        doc.setFillColor(217, 119, 6);
                        doc.rect(cellX + 1, cursorY, 1.5, blockH, 'F');
                    } else {
                        doc.setFillColor(...blockBgs[bi % 2]);
                        doc.rect(cellX + 1, cursorY, colW - 2, blockH, 'F');
                        const accentC = block.shift && block.shift.toLowerCase().includes('noche')
                            ? [99, 102, 241] : [59, 130, 246];
                        doc.setFillColor(...accentC);
                        doc.rect(cellX + 1, cursorY, 1.5, blockH, 'F');
                    }

                    // Texto
                    let textY = cursorY + BLOCK_PAD + LINE_H * 0.85;
                    const textMaxW = colW - CELL_PAD_X * 2 - 1.5;
                    block.lines.forEach((line, li) => {
                        if (li === 0) {
                            doc.setFont('helvetica', 'bold');
                            doc.setTextColor(...(block.isExternal ? [146, 64, 14] : C.navy));
                        } else {
                            doc.setFont('helvetica', 'normal');
                            doc.setTextColor(30, 41, 59);
                        }
                        let txt = line || '';
                        while (txt.length > 3 && doc.getStringUnitWidth(txt) * FONT_SIZE / doc.internal.scaleFactor > textMaxW) {
                            txt = txt.slice(0, -1);
                        }
                        doc.text(txt, cellX + CELL_PAD_X + 1.5, textY);
                        textY += LINE_H;
                    });

                    cursorY += blockH + BLOCK_GAP;
                });
            });

            // Restaurar estado
            doc.setFont('helvetica', 'normal');
            doc.setTextColor(30, 41, 59);
            drawFooter();

            const tableEndY = bodyY + bodyH;

            // ── Sección "Sin horario asignado" ────────────────────────────────────
            const unplaced = groupData.classes.filter(s => !s.day || s.day === 'N/A');
            if (unplaced.length === 0) return;

            const afterY = tableEndY + 6;

            // Etiqueta de sección
            doc.setFillColor(...C.amberLight);
            doc.setDrawColor(...C.amber);
            doc.setLineWidth(0.4);
            doc.roundedRect(8, afterY, W - 16, 7, 1, 1, 'FD');

            doc.setFillColor(...C.amber);
            doc.roundedRect(10, afterY + 1, 30, 5, 0.8, 0.8, 'F');
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(6);
            doc.setTextColor(...C.white);
            doc.text('ASIGNATURAS PENDIENTES', 25, afterY + 4.5, { align: 'center' });

            doc.setFont('helvetica', 'normal');
            doc.setFontSize(6.5);
            doc.setTextColor(120, 53, 15); // amber-900
            doc.text(
                `${unplaced.length} asignatura${unplaced.length > 1 ? 's' : ''} sin horario programado para este grupo`,
                44, afterY + 4.5
            );

            const unplacedRows = unplaced.map(s => {
                const cnt = s.studentIds ? s.studentIds.length : (s.studentCount || 0);
                return [
                    s.subjectName || '—',
                    s.career      || '—',
                    s.shift       || '—',
                    String(cnt),
                    s.teacherName || 'Pendiente',
                ];
            });

            doc.autoTable({
                startY: afterY + 9,
                head: [['Asignatura', 'Carrera', 'Jornada', 'Est.', 'Docente']],
                body: unplacedRows,
                theme: 'striped',
                styles: {
                    fontSize:    7,
                    cellPadding: 2.5,
                    textColor:   [30, 41, 59],
                    lineColor:   C.border,
                    lineWidth:   0.2,
                },
                headStyles: {
                    fillColor:  C.amber,
                    textColor:  C.white,
                    fontStyle:  'bold',
                    fontSize:   7,
                    halign:     'left',
                },
                alternateRowStyles: { fillColor: C.amberLight },
                columnStyles: {
                    0: { cellWidth: 65 },
                    1: { cellWidth: 55 },
                    2: { cellWidth: 25 },
                    3: { cellWidth: 12, halign: 'center' },
                    4: { cellWidth: 'auto' },
                },
                margin: { left: 8, right: 8, bottom: 18 },
                didDrawPage: () => drawFooter(),
            });
        });

        doc.save(`Horarios_Por_Periodo_Ingreso_${new Date().toISOString().split('T')[0]}.pdf`);
    };

    window.SchedulerPDF = {
        generateGroupSchedulesPDF,
        generateTeacherSchedulesPDF,
        generateIntakePeriodPDF
    };

})();
