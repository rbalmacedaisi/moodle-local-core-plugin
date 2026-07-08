SELECT '==ESTADO FINAL POST-RESTAURACION==' as '';
SELECT 'A. Grade grades item 2048 (Asignacion 2: Quiz) por userid con finalgrade=100:' AS info_;
SELECT userid, u.firstname, u.lastname, gg.finalgrade, gg.rawgrade, gg.timemodified FROM isi_grade_grades gg JOIN isi_user u ON u.id = gg.userid WHERE gg.itemid = 2048 AND gg.finalgrade = 100.00000 ORDER BY gg.userid;
SELECT 'B. Asign grades para assign 256 con grade >= 0:' AS info_;
SELECT ag.userid, u.firstname, u.lastname, ag.grade, ag.grader, ag.timemodified FROM isi_assign_grades ag JOIN isi_user u ON u.id = ag.userid WHERE ag.assignment = 256 AND ag.grade >= 0 ORDER BY ag.userid;
SELECT 'C. Archivos en submission 2754 (user 2317, assign 256):' AS info_;
SELECT id, component, filearea, filename, filesize FROM isi_files WHERE itemid = 2754 AND filename <> '.' ORDER BY id;
SELECT 'D. Grade history recien insertado:' AS info_;
SELECT id, action, source, itemid, userid, finalgrade, from_unixtime(timemodified) dt FROM isi_grade_grades_history WHERE source = 'manual-restore' AND itemid = 2048 ORDER BY userid;
SELECT 'E. Resumen consolidado:' AS info_;
SELECT 'Grados asign 256:' AS label, COUNT(*) AS val FROM isi_grade_grades WHERE itemid = 2048 AND finalgrade >= 0
UNION ALL
SELECT 'Grados asign 256 (assign_grades):', COUNT(*) FROM isi_assign_grades WHERE assignment = 256 AND grade >= 0
UNION ALL
SELECT 'Estudiantes en grupo 660:', COUNT(*) FROM isi_groups_members WHERE groupid = 660
UNION ALL
SELECT 'Estudiantes grupo 660 con grado en item 2048:', COUNT(DISTINCT gg.userid) FROM isi_grade_grades gg JOIN isi_groups_members gm ON gm.userid = gg.userid WHERE gm.groupid = 660 AND gg.itemid = 2048 AND gg.finalgrade >= 0;
