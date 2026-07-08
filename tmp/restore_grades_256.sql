SELECT '==ESTADO ANTES DE LA RESTAURACION==' as '';
SELECT 'grade_grades con finalgrade para item 2048:' AS check_name, COUNT(*) AS n FROM isi_grade_grades WHERE itemid = 2048 AND finalgrade IS NOT NULL AND finalgrade >= 0;
SELECT 'assign_grades para assign 256:' AS check_name, COUNT(*) AS n FROM isi_assign_grades WHERE assignment = 256;

START TRANSACTION;

-- 1. UPDATE grade_grades: 11 students
UPDATE isi_grade_grades
SET finalgrade = 100.00000,
    rawgrade = 100.00000,
    usermodified = 2613,
    timemodified = UNIX_TIMESTAMP()
WHERE itemid = 2048
  AND userid IN (2009, 2068, 2278, 2328, 2490, 2507, 2515, 2518, 2548, 2558, 2755);

SELECT '==DESPUES UPDATE grade_grades==' as '';
SELECT 'Rows affected:', ROW_COUNT();
SELECT userid, finalgrade, rawgrade, timemodified FROM isi_grade_grades WHERE itemid = 2048 AND userid IN (2009, 2068, 2278, 2328, 2490, 2507, 2515, 2518, 2548, 2558, 2755) ORDER BY userid;

-- 2. INSERT assign_grades para los 11 estudiantes (si no existen)
INSERT INTO isi_assign_grades (assignment, userid, timecreated, timemodified, grader, grade, attemptnumber)
VALUES
  (256, 2009, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 2613, 100.00000, 0),
  (256, 2068, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 2613, 100.00000, 0),
  (256, 2278, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 2613, 100.00000, 0),
  (256, 2328, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 2613, 100.00000, 0),
  (256, 2490, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 2613, 100.00000, 0),
  (256, 2507, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 2613, 100.00000, 0),
  (256, 2515, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 2613, 100.00000, 0),
  (256, 2518, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 2613, 100.00000, 0),
  (256, 2548, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 2613, 100.00000, 0),
  (256, 2558, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 2613, 100.00000, 0),
  (256, 2755, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 2613, 100.00000, 0)
ON DUPLICATE KEY UPDATE grade = 100.00000, grader = 2613, timemodified = UNIX_TIMESTAMP();

SELECT '==DESPUES INSERT assign_grades==' as '';
SELECT userid, grade, grader FROM isi_assign_grades WHERE assignment = 256 AND userid IN (2009, 2068, 2278, 2328, 2490, 2507, 2515, 2518, 2548, 2558, 2755) ORDER BY userid;

-- 3. INSERT history rows
INSERT INTO isi_grade_grades_history (action, oldid, source, timemodified, loggeduser, itemid, userid, rawgrade, rawgrademax, rawgrademin, rawscaleid, usermodified, finalgrade, hidden, locked, locktime, exported, overridden, excluded, feedback, feedbackformat, information, informationformat)
SELECT 2, gg.id, 'manual-restore', UNIX_TIMESTAMP(), 2613, gg.itemid, gg.userid, gg.rawgrade, gg.rawgrademax, gg.rawgrademin, gg.rawscaleid, gg.usermodified, gg.finalgrade, gg.hidden, gg.locked, gg.locktime, gg.exported, gg.overridden, gg.excluded, gg.feedback, gg.feedbackformat, gg.information, gg.informationformat
FROM isi_grade_grades gg WHERE gg.itemid = 2048 AND gg.userid IN (2009, 2068, 2278, 2328, 2490, 2507, 2515, 2518, 2548, 2558, 2755) AND gg.finalgrade IS NOT NULL AND gg.finalgrade >= 0;

SELECT '==HISTORIAL INSERTADO==' as '';
SELECT id, action, source, itemid, userid, finalgrade FROM isi_grade_grades_history WHERE source = 'manual-restore' AND itemid = 2048 ORDER BY userid;

-- 4. MOVE FILE: Actividad_de_Inform?tica_Azafata.pdf (id 25985) y sus annotations
SELECT '==ARCHIVOS A MOVER (itemid actual 65 -> 2754)==' as '';
SELECT id, component, filearea, itemid, filename FROM isi_files WHERE id IN (25985, 86437, 86441, 86444) ORDER BY id;

UPDATE isi_files SET itemid = 2754 WHERE id IN (25985, 86437, 86441, 86444);

SELECT '==ARCHIVOS DESPUES DE MOVER==' as '';
SELECT id, component, filearea, itemid, filename FROM isi_files WHERE id IN (25985, 86437, 86441, 86444) ORDER BY id;

SELECT '==CONTADOR TOTAL DE ARCHIVOS EN SUBMISSION 2754==' as '';
SELECT COUNT(*) AS file_count, SUM(filesize) AS total_size FROM isi_files WHERE component = 'assignsubmission_file' AND itemid = 2754 AND filename <> '.';

SELECT '==COMMIT==';
COMMIT;

SELECT '==VALIDACION POST-COMMIT==' as '';
SELECT 'grade_grades con finalgrade para item 2048:' AS check_name, COUNT(*) AS n FROM isi_grade_grades WHERE itemid = 2048 AND finalgrade IS NOT NULL AND finalgrade >= 0;
SELECT 'assign_grades para assign 256:' AS check_name, COUNT(*) AS n FROM isi_assign_grades WHERE assignment = 256 AND grade >= 0;
SELECT 'ARCHIVOS EN SUBMISSION 2754:' AS check_name, COUNT(*) AS n FROM isi_files WHERE itemid = 2754 AND filename <> '.';
