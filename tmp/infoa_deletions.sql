SELECT '==DELETED COURSE_MODULES EN CURSO 74 (logs)==' as '';
SELECT id, eventname, action, userid AS actor, contextinstanceid AS cm_id, from_unixtime(timecreated) dt, other FROM isi_logstore_standard_log WHERE courseid = 74 AND eventname LIKE '%course_module_deleted%' ORDER BY timecreated;
SELECT '==TOTAL CM DELETED EVENTS PARA CURSO 74==' as '';
SELECT COUNT(*) AS deleted_events_count FROM isi_logstore_standard_log WHERE courseid = 74 AND eventname LIKE '%course_module_deleted%';
SELECT '==5. RECYCLEBIN AUTO-EMPTY EVENTS (curso_bin_item_deleted)==' as '';
SELECT COUNT(*) AS bin_emptied_events FROM isi_logstore_standard_log WHERE courseid = 74 AND eventname = '\\tool_recyclebin\\event\\course_bin_item_deleted';
SELECT '==6. BIN DELETED EVENTS RANGE==' as '';
SELECT id, from_unixtime(timecreated) dt, other FROM isi_logstore_standard_log WHERE courseid = 74 AND eventname = '\\tool_recyclebin\\event\\course_bin_item_deleted' ORDER BY timecreated LIMIT 5;
