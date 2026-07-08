-- Find the module class and its gmk_class record
SELECT '---GROUP 704 (MATEMATICA II MODULO 2026-II) ---' as '';
SELECT id, name, courseid, corecourseid, gradecategoryid FROM isi_gmk_class WHERE id = 704;
SELECT '---GMK_MODULE_ENROLLMENT FOR GROUP 704---' as '';
SELECT id, classid, userid, status FROM isi_gmk_module_enrollment WHERE classid = 704;
SELECT '---GRADE ITEMS FOR COURSE 86 (no category filter)---' as '';
SELECT COUNT(*) AS total_items FROM isi_grade_items WHERE courseid = 86 AND itemtype IN ('mod', 'manual');
SELECT '---DETAIL ITEMS FOR COURSE 86---' as '';
SELECT id, itemname, itemtype, itemmodule, iteminstance, categoryid, grademax FROM isi_grade_items WHERE courseid = 86 AND itemtype IN ('mod', 'manual') ORDER BY sortorder LIMIT 30;
SELECT '---CATEGORIES IN COURSE 86---' as '';
SELECT id, fullname, courseid, parent FROM isi_grade_categories WHERE courseid = 86;
SELECT '---ITEM WITH ITEMINSTANCE 183 (assign 183)---' as '';
SELECT * FROM isi_grade_items WHERE iteminstance = 183 AND courseid = 86;
