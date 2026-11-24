<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Perfil del estudiante: lo editado en Moodle pasa a Odoo (20261001134).
 *
 * Odoo carga el perfil en Moodle (moodle_user_sync: core_user_update_users y el
 * enroll de Odoo para periodo_ingreso). Esta clase hace el camino de vuelta con
 * los mismos campos, solo cuando alguien guarda el perfil desde las paginas de
 * edicion de Moodle (user/editadvanced.php, user/edit.php):
 *
 *   Moodle                      Odoo res.partner                 Odoo moodle.user
 *   firstname + lastname        name                             name
 *   email                       email                            email
 *   birthdate (perfil)          birthdate                        birthDate
 *   documenttype (perfil)       x_studio_tipo_de_documento (CC)  documentType
 *   documentnumber (perfil)     vat                              documentNumber
 *   custom_phone (perfil)       phone                            phone
 *   studentstatus (perfil)      x_studio_student_status          studentstatus
 *   gmkjourney (perfil)         x_studio_jornada                 gmkjourney
 *   periodo_ingreso (perfil)    x_studio_periodo_de_matricula    enrollment_period
 *
 * No viajan: idnumber (numero_expediente es de solo lectura en Odoo), estado
 * financiero (lo calcula Odoo) y genero (res.partner no tiene ese campo).
 *
 * Solo se envian los campos que cambiaron respecto de la ultima foto conocida
 * (gmk_odoo_profile_sync.snapshot), para no pisar en Odoo datos que se hayan
 * cambiado alla y aun no se hayan cargado a Moodle. Las actualizaciones que
 * llegan por web service (las que manda Odoo) solo refrescan la foto: asi no
 * hay bucle.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local;

defined('MOODLE_INTERNAL') || die();

class odoo_profile_sync {

    /** Campos que viajan (clave interna => descripcion). */
    const FIELDS = ['name', 'email', 'birthdate', 'documenttype', 'documentnumber', 'phone',
        'studentstatus', 'journey', 'enrollmentperiod'];

    /** Tipo de documento en Moodle => codigo de la seleccion en Odoo. */
    const DOC_TYPES = [
        'Cédula de Ciudadanía' => 'CC',
        'Cédula de Extranjería' => 'CE',
        'Pasaporte' => 'PP',
        'Carnet Diplomático' => 'CD',
    ];

    /** Estados del estudiante que existen en Odoo ('inactivo' es solo de Moodle). */
    const ODOO_STATUSES = ['activo', 'aplazado', 'retirado', 'suspendido', 'desertor', 'graduado', 'egresado'];

    /** Paginas de edicion de perfil cuyo guardado se envia a Odoo. */
    const EDIT_SCRIPTS = ['/user/editadvanced.php', '/user/edit.php'];

    const LOG = '/local_grupomakro_core/logs/odoo_profile_sync.log';

    /**
     * Observer de \core\event\user_updated.
     */
    public static function on_user_updated(\core\event\user_updated $event): void {
        try {
            self::handle((int)$event->relateduserid, (int)$event->userid);
        } catch (\Throwable $e) {
            // Nunca romper el guardado del perfil por la sincronizacion.
            self::log('ERROR observer u' . (int)$event->relateduserid . ': ' . $e->getMessage());
        }
    }

    /**
     * Observer de \core\event\user_created: deja la foto inicial.
     */
    public static function on_user_created(\core\event\user_created $event): void {
        try {
            $userid = (int)$event->relateduserid;
            if (self::is_student($userid)) {
                self::save_snapshot($userid, self::current_values($userid));
            }
        } catch (\Throwable $e) {
            self::log('ERROR observer created u' . (int)$event->relateduserid . ': ' . $e->getMessage());
        }
    }

    /**
     * Decide si el cambio viaja a Odoo o solo refresca la foto.
     */
    public static function handle(int $userid, int $actorid): void {
        if ($userid <= 0 || !self::is_student($userid)) {
            return;
        }
        $current = self::current_values($userid);
        if (!self::is_profile_edit_request()) {
            // Cambio que vino de Odoo (web service) o de un proceso interno: solo la foto.
            self::save_snapshot($userid, $current);
            return;
        }
        $row = self::get_row($userid);
        $snapshot = $row ? (json_decode((string)$row->snapshot, true) ?: []) : [];
        $changes = [];
        foreach (self::FIELDS as $f) {
            $new = (string)($current[$f] ?? '');
            $old = (string)($snapshot[$f] ?? '');
            if ($new !== $old && $new !== '') {
                $changes[$f] = $new;
            }
        }
        if (!$changes) {
            return;
        }
        $task = new \local_grupomakro_core\task\sync_profile_to_odoo();
        $task->set_custom_data([
            'userid' => $userid,
            'actorid' => $actorid,
            'changes' => $changes,
            'previousdocumentnumber' => (string)($snapshot['documentnumber'] ?? $current['documentnumber'] ?? ''),
        ]);
        $task->set_component('local_grupomakro_core');
        \core\task\manager::queue_adhoc_task($task);
        self::log("ENCOLADO u$userid por u$actorid: " . implode(', ', array_keys($changes)));
    }

    /**
     * Envia los cambios al proxy. Devuelve true si Odoo los acepto o si no hay
     * contacto (no tiene sentido reintentar); lanza en errores transitorios.
     */
    public static function send(int $userid, int $actorid, array $changes, string $previousdoc): bool {
        $payload = [
            'moodleUserId' => $userid,
            'previousDocumentNumber' => $previousdoc,
            'actor' => $actorid,
            'fields' => self::to_odoo($changes),
        ];
        if (empty($payload['fields'])) {
            self::log("OMITIDO u$userid: ningun campo con valor valido para Odoo");
            self::merge_snapshot($userid, $changes, 'skipped');
            return true;
        }
        $res = wdr_manager::call_odoo_proxy('POST', '/api/odoo/profile/sync-from-moodle', [], $payload);
        $status = (int)$res['status'];
        $body = $res['body'] ?? [];
        if ($status === 200 && !empty($body['success'])) {
            self::merge_snapshot($userid, $changes, 'ok');
            self::log("OK u$userid partner " . ($body['partnerId'] ?? '?') . ': ' . implode(', ', array_keys($payload['fields'])));
            return true;
        }
        if ($status === 422) {
            $detail = (string)($body['detail'] ?? 'odoo_rejected');
            self::merge_snapshot($userid, $changes, 'rechazado: ' . substr($detail, 0, 200));
            self::log("RECHAZADO POR ODOO u$userid: $detail (no se reintenta)");
            return true;
        }
        if ($status === 404) {
            self::merge_snapshot($userid, $changes, 'not_found');
            self::log("SIN CONTACTO u$userid en Odoo (doc {$previousdoc}): no se reintenta");
            return true;
        }
        $err = $res['error'] ?? ($body['error'] ?? ('http ' . $status));
        self::set_status($userid, 'error: ' . substr((string)$err, 0, 200));
        self::log("FALLO u$userid: $err (se reintenta)");
        throw new \moodle_exception('error', 'moodle', '', null, 'odoo_profile_sync u' . $userid . ': ' . $err);
    }

    /**
     * Valores actuales del perfil en el formato de self::FIELDS.
     */
    public static function current_values(int $userid): array {
        global $DB;
        $u = $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname, email', MUST_EXIST);
        $pf = [];
        $rows = $DB->get_records_sql("SELECT f.shortname, d.data FROM {user_info_data} d
                JOIN {user_info_field} f ON f.id = d.fieldid
               WHERE d.userid = ? AND f.shortname IN ('birthdate','documenttype','documentnumber','custom_phone',
                     'studentstatus','gmkjourney','periodo_ingreso')", [$userid]);
        foreach ($rows as $r) {
            $pf[$r->shortname] = trim((string)$r->data);
        }
        $birth = '';
        if (!empty($pf['birthdate']) && is_numeric($pf['birthdate']) && (int)$pf['birthdate'] > -2000000000
                && (int)$pf['birthdate'] !== 0) {
            $birth = (new \DateTime('@' . (int)$pf['birthdate']))
                ->setTimezone(new \DateTimeZone('America/Panama'))->format('Y-m-d');
        }
        return [
            'name' => trim(preg_replace('/\s+/', ' ', $u->firstname . ' ' . $u->lastname)),
            'email' => trim((string)$u->email),
            'birthdate' => $birth,
            'documenttype' => $pf['documenttype'] ?? '',
            'documentnumber' => $pf['documentnumber'] ?? '',
            'phone' => $pf['custom_phone'] ?? '',
            'studentstatus' => $pf['studentstatus'] ?? '',
            'journey' => $pf['gmkjourney'] ?? '',
            'enrollmentperiod' => $pf['periodo_ingreso'] ?? '',
        ];
    }

    /**
     * Traduce a los campos que espera el proxy, descartando valores que Odoo no admite.
     */
    public static function to_odoo(array $changes): array {
        $out = [];
        foreach ($changes as $k => $v) {
            switch ($k) {
                case 'documenttype':
                    if (isset(self::DOC_TYPES[$v])) {
                        $out['documentType'] = self::DOC_TYPES[$v];
                        $out['documentTypeName'] = $v;
                    }
                    break;
                case 'studentstatus':
                    $s = \core_text::strtolower($v);
                    if (in_array($s, self::ODOO_STATUSES, true)) {
                        $out['studentStatus'] = $s;
                    }
                    break;
                case 'birthdate':
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                        $out['birthdate'] = $v;
                    }
                    break;
                case 'email':
                    if (validate_email($v)) {
                        $out['email'] = $v;
                    }
                    break;
                case 'name': $out['name'] = $v; break;
                case 'documentnumber': $out['documentNumber'] = $v; break;
                case 'phone': $out['phone'] = $v; break;
                case 'journey': $out['journey'] = $v; break;
                case 'enrollmentperiod': $out['enrollmentPeriod'] = $v; break;
            }
        }
        return $out;
    }

    public static function is_student(int $userid): bool {
        global $DB;
        $type = $DB->get_field_sql("SELECT d.data FROM {user_info_data} d JOIN {user_info_field} f ON f.id = d.fieldid
                                     WHERE d.userid = ? AND f.shortname = 'usertype'", [$userid]);
        return trim((string)$type) === 'Estudiante';
    }

    /**
     * Guardado hecho por una persona desde las paginas de edicion de perfil
     * (no web service, no CLI, no tarea).
     */
    public static function is_profile_edit_request(): bool {
        if (defined('WS_SERVER') || CLI_SCRIPT || (defined('AJAX_SCRIPT') && AJAX_SCRIPT)) {
            return false;
        }
        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        foreach (self::EDIT_SCRIPTS as $s) {
            if (substr($script, -strlen($s)) === $s) {
                return true;
            }
        }
        return false;
    }

    public static function get_row(int $userid) {
        global $DB;
        return $DB->get_record('gmk_odoo_profile_sync', ['userid' => $userid]);
    }

    public static function save_snapshot(int $userid, array $values, string $status = null): void {
        global $DB;
        $row = self::get_row($userid);
        $rec = (object)['userid' => $userid, 'snapshot' => json_encode($values, JSON_UNESCAPED_UNICODE),
            'timemodified' => time()];
        if ($status !== null) {
            $rec->laststatus = $status;
            $rec->lastsync = time();
        }
        if ($row) {
            $rec->id = $row->id;
            $DB->update_record('gmk_odoo_profile_sync', $rec);
        } else {
            $rec->laststatus = $rec->laststatus ?? '';
            $rec->lastsync = $rec->lastsync ?? 0;
            $DB->insert_record('gmk_odoo_profile_sync', $rec);
        }
    }

    private static function merge_snapshot(int $userid, array $changes, string $status): void {
        $row = self::get_row($userid);
        $snap = $row ? (json_decode((string)$row->snapshot, true) ?: []) : [];
        self::save_snapshot($userid, array_merge($snap, $changes), $status);
    }

    private static function set_status(int $userid, string $status): void {
        global $DB;
        $row = self::get_row($userid);
        if ($row) {
            $DB->update_record('gmk_odoo_profile_sync', (object)['id' => $row->id, 'laststatus' => $status,
                'lastsync' => time()]);
        }
    }

    public static function log(string $msg): void {
        global $CFG;
        $file = $CFG->dataroot . self::LOG;
        @file_put_contents($file, date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
    }
}
