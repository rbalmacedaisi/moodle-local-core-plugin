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
        const drawPageHeader = (levelName, groupData, pageNum, totalPages) => {
            // Banda superior sólida (un solo rectángulo, sin degradado problemático)
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
            doc.setTextColor(199, 210, 254); // indigo-200
            doc.text('Reporte de Horarios por Período de Ingreso', 10, 17);

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
            doc.text(`Pág. ${pageNum} / ${totalPages}`, W - 10, 23, { align: 'right' });

            // ── Tarjeta del período de ingreso ────────────────────────────────────
            doc.setFillColor(241, 245, 249); // slate-100
            doc.roundedRect(8, 29, W - 16, 18, 2, 2, 'F');
            doc.setDrawColor(...C.border);
            doc.setLineWidth(0.3);
            doc.roundedRect(8, 29, W - 16, 18, 2, 2, 'S');

            // Badge azul: período de ingreso
            doc.setFillColor(...C.navy);
            doc.roundedRect(12, 32, 38, 6, 1, 1, 'F');
            doc.setFont('helvetica', 'bold');
            doc.setFontSize(6);
            doc.setTextColor(...C.white);
            doc.text('PERÍODO DE INGRESO', 31, 36.2, { align: 'center' });

            doc.setFont('helvetica', 'bold');
            doc.setFontSize(10);
            doc.setTextColor(30, 41, 59); // slate-800
            doc.text(levelName, 54, 36.5);

            // Stats: asignaturas y estudiantes
            const placedCount   = groupData.classes.filter(c => c.day && c.day !== 'N/A').length;
            const unplacedCount = groupData.classes.filter(c => !c.day || c.day === 'N/A').length;
            const totalStu      = groupData.totalPeriodStudents || 0;
            const totalHrs      = (groupData.totalHours || 0).toFixed(1);

            const stats = [
                { label: 'Asignaturas con horario', value: String(placedCount)   },
                { label: 'Sin horario asignado',    value: String(unplacedCount) },
                { label: 'Estudiantes en período',  value: String(totalStu)      },
                { label: 'Horas asignadas',         value: `${totalHrs} h`       },
            ];

            stats.forEach((s, i) => {
                const x = W - 10 - (stats.length - 1 - i) * 42;
                doc.setFont('helvetica', 'bold');
                doc.setFontSize(9);
                doc.setTextColor(...C.navy);
                doc.text(s.value, x, 35.5, { align: 'right' });
                doc.setFont('helvetica', 'normal');
                doc.setFontSize(5.5);
                doc.setTextColor(...C.slate);
                doc.text(s.label, x, 39.5, { align: 'right' });
            });
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
        groups.forEach((levelName, index) => {
            if (index > 0) doc.addPage();

            const groupData   = groupedSchedules[levelName];
            const totalPages  = groups.length;

            drawPageHeader(levelName, groupData, index + 1, totalPages);

            // ── Tabla de horarios semanal ─────────────────────────────────────────
            const TABLE_START_Y = 50;
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
                            (st.entry_period || 'Sin Definir') === levelName
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

            // Cada día ocupa UNA columna en autoTable.
            // Dibujamos la tabla VACÍA (solo para crear el grid y la altura),
            // luego en didDrawCell pintamos el contenido manualmente bloque a bloque
            // con rectángulos de fondo sombreado alternado.
            const FONT_SIZE   = 6.5;
            const LINE_H_MM   = FONT_SIZE * 0.3528 * 1.3; // fontSize * pt-to-mm * lineHeight
            const BLOCK_PAD   = 2;   // padding interno del bloque
            const BLOCK_GAP   = 1.5; // espacio entre bloques
            const CELL_PAD_X  = 2;
            const CELL_PAD_TOP = 2;

            // Calcular altura de cada bloque por día (considerando wrap de texto)
            // El ancho útil del texto dentro de cada celda
            const textWidth = colW - CELL_PAD_X * 2 - 2;

            const measureBlock = (lines) => {
                // Contar líneas reales tras wrap a textWidth
                doc.setFontSize(FONT_SIZE);
                let totalLines = 0;
                lines.forEach(line => {
                    const wrapped = doc.splitTextToSize(line, textWidth);
                    totalLines += wrapped.length;
                });
                return BLOCK_PAD * 2 + totalLines * LINE_H_MM;
            };

            // Pre-calcular alturas para determinar la altura total de la fila
            const dayHeights = dayDataMap.map(d =>
                d.blocks.length === 0 ? 15 :
                d.blocks.reduce((sum, b) => sum + measureBlock(b.lines) + BLOCK_GAP, -BLOCK_GAP) + CELL_PAD_TOP * 2
            );
            const rowHeight = Math.max(...dayHeights, 15);

            doc.autoTable({
                startY: TABLE_START_Y,
                head: [DAY_DISP],
                body: [DAYS.map(() => '')],
                theme: 'plain',
                styles: {
                    fontSize: FONT_SIZE,
                    cellPadding: { top: CELL_PAD_TOP, right: CELL_PAD_X, bottom: CELL_PAD_TOP, left: CELL_PAD_X },
                    textColor: [30, 41, 59],
                    fillColor: C.white,
                    valign: 'top',
                    overflow: 'linebreak',
                    lineColor: C.border,
                    lineWidth: 0.3,
                    minCellHeight: rowHeight,
                },
                headStyles: {
                    fillColor:   C.navy,
                    textColor:   C.white,
                    fontStyle:   'bold',
                    halign:      'center',
                    fontSize:    8,
                    cellPadding: { top: 3, right: 2, bottom: 3, left: 2 },
                    lineColor:   C.border,
                    lineWidth:   0.3,
                },
                columnStyles: Object.fromEntries(
                    DAYS.map((_, i) => [i, { cellWidth: colW, fillColor: C.white }])
                ),
                margin: { left: 8, right: 8, bottom: 18 },
                willDrawCell: (data) => {
                    if (data.section !== 'body') return;
                    // Pintar fondo blanco explícito para todas las celdas del body
                    doc.setFillColor(...C.white);
                    doc.rect(data.cell.x, data.cell.y, data.cell.width, data.cell.height, 'F');
                    // Borde de celda
                    doc.setDrawColor(...C.border);
                    doc.setLineWidth(0.3);
                    doc.rect(data.cell.x, data.cell.y, data.cell.width, data.cell.height, 'S');
                },
                didDrawCell: (data) => {
                    if (data.section !== 'body') return;
                    const { blocks } = dayDataMap[data.column.index];
                    if (!blocks || blocks.length === 0) return;

                    doc.setFontSize(FONT_SIZE);
                    const cellX = data.cell.x;
                    const cellW = data.cell.width;
                    let cursorY = data.cell.y + CELL_PAD_TOP;

                    // Colores alternos de fondo para bloques
                    const blockBgs = [
                        [239, 246, 255],  // blue-50
                        [248, 250, 252],  // slate-50
                    ];

                    blocks.forEach((block, bi) => {
                        const blockH = measureBlock(block.lines);

                        if (block.isExternal) {
                            // Fichas externas: fondo amber claro + borde amber
                            doc.setFillColor(254, 243, 199); // amber-100
                            doc.roundedRect(cellX + 1, cursorY, cellW - 2, blockH, 0.8, 0.8, 'F');
                            doc.setFillColor(217, 119, 6);   // amber-600
                            doc.rect(cellX + 1, cursorY, 1.5, blockH, 'F');
                        } else {
                            // Fondo alternado normal
                            const bgColor = blockBgs[bi % 2];
                            doc.setFillColor(...bgColor);
                            doc.roundedRect(cellX + 1, cursorY, cellW - 2, blockH, 0.8, 0.8, 'F');

                            // Borde izquierdo de acento (shift-color)
                            const accentColor = block.shift && block.shift.toLowerCase().includes('noche')
                                ? [99, 102, 241]   // indigo
                                : [59, 130, 246];  // blue
                            doc.setFillColor(...accentColor);
                            doc.rect(cellX + 1, cursorY, 1.5, blockH, 'F');
                        }

                        // Texto del bloque
                        let textY = cursorY + BLOCK_PAD + LINE_H_MM * 0.8;
                        block.lines.forEach((line, li) => {
                            const wrapped = doc.splitTextToSize(line, textWidth - 1.5);
                            if (li === 0) {
                                doc.setFont('helvetica', 'bold');
                                doc.setTextColor(block.isExternal ? 146 : C.navy[0], block.isExternal ? 64 : C.navy[1], block.isExternal ? 14 : C.navy[2]);
                            } else {
                                doc.setFont('helvetica', 'normal');
                                doc.setTextColor(30, 41, 59);
                            }
                            wrapped.forEach(wl => {
                                doc.text(wl, cellX + CELL_PAD_X + 1.5, textY);
                                textY += LINE_H_MM;
                            });
                        });

                        cursorY += blockH + BLOCK_GAP;
                    });

                    // Resetear font
                    doc.setFont('helvetica', 'normal');
                    doc.setTextColor(30, 41, 59);
                },
                didDrawPage: (hookData) => {
                    // Redibujar header de página si autoTable crea páginas adicionales
                    if (hookData.pageNumber > 1) {
                        drawPageHeader(levelName, groupData, index + 1 + (hookData.pageNumber - 1), totalPages);
                    }
                    drawFooter();
                },
            });

            // ── Sección "Sin horario asignado" ────────────────────────────────────
            const unplaced = groupData.classes.filter(s => !s.day || s.day === 'N/A');
            if (unplaced.length === 0) return;

            const afterY = (doc.lastAutoTable.finalY || TABLE_START_Y) + 6;

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
            doc.text('SIN HORARIO ASIGNADO', 25, afterY + 4.5, { align: 'center' });

            doc.setFont('helvetica', 'normal');
            doc.setFontSize(6.5);
            doc.setTextColor(120, 53, 15); // amber-900
            doc.text(
                `${unplaced.length} asignatura${unplaced.length > 1 ? 's' : ''} pendiente${unplaced.length > 1 ? 's' : ''} de programación`,
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
