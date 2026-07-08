-- Find course_modules for assign 255 (Actividad Grupal No. 4) and assign 256 (Asignación 2: Quiz)
SELECT '---CM FOR ASSIGN 255 (Vuelo Digital)---' as '';
SELECT cm.id, cm.course, cm.section, cm.instance, cm.module, cm.deletioninprogress, s.name as section_name, s.availability FROM isi_course_modules cm LEFT JOIN isi_course_sections s ON s.id = cm.section WHERE cm.instance = 255 AND cm.course = 74;
SELECT '---CM FOR ASSIGN 256 (Asignación 2)---' as '';
SELECT cm.id, cm.course, cm.section, cm.instance, cm.module, cm.deletioninprogress, s.name as section_name, s.availability FROM isi_course_modules cm LEFT JOIN isi_course_sections s ON s.id = cm.section WHERE cm.instance = 256 AND cm.course = 74;
SELECT '---ALL CMs IN SECTION 924 (9503)---' as '';
SELECT cm.id, cm.course, cm.section, cm.instance, cm.module, cm.deletioninprogress FROM isi_course_modules WHERE cm.course = 74 AND cm.section = 924;
SELECT '---ALL CMs IN SECTION 925 (9504)---' as '';
SELECT cm.id, cm.course, cm.section, cm.instance, cm.module, cm.deletioninprogress FROM isi_course_modules WHERE cm.course = 74 AND cm.section = 925;
SELECT '---MODULE NAMES---' as '';
SELECT id, name FROM isi_modules WHERE id IN (1, 18, 20);