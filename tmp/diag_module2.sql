-- Look for module classes in gmk_class (is_module = 1)
SELECT '---MODULES IN GMK_CLASS---' as '';
SELECT id, name, corecourseid, gradecategoryid, is_module, instructorid, periodid FROM isi_gmk_class WHERE id IN (704) OR is_module = 1 LIMIT 10;
SELECT '---LOOKING FOR MODULE WITH GRADECATEGORYID 961---' as '';
SELECT id, name, corecourseid, gradecategoryid, is_module FROM isi_gmk_class WHERE gradecategoryid = 961;
SELECT '---ANY GMK_CLASS WITH NAME LIKE THIS MODULE---' as '';
SELECT id, name, corecourseid, gradecategoryid, is_module FROM isi_gmk_class WHERE name LIKE '%9548%' OR name LIKE '%MATEMATICA II%M%';
SELECT '---LOOKING IN GMK_CLASS BY COURSE 86 MODULE FLAG---' as '';
SELECT id, name, corecourseid, gradecategoryid, is_module, description FROM isi_gmk_class WHERE corecourseid = 86 ORDER BY id;
SELECT '---GMK_MODULE_ENROLLMENT SCHEMA---' as '';
DESCRIBE isi_gmk_module_enrollment;
