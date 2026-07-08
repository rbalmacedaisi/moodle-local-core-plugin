SELECT '==ACTIVIDAD LOG PARA USER 2735 EN CM 9554==' as '';
SELECT id, eventname, action, userid, relateduserid, from_unixtime(timecreated) AS dt, other FROM isi_logstore_standard_log WHERE contextinstanceid = 9554 AND (userid = 2735 OR relateduserid = 2735) ORDER BY timecreated;
SELECT '==FILE/ONLINETEXT SUBMISSION EVENTS==';
SELECT id, eventname, userid, relateduserid, from_unixtime(timecreated) dt, other FROM isi_logstore_standard_log WHERE component IN ('assignsubmission_file','assignsubmission_onlinetext') AND relateduserid = 2735 AND contextinstanceid IN (9439, 9554, 10083, 10250) ORDER BY timecreated;
SELECT '==DESCRIBE ASSIGN SUBMISSION STATUS==' as '';
SELECT submission_status, COUNT(*) FROM (SELECT CASE WHEN status = 'submitted' THEN 'submitted' WHEN status = 'draft' THEN 'draft' WHEN status = 'new' THEN 'new' WHEN status = 'reopened' THEN 'reopened' ELSE 'other:' || status END AS submission_status FROM isi_assign_submission WHERE assignment = 519) AS s GROUP BY submission_status;
