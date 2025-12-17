<?php

namespace local_grupomakro_core\local;
use stdClass;

defined('MOODLE_INTERNAL') || die();

define('PLUGIN_NAME','local_grupomakro_core');

class gmk_teacher_skill {
    
    public static function add_course_teacher_skill($courseInfo){
        global $DB,$USER;
        
        $shortname = $courseInfo['shortname'];
        if($DB->record_exists('gmk_teacher_skill',['shortname'=>$shortname])){
            return true;
        }
        
        $teacherSkill = new stdClass();
        $teacherSkill->shortname = $shortname; 
        $teacherSkill->name = $courseInfo['fullname'];
        $teacherSkill->courseid = $courseInfo['courseid'];
        $teacherSkill->timecreated = time();
        $teacherSkill->timemodified = time();
        $teacherSkill->usermodified = $USER->id;

        return $DB->insert_record('gmk_teacher_skill',$teacherSkill,false);
    }
    
    public static function update_course_teacher_skill($courseInfo){
        global $DB;
        $teacherSkill = $DB->get_record('gmk_teacher_skill',['courseid'=>$courseInfo['courseid']]);
        $teacherSkill->name = $courseInfo['fullname'];
        $teacherSkill->shortname = $courseInfo['shortname'];
        return $DB->update_record('gmk_teacher_skill',$teacherSkill);
    }

    public static function delete_course_teacher_skill($courseId){
        global $DB;
        $skillId = $DB->get_field('gmk_teacher_skill','id',['courseid'=>$courseId]);
        $DB->delete_records('gmk_teacher_skill_relation',['skillid'=>$skillId]);
        return $DB->delete_records('gmk_teacher_skill',['id'=>$skillId]);
    }
} 