SELECT '==CURRENT STATE==';
SELECT gg.id, gg.userid, gg.finalgrade, gg.rawgrade, gg.usermodified, gg.timemodified, ag.id as ag_id, ag.grade, ag.grader, ag.timemodified as ag_mod FROM isi_grade_grades gg LEFT JOIN isi_assign_grades ag ON ag.assignment = 183 AND ag.userid = gg.userid WHERE gg.itemid = 1947 AND gg.userid IN (1998, 2000) ORDER BY gg.userid;
SELECT '==HISTORY RECENT FOR THESE USERS==';
SELECT id, action, oldid, source, itemid, userid, rawgrade, finalgrade, timemodified FROM isi_grade_grades_history WHERE itemid = 1947 AND userid IN (1998, 2000) ORDER BY id DESC LIMIT 10;
