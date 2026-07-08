-- Look for course module deletions in course 86 (MATEMATICA II)
SELECT id, eventname, action, userid, courseid, contextinstanceid, from_unixtime(timecreated) as dt FROM isi_logstore_standard_log WHERE courseid = 86 AND eventname LIKE '%delete%' ORDER BY timecreated;
SELECT '---COURSE_MODULE CREATED IN COURSE 86 AROUND 14:12-14:20---' as '';
SELECT id, eventname, action, userid, courseid, contextinstanceid, from_unixtime(timecreated) as dt, other FROM isi_logstore_standard_log WHERE courseid = 86 AND eventname LIKE '%course_module_created%' AND timecreated BETWEEN 1781202000 AND 1781204000;
