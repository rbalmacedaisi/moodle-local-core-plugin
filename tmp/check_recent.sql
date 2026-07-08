-- Recent activity for items 2047 and 2048
SELECT '==LATEST HISTORY ACTIVITY PER ITEM ==' as '';
SELECT itemid, action, source, COUNT(*) AS n, MIN(from_unixtime(timemodified)) AS first, MAX(from_unixtime(timemodified)) AS last FROM isi_grade_grades_history WHERE itemid IN (2047, 2048) GROUP BY itemid, action, source ORDER BY itemid, last DESC;
SELECT '==ANY ANOMALOUS OPERATION IN LAST 30 DAYS==' as '';
SELECT action, source, itemid, userid, finalgrade, rawgrade, from_unixtime(timemodified) AS dt FROM isi_grade_grades_history WHERE itemid IN (2047, 2048) AND timemodified > UNIX_TIMESTAMP(NOW() - INTERVAL 30 DAY) ORDER BY timemodified;
SELECT '==GMK_COURSE_PROGRE FOR THESE STUDENTS - DETAIL==' as '';
SELECT cp.userid, u.firstname, u.lastname, cp.progress, cp.grade, cp.status, from_unixtime(cp.timemodified) AS dt FROM isi_gmk_course_progre cp JOIN isi_user u ON u.id = cp.userid WHERE cp.courseid = 74 AND cp.userid IN (SELECT userid FROM isi_groups_members WHERE groupid = 660) ORDER BY cp.progress DESC, cp.userid;