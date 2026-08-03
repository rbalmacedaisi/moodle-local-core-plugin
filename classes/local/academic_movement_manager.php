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
 * Academic movement manager.
 *
 * Phase 1 of the academic history refactor. Persists per-attempt rows and
 * append-only movement rows so the resolver can pick the best grade across
 * re-enrolments instead of overwriting prior evidence.
 *
 * The manager is intentionally additive: it never modifies or deletes
 * gmk_course_progre or the existing audit tables. Existing flows keep
 * writing to their current sinks; this manager only records parallel
 * rows that the resolver can later consume.
 *
 * @package     local_grupomakro_core
 * @category    local
 * @copyright   2026 Solutto Consulting
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Manages academic attempts and movements.
 *
 * Statuses for attempts:
 *   - active:    student is currently enrolled in the class.
 *   - closed:    class finished (grade consolidated or fail).
 *   - withdrawn: student or admin withdrew the enrollment.
 *   - annulled:  attempt annulled (rare; tracked for audit).
 *
 * Movement sources:
 *   - class_close:           closure of gmk_class sets terminal status.
 *   - integrated_grade:     manual Nota Final Integrada grade item.
 *   - homologate:           gmk_homologation_audit action=homologate.
 *   - homologation_revert:  gmk_homologation_audit action=revert.
 *   - revalidation:         gmk_revalidations.status=consolidated.
 *   - module_completion:    module-specific completion.
 *   - withdrawal:           withdrawal of a class enrollment.
 *   - manual:               any admin-driven grade mutation.
 */
class local_grupomakro_academic_movement_manager {

    /** @var string|null Cached hex hash used for idempotent migration inserts. */
    private static $lastidempotencyhash = null;

    /**
     * Returns the next attempt_no for a (user, plan, course) triple.
     *
     * Looks up the existing rows in gmk_course_attempts and returns max + 1
     * (or 1 when no attempts exist). This keeps the column dense so it can
     * be exposed to the UI as "Intento 1, 2, 3...".
     *
     * @param int $userid
     * @param int $learningplanid
     * @param int $corecourseid
     * @return int
     */
    public static function next_attempt_no(int $userid, int $learningplanid, int $corecourseid): int {
        global $DB;

        $max = $DB->get_field_sql(
            'SELECT MAX(attempt_no) FROM {gmk_course_attempts} WHERE userid = ? AND learningplanid = ? AND corecourseid = ?',
            [$userid, $learningplanid, $corecourseid]
        );
        return ((int)$max) + 1;
    }

    /**
     * Creates (or updates) an attempt row. If the (user, plan, course, attempt_no)
     * already exists, only the mutable fields (status, end_date, timemodified)
     * are updated.
     *
     * @param array $payload
     * @return int attempt id
     */
    public static function upsert_attempt(array $payload): int {
        global $DB;

        $required = ['userid', 'learningplanid', 'corecourseid', 'classid', 'is_module', 'enroll_date'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $payload)) {
                throw new coding_exception("upsert_attempt missing field: $key");
            }
        }

        $attemptno = isset($payload['attempt_no']) ? (int)$payload['attempt_no']
            : self::next_attempt_no((int)$payload['userid'], (int)$payload['learningplanid'], (int)$payload['corecourseid']);

        $existing = $DB->get_record('gmk_course_attempts', [
            'userid'         => (int)$payload['userid'],
            'learningplanid' => (int)$payload['learningplanid'],
            'corecourseid'   => (int)$payload['corecourseid'],
            'attempt_no'     => $attemptno,
        ]);

        $now = time();
        $record = (object)[
            'userid'         => (int)$payload['userid'],
            'learningplanid' => (int)$payload['learningplanid'],
            'corecourseid'   => (int)$payload['corecourseid'],
            'classid'        => isset($payload['classid']) ? (int)$payload['classid'] : null,
            'attempt_no'     => $attemptno,
            'is_module'      => empty($payload['is_module']) ? 0 : 1,
            'enroll_date'    => (int)$payload['enroll_date'],
            'end_date'       => isset($payload['end_date']) ? (int)$payload['end_date'] : null,
            'status'         => isset($payload['status']) ? (string)$payload['status'] : 'active',
            'usermodified'   => isset($payload['usermodified']) ? (int)$payload['usermodified'] : 0,
            'timecreated'    => $now,
            'timemodified'   => $now,
        ];

        if ($existing) {
            $record->id = $existing->id;
            $record->timecreated = $existing->timecreated;
            $DB->update_record('gmk_course_attempts', $record);
            return (int)$existing->id;
        }

        return (int)$DB->insert_record('gmk_course_attempts', $record);
    }

    /**
     * Append-only movement insert with idempotency.
     *
     * The idempotency hash dedupes on (user, plan, course, source, source_record_id).
     * Re-running the migration CLI therefore does not duplicate rows.
     *
     * @param array $payload Required keys: userid, learningplanid, corecourseid, source.
     *                       Optional keys: attempt_id, classid, source_record_id,
     *                       grade, course_status, effective_at, usermodified.
     * @return int movement id (or existing id when dedup hits)
     */
    public static function record_movement(array $payload): int {
        global $DB;

        $required = ['userid', 'learningplanid', 'corecourseid', 'source'];
        foreach ($required as $key) {
            if (!array_key_exists($key, $payload)) {
                throw new coding_exception("record_movement missing field: $key");
            }
        }

        $userid         = (int)$payload['userid'];
        $learningplanid = (int)$payload['learningplanid'];
        $corecourseid   = (int)$payload['corecourseid'];
        $source         = (string)$payload['source'];
        $sourcerecordid = isset($payload['source_record_id']) ? (int)$payload['source_record_id'] : 0;

        // Idempotency: skip when the same logical source already produced a row.
        if ($sourcerecordid > 0) {
            $existing = $DB->get_record('gmk_academic_movements', [
                'userid'         => $userid,
                'learningplanid' => $learningplanid,
                'corecourseid'   => $corecourseid,
                'source'         => $source,
                'source_record_id' => $sourcerecordid,
            ]);
            if ($existing) {
                return (int)$existing->id;
            }
        }

        $now = time();
        $effectiveat = isset($payload['effective_at']) ? (int)$payload['effective_at'] : $now;
        $record = (object)[
            'userid'          => $userid,
            'learningplanid'  => $learningplanid,
            'corecourseid'    => $corecourseid,
            'attempt_id'      => isset($payload['attempt_id']) ? (int)$payload['attempt_id'] : null,
            'classid'         => isset($payload['classid']) ? (int)$payload['classid'] : null,
            'source'          => $source,
            'source_record_id' => $sourcerecordid > 0 ? $sourcerecordid : null,
            'grade'           => isset($payload['grade']) ? (float)$payload['grade'] : null,
            'course_status'   => isset($payload['course_status']) ? (int)$payload['course_status'] : null,
            'effective_at'    => $effectiveat,
            'annulled'        => 0,
            'annulled_by'     => null,
            'annulled_at'     => null,
            'annul_reason'    => null,
            'usermodified'    => isset($payload['usermodified']) ? (int)$payload['usermodified'] : 0,
            'timecreated'     => $now,
            'timemodified'    => $now,
        ];

        return (int)$DB->insert_record('gmk_academic_movements', $record);
    }

    /**
     * Annul a movement with full audit snapshot.
     *
     * Stores the movement state into gmk_movement_deletion_log before flipping
     * annulled=1 so the original evidence is never lost.
     *
     * @param int $movementId
     * @param string $reason Required, min 20 chars (enforced).
     * @param int $actor
     * @return bool true on success
     * @throws moodle_exception when the reason is too short or the movement is already annulled
     */
    public static function annul_movement(int $movementId, string $reason, int $actor): bool {
        global $DB;

        $reason = trim($reason);
        if (strlen($reason) < 20) {
            throw new moodle_exception('reasontooshort', 'error', '', null,
                'El motivo debe tener al menos 20 caracteres.');
        }

        $movement = $DB->get_record('gmk_academic_movements', ['id' => $movementId], '*', MUST_EXIST);
        if ((int)$movement->annulled === 1) {
            throw new moodle_exception('alreadyannulled', 'error', '', null,
                'El movimiento ya estaba anulado.');
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            $snapshot = [
                'movement_id'    => (int)$movement->id,
                'userid'         => (int)$movement->userid,
                'learningplanid' => (int)$movement->learningplanid,
                'corecourseid'   => (int)$movement->corecourseid,
                'attempt_id'     => $movement->attempt_id ? (int)$movement->attempt_id : null,
                'classid'        => $movement->classid ? (int)$movement->classid : null,
                'source'         => $movement->source,
                'source_record_id' => $movement->source_record_id ? (int)$movement->source_record_id : null,
                'grade'          => $movement->grade !== null ? (float)$movement->grade : null,
                'course_status'  => $movement->course_status !== null ? (int)$movement->course_status : null,
                'effective_at'   => (int)$movement->effective_at,
                'snapshot_at'    => time(),
            ];

            $log = (object)[
                'movement_id'    => (int)$movement->id,
                'userid'         => (int)$movement->userid,
                'learningplanid' => (int)$movement->learningplanid,
                'corecourseid'   => (int)$movement->corecourseid,
                'snapshot_json'  => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                'reason'         => $reason,
                'acted_by'       => $actor,
                'acted_at'       => time(),
            ];
            $DB->insert_record('gmk_movement_deletion_log', $log);

            $DB->set_field('gmk_academic_movements', 'annulled', 1, ['id' => $movementId]);
            $DB->set_field('gmk_academic_movements', 'annulled_by', $actor, ['id' => $movementId]);
            $DB->set_field('gmk_academic_movements', 'annulled_at', time(), ['id' => $movementId]);
            $DB->set_field('gmk_academic_movements', 'annul_reason', $reason, ['id' => $movementId]);
            $DB->set_field('gmk_academic_movements', 'timemodified', time(), ['id' => $movementId]);

            $transaction->allow_commit();
        } catch (Exception $e) {
            $transaction->rollback($e);
            throw $e;
        }

        return true;
    }

    /**
     * Lists movements for one (user, plan, course) triple, ordered by effective_at DESC.
     *
     * @param int $userid
     * @param int $learningplanid
     * @param int $corecourseid
     * @param bool $includeAnnulled When false, only annulled=0 are returned.
     * @return array list of stdClass records
     */
    public static function list_movements(int $userid, int $learningplanid, int $corecourseid, bool $includeAnnulled = true): array {
        global $DB;

        $params = [$userid, $learningplanid, $corecourseid];
        $sql = 'userid = ? AND learningplanid = ? AND corecourseid = ?';
        if (!$includeAnnulled) {
            $sql .= ' AND annulled = 0';
        }
        return $DB->get_records_select('gmk_academic_movements', $sql, $params, 'effective_at DESC, id DESC');
    }
}
