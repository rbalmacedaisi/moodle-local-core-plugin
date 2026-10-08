-- =============================================================================
-- DES-MOODLE-003: Post-restore FK integrity check for isi_user.id
-- =============================================================================
--
-- PURPOSE:
--   Validates that the staging Moodle DB (restored from a sanitised prod dump)
--   preserves referential integrity for every FK that points at isi_user.id.
--   This is the cornerstone of the "Opcion 1" decision:
--     - NO se sanitiza isi_user.id
--     - el bridge Odoo <-> Moodle (moodle.user.moodle_user_id -> isi_user.id)
--       sigue siendo un FK logico valido
--   Si este script falla, el bridge se rompe y los PRs DES-ODOO-001-bis /
--   DES-ODOO-002 pierden su razon de ser en staging.
--
-- WHEN TO RUN:
--   1. Despues de `mysqldump | mysql` del staging, ANTES de habilitar
--      trafico de LXP contra staging.
--   2. Como parte del runbook del @asistente-de-bases-de-datos
--      (STAGING-DB-RUNBOOK.md), justo despues del cross-check Odoo-side
--      (post_restore_cross_check_moodle_odoo.sql, §1-§5).
--
-- OUTPUT:
--   - §0  resumen ejecutivo (1 fila con VERDICT OK/FAIL)
--   - §1..§N una seccion por tabla con FK a userid; cada seccion muestra
--          conteos huerfanos (debe ser 0 en TODAS)
--   - §99 muestra el rango real de isi_user.id (sirve para que el DBA
--          confirme que el rango coincide con prod o documente el shift)
--
-- ASSUMPTIONS:
--   - Prefijo de tablas: isi_  (config.php default del fork)
--   - Todas las tablas del plugin local_grupomakro_core usan ese mismo prefijo
--     (verificado en db/install.xml)
--   - El dump prod NO fue procesado con scripts que remapeen userid.
--
-- IDEMPOTENT: solo lectura, no muta nada. Seguro de correr N veces.

-- -----------------------------------------------------------------------------
-- §0  RESUMEN EJECUTIVO
-- -----------------------------------------------------------------------------
SELECT
    NOW()                                  AS checked_at,
    'DES-MOODLE-003 FK integrity'          AS check_name,
    (SELECT COUNT(*) FROM isi_user)        AS total_users,
    (SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name LIKE 'isi\\_%' ESCAPE '\\') AS isi_tables,
    CASE
        WHEN (SELECT COUNT(*) FROM (
            -- suma de huerfanos de todas las secciones §1..§N
            SELECT 1 FROM isi_role_assignments   r  WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = r.userid)
            UNION ALL
            SELECT 1 FROM isi_role_assignments   r  WHERE NOT EXISTS (SELECT 1 FROM isi_context  c WHERE c.id = r.contextid)
            UNION ALL
            SELECT 1 FROM isi_enrol              e  WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = e.userid)
            UNION ALL
            SELECT 1 FROM isi_user_enrolments    ue WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = ue.userid)
            UNION ALL
            SELECT 1 FROM isi_user_enrolments    ue WHERE NOT EXISTS (SELECT 1 FROM isi_enrol e WHERE e.id = ue.enrolid)
            UNION ALL
            SELECT 1 FROM isi_course_completions cc WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = cc.userid)
            UNION ALL
            SELECT 1 FROM isi_grade_grades       gg WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = gg.userid)
            UNION ALL
            SELECT 1 FROM isi_grade_grades       gg WHERE NOT EXISTS (SELECT 1 FROM isi_grade_items gi WHERE gi.id = gg.itemid)
            UNION ALL
            SELECT 1 FROM isi_groups_members     gm WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = gm.userid)
            UNION ALL
            SELECT 1 FROM isi_groups_members     gm WHERE NOT EXISTS (SELECT 1 FROM isi_groups g WHERE g.id = gm.groupid)
            UNION ALL
            SELECT 1 FROM isi_cohort_members     cm WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = cm.userid)
            UNION ALL
            SELECT 1 FROM isi_cohort_members     cm WHERE NOT EXISTS (SELECT 1 FROM isi_cohort c WHERE c.id = cm.cohortid)
            UNION ALL
            SELECT 1 FROM isi_files              f  WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = f.userid)
            UNION ALL
            SELECT 1 FROM isi_post               p  WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = p.userid)
            UNION ALL
            SELECT 1 FROM isi_forum_discussions  fd WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = fd.userid)
            UNION ALL
            SELECT 1 FROM isi_forum_posts        fp WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = fp.userid)
            UNION ALL
            SELECT 1 FROM isi_message            m  WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = m.useridfrom)
            UNION ALL
            SELECT 1 FROM isi_message            m  WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = m.useridto)
            UNION ALL
            SELECT 1 FROM isi_logstore_standard_log l WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = l.userid)
            UNION ALL
            -- Tablas del plugin local_grupomakro_core con userid propio
            SELECT 1 FROM isi_gmk_contract_user    WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = userid)
            UNION ALL
            SELECT 1 FROM isi_gmk_course_progre    WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = userid)
            UNION ALL
            SELECT 1 FROM isi_gmk_class_absence_state WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = userid)
            UNION ALL
            SELECT 1 FROM isi_gmk_student_suspension  WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = userid)
            UNION ALL
            SELECT 1 FROM isi_gmk_attendance_temp     WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = userid)
            UNION ALL
            SELECT 1 FROM isi_gmk_financial_status    WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = userid)
            UNION ALL
            SELECT 1 FROM isi_gmk_academic_movements  WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = userid)
            UNION ALL
            SELECT 1 FROM isi_gmk_course_attempts     WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = userid)
        ) AS orphans) = 0
        THEN 'OK ✅'
        ELSE 'FAIL ❌ -- revisar §1..§N'
    END AS verdict;

-- -----------------------------------------------------------------------------
-- §1..§N  DETALLE POR TABLA (todas deben devolver 0 huerfanos)
-- -----------------------------------------------------------------------------
-- Patron: cada query cuenta huerfanos para una FK a userid (o contextid /
-- groupid / cohortid cuando aplica). El header del SELECT identifica la tabla.

SELECT '§1 isi_role_assignments.userid huerfanos' AS check_point,
       COUNT(*) AS orphans
  FROM isi_role_assignments r
 WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = r.userid);

SELECT '§2 isi_role_assignments.contextid huerfanos' AS check_point,
       COUNT(*) AS orphans
  FROM isi_role_assignments r
 WHERE NOT EXISTS (SELECT 1 FROM isi_context c WHERE c.id = r.contextid);

SELECT '§3 isi_enrol.userid huerfanos' AS check_point,
       COUNT(*) AS orphans
  FROM isi_enrol e
 WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = e.userid);

SELECT '§4 isi_user_enrolments.userid huerfanos' AS check_point,
       COUNT(*) AS orphans
  FROM isi_user_enrolments ue
 WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = ue.userid);

SELECT '§5 isi_user_enrolments.enrolid huerfanos' AS check_point,
       COUNT(*) AS orphans
  FROM isi_user_enrolments ue
 WHERE NOT EXISTS (SELECT 1 FROM isi_enrol e WHERE e.id = ue.enrolid);

SELECT '§6 isi_course_completions.userid huerfanos' AS check_point,
       COUNT(*) AS orphans
  FROM isi_course_completions cc
 WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = cc.userid);

SELECT '§7 isi_grade_grades.userid huerfanos' AS check_point,
       COUNT(*) AS orphans
  FROM isi_grade_grades gg
 WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = gg.userid);

SELECT '§8 isi_grade_grades.itemid huerfanos' AS check_point,
       COUNT(*) AS orphans
  FROM isi_grade_grades gg
 WHERE NOT EXISTS (SELECT 1 FROM isi_grade_items gi WHERE gi.id = gg.itemid);

SELECT '§9 isi_groups_members.userid huerfanos' AS check_point,
       COUNT(*) AS orphans
  FROM isi_groups_members gm
 WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = gm.userid);

SELECT '§10 isi_groups_members.groupid huerfanos' AS check_point,
       COUNT(*) AS orphans
  FROM isi_groups_members gm
 WHERE NOT EXISTS (SELECT 1 FROM isi_groups g WHERE g.id = gm.groupid);

SELECT '§11 isi_cohort_members.userid huerfanos' AS check_point,
       COUNT(*) AS orphans
  FROM isi_cohort_members cm
 WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = cm.userid);

SELECT '§12 isi_cohort_members.cohortid huerfanos' AS check_point,
       COUNT(*) AS orphans
  FROM isi_cohort_members cm
 WHERE NOT EXISTS (SELECT 1 FROM isi_cohort c WHERE c.id = cm.cohortid);

SELECT '§13 isi_files.userid huerfanos' AS check_point,
       COUNT(*) AS orphans
  FROM isi_files f
 WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = f.userid);

SELECT '§14 isi_post.userid huerfanos' AS check_point,
       COUNT(*) AS orphans
  FROM isi_post p
 WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = p.userid);

SELECT '§15 isi_forum_discussions.userid huerfanos' AS check_point,
       COUNT(*) AS orphans
  FROM isi_forum_discussions fd
 WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = fd.userid);

SELECT '§16 isi_forum_posts.userid huerfanos' AS check_point,
       COUNT(*) AS orphans
  FROM isi_forum_posts fp
 WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = fp.userid);

SELECT '§17 isi_message.useridfrom huerfanos' AS check_point,
       COUNT(*) AS orphans
  FROM isi_message m
 WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = m.useridfrom);

SELECT '§18 isi_message.useridto huerfanos' AS check_point,
       COUNT(*) AS orphans
  FROM isi_message m
 WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = m.useridto);

SELECT '§19 isi_logstore_standard_log.userid huerfanos (esperado >0 si blacklist OK)' AS check_point,
       COUNT(*) AS orphans
  FROM isi_logstore_standard_log l
 WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = l.userid);

-- -----------------------------------------------------------------------------
-- Plugin local_grupomakro_core (tablas con columna userid)
-- -----------------------------------------------------------------------------
SELECT '§30 isi_gmk_contract_user.userid huerfanos' AS check_point,
       COUNT(*) AS orphans
  FROM isi_gmk_contract_user
 WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = userid);

SELECT '§31 isi_gmk_course_progre.userid huerfanos' AS check_point,
       COUNT(*) AS orphans
  FROM isi_gmk_course_progre
 WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = userid);

SELECT '§32 isi_gmk_class_absence_state.userid huerfanos' AS check_point,
       COUNT(*) AS orphans
  FROM isi_gmk_class_absence_state
 WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = userid);

SELECT '§33 isi_gmk_student_suspension.userid huerfanos' AS check_point,
       COUNT(*) AS orphans
  FROM isi_gmk_student_suspension
 WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = userid);

SELECT '§34 isi_gmk_attendance_temp.userid huerfanos' AS check_point,
       COUNT(*) AS orphans
  FROM isi_gmk_attendance_temp
 WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = userid);

SELECT '§35 isi_gmk_financial_status.userid huerfanos' AS check_point,
       COUNT(*) AS orphans
  FROM isi_gmk_financial_status
 WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = userid);

SELECT '§36 isi_gmk_academic_movements.userid huerfanos' AS check_point,
       COUNT(*) AS orphans
  FROM isi_gmk_academic_movements
 WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = userid);

SELECT '§37 isi_gmk_course_attempts.userid huerfanos' AS check_point,
       COUNT(*) AS orphans
  FROM isi_gmk_course_attempts
 WHERE NOT EXISTS (SELECT 1 FROM isi_user u WHERE u.id = userid);

-- -----------------------------------------------------------------------------
-- §99 RANGO DE isi_user.id (documentacion operativa)
-- -----------------------------------------------------------------------------
SELECT
    '§99 rango isi_user.id'                  AS info,
    MIN(id)                                  AS min_id,
    MAX(id)                                  AS max_id,
    COUNT(*)                                 AS total,
    COUNT(DISTINCT username)                 AS distinct_usernames,
    SUM(username REGEXP '^[0-9]+$')          AS usernames_numeric_vat_like,
    SUM(deleted = 1)                         AS deleted_users,
    SUM(suspended = 1)                       AS suspended_users,
    SUM(CONFIRMED = 1)                       AS confirmed_users
  FROM isi_user;

-- -----------------------------------------------------------------------------
-- §100 BLACKLIST defensiva (debe coincidir con STAGING-DB-RUNBOOK.md)
--          Estas tablas NO deben tener FKs que apunten a isi_user.id porque
--          el runbook las trunca o las sanitiza agresivamente. Si las queries
--          de §1..§37 dependen de estas tablas via JOIN, apareceran huerfanos
--          y sera EXPECTED. La blacklist documentada es:
-- -----------------------------------------------------------------------------
--   isi_logstore_standard_log   -- truncar / no restaurar
--   isi_cache_*                 -- no restaurar
--   isi_sessions                -- no restaurar
--   isi_event                   -- se conserva solo metadata
--   isi_backup_*                -- backups huerfanos jul-ago, omitir
--   isi_task_log                -- no restaurar
-- =============================================================================