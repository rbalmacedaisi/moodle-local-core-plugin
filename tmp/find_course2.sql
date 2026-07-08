SELECT '---CATEGORIES INFORMATICA---' as '';
SELECT id, name, parent FROM isi_course_categories WHERE name LIKE '%INFORMATICA%' LIMIT 10;
SELECT '---COURSE FULLNAME BY INFORM AND 2026---' as '';
SELECT id, shortname, fullname, category FROM isi_course WHERE fullname LIKE '%INFORMATICA%' LIMIT 30;
SELECT '---GROUPS INFORM AND AP---' as '';
SELECT id, name, courseid FROM isi_groups WHERE name LIKE '%INFORM%AP%' LIMIT 20;
SELECT '---GROUPS 9503---' as '';
SELECT id, name, courseid FROM isi_groups WHERE name LIKE '%9503%' LIMIT 20;