SELECT '==CURRENT GRADE ITEMS IN COURSE 74 ==' as '';
SELECT id, itemname, itemtype, itemmodule, iteminstance, categoryid FROM isi_grade_items WHERE courseid = 74 ORDER BY id;
SELECT '==HISTORIAL DE ITEMS 1765, 1840, 1841 ==' as '';
SELECT action, oldid, itemname, itemtype, itemmodule, iteminstance, from_unixtime(timemodified) dt FROM isi_grade_items_history WHERE oldid IN (1765, 1840, 1841) ORDER BY oldid, id DESC LIMIT 30;
