-- All submission_removed and course_module_deleted events around June 12-13
SELECT id, eventname, action, userid, courseid, relateduserid, from_unixtime(timecreated) as dt FROM isi_logstore_standard_log WHERE timecreated BETWEEN 1781260800 AND 1781520000 AND eventname LIKE '%remove%' ORDER BY timecreated LIMIT 50;
SELECT '---COURSE MODULE DELETED---' as '';
SELECT id, eventname, action, userid, courseid, relateduserid, contextinstanceid, from_unixtime(timecreated) as dt FROM isi_logstore_standard_log WHERE timecreated BETWEEN 1781260800 AND 1781520000 AND eventname LIKE '%course_module%' ORDER BY timecreated LIMIT 50;
SELECT '---COURSE_DELETED---' as '';
SELECT id, eventname, action, userid, courseid, relateduserid, contextinstanceid, from_unixtime(timecreated) as dt FROM isi_logstore_standard_log WHERE timecreated BETWEEN 1781260800 AND 1781520000 AND (eventname LIKE '%delete%' OR eventname LIKE '%trash%') ORDER BY timecreated LIMIT 50;
