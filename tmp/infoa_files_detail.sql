SELECT '==1. CONTENTHASHES DE ARCHIVOS RECUPERABLES==' as '';
SELECT f.id, f.component, f.itemid AS deleted_assign_id, f.filename, f.contenthash, f.filesize, f.userid AS uploaded_by FROM isi_files f WHERE f.component = 'assignsubmission_file' AND f.itemid IN (96, 97, 98, 99, 165, 257, 500, 65, 544, 735) AND f.filename <> '.';
SELECT '==2. CHECK IF FILES EXIST ON DISK==' as '';
SELECT contenthash, (CASE WHEN (SELECT 1 FROM (SELECT 1) z LIMIT 0) THEN 1 END) AS dummy FROM isi_files WHERE component = 'assignsubmission_file' AND itemid IN (96, 97, 98, 99, 165, 257, 500, 65, 544, 735) AND filename <> '.';
