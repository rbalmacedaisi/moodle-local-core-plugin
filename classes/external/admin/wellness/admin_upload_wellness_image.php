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
 * Sube la imagen de portada de un convenio, evento o formulario dinamico.
 *
 * El panel Vue manda el fichero como data URL (base64). Se valida tipo, peso
 * y dimensiones minimas, se guarda en el filearea correspondiente y se
 * persiste la URL ABSOLUTA de pluginfile en la columna del registro, porque
 * la LXP vive en otro dominio y resuelve tal cual cualquier valor que empiece
 * por http.
 *
 * Capability: local/grupomakro_core:manage_wellness.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\admin\wellness;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

class admin_upload_wellness_image extends external_api {

    /** Tipos aceptados => extension. */
    private const MIMES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    /** Peso maximo del fichero decodificado (bytes). */
    private const MAX_BYTES = 3145728; // 3 MB

    /**
     * kind => [filearea, tabla, columna, ancho minimo, alto minimo, ancho recomendado, alto recomendado]
     */
    public const KINDS = [
        'partner' => ['wellness_partner_logo', 'gmk_wellness_partner',      'logo_path',  300, 300,  600,  600],
        'event'   => ['wellness_event_cover',  'gmk_wellness_event',        'cover_path', 600, 315, 1200,  630],
        'form'    => ['wellness_form_cover',   'gmk_wellness_dynamic_form', 'cover_path', 600, 315, 1200,  630],
    ];

    public static function execute_parameters() {
        return new external_function_parameters([
            'kind'    => new external_value(PARAM_ALPHA, 'partner|event|form', VALUE_REQUIRED),
            'itemid'  => new external_value(PARAM_INT,   'Id del registro destino', VALUE_REQUIRED),
            'content' => new external_value(PARAM_RAW,   'Data URL base64 de la imagen', VALUE_REQUIRED),
        ]);
    }

    public static function execute($kind, $itemid, $content) {
        global $DB, $USER, $CFG;

        $params = self::validate_parameters(self::execute_parameters(), [
            'kind' => $kind, 'itemid' => $itemid, 'content' => $content,
        ]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manage_wellness', $context);

        $kind = (string)$params['kind'];
        if (!isset(self::KINDS[$kind])) {
            throw new \moodle_exception('wellness_image_kind_invalid', 'local_grupomakro_core');
        }
        [$filearea, $table, $column, $minw, $minh] = self::KINDS[$kind];

        $itemid = (int)$params['itemid'];
        if ($itemid <= 0 || !$DB->record_exists($table, ['id' => $itemid])) {
            throw new \moodle_exception('wellness_image_item_invalid', 'local_grupomakro_core');
        }

        // Data URL -> binario.
        $raw = (string)$params['content'];
        if (!preg_match('#^data:([a-z/+.-]+);base64,#i', $raw, $m)) {
            throw new \moodle_exception('wellness_image_format_invalid', 'local_grupomakro_core');
        }
        $declared = strtolower($m[1]);
        $binary = base64_decode(substr($raw, strlen($m[0])), true);
        if ($binary === false || $binary === '') {
            throw new \moodle_exception('wellness_image_format_invalid', 'local_grupomakro_core');
        }
        if (strlen($binary) > self::MAX_BYTES) {
            throw new \moodle_exception('wellness_image_too_big', 'local_grupomakro_core');
        }

        // El tipo real manda sobre lo que declare el cliente.
        $info = @getimagesizefromstring($binary);
        if ($info === false || empty($info['mime']) || !isset(self::MIMES[$info['mime']])) {
            throw new \moodle_exception('wellness_image_format_invalid', 'local_grupomakro_core');
        }
        if ($declared !== $info['mime']) {
            $declared = $info['mime'];
        }
        [$width, $height] = [(int)$info[0], (int)$info[1]];
        if ($width < $minw || $height < $minh) {
            throw new \moodle_exception('wellness_image_too_small', 'local_grupomakro_core',
                '', (object)['minw' => $minw, 'minh' => $minh, 'w' => $width, 'h' => $height]);
        }

        // Nombre fijo y corto: la columna es char(255) y ahi va la URL completa.
        $filename = 'cover.' . self::MIMES[$declared];

        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'local_grupomakro_core', $filearea, $itemid);
        $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'local_grupomakro_core',
            'filearea'  => $filearea,
            'itemid'    => $itemid,
            'filepath'  => '/',
            'filename'  => $filename,
            'userid'    => (int)$USER->id,
        ], $binary);

        // Cache-buster para que el navegador no sirva la portada anterior.
        $url = \moodle_url::make_pluginfile_url($context->id, 'local_grupomakro_core',
            $filearea, $itemid, '/', $filename, false)->out(false) . '?v=' . time();

        $DB->set_field($table, $column, $url, ['id' => $itemid]);
        $DB->set_field($table, 'timemodified', time(), ['id' => $itemid]);

        return ['ok' => true, 'url' => $url, 'width' => $width, 'height' => $height];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'ok'     => new external_value(PARAM_BOOL, 'True si se guardo'),
            'url'    => new external_value(PARAM_RAW,  'URL absoluta de la portada'),
            'width'  => new external_value(PARAM_INT,  'Ancho en px'),
            'height' => new external_value(PARAM_INT,  'Alto en px'),
        ]);
    }
}
