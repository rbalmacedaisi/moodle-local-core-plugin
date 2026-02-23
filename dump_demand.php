<?php
require_once(__DIR__ . '/config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/admin/scheduler.php');
use local_grupomakro_core\external\admin\scheduler;

$demand = scheduler::get_demand_data(1);
file_put_contents(__DIR__ . '/demand_debug.json', json_encode($demand['demand_tree'], JSON_PRETTY_PRINT));
echo "Done";
