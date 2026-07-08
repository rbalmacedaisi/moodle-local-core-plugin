-- Look for any events related to these assignments - last 30 days
SELECT '==ANY GRADE-RELATED EVENTS FOR THESE ASSIGNS LAST 60 DAYS ==' as '';
SELECT id, eventname, action, userid, relateduserid, from_unixtime(timecreated) AS dt, contextinstanceid, other FROM isi_logstore_standard_log WHERE courseid = 74 AND component = 'mod_assign' AND contextinstanceid IN (255, 256) AND eventname LIKE '%grade%' ORDER BY timecreated DESC LIMIT 50;
SELECT '==ANY course_module_completion FOR COURSE 74 IN SECTION 924==' as '';
SELECT id, coursemoduleid, userid, completionstate, from_unixtime(timemodified) AS dt FROM isi_course_modules_completion WHERE coursemoduleid IN (6828, 6829) ORDER BY timemodified DESC LIMIT 30;
SELECT '==OVERRIDE STATUS OF GRADES==' as '';
SELECT id, itemid, userid, finalgrade, overridden, excluded, hidden FROM isi_grade_grades WHERE itemid IN (2047, 2048) AND finalgrade IS NOT NULL ORDER BY overridden DESC, excluded DESC LIMIT 30;