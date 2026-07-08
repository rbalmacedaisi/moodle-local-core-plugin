-- Find the course "2026-II (D) INFORMÁTICA APLICADA (PRESENCIAL) AUDITORIO-9503"
SELECT '---CANDIDATE COURSES---' as '';
SELECT id, shortname, fullname, category, format FROM isi_course WHERE shortname LIKE '%INFORMATICA%' AND fullname LIKE '%2026-II%' LIMIT 10;
SELECT '---BY FULLNAME LIKE---' as '';
SELECT id, shortname, fullname, category, format FROM isi_course WHERE fullname LIKE '%INFORM%APLICADA%9503%' OR fullname LIKE '%2026-II%(D)%INFORM%' LIMIT 10;
SELECT '---GROUPS WITH THIS NAME---' as '';
SELECT id, name, courseid FROM isi_groups WHERE name LIKE '%INFORMATICA%' AND name LIKE '%9503%' LIMIT 10;