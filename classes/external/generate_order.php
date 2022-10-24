<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Class definition for the local_grupomakro_generate_order external function.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external;

use stdClass;
use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once $CFG->libdir . '/externallib.php';

/**
 * External function 'local_grupomakro_generate_order' implementation.
 *
 * @package     local_soluttolms_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generate_order extends external_api {

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'userid' => new external_value(PARAM_INT, 'The id of the user.'),
                'itemtype' => new external_value(PARAM_TEXT, 'The type of the item: for example "tuition".'),
                'itemname' => new external_value(PARAM_TEXT, 'The name of the item: for example "Tuition for course 1".'),
            ]
        );
    }

    /**
     * TODO describe what the function actually does.
     *
     * @param int $userid
     * @return mixed TODO document
     */
    public static function execute(int $userid, string $itemtype, string $itemname) {

        // Global variables.
        global $DB, $CFG, $USER;

        // Validate the parameters passed to the function.
        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
            'itemtype' => $itemtype,
            'itemname' => $itemname,
        ]);

        // Get the user's timezone.
        $timezone = $DB->get_field('user', 'timezone', ['id' => $userid]);

        // Let's generate the oid.
        $oid = uniqid($params['userid'].'-'.$params['itemid'].'-');

        // Get the following config settings from the Grupomakro Core plugin:
        // - tuitionfee
        // - tuitionfee_discount
        // - currency

        // Get the tuition fee.
        $tuitionfee = get_config('local_grupomakro_core', 'tuitionfee').'.00';

        // Get the tuition fee discount.
        $tuitionfee_discount = get_config('local_grupomakro_core', 'tuitionfee_discount');

        // Get the currency.
        $currency = get_config('local_grupomakro_core', 'currency');

        // If the tuitionfee_discount is not empty, then apply the discount.
        if (!empty($tuitionfee_discount)) {
            $tuitionfee = $tuitionfee - ($tuitionfee * $tuitionfee_discount / 100);
        }

        // Let's define a response array.
        $response = [
            'chargetotal' => $tuitionfee,
            'checkoutoption' => 'combinedpage',
            'currency' => $currency,
            'hash_algorithm' => 'HMACSHA256',
            'oid' => $oid,
            'responseFailURL' => $CFG->wwwroot . '/local/grupomakro_core/callbacks/fail.php',
            'responseSuccessURL' => $CFG->wwwroot . '/local/grupomakro_core/callbacks/success.php',
            'storename' => '8112000000114',
            'timezone' => $timezone,
            'txndatetime' => date('Y:m:d-H:i:s', time()),
            'txntype' => 'sale',
            'hash' => '',
        ];

        // Let's generate the hash.
        foreach ($response as $key => $value) {
            if ($key != 'hash') {
                $response['hash'] .= $value . '|';
            }
        }

        // Delete the last pipe.
        $response['hash'] = substr($response['hash'], 0, -1);
        $response['hash'] = hash_hmac('sha256', $response['hash'], 'cP9!?9syGF');

        // Codify the hash in base64.
        $response['hash'] = base64_encode($response['hash']);

        // Convert the response in a json.
        $response = json_encode($response);

        // Let's create the record in the gmk_orders table.
        $record = new stdClass();
        $record->userid = $params['userid'];
        $record->oid = $oid;
        $record->itemtype = $params['itemtype'];
        $record->itemname = $params['itemname'];
        $record->timecreated = time();
        $record->timemodified = time();
        $record->status = 'pending';
        $record->amount = $tuitionfee;
        $record->usermodified = $USER->id;

        $record->id = $DB->insert_record('gmk_orders', $record);

        // Return the result.
        return ['response' => $response];
    }

    /**
     * Describes the return value of the {@see self::execute()} method.
     *
     * @return external_description
     */
    public static function execute_returns(): external_description {
        return new external_single_structure(
            array(
                'response0' => new external_value(PARAM_RAW, 'A JSON string with all the data.'),
            )
        );
    }
}
