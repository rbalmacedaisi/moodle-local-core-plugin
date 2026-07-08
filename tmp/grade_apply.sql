-- Pre-state capture
SELECT '==BEFORE==';
SELECT gg.id, gg.userid, gg.finalgrade, gg.timemodified, ag.id as ag_id, ag.grade FROM isi_grade_grades gg LEFT JOIN isi_assign_grades ag ON ag.assignment = 183 AND ag.userid = gg.userid WHERE gg.itemid = 1947 AND gg.userid IN (1998, 2000);

SELECT '==BEGIN TRANSACTION==';
START TRANSACTION;

-- Update grade_grades for both students (set finalgrade + rawgrade + timemodified + usermodified)
UPDATE isi_grade_grades
SET finalgrade = CASE userid WHEN 1998 THEN 82.00000 WHEN 2000 THEN 87.00000 END,
    rawgrade = finalgrade,
    timemodified = UNIX_TIMESTAMP(),
    usermodified = 2732
WHERE itemid = 1947 AND userid IN (1998, 2000);

SELECT '==AFTER grade_grades==';
SELECT id, userid, finalgrade, rawgrade, timemodified FROM isi_grade_grades WHERE itemid = 1947 AND userid IN (1998, 2000);

-- Update assign_grades for both (grade column + timemodified + grader)
-- Use ON DUPLICATE so we create new rows if missing
INSERT INTO isi_assign_grades (assignment, userid, timecreated, timemodified, grader, grade, attemptnumber)
VALUES (183, 1998, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 2732, 82.00000, 0)
ON DUPLICATE KEY UPDATE grade = 82.00000, timemodified = UNIX_TIMESTAMP(), grader = 2732;

INSERT INTO isi_assign_grades (assignment, userid, timecreated, timemodified, grader, grade, attemptnumber)
VALUES (183, 2000, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 2732, 87.00000, 0)
ON DUPLICATE KEY UPDATE grade = 87.00000, timemodified = UNIX_TIMESTAMP(), grader = 2732;

SELECT '==AFTER assign_grades==';
SELECT id, assignment, userid, grade, grader, timemodified FROM isi_assign_grades WHERE assignment = 183 AND userid IN (1998, 2000);

-- Insert history rows for both
INSERT INTO isi_grade_grades_history (action, userid, origsystem, timemodified, loggeduser, itemid, rawgrade, rawgrademax, rawgrademin, finalgrade, usermodified)
SELECT 2, gg.id, 'mod/assign', UNIX_TIMESTAMP(), 2732, gg.itemid, gg.rawgrade, gg.rawgrademax, gg.rawgrademin, gg.finalgrade, gg.usermodified
FROM isi_grade_grades gg WHERE gg.itemid = 1947 AND gg.userid IN (1998, 2000);

SELECT '==INSERTED HISTORY==';
SELECT COUNT(*) AS new_history_rows FROM isi_grade_grades_history WHERE loggeduser = 2732 AND itemid = 1947 AND timemodified >= UNIX_TIMESTAMP() - 30;

-- Verify final state
SELECT '==VERIFY FINAL==';
SELECT gg.id, gg.userid, gg.finalgrade, gg.rawgrade, gg.usermodified, gg.timemodified, ag.grade, ag.grader, ag.timemodified as ag_mod FROM isi_grade_grades gg LEFT JOIN isi_assign_grades ag ON ag.assignment = 183 AND ag.userid = gg.userid WHERE gg.itemid = 1947 AND gg.userid IN (1998, 2000);

SELECT '==COMMIT==';
COMMIT;
