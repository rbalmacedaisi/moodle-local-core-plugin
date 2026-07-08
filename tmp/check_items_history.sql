SELECT '---SAMPLE HISTORY ROW FOR ITEM 1947---' as '';
SELECT * FROM isi_grade_items_history WHERE oldid = 1947 ORDER BY id DESC LIMIT 3;
SELECT '---CHECK IF THERE ARE ANY HISTORY ROWS RELATED---' as '';
SELECT * FROM isi_grade_items_history WHERE courseid = 86 AND categoryid IN (168, 961, 1080) ORDER BY id DESC LIMIT 5;
