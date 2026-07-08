SELECT '==1. ACTIVITY LOG: submission_graded EVENTS FOR THESE DELETED ASSIGNS==' as '';
SELECT id, eventname, userid AS grader, relateduserid AS student, contextinstanceid AS cm_id, from_unixtime(timecreated) dt, other FROM isi_logstore_standard_log WHERE component = 'mod_assign' AND contextinstanceid IN (6202, 6203, 6204, 6205, 6638, 6830, 7542, 5843, 9916, 10451, 7506, 10249) AND eventname LIKE '%grade%' ORDER BY timecreated;
SELECT '==2. ASSIGN_GRADES_HISTORY (si existe) ==' as '';
SHOW TABLES LIKE 'isi_assign_grades_h%';
SELECT '==3. GRADE_HISTORY FOR ITEMS WITH INSTANCE = DELETED ASSIGNS (puede ser que grade_items fue recreado) ==';
SELECT id, itemname, itemmodule, iteminstance, courseid, categoryid, timecreated, timemodified FROM isi_grade_items WHERE courseid = 74 AND iteminstance IN (96, 97, 98, 99, 165, 257, 500, 65, 544, 735);
SELECT '==4. ACTIVITY LOG: user_graded core events for these deleted assigns (cm_id) ==';
SELECT id, eventname, userid AS actor, userid AS user_id, relateduserid, contextinstanceid, from_unixtime(timecreated) dt, other FROM isi_logstore_standard_log WHERE contextinstanceid IN (6202, 6203, 6204, 6205, 6638, 6830, 7542, 5843, 9916, 10451) AND component = 'core' AND eventname = '\\core\\event\\user_graded' ORDER BY timecreated LIMIT 50;
