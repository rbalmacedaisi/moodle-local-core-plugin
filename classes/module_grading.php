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
 * Revisión y calificación de entregas de módulos independientes
 * (pages/module_management.php).
 *
 * Un módulo es una gmk_class con is_module=1. Sus actividades viven en la
 * sección de curso de la clase (coursesectionid); la categoría de notas de la
 * clase suele estar vacía, así que se resuelven primero por categoría y, si
 * no hay nada, por sección (mismo criterio que get_module_activities).
 *
 * @package     local_grupomakro_core
 * @copyright   2026 Solutto Consulting
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core;

defined('MOODLE_INTERNAL') || die();

class module_grading {

    /** Estados de entrega de una inscripción (resumen). */
    const SUMMARY_PENDING   = 'pending';    // Entregó y falta calificar.
    const SUMMARY_GRADED    = 'graded';     // Todas las actividades calificadas.
    const SUMMARY_PARTIAL   = 'partial';    // Alguna calificada, otra sin entrega.
    const SUMMARY_NONE      = 'none';       // Sin entrega ni nota.

    /**
     * Grade items calificables del módulo (sin asistencia).
     *
     * @param \stdClass $class gmk_class con id, corecourseid, gradecategoryid, coursesectionid
     * @return array lista de objetos grade_item (filas) con cmid cuando es actividad
     */
    public static function get_items(\stdClass $class): array {
        global $DB;
        $items = [];
        if ((int)$class->corecourseid > 0 && (int)$class->gradecategoryid > 0) {
            $items = $DB->get_records_sql(
                "SELECT gi.id, gi.itemname, gi.itemtype, gi.itemmodule, gi.iteminstance,
                        gi.grademax, gi.courseid
                   FROM {grade_items} gi
                  WHERE gi.courseid = :cid
                    AND gi.categoryid = :catid
                    AND gi.itemtype IN ('mod', 'manual')
                    AND COALESCE(gi.itemmodule, '') <> 'attendance'
               ORDER BY gi.sortorder ASC, gi.id ASC",
                ['cid' => (int)$class->corecourseid, 'catid' => (int)$class->gradecategoryid]
            );
        }
        if (empty($items) && (int)$class->coursesectionid > 0) {
            $items = $DB->get_records_sql(
                "SELECT gi.id, gi.itemname, gi.itemtype, gi.itemmodule, gi.iteminstance,
                        gi.grademax, gi.courseid
                   FROM {course_modules} cm
                   JOIN {modules} m ON m.id = cm.module
                   JOIN {grade_items} gi ON gi.itemmodule = m.name
                                        AND gi.iteminstance = cm.instance
                                        AND gi.courseid = cm.course
                  WHERE cm.section = :sectionid
                    AND cm.deletioninprogress = 0
                    AND gi.itemtype = 'mod'
                    AND m.name <> 'attendance'
               ORDER BY gi.sortorder ASC, gi.id ASC",
                ['sectionid' => (int)$class->coursesectionid]
            );
        }
        foreach ($items as $it) {
            $it->cmid = 0;
            if ($it->itemtype === 'mod' && !empty($it->itemmodule)) {
                $cm = get_coursemodule_from_instance($it->itemmodule, (int)$it->iteminstance,
                    (int)$it->courseid, false, IGNORE_MISSING);
                if ($cm) {
                    $it->cmid = (int)$cm->id;
                }
                if (trim((string)$it->itemname) === '') {
                    $it->itemname = (string)$DB->get_field($it->itemmodule, 'name', ['id' => (int)$it->iteminstance]);
                }
            }
            if (trim((string)$it->itemname) === '') {
                $it->itemname = 'Actividad';
            }
        }
        return array_values($items);
    }

    /**
     * Estado de una actividad para un estudiante (sin archivos).
     */
    public static function item_state(\stdClass $item, int $userid): array {
        global $DB;
        $state = [
            'itemid'          => (int)$item->id,
            'cmid'            => (int)$item->cmid,
            'name'            => (string)$item->itemname,
            'module'          => (string)($item->itemmodule ?? ''),
            'grademax'        => (float)$item->grademax,
            'submission'      => 'none',   // none|new|draft|submitted|reopened
            'submittedat'     => 0,
            'submissionid'    => 0,
            'attemptnumber'   => 0,
            'grade'           => null,     // 0-100
            'gradedat'        => 0,
            'needsgrading'    => false,
            'overridden'      => false,
        ];

        $gg = $DB->get_record('grade_grades', ['itemid' => (int)$item->id, 'userid' => $userid],
            'id, finalgrade, overridden, timemodified');
        if ($gg && $gg->finalgrade !== null && (float)$item->grademax > 0) {
            $state['grade'] = min(round(((float)$gg->finalgrade / (float)$item->grademax) * 100, 2), 100.0);
            $state['gradedat'] = (int)$gg->timemodified;
            $state['overridden'] = !empty($gg->overridden);
        }

        if ($state['module'] === 'assign') {
            $sub = $DB->get_record_sql(
                "SELECT id, status, timemodified, attemptnumber
                   FROM {assign_submission}
                  WHERE assignment = :a AND userid = :u AND groupid = 0 AND latest = 1",
                ['a' => (int)$item->iteminstance, 'u' => $userid], IGNORE_MULTIPLE);
            if ($sub) {
                $state['submission']    = (string)$sub->status;
                $state['submittedat']   = (int)$sub->timemodified;
                $state['submissionid']  = (int)$sub->id;
                $state['attemptnumber'] = (int)$sub->attemptnumber;
                $ag = $DB->get_record_sql(
                    "SELECT grade, timemodified FROM {assign_grades}
                      WHERE assignment = :a AND userid = :u AND attemptnumber = :n",
                    ['a' => (int)$item->iteminstance, 'u' => $userid, 'n' => (int)$sub->attemptnumber],
                    IGNORE_MULTIPLE);
                $assigngraded = $ag && $ag->grade !== null && (float)$ag->grade >= 0;
                if ($sub->status === 'submitted') {
                    // Pendiente si nunca se calificó, o si volvió a entregar después de la nota.
                    $lastgrade = max($assigngraded ? (int)$ag->timemodified : 0,
                        $state['grade'] !== null ? $state['gradedat'] : 0);
                    $state['needsgrading'] = ($state['grade'] === null && !$assigngraded)
                        || ((int)$sub->timemodified > $lastgrade && $lastgrade > 0);
                }
            }
        }
        return $state;
    }

    /**
     * Resume los estados de las actividades de una inscripción.
     */
    public static function summarize(array $states): string {
        if (empty($states)) {
            return self::SUMMARY_NONE;
        }
        $graded = 0;
        foreach ($states as $s) {
            if ($s['needsgrading']) {
                return self::SUMMARY_PENDING;
            }
            if ($s['grade'] !== null) {
                $graded++;
            }
        }
        if ($graded === count($states)) {
            return self::SUMMARY_GRADED;
        }
        return $graded > 0 ? self::SUMMARY_PARTIAL : self::SUMMARY_NONE;
    }

    /**
     * Estado de entrega por inscripción para una clase módulo.
     *
     * @return array [enrollmentid => ['summary'=>..., 'lastsubmission'=>ts, 'activities'=>[...]]]
     */
    public static function class_overview(int $classid): array {
        global $DB;
        $class = $DB->get_record('gmk_class', ['id' => $classid],
            'id, corecourseid, gradecategoryid, coursesectionid', MUST_EXIST);
        $items = self::get_items($class);
        $out = [];
        $enrollments = $DB->get_records('gmk_module_enrollment', ['classid' => $classid], '', 'id, userid');
        foreach ($enrollments as $e) {
            $states = [];
            $last = 0;
            foreach ($items as $it) {
                $st = self::item_state($it, (int)$e->userid);
                if (in_array($st['submission'], ['submitted', 'draft'], true)) {
                    $last = max($last, $st['submittedat']);
                }
                $states[] = $st;
            }
            $out[(int)$e->id] = [
                'summary'        => self::summarize($states),
                'lastsubmission' => $last,
                'activities'     => $states,
            ];
        }
        return $out;
    }

    /**
     * Cantidad de entregas por calificar (inscripciones activas) por clase módulo.
     *
     * @return array [classid => count]
     */
    public static function pending_counts(): array {
        global $DB;
        // Candidatas baratas: entregas 'submitted' de tareas en la sección de módulos
        // con inscripción activa. Luego se confirma con item_state (re-entregas, notas).
        $rows = $DB->get_records_sql(
            "SELECT e.id AS enrollmentid, e.classid, e.userid
               FROM {gmk_module_enrollment} e
               JOIN {gmk_class} gc ON gc.id = e.classid AND gc.is_module = 1
               JOIN {course_modules} cm ON cm.section = gc.coursesectionid AND cm.deletioninprogress = 0
               JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
               JOIN {assign_submission} s ON s.assignment = cm.instance AND s.userid = e.userid
                                         AND s.latest = 1 AND s.status = 'submitted'
              WHERE e.status = 'active'"
        );
        $counts = [];
        $classcache = [];
        foreach ($rows as $r) {
            $cid = (int)$r->classid;
            if (!isset($classcache[$cid])) {
                $class = $DB->get_record('gmk_class', ['id' => $cid],
                    'id, corecourseid, gradecategoryid, coursesectionid');
                $classcache[$cid] = $class ? self::get_items($class) : [];
            }
            foreach ($classcache[$cid] as $it) {
                if (self::item_state($it, (int)$r->userid)['needsgrading']) {
                    $counts[$cid] = ($counts[$cid] ?? 0) + 1;
                    break;
                }
            }
        }
        return $counts;
    }

    /**
     * Detalle de la entrega de una inscripción: actividades, archivos y retroalimentación.
     */
    public static function enrollment_detail(int $enrollmentid): array {
        global $DB, $CFG;
        $e = $DB->get_record('gmk_module_enrollment', ['id' => $enrollmentid], '*', MUST_EXIST);
        $class = $DB->get_record('gmk_class', ['id' => $e->classid],
            'id, corecourseid, gradecategoryid, coursesectionid', MUST_EXIST);
        $user = $DB->get_record('user', ['id' => $e->userid], 'id, firstname, lastname, email');
        $fs = get_file_storage();
        $activities = [];
        foreach (self::get_items($class) as $it) {
            $st = self::item_state($it, (int)$e->userid);
            $st['files'] = [];
            $st['onlinetext'] = '';
            $st['feedback'] = '';
            $st['moodleurl'] = '';
            $st['gradeurl'] = '';
            if ($st['module'] === 'assign' && $st['cmid'] > 0) {
                $ctx = \context_module::instance($st['cmid']);
                $st['moodleurl'] = (new \moodle_url('/mod/assign/view.php', ['id' => $st['cmid']]))->out(false);
                $st['gradeurl'] = (new \moodle_url('/mod/assign/view.php',
                    ['id' => $st['cmid'], 'action' => 'grader', 'userid' => (int)$e->userid]))->out(false);
                if ($st['submissionid'] > 0) {
                    $files = $fs->get_area_files($ctx->id, 'assignsubmission_file', 'submission_files',
                        $st['submissionid'], 'filename', false);
                    foreach ($files as $f) {
                        $st['files'][] = [
                            'id'       => (int)$f->get_id(),
                            'filename' => $f->get_filename(),
                            'filesize' => (int)$f->get_filesize(),
                            'mimetype' => (string)$f->get_mimetype(),
                            'url'      => (new \moodle_url('/local/grupomakro_core/pages/module_submission_file.php',
                                ['enrollmentid' => $enrollmentid, 'fileid' => $f->get_id()]))->out(false),
                        ];
                    }
                    $ot = $DB->get_record('assignsubmission_onlinetext',
                        ['submission' => $st['submissionid']], 'onlinetext', IGNORE_MULTIPLE);
                    if ($ot && trim(strip_tags((string)$ot->onlinetext)) !== '') {
                        $st['onlinetext'] = format_text($ot->onlinetext, FORMAT_HTML, ['context' => $ctx]);
                    }
                }
                // Comentario de retroalimentación del intento actual.
                $fb = $DB->get_record_sql(
                    "SELECT fc.commenttext
                       FROM {assign_grades} ag
                       JOIN {assignfeedback_comments} fc ON fc.grade = ag.id
                      WHERE ag.assignment = :a AND ag.userid = :u AND ag.attemptnumber = :n",
                    ['a' => (int)$it->iteminstance, 'u' => (int)$e->userid, 'n' => (int)$st['attemptnumber']],
                    IGNORE_MULTIPLE);
                if ($fb) {
                    $st['feedback'] = trim(html_to_text((string)$fb->commenttext, 0, false));
                }
            }
            if ($st['feedback'] === '') {
                $gg = $DB->get_record('grade_grades', ['itemid' => (int)$it->id, 'userid' => (int)$e->userid], 'feedback');
                if ($gg && trim((string)$gg->feedback) !== '') {
                    $st['feedback'] = trim(html_to_text((string)$gg->feedback, 0, false));
                }
            }
            $activities[] = $st;
        }
        return [
            'enrollmentid' => (int)$e->id,
            'status'       => (string)$e->status,
            'userid'       => (int)$e->userid,
            'fullname'     => $user ? fullname($user) : '',
            'email'        => $user ? $user->email : '',
            'duedate'      => (int)$e->duedate,
            'activities'   => $activities,
        ];
    }

    /**
     * Guarda notas (0-100) y retroalimentación de las actividades del módulo.
     *
     * Para tareas (assign) usa la API de la tarea cuando el usuario puede
     * calificarlas: queda como si la hubiera calificado el docente (nota en la
     * tarea, comentario visible al estudiante y libro de calificaciones
     * sincronizado). Si no tiene ese permiso, o la actividad no es una tarea,
     * escribe la nota y el comentario directamente en el libro de calificaciones.
     *
     * @param array $grades [['itemid'=>int, 'grade'=>float 0-100, 'feedback'=>string], ...]
     * @return array ['saved'=>n, 'activities'=>estado actualizado]
     */
    public static function save_grades(int $enrollmentid, array $grades): array {
        global $DB, $CFG, $USER;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $e = $DB->get_record('gmk_module_enrollment', ['id' => $enrollmentid], '*', MUST_EXIST);
        $class = $DB->get_record('gmk_class', ['id' => $e->classid],
            'id, corecourseid, gradecategoryid, coursesectionid', MUST_EXIST);
        $items = [];
        foreach (self::get_items($class) as $it) {
            $items[(int)$it->id] = $it;
        }
        $userid = (int)$e->userid;
        $saved = 0;
        foreach ($grades as $g) {
            $itemid = (int)($g['itemid'] ?? 0);
            if (!isset($items[$itemid]) || !isset($g['grade']) || $g['grade'] === '' || $g['grade'] === null) {
                continue;
            }
            $it = $items[$itemid];
            $pct = max(0.0, min(100.0, (float)$g['grade']));
            $feedback = trim((string)($g['feedback'] ?? ''));

            $usedassign = false;
            if ($it->itemmodule === 'assign' && (int)$it->cmid > 0) {
                $ctx = \context_module::instance((int)$it->cmid);
                if (has_capability('mod/assign:grade', $ctx)) {
                    list($course, $cm) = get_course_and_cm_from_cmid((int)$it->cmid, 'assign');
                    $assign = new \assign($ctx, $cm, $course);
                    $instance = $assign->get_instance();
                    if ((float)$instance->grade > 0) {
                        // Si la nota del libro estaba sobrescrita a mano, la tarea no la
                        // podría actualizar: se libera para que la nueva nota llegue.
                        $gg = \grade_grade::fetch(['itemid' => $itemid, 'userid' => $userid]);
                        if ($gg && !empty($gg->overridden)) {
                            $gg->set_overridden(false);
                        }
                        $data = new \stdClass();
                        $data->grade = round(($pct / 100.0) * (float)$instance->grade, 5);
                        $data->attemptnumber = -1;
                        $data->addattempt = 0;
                        $data->applytoall = 0;
                        $data->workflowstate = '';
                        $data->assignfeedbackcomments_editor = [
                            'text'   => $feedback !== '' ? format_text($feedback, FORMAT_PLAIN) : '',
                            'format' => FORMAT_HTML,
                        ];
                        $assign->save_grade($userid, $data);
                        $usedassign = true;
                    }
                }
            }
            if (!$usedassign) {
                $gi = \grade_item::fetch(['id' => $itemid]);
                if ($gi && (float)$gi->grademax > 0) {
                    $gi->update_final_grade($userid, ($pct / 100.0) * (float)$gi->grademax, 'gmk_module_grading',
                        $feedback !== '' ? $feedback : false, FORMAT_PLAIN, (int)$USER->id);
                }
            }
            $saved++;
        }
        $states = [];
        foreach ($items as $it) {
            $states[] = self::item_state($it, $userid);
        }
        return ['saved' => $saved, 'summary' => self::summarize($states), 'activities' => $states];
    }

    /**
     * Archivo entregado por el estudiante de una inscripción (o null si no le pertenece).
     */
    public static function get_submission_file(int $enrollmentid, int $fileid): ?\stored_file {
        global $DB;
        $e = $DB->get_record('gmk_module_enrollment', ['id' => $enrollmentid], 'id, classid, userid', MUST_EXIST);
        $class = $DB->get_record('gmk_class', ['id' => $e->classid],
            'id, corecourseid, gradecategoryid, coursesectionid', MUST_EXIST);
        $file = get_file_storage()->get_file_by_id($fileid);
        if (!$file || $file->is_directory() || $file->get_component() !== 'assignsubmission_file'
                || $file->get_filearea() !== 'submission_files') {
            return null;
        }
        foreach (self::get_items($class) as $it) {
            if ($it->itemmodule !== 'assign' || (int)$it->cmid <= 0) {
                continue;
            }
            $ctx = \context_module::instance((int)$it->cmid);
            if ((int)$file->get_contextid() !== (int)$ctx->id) {
                continue;
            }
            $ok = $DB->record_exists('assign_submission', [
                'id' => (int)$file->get_itemid(), 'assignment' => (int)$it->iteminstance, 'userid' => (int)$e->userid]);
            return $ok ? $file : null;
        }
        return null;
    }
}
