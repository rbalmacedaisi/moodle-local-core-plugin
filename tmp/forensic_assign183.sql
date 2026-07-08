SELECT id, course, name, duedate, allowsubmissionsfromdate, grade, timemodified, cutoffdate, gradingduedate FROM isi_assign WHERE id = 183\G
SELECT '---OTHER ASSIGN IN COURSE 86---' as '';
SELECT id, course, name, duedate, grade, timemodified FROM isi_assign WHERE course = 86 ORDER BY id;
SELECT '---ASSIGN_SUBMISSION COLS---' as '';
DESCRIBE isi_assign_submission;
