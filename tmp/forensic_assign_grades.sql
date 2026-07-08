-- All grades in assign_grades for assign 183
SELECT ag.id, ag.userid, u.firstname, u.lastname, ag.grade, ag.timemodified, ag.grader FROM isi_assign_grades ag LEFT JOIN isi_user u ON u.id = ag.userid WHERE ag.assignment = 183 ORDER BY ag.userid;
SELECT '---FILE SUBMISSIONS COUNT---' as '';
SELECT COUNT(*) AS file_subs FROM isi_assignsubmission_file WHERE assignment = 183;
SELECT '---DESCRIBE FILE SUB TABLE---' as '';
DESCRIBE isi_assignsubmission_file;
SELECT '---FILE SUBMISSIONS---' as '';
SELECT * FROM isi_assignsubmission_file WHERE assignment = 183 LIMIT 20;
SELECT '---FILES LINKED---' as '';
SHOW TABLES LIKE 'isi_files%';
DESCRIBE isi_files;
