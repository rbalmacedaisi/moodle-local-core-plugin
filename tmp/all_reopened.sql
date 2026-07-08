-- Complete analysis of all reopened submissions in the system
SELECT '==TOTAL REOPENED SUBMISSIONS IN SYSTEM==' as '';
SELECT COUNT(*) AS total_reopened, COUNT(DISTINCT s.assignment) AS num_assigns, COUNT(DISTINCT s.userid) AS num_students FROM isi_assign_submission s WHERE s.status = 'reopened' AND s.latest = 1;

SELECT '==ALL REOPENED SUBMISSIONS DETAIL==' as '';
SELECT s.id, s.assignment AS assign_id, a.name AS activity_name, a.course AS course_id, c.fullname AS course_name, s.userid, u.firstname, u.lastname, u.username AS cedula, s.timecreated AS sub_created, s.timemodified AS sub_modified, g.grade AS current_grade, gg.finalgrade AS final_grade, (SELECT COUNT(*) FROM isi_files f WHERE f.component = 'assignsubmission_file' AND f.itemid = s.id AND f.filename != '.') AS file_count, (SELECT COUNT(*) FROM isi_assign_grades WHERE assignment = s.assignment AND userid = s.userid) AS grade_records FROM isi_assign_submission s JOIN isi_assign a ON a.id = s.assignment JOIN isi_course c ON c.id = a.course JOIN isi_user u ON u.id = s.userid LEFT JOIN isi_assign_grades g ON g.assignment = s.assignment AND g.userid = s.userid AND g.attemptnumber = s.attemptnumber LEFT JOIN isi_grade_grades gg ON gg.itemid IN (SELECT id FROM isi_grade_items WHERE iteminstance = s.assignment AND courseid = a.course) AND gg.userid = s.userid WHERE s.status = 'reopened' AND s.latest = 1 ORDER BY s.assignment, s.userid;

SELECT '==REOPENED PER COURSE==' as '';
SELECT a.course AS course_id, c.shortname, COUNT(*) AS reopened_count FROM isi_assign_submission s JOIN isi_assign a ON a.id = s.assignment JOIN isi_course c ON c.id = a.course WHERE s.status = 'reopened' AND s.latest = 1 GROUP BY a.course, c.shortname ORDER BY reopened_count DESC;

SELECT '==REOPENED WITH vs WITHOUT GRADE==' as '';
SELECT CASE WHEN g.grade IS NULL OR g.grade < 0 THEN 'PENDING (no grade)' WHEN g.grade >= 0 THEN 'GRADED (has grade)' END AS grading_state, COUNT(*) AS n FROM isi_assign_submission s LEFT JOIN isi_assign_grades g ON g.assignment = s.assignment AND g.userid = s.userid AND g.attemptnumber = s.attemptnumber WHERE s.status = 'reopened' AND s.latest = 1 GROUP BY grading_state;

SELECT '==REOPENED WITH vs WITHOUT FILES==' as '';
SELECT CASE WHEN (SELECT COUNT(*) FROM isi_files f WHERE f.component = 'assignsubmission_file' AND f.itemid = s.id AND f.filename != '.') > 0 THEN 'HAS FILES' ELSE 'NO FILES' END AS files_state, COUNT(*) AS n FROM isi_assign_submission s WHERE s.status = 'reopened' AND s.latest = 1 GROUP BY files_state;

SELECT '==ASSIGN ATTEMPTREOPENMETHOD FOR REOPENED CASES==' as '';
SELECT DISTINCT a.attemptreopenmethod, a.maxattempts, COUNT(*) AS cnt FROM isi_assign_submission s JOIN isi_assign a ON a.id = s.assignment WHERE s.status = 'reopened' AND s.latest = 1 GROUP BY a.attemptreopenmethod, a.maxattempts;