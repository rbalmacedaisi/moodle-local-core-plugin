-- Grades for assign 183 (itemid 1947)
SELECT COUNT(*) AS total_grade_grades FROM isi_grade_grades WHERE itemid = 1947;
SELECT '---GRADE GRADES LIST---' as '';
SELECT gg.id, gg.itemid, gg.userid, gg.finalgrade, gg.rawgrade, gg.timemodified, gg.overridden, u.firstname, u.lastname FROM isi_grade_grades gg LEFT JOIN isi_user u ON u.id = gg.userid WHERE gg.itemid = 1947 ORDER BY gg.userid;
SELECT '---GRADE OUTCOMES?---' as '';
DESCRIBE isi_grade_grades;
