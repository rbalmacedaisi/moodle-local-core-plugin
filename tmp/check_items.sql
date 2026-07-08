SELECT gi.id, gi.itemname, gi.itemtype, gi.itemmodule, gi.iteminstance, gi.categoryid AS current_cat, 961 AS target_cat,
    (SELECT fullname FROM isi_grade_categories WHERE id = gi.categoryid) AS current_cat_name,
    (SELECT fullname FROM isi_grade_categories WHERE id = 961) AS target_cat_name
FROM isi_grade_items gi WHERE gi.id IN (1947, 2927);
