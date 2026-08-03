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
 * Academic grade resolver (Phase 1).
 *
 * Picks the best grade across re-enrolments using append-only movements
 * recorded by local_grupomakro_academic_movement_manager. Policy:
 *
 *   - Only movements with annulled=0 are considered.
 *   - The official grade is MAX(grade) among those movements.
 *   - When multiple movements share the same grade, the most recent one
 *     (effective_at DESC, id DESC) wins as the "active" record.
 *   - If any attempt is currently active (status='active'), the enrolment
 *     state is Cursando (2) and the official grade is the best historical
 *     movement; when no active attempts exist and the best movement is
 *     terminal (status in {3,4,5,6,7}), that status wins.
 *   - When no movements exist, returns nulls and COURSE_AVAILABLE (1).
 *
 * This resolver does not modify gmk_course_progre or any other existing
 * table. It is consumed by Phase 4 (UI / endpoint integration) and by the
 * CLI test suite shipped with Phase 1.
 *
 * @package     local_grupomakro_core
 * @category    local
 * @copyright   2026 Solutto Consulting
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Resolves the official academic grade for a (user, plan, course) triple.
 */
class local_grupomakro_academic_grade_resolver {

    /** @var int Cursando status. */
    public const STATUS_IN_PROGRESS = 2;

    /** @var int Aprobada / aprobada virtual. */
    public const STATUS_APPROVED = 4;

    /** @var int Reprobada. */
    public const STATUS_FAILED = 5;

    /** @var int Aprobada sin nota consolidada. */
    public const STATUS_COMPLETED = 3;

    /** @var int Pendiente de reválida. */
    public const STATUS_PENDING_REVALID = 6;

    /** @var int Revalidando. */
    public const STATUS_REVALIDATING = 7;

    /** @var int Disponible (sin movimiento). */
    public const STATUS_AVAILABLE = 1;

    /**
     * Returns the official grade, status and provenance.
     *
     * @param int $userid
     * @param int $learningplanid
     * @param int $corecourseid
     * @return array {grade: float|null, status: int, source: string, movement_id: int|null,
     *                effective_at: int|null, attempts_active: int, movements_count: int}
     */
    public static function resolve_official_grade(int $userid, int $learningplanid, int $corecourseid): array {
        global $DB;

        $movements = $DB->get_records_sql(
            'SELECT id, source, source_record_id, grade, course_status, effective_at, annulled
               FROM {gmk_academic_movements}
              WHERE userid = ? AND learningplanid = ? AND corecourseid = ?
                AND annulled = 0',
            [$userid, $learningplanid, $corecourseid],
            0,
            5000
        );

        $activeattempts = $DB->count_records('gmk_course_attempts', [
            'userid'         => $userid,
            'learningplanid' => $learningplanid,
            'corecourseid'   => $corecourseid,
            'status'         => 'active',
        ]);

        if (empty($movements)) {
            return [
                'grade'           => null,
                'status'          => $activeattempts > 0 ? self::STATUS_IN_PROGRESS : self::STATUS_AVAILABLE,
                'source'          => 'none',
                'movement_id'     => null,
                'effective_at'    => null,
                'attempts_active' => $activeattempts,
                'movements_count' => 0,
            ];
        }

        $best = null;
        foreach ($movements as $m) {
            if ($m->grade === null || $m->grade === '') {
                continue;
            }
            $grade = (float)$m->grade;
            if ($best === null
                || $grade > $best->grade
                || ($grade === $best->grade && (
                    (int)$m->effective_at > (int)$best->effective_at
                    || ((int)$m->effective_at === (int)$best->effective_at && (int)$m->id > (int)$best->id)
                ))
            ) {
                $best = $m;
            }
        }

        if ($best === null) {
            // Movements exist but none carry a grade (e.g. integrated_grade row with no mark yet).
            return [
                'grade'           => null,
                'status'          => $activeattempts > 0 ? self::STATUS_IN_PROGRESS : self::STATUS_AVAILABLE,
                'source'          => 'movements_without_grade',
                'movement_id'     => null,
                'effective_at'    => null,
                'attempts_active' => $activeattempts,
                'movements_count' => count($movements),
            ];
        }

        $status = self::STATUS_AVAILABLE;
        if ($activeattempts > 0) {
            $status = self::STATUS_IN_PROGRESS;
        } else if ($best->course_status !== null) {
            $status = (int)$best->course_status;
        }

        return [
            'grade'           => (float)$best->grade,
            'status'          => $status,
            'source'          => (string)$best->source,
            'movement_id'     => (int)$best->id,
            'effective_at'    => (int)$best->effective_at,
            'attempts_active' => $activeattempts,
            'movements_count' => count($movements),
        ];
    }

    /**
     * Helper used by the CLI test suite: returns movements for the same
     * (user, plan, course) without annulled filtering.
     *
     * @param int $userid
     * @param int $learningplanid
     * @param int $corecourseid
     * @return array
     */
    public static function list_all_movements(int $userid, int $learningplanid, int $corecourseid): array {
        global $DB;
        return $DB->get_records('gmk_academic_movements', [
            'userid'         => $userid,
            'learningplanid' => $learningplanid,
            'corecourseid'   => $corecourseid,
        ], 'effective_at DESC, id DESC');
    }
}
