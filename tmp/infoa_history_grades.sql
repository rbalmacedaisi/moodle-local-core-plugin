SELECT '==1. GRADE_ITEMS_HISTORY FOR DELETED ASSIGNS==' as '';
SELECT id, action, oldid, source, courseid, categoryid, itemname, itemtype, itemmodule, iteminstance, from_unixtime(timemodified) dt FROM isi_grade_items_history WHERE courseid = 74 AND iteminstance IN (96, 97, 98, 99, 165, 257, 500, 65, 544, 735) ORDER BY iteminstance, id DESC;
SELECT '==2. ALL ACTIVITY LOG ENTRIES FOR THE 33 STUDENTS ON ASSIGN 65 CM 5843 ==' as '';
SELECT 'TAMA?O DE LOG: ' as label, COUNT(*) AS total_rows FROM isi_logstore_standard_log WHERE contextinstanceid = 5843;
SELECT '==3. ACTIVITY LOG: ALL EVENTS FOR CM 5843 (assign 65) ==';
SELECT id, eventname, action, userid AS actor, relateduserid AS student, from_unixtime(timecreated) dt, other FROM isi_logstore_standard_log WHERE contextinstanceid = 5843 ORDER BY timecreated LIMIT 50;
SELECT '==4. ALL SUBMISSION_graded BY USER 2613 BETWEEN MAY 14-19==' as '';
SELECT id, userid AS grader, relateduserid AS student, contextinstanceid AS cm_id, from_unixtime(timecreated) dt FROM isi_logstore_standard_log WHERE component = 'mod_assign' AND eventname = '\\mod_assign\\event\\submission_graded' AND userid = 2613 AND timecreated BETWEEN UNIX_TIMESTAMP('2026-05-14') AND UNIX_TIMESTAMP('2026-05-20 00:00:00') ORDER BY timecreated;
