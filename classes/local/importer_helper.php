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
        // If it's a numeric Excel serial date
        if (is_numeric($excelDate)) {
             return Date::excelToTimestamp($excelDate);
        }
        // If it's a string date (YYYY-MM-DD or similar)
        if (!empty($excelDate) && ($ts = strtotime($excelDate))) {
            return $ts;
        }

        return 0; // Return 0 for empty or invalid dates
    }

    /**
     * Constructs the Student entity for core_user_external::create_users
     * Logic adapted from migrate.php construct_student_entity
     */
    public static function construct_student_entity($data) {
        global $CFG;
        // Data index mapping based on 'Estudiante-basico' sheet in migrate.php
        // [1]: DocType, [2]: DocNum/Username, [3]: ?, [4]: Name, [5]: Lastname, [6]: Email
        // [7]: Phone2, [8]: Phone1, [9]: Birthdate, [10]: Country, [11]: Address, [12]: Genre, [13]: Status, [14]: Journey

        $countryCodes = ['Panamá' => 'PA', 'Venezuela' => 'VE'];
        
        $username = strtolower(trim($data[2]));

        $studentEntity = [
            // 'createpassword' => 1, // Removed to use manual password
            'password'       => $username, // User request: Doc ID as password
            'username'       => $username,
            'firstname'      => $data[4],
            'lastname'       => $data[5],
            'email'          => $data[6],
            'idnumber'       => !empty($data[3]) ? $data[3] : '', // Column 3: Numero_ID
            'auth'           => 'manual',
            'confirmed'      => 1,
            'mnethostid'     => $CFG->mnet_localhost_id,
        ];

        if (!empty($data[10]) && isset($countryCodes[$data[10]])) {
            $studentEntity['country'] = $countryCodes[$data[10]];
        }
        if (!empty($data[8])) $studentEntity['phone1'] = $data[8]; // Phone mapping switched in migrate.php? 8 mapped to phone1
        if (!empty($data[7])) $studentEntity['phone2'] = $data[7];
        if (!empty($data[11])) $studentEntity['address'] = $data[11];

        // Custom Fields
        // Map Usertype from Column 15. Default 'Estudiante'.
        $userType = !empty($data[15]) ? $data[15] : 'Estudiante';

        // Flattened fields for internal API (profile_save_data)
        $studentEntity['profile_field_usertype'] = $userType;
        $studentEntity['profile_field_personalemail'] = $data[6];
        
        // Gender Mapping
        $rawGender = trim($data[12]);
        $mappedGender = $rawGender;
        if (stripos($rawGender, 'Hombre') !== false) {
             $mappedGender = 'Masculino';
        } elseif (stripos($rawGender, 'Mujer') !== false) {
             $mappedGender = 'Femenino';
        }
        $studentEntity['profile_field_gmkgenre'] = $mappedGender;
        
        // Document Type Mapping
        $rawDocType = trim($data[1]);
        $mappedDocType = $rawDocType;
        // Check for prefixes like "CC - ", "PP - ", "CE - "
        if (preg_match('/^(CC|PP|CE)\s*-\s*(.+)/i', $rawDocType, $matches)) {
            $mappedDocType = trim($matches[2]);
        }
        $studentEntity['profile_field_documenttype'] = $mappedDocType;
        $studentEntity['profile_field_documentnumber'] = $data[2];
        $studentEntity['profile_field_birthdate'] = self::excel_date_to_timestamp($data[9]);
        $studentEntity['profile_field_studentstatus'] = $data[13];
        $studentEntity['profile_field_gmkjourney'] = $data[14];

        // Keep legacy format just in case, though likely unused now
        $customFields = [
            ['type' => 'usertype', 'value' => $userType],
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
