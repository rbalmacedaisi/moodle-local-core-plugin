SELECT '==TODOS LOS GRADE_GRADES_HISTORY PARA ITEM 1766 (assign 65) ==' as '';
SELECT action, oldid AS gg_id, itemid, userid, finalgrade, rawgrade, from_unixtime(timemodified) dt FROM isi_grade_grades_history WHERE itemid = 1766 ORDER BY userid, timemodified LIMIT 100;
SELECT '==GRADE_GRADES QUE AUN EXISTEN PARA EL ITEM 1766?==' as '';
SELECT COUNT(*) AS active_grade_grades FROM isi_grade_grades WHERE itemid = 1766;
SELECT '==HISTORIAL DE GRADES PARA ASSIGN 65 (usando la relacion con grades_grades_history) ==' as '';
SELECT COUNT(*) FROM isi_grade_grades_history WHERE oldid IN (SELECT id FROM (SELECT 1) z) AND itemid = 1766;
SELECT '==CANTIDAD DE EVENTS submission_graded por cm_id (assign 65 = 5843) ==' as '';
SELECT COUNT(*) AS n FROM isi_logstore_standard_log WHERE eventname = '\\mod_assign\\event\\submission_graded' AND contextinstanceid = 5843;
