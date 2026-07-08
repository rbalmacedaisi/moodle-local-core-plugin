SELECT * FROM isi_course_modules WHERE id = 6666\G
SELECT '---SECTION 1091---' as '';
SELECT * FROM isi_course_sections WHERE id = 1091\G
SELECT '---GROUPS---' as '';
SELECT id, name, courseid, description FROM isi_groups WHERE id = 704\G
SELECT '---SHOW TABLES LIKE gmk---' as '';
SHOW TABLES LIKE 'isi_gmk%';
