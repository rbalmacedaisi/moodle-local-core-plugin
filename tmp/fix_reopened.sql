-- Plan BD:
-- Para los 26 PENDIENTES (no grade) con archivos: UPDATE status='submitted' para sacarlos del limbo
-- Para los 67 CALIFICADOS: solo el code fix los hace visibles (no tocar BD)
-- Para los 10 SIN ARCHIVOS: dejar como están (son huérfanos reales)

-- ANTES - Listado de los 26 pendientes con archivos
SELECT '==PENDIENTES REOPENED CON ARCHIVOS==' as '';
SELECT s.id AS submission_id, s.assignment, s.userid, u.username AS cedula, u.firstname, u.lastname, a.name AS activity, c.shortname AS course, (SELECT COUNT(*) FROM isi_files f WHERE f.component = 'assignsubmission_file' AND f.itemid = s.id AND f.filename <> '.') AS files FROM isi_assign_submission s JOIN isi_assign a ON a.id = s.assignment JOIN isi_course c ON c.id = a.course JOIN isi_user u ON u.id = s.userid LEFT JOIN isi_assign_grades g ON g.assignment = s.assignment AND g.userid = s.userid AND g.attemptnumber = s.attemptnumber WHERE s.status = 'reopened' AND s.latest = 1 AND (g.grade IS NULL OR g.grade < 0) AND EXISTS (SELECT 1 FROM isi_files f WHERE f.component = 'assignsubmission_file' AND f.itemid = s.id AND f.filename <> '.') ORDER BY s.assignment, s.userid;

-- Calcular COUNT para validación
SELECT COUNT(*) AS to_fix FROM isi_assign_submission s LEFT JOIN isi_assign_grades g ON g.assignment = s.assignment AND g.userid = s.userid AND g.attemptnumber = s.attemptnumber WHERE s.status = 'reopened' AND s.latest = 1 AND (g.grade IS NULL OR g.grade < 0) AND EXISTS (SELECT 1 FROM isi_files f WHERE f.component = 'assignsubmission_file' AND f.itemid = s.id AND f.filename <> '.');

START TRANSACTION;

UPDATE isi_assign_submission s
LEFT JOIN isi_assign_grades g ON g.assignment = s.assignment AND g.userid = s.userid AND g.attemptnumber = s.attemptnumber
SET s.status = 'submitted'
WHERE s.status = 'reopened' AND s.latest = 1 AND (g.grade IS NULL OR g.grade < 0)
  AND EXISTS (SELECT 1 FROM isi_files f WHERE f.component = 'assignsubmission_file' AND f.itemid = s.id AND f.filename <> '.');

SELECT ROW_COUNT() AS rows_updated;

-- Validar estado post-update
SELECT '==DESPUES - REOPENED RESTANTES==' as '';
SELECT COUNT(*) AS remaining_reopened_with_files FROM isi_assign_submission s LEFT JOIN isi_assign_grades g ON g.assignment = s.assignment AND g.userid = s.userid AND g.attemptnumber = s.attemptnumber WHERE s.status = 'reopened' AND s.latest = 1 AND EXISTS (SELECT 1 FROM isi_files f WHERE f.component = 'assignsubmission_file' AND f.itemid = s.id AND f.filename <> '.');

SELECT '==DISTRIBUCION POST-FIX==' as '';
SELECT status, COUNT(*) AS n, SUM(CASE WHEN (g.grade IS NULL OR g.grade < 0) THEN 1 ELSE 0 END) AS pending_count, SUM(CASE WHEN g.grade >= 0 THEN 1 ELSE 0 END) AS graded_count FROM isi_assign_submission s LEFT JOIN isi_assign_grades g ON g.assignment = s.assignment AND g.userid = s.userid AND g.attemptnumber = s.attemptnumber WHERE s.latest = 1 GROUP BY status;

SELECT '==VERIFICAR MARIA (2735) POST-FIX==' as '';
SELECT id, assignment, userid, status FROM isi_assign_submission WHERE assignment = 519 AND userid = 2735;

COMMIT;