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
$filter_usertype = optional_param('filter_usertype', '', PARAM_TEXT); // New Param
$page = optional_param('page', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$confirm = optional_param('confirm', 0, PARAM_INT);

// Header
echo $OUTPUT->header();

// Action: Delete
if ($action === 'delete' && $confirm && confirm_sesskey()) {
    $delete_all_matching = optional_param('delete_all_matching', 0, PARAM_INT);
    $ids = [];

    if ($delete_all_matching) {
        // Reconstruct Query to get ALL IDs
        // Note: We use the POSTed filters which we preserved in hidden fields
        $f_name = optional_param('filter_name', '', PARAM_TEXT);
        $f_user = optional_param('filter_username', '', PARAM_TEXT);
        $f_email = optional_param('filter_email', '', PARAM_TEXT);
        $f_type = optional_param('filter_usertype', '', PARAM_TEXT);

        $sql_del = "SELECT id FROM {user} WHERE deleted = 0 AND id <> :guestid AND id <> :myid";
        $params_del = ['guestid' => $CFG->siteguest, 'myid' => $USER->id];

        if ($f_name) {
            $sql_del .= " AND (" . $DB->sql_like('firstname', ':name', false) . " OR " . $DB->sql_like('lastname', ':name2', false) . ")";
            $params_del['name'] = "%$f_name%";
            $params_del['name2'] = "%$f_name%";
        }
        if ($f_user) {
            $sql_del .= " AND " . $DB->sql_like('username', ':user', false);
            $params_del['user'] = "%$f_user%";
        }
        if ($f_email) {
            $sql_del .= " AND " . $DB->sql_like('email', ':email', false);
            $params_del['email'] = "%$f_email%";
        }
        if ($f_type) {
             $ut_field_id = $DB->get_field('user_info_field', 'id', ['shortname' => 'usertype']);
             if ($ut_field_id) {
                $sql_del .= " AND EXISTS (SELECT 1 FROM {user_info_data} uid WHERE uid.userid = {user}.id AND uid.fieldid = :fieldid AND " . $DB->sql_like('uid.data', ':usertype', false) . ")";
                $params_del['fieldid'] = $ut_field_id;
                $params_del['usertype'] = $f_type;
             }
        }
        
        $ids = $DB->get_fieldset_sql($sql_del, $params_del);

    } else {
        $ids = optional_param_array('userids', [], PARAM_INT);
    }

    if (!empty($ids)) {
        core_php_time_limit::raise(300); // 5 mins
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

// Get Custom Field 'usertype' ID
$usertype_field_id = $DB->get_field('user_info_field', 'id', ['shortname' => 'usertype']);

// Filter Form
echo '<div class="card p-3 mb-3">';
echo '<h5 class="card-title">Filtros de Búsqueda</h5>';
echo '<form method="get" action="" class="form-inline">';
echo '  <input type="text" name="filter_name" class="form-control mr-2" placeholder="Nombre / Apellido" value="' . s($filter_name) . '">';
echo '  <input type="text" name="filter_username" class="form-control mr-2" placeholder="Usuario (Username)" value="' . s($filter_username) . '">';
echo '  <input type="text" name="filter_email" class="form-control mr-2" placeholder="Email" value="' . s($filter_email) . '">';

// User Type Select
echo '  <select name="filter_usertype" class="form-control mr-2">';
echo '    <option value="">-- Todos los Tipos --</option>';
$types = ['Estudiante', 'Docente'];
foreach ($types as $t) {
    $selected = ($filter_usertype === $t) ? 'selected' : '';
    echo "    <option value=\"$t\" $selected>$t</option>";
}
echo '  </select>';

echo '  <button type="submit" class="btn btn-primary">Buscar</button>';
echo '  <a href="?" class="btn btn-secondary ml-2">Limpiar</a>';
echo '</form>';
echo '</div>';

// Build Query
$userfields = \core_user\fields::for_userpic()->get_sql('', false, '', '', false)->selects;
$sql = "SELECT id, username, lastaccess, suspended, email, $userfields FROM {user} WHERE deleted = 0 AND id <> :guestid AND id <> :myid";
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
if ($filter_usertype && $usertype_field_id) {
    // Subquery to filter by custom field
    $sql .= " AND EXISTS (SELECT 1 FROM {user_info_data} uid WHERE uid.userid = {user}.id AND uid.fieldid = :fieldid AND " . $DB->sql_like('uid.data', ':usertype', false) . ")";
    $params['fieldid'] = $usertype_field_id;
    $params['usertype'] = $filter_usertype;
}

// Count
$count_sql = "SELECT COUNT(*) FROM {user} WHERE deleted = 0 AND id <> :guestid AND id <> :myid";
if ($filter_name) $count_sql .= " AND (" . $DB->sql_like('firstname', ':name', false) . " OR " . $DB->sql_like('lastname', ':name2', false) . ")";
if ($filter_username) $count_sql .= " AND " . $DB->sql_like('username', ':user', false);
if ($filter_email) $count_sql .= " AND " . $DB->sql_like('email', ':email', false);
if ($filter_usertype && $usertype_field_id) {
    $count_sql .= " AND EXISTS (SELECT 1 FROM {user_info_data} uid WHERE uid.userid = {user}.id AND uid.fieldid = :fieldid AND " . $DB->sql_like('uid.data', ':usertype', false) . ")";
}

$total = $DB->count_records_sql($count_sql, $params);
$users = $DB->get_records_sql($sql, $params, $page * GMK_BULK_DELETE_PER_PAGE, GMK_BULK_DELETE_PER_PAGE);

// Display Table
echo '<form method="post" action="" id="delete-form">';
echo '<input type="hidden" name="action" value="delete">';
echo '<input type="hidden" name="confirm" value="1">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
// Hidden params for "Delete All Matching" reconstruction
echo '<input type="hidden" name="filter_name" value="' . s($filter_name) . '">';
echo '<input type="hidden" name="filter_username" value="' . s($filter_username) . '">';
echo '<input type="hidden" name="filter_email" value="' . s($filter_email) . '">';
echo '<input type="hidden" name="filter_usertype" value="' . s($filter_usertype) . '">';
echo '<input type="hidden" name="delete_all_matching" id="delete_all_matching" value="0">';

if ($total > 0) {
    // Select All Matching Banner
    if ($total > GMK_BULK_DELETE_PER_PAGE) {
         echo '<div id="select-all-banner" class="alert alert-info" style="display:none;">';
         echo '   <span id="select-page-msg">Se han seleccionado los <strong>'.count($users).'</strong> usuarios de esta página.</span> ';
         echo '   <a href="#" id="select-everything-btn"><strong>Seleccionar los '.$total.' usuarios que coinciden con la búsqueda.</strong></a>';
         echo '   <span id="all-selected-msg" style="display:none;">Se han seleccionado los <strong>'.$total.'</strong> usuarios.</span>';
         echo '</div>';
    }

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
    var banner = document.getElementById('select-all-banner');
    
    for (var i = 0; i < checkboxes.length; i++) {
        checkboxes[i].checked = e.target.checked;
    }

    if (banner) {
        if (e.target.checked) {
            banner.style.display = 'block';
        } else {
             banner.style.display = 'none';
             // Reset full selection if unchecked
             document.getElementById('delete_all_matching').value = '0';
             document.getElementById('select-page-msg').style.display = 'inline';
             document.getElementById('select-everything-btn').style.display = 'inline';
             document.getElementById('all-selected-msg').style.display = 'none';
        }
    }
});

var selAllBtn = document.getElementById('select-everything-btn');
if (selAllBtn) {
    selAllBtn.addEventListener('click', function(e) {
        e.preventDefault();
        document.getElementById('delete_all_matching').value = '1';
        document.getElementById('select-page-msg').style.display = 'none';
        document.getElementById('select-everything-btn').style.display = 'none';
        document.getElementById('all-selected-msg').style.display = 'inline';
    });
}
</script>
";

echo $OUTPUT->footer();
