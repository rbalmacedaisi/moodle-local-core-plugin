-- Apply grades for Jarelis (1998=82) and Reyshell (2000=87) on assign 183 / itemid 1947
SELECT '==START==';
START TRANSACTION;

-- Update grade_grades (use literal VALUES to avoid order-of-assignment issues)
UPDATE isi_grade_grades SET finalgrade = 82.00000, rawgrade = 82.00000, usermodified = 2732, timemodified = UNIX_TIMESTAMP() WHERE itemid = 1947 AND userid = 1998;
UPDATE isi_grade_grades SET finalgrade = 87.00000, rawgrade = 87.00000, usermodified = 2732, timemodified = UNIX_TIMESTAMP() WHERE itemid = 1947 AND userid = 2000;

-- Update assign_grades for both
UPDATE isi_assign_grades SET grade = 82.00000, grader = 2732, timemodified = UNIX_TIMESTAMP() WHERE assignment = 183 AND userid = 1998;
INSERT INTO isi_assign_grades (assignment, userid, timecreated, timemodified, grader, grade, attemptnumber) VALUES (183, 2000, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 2732, 87.00000, 0) ON DUPLICATE KEY UPDATE grade = 87.00000, grader = 2732, timemodified = UNIX_TIMESTAMP();

SELECT '==AFTER UPDATES==';
SELECT gg.id, gg.userid, gg.finalgrade, gg.rawgrade, gg.usermodified, gg.timemodified, ag.grade, ag.grader FROM isi_grade_grades gg LEFT JOIN isi_assign_grades ag ON ag.assignment = 183 AND ag.userid = gg.userid WHERE gg.itemid = 1947 AND gg.userid IN (1998, 2000);

SELECT '==COMMIT==';
COMMIT;

SELECT '==POST-COMMIT VERIFY==';
SELECT gg.id, gg.userid, gg.finalgrade, gg.rawgrade, gg.usermodified, gg.timemodified, ag.id as ag_id, ag.grade, ag.grader, ag.timemodified as ag_mod FROM isi_grade_grades gg LEFT JOIN isi_assign_grades ag ON ag.assignment = 183 AND ag.userid = gg.userid WHERE gg.itemid = 1947 AND gg.userid IN (1998, 2000) ORDER BY gg.userid;
