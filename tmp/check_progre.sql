-- Check course progress table for students in this group
SELECT '==GMK_COURSE_PROGRE FOR COURSE 74 (INFOA)==';
DESCRIBE isi_gmk_course_progre;
SELECT '==PROGRESS ENTRIES FOR STUDENTS IN GROUP 660 ==';
SELECT cp.* FROM isi_gmk_course_progre cp JOIN isi_groups_members gm ON gm.userid = cp.userid WHERE gm.groupid = 660 AND cp.courseid = 74 LIMIT 20;
SELECT '==STUDENTS WITH ASSIGN 255 SUBMISSION BUT NO GRADE PROGRESS==';
SELECT s.userid, u.firstname, u.lastname FROM isi_assign_submission s JOIN isi_user u ON u.id = s.userid WHERE s.assignment = 255 ORDER BY s.userid;