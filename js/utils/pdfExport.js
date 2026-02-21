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

    const generateIntakePeriodPDF = (groupedSchedules, academicPeriod, subperiod = 0) => {
        const doc = getJsPDF();
        if (!doc) return;

        const subperiodLabel = subperiod === 1 ? ' (Bloque 1)' : (subperiod === 2 ? ' (Bloque 2)' : '');
        const periodLabel = typeof academicPeriod === 'object' && academicPeriod !== null
            ? `${academicPeriod.start} - ${academicPeriod.end}`
            : (academicPeriod || '');

        const groups = Object.keys(groupedSchedules).sort();

        if (groups.length === 0) {
            alert("No hay horarios asignados para exportar bajo esta vista.");
            return;
        }

        const dayOrder = { 'Lunes': 1, 'Martes': 2, 'Miercoles': 3, 'Miércoles': 3, 'Jueves': 4, 'Viernes': 5, 'Sabado': 6, 'Sábado': 6, 'Domingo': 7 };

        groups.forEach((levelName, index) => {
            if (index > 0) doc.addPage();
            const groupData = groupedSchedules[levelName];

            // --- PREMIUM HEADER ---
            doc.setFillColor(30, 64, 175); // Royal Blue
            doc.rect(0, 0, doc.internal.pageSize.getWidth(), 35, 'F');

            doc.setFontSize(16);
            doc.setFont('helvetica', 'bold');
            doc.setTextColor(255, 255, 255);
            doc.text('INSTITUTO SUPERIOR DE INGENIERÍA', doc.internal.pageSize.getWidth() / 2, 15, { align: 'center' });

            doc.setFontSize(12);
            doc.setFont('helvetica', 'normal');
            doc.text(`REPORTE DE HORARIOS POR PERIODO DE INGRESO${subperiodLabel}`, doc.internal.pageSize.getWidth() / 2, 23, { align: 'center' });

            // --- METADATA SECTION ---
            doc.setTextColor(50, 50, 50);
            doc.setFontSize(10);
            doc.setFont('helvetica', 'bold');
            doc.text(`Periodo de Ingreso:`, 20, 45);
            doc.setFont('helvetica', 'normal');
            doc.text(levelName, 55, 45);

            doc.setFont('helvetica', 'bold');
            doc.text(`Horas Totales:`, 20, 52);
            doc.setFont('helvetica', 'normal');
            doc.text(`${Math.round(groupData.totalHours)} hrs`, 55, 52);

            doc.setFont('helvetica', 'bold');
            doc.text(`Periodo Académico:`, 140, 45);
            doc.setFont('helvetica', 'normal');
            doc.text(periodLabel || 'Todos', 175, 45);

            doc.setFont('helvetica', 'bold');
            doc.text(`Página ${index + 1} de ${groups.length}`, doc.internal.pageSize.getWidth() - 40, 45);

            doc.setDrawColor(200, 200, 200);
            doc.setLineWidth(0.5);
            doc.line(20, 56, doc.internal.pageSize.getWidth() - 20, 56);

            // --- TABLE DATA ---
            // Sort classes by Day, then Start time
            const sortedClasses = [...groupData.classes].sort((a, b) => {
                const dayA = dayOrder[a.day] || 99;
                const dayB = dayOrder[b.day] || 99;
                if (dayA !== dayB) return dayA - dayB;
                return (a.start || "").localeCompare(b.start || "");
            });

            const tableData = sortedClasses.map(cls => {
                const studentCount = cls.studentIds ? cls.studentIds.length : (cls.studentCount || 0);
                return [
                    cls.day,
                    `${cls.start} - ${cls.end}`,
                    cls.subjectName,
                    (cls.teacherName && !cls.teacherName.includes('Asignar')) ? cls.teacherName : 'Pendiente',
                    cls.career || 'N/A',
                    cls.shift || 'N/A',
                    cls.room || 'Sin aula',
                    studentCount.toString()
                ];
            });

            doc.autoTable({
                startY: 62,
                head: [['DÍA', 'HORARIO', 'ASIGNATURA', 'DOCENTE', 'CARRERA', 'JORNADA', 'AULA', 'EST.']],
                body: tableData,
                theme: 'striped',
                headStyles: {
                    fillColor: [30, 64, 175], // Match header
                    textColor: [255, 255, 255],
                    fontStyle: 'bold',
                    fontSize: 8,
                    halign: 'center'
                },
                bodyStyles: {
                    fontSize: 7,
                    textColor: [60, 60, 60],
                    valign: 'middle'
                },
                alternateRowStyles: {
                    fillColor: [248, 250, 252] // Slate 50
                },
                columnStyles: {
                    0: { fontStyle: 'bold', cellWidth: 20 },
                    1: { cellWidth: 25 },
                    2: { cellWidth: 60 },
                    3: { cellWidth: 40 },
                    4: { cellWidth: 40 },
                    5: { cellWidth: 25 },
                    6: { cellWidth: 25 },
                    7: { halign: 'center', cellWidth: 15, fontStyle: 'bold' }
                },
                margin: { left: 20, right: 20, bottom: 20 },
                didDrawPage: function (data) {
                    // Footer
                    doc.setFontSize(7);
                    doc.setTextColor(150, 150, 150);
                    doc.text(`Generado automáticamente en el Tablero de Planificación - Sistema de Horarios`, 20, doc.internal.pageSize.getHeight() - 10);
                }
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
