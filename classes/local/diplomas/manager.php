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
 * Manager for the Diploma module: eligibility, templates, generation, public verification.
 *
 * @package    local_grupomakro_core
 * @copyright  2024 Solutto Consulting
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\diplomas;

defined('MOODLE_INTERNAL') || die();

use context_system;
use core_user;
use dml_exception;
use moodle_exception;
use moodle_url;
use stdClass;
use stored_file;

/**
 * Diplomas manager.
 */
class manager {
    /** File area for background images. */
    public const FILEAREA_BACKGROUND = 'diploma_background';
    /** File area for generated diploma PDFs. */
    public const FILEAREA_DOCUMENT = 'diploma_document';

    /** Field type: dynamic variable from student record. */
    public const FIELD_VARIABLE = 'variable';
    /** Field type: custom text, can include placeholders. */
    public const FIELD_CUSTOM = 'custom';
    /** Field type: static text (no variable substitution). */
    public const FIELD_STATIC = 'static';
    /** Field type: QR code linking to public verification URL. */
    public const FIELD_QR = 'qr';

    /** Status: diploma issued and currently valid. */
    public const STATUS_GENERATED = 'generated';
    /** Status: diploma issued but later revoked. */
    public const STATUS_REVOKED = 'revoked';

    /**
     * Catalog of supported variables (code => [label, resolver callable]).
     *
     * Resolvers receive ($user, $learningplan, $generation, $template) and return string.
     *
     * @return array<string, array{label:string,resolve:callable}>
     */
    public static function get_variable_catalog(): array {
        return [
            'fullname' => [
                'label' => get_string('diploma_var_fullname', 'local_grupomakro_core'),
                'resolve' => function (stdClass $user): string {
                    return trim(fullname($user));
                },
            ],
            'firstname' => [
                'label' => get_string('diploma_var_firstname', 'local_grupomakro_core'),
                'resolve' => function (stdClass $user): string {
                    return (string)$user->firstname;
                },
            ],
            'lastname' => [
                'label' => get_string('diploma_var_lastname', 'local_grupomakro_core'),
                'resolve' => function (stdClass $user): string {
                    return (string)$user->lastname;
                },
            ],
            'idnumber' => [
                'label' => get_string('diploma_var_idnumber', 'local_grupomakro_core'),
                'resolve' => function (stdClass $user): string {
                    return (string)$user->idnumber;
                },
            ],
            'documentnumber' => [
                'label' => get_string('diploma_var_documentnumber', 'local_grupomakro_core'),
                'resolve' => function (stdClass $user): string {
                    return self::resolve_user_custom_field($user->id, 'documentnumber');
                },
            ],
            'email' => [
                'label' => get_string('diploma_var_email', 'local_grupomakro_core'),
                'resolve' => function (stdClass $user): string {
                    return (string)$user->email;
                },
            ],
            'phone' => [
                'label' => get_string('diploma_var_phone', 'local_grupomakro_core'),
                'resolve' => function (stdClass $user): string {
                    $phone1 = self::resolve_user_custom_field($user->id, 'phone1');
                    $phone2 = self::resolve_user_custom_field($user->id, 'phone2');
                    foreach ([$phone1, $phone2] as $p) {
                        if ($p !== '') {
                            return $p;
                        }
                    }
                    return '';
                },
            ],
            'address' => [
                'label' => get_string('diploma_var_address', 'local_grupomakro_core'),
                'resolve' => function (stdClass $user): string {
                    return self::resolve_user_custom_field($user->id, 'address');
                },
            ],
            'career' => [
                'label' => get_string('diploma_var_career', 'local_grupomakro_core'),
                'resolve' => function (stdClass $user, ?stdClass $lp = null): string {
                    if ($lp && !empty($lp->planname)) {
                        return (string)$lp->planname;
                    }
                    return self::resolve_user_learning_plan_name($user->id);
                },
            ],
            'learningplan' => [
                'label' => get_string('diploma_var_learningplan', 'local_grupomakro_core'),
                'resolve' => function (stdClass $user, ?stdClass $lp = null): string {
                    if ($lp && !empty($lp->planname)) {
                        return (string)$lp->planname;
                    }
                    return self::resolve_user_learning_plan_name($user->id);
                },
            ],
            'period' => [
                'label' => get_string('diploma_var_period', 'local_grupomakro_core'),
                'resolve' => function (stdClass $user, ?stdClass $lp = null): string {
                    return $lp && !empty($lp->periodname) ? (string)$lp->periodname : '';
                },
            ],
            'subperiod' => [
                'label' => get_string('diploma_var_subperiod', 'local_grupomakro_core'),
                'resolve' => function (stdClass $user, ?stdClass $lp = null): string {
                    return $lp && !empty($lp->subperiodname) ? (string)$lp->subperiodname : '';
                },
            ],
            'issue_date' => [
                'label' => get_string('diploma_var_issue_date', 'local_grupomakro_core'),
                'resolve' => function (stdClass $user, ?stdClass $lp = null, ?stdClass $generation = null): string {
                    $ts = $generation ? (int)$generation->issued_at : time();
                    return userdate($ts, get_string('strftimedatefullshort'));
                },
            ],
            'diploma_number' => [
                'label' => get_string('diploma_var_diploma_number', 'local_grupomakro_core'),
                'resolve' => function (stdClass $user, ?stdClass $lp = null, ?stdClass $generation = null): string {
                    return $generation ? (string)$generation->diploma_number : '';
                },
            ],
            'diploma_version' => [
                'label' => get_string('diploma_var_diploma_version', 'local_grupomakro_core'),
                'resolve' => function (stdClass $user, ?stdClass $lp = null, ?stdClass $generation = null): string {
                    return $generation ? 'v' . (int)$generation->version : '';
                },
            ],
            'institution' => [
                'label' => get_string('diploma_var_institution', 'local_grupomakro_core'),
                'resolve' => function (): string {
                    $configured = (string)get_config('local_grupomakro_core', 'institutionname');
                    if ($configured !== '') {
                        return $configured;
                    }
                    $enlabel = get_string('diploma_var_institution', 'local_grupomakro_core');
                    if (current_language() === 'en' && $enlabel === 'Institution name') {
                        return 'Grupo Makro';
                    }
                    return $configured !== '' ? $configured : 'Grupo Makro';
                },
            ],
            'campus' => [
                'label' => get_string('diploma_var_campus', 'local_grupomakro_core'),
                'resolve' => function (): string {
                    return (string)get_config('local_grupomakro_core', 'campusname');
                },
            ],
        ];
    }

    /**
     * Resolves a single variable's value for a given user/generation context.
     *
     * @param string $code Variable code.
     * @param stdClass $user User record.
     * @param stdClass|null $lp Learning plan row (optional).
     * @param stdClass|null $generation Generation record (optional).
     * @return string Resolved value, or empty string if unknown.
     */
    public static function resolve_variable(string $code, stdClass $user, ?stdClass $lp = null, ?stdClass $generation = null): string {
        $catalog = self::get_variable_catalog();
        if (!isset($catalog[$code])) {
            return '';
        }
        $fn = $catalog[$code]['resolve'];
        return (string)$fn($user, $lp, $generation);
    }

    /**
     * Get user profile field value by shortname.
     *
     * @param int $userid
     * @param string $shortname
     * @return string
     */
    public static function resolve_user_custom_field(int $userid, string $shortname): string {
        global $DB;
        static $cache = [];
        if (isset($cache[$userid][$shortname])) {
            return $cache[$userid][$shortname];
        }
        $sql = "SELECT uid.data
                  FROM {user_info_data} uid
                  JOIN {user_info_field} uif ON uif.id = uid.fieldid
                 WHERE uid.userid = :userid AND uif.shortname = :shortname";
        $value = (string)$DB->get_field_sql($sql, ['userid' => $userid, 'shortname' => $shortname]);
        $cache[$userid][$shortname] = $value;
        return $value;
    }

    /**
     * Resolve the active learning plan name for a student.
     *
     * @param int $userid
     * @return string
     */
    public static function resolve_user_learning_plan_name(int $userid): string {
        global $DB;
        $sql = "SELECT lp.name
                  FROM {local_learning_users} lu
                  JOIN {local_learning_plans} lp ON lp.id = lu.learningplanid
                 WHERE lu.userid = :userid AND lu.userrolename = 'student'
              ORDER BY lu.id ASC";
        $name = (string)$DB->get_field_sql($sql, ['userid' => $userid]);
        return $name;
    }

    /**
     * Loads a diploma template row.
     *
     * @param int $id
     * @return stdClass|null
     */
    public static function get_template(int $id): ?stdClass {
        global $DB;
        $record = $DB->get_record('gmk_diploma_template', ['id' => $id], '*', IGNORE_MISSING);
        return $record ?: null;
    }

    /**
     * Lists diploma templates (active first).
     *
     * @return array
     */
    public static function list_templates(): array {
        global $DB;
        $rows = $DB->get_records('gmk_diploma_template', null, 'active DESC, name ASC');
        $out = [];
        foreach ($rows as $r) {
            $out[] = self::export_template($r);
        }
        return $out;
    }

    /**
     * Export a template row for the API/frontend.
     *
     * @param stdClass $row
     * @return array
     */
    public static function export_template(stdClass $row): array {
        global $DB, $CFG;
        $bgurl = '';
        if (!empty($row->background_fileid)) {
            $bgurl = (new moodle_url('/local/grupomakro_core/pages/diploma_image.php',
                ['id' => (int)$row->id]))->out(false);
        }
        $fields = $DB->get_records('gmk_diploma_tpl_field', ['templateid' => (int)$row->id], 'z_index ASC, id ASC');
        $exportedfields = [];
        foreach ($fields as $f) {
            $exportedfields[] = self::export_field($f);
        }
        return [
            'id' => (int)$row->id,
            'name' => (string)$row->name,
            'description' => (string)($row->description ?? ''),
            'orientation' => (string)$row->orientation,
            'width_mm' => (float)$row->width_mm,
            'height_mm' => (float)$row->height_mm,
            'active' => (int)$row->active,
            'background_filename' => (string)($row->background_filename ?? ''),
            'background_mimetype' => (string)($row->background_mimetype ?? ''),
            'background_url' => $bgurl,
            'timecreated' => (int)$row->timecreated,
            'timemodified' => (int)$row->timemodified,
            'fields' => $exportedfields,
        ];
    }

    /**
     * Export a template field for the API/frontend.
     *
     * @param stdClass $f
     * @return array
     */
    public static function export_field(stdClass $f): array {
        return [
            'id' => (int)$f->id,
            'templateid' => (int)$f->templateid,
            'field_type' => (string)$f->field_type,
            'variable_code' => (string)($f->variable_code ?? ''),
            'custom_text' => (string)($f->custom_text ?? ''),
            'static_text' => (string)($f->static_text ?? ''),
            'x_mm' => (float)$f->x_mm,
            'y_mm' => (float)$f->y_mm,
            'width_mm' => (float)$f->width_mm,
            'height_mm' => (float)$f->height_mm,
            'rotation' => (float)$f->rotation,
            'font_family' => (string)$f->font_family,
            'font_size' => (float)$f->font_size,
            'font_weight' => (string)$f->font_weight,
            'font_color' => (string)$f->font_color,
            'align' => (string)$f->align,
            'line_height' => (float)$f->line_height,
            'z_index' => (int)$f->z_index,
        ];
    }

    /**
     * Saves a diploma template (with its fields). Updates an existing one if id>0, otherwise creates a new one.
     *
     * @param array $payload Decoded payload from frontend.
     * @param int $actorid Acting user id.
     * @return array Exported template.
     */
    public static function save_template(array $payload, int $actorid): array {
        global $DB;
        $now = time();
        $id = isset($payload['id']) ? (int)$payload['id'] : 0;
        $name = trim((string)($payload['name'] ?? ''));
        if ($name === '') {
            throw new moodle_exception('diploma_template_name', 'local_grupomakro_core');
        }
        $orientation = ($payload['orientation'] ?? 'landscape') === 'portrait' ? 'portrait' : 'landscape';
        $widthmm = $orientation === 'portrait' ? 210.0 : 297.0;
        $heightmm = $orientation === 'portrait' ? 297.0 : 210.0;

        if ($id > 0) {
            $current = $DB->get_record('gmk_diploma_template', ['id' => $id], '*', MUST_EXIST);
            $current->name = $name;
            $current->description = (string)($payload['description'] ?? '');
            $current->orientation = $orientation;
            $current->width_mm = $widthmm;
            $current->height_mm = $heightmm;
            $current->active = !empty($payload['active']) ? 1 : 0;
            $current->timemodified = $now;
            $current->usermodified = $actorid;
            $DB->update_record('gmk_diploma_template', $current);
        } else {
            $record = (object)[
                'name' => $name,
                'description' => (string)($payload['description'] ?? ''),
                'orientation' => $orientation,
                'width_mm' => $widthmm,
                'height_mm' => $heightmm,
                'background_fileid' => 0,
                'background_filename' => '',
                'background_mimetype' => '',
                'active' => !empty($payload['active']) ? 1 : 1,
                'usermodified' => $actorid,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $id = $DB->insert_record('gmk_diploma_template', $record);
        }

        $DB->delete_records('gmk_diploma_tpl_field', ['templateid' => $id]);
        $fields = isset($payload['fields']) && is_array($payload['fields']) ? $payload['fields'] : [];
        $z = 0;
        foreach ($fields as $f) {
            $type = (string)($f['field_type'] ?? self::FIELD_VARIABLE);
            if (!in_array($type, [self::FIELD_VARIABLE, self::FIELD_CUSTOM, self::FIELD_STATIC, self::FIELD_QR], true)) {
                continue;
            }
            $row = (object)[
                'templateid' => $id,
                'field_type' => $type,
                'variable_code' => substr(trim((string)($f['variable_code'] ?? '')), 0, 50),
                'custom_text' => (string)($f['custom_text'] ?? ''),
                'static_text' => (string)($f['static_text'] ?? ''),
                'x_mm' => max(0.0, (float)($f['x_mm'] ?? 20)),
                'y_mm' => max(0.0, (float)($f['y_mm'] ?? 20)),
                'width_mm' => max(1.0, (float)($f['width_mm'] ?? 80)),
                'height_mm' => max(1.0, (float)($f['height_mm'] ?? 12)),
                'rotation' => (float)($f['rotation'] ?? 0),
                'font_family' => self::normalize_font((string)($f['font_family'] ?? 'helvetica')),
                'font_size' => max(4.0, (float)($f['font_size'] ?? 14)),
                'font_weight' => in_array(($f['font_weight'] ?? 'normal'), ['normal', 'bold'], true) ? $f['font_weight'] : 'normal',
                'font_color' => self::normalize_color((string)($f['font_color'] ?? '#000000')),
                'align' => in_array(($f['align'] ?? 'center'), ['left', 'center', 'right'], true) ? $f['align'] : 'center',
                'line_height' => max(0.8, (float)($f['line_height'] ?? 1.2)),
                'z_index' => (int)($f['z_index'] ?? $z),
                'usermodified' => $actorid,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $DB->insert_record('gmk_diploma_tpl_field', $row);
            $z++;
        }

        return self::export_template($DB->get_record('gmk_diploma_template', ['id' => $id], '*', MUST_EXIST));
    }

    /**
     * Duplicates a template (including its fields and background file reference).
     *
     * @param int $id Source template id.
     * @param int $actorid Acting user id.
     * @return array Exported new template.
     */
    public static function duplicate_template(int $id, int $actorid): array {
        global $DB;
        $src = $DB->get_record('gmk_diploma_template', ['id' => $id], '*', MUST_EXIST);
        $now = time();
        $new = (object)[
            'name' => $src->name . ' (copia)',
            'description' => (string)$src->description,
            'orientation' => $src->orientation,
            'width_mm' => $src->width_mm,
            'height_mm' => $src->height_mm,
            'background_fileid' => $src->background_fileid,
            'background_filename' => $src->background_filename,
            'background_mimetype' => $src->background_mimetype,
            'active' => $src->active,
            'usermodified' => $actorid,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $newid = $DB->insert_record('gmk_diploma_template', $new);
        // Copy background image as a new file so the duplicate has its own storage.
        if (!empty($src->background_fileid)) {
            $fs = get_file_storage();
            $file = $fs->get_file_by_id((int)$src->background_fileid);
            if ($file) {
                $newrec = (object)[
                    'contextid' => context_system::instance()->id,
                    'component' => 'local_grupomakro_core',
                    'filearea' => self::FILEAREA_BACKGROUND,
                    'itemid' => $newid,
                    'filepath' => '/',
                    'filename' => clean_param($file->get_filename(), PARAM_FILE),
                ];
                $fs->create_file_from_storedfile($newrec, $file);
                $stored = $fs->get_file(
                    $newrec->contextid,
                    $newrec->component,
                    $newrec->filearea,
                    $newrec->itemid,
                    '/',
                    $newrec->filename
                );
                if ($stored) {
                    $tpl = $DB->get_record('gmk_diploma_template', ['id' => $newid], '*', MUST_EXIST);
                    $tpl->background_fileid = (int)$stored->get_id();
                    $tpl->background_filename = (string)$stored->get_filename();
                    $tpl->background_mimetype = (string)$stored->get_mimetype();
                    $DB->update_record('gmk_diploma_template', $tpl);
                }
            }
        }
        $fields = $DB->get_records('gmk_diploma_tpl_field', ['templateid' => $id]);
        foreach ($fields as $f) {
            $f->id = null;
            $f->templateid = $newid;
            $f->timecreated = $now;
            $f->timemodified = $now;
            $f->usermodified = $actorid;
            $DB->insert_record('gmk_diploma_tpl_field', $f);
        }
        return self::export_template($DB->get_record('gmk_diploma_template', ['id' => $newid], '*', MUST_EXIST));
    }

    /**
     * Deletes a diploma template and its fields. Background file is removed too.
     *
     * @param int $id Template id.
     * @return void
     */
    public static function delete_template(int $id): void {
        global $DB;
        $tpl = $DB->get_record('gmk_diploma_template', ['id' => $id], '*', MUST_EXIST);
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            context_system::instance()->id,
            'local_grupomakro_core',
            self::FILEAREA_BACKGROUND,
            $id
        );
        foreach ($files as $f) {
            $f->delete();
        }
        $DB->delete_records('gmk_diploma_tpl_field', ['templateid' => $id]);
        $DB->delete_records('gmk_diploma_template', ['id' => $id]);
    }

    /**
     * Saves the background image (overwrites any previous one) for the given template.
     *
     * @param int $templateid
     * @param string $filename Original filename from upload.
     * @param string $mimetype
     * @param string $content Raw file contents.
     * @param int $actorid
     * @return array Public URL + metadata.
     */
    public static function save_background(int $templateid, string $filename, string $mimetype, string $content, int $actorid): array {
        global $DB;
        $tpl = $DB->get_record('gmk_diploma_template', ['id' => $templateid], '*', MUST_EXIST);
        $fs = get_file_storage();
        // Remove previous files.
        $existing = $fs->get_area_files(
            context_system::instance()->id,
            'local_grupomakro_core',
            self::FILEAREA_BACKGROUND,
            $templateid,
            'id ASC',
            false
        );
        foreach ($existing as $old) {
            $old->delete();
        }
        $clean = clean_param($filename, PARAM_FILE);
        if ($clean === '' || $clean === '.') {
            $clean = 'background.png';
        }
        $filerec = (object)[
            'contextid' => context_system::instance()->id,
            'component' => 'local_grupomakro_core',
            'filearea' => self::FILEAREA_BACKGROUND,
            'itemid' => $templateid,
            'filepath' => '/',
            'filename' => $clean,
            'userid' => $actorid,
        ];
        $stored = $fs->create_file_from_string($filerec, $content);

        $tpl->background_fileid = (int)$stored->get_id();
        $tpl->background_filename = (string)$stored->get_filename();
        $tpl->background_mimetype = (string)$stored->get_mimetype();
        $tpl->timemodified = time();
        $tpl->usermodified = $actorid;
        $DB->update_record('gmk_diploma_template', $tpl);

        $url = (new moodle_url('/local/grupomakro_core/pages/diploma_image.php',
            ['id' => $templateid]))->out(false);

        return [
            'fileid' => (int)$stored->get_id(),
            'filename' => (string)$stored->get_filename(),
            'mimetype' => (string)$stored->get_mimetype(),
            'filesize' => (int)$stored->get_filesize(),
            'url' => $url,
        ];
    }

    /**
     * Returns elegible graduands (per learning plan) that do not have any active diploma yet.
     *
     * "Elegible" = student has at least one learning plan where ALL obligatory courses (isrequired=1)
     * are approved (gmk_course_progre.status = COURSE_APPROVED = 4).
     *
     * @param int|null $learningplanid Optional filter to a single plan id.
     * @param string $search Optional search term (matches user fields).
     * @return array<int, array{user:stdClass,plan:stdClass}>
     */
    public static function list_eligible_graduands(?int $learningplanid = null, string $search = ''): array {
        global $DB;
        // 1) For each (user, plan) pair, verify that every required course is approved.
        $sql = "SELECT lu.userid, lu.learningplanid, lp.name AS planname,
                       lpcu.currperiodid AS periodid,
                       lper.name AS periodname,
                       lpcu.currsubperiodid AS subperiodid,
                       lsp.name AS subperiodname,
                       u.firstname, u.lastname, u.idnumber, u.email, u.username
                  FROM {local_learning_users} lu
                  JOIN {user} u ON u.id = lu.userid AND u.deleted = 0 AND u.suspended = 0
                  JOIN {local_learning_plans} lp ON lp.id = lu.learningplanid
             LEFT JOIN {local_learning_users} lpcu ON lpcu.userid = lu.userid AND lpcu.learningplanid = lu.learningplanid
             LEFT JOIN {local_learning_periods} lper ON lper.id = lpcu.currperiodid
             LEFT JOIN {local_learning_subperiods} lsp ON lsp.id = lpcu.currsubperiodid
                 WHERE lu.userrolename = 'student'";
        $params = [];
        if ($learningplanid && $learningplanid > 0) {
            $sql .= " AND lu.learningplanid = :lp";
            $params['lp'] = $learningplanid;
        }
        $candidates = $DB->get_records_sql($sql, $params);

        $out = [];
        foreach ($candidates as $c) {
            // Required courses for the plan.
            $required = $DB->get_records('local_learning_courses', ['learningplanid' => $c->learningplanid, 'isrequired' => 1]);
            if (empty($required)) {
                continue; // No obligatory courses defined: skip to avoid false positives.
            }
            $requiredcount = count($required);
            $approved = $DB->count_records_sql(
                "SELECT COUNT(1)
                   FROM {gmk_course_progre} p
                   JOIN {local_learning_courses} lc ON lc.id = p.courseid
                  WHERE p.userid = :userid
                    AND lc.learningplanid = :lp
                    AND lc.isrequired = 1
                    AND p.status = :approved",
                ['userid' => $c->userid, 'lp' => $c->learningplanid, 'approved' => 4]
            );
            if ((int)$approved < $requiredcount) {
                continue;
            }
            // Skip if already has a non-revoked diploma for ANY template in this plan.
            $has = $DB->record_exists_select(
                'gmk_diploma_generation',
                "userid = :u AND learningplanid = :lp AND status = :st",
                ['u' => $c->userid, 'lp' => $c->learningplanid, 'st' => self::STATUS_GENERATED]
            );
            if ($has) {
                continue;
            }
            $user = core_user::get_user($c->userid);
            if (!$user) {
                continue;
            }
            if ($search !== '') {
                $needle = core_text::strtolower($search);
                $haystack = core_text::strtolower(
                    fullname($user) . ' ' . $user->idnumber . ' ' . $user->username . ' ' .
                    self::resolve_user_custom_field($user->id, 'documentnumber')
                );
                if (strpos($haystack, $needle) === false) {
                    continue;
                }
            }
            $out[] = [
                'user' => [
                    'id' => (int)$user->id,
                    'firstname' => $user->firstname,
                    'lastname' => $user->lastname,
                    'fullname' => fullname($user),
                    'idnumber' => $user->idnumber,
                    'email' => $user->email,
                    'username' => $user->username,
                    'documentnumber' => self::resolve_user_custom_field($user->id, 'documentnumber'),
                ],
                'plan' => [
                    'id' => (int)$c->learningplanid,
                    'name' => (string)$c->planname,
                    'periodname' => (string)($c->periodname ?? ''),
                    'subperiodname' => (string)($c->subperiodname ?? ''),
                ],
            ];
        }
        return $out;
    }

    /**
     * List ALL students enrolled in the system (optionally filtered by plan
     * and/or search string), including those who have NOT yet met the
     * graduation requirements. Each entry carries a complete eligibility
     * breakdown (passed/missing requirements, progress percentage, and a
     * pre-baked eligibility flag) so the UI can show a popup with the
     * missing courses and still allow generating the diploma.
     *
     * @param int|null $learningplanid Optional plan id filter.
     * @param string $search Optional search string (matches full name, idnumber,
     *                        username or document number).
     * @param bool $onlyeligible If true, returns only fully eligible students.
     * @param int $limitfrom Pagination offset.
     * @param int $limitnum Pagination limit (0 = no limit).
     * @return array<int, array<string, mixed>> List of student rows.
     */
    public static function list_graduands_with_eligibility(
        ?int $learningplanid = null,
        string $search = '',
        bool $onlyeligible = false,
        int $limitfrom = 0,
        int $limitnum = 200
    ): array {
        global $DB;

        $sql = "SELECT lu.userid, lu.learningplanid, lp.name AS planname,
                       lpcu.currperiodid AS periodid,
                       lper.name AS periodname,
                       lpcu.currsubperiodid AS subperiodid,
                       lsp.name AS subperiodname,
                       u.firstname, u.lastname, u.idnumber, u.email, u.username
                  FROM {local_learning_users} lu
                  JOIN {user} u ON u.id = lu.userid AND u.deleted = 0 AND u.suspended = 0
                  JOIN {local_learning_plans} lp ON lp.id = lu.learningplanid
             LEFT JOIN {local_learning_users} lpcu ON lpcu.userid = lu.userid AND lpcu.learningplanid = lu.learningplanid
             LEFT JOIN {local_learning_periods} lper ON lper.id = lpcu.currperiodid
             LEFT JOIN {local_learning_subperiods} lsp ON lsp.id = lpcu.currsubperiodid
                 WHERE lu.userrolename = 'student'";
        $params = [];
        if ($learningplanid && $learningplanid > 0) {
            $sql .= " AND lu.learningplanid = :lp";
            $params['lp'] = $learningplanid;
        }
        if ($search !== '') {
            $sql .= " AND (LOWER(u.firstname) LIKE :s OR LOWER(u.lastname) LIKE :s
                       OR LOWER(u.username) LIKE :s OR LOWER(u.idnumber) LIKE :s)";
            $params['s'] = '%' . core_text::strtolower($search) . '%';
        }
        $sql .= " ORDER BY u.lastname ASC, u.firstname ASC";
        $candidates = $DB->get_records_sql($sql, $params, $limitfrom, $limitnum);

        $out = [];
        foreach ($candidates as $c) {
            $user = core_user::get_user($c->userid);
            if (!$user) {
                continue;
            }
            $eligibility = self::compute_eligibility((int)$c->userid, (int)$c->learningplanid);

            if ($onlyeligible && !$eligibility['is_eligible']) {
                continue;
            }

            $out[] = [
                'user' => [
                    'id' => (int)$user->id,
                    'firstname' => $user->firstname,
                    'lastname' => $user->lastname,
                    'fullname' => fullname($user),
                    'idnumber' => $user->idnumber,
                    'email' => $user->email,
                    'username' => $user->username,
                    'documentnumber' => self::resolve_user_custom_field($user->id, 'documentnumber'),
                ],
                'plan' => [
                    'id' => (int)$c->learningplanid,
                    'name' => (string)$c->planname,
                    'periodname' => (string)($c->periodname ?? ''),
                    'subperiodname' => (string)($c->subperiodname ?? ''),
                ],
                'eligibility' => $eligibility,
                'has_diploma' => $eligibility['has_diploma'],
            ];
        }
        return $out;
    }

    /**
     * Compute a full eligibility breakdown for one (user, plan) pair.
     *
     * @param int $userid
     * @param int $learningplanid
     * @return array<string, mixed> See structure below.
     */
    public static function compute_eligibility(int $userid, int $learningplanid): array {
        global $DB;

        // Required courses for the plan.
        $required = $DB->get_records('local_learning_courses',
            ['learningplanid' => $learningplanid, 'isrequired' => 1], 'id ASC');

        $requiredcount = count($required);
        $passednames = [];
        $missednames = [];
        $passed = 0;

        foreach ($required as $rc) {
            $row = $DB->get_record_sql(
                "SELECT p.status
                   FROM {gmk_course_progre} p
                  WHERE p.userid = :uid AND p.courseid = :cid",
                ['uid' => $userid, 'cid' => $rc->id]
            );
            // Status 4 = COURSE_APPROVED (per progress_manager constants).
            if ($row && (int)$row->status === 4) {
                $passed++;
                $passednames[] = (string)$rc->name;
            } else {
                $missednames[] = (string)$rc->name;
            }
        }

        $has = $DB->record_exists_select(
            'gmk_diploma_generation',
            "userid = :u AND learningplanid = :lp AND status = :st",
            ['u' => $userid, 'lp' => $learningplanid, 'st' => self::STATUS_GENERATED]
        );

        $is_eligible = ($requiredcount === 0 || $passed >= $requiredcount) && !$has;

        return [
            'is_eligible' => $is_eligible,
            'has_diploma' => (bool)$has,
            'required_count' => $requiredcount,
            'passed_count' => $passed,
            'progress_percent' => $requiredcount === 0 ? 100
                : (int)floor(($passed / $requiredcount) * 100),
            'passed_requirements' => $passednames,
            'missing_requirements' => $missednames,
            'reason' => self::explain_ineligibility($is_eligible, $requiredcount, $passed, $has),
        ];
    }

    /**
     * Build a human-readable reason string for why a student is (or isn't)
     * eligible. Useful for the popup in the UI.
     */
    private static function explain_ineligibility(bool $is_eligible, int $requiredcount, int $passed, bool $has): string {
        if ($is_eligible) {
            return 'El estudiante cumple todos los requisitos.';
        }
        if ($has) {
            return 'El estudiante ya tiene un diploma generado para este plan.';
        }
        if ($requiredcount === 0) {
            return 'Plan sin asignaturas obligatorias registradas.';
        }
        $missing = $requiredcount - $passed;
        return "Faltan {$missing} asignatura(s) obligatoria(s) por aprobar.";
    }

    /**
     * Same shape as list_graduands_with_eligibility but scoped to one user
     * for the popup detail view.
     */
    public static function get_graduand_eligibility_detail(int $userid, int $learningplanid): ?array {
        global $DB;
        $user = core_user::get_user($userid);
        if (!$user) {
            return null;
        }
        $plan = $DB->get_record('local_learning_plans', ['id' => $learningplanid], 'id, name');
        if (!$plan) {
            return null;
        }
        $eligibility = self::compute_eligibility($userid, $learningplanid);
        return [
            'user' => [
                'id' => (int)$user->id,
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'fullname' => fullname($user),
                'idnumber' => $user->idnumber,
                'email' => $user->email,
                'username' => $user->username,
                'documentnumber' => self::resolve_user_custom_field($user->id, 'documentnumber'),
            ],
            'plan' => [
                'id' => (int)$plan->id,
                'name' => (string)$plan->name,
            ],
            'eligibility' => $eligibility,
        ];
    }

    /**
     * Returns the count of eligible graduands by learning plan.
     *
     * @return array<int, array{id:int,name:string,count:int}>
     */
    public static function count_eligible_by_plan(): array {
        global $DB;
        $plans = $DB->get_records('local_learning_plans', null, 'name ASC');
        $out = [];
        foreach ($plans as $p) {
            $rows = self::list_eligible_graduands((int)$p->id);
            $out[] = ['id' => (int)$p->id, 'name' => (string)$p->name, 'count' => count($rows)];
        }
        return $out;
    }

    /**
     * List learning plans available in the system.
     *
     * @return array
     */
    public static function list_learning_plans(): array {
        global $DB;
        $rows = $DB->get_records('local_learning_plans', null, 'name ASC', 'id, name');
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['id' => (int)$r->id, 'name' => (string)$r->name];
        }
        return $out;
    }

    /**
     * Generates diploma records (and PDFs) for a set of students and a chosen template.
     *
     * @param int $templateid
     * @param array $items Array of [userid, learningplanid] pairs.
     * @param int $actorid
     * @return array Summary with success/errors and IDs.
     */
    public static function generate_diplomas(int $templateid, array $items, int $actorid): array {
        global $DB;
        $template = $DB->get_record('gmk_diploma_template', ['id' => $templateid], '*', MUST_EXIST);
        $fields = $DB->get_records('gmk_diploma_tpl_field', ['templateid' => $templateid], 'z_index ASC, id ASC');
        $success = 0;
        $errors = 0;
        $generated = [];
        foreach ($items as $item) {
            $userid = (int)($item['userid'] ?? 0);
            $lpid = (int)($item['learningplanid'] ?? 0);
            if ($userid <= 0 || $lpid <= 0) {
                $errors++;
                continue;
            }
            try {
                $user = core_user::get_user($userid, '*', MUST_EXIST);
                $lp = $DB->get_record_sql(
                    "SELECT lp.id AS id, lp.name AS planname,
                            lu.currperiodid AS periodid,
                            lp2.name AS periodname,
                            lu.currsubperiodid AS subperiodid,
                            lsp.name AS subperiodname
                       FROM {local_learning_users} lu
                       JOIN {local_learning_plans} lp ON lp.id = lu.learningplanid
                  LEFT JOIN {local_learning_periods} lp2 ON lp2.id = lu.currperiodid
                  LEFT JOIN {local_learning_subperiods} lsp ON lsp.id = lu.currsubperiodid
                      WHERE lu.userid = :uid AND lu.learningplanid = :lpid AND lu.userrolename = 'student'",
                    ['uid' => $userid, 'lpid' => $lpid]
                );
                $token = self::generate_verification_token();
                $verificationurl = self::build_verification_url($token);
                $number = self::generate_diploma_number($user, $lp);
                $now = time();

                $generation = (object)[
                    'templateid' => $templateid,
                    'userid' => $userid,
                    'learningplanid' => $lpid,
                    'diploma_number' => $number,
                    'version' => 1,
                    'status' => self::STATUS_GENERATED,
                    'verification_token' => $token,
                    'verification_url' => $verificationurl,
                    'snapshot_json' => null,
                    'issued_by' => $actorid,
                    'issued_at' => $now,
                    'revoked_by' => 0,
                    'revoked_at' => 0,
                    'revoke_reason' => null,
                    'usermodified' => $actorid,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ];
                $genid = $DB->insert_record('gmk_diploma_generation', $generation);

                // Render PDF using renderer.
                $renderer = new renderer();
                $pdfbytes = $renderer->render_pdf($template, $fields, $user, $lp ?: null, $generation, $verificationurl);

                // Persist file.
                $fs = get_file_storage();
                $filename = 'diploma_' . $templateid . '_' . $userid . '_' . $genid . '.pdf';
                $filerec = (object)[
                    'contextid' => context_system::instance()->id,
                    'component' => 'local_grupomakro_core',
                    'filearea' => self::FILEAREA_DOCUMENT,
                    'itemid' => $genid,
                    'filepath' => '/',
                    'filename' => $filename,
                    'userid' => $actorid,
                ];
                $stored = $fs->create_file_from_string($filerec, $pdfbytes);

                $docrec = (object)[
                    'generationid' => $genid,
                    'fileitemid' => $genid,
                    'filename' => $filename,
                    'mimetype' => 'application/pdf',
                    'version' => 1,
                    'filesize' => (int)$stored->get_filesize(),
                    'contenthash' => (string)$stored->get_contenthash(),
                    'usermodified' => $actorid,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ];
                $docid = $DB->insert_record('gmk_diploma_document', $docrec);

                $generated[] = [
                    'generationid' => $genid,
                    'documentid' => $docid,
                    'userid' => $userid,
                    'learningplanid' => $lpid,
                    'verification_url' => $verificationurl,
                ];
                $success++;
            } catch (Throwable $e) {
                $errors++;
            }
        }
        return ['success' => $success, 'errors' => $errors, 'generated' => $generated];
    }

    /**
     * Lists generations for a given template + status filter.
     *
     * @param int|null $templateid
     * @param string|null $status
     * @param string $search
     * @return array
     */
    public static function list_generations(?int $templateid = null, ?string $status = null, string $search = ''): array {
        global $DB;
        $where = ['1=1'];
        $params = [];
        if ($templateid && $templateid > 0) {
            $where[] = 'g.templateid = :tid';
            $params['tid'] = $templateid;
        }
        if ($status && $status !== '') {
            $where[] = 'g.status = :st';
            $params['st'] = $status;
        }
        $sql = "SELECT g.*, u.firstname, u.lastname, u.idnumber, u.email, t.name AS templatename,
                       lp.name AS planname, d.id AS docid, d.filename AS docfilename
                  FROM {gmk_diploma_generation} g
                  JOIN {user} u ON u.id = g.userid
                  JOIN {gmk_diploma_template} t ON t.id = g.templateid
                  JOIN {local_learning_plans} lp ON lp.id = g.learningplanid
             LEFT JOIN {gmk_diploma_document} d ON d.generationid = g.id AND d.version = g.version
                 WHERE " . implode(' AND ', $where) . "
              ORDER BY g.issued_at DESC, g.id DESC";
        $rows = $DB->get_records_sql($sql, $params, 0, 200);
        $out = [];
        foreach ($rows as $r) {
            $user = core_user::get_user($r->userid);
            if ($search !== '' && $user) {
                $needle = core_text::strtolower($search);
                $hay = core_text::strtolower(
                    fullname($user) . ' ' . $user->idnumber . ' ' . self::resolve_user_custom_field($user->id, 'documentnumber')
                );
                if (strpos($hay, $needle) === false) {
                    continue;
                }
            }
            $out[] = [
                'id' => (int)$r->id,
                'templateid' => (int)$r->templateid,
                'template_name' => (string)$r->templatename,
                'userid' => (int)$r->userid,
                'student_name' => fullname($user),
                'student_idnumber' => (string)$user->idnumber,
                'student_document' => self::resolve_user_custom_field($user->id, 'documentnumber'),
                'learningplanid' => (int)$r->learningplanid,
                'learningplan_name' => (string)$r->planname,
                'diploma_number' => (string)$r->diploma_number,
                'version' => (int)$r->version,
                'status' => (string)$r->status,
                'issued_at' => (int)$r->issued_at,
                'verification_url' => (string)$r->verification_url,
                'documentid' => isset($r->docid) ? (int)$r->docid : 0,
                'filename' => (string)($r->docfilename ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Revokes a generated diploma (does NOT delete the file).
     *
     * @param int $generationid
     * @param int $actorid
     * @param string $reason
     * @return void
     */
    public static function revoke_generation(int $generationid, int $actorid, string $reason = ''): void {
        global $DB;
        $gen = $DB->get_record('gmk_diploma_generation', ['id' => $generationid], '*', MUST_EXIST);
        if ($gen->status === self::STATUS_REVOKED) {
            return;
        }
        $gen->status = self::STATUS_REVOKED;
        $gen->revoked_by = $actorid;
        $gen->revoked_at = time();
        $gen->revoke_reason = $reason;
        $gen->timemodified = time();
        $gen->usermodified = $actorid;
        $DB->update_record('gmk_diploma_generation', $gen);
    }

    /**
     * Returns the verification payload for a public token.
     *
     * @param string $token
     * @return array|null
     */
    public static function get_verification_data(string $token): ?array {
        global $DB;
        $gen = $DB->get_record('gmk_diploma_generation', ['verification_token' => $token], '*', IGNORE_MISSING);
        if (!$gen) {
            return null;
        }
        $user = core_user::get_user($gen->userid);
        $template = $DB->get_record('gmk_diploma_template', ['id' => $gen->templateid], '*', IGNORE_MISSING);
        $lp = $DB->get_record('local_learning_plans', ['id' => $gen->learningplanid], 'id, name', IGNORE_MISSING);
        return [
            'generationid' => (int)$gen->id,
            'status' => (string)$gen->status,
            'statuslabel' => $gen->status === self::STATUS_REVOKED
                ? get_string('diploma_status_revoked', 'local_grupomakro_core')
                : get_string('diploma_status_generated', 'local_grupomakro_core'),
            'studentname' => $user ? fullname($user) : '',
            'studentdocument' => $user ? self::resolve_user_custom_field($user->id, 'documentnumber') : '',
            'careername' => $lp ? (string)$lp->name : '',
            'templatename' => $template ? (string)$template->name : '',
            'diplomanumber' => (string)$gen->diploma_number,
            'version' => (int)$gen->version,
            'issued_at' => (int)$gen->issued_at,
            'verification_url' => (string)$gen->verification_url,
        ];
    }

    /**
     * Returns the latest PDF binary for a given generation.
     *
     * @param int $generationid
     * @return array{filename:string,mimetype:string,contentbase64:string}|null
     */
    public static function get_generation_pdf(int $generationid): ?array {
        global $DB;
        $doc = $DB->get_record('gmk_diploma_document', ['generationid' => $generationid], '*', IGNORE_MISSING);
        if (!$doc) {
            return null;
        }
        $fs = get_file_storage();
        $file = $fs->get_file(
            context_system::instance()->id,
            'local_grupomakro_core',
            self::FILEAREA_DOCUMENT,
            (int)$doc->fileitemid,
            '/',
            (string)$doc->filename
        );
        if (!$file) {
            return null;
        }
        return [
            'filename' => (string)$doc->filename,
            'mimetype' => (string)$doc->mimetype,
            'contentbase64' => base64_encode($file->get_content()),
        ];
    }

    /**
     * Generates a unique diploma number.
     */
    private static function generate_diploma_number(stdClass $user, ?stdClass $lp): string {
        global $DB;
        $prefix = 'DP';
        if (!empty($user->idnumber)) {
            $prefix .= '-' . preg_replace('/[^A-Za-z0-9]/', '', $user->idnumber);
        }
        if ($lp && !empty($lp->planname)) {
            $prefix .= '-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $lp->planname), 0, 5));
        }
        $prefix = rtrim($prefix, '-');
        $attempts = 0;
        do {
            $candidate = $prefix . '-' . date('Y') . '-' . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $attempts++;
        } while ($DB->record_exists('gmk_diploma_generation', ['diploma_number' => $candidate]) && $attempts < 5);
        return $candidate;
    }

    /**
     * Generates a unique random token for the public verification URL.
     */
    private static function generate_verification_token(): string {
        global $DB;
        do {
            $token = bin2hex(random_bytes(16));
        } while ($DB->record_exists('gmk_diploma_generation', ['verification_token' => $token]));
        return $token;
    }

    /**
     * Builds the public verification URL.
     */
    public static function build_verification_url(string $token): string {
        global $CFG;
        return rtrim($CFG->wwwroot, '/') . '/local/grupomakro_core/pages/diploma_verify.php?t=' . $token;
    }

    /**
     * Normalize a font name to a safe tcpdf identifier or passthrough.
     */
    public static function normalize_font(string $name): string {
        $name = trim($name);
        if ($name === '') {
            return 'helvetica';
        }
        // Allow only [a-z0-9_-] to keep tcpdf happy.
        $clean = strtolower(preg_replace('/[^a-z0-9_\-]/i', '', $name));
        return $clean === '' ? 'helvetica' : $clean;
    }

    /**
     * Normalize a hex color (#rgb / #rrggbb).
     */
    public static function normalize_color(string $hex): string {
        $hex = trim($hex);
        if (preg_match('/^#([0-9a-fA-F]{6})$/', $hex, $m)) {
            return '#' . strtolower($m[1]);
        }
        if (preg_match('/^#([0-9a-fA-F]{3})$/', $hex, $m)) {
            $r = $m[1][0] . $m[1][0];
            $g = $m[1][1] . $m[1][1];
            $b = $m[1][2] . $m[1][2];
            return '#' . strtolower($r . $g . $b);
        }
        return '#000000';
    }

    /**
     * Returns the template icon / meta for the API.
     */
    public static function get_variable_catalog_for_api(): array {
        $catalog = self::get_variable_catalog();
        $out = [];
        foreach ($catalog as $code => $info) {
            $out[] = ['code' => $code, 'label' => $info['label']];
        }
        return $out;
    }
}
