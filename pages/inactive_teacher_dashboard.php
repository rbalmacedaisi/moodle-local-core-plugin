<?php
/**
 * Inactive Teacher Dashboard
 * Displayed when a recognized teacher has no currently active classes.
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/inactive_teacher_dashboard.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Estado Docente');
$PAGE->set_heading('Estado Docente');
$PAGE->set_pagelayout('standard');
$PAGE->add_body_class('gmk-full-frame');

// Reuse the teacher experience styles for consistency
$PAGE->requires->css(new moodle_url('/local/grupomakro_core/styles/teacher_experience.css'));
$PAGE->requires->css(new moodle_url('https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css'));

echo $OUTPUT->header();

$user_fullname = fullname($USER);
$profile_url = new moodle_url('/user/profile.php', ['id' => $USER->id]);
$logout_url = new moodle_url('/login/logout.php', ['sesskey' => sesskey()]);

?>

<style>
    .inactive-dashboard-container {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-height: 60vh;
        text-align: center;
        padding: 2rem;
        background-color: #f5f5f5;
        border-radius: 12px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        max-width: 800px;
        margin: 2rem auto;
    }
    
    .inactive-icon {
        font-size: 6rem;
        color: #9e9e9e; /* Grey for inactive */
        margin-bottom: 1rem;
    }
    
    .inactive-title {
        font-size: 2rem;
        font-weight: bold;
        color: #333;
        margin-bottom: 0.5rem;
    }
    
    .inactive-message {
        font-size: 1.2rem;
        color: #666;
        margin-bottom: 2rem;
        max-width: 600px;
    }
    
    .action-buttons {
        display: flex;
        gap: 1.5rem;
    }
    
    .btn-gmk {
        padding: 0.8rem 2rem;
        border-radius: 8px;
        text-decoration: none;
        font-weight: 600;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .btn-profile {
        background-color: #1976D2;
        color: white;
    }
    
    .btn-profile:hover {
        background-color: #1565C0;
        color: white;
        text-decoration: none;
    }
    
    .btn-logout {
        background-color: #D32F2F;
        color: white;
    }
    
    .btn-logout:hover {
        background-color: #C62828;
        color: white;
        text-decoration: none;
    }

    /* Moodle override */
    #page-header { display: none; }
</style>

<div class="inactive-dashboard-container">
    <i class="mdi mdi-school-off inactive-icon"></i>
    
    <h1 class="inactive-title">Hola, <?php echo $user_fullname; ?></h1>
    
    <p class="inactive-message">
        Actualmente no tienes clases activas asignadas en este periodo. <br>
        Si crees que esto es un error, por favor contacta al administrador académico.
    </p>
    
    <div class="action-buttons">
        <a href="<?php echo $profile_url; ?>" class="btn-gmk btn-profile">
            <i class="mdi mdi-account-circle"></i> Ir a mi Perfil
        </a>
        
        <a href="<?php echo $logout_url; ?>" class="btn-gmk btn-logout">
            <i class="mdi mdi-logout"></i> Cerrar Sesión
        </a>
    </div>
</div>

<?php
echo $OUTPUT->footer();
