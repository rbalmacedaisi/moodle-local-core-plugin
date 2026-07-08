-- All info for student 1998 (Jarelis Guisselle Ornano Paredes)
SELECT '---USER PROFILE---' as '';
SELECT id, firstname, lastname, email, username FROM isi_user WHERE id = 1998;
SELECT '---STUDENT IN COURSE 86 (Matematica II)?---' as '';
SELECT * FROM isi_user_enrolments ue JOIN isi_enrol e ON e.id = ue.enrolid WHERE ue.userid = 1998 AND e.courseid = 86;
SELECT '---IN GROUP 704?---' as '';
SELECT * FROM isi_groups_members WHERE groupid = 704 AND userid = 1998;
SELECT '---GROUPS 1998 IS IN---' as '';
SELECT g.id, g.name, g.courseid FROM isi_groups_members gm JOIN isi_groups g ON g.id = gm.groupid WHERE gm.userid = 1998;
SELECT '---ANY SUBMISSION 1998---' as '';
SELECT * FROM isi_assign_submission WHERE userid = 1998 AND assignment = 183;
SELECT '---ASSIGN_GRADES 1998 FOR ASSIGN 183---' as '';
SELECT * FROM isi_assign_grades WHERE assignment = 183 AND userid = 1998;
SELECT '---GRADE_GRADES 1998 FOR ITEM 1947---' as '';
SELECT * FROM isi_grade_grades WHERE itemid = 1947 AND userid = 1998\G
SELECT '---GRADE_HISTORY 1998 ITEM 1947---' as '';
SELECT * FROM isi_grade_grades_history WHERE itemid = 1947 AND userid = 1998 ORDER BY timemodified;
SELECT '---ALL GRADES 1998 EVER (any item)---' as '';
SELECT gg.id, gg.itemid, gi.itemname, gi.iteminstance, gi.itemtype, gg.finalgrade, gg.rawgrade, gg.timemodified FROM isi_grade_grades gg LEFT JOIN isi_grade_items gi ON gi.id=gg.itemid WHERE gg.userid=1998 AND gg.finalgrade IS NOT NULL ORDER BY gg.timemodified DESC LIMIT 30;
SELECT '---ACTIVITY LOG?---' as '';
SHOW TABLES LIKE 'isi_log%';
SELECT '---OTHER STD ATTEMPTS---' as '';
SELECT * FROM isi_quiz_attempts WHERE userid = 1998 LIMIT 5;
