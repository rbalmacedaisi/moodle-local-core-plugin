SHOW TABLES LIKE 'isi_tool_recycle%';
DESCRIBE isi_tool_recyclebin_course;
DESCRIBE isi_tool_recyclebin_category;
SELECT '---BIN COURSE 86 ALL---' as '';
SELECT * FROM isi_tool_recyclebin_course WHERE courseid = 86;
SELECT '---BIN CAT---' as '';
SELECT COUNT(*) FROM isi_tool_recyclebin_category;
