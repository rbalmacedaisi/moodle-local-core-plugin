-- Look for files relating to the assign 183 submissions (submission id -> files via files where component='assignsubmission_file')
SELECT f.id, f.contenthash, f.filename, f.filesize, f.userid, f.contextid, f.itemid, f.filearea, f.timecreated, f.timemodified FROM isi_files f WHERE f.component = 'assignsubmission_file' AND f.itemid IN (SELECT asub.id FROM isi_assign_submission asub WHERE asub.assignment = 183) ORDER BY f.id;
SELECT '---SUMMARY---' as '';
SELECT COUNT(*) AS file_count, SUM(filesize) AS total_size FROM isi_files f WHERE f.component = 'assignsubmission_file' AND f.itemid IN (SELECT asub.id FROM isi_assign_submission asub WHERE asub.assignment = 183);
SELECT '---FILES EXISTS ON DISK?---' as '';
SELECT f.id, f.contenthash, f.filename, f.filesize, f.timecreated FROM isi_files f WHERE f.component = 'assignsubmission_file' AND f.itemid IN (SELECT asub.id FROM isi_assign_submission asub WHERE asub.assignment = 183) LIMIT 5;
