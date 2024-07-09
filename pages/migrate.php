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

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

global $DB;

$enableStudentsCreation = true;
$enableTeachersCreation = true;
$enableLPCreation = true;
$enableCourseCreation = true;
$enableCourseLPCreation= true;
$enableStudentLPCreation= true;
$enableTeacherLPCreation= true;

$createdStudents=0;
$createdTeachers=0;
$createdLPs=0;
$createdCourses=0;
$courseLPCreated = 0;
$studentLPCreated = 0;
$teacherLPCreated = 0;
$limit=2;

// Load the Excel file
$inputFileName = 'Formatos migracion ISI.xlsx';
$objPHPExcel = IOFactory::load($inputFileName);
// Get the number of sheets in the Excel file
$sheetCount = $objPHPExcel->getSheetCount();

// Create the arrays that'll hold the results.
$studentsCreationResults = ['success'=>[],'error'=>[]];
$teachersCreationResults = ['success'=>[],'error'=>[]];
$learningPlanCreationResults = ['success'=>[],'error'=>[]];
$courseCreationResults = ['success'=>[],'error'=>[]];
$courseLearningPlanRelationCreationResults = ['success'=>[],'error'=>[]];
$studentLPCreationResults = ['success'=>[],'error'=>[]];
$teacherLPCreationResults = ['success'=>[],'error'=>[]];

// Loop through each sheet and create the corresponding entity
for ($sheetIndex = 0; $sheetIndex < $sheetCount; $sheetIndex++) {
    $sheet = $objPHPExcel->getSheet($sheetIndex);
    $sheetName = $sheet->getTitle();
    
    // Get the highest row and column numbers
    $highestRow = $sheet->getHighestDataRow(); 
    $highestColumn = $sheet->getHighestDataColumn();
    $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

    // Loop through each row
    for ($row = 1; $row <= $highestRow; $row++) {
        // Assuming the first row contains column headers
        if ($row == 1) {
            continue;
        }

        // Get data from each column
        $rowData = array();
        for ($col =1 ; $col <= $highestColumnIndex; $col++) {
            $cellValue = $sheet->getCellByColumnAndRow($col, $row)->getValue();

            if ($sheetName === "Estudiante-basico" && $col == 8 && Date::isDateTime($sheet->getCellByColumnAndRow($col, $row))) {
                $cellValue =  Date::excelToTimestamp($cellValue)+(24 * 3600);
            }
            if ($sheetName === "Docente-basico" && $col == 7 && Date::isDateTime($sheet->getCellByColumnAndRow($col, $row))) {
                $cellValue =  Date::excelToTimestamp($cellValue)+(24 * 3600);
            }
            // Store data into an array
            $rowData[$col] = $cellValue;
        }
        // -------------------------------------------------------------------------------------------------
        
        //STUDENTS CREATION -------------------------- create STUDENTS users from Estudiante-basico table
        if($sheetName === "Estudiante-basico"){
            $userName = $rowData[2];
            $userState = $rowData[13];
            
            $creationResult = ['Usuario'=>$userName,'Tabla'=>$sheetName,'Fila'=>$row];
            try{
                if(!$enableStudentsCreation){
                    throw new Exception('Creacion de estudiantes deshabilitada.');
                }
                
                if($createdStudents >= $limit){
                    throw new Exception('Se supero el limite de estudiantes creados para esta prueba');
                }
                $studentData = construct_student_entity($rowData);
                

                //Uncomment the line below to create the users.
                $createdStudent = core_user_external::create_users([$studentData])[0];
                $message = 'ok';
                //If the user is suspended, we have to updated it.
                if($userState==='inactivo'){
                    $updateUserParams = ['id'=>$createdStudent['id'],'suspended'=>1];
                    try{
                        $updatedStudent = core_user_external::update_users([$updateUserParams])[0];
                    }catch(Exception $e){
                        $message = $e->getMessage();
                    }
                }
                $creationResult['Id asignado']=$createdStudent['id'];
                $creationResult['Mensaje']=$message;
                $studentsCreationResults['success'][]=$creationResult;
                $createdStudents += 1;
            }catch (Exception $e){
                $DB->force_transaction_rollback();
                $creationResult['Error'] = $e->debuginfo? $e->debuginfo : $e->getMessage();
                $studentsCreationResults['error'][]=$creationResult;
                $createdStudents += 1;
            }
        }
        
        //TEACHERS CREATION -------------------------- create TEACHERS users from Docente-basico table
        if($sheetName === "Docente-basico"){
            $userName =$rowData[2];
            $userState =$rowData[13];
            
            $creationResult = ['Usuario'=>$userName,'Tabla'=>$sheetName,'Fila'=>$row];
            try{
                if(!$enableTeachersCreation){
                    throw new Exception('Creacion de profesores deshabilitada.');
                }
                if($createdTeachers >= $limit){
                    throw new Exception('Se supero el limite de maestros creados para esta prueba.');
                }
                
                $teacherData = construct_teacher_entity($rowData);
                //Uncomment the line below to create the users
                $createdTeacher = core_user_external::create_users([$teacherData])[0];
                $message = 'ok';
                //If the user is suspended, we have to updated it.
                if($userState==='inactivo'){
                    $updateUserParams = ['id'=>$createdTeacher['id'],'suspended'=>1];
                    try{
                        $updatedTeacher = core_user_external::update_users([$updateUserParams])[0];
                    }catch(Exception $e){
                        $message = $e->getMessage();
                    }
                }
                $creationResult['Id asignado']=$createdTeacher['id'];
                $creationResult['Mensaje']=$message;
                $teachersCreationResults['success'][]=$creationResult;
                $createdTeachers+=1;
            }catch (Exception $e){
                $DB->force_transaction_rollback();
                $creationResult['Error'] = $e->debuginfo? $e->debuginfo : $e->getMessage();
                $teachersCreationResults['error'][]=$creationResult;
                $createdTeachers += 1;
            }
        }
        
        //LEARNING PLANS CREATION -------------------------- Create LEARNING PLANS from Carrera-basico table
        if($sheetName === "Carrera-basico"){
            $learningPlanCode = $rowData[1];
            $learningPlanShortId = $rowData[2];
            $learningPlanName = $rowData[3];
            $creationResult = ['Carrera'=>$learningPlanName,'Nombre corto'=>$learningPlanShortId,'Tabla'=>$sheetName,'Fila'=>$row];
            try{
                if(!$enableLPCreation){
                    throw new Exception('Creacion de LP deshabilitada.');
                }
                if($createdLPs >= $limit){
                    throw new Exception('Se supero el limite de LPs creados para esta prueba.');
                }
                if(!$learningPlanShortId || $learningPlanShortId===''){
                    throw new Exception('El learning plan debe tener un nombre corto.');
                }
                if(!$learningPlanName || $learningPlanName===''){
                    throw new Exception('El learning plan debe tener un nombre.');
                }
                
                $learningPlanData = construct_learning_plan_entity($rowData);
                [
                    'code'=>$code,
                    'learningshortid' => $learningshortid,
                    'learningname' => $learningname,
                    'periods' => $periods,
                    'description' => $description,
                    'hasperiod' => $hasperiod,
                ] = $learningPlanData;

                //Uncomment the line below to create the learning Plans
                $createdLearningPlan = save_learning_plan_external::save_learning_plan($learningshortid, $learningname,$periods,[],[],null,$description, $hasperiod);
                $message = 'ok';
                $creationResult['Id asignado']=$createdLearningPlan['learningplanid'];
                $creationResult['Mensaje']=$message;
                $learningPlanCreationResults['success'][$learningPlanCode]=$creationResult;
                $createdLPs += 1;
            }catch (Exception $e){
                $creationResult['Error']=$e->getMessage();
                $learningPlanCreationResults['error'][$learningPlanCode]=$creationResult;
                $createdLPs += 1;
            }
        }
        
        //COURSES CREATION -------------------------- Create COURSES from Curso-basico table
        if($sheetName === "Curso-basico"){
            $courseShortname = $rowData[1];
            $courseFullname = $rowData[2];
            $coursePrerequisites = $rowData[6];
            
            $creationResult = ['Curso'=>$courseFullname,'Tabla'=>$sheetName,'Fila'=>$row];
            try{
                if(!$enableCourseCreation){
                    throw new Exception('Creacion de cursos deshabilitada.');
                }
                // throw new Exception('Creacion de LP deshabilitada.');
                if($createdCourses >= $limit){
                    throw new Exception('Se supero el limite de cursos creados para esta prueba.');
                }
                
                if(!$courseShortname || $courseShortname===''){
                    throw new Exception('El Curso debe tener un nombre corto (código).');
                }
                
                if(!$courseFullname || $courseFullname===''){
                    throw new Exception('El Curso debe tener un nombre.');
                }
                
                if($coursePrerequisites){
                    $coursePreRequisites = explode(',',$coursePrerequisites);
                    foreach($coursePreRequisites as $preRequisite){
                        if(!$DB->get_record('course',['shortname'=>$preRequisite])){
                            throw new Exception('El curso prerequisito con nombre corto '.$preRequisite.' no ha sido creado aun.');
                        }
                    }
                }
                
                $courseData =construct_course_entity($rowData);

                //Uncomment the line below to create the courses
                $createdCourse = core_course_external::create_courses([$courseData])[0];
                $message = 'ok';
                
                $creationResult['Id asignado']=$createdCourse['id'];
                $creationResult['Mensaje']=$message;
                $courseCreationResults['success'][]=$creationResult;
                $createdCourses+=1;
            }catch (Exception $e){
                $DB->force_transaction_rollback();
                $creationResult['Error']=$e->getMessage();
                $courseCreationResults['error'][]=$creationResult;
                $createdCourses+=1;
            }
        }
        
        //LP COURSES CREATION -------------------------- Added COURSES to LEARNING PLANS from Curso-carrera table
        if($sheetName === "Curso-carrera"){
            $courseShortname = $rowData[1];
            $learningPlanCode = $rowData[2];
            $period = $rowData[3];
            $bimester = $rowData[4];
            $creationResult = ['Nombre corto curso'=>$courseShortname, 'Codigo Carrera'=>$learningPlanCode,'Tabla'=>$sheetName,'Fila'=>$row];  
            try{
                
                if(!$enableCourseLPCreation){
                    throw new Exception('Creacion de cursos LP deshabilitada.');
                }
                if($courseLPCreated >= $limit){
                    throw new Exception('Se supero el limite de cursos LP creados para esta prueba.');
                }
                if(!$courseShortname){
                    throw new Exception('Se debe especificar el shortname de el curso.');
                }
                if(!$learningPlanCode){
                    throw new Exception('Se debe especificar el codigo del learning plan especificado en la hoja Carrera-basico.');
                }
                if(!$period){
                    throw new Exception('Se debe especificar el periodo al cual pertenece el curso.');
                }
                if(!$bimester){
                    throw new Exception('Se debe especificar el bimestre al cual pertenece el curso.');
                }
                if(is_string($period) || is_string($bimester)){
                    throw new Exception('El valor del periodo y del bimestre deben ser númericos.');
                }
                if (!($bimester >= 1 && $bimester <= 2) || is_float($bimester)) {
                    throw new Exception('El valor del bimestre debe estar entre 1 y 2, sin decimales.');
                }
                
                if(!$courseId = $DB->get_field('course','id',['shortname'=>$courseShortname])){
                    throw new Exception('El curso con codigo '.$courseShortname.' no ha sido creado.');
                }
                
                $learningPlanShortName=null;
                if(array_key_exists($courseLearningPlanRelation['learningPlanCode'],$learningPlanCreationResults['success'])){
                    $learningPlanShortName = $learningPlanCreationResults['success'][$learningPlanCode]['Nombre corto'];
                }
                else if(array_key_exists($courseLearningPlanRelation['learningPlanCode'],$learningPlanCreationResults['error'])){
                    $learningPlanShortName = $learningPlanCreationResults['error'][$learningPlanCode]['Nombre corto'];
                }
                if(!$learningPlanId = $DB->get_field('local_learning_plans','id',['shortname'=>$learningPlanShortName])){
                    throw new Exception('El plan de aprendizaje no ha sido creado.');
                }
                $learningPlanPeriods = array_values($DB->get_records('local_learning_periods',['learningplanid'=>$learningPlanId],'','id'));
                
                if($period > count($learningPlanPeriods)){
                    throw new Exception('El plan de aprendizaje no posee periodo '.$period.'.');
                }
                $coursePeriodId = $learningPlanPeriods[$period-1]->id;
            
                $periodSubPeriodId = $DB->get_field('local_learning_subperiods','id',['learningplanid'=>$learningPlanId, 'periodid'=>$coursePeriodId, 'position'=>$bimester-1]);
                
                //Uncomment the line below to create the courses
                $createdCourseLearningPlanRelation = save_learning_course_external::save_learning_course($learningPlanId,$coursePeriodId,$periodSubPeriodId,$courseId,1,-1,null);
                $message = 'ok';
                
                $learningPlanPeriodCourses=$DB->get_records('local_learning_courses',['learningplanid'=>$learningPlanId,'periodid'=>$coursePeriodId],'','id,courseid');
                $courseIdsToAddRelation = '';
                $lpCourseId=null;
                foreach($learningPlanPeriodCourses as $lpCourse){
                    if($lpCourse->courseid == $courseId){
                        $lpCourseId = $lpCourse->id;
                        continue;
                    }
                    $courseIdsToAddRelation .= $lpCourse->id.',';
                }
                $courseIdsToAddRelation = rtrim($courseIdsToAddRelation, ',');
                if($courseIdsToAddRelation !== ''){
                    try{
                        $relationDone=add_course_relations_external::add_course_relations($lpCourseId,$courseIdsToAddRelation);
                        if(!$relationDone){
                            throw new Exception('La relacion con los cursos del periodo no se pudo establecer, debera hacerlo manualmente.');
                        }
                    }catch (Exception $e){
                        $message = $e->getMessage();
                    }
                }
                $creationResult['Curso Agregado a la carrera']=true;
                $creationResult['Mensaje']=$message;
                $courseLearningPlanRelationCreationResults['success'][]=$creationResult;
                $courseLPCreated+=1;
            }catch (Exception $e){
                $creationResult['Error']=$e->getMessage();
                $courseLearningPlanRelationCreationResults['error'][]=$creationResult;
                $courseLPCreated+=1;
            }
        }
        
        //LP STUDENTS CREATION --------------------------  Added STUDENTS to LEARNING PLANS from Estudiante-basico table
        if($sheetName === "Estudiante-carrera"){
            $studentUsername = $rowData[1];
            $learningPlanCode = $rowData[2];
            $period = $rowData[3];
            $bimester = $rowData[4];
            $creationResult = ['Nombre de usuario'=>$studentUsername, 'Codigo Carrera'=>$learningPlanCode,'Tabla'=>$sheetName,'Fila'=>$row];  
            
            try{

                if(!$enableStudentLPCreation){
                    throw new Exception('Creacion de estudiantes LP deshabilitada.');
                }
                // throw new Exception('Creacion de LP deshabilitada.');
                if($studentLPCreated >= $limit){
                    throw new Exception('Se supero el limite de estudiantes LP creados para esta prueba.');
                }
                if(!$studentUsername){
                    throw new Exception('Se debe especificar el username del estudiante (identificacioó).');
                }
                if(!$learningPlanCode){
                    throw new Exception('Se debe especificar el codigo del learning plan especificado en la hoja Carrera-basico.');
                }   
                if(!$period){
                    throw new Exception('Se debe especificar el periodo al cual se dirige el estudiante.');
                } 
                if(!$bimester){
                    throw new Exception('Se debe especificar el bimestre al cual se dirige el estudiante dentro del periodo.');
                }
                
                if(is_string($period) || is_string($bimester)){
                    throw new Exception('El valor del periodo y del bimestre deben ser númericos');
                }
                if (!($bimester >= 1 && $bimester <= 2) || is_float($bimester)) {
                    throw new Exception('El valor del bimestre debe estar entre 1 y 2, sin decimales.');
                }
                
                if(!$studentUserId = $DB->get_field('user','id',['username'=>$studentUsername])){
                    throw new Exception('El usuario '.$studentUsername.' no existe.');
                }
                
                $learningPlanShortName=null;
                if(array_key_exists($studentLearningPlanRelation['learningPlanCode'],$learningPlanCreationResults['success'])){
                    $learningPlanShortName = $learningPlanCreationResults['success'][$learningPlanCode]['Nombre corto'];
                }
                else if(array_key_exists($studentLearningPlanRelation['learningPlanCode'],$learningPlanCreationResults['error'])){
                    $learningPlanShortName = $learningPlanCreationResults['error'][$learningPlanCode]['Nombre corto'];
                }
                if(!$learningPlanId = $DB->get_field('local_learning_plans','id',['shortname'=>$learningPlanShortName])){
                    throw new Exception('El plan de aprendizaje no ha sido creado.');
                }
                $learningPlanPeriods = array_values($DB->get_records('local_learning_periods',['learningplanid'=>$learningPlanId],'','id'));
                
                if($period > count($learningPlanPeriods)){
                    throw new Exception('El plan de aprendizaje no posee periodo '.$period.'.');
                }
                $studentPeriodId = $learningPlanPeriods[$period-1]->id;
                
                $lpStudentCreated = add_learning_user_external::add_learning_user($learningPlanId,$studentUserId,5,$studentPeriodId,null);
                $message = 'ok';
                
                $creationResult['Estudiante añadido a carrera']=true;
                $creationResult['Mensaje']='ok';
                $studentLPCreationResults['success'][]=$creationResult;
                // $studentLPCreationResults
                $studentLPCreated+=1;
            }catch(Exception $e){
                $creationResult['Error']=$e->getMessage();
                $studentLPCreationResults['error'][]=$creationResult;
                $studentLPCreated+=1;
            }
        }
        
        //LP TEACHERS CREATION --------------------------  Added TEACHERS to LEARNING PLANS from Docente-carrera table
        if($sheetName === "Docente-carrera"){
            $teacherUsername = $rowData[1];
            $learningPlanCode = $rowData[2];
            $creationResult = ['Nombre de usuario'=>$studentUsername, 'Codigo Carrera'=>$learningPlanCode,'Tabla'=>$sheetName,'Fila'=>$row];
            try{
                
                if(!$enableTeacherLPCreation){
                    throw new Exception('Creacion de estudiantes LP deshabilitada.');
                }
                // throw new Exception('Creacion de LP deshabilitada.');
                if($teacherLPCreated >= $limit){
                    throw new Exception('Se supero el limite de estudiantes LP creados para esta prueba.');
                }
                
                if(!$teacherUsername){
                    throw new Exception('Se debe especificar el username del estudiante (identificacioó)');
                }
                if(!$learningPlanCode){
                    throw new Exception('Se debe especificar el codigo del learning plan especificado en la hoja Carrera-basico');
                }
                
                if(!$teacherUserId = $DB->get_field('user','id',['username'=>$teacherUsername])){
                    throw new Exception('El usuario '.$teacherUsername.' no existe');
                }
                
                $learningPlanShortName=null;
                if(array_key_exists($teacherLearningPlanRelation['learningPlanCode'],$learningPlanCreationResults['success'])){
                    $learningPlanShortName = $learningPlanCreationResults['success'][$learningPlanCode]['Nombre corto'];
                }
                else if(array_key_exists($teacherLearningPlanRelation['learningPlanCode'],$learningPlanCreationResults['error'])){
                    $learningPlanShortName = $learningPlanCreationResults['error'][$learningPlanCode]['Nombre corto'];
                }
                if(!$learningPlanId = $DB->get_field('local_learning_plans','id',['shortname'=>$learningPlanShortName])){
                    throw new Exception('El plan de aprendizaje no ha sido creado.');
                }
                $lpTeacherCreated = add_learning_user_external::add_learning_user($learningPlanId,$teacherUserId,4,null,null);
                $creationResult['Docente añadido a carrera']=true;
                $creationResult['Mensaje']='ok';
                $teacherLPCreationResults['success'][]=$creationResult;
                $teacherLPCreated+=1;
            }catch(Exception $e){
                $creationResult['Error']=$e->getMessage();
                $teacherLPCreationResults['error'][]=$creationResult;
                $teacherLPCreated+=1;
            }
        }
    }
}

$studentsCreationResults = json_encode($studentsCreationResults, JSON_PRETTY_PRINT);
$teachersCreationResults = json_encode($teachersCreationResults, JSON_PRETTY_PRINT);
$learningPlanCreationResults = json_encode($learningPlanCreationResults, JSON_PRETTY_PRINT);
$courseCreationResults = json_encode($courseCreationResults, JSON_PRETTY_PRINT);
$courseLearningPlanRelationCreationResults = json_encode($courseLearningPlanRelationCreationResults, JSON_PRETTY_PRINT);
$studentLPCreationResults = json_encode($studentLPCreationResults, JSON_PRETTY_PRINT);
$teacherLPCreationResults = json_encode($teacherLPCreationResults, JSON_PRETTY_PRINT);
        
$folderPath = __DIR__.'/';

$studentsCreationResultsPath = $folderPath . 'studentsCreationResults.txt';
$teachersCreationResultsPath = $folderPath . 'teachersCreationResults.txt';
$learningPlanCreationResultsPath = $folderPath . 'learningPlanCreationResults.txt';
$courseCreationResultsPath = $folderPath . 'courseCreationResults.txt';
$courseLearningPlanRelationCreationResultsPath = $folderPath . 'courseLearningPlanRelationCreationResults.txt';
$studentLPCreationResultsPath = $folderPath . 'studentLPCreationResults.txt';
$teacherLPCreationResultsPath = $folderPath . 'teacherLPCreationResults.txt';

$resultsArray = [
    $studentsCreationResultsPath=>$studentsCreationResults,
    $teachersCreationResultsPath=>$teachersCreationResults,
    $learningPlanCreationResultsPath=>$learningPlanCreationResults,
    $courseCreationResultsPath=>$courseCreationResults,
    $courseLearningPlanRelationCreationResultsPath=>$courseLearningPlanRelationCreationResults,
    $studentLPCreationResultsPath=>$studentLPCreationResults,
    $teacherLPCreationResultsPath=>$teacherLPCreationResults,
];

foreach($resultsArray as $resultPath => $resultJson){
    try{
        $fileHandle = fopen($resultPath, 'w');
        if (!$fileHandle) {
            throw new Exception('No se pudo escribir el archivo'.$resultPath);
        }
        fwrite($fileHandle, $resultJson);
        fclose($fileHandle);
        
    }catch (Exception $e){
        print_object($e->getMessage());
    }
    
}

function construct_student_entity($data){
    $countryCodes=['Panamá'=>'PA','Venezuela'=>'VE'];
    $countryTimezones = ['Panamá'=>'America/Panama','Venezuela'=>'America/Caracas'];
    
    $studentEntity = [
        'createpassword'=>1,
        'username'=> $data[2],
        'firstname' => $data[4],
        'lastname' => $data[5],
        'email' => $data[6]
    ];
    $studentEntity = $data[10] ? array_merge($studentEntity, ['country' => $countryCodes[$data[10]]]) : $studentEntity;
    $studentEntity = $data[8] ? array_merge($studentEntity, ['phone1' => $data[8]]) : $studentEntity;
    $studentEntity = $data[7] ? array_merge($studentEntity, ['phone2' => $data[7]]) : $studentEntity;
    $studentEntity = $data[11] ? array_merge($studentEntity, ['address' => $data[11]]) : $studentEntity;
    $customFields = [
        [
            'type'=>'documenttype',
            'value'=>$data[1]
        ],   
        [
            'type'=>'documentnumber',
            'value'=>$data[2]
        ],   
        [
            'type'=>'birthdate',
            'value'=>$data[9]
        ],   
        [
            'type'=>'studentstatus',
            'value'=>$data[13]
        ],
        [
            'type'=>'personalemail',
            'value'=>$data[6]
        ],
        [
            'type'=>'gmkgenre',
            'value'=>$data[12]
        ],
        [
            'type'=>'gmkjourney',
            'value'=>$data[14]
        ]
    ];
    
    $studentEntity['customfields'] = $customFields;
    return  $studentEntity;
}

function construct_teacher_entity($data){
    
    $teacherEntity = [
        'createpassword'=>1,
        'username'=> strtolower($data[2]),
        'firstname' => $data[4],
        'lastname' => $data[5],
        'email' => $data[6]
    ];
    $teacherEntity = $data[7] ? array_merge($teacherEntity, ['phone2' => $data[7]]) : $teacherEntity;
    $customFields = [
        [
            'type'=>'documenttype',
            'value'=>$data[1]
        ],   
        [
            'type'=>'documentnumber',
            'value'=>$data[2]
        ],   
        [
            'type'=>'birthdate',
            'value'=>$data[8]
        ],   
        [
            'type'=>'studentstatus',
            'value'=>$data[10]
        ],
        [
            'type'=>'personalemail',
            'value'=>$data[6]
        ],
        [
            'type'=>'gmkgenre',
            'value'=>$data[9]
        ],
    ];
    
    $teacherEntity['customfields'] = $customFields;
    
    return $teacherEntity;
}

function construct_learning_plan_entity($data){
    
    $learningPlanEntity = [
        'code'=>$data[1],
        'learningshortid'=>$data[2],
        'learningname'=> $data[3],
        'description'=>$data[3],
        'hasperiod'=>true
    ];
    $periods = [];
    for($period = 1; $period <= $data[5];$period++){
        $periods[]=['name'=>'Cuatrimestre '.$period, 'months'=>4, 'hassubperiods'=>true];
    }
    $learningPlanEntity['periods']=$periods;
    return $learningPlanEntity;
}

function construct_course_entity($data){
    return  [
        'fullname'=>$data[2],
        'shortname'=> $data[1],
        'categoryid' =>$data[7]===0? 18 : 17,
        'customfields'=>[
            [
                'shortname'=>'credits',
                'value'=>strval($data[3])
            ],
            [
                'shortname'=>'t',
                'value'=>strval($data[4])
            ],
            [
                'shortname'=>'p',
                'value'=>strval($data[5])
            ],
            [
                'shortname'=>'pre',
                'value'=>strval($data[6])
            ],
            [
                'shortname'=>'tc',
                'value'=>$data[7]
            ]
        ]
    ];
}



