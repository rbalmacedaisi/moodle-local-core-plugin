-- Check grade_grades (master gradebook) for both items
SELECT '==GRADE_GRADES FOR ITEM 2047 (assign 255 Vuelo Digital)==';
SELECT COUNT(*) AS total, SUM(CASE WHEN finalgrade IS NOT NULL THEN 1 ELSE 0 END) AS with_grade FROM isi_grade_grades WHERE itemid = 2047;
SELECT '==STUDENTS WITH GRADE==';
SELECT gg.userid, u.firstname, u.lastname, gg.finalgrade, gg.rawgrade, gg.timemodified FROM isi_grade_grades gg JOIN isi_user u ON u.id = gg.userid WHERE gg.itemid = 2047 AND gg.finalgrade IS NOT NULL ORDER BY gg.userid;
SELECT '==GRADE_GRADES FOR ITEM 2048 (assign 256 Quiz)==';
SELECT COUNT(*) AS total, SUM(CASE WHEN finalgrade IS NOT NULL THEN 1 ELSE 0 END) AS with_grade FROM isi_grade_grades WHERE itemid = 2048;
SELECT '==STUDENTS WITH GRADE==';
SELECT gg.userid, u.firstname, u.lastname, gg.finalgrade, gg.rawgrade, gg.timemodified FROM isi_grade_grades gg JOIN isi_user u ON u.id = gg.userid WHERE gg.itemid = 2048 AND gg.finalgrade IS NOT NULL ORDER BY gg.userid;
SELECT '==USERS IN GROUP 660 (linked to section 924)==';
SELECT u.id, u.firstname, u.lastname FROM isi_groups_members gm JOIN isi_user u ON u.id = gm.userid WHERE gm.groupid = 660 ORDER BY u.id;