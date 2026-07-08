-- Move grade items to their proper categories for the modules
SELECT '==BEFORE STATE==';
SELECT id, itemname, categoryid FROM isi_grade_items WHERE id IN (1947, 2927);

START TRANSACTION;

-- Item 1947 (assign 183) -> category 961 (MATEMÁTICA II MÓDULO 2026-II-9548)
UPDATE isi_grade_items SET categoryid = 961 WHERE id = 1947;

-- Item 2927 (assign 640) -> category 1080 (MATEMÁTICA II MÓDULO 2026-III-9654)
UPDATE isi_grade_items SET categoryid = 1080 WHERE id = 2927;

SELECT '==AFTER UPDATES==';
SELECT id, itemname, categoryid FROM isi_grade_items WHERE id IN (1947, 2927);

-- Insert history entries for both (action 2 = UPDATE)
INSERT INTO isi_grade_items_history (action, oldid, source, timemodified, loggeduser, courseid, categoryid, itemname, itemtype, itemmodule, iteminstance, itemnumber, iteminfo, idnumber, calculation, gradetype, grademax, grademin, scaleid, outcomeid, gradepass, multfactor, plusfactor, aggregationcoef, aggregationcoef2, sortorder, hidden, locked, locktime, needsupdate, display, decimals, weightoverride)
SELECT 2, gi.id, 'manual-fix', UNIX_TIMESTAMP(), 2732, gi.courseid, gi.categoryid, gi.itemname, gi.itemtype, gi.itemmodule, gi.iteminstance, gi.itemnumber, gi.iteminfo, gi.idnumber, gi.calculation, gi.gradetype, gi.grademax, gi.grademin, gi.scaleid, gi.outcomeid, gi.gradepass, gi.multfactor, gi.plusfactor, gi.aggregationcoef, gi.aggregationcoef2, gi.sortorder, gi.hidden, gi.locked, gi.locktime, gi.needsupdate, gi.display, gi.decimals, gi.weightoverride
FROM isi_grade_items gi WHERE gi.id IN (1947, 2927);

SELECT '==HISTORY ROWS INSERTED==';
SELECT id, action, oldid, source, categoryid, itemname, from_unixtime(timemodified) AS dt FROM isi_grade_items_history WHERE source = 'manual-fix' AND oldid IN (1947, 2927);

SELECT '==COMMIT==';
COMMIT;

SELECT '==POST-COMMIT VERIFY (activity now discoverable)==';
SELECT 'activities in module 9548 (gradecategoryid=961):' AS check_, COUNT(*) AS activities_in_category
FROM isi_grade_items WHERE courseid = 86 AND categoryid = 961 AND itemtype IN ('mod','manual');

SELECT gi.id, gi.itemname, gi.itemtype, gi.itemmodule, gi.iteminstance, gi.categoryid
FROM isi_grade_items gi WHERE gi.id IN (1947, 2927) ORDER BY gi.id;
