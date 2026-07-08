-- Look at all UPDATE/DELETE events for these items in history
SELECT '==GRADE_GRADES_HISTORY ACTIONS FOR ITEM 2047 (Vuelo Digital)==' as '';
SELECT action, COUNT(*) AS n, MIN(from_unixtime(timemodified)) AS first, MAX(from_unixtime(timemodified)) AS last FROM isi_grade_grades_history WHERE itemid = 2047 GROUP BY action;
SELECT '==DELETE EVENTS ITEM 2047==';
SELECT action, oldid, source, itemid, userid, finalgrade, rawgrade, from_unixtime(timemodified) AS dt FROM isi_grade_grades_history WHERE itemid = 2047 AND action = 3 ORDER BY timemodified LIMIT 30;
SELECT '==UPDATE EVENTS ITEM 2047==';
SELECT action, oldid, source, itemid, userid, finalgrade, rawgrade, from_unixtime(timemodified) AS dt FROM isi_grade_grades_history WHERE itemid = 2047 AND action = 2 ORDER BY timemodified LIMIT 30;
SELECT '==DELETE EVENTS ITEM 2048 (Quiz)==';
SELECT action, oldid, source, itemid, userid, finalgrade, rawgrade, from_unixtime(timemodified) AS dt FROM isi_grade_grades_history WHERE itemid = 2048 AND action = 3 ORDER BY timemodified LIMIT 30;
SELECT '==UPDATE EVENTS ITEM 2048 (Quiz)==';
SELECT action, oldid, source, itemid, userid, finalgrade, rawgrade, from_unixtime(timemodified) AS dt FROM isi_grade_grades_history WHERE itemid = 2048 AND action = 2 ORDER BY timemodified LIMIT 30;