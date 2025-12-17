<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/user/lib.php');

// Defines
define('GMK_BULK_DELETE_PER_PAGE', 20);

// Permissions
admin_externalpage_setup('grupomakro_core_bulk_delete_users');

$PAGE->set_url('/local/grupomakro_core/pages/bulk_delete_users.php');
$PAGE->set_title('Eliminación Masiva de Usuarios');
$PAGE->set_heading('Gestión y Eliminación de Usuarios');

// Params
$filter_name = optional_param('filter_name', '', PARAM_TEXT);
$filter_email = optional_param('filter_email', '', PARAM_TEXT);
$filter_username = optional_param('filter_username', '', PARAM_TEXT);
$page = optional_param('page', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$confirm = optional_param('confirm', 0, PARAM_INT);
 
// Header
echo $OUTPUT->header();

// Action: Delete
if ($action === 'delete' && $confirm && confirm_sesskey()) {
    $ids = optional_param_array('userids', [], PARAM_INT);
    if (!empty($ids)) {
        $count = 0;
        foreach ($ids as $userid) {
            // Safety checks
            if ($userid == $USER->id || is_siteadmin($userid) || is_guest($userid)) {
                continue; // Skip self, admin, guest
            }
            $u = $DB->get_record('user', ['id' => $userid]);
            if ($u) {
                if (delete_user($u)) {
                    $count++;
                }
            }
        }
        echo $OUTPUT->notification("$count usuarios eliminados correctamente.", 'success');
    } else {
        echo $OUTPUT->notification("No se seleccionaron usuarios.", 'warning');
    }
}

// Filter Form
echo '<div class="card p-3 mb-3">';
echo '<h5 class="card-title">Filtros de Búsqueda</h5>';
echo '<form method="get" action="" class="form-inline">';
echo '  <input type="text" name="filter_name" class="form-control mr-2" placeholder="Nombre / Apellido" value="' . s($filter_name) . '">';
echo '  <input type="text" name="filter_username" class="form-control mr-2" placeholder="Usuario (Username)" value="' . s($filter_username) . '">';
echo '  <input type="text" name="filter_email" class="form-control mr-2" placeholder="Email" value="' . s($filter_email) . '">';
echo '  <button type="submit" class="btn btn-primary">Buscar</button>';
echo '  <a href="?" class="btn btn-secondary ml-2">Limpiar</a>';
echo '</form>';
echo '</div>';

// Build Query
$sql = "SELECT id, firstname, lastname, email, username, lastaccess, suspended FROM {user} WHERE deleted = 0 AND id <> :guestid AND id <> :myid";
$params = ['guestid' => $CFG->siteguest, 'myid' => $USER->id];

if ($filter_name) {
    $sql .= " AND (" . $DB->sql_like('firstname', ':name', false) . " OR " . $DB->sql_like('lastname', ':name2', false) . ")";
    $params['name'] = "%$filter_name%";
    $params['name2'] = "%$filter_name%";
}
if ($filter_username) {
    $sql .= " AND " . $DB->sql_like('username', ':user', false);
    $params['user'] = "%$filter_username%";
}
if ($filter_email) {
    $sql .= " AND " . $DB->sql_like('email', ':email', false);
    $params['email'] = "%$filter_email%";
}

// Count
$count_sql = "SELECT COUNT(*) FROM {user} WHERE deleted = 0 AND id <> :guestid AND id <> :myid";
if ($filter_name) $count_sql .= " AND (" . $DB->sql_like('firstname', ':name', false) . " OR " . $DB->sql_like('lastname', ':name2', false) . ")";
if ($filter_username) $count_sql .= " AND " . $DB->sql_like('username', ':user', false);
if ($filter_email) $count_sql .= " AND " . $DB->sql_like('email', ':email', false);

$total = $DB->count_records_sql($count_sql, $params);
$users = $DB->get_records_sql($sql, $params, $page * GMK_BULK_DELETE_PER_PAGE, GMK_BULK_DELETE_PER_PAGE);

// Display Table
echo '<form method="post" action="" id="delete-form">';
echo '<input type="hidden" name="action" value="delete">';
echo '<input type="hidden" name="confirm" value="1">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';

if ($total > 0) {
    $table = new html_table();
    $table->head = [
        '<input type="checkbox" id="select-all">',
        'Foto',
        'Nombre Completo',
        'Usuario',
        'Email',
        'Último Acceso',
        'Estado'
    ];
    $table->attributes['class'] = 'generaltable table table-hover';

    foreach ($users as $user) {
        $checkbox = '<input type="checkbox" name="userids[]" value="' . $user->id . '" class="user-checkbox">';
        $avatar = $OUTPUT->user_picture($user, ['size' => 35]);
        $fullname = fullname($user);
        $lastaccess = $user->lastaccess ? userdate($user->lastaccess) : 'Nunca';
        $status = $user->suspended ? '<span class="badge badge-warning">Suspendido</span>' : '<span class="badge badge-success">Activo</span>';

        $table->data[] = [
            $checkbox,
            $avatar,
            $fullname,
            $user->username,
            $user->email,
            $lastaccess,
            $status
        ];
    }
    
    echo html_writer::table($table);
    
    // Pagination
    echo $OUTPUT->paging_bar($total, $page, GMK_BULK_DELETE_PER_PAGE, $PAGE->url);
    
    // Delete Button
    echo '<div class="mt-3">';
    echo '<button type="submit" class="btn btn-danger" onclick="return confirm(\'¿Estás SEGURO de eliminar los usuarios seleccionados/as? Esta acción NO se puede deshacer.\');">Eliminar Seleccionados</button>';
    echo '</div>';

} else {
    echo $OUTPUT->notification('No se encontraron usuarios con los filtros aplicados.', 'info');
}

echo '</form>';

// JS for Select All
echo "
<script>
document.getElementById('select-all').addEventListener('change', function(e) {
    var checkboxes = document.querySelectorAll('.user-checkbox');
    for (var i = 0; i < checkboxes.length; i++) {
        checkboxes[i].checked = e.target.checked;
    }
});
</script>
";

echo $OUTPUT->footer();
