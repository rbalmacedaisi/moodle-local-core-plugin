<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/vendor/autoload.php');

// Permissions
admin_externalpage_setup('grupomakro_core_manage_courses');
if (!is_siteadmin()) {
    print_error('onlyadmins', 'error');
}

$PAGE->set_url('/local/grupomakro_core/pages/manage_courses.php');
$PAGE->set_title('Gestión de Cursos Moderna');
$PAGE->set_heading('Gestor Avanzado de Cursos');
$PAGE->requires->jquery();
$PAGE->requires->js_call_amd('core/modal_factory', 'create');
$PAGE->requires->js_call_amd('core/modal_events', 'types');

// Params
$filter_search = optional_param('search', '', PARAM_TEXT);
$filter_category = optional_param('category', 0, PARAM_INT);
$filter_visible = optional_param('visible', -1, PARAM_INT); // -1 all, 1 yes, 0 no
$filter_plan = optional_param('plan', 0, PARAM_INT);
$filter_schedule_status = optional_param('schedulestatus', -1, PARAM_INT); // -1 All, 1 Active, 0 Inactive
$action = optional_param('action', '', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 20;

// Set Params to URL for Persistence in Pagination
if ($filter_search !== '') $PAGE->url->param('search', $filter_search);
if ($filter_category > 0) $PAGE->url->param('category', $filter_category);
if ($filter_visible !== -1) $PAGE->url->param('visible', $filter_visible);
if ($filter_plan > 0) $PAGE->url->param('plan', $filter_plan);
if ($filter_schedule_status !== -1) $PAGE->url->param('schedulestatus', $filter_schedule_status);

// Context for SQL
$context = context_system::instance();

// Base SQL Construction
$where = ["c.id <> 1"]; // Exclude site course
$params = [];

if (!empty($filter_search)) {
    $where[] = "( " . $DB->sql_like('c.fullname', ':search1', false) . " OR " . $DB->sql_like('c.shortname', ':search2', false) . " OR " . $DB->sql_like('c.idnumber', ':search3', false) . " )";
    $params['search1'] = "%$filter_search%";
    $params['search2'] = "%$filter_search%";
    $params['search3'] = "%$filter_search%";
}

if ($filter_category > 0) {
    $where[] = "c.category = :cat";
    $params['cat'] = $filter_category;
}

if ($filter_visible !== -1) {
    $where[] = "c.visible = :vis";
    $params['vis'] = $filter_visible;
}

// Filter by Learning Plan
if ($filter_plan > 0) {
    // We need to join local_learning_courses
    // Since we are building the main query later, we can check existence here
    // or add a subquery condition.
    $where[] = "EXISTS (SELECT 1 FROM {local_learning_courses} llc WHERE llc.courseid = c.id AND llc.learningplanid = :planid)";
    $params['planid'] = $filter_plan;
}

// Filter by Schedule Status
if ($filter_schedule_status !== -1) {
    if ($filter_schedule_status == 1) {
        // Has active schedules (closed = 0)
        $where[] = "EXISTS (SELECT 1 FROM {gmk_class} gc WHERE gc.courseid = c.id AND gc.closed = 0)";
    } else {
        // No active schedules
        $where[] = "NOT EXISTS (SELECT 1 FROM {gmk_class} gc WHERE gc.courseid = c.id AND gc.closed = 0)";
    }
}

$whereSQL = implode(" AND ", $where);

// Columns for SQL
// 1. Schedules Count
// 2. Students Count
// 3. Learning Plans (Concatenated names) - Using specific SQL for compatibility or just fetching later to avoid complex group_concat limitations across DBs
// 4. Active Schedules names - Similar approach.

// Let's stick to basic counts in the main query and maybe fetching details for the viewed page only to keep it clean, 
// OR use subqueries for the list names if supported. Moodle $DB abstracting makes GROUP_CONCAT tricky sometimes.
// Strategy: Fetch the Page courses first, then fetch their specific metadata in a separate targeted query or loop (loop is fine for 20 items).

$sql_cols = "c.id, c.fullname, c.shortname, c.idnumber, c.category, c.visible, c.startdate, c.enddate, 
            (SELECT COUNT(gc.id) FROM {gmk_class} gc WHERE gc.courseid = c.id) as total_schedules_count,
            (SELECT COUNT(gc.id) FROM {gmk_class} gc WHERE gc.courseid = c.id AND gc.closed = 0) as active_schedules_count,
            (SELECT COUNT(DISTINCT ra.userid) FROM {role_assignments} ra 
             JOIN {context} ctx ON ctx.id = ra.contextid 
             WHERE ctx.instanceid = c.id AND ctx.contextlevel = 50 AND ra.roleid = 5) as student_count"; 

// EXPORT TO XLSX
if ($action === 'export') {
    $filename = 'reporte_cursos_' . date('Ymd_His') . '.xlsx';
    
    // Create Spreadsheet
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Cursos');
    
    // Headers
    $headers = ['ID', 'Shortname', 'Fullname', 'Category ID', 'Total Schedules', 'Active Schedules', 'Students', 'Visible', 'Start Date'];
    $sheet->fromArray($headers, NULL, 'A1');
    
    // Fetch ALL records
    $export_courses = $DB->get_records_sql("SELECT $sql_cols FROM {course} c WHERE $whereSQL ORDER BY c.fullname ASC", $params);
    
    $row = 2;
    foreach ($export_courses as $c) {
        $sheet->setCellValue('A' . $row, $c->id);
        $sheet->setCellValue('B' . $row, $c->shortname);
        $sheet->setCellValue('C' . $row, $c->fullname);
        $sheet->setCellValue('D' . $row, $c->category);
        $sheet->setCellValue('E' . $row, $c->total_schedules_count);
        $sheet->setCellValue('F' . $row, $c->active_schedules_count);
        $sheet->setCellValue('G' . $row, $c->student_count);
        $sheet->setCellValue('H' . $row, $c->visible ? 'Yes' : 'No');
        $sheet->setCellValue('I' . $row, userdate($c->startdate, '%Y-%m-%d'));
        $row++;
    }
    
    // Auto-size columns
    foreach(range('A','I') as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    // Output headers for download
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment;filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    die;
}

echo $OUTPUT->header();

// Styles & Assets (Material Design lookalike)
echo '
<link href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<!-- Bootstrap Modals CSS (if not fully loaded by theme) -->
<style>
    body { font-family: "Roboto", sans-serif; }
    .card-material {
        background: #fff;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        border: none;
        margin-bottom: 20px;
        transition: transform 0.2s;
    }
    .card-material:hover { box-shadow: 0 4px 8px rgba(0,0,0,0.15); }
    .card-header-material {
        background: transparent;
        border-bottom: 1px solid #eee;
        padding: 15px 20px;
        font-weight: 500;
        color: #333;
        font-size: 1.1rem;
    }
    .btn-material {
        border-radius: 20px;
        text-transform: uppercase;
        font-weight: 500;
        font-size: 0.85rem;
        letter-spacing: 0.5px;
        padding: 8px 20px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.2);
        border: none;
    }
    .btn-material-primary { background: #1976D2; color: #fff; }
    .btn-material-primary:hover { background: #1565C0; color: #fff; text-decoration: none;}
    .stats-card { text-align: center; padding: 20px; }
    .stats-number { font-size: 2rem; font-weight: 700; color: #1976D2; }
    .stats-label { color: #666; font-size: 0.9rem; text-transform: uppercase; }
    .table-material th { border-top: none; color: #666; font-weight: 500; }
    .badge-material { padding: 5px 10px; border-radius: 4px; font-weight: 500; font-size: 0.8rem; }
    .badge-visible { background: #E8F5E9; color: #2E7D32; }
    .badge-hidden { background: #FFEBEE; color: #C62828; }
    .action-icon { font-size: 1.2rem; color: #555; margin: 0 5px; transition: color 0.2s; }
    .action-icon:hover { color: #1976D2; text-decoration: none; }
    .action-icon.delete:hover { color: #C62828; }
    /* Modal Styles override */
    .modal-header { background: #f5f5f5; border-bottom: 1px solid #ddd; }
    .modal-title { font-weight: 500; }
</style>
';

// Stats logic (Cached / Fast)
$total_courses = $DB->count_records_select('course', 'id <> 1');
$visible_courses = $DB->count_records_select('course', 'visible = 1 AND id <> 1');
$hidden_courses = $total_courses - $visible_courses;

// Output Dashboard
echo '<div class="row mb-4">';
echo ' <div class="col-md-4"><div class="card-material stats-card"><div class="stats-number">'.$total_courses.'</div><div class="stats-label">Total Cursos</div></div></div>';
echo ' <div class="col-md-4"><div class="card-material stats-card"><div class="stats-number">'.$visible_courses.'</div><div class="stats-label">Visibles</div></div></div>';
echo ' <div class="col-md-4"><div class="card-material stats-card"><div class="stats-number">'.$hidden_courses.'</div><div class="stats-label" style="color: #C62828;">Ocultos</div></div></div>';
echo '</div>';

// Main Content
echo '<div class="card-material">';
echo '<div class="card-header-material d-flex justify-content-between align-items-center">';
echo '  <span><i class="mdi mdi-school"></i> Listado de Cursos</span>';
echo '  <a href="'.$CFG->wwwroot.'/course/edit.php?category=0" class="btn btn-material btn-material-primary"><i class="mdi mdi-plus"></i> Crear Nuevo Curso</a>';
echo '</div>';
echo '<div class="card-body">';

// Filters
echo '<form method="get" class="row mb-4 bg-light p-3 rounded mx-1">';
echo ' <div class="col-md-3 mb-2">';
echo '   <div class="input-group">';
echo '     <div class="input-group-prepend"><span class="input-group-text border-0 bg-white"><i class="mdi mdi-magnify"></i></span></div>';
echo '     <input type="text" name="search" class="form-control border-0" placeholder="Buscar..." value="'.s($filter_search).'">';
echo '   </div>';
echo ' </div>';

// Category Filter
echo ' <div class="col-md-2 mb-2">';
echo '   <select name="category" class="form-control border-0"><option value="0">-- Categorías --</option>';
$cats = \core_course_category::make_categories_list();
foreach($cats as $id => $name){ 
    $sel = ($filter_category == $id) ? 'selected' : '';
    echo "<option value='$id' $sel>$name</option>"; 
}
echo '   </select>';
echo ' </div>';

// Learning Plan Filter
echo ' <div class="col-md-2 mb-2">';
echo '   <select name="plan" class="form-control border-0"><option value="0">-- Plan Aprendizaje --</option>';
$plans = $DB->get_records_menu('local_learning_plans', null, 'name ASC', 'id, name');
foreach($plans as $id => $name) {
    $sel = ($filter_plan == $id) ? 'selected' : '';
    echo "<option value='$id' $sel>$name</option>"; 
}
echo '   </select>';
echo ' </div>';

// Schedule Status Filter
echo ' <div class="col-md-2 mb-2">';
echo '   <select name="schedulestatus" class="form-control border-0">';
echo '     <option value="-1" '.($filter_schedule_status==-1?'selected':'').'>-- Estado Horarios --</option>';
echo '     <option value="1" '.($filter_schedule_status==1?'selected':'').'>Con Horarios Activos</option>';
echo '     <option value="0" '.($filter_schedule_status==0?'selected':'').'>Sin Horarios Activos</option>';
echo '   </select>';
echo ' </div>';


// Visible Filter
// echo ' <div class="col-md-2 mb-2">';
// echo '   <select name="visible" class="form-control border-0">';
// echo '     <option value="-1" '.($filter_visible==-1?'selected':'').'>Todos Estados</option>';
// echo '     <option value="1" '.($filter_visible==1?'selected':'').'>Visibles</option>';
// echo '     <option value="0" '.($filter_visible==0?'selected':'').'>Ocultos</option>';
// echo '   </select>';
// echo ' </div>';

echo ' <div class="col-md-3 mb-2 d-flex justify-content-end">';
echo '   <button type="submit" class="btn btn-secondary mr-2">Filtrar</button>';
echo '   <button type="submit" name="action" value="export" class="btn btn-success"><i class="mdi mdi-file-excel"></i> Excel</button>';
echo ' </div>';
echo '</form>';

// Results
$total_matching = $DB->count_records_sql("SELECT COUNT(*) FROM {course} c WHERE $whereSQL", $params);
$courses = $DB->get_records_sql("SELECT $sql_cols FROM {course} c WHERE $whereSQL ORDER BY c.fullname ASC", $params, $page * $perpage, $perpage);

// Fetch Extra Data for specific visible courses
if ($courses) {
    $courseContexts = [];
    foreach ($courses as $c) {
        // Fetch Plans
        $c->plans = $DB->get_records_sql("
            SELECT lp.id, lp.name 
            FROM {local_learning_plans} lp
            JOIN {local_learning_courses} llc ON llc.learningplanid = lp.id
            WHERE llc.courseid = ?", [$c->id]);
        
        // Fetch Active Schedules (closed=0)
        $c->active_schedules = $DB->get_records_sql("
            SELECT gc.id, gc.name, gc.inithourformatted, gc.endhourformatted, gc.classdays
            FROM {gmk_class} gc
            WHERE gc.courseid = ? AND gc.closed = 0", [$c->id]);
    }
}


if ($total_matching > 0) {
    echo '<div class="table-responsive">';
    echo '<table class="table table-hover table-material">';
    echo '<thead><tr>
            <th>ID</th>
            <th>Shortname</th>
            <th>Nombre Completo</th>
            <th>Planes</th>
            <th>Horarios Activos</th>
            <th>Estudiantes</th>
            <th>Estado</th>
            <th class="text-right">Acciones</th>
          </tr></thead>';
    echo '<tbody>';
    
    foreach ($courses as $c) {
        $catName = isset($cats[$c->category]) ? $cats[$c->category] : 'Unknown';
        $statusBadge = $c->visible ? '<span class="badge-material badge-visible">Visible</span>' : '<span class="badge-material badge-hidden">Oculto</span>';
        
        $editUrl = new moodle_url('/course/edit.php', ['id' => $c->id]);
        $viewUrl = new moodle_url('/course/view.php', ['id' => $c->id]);
        $deleteUrl = new moodle_url('/course/delete.php', ['id' => $c->id]);
        
        // Plans Data
        $plansCount = count($c->plans);
        // Ensure strictly valid JSON compatible with HTML attribute
        $plansAttr = htmlspecialchars(json_encode(array_values($c->plans), JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
        $plansBtnClass = $plansCount > 0 ? 'btn-info' : 'btn-light disabled';
        $plansBtn = '<button type="button" class="btn btn-sm '.$plansBtnClass.' view-plans-btn" data-plans="'.$plansAttr.'" data-course="'.s($c->fullname).'">
                        <i class="mdi mdi-book-open-variant"></i> '.$plansCount.'
                     </button>';

        // Schedules Data
        $schedulesCount = count($c->active_schedules);
        $schedulesAttr = htmlspecialchars(json_encode(array_values($c->active_schedules), JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
        $schedulesBtnClass = $schedulesCount > 0 ? 'btn-success' : 'btn-light disabled';
        $schedulesBtn = '<button type="button" class="btn btn-sm '.$schedulesBtnClass.' view-schedules-btn" data-schedules="'.$schedulesAttr.'" data-course="'.s($c->fullname).'">
                            <i class="mdi mdi-calendar-clock"></i> '.$schedulesCount.'
                         </button>';

        echo '<tr>';
        echo '<td>'.$c->id.'</td>';
        echo '<td><strong>'.s($c->shortname).'</strong></td>';
        echo '<td><a href="'.$viewUrl.'" class="text-dark">'.s($c->fullname).'</a><br><small class="text-muted">'.$catName.'</small></td>';
        echo '<td class="text-center">'.$plansBtn.'</td>';
        echo '<td class="text-center">'.$schedulesBtn.'</td>';
        echo '<td class="text-center"><span class="badge badge-light border">'.$c->student_count.'</span></td>';
        echo '<td>'.$statusBadge.'</td>';
        echo '<td class="text-right">';
        echo '  <a href="'.$viewUrl.'" title="Ver" class="action-icon" target="_blank"><i class="mdi mdi-eye"></i></a>';
        echo '  <a href="'.$editUrl.'" title="Editar" class="action-icon"><i class="mdi mdi-pencil"></i></a>';
        echo '  <a href="'.$deleteUrl.'" title="Eliminar" class="action-icon delete"><i class="mdi mdi-delete"></i></a>';
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
    
    echo $OUTPUT->paging_bar($total_matching, $page, $perpage, $PAGE->url);

} else {
    echo '<div class="alert alert-info text-center p-4">No se encontraron cursos con los filtros seleccionados.</div>';
}

echo '</div>'; // card-body
echo '</div>'; // card-material

// MODALS HTML
echo '
<!-- Plans Modal -->
<div class="modal fade" id="plansModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Planes de Aprendizaje</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <h6 id="plansModalCourseName" class="text-muted mb-3"></h6>
        <ul id="plansList" class="list-group">
           <!-- Dynamic content -->
        </ul>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<!-- Schedules Modal -->
<div class="modal fade" id="schedulesModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Horarios Activos</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <h6 id="schedulesModalCourseName" class="text-muted mb-3"></h6>
        <div id="schedulesList" class="list-group">
           <!-- Dynamic content -->
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>
';

// Javascript for Modals
echo '
<script>
require(["jquery"], function($) {
    $(document).ready(function() {
        // Use event delegation for reliability since buttons might be dynamically managed or just for best practice
        $(document).on("click", ".view-plans-btn", function(e) {
            e.preventDefault(); // Prevent default link behavior if any form submission implied
            var btn = $(this);
            var plans = btn.data("plans");
            var course = btn.data("course");
            
            $("#plansModalCourseName").text("Curso: " + course);
            var list = $("#plansList");
            list.empty();
            
            if (plans && plans.length > 0) {
                $.each(plans, function(i, plan) {
                    list.append("<li class=\'list-group-item\'><i class=\'mdi mdi-notebook-outline mr-2\'></i>" + plan.name + "</li>");
                });
            } else {
                list.append("<li class=\'list-group-item text-muted\'>No hay planes asociados.</li>");
            }
            
            $("#plansModal").modal("show");
        });

        $(document).on("click", ".view-schedules-btn", function(e) {
            e.preventDefault();
            var btn = $(this);
            var schedules = btn.data("schedules");
            var course = btn.data("course");
            
            $("#schedulesModalCourseName").text("Curso: " + course);
            var list = $("#schedulesList");
            list.empty();
            
            if (schedules && schedules.length > 0) {
                $.each(schedules, function(i, sch) {
                    list.append("<div class=\'list-group-item list-group-item-action flex-column align-items-start\'>" +
                                "<div class=\'d-flex w-100 justify-content-between\'>" +
                                "<h6 class=\'mb-1\'>" + sch.name + "</h6>" +
                                "</div>" +
                                "<p class=\'mb-1\'>Incio: " + sch.inithourformatted + " - Fin: " + sch.endhourformatted + "</p>" +
                                "</div>");
                });
            } else {
                list.append("<div class=\'alert alert-warning\'>No hay horarios activos.</div>");
            }
            
            $("#schedulesModal").modal("show");
        });
    });
});
</script>
';


echo $OUTPUT->footer();
