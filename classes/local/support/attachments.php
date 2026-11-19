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
 * Mesa de ayuda: adjuntos del chat (imagenes, videos y PDF), pedido de Ramiro
 * del 18-nov-2026 ("que el sistema permita enviar imagenes y videos").
 *
 * Flujo:
 *  1. El cliente (portal con token, bandeja o Ayuda TI con sesion) sube cada
 *     archivo a support_upload.php, que lo valida y lo deja en el area de
 *     borrador del usuario (contexto de usuario, user/draft, itemid propio).
 *  2. send_message / create_ticket reciben ese draftitemid: attach() vuelve a
 *     validar el conjunto y copia los archivos al contexto de sistema,
 *     componente local_grupomakro_core, filearea tkt_attachment,
 *     itemid = id del mensaje. gmk_tkt_message.hasattachments = cantidad.
 *  3. support_file.php los sirve con el mismo control de acceso que el ticket
 *     (solicitante, personal que puede verlo; las notas internas nunca al
 *     solicitante; Psicologia confidencial sigue su regla).
 *
 * Validacion: extension permitida (tkt_attach_types), tipo real por los
 * primeros bytes (no por la extension ni el mimetype del navegador), tamano
 * por clase (imagen/PDF tkt_attach_maxmb, video tkt_attach_video_maxmb, nunca
 * mas que el limite de PHP del servidor) y tkt_attach_maxfiles por mensaje.
 *
 * Privacidad: notificaciones, listados e indicador nunca llevan el archivo ni
 * su nombre: solo "📎 Adjunto" o "🎬 Video".
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\support;

use moodle_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

class attachments {

    const COMPONENT = 'local_grupomakro_core';
    const FILEAREA = 'tkt_attachment';

    /** extension => [kind, mimetype] */
    const TYPES = [
        'jpg' => ['image', 'image/jpeg'], 'jpeg' => ['image', 'image/jpeg'], 'png' => ['image', 'image/png'],
        'webp' => ['image', 'image/webp'], 'gif' => ['image', 'image/gif'], 'heic' => ['image', 'image/heic'],
        'heif' => ['image', 'image/heif'],
        'mp4' => ['video', 'video/mp4'], 'm4v' => ['video', 'video/mp4'], 'mov' => ['video', 'video/quicktime'],
        'webm' => ['video', 'video/webm'],
        'pdf' => ['file', 'application/pdf'],
    ];

    /**
     * Effective limits (config + server).
     *
     * @return array {types string[], maxfiles int, imagebytes int, videobytes int, serverbytes int, accept string}
     */
    public static function limits(): array {
        global $CFG;
        $types = [];
        foreach (explode(',', strtolower((string)config::get('tkt_attach_types'))) as $t) {
            $t = trim(ltrim(trim($t), '.'));
            if ($t !== '' && isset(self::TYPES[$t])) {
                $types[] = $t;
            }
        }
        $types = array_values(array_unique($types));
        $server = (int)get_max_upload_file_size($CFG->maxbytes ?? 0);
        $mb = function(string $name) use ($server) {
            $bytes = max(0, config::get_int($name)) * 1024 * 1024;
            return $server > 0 ? min($bytes, $server) : $bytes;
        };
        $accept = [];
        foreach ($types as $t) {
            $accept[] = self::TYPES[$t][1];
            $accept[] = '.' . $t;
        }
        return [
            'types' => $types,
            'maxfiles' => max(0, config::get_int('tkt_attach_maxfiles')),
            'imagebytes' => $mb('tkt_attach_maxmb'),
            'videobytes' => $mb('tkt_attach_video_maxmb'),
            'serverbytes' => $server,
            'accept' => implode(',', array_values(array_unique($accept))),
        ];
    }

    public static function enabled(): bool {
        $l = self::limits();
        return $l['maxfiles'] > 0 && !empty($l['types']);
    }

    public static function extension(string $filename): string {
        return strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    }

    /**
     * Real type from the first bytes. Returns the canonical extension family
     * ('jpg', 'png', 'gif', 'webp', 'heic', 'mp4', 'mov', 'webm', 'pdf') or ''.
     */
    public static function sniff(string $head): string {
        if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) {
            return 'jpg';
        }
        if (strncmp($head, "\x89PNG\r\n\x1A\n", 8) === 0) {
            return 'png';
        }
        if (strncmp($head, 'GIF87a', 6) === 0 || strncmp($head, 'GIF89a', 6) === 0) {
            return 'gif';
        }
        if (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') {
            return 'webp';
        }
        if (strncmp($head, '%PDF-', 5) === 0) {
            return 'pdf';
        }
        if (strncmp($head, "\x1A\x45\xDF\xA3", 4) === 0) {
            return 'webm';
        }
        if (in_array(substr($head, 4, 4), ['moov', 'mdat', 'wide', 'free', 'skip', 'pnot'], true)) {
            return 'mov'; // Older QuickTime files start with an atom other than ftyp.
        }
        if (substr($head, 4, 4) === 'ftyp') {
            $brand = substr($head, 8, 4);
            if (in_array($brand, ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'mif1', 'msf1', 'avif'], true)) {
                return $brand === 'avif' ? '' : 'heic';
            }
            if ($brand === 'qt  ') {
                return 'mov';
            }
            return 'mp4'; // isom, mp41, mp42, M4V, 3gp4, dash...
        }
        return '';
    }

    /**
     * Families that each extension accepts as real content.
     */
    private static function compatible(string $ext, string $sniffed): bool {
        $family = ['jpg' => 'jpg', 'jpeg' => 'jpg', 'png' => 'png', 'gif' => 'gif', 'webp' => 'webp',
            'heic' => 'heic', 'heif' => 'heic', 'mp4' => 'mp4|mov', 'm4v' => 'mp4|mov', 'mov' => 'mov|mp4',
            'webm' => 'webm', 'pdf' => 'pdf'];
        return isset($family[$ext]) && in_array($sniffed, explode('|', $family[$ext]), true);
    }

    /**
     * Validates one file. Throws moodle_exception with a message for the user.
     *
     * @return array {kind, mimetype, ext}
     */
    public static function validate(string $filename, int $size, string $head, ?array $limits = null): array {
        $limits = $limits ?? self::limits();
        $ext = self::extension($filename);
        if ($ext === '' || !in_array($ext, $limits['types'], true) || !isset(self::TYPES[$ext])) {
            throw new moodle_exception('tkt_err_attachtype', 'local_grupomakro_core', '',
                implode(', ', $limits['types']));
        }
        $sniffed = self::sniff($head);
        if (!self::compatible($ext, $sniffed)) {
            throw new moodle_exception('tkt_err_attachcontent', 'local_grupomakro_core', '', s($filename));
        }
        [$kind, $mime] = self::TYPES[$ext];
        $max = $kind === 'video' ? $limits['videobytes'] : $limits['imagebytes'];
        if ($size <= 0) {
            throw new moodle_exception('tkt_err_attachempty', 'local_grupomakro_core');
        }
        if ($max > 0 && $size > $max) {
            throw new moodle_exception('tkt_err_attachsize', 'local_grupomakro_core', '',
                (object)['name' => s($filename), 'max' => display_size($max)]);
        }
        return ['kind' => $kind, 'mimetype' => $mime, 'ext' => $ext];
    }

    public static function validate_stored(\stored_file $file, ?array $limits = null): array {
        $handle = $file->get_content_file_handle();
        $head = $handle ? (string)fread($handle, 32) : '';
        if ($handle) {
            fclose($handle);
        }
        return self::validate($file->get_filename(), (int)$file->get_filesize(), $head, $limits);
    }

    /**
     * Files in $userid's draft area $itemid (never another user's).
     *
     * @return \stored_file[]
     */
    public static function draft_files(int $userid, int $itemid): array {
        if ($itemid <= 0 || $userid <= 0) {
            return [];
        }
        $fs = get_file_storage();
        $context = \context_user::instance($userid);
        return array_values($fs->get_area_files($context->id, 'user', 'draft', $itemid, 'id', false));
    }

    /**
     * Moves the draft files of $userid into message $message. Call inside the
     * send/create transaction, after the message row exists.
     *
     * @return int files attached
     */
    public static function attach(stdClass $message, int $userid, int $draftitemid): int {
        global $DB;
        $files = self::draft_files($userid, $draftitemid);
        if (!$files) {
            return 0;
        }
        self::check_set($files);
        $fs = get_file_storage();
        $syscontext = \context_system::instance();
        $used = [];
        foreach ($files as $file) {
            $info = self::validate_stored($file);
            $name = clean_filename($file->get_filename());
            $base = pathinfo($name, PATHINFO_FILENAME);
            $n = 1;
            while (isset($used[$name])) {
                $name = $base . '-' . (++$n) . '.' . $info['ext'];
            }
            $used[$name] = true;
            $fs->create_file_from_storedfile([
                'contextid' => $syscontext->id,
                'component' => self::COMPONENT,
                'filearea' => self::FILEAREA,
                'itemid' => (int)$message->id,
                'filepath' => '/',
                'filename' => $name,
                'mimetype' => $info['mimetype'],
                'userid' => $userid,
            ], $file);
        }
        foreach ($files as $file) {
            $file->delete();
        }
        $DB->set_field('gmk_tkt_message', 'hasattachments', count($files), ['id' => (int)$message->id]);
        $message->hasattachments = count($files);
        return count($files);
    }

    /**
     * Count and per-file validation of a whole draft set (before writing anything).
     *
     * @param \stored_file[] $files
     */
    public static function check_set(array $files, ?array $limits = null): void {
        $limits = $limits ?? self::limits();
        if (!$files) {
            return;
        }
        if ($limits['maxfiles'] <= 0) {
            throw new moodle_exception('tkt_err_attachdisabled', 'local_grupomakro_core');
        }
        if (count($files) > $limits['maxfiles']) {
            throw new moodle_exception('tkt_err_attachcount', 'local_grupomakro_core', '', $limits['maxfiles']);
        }
        foreach ($files as $file) {
            self::validate_stored($file, $limits);
        }
    }

    /**
     * Attachments of the given messages, exported for the chat.
     *
     * @param int[] $messageids
     * @return array messageid => [attachment...]
     */
    public static function for_messages(array $messageids): array {
        global $DB;
        $messageids = array_values(array_filter(array_map('intval', $messageids)));
        if (!$messageids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($messageids, SQL_PARAMS_NAMED, 'am');
        $params += ['ctx' => \context_system::instance()->id, 'comp' => self::COMPONENT, 'area' => self::FILEAREA];
        $rows = $DB->get_records_select('files',
            "contextid = :ctx AND component = :comp AND filearea = :area AND itemid $in AND filename <> '.'",
            $params, 'itemid, id', 'id, itemid, filename, filesize, mimetype');
        $base = (new \moodle_url('/local/grupomakro_core/support_file.php'))->out(false);
        $out = [];
        foreach ($rows as $r) {
            $ext = self::extension((string)$r->filename);
            $kind = self::TYPES[$ext][0] ?? 'file';
            $out[(int)$r->itemid][] = [
                'id' => (int)$r->id,
                'filename' => (string)$r->filename,
                'filesize' => (int)$r->filesize,
                'mimetype' => (string)$r->mimetype,
                'kind' => $kind,
                // The portal appends &token=...; Moodle pages use the session.
                'url' => $base . '?id=' . (int)$r->id,
            ];
        }
        return $out;
    }

    /**
     * Short marker for notifications and lists: never the file or its name.
     */
    public static function marker(int $messageid): string {
        $files = self::for_messages([$messageid])[$messageid] ?? [];
        if (!$files) {
            return '';
        }
        foreach ($files as $f) {
            if ($f['kind'] === 'video') {
                return '🎬 Video';
            }
        }
        return '📎 Adjunto';
    }

    /**
     * Stored file $fileid if $userid may read it, else null. Same rule as the
     * ticket: requester (never internal notes) or staff that can see it.
     */
    public static function file_for(int $fileid, int $userid): ?\stored_file {
        global $DB;
        $fs = get_file_storage();
        $file = $fs->get_file_by_id($fileid);
        if (!$file || $file->is_directory() || $file->get_component() !== self::COMPONENT
                || $file->get_filearea() !== self::FILEAREA
                || (int)$file->get_contextid() !== (int)\context_system::instance()->id) {
            return null;
        }
        $message = $DB->get_record('gmk_tkt_message', ['id' => (int)$file->get_itemid()]);
        if (!$message || !empty($message->deleted)) {
            return null;
        }
        try {
            [, $role] = manager::require_ticket((int)$message->ticketid, $userid);
        } catch (moodle_exception $e) {
            return null;
        }
        if ($role === manager::ROLE_REQUESTER && !empty($message->internal)) {
            return null;
        }
        return $file;
    }
}
