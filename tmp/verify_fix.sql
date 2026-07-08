-- Simulate EXACTLY the gmk_get_pending_grading_items query for Maria and the other 19 fixed cases
SELECT '==PENDANT GRADING (POST-FIX) - SHOULD NOW INCLUDE MARIA==' as '';
SELECT s.id AS submissionid, s.userid, u.firstname, u.lastname, u.username AS cedula, a.name AS activity_name, c.shortname AS course, s.status AS submissionstatus, g.grade
FROM isi_assign_submission s
JOIN isi_assign a ON a.id = s.assignment
JOIN isi_course c ON c.id = a.course
JOIN isi_user u ON u.id = s.userid
LEFT JOIN isi_assign_grades g ON g.assignment = a.id AND g.userid = s.userid AND g.attemptnumber = s.attemptnumber
WHERE
  -- PENDING filter (post-fix): submitted OR (reopened AND has files)
  (
    s.status = 'submitted'
    OR (s.status = 'reopened' AND EXISTS (SELECT 1 FROM isi_files f WHERE f.component = 'assignsubmission_file' AND f.itemid = s.id AND f.filename <> '.'))
  )
  AND s.latest = 1
  AND (g.grade IS NULL OR g.grade < 0)
  AND s.userid IN (2735, 2228, 2696, 2602, 2609, 2552, 2220, 2315, 2321, 2481, 2794, 2677, 2512, 2867, 2572, 2846, 2658, 2670, 2117, 2315)
ORDER BY s.assignment, s.userid;

SELECT '==VERIFICACION DIRECTA MARIA 2735 ASSIGN 519==' as '';
SELECT s.id AS sub_id, s.userid, s.assignment, s.status AS submission_status, g.grade AS assign_grade, gg.finalgrade, (SELECT COUNT(*) FROM isi_files f WHERE f.component = 'assignsubmission_file' AND f.itemid = s.id AND f.filename <> '.') AS num_files
FROM isi_assign_submission s
LEFT JOIN isi_assign_grades g ON g.assignment = s.assignment AND g.userid = s.userid AND g.attemptnumber = s.attemptnumber
LEFT JOIN isi_grade_items gi ON gi.iteminstance = s.assignment AND gi.courseid = (SELECT course FROM isi_assign WHERE id = s.assignment)
LEFT JOIN isi_grade_grades gg ON gg.itemid = gi.id AND gg.userid = s.userid
WHERE s.userid = 2735 AND s.assignment = 519 AND s.latest = 1;

SELECT '==STILL REOPENED IN SYSTEM==' as '';
SELECT 'reopened' AS status, COUNT(*) AS total, SUM(CASE WHEN (SELECT COUNT(*) FROM isi_files f WHERE f.component = 'assignsubmission_file' AND f.itemid = s.id AND f.filename <> '.') > 0 THEN 1 ELSE 0 END) AS with_files, SUM(CASE WHEN g.grade IS NULL OR g.grade < 0 THEN 1 ELSE 0 END) AS pending, SUM(CASE WHEN g.grade >= 0 THEN 1 ELSE 0 END) AS graded FROM isi_assign_submission s LEFT JOIN isi_assign_grades g ON g.assignment = s.assignment AND g.userid = s.userid AND g.attemptnumber = s.attemptnumber WHERE s.status = 'reopened' AND s.latest = 1;