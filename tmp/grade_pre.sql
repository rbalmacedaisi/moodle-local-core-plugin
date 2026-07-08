-- Pre-flight check for both students
SELECT '---JARELIS 1998 BEFORE---' as '';
SELECT gg.id, gg.itemid, gg.userid, gg.finalgrade, gg.rawgrade, gg.timemodified, ag.id as ag_id, ag.grade, ag.timemodified as ag_mod FROM isi_grade_grades gg LEFT JOIN isi_assign_grades ag ON ag.assignment = 183 AND ag.userid = gg.userid WHERE gg.itemid = 1947 AND gg.userid IN (1998, 2000);

SELECT '---SUBMISSION COUNT FOR BOTH---' as '';
SELECT userid, COUNT(*) as subs FROM isi_assign_submission WHERE assignment = 183 AND userid IN (1998, 2000) GROUP BY userid;
