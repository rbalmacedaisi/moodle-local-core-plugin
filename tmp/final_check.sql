-- Simulate exact query from local_grupomakro_get_module_activities for the module 9548
SELECT '==SIMULATING AJAX QUERY FOR CLASS 9548 (MATEMATICA II MODULO 2026-II) ==';
SELECT 'Item del módulo (gmk_class 9548):' AS info_, corecourseid, gradecategoryid FROM isi_gmk_class WHERE id = 9548;
SELECT 'Grade items que verá el AJAX (con filtro de categoría):' AS info_, gi.id, gi.itemname, gi.itemtype, gi.itemmodule, gi.iteminstance, gi.grademax, gi.grademin FROM isi_grade_items gi WHERE gi.courseid = 86 AND gi.itemtype IN ('mod', 'manual') AND gi.categoryid = 961 ORDER BY gi.sortorder, gi.id;
SELECT '==NOTA ACTUAL DE JARELIS (1998) EN ESE ÍTEM==';
SELECT gg.id, gg.userid, gg.finalgrade, gg.rawgrade FROM isi_grade_grades gg WHERE gg.itemid = 1947 AND gg.userid = 1998;
SELECT '==NOTA ACTUAL DE REYSHELL (2000) EN ESE ÍTEM==';
SELECT gg.id, gg.userid, gg.finalgrade, gg.rawgrade FROM isi_grade_grades gg WHERE gg.itemid = 1947 AND gg.userid = 2000;
SELECT '==8 ESTUDIANTES YA CALIFICADOS==';
SELECT gg.userid, u.firstname, u.lastname, gg.finalgrade FROM isi_grade_grades gg JOIN isi_user u ON u.id = gg.userid WHERE gg.itemid = 1947 AND gg.finalgrade IS NOT NULL ORDER BY gg.userid;
