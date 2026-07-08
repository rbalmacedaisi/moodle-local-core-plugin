-- Detailed look at groups for INFOA course
SELECT '---ALL GROUPS COURSE 74 INFOA---' as '';
SELECT id, name, courseid FROM isi_groups WHERE courseid = 74 ORDER BY id;
SELECT '---GROUP 711 (MÓDULO 2026-II)---' as '';
SELECT id, name, courseid FROM isi_groups WHERE id = 711;
SELECT '---ALL COURSES WITH AUDITORIO---' as '';
SELECT id, shortname, fullname, category FROM isi_course WHERE fullname LIKE '%AUDITORIO%' LIMIT 10;
SELECT '---GROUPS WITH AUDITORIO---' as '';
SELECT id, name, courseid FROM isi_groups WHERE name LIKE '%AUDITORIO%' LIMIT 30;