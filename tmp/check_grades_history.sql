-- Check history for these items to see if grades were ever deleted/changed
SELECT '==GRADE_GRADES_HISTORY FOR ITEM 2047 (Vuelo Digital)==' as '';
SELECT action, COUNT(*) FROM isi_grade_grades_history WHERE itemid = 2047 GROUP BY action;
SELECT '==SAMPLE==';
SELECT action, oldid, source, itemid, userid, finalgrade, rawgrade, from_unixtime(timemodified) AS dt FROM isi_grade_grades_history WHERE itemid = 2047 ORDER BY timemodified LIMIT 10;
SELECT '==GRADE_GRADES_HISTORY FOR ITEM 2048 (Quiz)==' as '';
SELECT action, COUNT(*) FROM isi_grade_grades_history WHERE itemid = 2048 GROUP BY action;
SELECT '==SAMPLE==';
SELECT action, oldid, source, itemid, userid, finalgrade, rawgrade, from_unixtime(timemodified) AS dt FROM isi_grade_grades_history WHERE itemid = 2048 ORDER BY timemodified LIMIT 10;
SELECT '==ANY USER_graded EVENT FOR ASSIGN 255 IN LOGS==' as '';
SELECT id, userid, relateduserid, from_unixtime(timecreated) dt FROM isi_logstore_standard_log WHERE eventname LIKE '%submission_graded%' AND component='mod_assign' AND courseid = 74 AND contextinstanceid = 255 ORDER BY timecreated LIMIT 20;
SELECT '==ANY LOG FOR ASSIGN 255 TODAY (recent)==' as '';
SELECT id, eventname, action, userid, relateduserid, from_unixtime(timecreated) dt, other FROM isi_logstore_standard_log WHERE courseid = 74 AND contextinstanceid = 255 AND timecreated > UNIX_TIMESTAMP(NOW() - INTERVAL 30 DAY) ORDER BY timecreated DESC LIMIT 30;