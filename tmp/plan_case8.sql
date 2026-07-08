SELECT '==FILE SUBMISSIONS PARA USER 2735 EN ASSIGN 519==' as '';
SELECT s.id AS submission_id, s.assignment, s.status, f.id AS file_id, f.filename, f.filesize, f.contenthash, f.timecreated FROM isi_assign_submission s LEFT JOIN isi_files f ON f.component = 'assignsubmission_file' AND f.itemid = s.id WHERE s.assignment = 519 AND s.userid = 2735;
SELECT '==GRADE_HISTORY ITEM 2720 USER 2735==' as '';
SELECT action, source, itemid, userid, finalgrade, rawgrade, from_unixtime(timemodified) dt FROM isi_grade_grades_history WHERE itemid = 2720 AND userid = 2735 ORDER BY timemodified;
SELECT '==COUNT SUBMITTED PER ASSSIGN 519 W FILES==' as '';
SELECT s.status, COUNT(*) AS n, COUNT(DISTINCT s.userid) AS users FROM isi_assign_submission s WHERE s.assignment = 519 GROUP BY s.status;
SELECT '==EXISTEN ARCHIVOS DE MARIA 2735 EN FILEDIR==';
SELECT 'placeholder' AS status;
