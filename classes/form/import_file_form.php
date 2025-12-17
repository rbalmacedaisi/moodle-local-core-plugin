<?php
namespace local_grupomakro_core\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class import_file_form extends \moodleform {
    
    public function definition() {
        $mform = $this->_form;
        $customdata = $this->_customdata;
        $filetypes = isset($customdata['filetypes']) ? $customdata['filetypes'] : '*';

        $mform->addElement('filepicker', 'importfile', 'Seleccionar Archivo', null, ['accepted_types' => $filetypes]);
        $mform->addRule('importfile', null, 'required', null, 'client');

        $this->add_action_buttons(false, 'Importar');
    }
}
