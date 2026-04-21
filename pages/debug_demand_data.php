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

<!-- Vue 3 -->
<script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>

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
    th { background: #f1f5f9; padding: 10px; text-align: left; font-weight: 600; color: #334155; border-bottom: 2px solid #cbd5e1; position: sticky; top: 0; z-index: 10; }
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
    .loading { text-align: center; padding: 40px; color: #64748b; }
    .loading-spinner { border: 3px solid #e2e8f0; border-top: 3px solid #3b82f6; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto 15px; }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
    .tab-btn { padding: 8px 16px; background: #e2e8f0; border: none; border-radius: 6px; cursor: pointer; margin-right: 5px; }
    .tab-btn.active { background: #3b82f6; color: white; }
    .tab-content { display: none; }
    .tab-content.active { display: block; }
</style>

<div class="debug-container" id="app">
    <h1> Debug: Demand Data Analysis</h1>

    <!-- Quick Links -->
    <div class="card">
        <h3>Accesos Rápidos</h3>
        <a href="../pages/academic_planning.php" class="quick-link">📋 Academic Planning</a>
        <a href="debug_demand_data.php" class="quick-link">🔄 Debug Demand Data</a>
    </div>

    <!-- Period Selector -->
    <div class="card">
        <h3>Seleccionar Periodo</h3>
        <div style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
            <select id="periodSelect">
                <option value="">-- Seleccione un periodo --</option>
            </select>
            <button onclick="runDebug()">▶ Ejecutar Análisis</button>
        </div>
    </div>

    <!-- Loading -->
    <div id="loading" class="card" style="display:none;">
        <div class="loading">
            <div class="loading-spinner"></div>
            <p>Procesando datos...</p>
        </div>
    </div>

    <!-- Results -->
    <div id="results" style="display:none;">
        <!-- Summary Stats -->
        <div class="summary-grid" id="summaryStats"></div>

        <!-- Tabs -->
        <div class="card">
            <div style="margin-bottom:15px;">
                <button class="tab-btn active" onclick="showTab('step1')">Paso 1: Estudiantes</button>
                <button class="tab-btn" onclick="showTab('step2')">Paso 2: Ignorados/Deferrals</button>
                <button class="tab-btn" onclick="showTab('step3')">Paso 3: Análisis Filtros</button>
                <button class="tab-btn" onclick="showTab('step4')">Paso 4: Buckets</button>
                <button class="tab-btn" onclick="showTab('tree')"> Árbol Final</button>
            </div>

            <!-- Tab: Step 1 -->
            <div id="tab-step1" class="tab-content active">
                <h2>📊 Paso 1: Estudiantes Totales</h2>
                <p>Total: <strong id="totalStudentsCount">0</strong></p>
                <div id="step1" class="overflow-auto" style="max-height:500px;"></div>
            </div>

            <!-- Tab: Step 2 -->
            <div id="tab-step2" class="tab-content">
                <h2>📊 Paso 2: Ignorados y Deferrals</h2>
                <div id="step2"></div>
            </div>

            <!-- Tab: Step 3 -->
            <div id="tab-step3" class="tab-content">
                <h2>📊 Paso 3: Análisis de Filtros</h2>
                <div id="step3a"></div>
                <h3 style="color:#ef4444;">❌ Filtrados (no agregados al árbol)</h3>
                <div id="step3b" class="overflow-auto" style="max-height:500px;"></div>
                <h3 style="color:#22c55e;">✅ Agregados al árbol</h3>
                <div id="step3c" class="overflow-auto" style="max-height:500px;"></div>
            </div>

            <!-- Tab: Step 4 -->
            <div id="tab-step4" class="tab-content">
                <h2>📊 Paso 4: Student Count por Bucket</h2>
                <div id="step4" class="overflow-auto" style="max-height:500px;"></div>
            </div>

            <!-- Tab: Tree -->
            <div id="tab-tree" class="tab-content">
                <h2>🌳 Demand Tree Final</h2>
                <pre id="finalTree" style="max-height:600px;"></pre>
            </div>
        </div>
    </div>
</div>

<script>
const sesskey = '<?php echo sesskey(); ?>';
const wwwroot = '<?php echo $CFG->wwwroot; ?>';

async function loadPeriods() {
    try {
        const response = await fetch(wwwroot + '/local/grupomakro_core/ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'local_grupomakro_get_academic_periods', sesskey })
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
        alert('Error cargando periodos: ' + e.message);
    }
}

function showTab(tabName) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    document.getElementById('tab-' + tabName).classList.add('active');
    event.target.classList.add('active');
}

async function runDebug() {
    const periodId = document.getElementById('periodSelect').value;
    if (!periodId) {
        alert('Por favor seleccione un periodo');
        return;
    }

    document.getElementById('loading').style.display = 'block';
    document.getElementById('results').style.display = 'none';

    try {
        const response = await fetch(wwwroot + '/local/grupomakro_core/ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'local_grupomakro_debug_demand_data',
                sesskey,
                periodid: periodId
            })
        });
        const json = await response.json();

        if (json.error) {
            alert('Error: ' + json.message);
            return;
        }

        renderResults(json.data);
        document.getElementById('loading').style.display = 'none';
        document.getElementById('results').style.display = 'block';
    } catch (e) {
        console.error('Error:', e);
        alert('Error: ' + e.message);
        document.getElementById('loading').style.display = 'none';
    }
}

function renderResults(data) {
    // Summary
    document.getElementById('summaryStats').innerHTML = `
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
            <div class="stat-label">Filtrados</div>
        </div>
        <div class="stat-box stat-warning">
            <div class="stat-number">${data.ignored_count || 0}</div>
            <div class="stat-label">Asignaturas Ignoradas</div>
        </div>
    `;

    // Step 1: Students
    document.getElementById('totalStudentsCount').textContent = data.total_students || 0;
    let s1 = '<table><tr><th>#</th><th>Nombre</th><th>dbId</th><th>Carrera</th><th>Shift</th><th>Nivel</th><th>Subperiod</th><th>Entry</th><th>Pending</th></tr>';
    (data.students || []).forEach((s, i) => {
        s1 += `<tr>
            <td>${i + 1}</td>
            <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;">${s.name}</td>
            <td><span class="badge badge-blue">${s.dbId}</span></td>
            <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;">${(s.career || '').substring(0, 25)}...</td>
            <td>${s.shift}</td>
            <td><span class="badge badge-gray">${s.currentSemConfig}</span></td>
            <td><span class="badge badge-gray">${s.currentSubperiodConfig}</span></td>
            <td><span class="badge badge-blue">${s.entry_period}</span></td>
            <td>${s.pendingSubjects?.length || 0}</td>
        </tr>`;
    });
    s1 += '</table>';
    document.getElementById('step1').innerHTML = s1;

    // Step 2: Ignored and Deferrals
    let s2 = `<p><strong>Ignorados (status=2):</strong> ${data.ignored_count || 0}</p>`;
    s2 += '<div style="display:flex;flex-wrap:wrap;gap:5px;margin:10px 0;">';
    (data.ignored_map || []).forEach(id => {
        const subject = data.subjects_map?.[id] || id;
        s2 += `<span class="badge badge-red" title="${id}">${subject}</span>`;
    });
    s2 += '</div>';
    s2 += `<p style="margin-top:15px;"><strong>Deferrals cargados:</strong> ${data.deferrals_count || 0}</p>`;
    s2 += '<div class="overflow-auto" style="max-height:200px;">';
    Object.entries(data.deferrals_by_course || {}).slice(0, 30).forEach(([courseId, cohorts]) => {
        const subject = data.subjects_map?.[courseId] || courseId;
        Object.entries(cohorts).forEach(([cohortKey, targetIdx]) => {
            s2 += `<div style="margin:3px 0;font-size:12px;background:#fef3c7;padding:4px 8px;border-radius:4px;">
                <strong>${subject}</strong> → ${cohortKey} = P-${targetIdx}
            </div>`;
        });
    });
    s2 += '</div>';
    document.getElementById('step2').innerHTML = s2;

    // Step 3a: Filter explanation
    let s3a = `<p><strong>Explicación del cálculo de isPriority:</strong></p>`;
    s3a += `<pre style="background:#f8fafc;border:1px solid #e2e8f0;padding:10px;margin:10px 0;">
isPriority = (isPreRequisiteMet && course.semester <= targetLevel)

Donde:
- isPreRequisiteMet = true si el estudiante ha aprobado o está cursando los prerrequisitos
- targetLevel = (currentSemConfig es Bimestre 2) ? nivel + 1 : nivel
</pre>`;
    document.getElementById('step3a').innerHTML = s3a;

    // Step 3b: Filtered students
    let reasonsCount = {};
    (data.students_no_priority || []).forEach(s => {
        const reason = s.filterReason || 'unknown';
        reasonsCount[reason] = (reasonsCount[reason] || 0) + 1;
    });
    let s3b = `<p><strong>Razones de filtrado:</strong></p><div style="display:flex;flex-wrap:wrap;gap:5px;margin-bottom:15px;">`;
    Object.entries(reasonsCount).forEach(([reason, count]) => {
        const badgeClass = reason === 'no_priority_match' ? 'badge-red' : 'badge-yellow';
        s3b += `<span class="badge ${badgeClass}">${reason}: ${count}</span>`;
    });
    s3b += '</div>';

    s3b += '<div>';
    (data.students_no_priority || []).slice(0, 150).forEach((s, i) => {
        s3b += `<div class="student-row filtered">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:5px;">
                <div>
                    <strong>${s.name}</strong> <span class="badge badge-blue">${s.dbId}</span>
                    <span style="margin-left:10px;color:#64748b;font-size:12px;">${(s.career || '').substring(0, 35)}...</span>
                </div>
                <span class="badge badge-red">FILTRADO: ${s.filterReason}</span>
            </div>
            <div style="margin-top:8px;font-size:12px;color:#475569;">
                <div><strong>levelKey:</strong> "${s.levelKey}" | <strong>cohortKey:</strong> "${(s.cohortKey || '').substring(0, 60)}..."</div>
                <div><strong>pending:</strong> "${s.firstPending?.name}" (sem=${s.firstPending?.semester}) | isPriority=${s.firstPending?.isPriority} | isPreRequisiteMet=${s.firstPending?.isPreRequisiteMet}</div>
            </div>
        </div>`;
    });
    s3b += '</div>';
    if ((data.students_no_priority || []).length > 150) {
        s3b += `<p style="color:#64748b;margin-top:10px;">Mostrando primeros 150 de ${data.students_no_priority.length}</p>`;
    }
    document.getElementById('step3b').innerHTML = s3b;

    // Step 3c: Passed students
    let s3c = `<p><strong>${data.students_with_priority?.length || 0} estudiantes fueron agregados al árbol:</strong></p>`;
    s3c += '<div>';
    (data.students_with_priority || []).slice(0, 150).forEach((s, i) => {
        s3c += `<div class="student-row passed">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:5px;">
                <div>
                    <strong>${s.name}</strong> <span class="badge badge-blue">${s.dbId}</span>
                    <span style="margin-left:10px;color:#64748b;font-size:12px;">${(s.career || '').substring(0, 35)}...</span>
                </div>
                <span class="badge badge-green">AGREGADO</span>
            </div>
            <div style="margin-top:8px;font-size:12px;color:#475569;">
                <div><strong>levelKey:</strong> "${s.levelKey}"</div>
                <div><strong>pending:</strong> ${s.pendingCount} materias | isPriority=${s.firstPending?.isPriority}</div>
            </div>
        </div>`;
    });
    s3c += '</div>';
    document.getElementById('step3c').innerHTML = s3c;

    // Step 4: Buckets
    let s4 = '<table><tr><th>Career</th><th>Shift</th><th>Level</th><th>Students</th><th>Courses</th></tr>';
    Object.entries(data.demand_tree || {}).forEach(([career, shifts]) => {
        Object.entries(shifts).forEach(([shift, levels]) => {
            Object.entries(levels).forEach(([levelKey, bucket]) => {
                const courseCount = Object.keys(bucket.course_counts || {}).length;
                s4 += `<tr>
                    <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;">${career.substring(0, 25)}...</td>
                    <td>${shift}</td>
                    <td><span class="badge badge-blue">${levelKey}</span></td>
                    <td><span class="badge badge-green">${bucket.student_count || 0}</span></td>
                    <td>${courseCount}</td>
                </tr>`;
            });
        });
    });
    s4 += '</table>';
    document.getElementById('step4').innerHTML = s4;

    // Final tree
    document.getElementById('finalTree').textContent = JSON.stringify(data.demand_tree, null, 2);
}

// Load periods on page load
loadPeriods();
</script>

<?php
echo $OUTPUT->footer();
