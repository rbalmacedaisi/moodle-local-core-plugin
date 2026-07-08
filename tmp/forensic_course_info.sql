-- Get course 86 details and activities
SELECT id, shortname, fullname, category, format FROM isi_course WHERE id = 86;
SELECT '---SECTIONS---';
SELECT id, course, section, name, summary, sequence FROM isi_course_sections WHERE course = 86 ORDER BY section;
