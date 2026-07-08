-- Students enrolled in group 704
SELECT COUNT(DISTINCT userid) AS total_users_in_group_704 FROM isi_groups_members WHERE groupid = 704;
SELECT '---SUBMISSION vs GRADE COMPARISON---' as '';
SELECT 'Students with submissions' as label, COUNT(DISTINCT s.userid) AS n FROM isi_assign_submission s WHERE s.assignment = 183;
SELECT 'Students with non-NULL grade in grade_grades for item 1947' as label, COUNT(DISTINCT gg.userid) AS n FROM isi_grade_grades gg WHERE gg.itemid = 1947 AND gg.finalgrade IS NOT NULL;
SELECT '---LIST GRADED---' as '';
SELECT gg.userid, u.firstname, u.lastname, gg.finalgrade, gg.rawgrade, gg.timemodified FROM isi_grade_grades gg JOIN isi_user u ON u.id = gg.userid WHERE gg.itemid = 1947 AND gg.finalgrade IS NOT NULL ORDER BY gg.userid;
SELECT '---NO SUBMISSION BUT GRADED?---' as '';
SELECT gg.userid, u.firstname, u.lastname, gg.finalgrade FROM isi_grade_grades gg JOIN isi_user u ON u.id = gg.userid WHERE gg.itemid = 1947 AND gg.finalgrade IS NOT NULL AND gg.userid NOT IN (SELECT userid FROM isi_assign_submission WHERE assignment = 183);
SELECT '---USERS WITH SUBMISSION MISSING GRADE---' as '';
SELECT s.userid, u.firstname, u.lastname, gg.finalgrade FROM isi_assign_submission s JOIN isi_user u ON u.id = s.userid LEFT JOIN isi_grade_grades gg ON gg.itemid = 1947 AND gg.userid = s.userid WHERE s.assignment = 183 ORDER BY s.userid;
