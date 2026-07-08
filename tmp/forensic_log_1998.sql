-- relateduserid is the field
SELECT id, eventname, action, userid AS grader, relateduserid AS target, timecreated, from_unixtime(timecreated) AS dt FROM isi_logstore_standard_log WHERE component = 'mod_assign' AND relateduserid = 1998 ORDER BY timecreated DESC LIMIT 50;
SELECT '---ANY GRADE EVENT FOR STUDENT 1998---' as '';
SELECT id, eventname, component, action, userid AS grader, relateduserid, timecreated, from_unixtime(timecreated) AS dt FROM isi_logstore_standard_log WHERE eventname LIKE '%grade%' AND relateduserid = 1998 ORDER BY timecreated DESC LIMIT 30;
SELECT '---ALL ACTIVITY 1998 IN COURSE 86---' as '';
SELECT id, eventname, action, timecreated, from_unixtime(timecreated) AS dt, other FROM isi_logstore_standard_log WHERE courseid = 86 AND relateduserid = 1998 ORDER BY timecreated DESC LIMIT 50;
