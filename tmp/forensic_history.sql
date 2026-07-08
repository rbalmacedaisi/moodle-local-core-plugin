-- Look for grade history tables 
SHOW TABLES LIKE 'isi_grade%';
SELECT '---GRADE_HISTORY 1947---' as '';
SELECT * FROM isi_grade_grades_history WHERE itemid = 1947 ORDER BY timemodified DESC LIMIT 50;
SELECT '---OTHER_GRADES---' as '';
SHOW TABLES LIKE 'isi_assign%';
SELECT '---ASSIGN_GRADES---' as '';
DESCRIBE isi_assign_grades;
SELECT '---ASSIGNSUBMISSION_FILES---' as '';
SHOW TABLES LIKE 'isi_files%';
