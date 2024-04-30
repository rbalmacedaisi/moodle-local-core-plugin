<?php

require_once(__DIR__ . '/../../../config.php');
// require_once($CFG->dirroot.'/vendor/autoload.php');
// require_once($CFG->dirroot.'/user/externallib.php');
// require_once($CFG->dirroot.'/user/lib.php');
// require_once($CFG->dirroot.'/course/externallib.php');
// require_once($CFG->dirroot.'/local/sc_learningplans/external/learning/save_learning_plan.php');
// require_once($CFG->dirroot.'/local/sc_learningplans/external/course/save_learning_course.php');
// require_once($CFG->dirroot.'/local/sc_learningplans/external/course/add_course_relations.php');
// require_once($CFG->dirroot.'/local/sc_learningplans/external/user/add_learning_user.php');
require_once($CFG->dirroot . '/grade/querylib.php');
require_once($CFG->dirroot . '/grade/lib.php');
// require_once($CFG->dirroot . '/grade/classes/external/create_gradecategories.php');

// use PhpOffice\PhpSpreadsheet\IOFactory;
// use PhpOffice\PhpSpreadsheet\Shared\Date;

// global $DB;

try{
    // $gradeTree = new grade_tree(92);
    // $categoryData= [
    //     'fullname'=>'Testing notas 2 class grade category',
    //     'options'=>[
    //         'aggregation'=>10,
    //         'aggregateonlygraded'=>false,
    //         'itemname'=>'Total testing notas 2 class',
    //         'grademax'=>100,
    //         'grademin'=>0,
    //         'gradepass'=>0,
    //         ]
    //     ];
    // $categories = core_grades\external\create_gradecategories::execute(92,[$categoryData]);
    // print_object($categories);
    // $gtree = new grade_tree(92, false, false);
    // print_object($gtree);
    // $gradeCategory = $gtree->locate_element('cg143')['object'];
    // print_object($gradeCategory->delete());
    die;
    // $gradeCategory = $gtree->locate_element('cg140')['object'];
    // $gradeItem=  $gtree->locate_element('ig594')['object'];
    // $gradeMoved= $gradeItem->set_parent($gradeCategory->id);
    // print_object(grade_get_course_grades(92,3)); 
    // print_object(grade_get_course_grade(3,92)); 
    
    // $cinfo = new completion_info(get_course(92));
    // print_object($cinfo->is_course_complete(3));
    // print_object(core_completion\progress::get_course_progress_percentage(get_course(92),3));
    
    // print_object($gradeTree->get_items());
}catch(Exception $e){
    print_object($e);
    die;
}



