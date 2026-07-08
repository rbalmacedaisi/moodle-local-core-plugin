SELECT '==1. RECYCLEBIN DEL CURSO 74 ==' as '';
SELECT * FROM isi_tool_recyclebin_course WHERE courseid = 74 ORDER BY timecreated DESC;
SELECT '==2. BIN_CATEGORY TABLE==';
SELECT * FROM isi_tool_recyclebin_category WHERE fullname LIKE '%AUDITORIO%' OR fullname LIKE '%2026-II (D)%' OR shortname LIKE '%9503%' OR shortname LIKE '%9504%';
SELECT '==3. TOTAL RECYCLEBIN==';
SELECT COUNT(*) AS total_bin FROM isi_tool_recyclebin_course;
