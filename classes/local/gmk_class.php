<?php

namespace local_grupomakro_core\local;

defined('MOODLE_INTERNAL') || die();

define('PLUGIN_NAME','local_grupomakro_core');

require_once($CFG->dirroot.'/grade/lib.php');

class gmk_class {
    
    public static function get_class_type_values(){
        return [
          ['value'=>0, 'label'=>get_string('classType_0',PLUGIN_NAME)],
          ['value'=>1, 'label'=>get_string('classType_1',PLUGIN_NAME)],
          ['value'=>2, 'label'=>get_string('classType_2',PLUGIN_NAME)],
        ];
    }
    // public const CLASS_TYPES_VALUES = 
    
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