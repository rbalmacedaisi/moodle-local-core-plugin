<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Cartas (20261001130): copia de un documento de carta a Odoo en segundo plano.
 * Si Odoo no confirma, la tarea falla y Moodle la reintenta con espera creciente.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\task;

use local_grupomakro_core\local\letters\manager;

defined('MOODLE_INTERNAL') || die();

class letters_odoo_copy extends \core\task\adhoc_task {

    public function execute() {
        $data = (array)$this->get_custom_data();
        $documentid = (int)($data['documentid'] ?? 0);
        if ($documentid <= 0) {
            return;
        }
        if (!manager::copy_document_to_odoo($documentid)) {
            throw new \moodle_exception('letter_err_odoo', 'local_grupomakro_core', '', $documentid);
        }
        mtrace('letters_odoo_copy: documento ' . $documentid . ' copiado a Odoo');
    }
}
