SELECT '==GRADE ITEMS IN CATEGORY 924==' as '';
SELECT id, itemname, itemtype, itemmodule, iteminstance, grademax FROM isi_grade_items WHERE courseid = 74 AND categoryid = 924 ORDER BY sortorder, id;
SELECT '==CATEGORY 924 CONFIG==' as '';
SELECT id, fullname, courseid, parent, aggregation, aggregateoutcomes, timecreated FROM isi_grade_categories WHERE id = 924;