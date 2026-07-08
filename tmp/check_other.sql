SELECT '==GMK_CLASS 9503 vs 9504==' as '';
SELECT id, name, corecourseid, gradecategoryid, is_module FROM isi_gmk_class WHERE id IN (9503, 9504);
SELECT '==GRADE ITEMS IN CATEGORY 925 (for class 9504)==' as '';
SELECT id, itemname, itemtype, itemmodule, iteminstance, grademax FROM isi_grade_items WHERE courseid = 74 AND categoryid = 925 ORDER BY sortorder;
SELECT '==GRADE ITEMS WHERE ASSIGN 255 OR 256 ARE NOT IN 924 OR 925 (if any)==';
SELECT gi.id, gi.itemname, gi.itemtype, gi.itemmodule, gi.iteminstance, gi.categoryid FROM isi_grade_items gi WHERE gi.iteminstance IN (255, 256);
SELECT '==CHECK GRADE_GRADES WHERE ITEM=2047 OR 2048 - STUDENTS WITH NON-NULL BUT NOT IN GROUP 660==' as '';
SELECT gg.userid, u.firstname, u.lastname FROM isi_grade_grades gg JOIN isi_user u ON u.id = gg.userid WHERE gg.itemid IN (2047, 2048) AND gg.finalgrade IS NOT NULL AND gg.userid NOT IN (SELECT userid FROM isi_groups_members WHERE groupid = 660);