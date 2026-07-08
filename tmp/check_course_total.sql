-- Look at the course total grade (itemtype=course) for these students
SELECT '==COURSE TOTAL GRADES FOR STUDENTS IN GROUP 660==' as '';
SELECT gi.id, gi.itemname, gi.itemtype, gi.categoryid, gg.userid, gg.finalgrade, gg.rawgrade FROM isi_grade_items gi LEFT JOIN isi_grade_grades gg ON gg.itemid = gi.id WHERE gi.courseid = 74 AND gi.itemtype = 'course' AND gg.userid IN (SELECT userid FROM isi_groups_members WHERE groupid = 660) ORDER BY gg.userid;
SELECT '==GRADE ITEMS IN CATEGORY 924 (for class 9503)==' as '';
SELECT gi.id, gi.itemname, gi.itemtype, gi.itemmodule, gi.iteminstance, gi.grademax FROM isi_grade_items WHERE gi.courseid = 74 AND gi.categoryid = 924 ORDER BY gi.sortorder, gi.id;
SELECT '==CATEGORY 924 CONFIG==' as '';
SELECT id, fullname, courseid, parent, aggregation, aggregateoutcomes, timecreated FROM isi_grade_categories WHERE id = 924;
SELECT '==SECTION 924 (Class 9503) SUMMARY==' as '';
SELECT s.id, s.section, s.name, s.sequence FROM isi_course_sections s WHERE s.id = 924;
SELECT '==PROGRESS_MANAGER TRIGGER? (look for events today)==' as '';
SELECT id, eventname, action, userid, relateduserid, from_unixtime(timecreated) dt, other FROM isi_logstore_standard_log WHERE courseid = 74 AND eventname LIKE '%user_graded%' AND timecreated > UNIX_TIMESTAMP(NOW() - INTERVAL 7 DAY) ORDER BY timecreated DESC LIMIT 30;