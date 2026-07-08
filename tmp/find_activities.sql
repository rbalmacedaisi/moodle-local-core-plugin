-- Find specific activities in course 74
SELECT '---ALL ASSIGNS IN COURSE 74---' as '';
SELECT id, course, name, duedate, grade, timemodified FROM isi_assign WHERE course = 74 ORDER BY id;
SELECT '---ALL QUIZZES IN COURSE 74---' as '';
SELECT id, course, name, timemodified FROM isi_quiz WHERE course = 74 ORDER BY id;
SELECT '---ASSIGN LIKELY "VUELO DIGITAL"---' as '';
SELECT id, course, name, duedate, grade, timemodified FROM isi_assign WHERE course = 74 AND (name LIKE '%Vuelo%' OR name LIKE '%Digital%' OR name LIKE '%Actividad Grupal%' OR name LIKE '%Operaciones%');
SELECT '---QUIZ "Asignación 2"---' as '';
SELECT id, course, name, timemodified FROM isi_quiz WHERE course = 74 AND name LIKE '%Asignación%';
SELECT '---SECTIONS IN COURSE 74---' as '';
SELECT id, course, section, name, sequence FROM isi_course_sections WHERE course = 74 ORDER BY section;