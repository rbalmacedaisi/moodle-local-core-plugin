<?php

namespace local_grupomakro_core\local;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/vendor/autoload.php');

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Exception;

class importer_helper {

    /**
     * Loads an Excel file and returns the spreadsheet object
     */
    public static function load_spreadsheet($filepath) {
        if (!file_exists($filepath)) {
            throw new Exception('File not found: ' . $filepath);
        }
        return IOFactory::load($filepath);
    }

    /**
     * Converts an Excel date value to Unix timestamp
     */
    public static function excel_date_to_timestamp($excelDate) {
        if (Date::isDateTime($excelDate)) {
             return Date::excelToTimestamp($excelDate) + (24 * 3600); // Adjustment from migrate.php
        }
        // Fallback or simple check if it's already a timestamp or numeric
        if (is_numeric($excelDate)) {
             return Date::excelToTimestamp($excelDate);
        }
        return time(); // Default or error? migrate.php used a specific logic
    }

    /**
     * Constructs the Student entity for core_user_external::create_users
     * Logic adapted from migrate.php construct_student_entity
     */
    public static function construct_student_entity($data) {
        // Data index mapping based on 'Estudiante-basico' sheet in migrate.php
        // [1]: DocType, [2]: DocNum/Username, [3]: ?, [4]: Name, [5]: Lastname, [6]: Email
        // [7]: Phone2, [8]: Phone1, [9]: Birthdate, [10]: Country, [11]: Address, [12]: Genre, [13]: Status, [14]: Journey

        $countryCodes = ['Panamá' => 'PA', 'Venezuela' => 'VE'];
        
        $username = strtolower(trim($data[2]));

        $studentEntity = [
            'createpassword' => 1,
            'username'       => $username,
            'firstname'      => $data[4],
            'lastname'       => $data[5],
            'email'          => $data[6],
            'auth'           => 'manual',
        ];

        if (!empty($data[10]) && isset($countryCodes[$data[10]])) {
            $studentEntity['country'] = $countryCodes[$data[10]];
        }
        if (!empty($data[8])) $studentEntity['phone1'] = $data[8]; // Phone mapping switched in migrate.php? 8 mapped to phone1
        if (!empty($data[7])) $studentEntity['phone2'] = $data[7];
        if (!empty($data[11])) $studentEntity['address'] = $data[11];

        // Custom Fields
        $customFields = [
            ['type' => 'usertype', 'value' => 'Estudiante'],
            ['type' => 'documenttype', 'value' => $data[1]],
            ['type' => 'documentnumber', 'value' => $data[2]],
            // Start migrate.php used excelDateToUnixTimestamp($data[9])
            ['type' => 'birthdate', 'value' => self::excel_date_to_timestamp($data[9])], 
            ['type' => 'studentstatus', 'value' => $data[13]],
            ['type' => 'personalemail', 'value' => $data[6]],
            ['type' => 'gmkgenre', 'value' => $data[12]],
            ['type' => 'gmkjourney', 'value' => $data[14]]
        ];

        $studentEntity['customfields'] = $customFields;
        return $studentEntity;
    }
}
