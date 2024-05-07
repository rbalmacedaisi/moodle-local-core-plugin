<?php

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/grade/lib.php');

class local_grupomakro_class {
    
    public static function add_module_to_class_grade_category( $moduleInfo, $classGradeCategoryId){
        global $DB;
        
        $courseModuleRecord = $moduleInfo->get_course_module_record();
        
        $classCourseGradeTree= new grade_tree($courseModuleRecord->course, false, false);
        $classGradeCategory = $classCourseGradeTree->locate_element('cg'.$classGradeCategoryId)['object'];
        
        $gradeItemId = $DB->get_field('grade_items','id',['itemmodule'=>$moduleInfo->__get('modname'),'iteminstance'=>$moduleInfo->__get('instance')]);
        $gradeItem= $classCourseGradeTree->locate_element('ig'.$gradeItemId);
        // print_object(gradeItem);
        return $gradeItem? $gradeItem['object']->set_parent($classGradeCategoryId) : false;
    }
} 