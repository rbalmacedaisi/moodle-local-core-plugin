<?php
require_once(__DIR__ . '/../../../config.php');
global $DB, $PAGE, $OUTPUT, $CFG;

$context = context_system::instance();
require_login();
require_capability('moodle/site:config', $context);

$PAGE->set_url('/local/grupomakro_core/pages/debug_demand_data.php');
$PAGE->set_context($context);
$PAGE->set_title('Debug - Demand Data');
$PAGE->set_heading('Debug: Demand Data Analysis');
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();
?>

<style>
    body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #f8fafc; padding: 20px; }
    .debug-container { max-width: 1400px; margin: 0 auto; }
    h1 { color: #1e293b; border-bottom: 3px solid #3b82f6; padding-bottom: 10px; }
    h2 { color: #334155; margin-top: 30px; border-left: 4px solid #3b82f6; padding-left: 12px; }
    h3 { color: #475569; margin-top: 20px; }
    .card { background: white; border-radius: 12px; padding: 20px; margin: 15px 0; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border: 1px solid #e2e8f0; }
    .card-success { border-left: 5px solid #22c55e; }
    .card-error { border-left: 5px solid #ef4444; }
    .card-warning { border-left: 5px solid #f59e0b; }
    .card-info { border-left: 5px solid #3b82f6; }
    select, button { padding: 10px 16px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 14px; cursor: pointer; }
    select { background: white; min-width: 250px; }
    button { background: #3b82f6; color: white; border: none; font-weight: 600; }
    button:hover { background: #2563eb; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px; }
    .stat-box { background: #f1f5f9; border-radius: 10px; padding: 15px; text-align: center; }
    .stat-number { font-size: 32px; font-weight: bold; color: #1e293b; }
    .stat-label { font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-top: 5px; }
    .stat-success .stat-number { color: #22c55e; }
    .stat-error .stat-number { color: #ef4444; }
    .stat-warning .stat-number { color: #f59e0b; }
    table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 13px; }
    th { background: #f1f5f9; padding: 10px; text-align: left; font-weight: 600; color: #334155; border-bottom: 2px solid #cbd5e1; position: sticky; top: 0; }
    td { padding: 8px 10px; border-bottom: 1px solid #e2e8f0; }
    tr:hover { background: #f8fafc; }
    .badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
    .badge-green { background: #dcfce7; color: #166534; }
    .badge-red { background: #fee2e2; color: #991b1b; }
    .badge-yellow { background: #fef3c7; color: #92400e; }
    .badge-blue { background: #dbeafe; color: #1e40af; }
    .badge-gray { background: #f1f5f9; color: #475569; }
    pre { background: #1e293b; color: #e2e8f0; padding: 15px; border-radius: 8px; overflow-x: auto; font-size: 12px; line-height: 1.5; }
    .section-header { display: flex; align-items: center; gap: 15px; margin: 20px 0 10px; }
    .filter-reason { background: #fef3c7; padding: 8px 12px; border-radius: 6px; margin: 5px 0; font-size: 12px; color: #92400e; }
    .student-row { background: white; padding: 12px; border-radius: 8px; margin: 5px 0; border: 1px solid #e2e8f0; }
    .student-row.filtered { border-left: 4px solid #ef4444; background: #fef2f2; }
    .student-row.passed { border-left: 4px solid #22c55e; background: #f0fdf4; }
    .quick-link { display: inline-block; padding: 8px 16px; background: #3b82f6; color: white; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 500; margin: 5px; }
    .quick-link:hover { background: #2563eb; }
    .summary-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin: 15px 0; }
    .overflow-auto { overflow-x: auto; max-height: 400px; overflow-y: auto; }
</style>

<div class="debug-container">
    <h1>🔍 Debug: Demand Data Analysis</h1>

    <!-- Quick Links -->
    <div class="card">
        <h3>Accesos Rápidos</h3>
        <a href="academic_planning.php" class="quick-link">📋 Academic Planning</a>
        <a href="debug_demand_data.php" class="quick-link">🔄 Debug Demand Data</a>
    </div>

    <!-- Period Selector -->
    <div class="card">
        <h3>Seleccionar Periodo</h3>
        <div style="display: flex; gap: 15px; align-items: center;">
            <select id="periodSelect">
                <option value="">-- Seleccione un periodo --</option>
            </select>
            <button onclick="runDebug()">▶ Ejecutar Análisis</button>
        </div>
    </div>

    <!-- Results Container -->
    <div id="results" style="display: none;">
        <!-- Summary Stats -->
        <div class="summary-grid" id="summaryStats"></div>

        <!-- Step by Step Analysis -->
        <div class="card card-info">
            <h2>📊 Paso 1: Estudiantes Totales</h2>
            <div id="step1"></div>
        </div>

        <div class="card card-warning">
            <h2>📊 Paso 2: Pre-carga de Ignorados y Deferrals</h2>
            <div id="step2"></div>
        </div>

        <div class="card card-error">
            <h2>📊 Paso 3: Construcción del Árbol (con filtros)</h2>
            <div id="step3a"></div>
            <h3>❌ Estudiantes NO agregados (filtrados)</h3>
            <div id="step3b"></div>
            <h3>✅ Estudiantes AGREGADOS al árbol</h3>
            <div id="step3c"></div>
        </div>

        <div class="card card-success">
            <h2>📊 Paso 4: Student Count por Bucket</h2>
            <div id="step4"></div>
        </div>

        <div class="card">
            <h2>🌳 Demand Tree Final</h2>
            <pre id="finalTree"></pre>
        </div>
    </div>
</div>

<script>
const { createApp } = Vue;

createApp({
    mounted() {
        this.loadPeriods();
    },
    methods: {
        async loadPeriods() {
            try {
                const response = await fetch('<?php echo $CFG->wwwroot; ?>/local/grupomakro_core/ajax.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'local_grupomakro_get_academic_periods',
                        sesskey: M.cfg.sesskey
                    })
                });
                const json = await response.json();
                const periods = json.data || json.periods || [];
                const select = document.getElementById('periodSelect');
                periods.forEach(p => {
                    const opt = document.createElement('option');
                    opt.value = p.id;
                    opt.textContent = p.name;
                    select.appendChild(opt);
                });
            } catch (e) {
                console.error('Error loading periods:', e);
            }
        },

        async runDebug() {
            const periodId = document.getElementById('periodSelect').value;
            if (!periodId) {
                alert('Por favor seleccione un periodo');
                return;
            }

            document.getElementById('results').style.display = 'block';
            this.showLoading();

            try {
                const response = await fetch('<?php echo $CFG->wwwroot; ?>/local/grupomakro_core/ajax.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'local_grupomakro_debug_demand_data',
                        sesskey: M.cfg.sesskey,
                        periodid: periodId
                    })
                });
                const json = await response.json();

                if (json.error) {
                    alert('Error: ' + json.message);
                    return;
                }

                this.renderResults(json.data);
            } catch (e) {
                console.error('Error:', e);
                alert('Error: ' + e.message);
            }
        },

        showLoading() {
            document.getElementById('results').innerHTML = '<div class="card"><p>Cargando...</p></div>';
        },

        renderResults(data) {
            // Summary
            const summaryHTML = `
                <div class="stat-box">
                    <div class="stat-number">${data.total_students || 0}</div>
                    <div class="stat-label">Estudiantes Totales</div>
                </div>
                <div class="stat-box stat-success">
                    <div class="stat-number">${data.students_with_priority?.length || 0}</div>
                    <div class="stat-label">Agregados al Árbol</div>
                </div>
                <div class="stat-box stat-error">
                    <div class="stat-number">${data.students_no_priority?.length || 0}</div>
                    <div class="stat-label">Filtrados (sin priority)</div>
                </div>
                <div class="stat-box stat-warning">
                    <div class="stat-number">${data.ignored_count || 0}</div>
                    <div class="stat-label">Asignaturas Ignoradas</div>
                </div>
            `;
            document.getElementById('summaryStats').innerHTML = summaryHTML;

            // Step 1: Students
            let step1HTML = `<p><strong>Total:</strong> ${data.students?.length || 0} estudiantes</p>`;
            step1HTML += '<div class="overflow-auto"><table><tr><th>#</th><th>Nombre</th><th>dbId</th><th>Carrera</th><th>Shift</th><th>Nivel (currentSemConfig)</th><th>Subperiod (currentSubperiodConfig)</th><th>entry_period</th><th>pendingSubjects</th></tr>';
            (data.students || []).slice(0, 50).forEach((s, i) => {
                step1HTML += `<tr>
                    <td>${i + 1}</td>
                    <td>${s.name}</td>
                    <td><span class="badge badge-blue">${s.dbId}</span></td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;">${s.career?.substring(0, 30)}...</td>
                    <td>${s.shift}</td>
                    <td><span class="badge badge-gray">${s.currentSemConfig}</span></td>
                    <td><span class="badge badge-gray">${s.currentSubperiodConfig}</span></td>
                    <td><span class="badge badge-blue">${s.entry_period}</span></td>
                    <td>${s.pendingSubjects?.length || 0}</td>
                </tr>`;
            });
            step1HTML += '</table></div>';
            if (data.students?.length > 50) {
                step1HTML += `<p style="color:#64748b;margin-top:10px;">Mostrando primeros 50 de ${data.students.length}</p>`;
            }
            document.getElementById('step1').innerHTML = step1HTML;

            // Step 2: Ignored and Deferrals
            let step2HTML = `<p><strong>Asignaturas Ignoradas (status=2):</strong> ${data.ignored_count || 0}</p>`;
            step2HTML += '<div style="display:flex;flex-wrap:wrap;gap:5px;margin:10px 0;">';
            (data.ignored_map || []).forEach(id => {
                const subject = data.subjects_map?.[id];
                step2HTML += `<span class="badge badge-red">${subject || id}</span>`;
            });
            step2HTML += '</div>';
            step2HTML += `<p style="margin-top:15px;"><strong>Deferrals cargados:</strong> ${data.deferrals_count || 0}</p>`;
            step2HTML += '<div class="overflow-auto" style="max-height:200px;">';
            Object.entries(data.deferrals_by_course || {}).slice(0, 20).forEach(([courseId, cohorts]) => {
                const subject = data.subjects_map?.[courseId] || courseId;
                Object.entries(cohorts).forEach(([cohortKey, targetIdx]) => {
                    step2HTML += `<div style="margin:3px 0;font-size:12px;background:#fef3c7;padding:4px 8px;border-radius:4px;">
                        <strong>${subject}</strong> → ${cohortKey} = P-${targetIdx}
                    </div>`;
                });
            });
            step2HTML += '</div>';
            document.getElementById('step2').innerHTML = step2HTML;

            // Step 3a: Priority calculation explanation
            let step3aHTML = `<p><strong>Explicación del cálculo de isPriority:</strong></p>`;
            step3aHTML += `<pre style="background:#f8fafc;border:1px solid #e2e8f0;padding:10px;margin:10px 0;">
isPriority = (isPreRequisiteMet && course.semester <= targetLevel)

Donde:
- isPreRequisiteMet = true si el estudiante ha aprobado o está cursando los prerrequisitos
- targetLevel = (currentSemConfig es Bimestre 2) ? nivel + 1 : nivel

Ejemplos del cohortKey para estudiantes procesados:
`;
            (data.students_with_priority || []).slice(0, 3).forEach(s => {
                step3aHTML += `  - ${s.name}: cohortKey = "${s.cohortKey}"\n`;
            });
            (data.students_no_priority || []).slice(0, 3).forEach(s => {
                step3aHTML += `  - ${s.name}: cohortKey = "${s.cohortKey}" | isPriority=${s.firstPending?.isPriority} | level=${s.levelLabel} | sub=${s.subLabel}\n`;
            });
            step3aHTML += '</pre>';
            document.getElementById('step3a').innerHTML = step3aHTML;

            // Step 3b: Filtered students
            let step3bHTML = `<p><strong>Razones por las que fueron filtrados:</strong></p>`;
            const reasonsCount = {};
            (data.students_no_priority || []).forEach(s => {
                const reason = s.filterReason || 'unknown';
                reasonsCount[reason] = (reasonsCount[reason] || 0) + 1;
            });
            Object.entries(reasonsCount).forEach(([reason, count]) => {
                const badgeClass = reason === 'no_priority_match' ? 'badge-red' : 'badge-yellow';
                step3bHTML += `<span class="badge ${badgeClass}">${reason}: ${count}</span> `;
            });

            step3bHTML += '<div class="overflow-auto" style="max-height:400px;">';
            (data.students_no_priority || []).slice(0, 100).forEach((s, i) => {
                const reason = s.filterReason || 'unknown';
                step3bHTML += `<div class="student-row filtered">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <div>
                            <strong>${s.name}</strong> <span class="badge badge-blue">${s.dbId}</span>
                            <span style="margin-left:10px;color:#64748b;">${s.career?.substring(0, 40)}...</span>
                        </div>
                        <span class="badge badge-red">FILTRADO: ${reason}</span>
                    </div>
                    <div style="margin-top:8px;font-size:12px;color:#475569;">
                        <div><strong>levelKey:</strong> "${s.levelKey}" | <strong>cohortKey:</strong> "${s.cohortKey}"</div>
                        <div><strong>firstPending:</strong> "${s.firstPending?.name}" | isPriority=${s.firstPending?.isPriority} | isPreRequisiteMet=${s.firstPending?.isPreRequisiteMet}</div>
                    </div>
                </div>`;
            });
            step3bHTML += '</div>';
            if (data.students_no_priority?.length > 100) {
                step3bHTML += `<p style="color:#64748b;margin-top:10px;">Mostrando primeros 100 de ${data.students_no_priority.length}</p>`;
            }
            document.getElementById('step3b').innerHTML = step3bHTML;

            // Step 3c: Students added to tree
            let step3cHTML = `<p><strong>${data.students_with_priority?.length || 0} estudiantes fueron agregados al árbol:</strong></p>`;
            step3cHTML += '<div class="overflow-auto" style="max-height:400px;">';
            (data.students_with_priority || []).slice(0, 100).forEach((s, i) => {
                step3cHTML += `<div class="student-row passed">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <div>
                            <strong>${s.name}</strong> <span class="badge badge-blue">${s.dbId}</span>
                            <span style="margin-left:10px;color:#64748b;">${s.career?.substring(0, 40)}...</span>
                        </div>
                        <span class="badge badge-green">AGREGADO</span>
                    </div>
                    <div style="margin-top:8px;font-size:12px;color:#475569;">
                        <div><strong>levelKey:</strong> "${s.levelKey}"</div>
                        <div><strong>pendingSubjects:</strong> ${s.pendingCount} | isPriority=${s.firstPending?.isPriority}</div>
                    </div>
                </div>`;
            });
            step3cHTML += '</div>';
            document.getElementById('step3c').innerHTML = step3cHTML;

            // Step 4: Bucket counts
            let step4HTML = `<p><strong> student_count por bucket (Career → Shift → Level):</strong></p>`;
            step4HTML += '<div class="overflow-auto"><table><tr><th>Career</th><th>Shift</th><th>Level</th><th>student_count</th><th>course_counts</th></tr>';
            Object.entries(data.demand_tree || {}).forEach(([career, shifts]) => {
                Object.entries(shifts).forEach(([shift, levels]) => {
                    Object.entries(levels).forEach(([levelKey, bucket]) => {
                        const courseCount = Object.keys(bucket.course_counts || {}).length;
                        step4HTML += `<tr>
                            <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;">${career.substring(0, 30)}...</td>
                            <td>${shift}</td>
                            <td><span class="badge badge-blue">${levelKey}</span></td>
                            <td><span class="badge badge-green">${bucket.student_count || 0}</span></td>
                            <td>${courseCount} cursos</td>
                        </tr>`;
                    });
                });
            });
            step4HTML += '</table></div>';
            document.getElementById('step4').innerHTML = step4HTML;

            // Final tree
            document.getElementById('finalTree').textContent = JSON.stringify(data.demand_tree, null, 2);
        }
    }
}).mount('.debug-container');

// Make runDebug available globally
window.runDebug = () => {
    const app = document.querySelector('.debug-container').__vue_app__;
    if (app && app._instance.proxy.runDebug) {
        app._instance.proxy.runDebug();
    }
};
</script>

<?php
echo $OUTPUT->footer();
