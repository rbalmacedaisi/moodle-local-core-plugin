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
 * Audit log of every QR attendance attempt (success or refusal).
 *
 * Lives in moodledata, outside the plugin code, so deploys and manual copies of
 * the plugin directory cannot remove it. One file per month
 * (qr_attendance-YYYY-MM.log, JSON lines); old months are kept as history and
 * never truncated from the UI.
 *
 * Until 2026-10 the log was gmk_qr_attendance.log inside the plugin directory:
 * on 2026-09-09 it was moved out with a manual backup of the plugin and, as
 * www-data could not create files in that directory and the write was silenced,
 * nothing was logged until 2026-10-07.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local;

defined('MOODLE_INTERNAL') || die();

class qr_attendance_log {

    /** File name prefix of the monthly files. */
    const PREFIX = 'qr_attendance-';

    /**
     * Directory of the log files, created on demand.
     */
    public static function dir(): string {
        global $CFG;
        return make_writable_directory($CFG->dataroot . '/grupomakro_core/qr_attendance');
    }

    /**
     * File of the month a line dated $time (Y-m-d H:i:s) belongs to.
     */
    public static function file_for(string $time = ''): string {
        $month = preg_match('/^(\d{4}-\d{2})/', $time, $m) ? $m[1] : date('Y-m');
        return self::dir() . '/' . self::PREFIX . $month . '.log';
    }

    /**
     * Append one JSON line. A failed write is reported to the PHP error log
     * instead of being silently lost.
     */
    public static function write(string $line, string $time = ''): void {
        try {
            $file = self::file_for($time);
            if (@file_put_contents($file, rtrim($line, "\r\n") . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
                error_log('local_grupomakro_core: no se pudo escribir el log de asistencia QR en ' . $file);
            }
        } catch (\Throwable $e) {
            error_log('local_grupomakro_core: log de asistencia QR no disponible: ' . $e->getMessage());
        }
    }

    /**
     * Monthly files, newest first.
     *
     * @return string[]
     */
    public static function files(): array {
        $files = glob(self::dir() . '/' . self::PREFIX . '*.log') ?: [];
        rsort($files, SORT_STRING);
        return $files;
    }
}
