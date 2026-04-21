<?php
namespace local_grupomakro_core\external\admin;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use stdClass;

class scheduler extends external_api {

    // --- 1. Scheduler Context (Config) ---

    public static function get_scheduler_context_parameters() {
        return new external_function_parameters([
            'periodid' => new external_value(PARAM_INT, 'Academic Period ID')
        ]);
    }

    public static function get_scheduler_context($periodid) {
        global $DB;
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        // 1. Get Classrooms
        $classrooms = $DB->get_records('gmk_classrooms', ['active' => 1], 'name ASC');

        // 2. Get Holidays for this period
        $holidays = $DB->get_records('gmk_holidays', ['academicperiodid' => $periodid], 'date ASC');

        // 3. Get Subject Loads for this period
        $loads = $DB->get_records('gmk_subject_loads', ['academicperiodid' => $periodid], 'subjectname ASC');

        // 4. Get Period Record and Careers
        $periodRec = $DB->get_record('gmk_academic_periods', ['id' => $periodid]);
        $period = null;
        $configSettings = '';
        $careers = [];

        if ($periodRec) {
            $period = [
                'id' => $periodRec->id,
                'name' => $periodRec->name,
                'start' => date('Y-m-d', $periodRec->startdate),
                'end' => date('Y-m-d', $periodRec->enddate)
            ];
            $configSettings = $periodRec->configsettings ?: '';

            // Add subperiod date ranges from academic calendar
            $calendar = $DB->get_record('gmk_academic_calendar', ['academicperiodid' => $periodid]);
            if ($calendar && $calendar->hassubperiods) {
                $period['subperiods'] = [
                    1 => ['start' => date('Y-m-d', $calendar->block1start), 'end' => date('Y-m-d', $calendar->block1end)],
                    2 => ['start' => date('Y-m-d', $calendar->block2start), 'end' => date('Y-m-d', $calendar->block2end)]
                ];
            }

            // Extract Careers from linked learning plans
            $lpIds = json_decode($periodRec->learningplans, true);
            if ($lpIds && is_array($lpIds)) {
                list($insql, $inparams) = $DB->get_in_or_equal($lpIds);
                $plans = $DB->get_records_select('local_learning_plans', "id $insql", $inparams, 'name ASC', 'id, name');
                foreach ($plans as $p) {
                    $careers[] = $p->name;
                }
            }
        }

        return [
            'classrooms' => array_values($classrooms),
            'holidays' => array_values($holidays),
            'loads' => array_values($loads),
            'period' => $period,
            'configSettings' => $configSettings,
            'careers' => $careers
        ];
    }

    public static function get_scheduler_context_returns() {
        return new external_single_structure([
            'classrooms' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'ID'),
                    'name' => new external_value(PARAM_TEXT, 'Name'),
                    'capacity' => new external_value(PARAM_INT, 'Capacity'),
                    'type' => new external_value(PARAM_TEXT, 'Type'),
                    'active' => new external_value(PARAM_INT, 'Active')
                ])
            ),
            'holidays' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'ID'),
                    'date' => new external_value(PARAM_INT, 'Timestamp'),
                    'name' => new external_value(PARAM_TEXT, 'Name'),
                    'type' => new external_value(PARAM_TEXT, 'Type')
                ])
            ),
            'loads' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'ID'),
                    'subjectname' => new external_value(PARAM_TEXT, 'Subject Name'),
                    'total_hours' => new external_value(PARAM_INT, 'Total Hours'),
                    'intensity' => new external_value(PARAM_FLOAT, 'Weekly Intensity', VALUE_OPTIONAL)
                ])
            ),
            'period' => new external_single_structure([
                'id' => new external_value(PARAM_INT, 'ID'),
                'name' => new external_value(PARAM_TEXT, 'Name'),
                'start' => new external_value(PARAM_TEXT, 'Start Date'),
                'end' => new external_value(PARAM_TEXT, 'End Date')
            ], 'Period metadata', VALUE_OPTIONAL),
            'configSettings' => new external_value(PARAM_RAW, 'JSON encoded config settings', VALUE_OPTIONAL),
            'careers' => new external_multiple_structure(new external_value(PARAM_TEXT, 'Career name'), 'List of career names', VALUE_OPTIONAL)
        ]);
    }

    // --- 2. Save Config ---

    public static function save_scheduler_config_parameters() {
        return new external_function_parameters([
            'periodid' => new external_value(PARAM_INT, 'Period ID'),
            'holidays' => new external_multiple_structure(
                new external_single_structure([
                    'date' => new external_value(PARAM_INT, ''),
                    'name' => new external_value(PARAM_TEXT, ''),
                    'type' => new external_value(PARAM_TEXT, '')
                ]),
                'List of holidays',
                VALUE_DEFAULT,
                []
            ),
            'loads' => new external_multiple_structure(
                new external_single_structure([
                    'subjectname' => new external_value(PARAM_TEXT, ''),
                    'total_hours' => new external_value(PARAM_INT, ''),
                    'intensity' => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, 0)
                ]),
                'List of subject loads',
                VALUE_DEFAULT,
                []
            ),
            'configsettings' => new external_value(PARAM_RAW, 'JSON string with shift/internal parameters', VALUE_DEFAULT, '')
        ]);
    }

    public static function save_scheduler_config($periodid, $holidays, $loads, $configsettings = '') {
        global $DB;
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $transaction = $DB->start_delegated_transaction();

        try {
            // Replace Holidays
            $DB->delete_records('gmk_holidays', ['academicperiodid' => $periodid]);
            foreach ($holidays as $h) {
                $rec = (object)$h;
                $rec->academicperiodid = $periodid;
                $rec->usermodified = $GLOBALS['USER']->id;
                $rec->timecreated = time();
                $rec->timemodified = time();
                $DB->insert_record('gmk_holidays', $rec);
            }

            // Replace Loads
            // Note: This wipes custom loads. Be careful if UI doesn't send all.
            // Assuming UI sends full list of configured loads.
            $DB->delete_records('gmk_subject_loads', ['academicperiodid' => $periodid]);
            foreach ($loads as $l) {
                $rec = (object)$l;
                $rec->academicperiodid = $periodid;
                $rec->usermodified = $GLOBALS['USER']->id;
                $rec->timecreated = time();
                $rec->timemodified = time();
                $DB->insert_record('gmk_subject_loads', $rec);
            }

            // Update configuration settings in period if provided
            if ($configsettings !== '') {
                $DB->set_field('gmk_academic_periods', 'configsettings', $configsettings, ['id' => $periodid]);
            }

            $transaction->allow_commit();
            return true;

        } catch (\Exception $e) {
            $transaction->rollback($e);
            return false;
        }
    }

    public static function save_scheduler_config_returns() {
        return new external_value(PARAM_BOOL, 'Success');
    }

    // --- 3. Demand Data ---

    public static function get_demand_data_parameters() {
        return new external_function_parameters([
            'periodid' => new external_value(PARAM_INT, 'Academic Period ID')
        ]);
    }

    public static function get_demand_data($periodid) {
        global $DB, $CFG;
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/planning_manager.php');

        // Determine effective planning period.
        // gmk_academic_planning records are stored with the BASE period used during planning,
        // not the TARGET period (P-I, P-II, etc.). If the selected $periodid is a target period
        // mapped from an earlier base period, we use that base period to query planning records.
        $effectivePeriodId = $periodid;
        $directCount = $DB->count_records('gmk_academic_planning', ['academicperiodid' => $periodid]);
        if ($directCount === 0) {
            $reverseMap = $DB->get_record('gmk_planning_period_maps', ['target_period_id' => $periodid]);
            if ($reverseMap) {
                $effectivePeriodId = $reverseMap->base_period_id;
                gmk_log("get_demand_data: period $periodid es target de base {$effectivePeriodId} (index {$reverseMap->relative_index})");
            }
        }

        // Use the comprehensive planning_manager demand calculation.
        $data = \local_grupomakro_core\local\planning_manager::get_demand_data($effectivePeriodId);

        $demand_tree = $data['demand_tree'];

        // Build curricula map for enriching tree entries with subjectid, levelid, subperiod.
        // local_learning_courses links Moodle courses to plan periods.
        $lpc_sql = "SELECT lpc.id as subjectid, lpc.learningplanid, lpc.periodid as levelid,
                           lpc.courseid as moodleid,
                           (COALESCE(sp.position + 1, 0)) as subperiod_pos
                    FROM {local_learning_courses} lpc
                    LEFT JOIN {local_learning_subperiods} sp ON sp.id = lpc.subperiodid";
        $lpc_records = $DB->get_records_sql($lpc_sql);

        // Index by [planid][moodleid] -> {subjectid, levelid, subperiod_pos}
        $curricula_map = [];
        foreach ($lpc_records as $lpc) {
            if (!empty($lpc->learningplanid)) {
                $curricula_map[$lpc->learningplanid][$lpc->moodleid] = [
                    'subjectid' => (int)$lpc->subjectid,
                    'levelid'   => (int)$lpc->levelid,
                    'subperiod' => (int)$lpc->subperiod_pos,
                ];
            }
        }

        // Enrich demand_tree course_counts with subjectid, levelid, subperiod, plan_map
        // (planning_manager builds an empty plan_map; we populate it here from curricula).
        foreach ($demand_tree as $career => &$shifts) {
            foreach ($shifts as $shift => &$semesters) {
                foreach ($semesters as $levelKey => &$semData) {
                    foreach ($semData['course_counts'] as $moodleId => &$courseData) {
                        if (!isset($courseData['subjectid'])) $courseData['subjectid'] = 0;
                        if (!isset($courseData['levelid']))   $courseData['levelid']   = 0;
                        if (!isset($courseData['subperiod'])) $courseData['subperiod'] = 0;
                        if (!isset($courseData['plan_map']))  $courseData['plan_map']  = [];

                        foreach ($curricula_map as $planId => $planCourses) {
                            if (isset($planCourses[$moodleId])) {
                                $info = $planCourses[$moodleId];
                                // Use first plan found as the primary subjectid/levelid
                                if (empty($courseData['subjectid'])) {
                                    $courseData['subjectid'] = $info['subjectid'];
                                    $courseData['levelid']   = $info['levelid'];
                                    $courseData['subperiod'] = $info['subperiod'];
                                }
                                $courseData['plan_map'][$planId] = [
                                    'subjectid' => $info['subjectid'],
                                    'levelid'   => $info['levelid'],
                                ];
                            }
                        }
                    }
                    unset($courseData);
                }
                unset($semData);
            }
            unset($semesters);
        }
        unset($shifts);

        // Build student_list in the format expected by the scheduler external API.
        $student_list = [];
        foreach ($data['student_list'] as $stu) {
            $semNum = 0;
            if (!empty($stu['currentSemConfig']) && preg_match('/(\d+)/', $stu['currentSemConfig'], $m)) {
                $semNum = (int)$m[1];
            }
            $student_list[] = [
                'id'           => (int)$stu['dbId'],
                'dbId'         => (int)$stu['dbId'],
                'name'         => (string)$stu['name'],
                'career'       => (string)$stu['career'],
                'shift'        => (string)($stu['shift'] ?: 'Sin Jornada'),
                'semester'     => $semNum,
                'entry_period' => (string)($stu['entry_period'] ?? ''),
            ];
        }

        // Build subjects list [{id, name}].
        $subjects = [];
        foreach ($data['subjects'] as $subj) {
            $subjects[] = [
                'id'   => (int)$subj['id'],
                'name' => (string)$subj['name'],
            ];
        }

        // Build projections list [{id, career, shift, count}].
        $projections = [];
        foreach ($data['projections'] as $proj) {
            $projections[] = [
                'id'     => (int)$proj->id,
                'career' => (string)$proj->career,
                'shift'  => (string)$proj->shift,
                'count'  => (int)$proj->count,
            ];
        }

        gmk_log("get_demand_data: periodid={$periodid} effectivePeriod={$effectivePeriodId} tree_keys=" . count($demand_tree) . " students=" . count($student_list));

        return [
            'demand_tree'  => json_encode($demand_tree),
            'student_list' => $student_list,
            'projections'  => $projections,
            'subjects'     => $subjects,
        ];
    }

    public static function get_demand_data_returns() {
        return new external_single_structure([
            'demand_tree' => new external_value(PARAM_RAW, 'JSON nested structure [Career][Jornada][Sem]'),
            'student_list' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, ''),
                    'dbId' => new external_value(PARAM_INT, 'DB user id for matching with class studentIds', VALUE_OPTIONAL),
                    'name' => new external_value(PARAM_TEXT, ''),
                    'career' => new external_value(PARAM_TEXT, ''),
                    'shift' => new external_value(PARAM_TEXT, ''),
                    'semester' => new external_value(PARAM_INT, ''),
                    'entry_period' => new external_value(PARAM_TEXT, '', VALUE_OPTIONAL)
                ])
            ),
            'projections' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, ''),
                    'career' => new external_value(PARAM_TEXT, ''),
                    'shift' => new external_value(PARAM_TEXT, ''),
                    'count' => new external_value(PARAM_INT, '')
                ])
            ),
            'subjects' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, ''),
                    'name' => new external_value(PARAM_TEXT, '')
                ]), 'List of all subjects', VALUE_OPTIONAL
            )
        ]);
    }
    
    // --- 4. Save Projections ---
    
    public static function save_projections_parameters() {
        return new external_function_parameters([
            'periodid' => new external_value(PARAM_INT, ''),
            'projections' => new external_multiple_structure(
                new external_single_structure([
                    'career' => new external_value(PARAM_TEXT, ''),
                    'shift' => new external_value(PARAM_TEXT, ''),
                    'count' => new external_value(PARAM_INT, '')
                ])
            )
        ]);
    }
    
    public static function save_projections($periodid, $projections) {
        global $DB;
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);
        
        $DB->delete_records('gmk_academic_projections', ['academicperiodid' => $periodid]);
        
        foreach ($projections as $p) {
            $rec = (object)$p;
            $rec->academicperiodid = $periodid;
            $rec->usermodified = $GLOBALS['USER']->id;
            $rec->timecreated = time();
            $rec->timemodified = time();
            $DB->insert_record('gmk_academic_projections', $rec);
        }
        
        return true;
    }
    
    public static function save_projections_returns() {
        return new external_value(PARAM_BOOL, '');
    }
    
    // --- 5. Save Generated Schedule ---
    
    public static function save_generation_result_parameters() {
         return new external_function_parameters([
            'periodid' => new external_value(PARAM_INT, 'Academic Period ID'),
            'schedules' => new external_value(PARAM_RAW, 'JSON array of schedule objects'),
            'preserveexisting' => new external_value(PARAM_BOOL, 'When true, do not delete classes not present in payload', VALUE_DEFAULT, false)
         ]);
    }
    
    public static function save_generation_result($periodid, $schedules, bool $phase1only = false, bool $preserveexisting = false) {
        global $DB;
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);
        
        $data = is_string($schedules) ? json_decode($schedules, true) : $schedules;
        if (!is_array($data)) {
            $data = [];
        }
        $data = self::dedupe_payload_schedules($data);
        gmk_log("Iniciando guardado para Periodo Institucional: $periodid. Clases en payload: " . count($data));
        
        $transaction = $DB->start_delegated_transaction();
        
        try {
            // Need to decide: Wipe existing classes for this period? 
            // Or Update?
            // "Save Generation" implies overwriting the schedule for this period.
            // But we must be careful not to delete classes that have grades/attendance if we are re-running mid-term.
            // Start of term -> Safe to wipe.
            
            // For now, let's iterate and Create/Update.
            // Assumption: Frontend sends 'id' if updating.
            // To avoid duplicates while allowing updates:
            // 1. Identify existing numeric IDs in the payload that ARE programmed
            $validIds = [];
            $processablePayloadCount = 0;
            $classPeriodCache = [];
            $getClassPeriod = function(int $classid) use (&$classPeriodCache, $DB) {
                if (!array_key_exists($classid, $classPeriodCache)) {
                    $pid = $DB->get_field('gmk_class', 'periodid', ['id' => $classid], IGNORE_MISSING);
                    $classPeriodCache[$classid] = ($pid === false || $pid === null) ? null : (int)$pid;
                }
                return $classPeriodCache[$classid];
            };
            foreach ($data as $cls) {
                // External classes belong to other periods — never include in validIds (they are never deleted).
                if (self::is_payload_external($cls)) {
                    continue;
                }
                $isProgrammed = self::is_payload_programmed($cls);
                if (!$isProgrammed) {
                    continue;
                }

                $processablePayloadCount++;
                if (!empty($cls['id']) && is_numeric($cls['id'])) {
                    $candidateId = (int)$cls['id'];
                    $existingPeriod = $getClassPeriod($candidateId);
                    if ($existingPeriod !== null && $existingPeriod !== (int)$periodid) {
                        gmk_log("INFO: Skip foreign class id={$candidateId} (period {$existingPeriod}) while publishing period {$periodid}");
                        continue;
                    }
                    $validIds[] = $candidateId;
                }
            }

            // 2. Delete classes that belong to this period and are NOT in the payload.
            //    Never touch classes from other periods — they are "external" in the board.
            if ($preserveexisting) {
                gmk_log("INFO: preserveexisting=true, skipping destructive cleanup for period {$periodid}");
            } else if (!empty($validIds)) {
                $placeholders = implode(',', array_fill(0, count($validIds), '?'));
                $DB->delete_records_select(
                    'gmk_class',
                    "periodid = ? AND id NOT IN ($placeholders)",
                    array_merge([$periodid], $validIds)
                );
            } else if ($processablePayloadCount > 0 || empty($data)) {
                // No programmed ids in payload → wipe all classes of this period
                $DB->delete_records('gmk_class', ['periodid' => $periodid]);
            } else {
                gmk_log("INFO: Skip destructive cleanup for period {$periodid}: payload had no processable internal classes.");
            }

            $teachers_cache = [];
            $courses_cache = [];
            $classrooms_cache = [];
            $payload_user_cache = [];
            
            $periodRec = $DB->get_record('gmk_academic_periods', ['id' => $periodid]);
            $periodName = $periodRec ? $periodRec->name : "ID: $periodid";
            $periodStart = $periodRec ? $periodRec->startdate : time();
            $periodEnd = $periodRec ? $periodRec->enddate : time();

            // Cache names for logging
            $lp_names = $DB->get_records_menu('local_learning_plans', [], '', 'id, name');
            $lvl_names = $DB->get_records_menu('local_learning_periods', [], '', 'id, name');
            $course_fullnames = $DB->get_records_menu('course', [], '', 'id, fullname');

            // Resolve payload student token (userid or idnumber) to real userid.
            $resolve_payload_userid = function($token) use ($DB, &$payload_user_cache) {
                $raw = trim((string)$token);
                if ($raw === '') {
                    return 0;
                }
                if (array_key_exists($raw, $payload_user_cache)) {
                    return (int)$payload_user_cache[$raw];
                }

                $uid = 0;
                if (is_numeric($raw) && (int)$raw > 0) {
                    $candidate = (int)$raw;
                    $exists = $DB->record_exists('user', ['id' => $candidate, 'deleted' => 0]);
                    if ($exists) {
                        $uid = $candidate;
                    } else {
                        $byidnum = $DB->get_field('user', 'id', ['idnumber' => (string)$raw, 'deleted' => 0], IGNORE_MULTIPLE);
                        $uid = $byidnum ? (int)$byidnum : 0;
                    }
                } else {
                    $byidnum = $DB->get_field('user', 'id', ['idnumber' => $raw, 'deleted' => 0], IGNORE_MULTIPLE);
                    $uid = $byidnum ? (int)$byidnum : 0;
                }

                $payload_user_cache[$raw] = $uid;
                return $uid;
            };

            // Pre-clean: remove grade_categories (and their grade_items/grade_grades) that were left over
            // from partial previous publish attempts. These are categories whose fullname ends with
            // '-{classid} grade category' where classid belongs to this period but gradecategoryid=0 in gmk_class
            // (i.e. the category was created but the id was never saved back).
            // This prevents 'Duplicate entry' errors on grade_grades during retries.
            if (!$preserveexisting) {
                $periodClassIds = $DB->get_fieldset_select('gmk_class', 'id', 'periodid = :pid AND gradecategoryid = 0', ['pid' => $periodid]);
            if (!empty($periodClassIds)) {
                foreach ($periodClassIds as $cid) {
                    $orphanCats = $DB->get_records_sql(
                        "SELECT id FROM {grade_categories} WHERE " . $DB->sql_like('fullname', ':suffix'),
                        ['suffix' => '%-' . $cid . ' grade category']
                    );
                    foreach ($orphanCats as $ocat) {
                        // Delete grade_items and their grade_grades for this orphan category
                        $gitems = $DB->get_fieldset_select('grade_items', 'id', 'categoryid = :cid OR (itemtype = :t AND iteminstance = :iid)',
                            ['cid' => $ocat->id, 't' => 'category', 'iid' => $ocat->id]);
                        if (!empty($gitems)) {
                            list($insql, $inparams) = $DB->get_in_or_equal($gitems);
                            $DB->delete_records_select('grade_grades', "itemid $insql", $inparams);
                            $DB->delete_records_select('grade_items', "id $insql", $inparams);
                        }
                        $DB->delete_records('grade_categories', ['id' => $ocat->id]);
                        gmk_log("INFO: Pre-clean eliminó grade_category id={$ocat->id} huérfana para clase $cid");
                    }
                }
            }
            } else {
                gmk_log("INFO: preserveexisting=true, skipping period-wide pre-clean in save_generation_result");
            }

            // Load academic calendar once for the period (outside the loop to avoid multiple-records error).
            $periodCalendar = $DB->get_record('gmk_academic_calendar', ['academicperiodid' => $periodid], '*', IGNORE_MULTIPLE);

            // Accumulates classRec objects to process Moodle activities AFTER the DB transaction commits.
            // This is critical: Moodle core functions (add_moduleinfo, groups_create_group, etc.) open their
            // own internal transactions/get_records; running them inside a delegated_transaction causes the
            // outer transaction to be marked as broken on any exception, rolling back all plugin DB writes.
            $classRecsForMoodle = [];

            foreach ($data as $cls) {
                // Skip classes from other periods (external/overlap classes shown on the board for reference only).
                // Their periodid differs from the current publish target — never modify them.
                if (self::is_payload_external($cls)) {
                    continue;
                }

                // Skip classes that are not programmed (unassigned)
                $isProgrammed = self::is_payload_programmed($cls);

                if (!$isProgrammed) {
                    continue;
                }

                $classRec = new stdClass();
                $isUpdate = false;
                $forceclearbbbmoduleids = false;
                if (!empty($cls['id']) && is_numeric($cls['id'])) {
                    $classRec->id = (int)$cls['id'];
                    $isUpdate = true;
                    $existingPeriod = $getClassPeriod((int)$classRec->id);
                    if ($existingPeriod !== null && $existingPeriod !== (int)$periodid) {
                        gmk_log("INFO: Ignoring update for foreign class id={$classRec->id} (period {$existingPeriod}), target period={$periodid}");
                        continue;
                    }
                }

                $courseId = (!empty($cls['courseid']) && is_numeric($cls['courseid'])) ? (int)$cls['courseid'] : 0;
                $lpid = (int)($cls['learningplanid'] ?? 0);
                $coreCourseId = (!empty($cls['corecourseid']) && is_numeric($cls['corecourseid'])) ? (int)$cls['corecourseid'] : 0;

                if ($courseId > 0) {
                    $subjById = $DB->get_record('local_learning_courses', ['id' => $courseId], 'id, courseid, learningplanid', IGNORE_MULTIPLE);
                    if (!$subjById) {
                        $courseId = 0;
                    } else {
                        if ($coreCourseId <= 0) {
                            $coreCourseId = (int)$subjById->courseid;
                        }
                        if ($lpid <= 0 && !empty($subjById->learningplanid)) {
                            $lpid = (int)$subjById->learningplanid;
                        }
                        if ($coreCourseId > 0 && (int)$subjById->courseid !== $coreCourseId) {
                            $searchParams = ['courseid' => $coreCourseId];
                            if ($lpid > 0) {
                                $searchParams['learningplanid'] = $lpid;
                            }
                            $subjByCore = $DB->get_record('local_learning_courses', $searchParams, 'id', IGNORE_MULTIPLE);
                            if (!$subjByCore) {
                                $subjByCore = $DB->get_record('local_learning_courses', ['courseid' => $coreCourseId], 'id', IGNORE_MULTIPLE);
                            }
                            $courseId = $subjByCore ? (int)$subjByCore->id : 0;
                            if ($courseId > 0) {
                                gmk_log("HEALING: Corrected mismatched courseid using corecourseid {$coreCourseId} -> subject {$courseId}");
                            }
                        }
                    }
                }

                // Choose class learningplanid based on the majority of linked students for this subject.
                // This avoids assigning classes to an unrelated plan when the subject exists in multiple plans.
                if ($coreCourseId > 0 && !empty($cls['studentIds']) && is_array($cls['studentIds'])) {
                    $linkedUserIds = [];
                    foreach ($cls['studentIds'] as $token) {
                        $uid = $resolve_payload_userid($token);
                        if ($uid > 0) {
                            $linkedUserIds[$uid] = $uid;
                        }
                    }
                    $linkedUserIds = array_values($linkedUserIds);

                    if (!empty($linkedUserIds)) {
                        list($insql, $inparams) = $DB->get_in_or_equal($linkedUserIds, SQL_PARAMS_NAMED, 'stu');
                        $planrows = $DB->get_records_sql(
                            "SELECT lu.learningplanid, COUNT(DISTINCT lu.userid) AS studentcount
                               FROM {local_learning_users} lu
                               JOIN {local_learning_courses} lpc
                                 ON lpc.learningplanid = lu.learningplanid
                                AND lpc.courseid = :corecourseid
                              WHERE lu.userid {$insql}
                                AND (lu.userroleid = :studentrole OR lu.userrolename = :studentrolename)
                                AND lu.status = :activestatus
                           GROUP BY lu.learningplanid
                           ORDER BY studentcount DESC, lu.learningplanid ASC",
                            ['corecourseid' => $coreCourseId, 'studentrole' => 5, 'studentrolename' => 'student', 'activestatus' => 'activo'] + $inparams
                        );

                        if (!empty($planrows)) {
                            $top = reset($planrows);
                            $majorityPlanId = (int)$top->learningplanid;
                            if ($majorityPlanId > 0 && $majorityPlanId !== $lpid) {
                                $oldPlanName = $lp_names[$lpid] ?? ('Plan ' . $lpid);
                                $newPlanName = $lp_names[$majorityPlanId] ?? ('Plan ' . $majorityPlanId);
                                gmk_log("HEALING: Reassigned class plan by student majority for corecourse {$coreCourseId}: {$oldPlanName} ({$lpid}) -> {$newPlanName} ({$majorityPlanId})");
                                $lpid = $majorityPlanId;
                            }
                        }
                    }
                }

                // Normalize subject link to the selected plan when subject exists in multiple plans.
                if ($coreCourseId > 0 && $lpid > 0) {
                    $preferredSubj = $DB->get_record(
                        'local_learning_courses',
                        ['courseid' => $coreCourseId, 'learningplanid' => $lpid],
                        'id',
                        IGNORE_MULTIPLE
                    );
                    if ($preferredSubj && (int)$preferredSubj->id !== (int)$courseId) {
                        gmk_log("HEALING: Adjusted subject link by plan/core match for corecourse {$coreCourseId}, plan {$lpid}: {$courseId} -> {$preferredSubj->id}");
                        $courseId = (int)$preferredSubj->id;
                    }
                }

                if (empty($courseId) || $courseId == "0") {
                    if (!empty($cls['subjectName'])) {
                        $subjByRef = $DB->get_record_sql("SELECT lc.id, lc.courseid FROM {local_learning_courses} lc
                                                         JOIN {course} c ON c.id = lc.courseid
                                                         WHERE (c.fullname = ? OR c.shortname = ?)
                                                           " . ($lpid ? "AND lc.learningplanid = ?" : "") . "
                                                         ORDER BY lc.id DESC",
                                                         $lpid ? [$cls['subjectName'], $cls['subjectName'], $lpid] : [$cls['subjectName'], $cls['subjectName']],
                                                         IGNORE_MULTIPLE);
                        // Fallback: search by name without learningplanid filter
                        if (!$subjByRef && $lpid) {
                            $subjByRef = $DB->get_record_sql("SELECT lc.id, lc.courseid FROM {local_learning_courses} lc
                                                             JOIN {course} c ON c.id = lc.courseid
                                                             WHERE (c.fullname = ? OR c.shortname = ?)
                                                             ORDER BY lc.id DESC",
                                                             [$cls['subjectName'], $cls['subjectName']],
                                                             IGNORE_MULTIPLE);
                        }
                        if ($subjByRef) {
                            $courseId = $subjByRef->id;
                            if (empty($cls['corecourseid'])) {
                                $cls['corecourseid'] = $subjByRef->courseid;
                            }
                        }
                    }
                    if ((empty($courseId) || $courseId == "0") && $coreCourseId > 0) {
                        // Try with learningplanid first
                        $searchParams = ['courseid' => $coreCourseId];
                        if ($lpid) {
                            $searchParams['learningplanid'] = $lpid;
                        }
                        $subjByCore = $DB->get_record('local_learning_courses', $searchParams, 'id', IGNORE_MULTIPLE);
                        // Fallback: ignore learningplanid (it may be confused with periodid)
                        if (!$subjByCore) {
                            $subjByCore = $DB->get_record('local_learning_courses', ['courseid' => $coreCourseId], 'id', IGNORE_MULTIPLE);
                        }
                        if ($subjByCore) $courseId = $subjByCore->id;
                    }
                    if (!empty($courseId) && $courseId != "0") {
                        gmk_log("HEALING: Resolved courseid " . $courseId . " for class: " . ($cls['subjectName'] ?? 'unnamed'));
                    }
                }

                if ($coreCourseId <= 0 && !empty($courseId)) {
                    $tmpCore = $DB->get_field('local_learning_courses', 'courseid', ['id' => $courseId], IGNORE_MISSING);
                    if ($tmpCore) {
                        $coreCourseId = (int)$tmpCore;
                    }
                }

                if ($coreCourseId > 0 && $lpid > 0) {
                    $preferredSubj = $DB->get_record(
                        'local_learning_courses',
                        ['courseid' => $coreCourseId, 'learningplanid' => $lpid],
                        'id',
                        IGNORE_MULTIPLE
                    );
                    if ($preferredSubj && (int)$preferredSubj->id !== (int)$courseId) {
                        gmk_log("HEALING: Final subject link normalization by plan/core for corecourse {$coreCourseId}, plan {$lpid}: {$courseId} -> {$preferredSubj->id}");
                        $courseId = (int)$preferredSubj->id;
                    }
                }
                
                $classRec->periodid = $periodid;
                $classRec->courseid = $courseId;
                $classRec->learningplanid = $lpid;
                $classRec->name = $cls['subjectName'] ?? 'Clase Auto';

                // Lookup corecourseid and other metadata using courseid (Subject ID)
                if (!array_key_exists($courseId, $courses_cache)) {
                    $subj = $DB->get_record('local_learning_courses', ['id' => $courseId], 'id, courseid, learningplanid, periodid');
                    $courses_cache[$courseId] = $subj;
                }
                $subjMeta = $courses_cache[$courseId];
                
                // DEBUG: Trace ID resolution for this class with names
                $inputPlanName = $lp_names[$cls['learningplanid'] ?? 0] ?? 'N/A';
                $inputLvlName = $lvl_names[$cls['periodid'] ?? 0] ?? 'N/A';
                $resolvedLvlName = $subjMeta ? ($lvl_names[$subjMeta->periodid ?? 0] ?? 'N/A') : 'N/A';
                $resolvedPlanName = $subjMeta ? ($lp_names[$subjMeta->learningplanid ?? 0] ?? 'N/A') : 'N/A';
                $moodleCourseName = $subjMeta ? ($course_fullnames[$subjMeta->courseid ?? 0] ?? 'N/A') : 'N/A';

                gmk_log("DEBUG: PROCESANDO CLASE " . ($cls['id'] ?? 'NUEVA'));
                gmk_log("  -> Materia: '" . ($cls['subjectName'] ?? 'Sin Nombre') . "'");
                gmk_log("  -> Moodle Course: [$moodleCourseName] (ID: " . ($subjMeta->courseid ?? '0') . ")");
                gmk_log("  -> INPUT (Frontend): Plan=[$inputPlanName], Nivel=[$inputLvlName], SubjectID=" . ($cls['courseid'] ?? '0'));
                gmk_log("  -> RESOLVED (Backend): SubjectID=" . $courseId . ", Plan=[$resolvedPlanName], Nivel=[$resolvedLvlName]");
                gmk_log("  -> INSTITUTIONAL PERIOD (Target): [$periodName] (ID: $periodid)");
                
                if (!$subjMeta) {
                    gmk_log("  -> WARNING: No se encontró registro en local_learning_courses para Subject ID " . $courseId);
                }

                // Ensure we store the Subject ID (local_learning_courses.id) in courseid
                $classRec->courseid = $courseId;
                // Core Moodle Course ID
                $classRec->corecourseid = $subjMeta ? $subjMeta->courseid : ($cls['corecourseid'] ?? 0);
                // Learning Plan: always trust meta over frontend value (frontend may send period id instead of plan id)
                $classRec->learningplanid = $subjMeta ? (int)$subjMeta->learningplanid : (int)$lpid;
                // Subject name: always use the real Moodle course fullname to avoid double-building the nomenclature name
                if ($subjMeta && !empty($subjMeta->courseid)) {
                    $realCourseName = $course_fullnames[$subjMeta->courseid] ?? null;
                    if ($realCourseName) {
                        $classRec->name = $realCourseName;
                    }
                }
                
                // Note: periodid in DB stores the Institutional Period for filtering.
                // The Academic Level (Level ID) is derived by list_classes using courseid.
                $classRec->periodid = (int)$periodid; 
                
                // Lookup instructor ID prioritizing teacherName
                $tname = trim($cls['teacherName'] ?? '');
                if (!empty($tname) && !is_numeric($tname)) {
                    if (!array_key_exists($tname, $teachers_cache)) {
                        $sql = "SELECT id FROM {user} WHERE ".$DB->sql_concat('firstname', "' '", 'lastname')." = :name";
                        $tid = $DB->get_field_sql($sql, ['name' => $tname], IGNORE_MULTIPLE);
                        $teachers_cache[$tname] = $tid ?: 0;
                    }
                    $classRec->instructorid = $teachers_cache[$tname];
                } else {
                    $classRec->instructorid = $cls['instructorid'] ?? 0;
                }
                
                // subGroup from frontend is c.groupid aliased as subGroup in get_generated_schedules —
                // i.e. the real Moodle group ID for existing classes, or 0 for new (draft) classes.
                // Always start with 0; the correct value is assigned after create_class_group (new)
                // or restored from existingDbRec (update) in the block below.
                $classRec->groupid = 0;
                $classRec->subperiodid = $cls['subperiod'] ?? 0;
                $classRec->type = $cls['type'] ?? 0; 
                $classRec->typelabel = $cls['typeLabel'] ?? 'Presencial';
                
                // Metadata Persistence
                $classRec->shift = $cls['shift'] ?? '';
                $classRec->level_label = $cls['levelDisplay'] ?? '';
                $classRec->career_label = $lp_names[(int)$classRec->learningplanid] ?? ($cls['career'] ?? '');

                $classRec->inittime = $cls['start'] ?? '';
                $classRec->endtime = $cls['end'] ?? '';
                $classRec->inithourformatted = $classRec->inittime ? date('h:i A', strtotime($classRec->inittime)) : '';
                $classRec->endhourformatted = $classRec->endtime ? date('h:i A', strtotime($classRec->endtime)) : '';
                
                if ($classRec->inittime && $classRec->endtime) {
                    $sTS = strtotime($classRec->inittime);
                    $eTS = strtotime($classRec->endtime);
                    $classRec->inittimets = (date('H', $sTS) * 3600) + (date('i', $sTS) * 60);
                    $classRec->endtimets = (date('H', $eTS) * 3600) + (date('i', $eTS) * 60);
                    $classRec->classduration = $classRec->endtimets - $classRec->inittimets;
                } else {
                    $classRec->inittimets = 0;
                    $classRec->endtimets = 0;
                    $classRec->classduration = 0;
                }

                // Date logic based on Subperiod (Block)
                $classRec->initdate = $periodStart;
                $classRec->enddate = $periodEnd;
                
                $calendar = $periodCalendar;
                if ($calendar) {
                    if ($classRec->subperiodid == 1 && !empty($calendar->block1start)) {
                        $classRec->initdate = $calendar->block1start;
                        $classRec->enddate = $calendar->block1end;
                    } else if ($classRec->subperiodid == 2 && !empty($calendar->block2start)) {
                        $classRec->initdate = $calendar->block2start;
                        $classRec->enddate = $calendar->block2end;
                    }
                }

                // Derive classdays bitmask from sessions or day name if not explicitly set
                $rawClassdays = $cls['classdays'] ?? '0/0/0/0/0/0/0';
                $isAllZero = ($rawClassdays === '0/0/0/0/0/0/0' || empty(trim($rawClassdays)));
                
                if ($isAllZero) {
                    // Derive from sessions or day
                    $dayNameMap = [
                        'lunes' => 0,
                        'monday' => 0,
                        'martes' => 1,
                        'tuesday' => 1,
                        'miercoles' => 2,
                        'wednesday' => 2,
                        'jueves' => 3,
                        'thursday' => 3,
                        'viernes' => 4,
                        'friday' => 4,
                        'sabado' => 5,
                        'saturday' => 5,
                        'domingo' => 6,
                        'sunday' => 6
                    ];
                    $mask = [0, 0, 0, 0, 0, 0, 0];
                    
                    if (isset($cls['sessions']) && is_array($cls['sessions'])) {
                        foreach ($cls['sessions'] as $sess) {
                            if (!self::has_payload_valid_session($sess)) {
                                continue;
                            }
                            $sdaykey = cleanString((string)($sess['day'] ?? ''));
                            if (isset($dayNameMap[$sdaykey])) {
                                $mask[$dayNameMap[$sdaykey]] = 1;
                            }
                        }
                    } else if (self::is_payload_valid_day($cls['day'] ?? '')) {
                        $sdaykey = cleanString((string)$cls['day']);
                        if (isset($dayNameMap[$sdaykey])) {
                            $mask[$dayNameMap[$sdaykey]] = 1;
                        }
                    }
                    
                    $derivedClassdays = implode('/', $mask);
                    if ($derivedClassdays !== '0/0/0/0/0/0/0') {
                        $rawClassdays = $derivedClassdays;
                    }
                }
                
                $classRec->classdays = $rawClassdays;
                $classRec->active = 1;
                $classRec->timemodified = time();
                $classRec->usermodified = $GLOBALS['USER']->id;

                // For updates: read current DB record to preserve approved state and existing Moodle structures.
                // For inserts: default approved=0 (activities will be created now; approval happens separately).
                $existingDbRec = null;
                if ($isUpdate) {
                    $existingDbRec = $DB->get_record('gmk_class', ['id' => $classRec->id]);
                    // If the record no longer exists in DB (e.g. it was deleted by a previous buggy publish),
                    // fall back to INSERT so the class is fully recreated instead of silently lost.
                    if (!$existingDbRec) {
                        gmk_log("WARNING: clase id={$classRec->id} no existe en gmk_class (fue eliminada). Insertando como nueva.");
                        unset($classRec->id);
                        $isUpdate = false;
                    }
                }
                if ($isUpdate) {
                    // Preserve approved=1 only when the class already has activities (complete flow).
                    // Classes published with old code (no attendancemoduleid) get reset to 0
                    // so they go through the proper approval flow with student enrollment.
                    $attReason = '';
                    $hasActivities = $existingDbRec && gmk_is_valid_class_attendance_module($existingDbRec, $attReason);
                    $classRec->approved = $hasActivities ? (int)$existingDbRec->approved : 0;
                    // Preserve existing Moodle structure IDs so create_class_activities can find them
                    if ($existingDbRec) {
                        if (empty($classRec->attendancemoduleid)) $classRec->attendancemoduleid = $existingDbRec->attendancemoduleid;
                        if (empty($classRec->coursesectionid))    $classRec->coursesectionid    = $existingDbRec->coursesectionid;
                        if (empty($classRec->groupid))            $classRec->groupid             = $existingDbRec->groupid;
                        if (empty($classRec->gradecategoryid))    $classRec->gradecategoryid     = $existingDbRec->gradecategoryid;
                        if (empty($classRec->bbbmoduleids) && !empty($existingDbRec->bbbmoduleids)) {
                            $filtered = self::filter_preserved_bbbmoduleids(
                                (string)$existingDbRec->bbbmoduleids,
                                (int)$classRec->id,
                                (int)$classRec->corecourseid
                            );
                            if (!empty($filtered)) {
                                $classRec->bbbmoduleids = $filtered;
                            } else {
                                // Existing mapping is stale/crossed, clear it on update.
                                $classRec->bbbmoduleids = null;
                                $forceclearbbbmoduleids = true;
                            }
                        }
                    }
                } else {
                    $classRec->approved = 0;
                }

                // Resolve classroomid from payload so build_class_group_name has it for both INSERT and UPDATE
                $classRec->classroomid = null;
                $roomRef = $cls['room'] ?? '';
                if (!empty($roomRef) && $roomRef !== 'Sin aula') {
                    if (is_numeric($roomRef)) {
                        $classRec->classroomid = (int)$roomRef;
                    } else {
                        $rname = trim($roomRef);
                        if (!array_key_exists($rname, $classrooms_cache)) {
                            $rid = $DB->get_field('gmk_classrooms', 'id', ['name' => $rname], IGNORE_MULTIPLE);
                            $classrooms_cache[$rname] = $rid ?: null;
                        }
                        $classRec->classroomid = $classrooms_cache[$rname];
                    }
                }
                if (empty($classRec->classroomid) && !empty($cls['sessions']) && is_array($cls['sessions'])) {
                    $firstSess = $cls['sessions'][0] ?? [];
                    $sessRoom  = $firstSess['classroomid'] ?? null;
                    if (!empty($sessRoom)) {
                        if (is_numeric($sessRoom)) {
                            $classRec->classroomid = (int)$sessRoom;
                        } else {
                            $rname = trim($sessRoom);
                            if (!array_key_exists($rname, $classrooms_cache)) {
                                $rid = $DB->get_field('gmk_classrooms', 'id', ['name' => $rname], IGNORE_MULTIPLE);
                                $classrooms_cache[$rname] = $rid ?: null;
                            }
                            $classRec->classroomid = $classrooms_cache[$rname];
                        }
                    }
                }

                // Build full class name with nomenclature: PERIOD (SHIFT) SUBJECT (TYPE) ROOM
                $classRec->name = build_class_group_name($classRec);

                if ($isUpdate) {
                    // Re-read right before update to avoid clobbering structure IDs with stale payload values.
                    $latestDbRec = $DB->get_record('gmk_class', ['id' => $classRec->id]);
                    if ($latestDbRec) {
                        if (empty($classRec->attendancemoduleid) && !empty($latestDbRec->attendancemoduleid)) {
                            $classRec->attendancemoduleid = $latestDbRec->attendancemoduleid;
                        }
                        if (empty($classRec->coursesectionid) && !empty($latestDbRec->coursesectionid)) {
                            $classRec->coursesectionid = $latestDbRec->coursesectionid;
                        }
                        if (empty($classRec->groupid) && !empty($latestDbRec->groupid)) {
                            $classRec->groupid = $latestDbRec->groupid;
                        }
                        if (empty($classRec->gradecategoryid) && !empty($latestDbRec->gradecategoryid)) {
                            $classRec->gradecategoryid = $latestDbRec->gradecategoryid;
                        }
                        if (empty($classRec->bbbmoduleids) && !empty($latestDbRec->bbbmoduleids)) {
                            $filtered = self::filter_preserved_bbbmoduleids(
                                (string)$latestDbRec->bbbmoduleids,
                                (int)$classRec->id,
                                (int)$classRec->corecourseid
                            );
                            if (!empty($filtered)) {
                                $classRec->bbbmoduleids = $filtered;
                            } else {
                                $classRec->bbbmoduleids = null;
                                $forceclearbbbmoduleids = true;
                            }
                        }
                    }

                    // Final guard: never overwrite with empties on update.
                    if (empty($classRec->attendancemoduleid)) unset($classRec->attendancemoduleid);
                    if (empty($classRec->coursesectionid)) unset($classRec->coursesectionid);
                    if (empty($classRec->groupid)) unset($classRec->groupid);
                    if (empty($classRec->gradecategoryid)) unset($classRec->gradecategoryid);
                    if (empty($classRec->bbbmoduleids) && !$forceclearbbbmoduleids) unset($classRec->bbbmoduleids);

                    $classid = $classRec->id;
                    $DB->update_record('gmk_class', $classRec);
                    gmk_log("INFO: UPDATE clase $classid — corecourseid={$classRec->corecourseid} groupid={$classRec->groupid} coursesectionid={$classRec->coursesectionid} attendancemoduleid={$classRec->attendancemoduleid}");
                } else {
                    $classRec->timecreated = time();
                    $classid = $DB->insert_record('gmk_class', $classRec);
                    $classRec->id = $classid;
                    gmk_log("INFO: INSERT clase $classid — corecourseid={$classRec->corecourseid} name={$classRec->name}");
                }

                // Store classRec for the post-transaction Moodle activity creation phase.
                $classRecsForMoodle[$classid] = clone $classRec;

                // Save Students to Queue
                // studentIds contains idnumbers (document numbers), resolve to real user ids.
                // Clear both queue and pre_registration so re-publishing never leaves duplicates.
                $DB->delete_records('gmk_class_queue',            ['classid' => $classid]);
                $DB->delete_records('gmk_class_pre_registration', ['classid' => $classid]);
                if (!empty($cls['studentIds']) && is_array($cls['studentIds'])) {
                    foreach ($cls['studentIds'] as $uid) {
                        // Resolve idnumber → real user id if needed
                        if (!is_numeric($uid) || (int)$uid <= 0) {
                            $realId = $DB->get_field('user', 'id', ['idnumber' => $uid, 'deleted' => 0]);
                        } else {
                            // Numeric: could be idnumber or userid; verify it's a valid userid
                            $realId = $DB->record_exists('user', ['id' => (int)$uid, 'deleted' => 0])
                                ? (int)$uid
                                : $DB->get_field('user', 'id', ['idnumber' => (string)$uid, 'deleted' => 0]);
                        }
                        if (!$realId) continue; // Skip if user not found
                        $q = new stdClass();
                        $q->classid = $classid;
                        $q->userid = (int)$realId;
                        $q->courseid = $cls['courseid'];
                        $q->timecreated = time();
                        $q->timemodified = time();
                        $q->usermodified = $GLOBALS['USER']->id;
                        $DB->insert_record('gmk_class_queue', $q);
                    }
                }
                
                // Save Schedule Details (Sessions/Stripes)
                $DB->delete_records('gmk_class_schedules', ['classid' => $classid]);
                $sessionsToSave = [];
                
                // assignedDates lives on the root schedule object (not per-session),
                // so we capture it once and propagate it to every session record.
                $rootAssignedDates = null;
                if (!empty($cls['assignedDates']) && is_array($cls['assignedDates'])) {
                    $rootAssignedDates = json_encode(array_values($cls['assignedDates']));
                } else if (!empty($cls['assignedDates']) && is_string($cls['assignedDates'])) {
                    $rootAssignedDates = $cls['assignedDates'];
                }

                if (isset($cls['sessions']) && is_array($cls['sessions']) && count($cls['sessions']) > 0) {
                    foreach ($cls['sessions'] as $sess) {
                        if (!self::has_payload_valid_session($sess)) {
                            continue;
                        }
                        $sessionsToSave[] = $sess;
                    }
                } else if (self::is_payload_valid_day($cls['day'] ?? '')
                    && self::is_payload_valid_time($cls['start'] ?? '')
                    && self::is_payload_valid_time($cls['end'] ?? '')) {
                    $sessionsToSave[] = [
                        'day' => $cls['day'],
                        'start' => $cls['start'],
                        'end' => $cls['end'],
                        'classroomid' => (!empty($cls['room']) && $cls['room'] !== 'Sin aula') ? $cls['room'] : null,
                        'excluded_dates' => $cls['excluded_dates'] ?? null
                    ];
                }

                foreach ($sessionsToSave as $sess) {
                    $sLink = new stdClass();
                    $sLink->classid = $classid;
                    $sLink->day = $sess['day'];
                    $sLink->start_time = $sess['start'];
                    $sLink->end_time = $sess['end'];
                    $sLink->classroomid = null;

                    $cid = $sess['classroomid'] ?? null;
                    if (!empty($cid)) {
                        if (is_numeric($cid)) {
                            $sLink->classroomid = $cid;
                        } else {
                            $rname = trim($cid);
                            if (!array_key_exists($rname, $classrooms_cache)) {
                                $rid = $DB->get_field('gmk_classrooms', 'id', ['name' => $rname], IGNORE_MULTIPLE);
                                $classrooms_cache[$rname] = $rid ?: null;
                            }
                            $sLink->classroomid = $classrooms_cache[$rname];
                        }
                    }
                    $sLink->excluded_dates = !empty($sess['excluded_dates']) ? (is_array($sess['excluded_dates']) ? json_encode($sess['excluded_dates']) : $sess['excluded_dates']) : null;
                    // Per-session assignedDates override root; fall back to root schedule's assignedDates
                    if (!empty($sess['assignedDates'])) {
                        $sLink->assigned_dates = is_array($sess['assignedDates']) ? json_encode(array_values($sess['assignedDates'])) : $sess['assignedDates'];
                    } else {
                        $sLink->assigned_dates = $rootAssignedDates;
                    }
                    $sLink->usermodified = $GLOBALS['USER']->id;
                    $sLink->timecreated = time();
                    $sLink->timemodified = time();

                    $DB->insert_record('gmk_class_schedules', $sLink);
                }
            }
            gmk_log("Guardado exitoso para Periodo $periodid");
            $transaction->allow_commit();

            // Clear draft only in full publish mode.
            // Single-class republish keeps draft and frontend re-saves explicitly.
            if (!$preserveexisting) {
                $DB->set_field('gmk_academic_periods', 'draft_schedules', null, ['id' => $periodid]);
                gmk_log("Draft limpiado para Periodo $periodid");
            } else {
                gmk_log("INFO: preserveexisting=true, draft preservado para periodo {$periodid}");
            }

        } catch (\Exception $e) {
            $transaction->rollback($e);
            gmk_log("ERROR en save_generation_result: " . $e->getMessage());
            return $e->getMessage();
        }

        // PHASE 2: Create Moodle structures (groups, sections, activities) OUTSIDE the transaction.
        // When $phase1only=true the caller (ajax.php) drives phase 2 class-by-class instead.
        if ($phase1only) {
            gmk_log("FASE 1 completada para Periodo $periodid (phase1only=true, FASE 2 delegada al cliente)");
            return true;
        }
        // These Moodle core functions open their own internal transactions/queries and must not run
        // inside a delegated_transaction — any exception there would roll back all plugin DB writes.
        // Phase 2 errors are non-fatal: plugin data is already saved; Moodle structures are best-effort.
        try {
            gmk_log("FASE 2: Creando estructuras Moodle para " . count($classRecsForMoodle) . " clases");
            \core_php_time_limit::raise(600);
            raise_memory_limit(MEMORY_HUGE);

            foreach ($classRecsForMoodle as $classid => $classRec) {
                // Re-read from DB to get the latest state (in case a previous iteration updated it).
                $freshRec = $DB->get_record('gmk_class', ['id' => $classid]);
                if (!$freshRec) continue;
                foreach ((array)$freshRec as $k => $v) { $classRec->$k = $v; }

                if (empty($classRec->corecourseid)) {
                    gmk_log("WARNING FASE2: clase $classid sin corecourseid — saltando");
                    continue;
                }

                // Ensure group exists and belongs to the class course.
                $groupReason = '';
                if (!gmk_is_valid_class_group($classRec, $groupReason)) {
                    try {
                        $groupId = create_class_group($classRec);
                        $DB->set_field('gmk_class', 'groupid', $groupId, ['id' => $classid]);
                        $classRec->groupid = $groupId;
                        gmk_log("INFO FASE2: Grupo creado/reparado para clase $classid: groupid=$groupId");
                    } catch (Throwable $ge) {
                        gmk_log("WARNING FASE2: No se pudo crear grupo para clase $classid: " . $ge->getMessage());
                        continue;
                    }
                }

                // Ensure section exists and belongs to the class course.
                $sectionReason = '';
                if (!gmk_is_valid_class_section($classRec, $sectionReason)) {
                    try {
                        $sectionId = create_class_section($classRec);
                        $DB->set_field('gmk_class', 'coursesectionid', $sectionId, ['id' => $classid]);
                        $classRec->coursesectionid = $sectionId;
                        gmk_log("INFO FASE2: Sección creada para clase $classid: sectionid=$sectionId");
                    } catch (Throwable $se) {
                        gmk_log("WARNING FASE2: No se pudo crear sección para clase $classid: " . $se->getMessage());
                        continue;
                    }
                }

                // Create or recreate activities (attendance + BBB sessions).
                $attReason = '';
                $hasActivities = gmk_is_valid_class_attendance_module($classRec, $attReason);
                try {
                    create_class_activities($classRec, $hasActivities);
                    $commitok = gmk_best_effort_db_commit("scheduler_phase2_class_{$classid}");
                    if (!$commitok) {
                        gmk_log("WARNING FASE2: COMMIT best-effort fallo para clase $classid");
                    }
                    $attcmid = (int)($classRec->attendancemoduleid ?? 0);
                    $extcheck = gmk_secondary_db_activity_check(
                        (int)$classid,
                        (int)$classRec->corecourseid,
                        (int)$classRec->coursesectionid,
                        $attcmid
                    );
                    if (!empty($extcheck['enabled']) && empty($extcheck['ok'])) {
                        gmk_log("WARNING FASE2: EXTCHECK mismatch clase $classid"
                            . " class_attendancemoduleid={$extcheck['class_attendancemoduleid']}"
                            . " cm_exists={$extcheck['cm_exists']}"
                            . " section_modules={$extcheck['section_modules_att_bbb']}");
                        usleep(700000);
                        gmk_best_effort_db_commit("scheduler_phase2_retry_class_{$classid}");
                        $extcheck2 = gmk_secondary_db_activity_check(
                            (int)$classid,
                            (int)$classRec->corecourseid,
                            (int)$classRec->coursesectionid,
                            $attcmid
                        );
                        if (!empty($extcheck2['enabled']) && empty($extcheck2['ok'])) {
                            throw new \Exception("Persistencia cruzada fallida para clase {$classid}");
                        }
                    }
                    gmk_log("INFO FASE2: Actividades " . ($hasActivities ? "recreadas" : "creadas") . " para clase $classid");
                } catch (Throwable $ae) {
                    gmk_log("WARNING FASE2: No se pudieron crear actividades para clase $classid: " . $ae->getMessage());
                }
            }

            gmk_log("FASE 2 completa para Periodo $periodid");
        } catch (Throwable $phase2err) {
            // Phase 2 failure is non-fatal — plugin DB data was already committed.
            gmk_log("WARNING FASE2 error global para Periodo $periodid: " . $phase2err->getMessage());
        }

        return true;
    }
    
    public static function save_generation_result_returns() {
        return new external_value(PARAM_BOOL, '');
    }

    // --- Helpers ---
    private static function payload_day_token($day): string {
        $token = \core_text::strtolower(trim((string)$day));
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $token);
        if ($ascii !== false && is_string($ascii)) {
            $token = $ascii;
        }
        $token = preg_replace('/\s+/', '', $token);
        if ($token === null) {
            return '';
        }
        return $token;
    }

    private static function is_payload_valid_day($day): bool {
        $token = self::payload_day_token($day);
        return !in_array($token, ['', 'n/a', 'na', 'n-a', 'sinasignar', 'sinasignar.'], true);
    }

    private static function is_payload_valid_time($time): bool {
        $token = trim((string)$time);
        if ($token === '' || $token === '00:00' || $token === '00:00:00') {
            return false;
        }
        return true;
    }

    private static function has_payload_valid_session($session): bool {
        if (!is_array($session)) {
            return false;
        }
        return self::is_payload_valid_day($session['day'] ?? '')
            && self::is_payload_valid_time($session['start'] ?? '')
            && self::is_payload_valid_time($session['end'] ?? '');
    }

    private static function is_payload_programmed(array $cls): bool {
        if (!empty($cls['sessions']) && is_array($cls['sessions'])) {
            foreach ($cls['sessions'] as $sess) {
                if (self::has_payload_valid_session($sess)) {
                    return true;
                }
            }
        }

        return self::is_payload_valid_day($cls['day'] ?? '')
            && self::is_payload_valid_time($cls['start'] ?? '')
            && self::is_payload_valid_time($cls['end'] ?? '');
    }

    private static function payload_schedule_key(array $cls): string {
        $core = (string)($cls['corecourseid'] ?? '');
        if ($core === '' || $core === '0') {
            $core = 'subject:' . (string)($cls['courseid'] ?? '');
        }

        $shift = trim((string)($cls['shift'] ?? ''));
        $learningplan = (string)($cls['learningplanid'] ?? '');
        $career = trim((string)($cls['career'] ?? ''));
        $subperiod = (string)($cls['subperiod'] ?? 0);
        $type = (string)($cls['type'] ?? 0);
        $instructor = (string)($cls['instructorid'] ?? ($cls['instructorId'] ?? ''));

        $timingParts = [];
        if (!empty($cls['sessions']) && is_array($cls['sessions'])) {
            foreach ($cls['sessions'] as $sess) {
                $day = strtolower(trim((string)($sess['day'] ?? '')));
                $start = trim((string)($sess['start'] ?? ''));
                $end = trim((string)($sess['end'] ?? ''));
                $room = '';
                if (array_key_exists('classroomid', $sess)) {
                    $room = strtolower(trim((string)$sess['classroomid']));
                } else if (array_key_exists('room', $sess)) {
                    $room = strtolower(trim((string)$sess['room']));
                }
                $timingParts[] = $day . '|' . $start . '|' . $end . '|' . $room;
            }
            sort($timingParts, SORT_STRING);
        } else {
            $day = strtolower(trim((string)($cls['day'] ?? '')));
            $start = trim((string)($cls['start'] ?? ''));
            $end = trim((string)($cls['end'] ?? ''));
            $room = strtolower(trim((string)($cls['room'] ?? '')));
            $timingParts[] = $day . '|' . $start . '|' . $end . '|' . $room;
        }

        return implode('||', [$core, $shift, $learningplan, $career, $subperiod, $type, $instructor, implode(';', $timingParts)]);
    }

    private static function dedupe_payload_schedules(array $data): array {
        $out = [];
        $seen = [];

        foreach ($data as $cls) {
            if (!is_array($cls)) {
                continue;
            }

            if (self::is_payload_external($cls)) {
                $out[] = $cls;
                continue;
            }

            $isProgrammed = self::is_payload_programmed($cls);
            if (!$isProgrammed) {
                $out[] = $cls;
                continue;
            }

            $key = self::payload_schedule_key($cls);
            $keyhash = substr(sha1($key), 0, 12);
            $hasNumericId = !empty($cls['id']) && is_numeric($cls['id']) && (int)$cls['id'] > 0;

            if (!isset($seen[$key])) {
                $seen[$key] = ['idx' => count($out), 'hasNumericId' => $hasNumericId];
                $out[] = $cls;
                continue;
            }

            $prev = $seen[$key];
            if ($hasNumericId && !$prev['hasNumericId']) {
                $out[$prev['idx']] = $cls;
                $seen[$key]['hasNumericId'] = true;
                gmk_log("INFO: Dedup payload replaced temp item with numeric item (key={$keyhash})");
            } else {
                gmk_log("INFO: Dedup payload skipped duplicate item (key={$keyhash})");
            }
        }

        return $out;
    }

    private static function is_payload_external(array $cls): bool {
        if (!array_key_exists('isExternal', $cls)) {
            return false;
        }

        $flag = $cls['isExternal'];
        if (is_bool($flag)) {
            return $flag;
        }
        if (is_int($flag) || is_float($flag)) {
            return ((int)$flag) === 1;
        }
        if (is_string($flag)) {
            $norm = strtolower(trim($flag));
            return in_array($norm, ['1', 'true', 'yes', 'y', 'si', 'on'], true);
        }
        return false;
    }

    private static function parse_semester_number($name) {
        if (preg_match('/(I|II|III|IV|V|VI|VII|VIII|IX|X|XI|XII)$/i', $name, $matches)) {
            $romans = [
                'I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 
                'VI' => 6, 'VII' => 7, 'VIII' => 8, 'IX' => 9, 'X' => 10, 
                'XI' => 11, 'XII' => 12
            ];
            return $romans[strtoupper($matches[1])] ?? 0;
        }
        if (preg_match('/(\d+)/', $name, $matches)) {
            return (int)$matches[1];
        }
        return 0;
    }

    /**
     * Extract class id token from BBB name pattern "<class name>-<classid>-<timestamp>".
     */
    private static function extract_classid_from_bbb_name(string $bbbname): int {
        $bbbname = trim($bbbname);
        if ($bbbname === '') {
            return 0;
        }
        if (preg_match('/-(\d+)-(\d{6,})$/', $bbbname, $m)) {
            return (int)$m[1];
        }
        return 0;
    }

    /**
     * Keep only safe BBB cmids for this class/course and discard stale/cross refs.
     * Returns comma-separated list or null when none are valid.
     */
    private static function filter_preserved_bbbmoduleids(string $rawcmids, int $classid, int $corecourseid): ?string {
        global $DB;

        $ids = [];
        foreach (explode(',', $rawcmids) as $part) {
            $cmid = (int)trim((string)$part);
            if ($cmid > 0) {
                $ids[$cmid] = $cmid;
            }
        }
        $ids = array_values($ids);
        if (empty($ids)) {
            return null;
        }

        list($insql, $params) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'bcm');
        $rows = $DB->get_records_sql(
            "SELECT cm.id, cm.course, m.name AS modulename, b.name AS bbbname
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
          LEFT JOIN {bigbluebuttonbn} b ON b.id = cm.instance
              WHERE cm.id {$insql}",
            $params
        );

        $valid = [];
        foreach ($ids as $cmid) {
            $row = $rows[(int)$cmid] ?? null;
            if (!$row || (string)$row->modulename !== 'bigbluebuttonbn') {
                continue;
            }
            if ($corecourseid > 0 && (int)$row->course !== $corecourseid) {
                continue;
            }
            $tokenclassid = self::extract_classid_from_bbb_name((string)($row->bbbname ?? ''));
            if ($tokenclassid > 0 && $classid > 0 && $tokenclassid !== $classid) {
                continue;
            }
            $valid[(int)$cmid] = (int)$cmid;
        }

        if (empty($valid)) {
            return null;
        }

        $valid = array_values($valid);
        sort($valid);
        return implode(',', $valid);
    }

    // --- 6. Fetch Generated Schedules ---
    public static function get_generated_schedules_parameters() {
        return new external_function_parameters([
            'periodid' => new external_value(PARAM_INT, 'Academic Period ID')
        ]);
    }

    public static function get_generated_schedules($periodid, $includeoverlaps = false) {
        global $DB;
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        if (function_exists('gmk_log')) {
            gmk_log("DEBUG: get_generated_schedules(periodid=$periodid, includeoverlaps=$includeoverlaps)");
        }

        $sql = "SELECT c.id, c.courseid, c.name as subjectname, c.instructorid, u.firstname, u.lastname,
                       lp.name as career, c.type, c.typelabel, c.subperiodid as subperiod, c.groupid as subGroup, c.learningplanid,
                       c.shift, c.level_label, c.career_label, c.periodid as institutional_period_id, c.corecourseid,
                       c.initdate, c.enddate, c.inittime, c.endtime, c.classdays
                FROM {gmk_class} c
                LEFT JOIN {user} u ON u.id = c.instructorid
                LEFT JOIN {local_learning_plans} lp ON lp.id = c.learningplanid
                WHERE c.periodid = :periodid";
        
        $params = ['periodid' => $periodid];
        
        if ($includeoverlaps) {
            $period = $DB->get_record('gmk_academic_periods', ['id' => $periodid], 'id, startdate, enddate');
            if ($period) {
                // Fetch classes from OTHER periods OR WITHOUT period that overlap in dates
                // Intersection condition: (s1 <= e2) AND (e1 >= s2)
                // Using COALESCE to handle NULL periodid safely
                $sql .= " OR (COALESCE(c.periodid, 0) != :periodid2 
                              AND c.initdate <= :enddate AND c.enddate >= :startdate)";
                $params['periodid2'] = $periodid;
                $params['startdate'] = $period->startdate;
                $params['enddate'] = $period->enddate;
                
                if (function_exists('gmk_log')) {
                    gmk_log("DEBUG: Overlap SQL active. Period: " . userdate($period->startdate) . " to " . userdate($period->enddate));
                }
            }
        }

        $classes = $DB->get_records_sql($sql, $params);
        if (function_exists('gmk_log')) {
            gmk_log("DEBUG: Found " . count($classes) . " classes total.");
        }
        $result = [];
        
        $classrooms_cache = [];
        $subjects_metadata_cache = [];

        foreach ($classes as $c) {
            $sessions = $DB->get_records('gmk_class_schedules', ['classid' => $c->id]);
            $sessArr = [];
            foreach ($sessions as $s) {
                // Resolve classroom name
                $roomName = 'Sin aula';
                if (!empty($s->classroomid)) {
                    if (!isset($classrooms_cache[$s->classroomid])) {
                        $rinfo = $DB->get_field('gmk_classrooms', 'name', ['id' => $s->classroomid], IGNORE_MISSING);
                        $classrooms_cache[$s->classroomid] = $rinfo ? $rinfo : $s->classroomid;
                    }
                    $roomName = $classrooms_cache[$s->classroomid];
                }
                
                $sessArr[] = [
                    'day' => $s->day,
                    'start' => $s->start_time,
                    'end' => $s->end_time,
                    'classroomid' => $s->classroomid,
                    'roomName' => $roomName,
                    'excluded_dates' => !empty($s->excluded_dates) ? json_decode($s->excluded_dates, true) : [],
                    'assignedDates'  => !empty($s->assigned_dates)  ? json_decode($s->assigned_dates,  true) : null
                ];
            }

        // FALLBACK: If no sessions in gmk_class_schedules, try legacy fields in gmk_class
        if (empty($sessArr) && !empty($c->inittime) && $c->inittime !== '00:00') {
            $dayBitmask = explode('/', $c->classdays);
            $dayNames = ['Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado', 'Domingo'];
            foreach ($dayBitmask as $idx => $val) {
                if ($val == '1' && isset($dayNames[$idx])) {
                    $sessArr[] = [
                        'day' => $dayNames[$idx],
                        'start' => $c->inittime,
                        'end' => $c->endtime,
                        'roomName' => 'Sin aula',
                        'excluded_dates' => []
                    ];
                }
            }
        }
            
            // Derive Academic Level from Subject ID (courseid) if missing
            $academic_period_id = (int)($c->institutional_period_id ?? 0);
            
            // HEALING: If courseid is missing but we have metadata, try to resolve it
            if (empty($c->courseid) || $c->courseid == "0") {
                if (!empty($c->corecourseid)) {
                    $subjByCore = $DB->get_record('local_learning_courses', ['courseid' => $c->corecourseid], 'id, learningplanid, periodid', IGNORE_MULTIPLE);
                    if ($subjByCore) {
                        $c->courseid = $subjByCore->id;
                        if (empty($c->learningplanid)) $c->learningplanid = $subjByCore->learningplanid;
                        $academic_period_id = $subjByCore->periodid;
                    }
                }
                if ((empty($c->courseid) || $c->courseid == "0") && !empty($c->subjectname)) {
                    $subjByName = $DB->get_record_sql("SELECT lc.id, lc.learningplanid, lc.periodid, lc.courseid FROM {local_learning_courses} lc 
                                                       JOIN {course} co ON co.id = lc.courseid 
                                                       WHERE co.fullname = ? OR co.shortname = ? 
                                                       ORDER BY lc.id DESC", [$c->subjectname, $c->subjectname], IGNORE_MULTIPLE);
                    if ($subjByName) {
                        $c->courseid = $subjByName->id;
                        if (empty($c->learningplanid)) $c->learningplanid = $subjByName->learningplanid;
                        $academic_period_id = $subjByName->periodid;
                        if (empty($c->corecourseid)) $c->corecourseid = $subjByName->courseid;
                    }
                }
            }

            // If we have a valid subject ID (courseid column in gmk_class), get its period if not already set
            if (!empty($c->courseid) && $c->courseid != "0") {
                $subjCacheKey = $c->courseid . '_' . ($c->learningplanid ?? 0);
                if (!isset($subjects_metadata_cache[$subjCacheKey])) {
                    $subj = $DB->get_record('local_learning_courses', ['id' => $c->courseid], 'id, learningplanid, periodid, courseid');
                    if (!$subj) {
                        $searchParams = ['courseid' => $c->courseid];
                        if (!empty($c->learningplanid)) {
                            $searchParams['learningplanid'] = $c->learningplanid;
                        }
                        $subj = $DB->get_record('local_learning_courses', $searchParams, 'id, learningplanid, periodid, courseid', IGNORE_MULTIPLE);
                        if (!$subj && !empty($c->corecourseid)) {
                            $subj = $DB->get_record('local_learning_courses', ['courseid' => $c->corecourseid], 'id, learningplanid, periodid, courseid', IGNORE_MULTIPLE);
                        }
                    }
                    $subjects_metadata_cache[$subjCacheKey] = $subj ?: null;
                }

                $meta = $subjects_metadata_cache[$subjCacheKey];
                if ($meta) {
                    // CRITICAL: ONLY overwrite if current value is 0. Respect the saved majority plan!
                    if (empty($c->learningplanid)) $c->learningplanid = $meta->learningplanid;
                    if (empty($academic_period_id)) $academic_period_id = $meta->periodid;
                    
                    // If the stored courseid was a corecourseid (Moodle ID), heal it to Subject ID (Link ID)
                    if ($c->courseid == $meta->courseid && $c->courseid != $meta->id) {
                        $c->courseid = (int)$meta->id;
                    } else {
                        $c->courseid = (int)$c->courseid;
                    }
                }
            }

            $subjectName = $c->subjectname ?? ('Materia ' . $c->courseid);
            // Prefer the institutional period stored on gmk_class (c.periodid) — that is what
            // save_generation_result writes and what the frontend uses to detect external classes.
            // Only fall back to academic_period_id (from local_learning_courses) when not set.
            $institutionalPeriodId = (int)($c->institutional_period_id ?? 0);
            $finalPeriodId = $institutionalPeriodId ?: (int)($academic_period_id ?: 0);

            $instructorId = (int)($c->instructorid ?? 0);
            $normalizeUserIds = static function(array $ids, $instructorId) {
                $out = [];
                foreach ($ids as $raw) {
                    $uid = (int)$raw;
                    if ($uid <= 0) {
                        continue;
                    }
                    if ($instructorId > 0 && $uid === (int)$instructorId) {
                        continue;
                    }
                    $out[$uid] = $uid;
                }
                return array_values($out);
            };

            $groupStudentUserIds = [];
            if (!empty($c->groupid)) {
                $groupSql = "SELECT gm.userid
                               FROM {groups_members} gm
                               JOIN {user} u ON gm.userid = u.id
                              WHERE gm.groupid = ? AND u.deleted = 0";
                $groupParams = [(int)$c->groupid];
                if (!empty($c->instructorid)) {
                    $groupSql .= " AND gm.userid <> ?";
                    $groupParams[] = (int)$c->instructorid;
                }
                $groupStudentUserIds = $DB->get_fieldset_sql($groupSql, $groupParams);
            }

            $preRegUserIds = $DB->get_fieldset_sql(
                "SELECT pr.userid
                   FROM {gmk_class_pre_registration} pr
                  WHERE pr.classid = ?",
                [$c->id]
            );
            $queuedUserIds = $DB->get_fieldset_sql(
                "SELECT q.userid
                   FROM {gmk_class_queue} q
                  WHERE q.classid = ?",
                [$c->id]
            );
            $progreUserIds = $DB->get_fieldset_sql(
                "SELECT p.userid
                   FROM {gmk_course_progre} p
                  WHERE p.classid = ?",
                [$c->id]
            );

            $preRegUserIds = $normalizeUserIds((array)$preRegUserIds, $instructorId);
            $queuedUserIds = $normalizeUserIds((array)$queuedUserIds, $instructorId);
            $groupStudentUserIds = $normalizeUserIds((array)$groupStudentUserIds, $instructorId);
            $progreUserIds = $normalizeUserIds((array)$progreUserIds, $instructorId);

            $enrolledUserIds = array_values(array_unique(array_merge($groupStudentUserIds, $progreUserIds)));
            $pendingCandidateUserIds = array_values(array_diff(
                array_values(array_unique(array_merge($preRegUserIds, $queuedUserIds))),
                $enrolledUserIds
            ));

            $classUserIds = array_values(array_unique(array_merge(
                $preRegUserIds,
                $queuedUserIds,
                $enrolledUserIds
            )));

            $classStudentIds = [];
            $validUserIds = [];
            if (!empty($classUserIds)) {
                $userRows = $DB->get_records_list('user', 'id', $classUserIds, '', 'id, idnumber, deleted');
                foreach ($classUserIds as $uid) {
                    $uid = (int)$uid;
                    if (empty($userRows[$uid]) || !empty($userRows[$uid]->deleted)) {
                        continue;
                    }
                    $validUserIds[$uid] = $uid;
                    $idnumber = trim((string)$userRows[$uid]->idnumber);
                    $classStudentIds[] = ($idnumber !== '') ? $idnumber : (string)$uid;
                }
                $classStudentIds = array_values(array_unique($classStudentIds));
            }

            $countValid = static function(array $ids, array $validSet) {
                $count = 0;
                foreach ($ids as $id) {
                    if (isset($validSet[(int)$id])) {
                        $count++;
                    }
                }

                if ($coreCourseId <= 0 && !empty($courseId)) {
                    $tmpCore = $DB->get_field('local_learning_courses', 'courseid', ['id' => $courseId], IGNORE_MISSING);
                    if ($tmpCore) {
                        $coreCourseId = (int)$tmpCore;
                    }
                }
                return $count;
            };
            $validSet = [];
            foreach ($validUserIds as $uid) {
                $validSet[(int)$uid] = true;
            }
            $preRegisteredCount = $countValid($preRegUserIds, $validSet);
            $queuedCount = $countValid($queuedUserIds, $validSet);
            $enrolledCount = $countValid($enrolledUserIds, $validSet);
            $pendingEnrollmentCount = $countValid($pendingCandidateUserIds, $validSet);

            $result[] = [
                'id' => (int)$c->id,
                'courseid' => (int)$c->courseid,
                'subjectName' => $subjectName,
                'teacherName' => ($c->instructorid && !empty($c->firstname)) ? ($c->firstname . ' ' . $c->lastname) : null,
                'instructorid' => (int)$c->instructorid,
                'day' => empty($sessArr) ? 'N/A' : $sessArr[0]['day'],
                'start' => empty($sessArr) ? '00:00' : $sessArr[0]['start'],
                'end' => empty($sessArr) ? '00:00' : $sessArr[0]['end'],
                'room' => empty($sessArr) ? 'Sin aula' : $sessArr[0]['roomName'],
                'corecourseid' => (int)($c->corecourseid ?? 0),
                'studentIds' => $classStudentIds,
                'studentCount' => count($classStudentIds),
                'preRegisteredCount' => (int)$preRegisteredCount,
                'queuedCount' => (int)$queuedCount,
                'enrolledCount' => (int)$enrolledCount,
                'pendingEnrollmentCount' => (int)$pendingEnrollmentCount,
                'career' => !empty($c->career_label) ? $c->career_label : ($c->career ?? 'General'),
                'shift' => !empty($c->shift) ? $c->shift : 'No Definida', 
                'levelDisplay' => !empty($c->level_label) ? $c->level_label : 'Nivel X', 
                'subGroup' => (int)($c->subgroup ?? 0),
                'subperiod' => (int)($c->subperiod ?? 1),
                'approved' => (int)($c->approved ?? 0),
                'type' => (int)($c->type ?? 0),
                'typeLabel' => $c->typelabel ?? 'Presencial',
                'learningplanid' => (int)($c->learningplanid ?? 0),
                'periodid' => $finalPeriodId,
                'isExternal' => ($finalPeriodId !== (int)$periodid && $finalPeriodId !== 0),
                'initdate' => (int)($c->initdate ?? 0),
                'enddate' => (int)($c->enddate ?? 0),
                'sessions' => $sessArr,
                // assignedDates: use the first session's assigned_dates as the root value
                // (all sessions of the same class share the same set when generated from the planner)
                'assignedDates' => (!empty($sessArr) && !empty($sessArr[0]['assignedDates']))
                    ? $sessArr[0]['assignedDates']
                    : null
            ];

            if ($includeoverlaps && function_exists('gmk_log')) {
                $isExtFlag = ($finalPeriodId !== (int)$periodid && $finalPeriodId !== 0) ? 'YES' : 'NO';
                if ($isExtFlag == 'YES' || $c->id == 9114) {
                    gmk_log("DEBUG isExternal: ID={$c->id}, FinalPeriod={$finalPeriodId}, TargetPeriod={$periodid}, Result={$isExtFlag}, DBPeriod=".($c->institutional_period_id ?? 'NULL'));
                }
            }
        }
        return $result;
    }

    public static function get_generated_schedules_returns() {
        return new external_value(PARAM_RAW, 'JSON representation of schedules array');
    }
}
