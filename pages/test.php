<?php

require_once(__DIR__ . '/../../../config.php');

require_once($CFG->libdir.'/modinfolib.php');
require_once($CFG->dirroot.'/course/lib.php');
require_once($CFG->dirroot.'/mod/resource/lib.php');
require_once($CFG->dirroot.'/course/modlib.php');

$course = get_course(64);

$coursemod = get_fast_modinfo(64,116);
print_object($coursemod);

$module= $coursemod->get_cm(2460);
// print_object($module);
// print_object($course->get_cm(2362)->get_modinfo	());
// print_object($course->get_groups());

$completion = new completion_info($course);
// print_object($completion->get_activities());
print_object($completion->get_data($module,false,116,null));


// $completionInfo = $completion->get_completions(116,null);
// print_object($completionInfo);
print_object($completion->is_course_complete(116));


print_object($completion->get_progress_all('',array(),152));
print_object(core_completion\progress::get_course_progress_percentage($course,116));