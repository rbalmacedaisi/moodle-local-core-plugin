<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/course/lib.php');

if (file_exists($CFG->dirroot . '/vendor/autoload.php')) {
    require_once($CFG->dirroot . '/vendor/autoload.php');
}

// Permissions
admin_externalpage_setup('grupomakro_core_manage_courses');
die('GMK DEBUG: ALIVE after setup'); // DEBUG LINE
if (!is_siteadmin()) {
    print_error('onlyadmins', 'error');
}

$PAGE->set_url('/local/grupomakro_core/pages/manage_courses.php');
$PAGE->set_title('Gestión de Cursos Moderna');
$PAGE->set_heading('Gestor Avanzado de Cursos');
$PAGE->requires->jquery();

// Params
$filter_search = optional_param('search', '', PARAM_TEXT);
$filter_category = optional_param('category', 0, PARAM_INT);
$filter_visible = optional_param('visible', -1, PARAM_INT); // -1 all, 1 yes, 0 no
$filter_plan = optional_param('plan', 0, PARAM_INT);
$filter_schedule_status = optional_param('schedulestatus', -1, PARAM_INT); // -1 All, 1 Active, 0 Inactive
$action = optional_param('action', '', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 20;

// Context for SQL
$context = context_system::instance();

// Base SQL Construction
$where = ["c.id <> 1"]; // Exclude site course
$params = [];

// Explicitly build URL for pagination to ensure persistence
$pagingurl = new moodle_url('/local/grupomakro_core/pages/manage_courses.php');

if (!empty($filter_search)) {
    $where[] = "( " . $DB->sql_like('c.fullname', ':search1', false) . " OR " . $DB->sql_like('c.shortname', ':search2', false) . " OR " . $DB->sql_like('c.idnumber', ':search3', false) . " )";
    $params['search1'] = "%$filter_search%";
    $params['search2'] = "%$filter_search%";
    $params['search3'] = "%$filter_search%";
    $pagingurl->param('search', $filter_search);
}

if ($filter_category > 0) {
    $where[] = "c.category = :cat";
    $params['cat'] = $filter_category;
    $pagingurl->param('category', $filter_category);
}

if ($filter_visible !== -1) {
    $where[] = "c.visible = :vis";
    $params['vis'] = $filter_visible;
    $pagingurl->param('visible', $filter_visible);
}

// Filter by Learning Plan
if ($filter_plan > 0) {
    $where[] = "EXISTS (SELECT 1 FROM {local_learning_courses} llc WHERE llc.courseid = c.id AND llc.learningplanid = :planid)";
    $params['planid'] = $filter_plan;
    $pagingurl->param('plan', $filter_plan);
}

// Filter by Schedule Status
if ($filter_schedule_status !== -1) {
    if ($filter_schedule_status == 1) {
        $where[] = "EXISTS (SELECT 1 FROM {gmk_class} gc WHERE gc.courseid = c.id AND gc.closed = 0)";
    } else {
        $where[] = "NOT EXISTS (SELECT 1 FROM {gmk_class} gc WHERE gc.courseid = c.id AND gc.closed = 0)";
    }
    $pagingurl->param('schedulestatus', $filter_schedule_status);
}

// Set URL to page (good practice)
$PAGE->set_url($pagingurl);

$whereSQL = implode(" AND ", $where);

// Columns for SQL
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

// Styles & Assets (Material Design lookalike + Custom Modal Styles mimickng Academic Panel)
echo '
<link href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<style>
    body { font-family: "Roboto", sans-serif; }
    
    /* Material Cards */
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
    
    /* Buttons */
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
    
    /* Stats */
    .stats-card { text-align: center; padding: 20px; }
    .stats-number { font-size: 2rem; font-weight: 700; color: #1976D2; }
    .stats-label { color: #666; font-size: 0.9rem; text-transform: uppercase; }
    
    /* Table */
    .table-material th { border-top: none; color: #666; font-weight: 500; }
    
    /* Badges & Icons */
    .badge-material { padding: 5px 10px; border-radius: 4px; font-weight: 500; font-size: 0.8rem; }
    .badge-visible { background: #E8F5E9; color: #2E7D32; }
    .badge-hidden { background: #FFEBEE; color: #C62828; }
    .action-icon { font-size: 1.2rem; color: #555; margin: 0 5px; transition: color 0.2s; }
    .action-icon:hover { color: #1976D2; text-decoration: none; }
    .action-icon.delete:hover { color: #C62828; }

    /* --- Custom Modal Styling (Mimicking academicpanel.php / grademodal.js) --- */
    ul.modules-item-list {
        display: grid;
        grid-template-columns: repeat(1,1fr);
        padding: 0 1rem;
        list-style-type: none;
        margin-bottom: 0;
    }
    ul.modules-item-list li.item-list {
        position: relative;
        display: flex;
        align-items: center;
        margin: 0 0 10px 0;
        padding: 10px;
        border-radius: 0.75rem;
        transition: all .3s ease-in-out;
        gap: 1rem;
        background-color: #f5f5f5; /* Light grey bg like cards */
        border: 1px solid #e0e0e0;
    }
    ul.modules-item-list li.item-list:hover {
        background-color: #eeeeee;
        box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    }
    .custom-avatar {
        height: 35px;
        width: 35px;
        min-width: 35px;
        border-radius: 50%;
        background-color: #e0e0e0;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .custom-avatar i {
        font-size: 20px !important;
        color: #4CAF50; /* Success green like grademodal */
    }
    /* Blue avatar for schedules/active to distinguish */
    .custom-avatar.blue-avatar i { color: #1976D2; }

    .list-item-info {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
        justify-content: space-between;
        flex-grow: 1;
    }
    .list-item-info-text p {
        margin: 0;
        font-size: 0.9rem;
        font-weight: 500;
        color: #333;
    }
    .list-item-subtext {
        font-size: 0.8rem;
        color: #666;
    }
    .modlist-header {
        font-weight: bold;
        color: #757575; /* text--secondary */
        font-size: 0.875rem; /* text-subtitle-2 */
        padding-left: 1rem;
        margin-bottom: 10px;
        display: block;
    }
    .modal-mimic-title {
        font-size: 1.1rem;
        font-weight: bold;
        padding: 0 1rem 1rem 1rem;
        border-bottom: 1px solid #eff0f1;
        margin-bottom: 1rem;
    }
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

echo ' <div class="col-md-3 mb-2 d-flex justify-content-end">';
echo '   <button type="submit" class="btn btn-secondary mr-2">Filtrar</button>';
echo '   <button type="submit" name="action" value="export" class="btn btn-success"><i class="mdi mdi-file-excel"></i> Excel</button>';
echo ' </div>';
echo '</form>';

// Results
$total_matching = $DB->count_records_sql("SELECT COUNT(*) FROM {course} c WHERE $whereSQL", $params);
$courses = $DB->get_records_sql("SELECT $sql_cols FROM {course} c WHERE $whereSQL ORDER BY c.fullname ASC", $params, $page * $perpage, $perpage);

// Data for JS
$jsCourseData = [];

// Fetch Extra Data
if ($courses) {
    foreach ($courses as $c) {
        // Fetch Plans
        $c->plans = $DB->get_records_sql("
            SELECT lp.id, lp.name 
            FROM {local_learning_plans} lp
            JOIN {local_learning_courses} llc ON llc.learningplanid = lp.id
            WHERE llc.courseid = ?", [$c->id]);
        
        // Fetch All Schedules (closed included)
        // Added Try-Catch for Robustness against DB Schema Mismatch
        try {
            $c->active_schedules = $DB->get_records_sql("
                SELECT gc.id, gc.name, gc.inithourformatted, gc.endhourformatted, gc.classdays, gc.closed, gc.initdate, gc.enddate, gc.approved
                FROM {gmk_class} gc
                WHERE gc.courseid = ?
                ORDER BY gc.closed ASC, gc.id DESC", [$c->id]);
        } catch (\Exception $e) {
            error_log("GMK_DEBUG: Error fetching schedules for course " . $c->id . ". Likely missing columns. Error: " . $e->getMessage());
            // Fallback Query without dates
            $c->active_schedules = $DB->get_records_sql("
                SELECT gc.id, gc.name, gc.inithourformatted, gc.endhourformatted, gc.classdays, gc.closed, gc.approved
                FROM {gmk_class} gc
                WHERE gc.courseid = ?
                ORDER BY gc.closed ASC, gc.id DESC", [$c->id]);
        }
            
        // Add to JS Data
        $jsCourseData[$c->id] = [
            'plans' => array_values($c->plans),
            'schedules' => array_values($c->active_schedules)
        ];
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
        $plansBtnClass = $plansCount > 0 ? 'btn-info' : 'btn-light disabled';
        $plansBtn = '<button type="button" class="btn btn-sm '.$plansBtnClass.' view-plans-btn" data-courseid="'.$c->id.'" data-coursename="'.s($c->fullname).'">
                        <i class="mdi mdi-book-open-variant"></i> '.$plansCount.'
                     </button>';

        // Schedules Data
        $schedulesCount = count($c->active_schedules);
        $schedulesBtnClass = $schedulesCount > 0 ? 'btn-success' : 'btn-light disabled';
        $schedulesBtn = '<button type="button" class="btn btn-sm '.$schedulesBtnClass.' view-schedules-btn" data-courseid="'.$c->id.'" data-coursename="'.s($c->fullname).'">
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
    
    // Explicitly pass the constructed paging URL
    echo $OUTPUT->paging_bar($total_matching, $page, $perpage, $pagingurl);

} else {
    echo '<div class="alert alert-info text-center p-4">No se encontraron cursos con los filtros seleccionados.</div>';
}

echo '</div>'; // card-body
echo '</div>'; // card-material

// Inject JS Data safely
echo '<script>
window.gmkCourseData = ' . json_encode($jsCourseData) . ';
console.log("GMK: Course Data Loaded", window.gmkCourseData);
</script>';

// Javascript for Moodle Modals via proper Moodle API
// We use js_amd_inline to ensure it runs AFTER require.js is loaded by the theme
$js_modal_code = '
require(["jquery", "core/modal_factory", "core/modal_events"], function($, ModalFactory, ModalEvents) {
    console.log("GMK: AMD Modules Loaded");
    
    $(document).ready(function() {
        console.log("GMK: Document Ready");
        
        // Plans Modal
        $(document).on("click", ".view-plans-btn", function(e) {
            e.preventDefault();
            console.log("GMK: Plans Clicked");
            
            var btn = $(this);
            var courseId = btn.data("courseid");
            var courseName = btn.data("coursename");
            
            // Fetch data from global object
            var data = (window.gmkCourseData && window.gmkCourseData[courseId]) ? window.gmkCourseData[courseId] : null;
            var plans = data ? data.plans : [];
            console.log("GMK: Plans Data", plans);

            ModalFactory.create({
                type: ModalFactory.types.DEFAULT,
                title: "Planes de Aprendizaje",
                body: "Cargando...",
            }).then(function(modal) {
                var bodyHtml = "<div class=\'modal-mimic-title\'>" + courseName + "</div>";
                bodyHtml += "<div class=\'modlist\'><span class=\'modlist-header\'>Planes Asociados</span><ul class=\'modules-item-list\'>";
                
                if (plans && plans.length > 0) {
                    $.each(plans, function(i, plan) {
                        bodyHtml += "<li class=\'item-list\'>" +
                                       "<div class=\'custom-avatar\'><i class=\'mdi mdi-notebook-multiple\'></i></div>" +
                                       "<div class=\'list-item-info\'>" +
                                            "<div class=\'list-item-info-text\'><p>" + plan.name + "</p></div>" +
                                       "</div>" +
                                    "</li>";
                    });
                } else {
                    bodyHtml += "<li class=\'item-list\'><i class=\'mdi mdi-alert-circle-outline mr-2\'></i> Ningún plan asociado</li>";
                }
                bodyHtml += "</ul></div>";
                
                modal.setBody(bodyHtml);
                modal.show();
            }).fail(function(ex) {
                console.error("GMK: Modal Create Failed", ex);
            });
        });

        // Schedules Modal
        $(document).on("click", ".view-schedules-btn", function(e) {
            e.preventDefault();
            console.log("GMK: Schedules Clicked");
            
            var btn = $(this);
            var courseId = btn.data("courseid");
            var courseName = btn.data("coursename");

            // Fetch data from global object
            var data = (window.gmkCourseData && window.gmkCourseData[courseId]) ? window.gmkCourseData[courseId] : null;
            var schedules = data ? data.schedules : [];
            console.log("GMK: Schedules Data", schedules);
            
             ModalFactory.create({
                type: ModalFactory.types.DEFAULT,
                title: "Horarios del Curso",
                body: "Cargando...",
                large: true
            }).then(function(modal) {
                var bodyHtml = "<div class=\'modal-mimic-title\'>" + courseName + "</div>";
                bodyHtml += "<div class=\'modlist\'><span class=\'modlist-header\'>Listado de Horarios</span><ul class=\'modules-item-list\'>";

                if (schedules && schedules.length > 0) {
                    $.each(schedules, function(i, sch) {
                         var isClosed = sch.closed == 1;
                         var statusBadge = isClosed 
                            ? "<span class=\'badge badge-danger ml-2\'>CERRADO</span>" 
                            : "<span class=\'badge badge-success ml-2\'>ABIERTO</span>";
                         
                         var iconClass = isClosed ? "mdi mdi-lock" : "mdi mdi-calendar-clock";
                         var avatarClass = isClosed ? "custom-avatar" : "custom-avatar blue-avatar"; 
                         
                         // Date formatting
                         var dateRange = "";
                         if (sch.initdate > 0 && sch.enddate > 0) {
                             var d1 = new Date(sch.initdate * 1000).toLocaleDateString();
                             var d2 = new Date(sch.enddate * 1000).toLocaleDateString();
                             dateRange = " | " + d1 + " - " + d2;
                         }

                         var actionBtn = "";
                         if (isClosed) {
                             actionBtn = "<button class=\'btn btn-sm btn-outline-primary reopen-schedule-btn\' data-id=\'" + sch.id + "\'><i class=\'mdi mdi-lock-open-variant\'></i> Re-abrir</button>";
                         } else {
                             actionBtn = "<button class=\'btn btn-sm btn-outline-danger close-schedule-btn\' data-id=\'" + sch.id + "\'><i class=\'mdi mdi-lock\'></i> Cerrar</button>";
                         }
                         
                         if (sch.approved == 1) {
                             actionBtn += " <button class=\'btn btn-sm btn-outline-warning revert-schedule-btn\' data-id=\'" + sch.id + "\'><i class=\'mdi mdi-undo\'></i> Revertir</button>";
                             // Manual Enrollment Button
                             if (!isClosed) {
                                 actionBtn += " <button class=\'btn btn-sm btn-outline-info enroll-student-btn\' data-id=\'" + sch.id + "\' data-name=\'" + sch.name + "\'><i class=\'mdi mdi-account-plus\'></i> Inscribir</button>";
                             }
                         }

                         bodyHtml += "<li class=\'item-list\' style=\'" + (isClosed ? "opacity:0.8; background:#f9f9f9;" : "") + "\'>" +
                                        "<div class=\'" + avatarClass + "\'><i class=\'" + iconClass + "\'></i></div>" +
                                        "<div class=\'list-item-info\'>" +
                                            "<div class=\'list-item-info-text\'>" +
                                                "<p>" + sch.name + statusBadge + "</p>" +
                                                "<span class=\'list-item-subtext\'>Horario: " + sch.inithourformatted + " - " + sch.endhourformatted + dateRange + "</span>" +
                                            "</div>" +
                                        "</div>" +
                                        "<div class=\'list-item-actions pl-2\'>" + actionBtn + "</div>" +
                                     "</li>";
                    });
                } else {
                     bodyHtml += "<li class=\'item-list\'><i class=\'mdi mdi-alert-circle-outline mr-2\'></i> No hay horarios registrados</li>";
                }
                bodyHtml += "</ul></div>";
                
                modal.setBody(bodyHtml);
                modal.show();
            }).fail(function(ex) {
                console.error("GMK: Modal Create Failed", ex);
            });
        });
        
        // Re-open / Close Actions
        $(document).on("click", ".reopen-schedule-btn, .close-schedule-btn", function(e) {
            e.preventDefault();
            var btn = $(this);
            var classId = btn.data("id");
            var isOpenAction = btn.hasClass("reopen-schedule-btn");
            var actionText = isOpenAction ? "Re-abrir" : "Cerrar";
            
            if(!confirm("¿Está seguro que desea " + actionText + " este horario?")) return;
            
            // Call External Function
            require([\'core/ajax\'], function(ajax) {
                var promises = ajax.call([{
                    methodname: \'local_grupomakro_toggle_class_status\',
                    args: { classId: classId, open: isOpenAction }
                }]);
                
                promises[0].done(function(response) {
                    location.reload(); // Reload to reflect changes
                }).fail(function(ex) {
                    alert("Error: " + ex.message);
                });
            });
        });

        // Revert Approval Action
        $(document).on("click", ".revert-schedule-btn", function(e) {
             e.preventDefault();
             var btn = $(this);
             var classId = btn.data("id");
             
             if(!confirm("¿Está seguro que desea REVERTIR la aprobación de este horario?\\nEsto eliminará el grupo asociado y devolverá a los estudiantes a pre-inscripción.")) return;
             
             require([\'core/ajax\'], function(ajax) {
                 var promises = ajax.call([{
                     methodname: \'local_grupomakro_revert_approval\',
                     args: { classId: classId }
                 }]);
                 
                 promises[0].done(function(response) {
                     if (response.status === \'ok\') {
                         location.reload();
                     } else {
                         alert(response.message);
                     }
                 }).fail(function(ex) {
                     alert("Error: " + ex.message);
                 });
             });
        });

        // Manual Enrollment Action
        $(document).on("click", ".enroll-student-btn", function(e) {
            e.preventDefault();
            var btn = $(this);
            var classId = btn.data("id");
            var className = btn.data("name");

             ModalFactory.create({
                type: ModalFactory.types.DEFAULT,
                title: "Inscribir Estudiante - " + className,
                body: "Cargando...",
            }).then(function(modal) {
                var bodyHtml = "<div>" +
                                 "<div class=\'form-group\'>" +
                                    "<label>Buscar Usuario (Nombre, Email)</label>" +
                                    "<div class=\'input-group\'>" +
                                        "<input type=\'text\' class=\'form-control\' id=\'user_search_input\' placeholder=\'Min 3 caracteres...\'>" +
                                        "<div class=\'input-group-append\'>" +
                                            "<button class=\'btn btn-primary\' id=\'user_search_btn\'>Buscar</button>" +
                                        "</div>" +
                                    "</div>" +
                                 "</div>" +
                                 "<div id=\'user_search_results\' style=\'max-height: 200px; overflow-y: auto;\' class=\'mt-2\'></div>" +
                               "</div>";
                
                modal.setBody(bodyHtml);
                modal.show();
                
                // Bind Search Event inside Modal RE-BINDING needed dynamically usually, but document.on works if selectors are unique enough or scoped
                // Let's store classId in the search button data for easy access
                setTimeout(function() {
                    $('#user_search_btn').data('classid', classId);
                }, 100);

            }).fail(function(ex) {
                console.error("GMK: Modal Create Failed", ex);
            });
        });

        // User Search Logic
        $(document).on("click", "#user_search_btn", function(e) {
            e.preventDefault();
            var btn = $(this);
            var query = $('#user_search_input').val();
            var resultsDiv = $('#user_search_results');
            var classId = btn.data('classid');

            if(query.length < 3) {
                alert("Ingrese al menos 3 caracteres.");
                return;
            }

            resultsDiv.html('<div class="text-center"><i class="fa fa-spinner fa-spin"></i> Buscando...</div>');

            require(['core/ajax'], function(ajax) {
                 var promises = ajax.call([{
                     methodname: 'local_grupomakro_search_users',
                     args: { query: query }
                 }]);
                 
                 promises[0].done(function(users) {
                     var html = "<ul class=\'list-group\'>";
                     if (users.length > 0) {
                         $.each(users, function(i, u) {
                             html += "<li class=\'list-group-item d-flex justify-content-between align-items-center\'>" +
                                        "<div><strong>" + u.fullname + "</strong><br><small>" + u.email + "</small></div>" +
                                        "<button class=\'btn btn-sm btn-success perform-enroll-btn\' data-userid=\'" + u.id + "\' data-classid=\'" + classId + "\'>Inscribir</button>" +
                                     "</li>";
                         });
                     } else {
                         html += "<li class=\'list-group-item\'>No se encontraron usuarios.</li>";
                     }
                     html += "</ul>";
                     resultsDiv.html(html);
                 }).fail(function(ex) {
                     resultsDiv.html('<span class="text-danger">Error: ' + ex.message + '</span>');
                 });
            });
        });

        // Perform Enrollment
        $(document).on("click", ".perform-enroll-btn", function(e) {
            e.preventDefault();
            var btn = $(this);
            var userId = btn.data('userid');
            var classId = btn.data('classid'); // Passed from search button

            btn.prop('disabled', true).text('Inscribiendo...');

            require(['core/ajax'], function(ajax) {
                 var promises = ajax.call([{
                     methodname: 'local_grupomakro_manual_enroll',
                     args: { classId: classId, userId: userId }
                 }]);
                 
                 promises[0].done(function(response) {
                     if (response.status === 'ok') {
                         btn.removeClass('btn-success').addClass('btn-secondary').text('Inscrito');
                         alert("Usuario inscrito correctamente.");
                     } else {
                         btn.prop('disabled', false).text('Inscribir'); // Reset
                         alert("Error: " + response.message);
                     }
                 }).fail(function(ex) {
                     btn.prop('disabled', false).text('Inscribir');
                     alert("Error de sistema: " + ex.message);
                 });
            });
        });

    });
});';

$PAGE->requires->js_amd_inline($js_modal_code);

echo $OUTPUT->footer();
