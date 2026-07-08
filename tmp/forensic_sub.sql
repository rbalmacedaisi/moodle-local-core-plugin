-- Submissions for assign 183 (the activity)
SELECT COUNT(*) AS submissions_183 FROM isi_assign_submission WHERE assignment = 183;
SELECT '---RECOUNT BY STATUS---' as '';
SELECT status, COUNT(*) c FROM isi_assign_submission WHERE assignment = 183 GROUP BY status;
SELECT '---SAMPLES---' as '';
SELECT id, assignment, userid, timecreated, timemodified, status, groupid, attemptnumber, latest FROM isi_assign_submission WHERE assignment = 183 ORDER BY id DESC LIMIT 10;
SELECT '---GRADE_ITEMS for assigns in course86---' as '';
SELECT id, iteminstance, itemmodule, itemtype, grademax, gradepass, timecreated FROM isi_grade_items WHERE itemmodule = 'assign' AND iteminstance IN (183, 296, 311, 341, 342, 362, 370, 425, 430, 472, 640);
