-- Insert proper history rows for both grades
INSERT INTO isi_grade_grades_history (action, oldid, source, timemodified, loggeduser, itemid, userid, rawgrade, rawgrademax, rawgrademin, rawscaleid, usermodified, finalgrade, hidden, locked, locktime, exported, overridden, excluded, feedback, feedbackformat, information, informationformat)
SELECT 2, gg.id, 'mod/assign', UNIX_TIMESTAMP(), 2732, gg.itemid, gg.userid, gg.rawgrade, gg.rawgrademax, gg.rawgrademin, gg.rawscaleid, gg.usermodified, gg.finalgrade, gg.hidden, gg.locked, gg.locktime, gg.exported, gg.overridden, gg.excluded, gg.feedback, gg.feedbackformat, gg.information, gg.informationformat
FROM isi_grade_grades gg WHERE gg.itemid = 1947 AND gg.userid IN (1998, 2000);

SELECT '==INSERTED HISTORY==';
SELECT id, action, oldid AS gg_id, source, itemid, userid, rawgrade, finalgrade FROM isi_grade_grades_history WHERE loggeduser = 2732 AND itemid = 1947 AND timemodified >= UNIX_TIMESTAMP() - 30 ORDER BY id;

SELECT '==VERIFY FINAL==';
SELECT gg.id, gg.userid, gg.finalgrade, gg.rawgrade, gg.usermodified, gg.timemodified, ag.grade, ag.grader, ag.timemodified as ag_mod FROM isi_grade_grades gg LEFT JOIN isi_assign_grades ag ON ag.assignment = 183 AND ag.userid = gg.userid WHERE gg.itemid = 1947 AND gg.userid IN (1998, 2000);
