<?php

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot.'/vendor/autoload.php');
require_once($CFG->dirroot.'/user/externallib.php');
require_once($CFG->dirroot.'/user/lib.php');
require_once($CFG->dirroot.'/course/externallib.php');
require_once($CFG->dirroot.'/local/sc_learningplans/external/learning/save_learning_plan.php');
require_once($CFG->dirroot.'/local/sc_learningplans/external/course/save_learning_course.php');
require_once($CFG->dirroot.'/local/sc_learningplans/external/course/add_course_relations.php');
require_once($CFG->dirroot.'/local/sc_learningplans/external/user/add_learning_user.php');
require_once($CFG->dirroot . '/grade/querylib.php');
require_once($CFG->dirroot . '/grade/lib.php');

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

global $DB;

try{
    // $grades = grade_get_course_grades(54,129);
    $grades = grade_get_course_grades(54,129);
    print_object($grades);
    $grades = grade_get_course_grade(129,54);
    print_object($grades);
}catch(Exception $e){
    print_object($e);
}



