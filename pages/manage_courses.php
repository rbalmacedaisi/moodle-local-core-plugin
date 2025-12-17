<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/course/lib.php');

// Permissions
admin_externalpage_setup('grupomakro_core_manage_courses');
if (!is_siteadmin()) {
    print_error('onlyadmins', 'error');
}

$PAGE->set_url('/local/grupomakro_core/pages/manage_courses.php');
$PAGE->set_title('Gestión de Cursos Moderna');
$PAGE->set_heading('Gestor Avanzado de Cursos');

// Params
$filter_search = optional_param('search', '', PARAM_TEXT);
$filter_category = optional_param('category', 0, PARAM_INT);
$filter_visible = optional_param('visible', -1, PARAM_INT); // -1 all, 1 yes, 0 no
$action = optional_param('action', '', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 20;

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

$whereSQL = implode(" AND ", $where);

// Columns for SQL
// Subquery for Student Count (Role = 5 usually, but checking all roles or context users is safer fallback, let's use enrolments count if possible or RA)
// Using role_assignments on course context (50) for 'student' role logic is complex identifying which id is student.
// Let's use a generic count of role assignments for now or specific if specific role known.
// Creating a subquery for gmk_class count and role_assignments count.
// Optimization: Moodle 'course' table doesn't have student count.
$sql_cols = "c.id, c.fullname, c.shortname, c.idnumber, c.category, c.visible, c.startdate, c.enddate, 
            (SELECT COUNT(gc.id) FROM {gmk_class} gc WHERE gc.courseid = c.id) as schedules_count,
            (SELECT COUNT(DISTINCT ra.userid) FROM {role_assignments} ra 
             JOIN {context} ctx ON ctx.id = ra.contextid 
             WHERE ctx.instanceid = c.id AND ctx.contextlevel = 50 AND ra.roleid = 5) as student_count"; 
             // Assuming roleid 5 is student. Adjust if distinct logic needed.

// EXPORT TO CSV
if ($action === 'export') {
    $filename = 'reporte_cursos_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $fp = fopen('php://output', 'w');
    fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM
    
    // Headers
    fputcsv($fp, ['ID', 'Shortname', 'Fullname', 'Category ID', 'Schedules', 'Students', 'Visible', 'Start Date']);
    
    // Fetch ALL records for export
    $export_courses = $DB->get_records_sql("SELECT $sql_cols FROM {course} c WHERE $whereSQL ORDER BY c.fullname ASC", $params);
    
    foreach ($export_courses as $c) {
        fputcsv($fp, [
            $c->id, 
            $c->shortname, 
            $c->fullname, 
            $c->category, 
            $c->schedules_count, 
            $c->student_count, 
            $c->visible ? 'Yes' : 'No', 
            userdate($c->startdate, '%Y-%m-%d')
        ]);
    }
    fclose($fp);
    die;
}

echo $OUTPUT->header();

// Styles & Assets (Material Design lookalike)
echo '
<link href="https://fonts.googleapis.com/css?family=Roboto:300,400,500,700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
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
echo ' <div class="col-md-4 mb-2">';
echo '   <div class="input-group">';
echo '     <div class="input-group-prepend"><span class="input-group-text border-0 bg-white"><i class="mdi mdi-magnify"></i></span></div>';
echo '     <input type="text" name="search" class="form-control border-0" placeholder="Buscar por Nombre, Shortname o ID..." value="'.s($filter_search).'">';
echo '   </div>';
echo ' </div>';
echo ' <div class="col-md-3 mb-2">';
echo '   <select name="category" class="form-control border-0"><option value="0">-- Todas las Categorías --</option>';
$cats = \core_course_category::make_categories_list();
foreach($cats as $id => $name){ 
    $sel = ($filter_category == $id) ? 'selected' : '';
    echo "<option value='$id' $sel>$name</option>"; 
}
echo '   </select>';
echo ' </div>';
echo ' <div class="col-md-2 mb-2">';
echo '   <select name="visible" class="form-control border-0">';
echo '     <option value="-1" '.($filter_visible==-1?'selected':'').'>Todos Estados</option>';
echo '     <option value="1" '.($filter_visible==1?'selected':'').'>Visibles</option>';
echo '     <option value="0" '.($filter_visible==0?'selected':'').'>Ocultos</option>';
echo '   </select>';
echo ' </div>';
echo ' <div class="col-md-3 mb-2 d-flex justify-content-end">';
echo '   <button type="submit" class="btn btn-secondary mr-2">Filtrar</button>';
echo '   <button type="submit" name="action" value="export" class="btn btn-success"><i class="mdi mdi-file-excel"></i> CSV</button>';
echo ' </div>';
echo '</form>';

// Results
$total_matching = $DB->count_records_sql("SELECT COUNT(*) FROM {course} c WHERE $whereSQL", $params);
$courses = $DB->get_records_sql("SELECT $sql_cols FROM {course} c WHERE $whereSQL ORDER BY c.fullname ASC", $params, $page * $perpage, $perpage);

if ($total_matching > 0) {
    echo '<div class="table-responsive">';
    echo '<table class="table table-hover table-material">';
    echo '<thead><tr>
            <th>ID</th>
            <th>Shortname</th>
            <th>Nombre Completo</th>
            <th>Categoría</th>
            <th class="text-center">Horarios</th>
            <th class="text-center">Estudiantes</th>
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
        
        echo '<tr>';
        echo '<td>'.$c->id.'</td>';
        echo '<td><strong>'.s($c->shortname).'</strong></td>';
        echo '<td><a href="'.$viewUrl.'" class="text-dark">'.s($c->fullname).'</a></td>';
        echo '<td><small class="text-muted">'.$catName.'</small></td>';
        echo '<td class="text-center"><span class="badge badge-light border">'.$c->schedules_count.'</span></td>';
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

echo $OUTPUT->footer();
