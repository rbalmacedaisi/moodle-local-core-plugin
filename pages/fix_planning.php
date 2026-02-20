<?php
$file = 'academic_planning.php';
$content = file_get_contents($file);
$content = str_replace('selections: JSON.stringify(items)', "selections: JSON.stringify(items),\n                      deferredGroups: JSON.stringify(deferredGroups)", $content);
$content = str_replace('res.subjects ? res.subjects.find', 'res.all_subjects ? res.all_subjects.find', $content);
$content = str_replace('pp.projected_students', 'pp.count', $content);
file_put_contents($file, $content);
