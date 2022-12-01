<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Form - File Manager for learning plan image
 *
 * @package     local_sc_learningplans
 * @copyright   2022 Solutto <nicolas.castillo@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once("$CFG->libdir/formslib.php");

class createcontract_form extends moodleform {
    public function definition() {
        $mform = $this->_form; // Don't forget the underscore!
        $maxbytes = 100000;
        $mform->addElement(
            'filemanager',
            'identification_document',
            null,
            array('subdirs' => 0, 'maxbytes' => $maxbytes, 'maxfiles' => 1, 'accepted_types' => '.jpg', '.png', '.svg', 'jpeg')
        );
        $mform->disable_form_change_checker();
    }
}

class createlp_form_editor extends moodleform {
    public function definition() {
        $mform = $this->_form;
        $mform->addElement('editor', 'desc_plan');
        $mform->setType('desc_plan', PARAM_RAW);

        $mform->disable_form_change_checker();
    }
}
