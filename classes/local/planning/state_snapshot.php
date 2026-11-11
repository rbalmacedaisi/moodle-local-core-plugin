<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Foto del estado academico y comparacion entre dos fotos.
 *
 * Red de seguridad del tablero de planificacion: antes y despues de cada accion que escribe
 * se toma una foto de todas las tablas que una publicacion o un ajuste podria tocar (clases,
 * horarios, colas, sesiones de asistencia, salas BBB, eventos de calendario, grupos, matriculas,
 * progreso de estudiantes y datos de planificacion). La comparacion dice exactamente que filas
 * se crearon, borraron o cambiaron, y en que columnas.
 *
 * Solo lectura sobre la base. Las fotos se guardan como JSON comprimido en
 * $CFG->dataroot/local_grupomakro_core/snapshots/.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Grupo Makro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\planning;

defined('MOODLE_INTERNAL') || die();

class state_snapshot {

    /**
     * Tablas vigiladas. 'where' limita filas en tablas compartidas con el resto de Moodle.
     * Las filas se guardan completas (columnas de texto largo se reducen a un hash).
     */
    public static function tables() {
        return [
            'gmk_class'                   => [],
            'gmk_class_schedules'         => [],
            'gmk_class_queue'             => [],
            'gmk_class_pre_registration'  => [],
            'gmk_bbb_attendance_relation' => [],
            'gmk_course_progre'           => [],
            'local_learning_users'        => [],
            'gmk_academic_planning'       => [],
            'gmk_planning_period_maps'    => [],
            'gmk_student_deferrals'       => [],
            'gmk_academic_deferrals'      => [],
            'gmk_academic_alerts'         => [],
            'gmk_group_curriculum'        => [],
            'gmk_academic_periods'        => [],
            'gmk_academic_calendar'       => [],
            'gmk_classrooms'              => [],
            'gmk_holidays'                => [],
            'gmk_subject_loads'           => [],
            'attendance'                  => [],
            'attendance_sessions'         => [],
            'bigbluebuttonbn'             => [],
            'event'                       => ['where' => "modulename IN ('attendance', 'bigbluebuttonbn')"],
            'course_modules'              => ['where' => "module IN (SELECT id FROM {modules} WHERE name IN ('attendance', 'bigbluebuttonbn'))"],
            'course_sections'             => ['where' => "course IN (SELECT courseid FROM {local_learning_courses})"],
            'groups'                      => ['where' => "courseid IN (SELECT courseid FROM {local_learning_courses})"],
            'groups_members'              => ['where' => "groupid IN (SELECT g.id FROM {groups} g WHERE g.courseid IN (SELECT courseid FROM {local_learning_courses}))"],
            'user_enrolments'             => ['where' => "enrolid IN (SELECT e.id FROM {enrol} e WHERE e.courseid IN (SELECT courseid FROM {local_learning_courses}))"],
            'grade_categories'            => ['where' => "courseid IN (SELECT courseid FROM {local_learning_courses})"],
        ];
    }

    /** Columnas de texto que se guardan como hash para no inflar la foto. */
    const HASHED_TEXT_MIN = 512;

    /**
     * Toma la foto.
     *
     * @return array ['meta' => ..., 'tables' => [tabla => [id => fila]]]
     */
    public static function take($label = '') {
        global $DB, $CFG;
        \core_php_time_limit::raise();
        raise_memory_limit(MEMORY_HUGE);
        $dbman = $DB->get_manager();
        $out = ['meta' => [
            'label' => (string)$label,
            'time' => time(),
            'dbname' => $CFG->dbname,
            'wwwroot' => $CFG->wwwroot,
        ], 'tables' => []];
        foreach (self::tables() as $table => $opt) {
            if (!$dbman->table_exists($table)) {
                $out['tables'][$table] = null;
                continue;
            }
            $where = $opt['where'] ?? '';
            $sql = "SELECT * FROM {" . $table . "}" . ($where ? " WHERE $where" : '') . " ORDER BY id";
            // Formato compacto: columnas una vez, filas como lista de valores.
            $cols = array_keys($DB->get_columns($table));
            $rows = [];
            $rs = $DB->get_recordset_sql($sql);
            foreach ($rs as $r) {
                $row = [];
                foreach ($cols as $k) {
                    $v = $r->$k ?? null;
                    if (is_string($v) && strlen($v) >= self::HASHED_TEXT_MIN) {
                        $v = 'sha1:' . sha1($v) . ':' . strlen($v);
                    }
                    $row[] = $v;
                }
                $rows[(string)$r->id] = $row;
            }
            $rs->close();
            $out['tables'][$table] = ['cols' => $cols, 'rows' => $rows];
        }
        return $out;
    }

    public static function dir() {
        global $CFG;
        $dir = $CFG->dataroot . '/local_grupomakro_core/snapshots';
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        return $dir;
    }

    /** Guarda la foto y devuelve la ruta. */
    public static function save(array $snap, $name = '') {
        $name = $name !== '' ? preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) : 'foto';
        $path = self::dir() . '/' . date('Ymd_His', $snap['meta']['time']) . '_' . $name . '.json.gz';
        file_put_contents($path, gzencode(json_encode($snap), 6));
        return $path;
    }

    public static function load($path) {
        if (!is_file($path)) {
            $alt = self::dir() . '/' . $path;
            if (is_file($alt)) {
                $path = $alt;
            }
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \moodle_exception('error', 'error', '', null, "No se encuentra la foto: $path");
        }
        $json = substr($path, -3) === '.gz' ? gzdecode($raw) : $raw;
        return json_decode($json, true);
    }

    /**
     * Compara dos fotos.
     *
     * @param array $a foto anterior
     * @param array $b foto posterior
     * @param array $ignorecols columnas que no cuentan como cambio (ej. ['timemodified'])
     * @return array [tabla => ['added' => [id=>fila], 'removed' => [id=>fila], 'changed' => [id => [col => [antes, despues]]]]]
     *               solo con tablas que tienen diferencias
     */
    public static function diff(array $a, array $b, array $ignorecols = []) {
        $out = [];
        $tables = array_unique(array_merge(array_keys($a['tables']), array_keys($b['tables'])));
        foreach ($tables as $t) {
            $ta = $a['tables'][$t] ?? null;
            $tb = $b['tables'][$t] ?? null;
            if ($ta === null || $tb === null) {
                continue;
            }
            $ra = self::assoc($ta);
            $rb = self::assoc($tb);
            $d = ['added' => [], 'removed' => [], 'changed' => []];
            foreach ($rb as $id => $row) {
                if (!isset($ra[$id])) {
                    $d['added'][$id] = $row;
                }
            }
            foreach ($ra as $id => $row) {
                if (!isset($rb[$id])) {
                    $d['removed'][$id] = $row;
                    continue;
                }
                $cols = [];
                foreach ($row as $k => $v) {
                    if (in_array($k, $ignorecols, true)) {
                        continue;
                    }
                    $nv = $rb[$id][$k] ?? null;
                    if ((string)$v !== (string)$nv) {
                        $cols[$k] = [$v, $nv];
                    }
                }
                if ($cols) {
                    $d['changed'][$id] = $cols;
                }
            }
            if ($d['added'] || $d['removed'] || $d['changed']) {
                $out[$t] = $d;
            }
        }
        return $out;
    }

    /** Filas de una tabla de la foto como [id => [columna => valor]]. */
    protected static function assoc(array $t) {
        $out = [];
        foreach ($t['rows'] as $id => $vals) {
            $out[$id] = array_combine($t['cols'], $vals);
        }
        return $out;
    }

    /** Numero de filas de una tabla en la foto (null si la tabla no existe). */
    public static function count_rows(array $snap, $table) {
        $t = $snap['tables'][$table] ?? null;
        return $t === null ? null : count($t['rows']);
    }

    /**
     * Revisa un diff contra lo que se esperaba que cambiara.
     *
     * @param array $diff resultado de diff()
     * @param array $expected [tabla => ['added' => bool, 'removed' => bool, 'changed' => [columnas permitidas] | true]]
     * @return string[] lista de violaciones (vacia = todo dentro de lo esperado)
     */
    public static function unexpected(array $diff, array $expected) {
        $bad = [];
        foreach ($diff as $t => $d) {
            $e = $expected[$t] ?? null;
            if ($d['added'] && empty($e['added'])) {
                $bad[] = "$t: " . count($d['added']) . " filas nuevas no esperadas";
            }
            if ($d['removed'] && empty($e['removed'])) {
                $bad[] = "$t: " . count($d['removed']) . " filas BORRADAS no esperadas";
            }
            if ($d['changed']) {
                $allowed = $e['changed'] ?? [];
                foreach ($d['changed'] as $id => $cols) {
                    if ($allowed === true) {
                        continue;
                    }
                    $extra = array_diff(array_keys($cols), (array)$allowed);
                    if ($extra) {
                        $bad[] = "$t id=$id: columnas cambiadas no esperadas: " . implode(',', $extra);
                    }
                }
            }
        }
        return $bad;
    }

    /** Resumen legible de un diff. */
    public static function summarize(array $diff, $maxdetail = 15) {
        if (!$diff) {
            return "Sin diferencias.\n";
        }
        $txt = '';
        foreach ($diff as $t => $d) {
            $txt .= sprintf("%-30s +%d  -%d  ~%d\n", $t, count($d['added']), count($d['removed']), count($d['changed']));
            $n = 0;
            foreach ($d['removed'] as $id => $row) {
                if ($n++ >= $maxdetail) {
                    break;
                }
                $txt .= "    - borrada id=$id\n";
            }
            foreach ($d['changed'] as $id => $cols) {
                if ($n++ >= $maxdetail) {
                    break;
                }
                $parts = [];
                foreach ($cols as $k => $ch) {
                    $parts[] = "$k: " . self::short($ch[0]) . ' -> ' . self::short($ch[1]);
                }
                $txt .= "    ~ id=$id " . implode('; ', $parts) . "\n";
            }
        }
        return $txt;
    }

    private static function short($v) {
        $s = $v === null ? 'NULL' : (string)$v;
        return strlen($s) > 40 ? substr($s, 0, 37) . '...' : $s;
    }
}
