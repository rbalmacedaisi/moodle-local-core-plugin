-- Insert proper history entries (separate transaction)
INSERT INTO isi_grade_grades_history (action, oldid, source, timemodified, loggeduser, itemid, userid, rawgrade, rawgrademax, rawgrademin, rawscaleid, usermodified, finalgrade, hidden, locked, locktime, exported, overridden, excluded)
SELECT 2, gg.id, 'mod/assign', gg.timemodified, gg.usermodified, gg.itemid, gg.userid, gg.rawgrade, gg.rawgrademax, gg.rawgrademin, gg.rawscaleid, gg.usermodified, gg.finalgrade, gg.hidden, gg.locked, gg.locktime, gg.exported, gg.overridden, gg.excluded
FROM isi_grade_grades gg WHERE gg.itemid = 1947 AND gg.userid IN (1998, 2000);

SELECT '==VERIFY FINAL==';
SELECT id, action, oldid AS grade_id, source, itemid, userid, rawgrade, finalgrade, from_unixtime(timemodified) AS dt FROM isi_grade_grades_history WHERE itemid = 1947 AND userid IN (1998, 2000) ORDER BY id;
SELECT '==FINAL STATE OF GRADES==';
SELECT u.id, u.firstname, u.lastname, gg.finalgrade, gg.rawgrade, ag.grade FROM isi_grade_grades gg JOIN isi_user u ON u.id = gg.userid LEFT JOIN isi_assign_grades ag ON ag.assignment = 183 AND ag.userid = gg.userid WHERE gg.itemid = 1947 AND gg.userid IN (1998, 2000);
