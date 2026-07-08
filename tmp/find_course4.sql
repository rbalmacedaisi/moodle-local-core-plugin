-- Check gmk_class for INFOA classes (course 74)
SELECT '---ALL GMK_CLASS FOR COURSE 74---' as '';
SELECT id, name, corecourseid, gradecategoryid, is_module, instructorid, periodid FROM isi_gmk_class WHERE corecourseid = 74 ORDER BY id;
SELECT '---ANY GROUP WITH 9503 (num) ID---' as '';
SELECT id, name, courseid FROM isi_groups WHERE name REGEXP '\\-?9503|9503$';
SELECT '---GMK_CLASS WITH 9503---' as '';
SELECT id, name, corecourseid, gradecategoryid FROM isi_gmk_class WHERE name LIKE '%9503%';
SELECT '---GMK_CLASS WITH AUDITORIO 2026-II D---' as '';
SELECT id, name, corecourseid, gradecategoryid FROM isi_gmk_class WHERE name LIKE '%AUDITORIO%' AND name LIKE '%2026-II%' AND name LIKE '%D%' LIMIT 10;
SELECT '---SEARCH GROUPS WITH 'AUDITORIO' AND 'INFO' AND '2026-II'---' as '';
SELECT g.id, g.name, g.courseid FROM isi_groups g WHERE g.name LIKE '%AUDITORIO%' AND g.name LIKE '%INFORM%' AND g.name LIKE '%2026-II%';