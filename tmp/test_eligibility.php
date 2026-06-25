<?php
define('CLI_SCRIPT', true);
require '/var/www/html/moodle/config.php';

// Test 1: backend returns students when onlyeligible=0
echo "=== Test 1: list_graduands with onlyeligible=0 ===\n";
$grads = \local_grupomakro_core\local\diplomas\manager::list_graduands_with_eligibility(null, '', false, 0, 10);
echo "  Returned: " . count($grads) . " students (capped to 10)\n";
foreach ($grads as $g) {
    $elig = $g['eligibility'];
    echo "  - {$g['user']['fullname']} | plan={$g['plan']['name']} | "
        . "passed={$elig['passed_count']}/{$elig['required_count']} "
        . "({$elig['progress_percent']}%) | "
        . ($elig['is_eligible'] ? 'APTO' : 'no-apto')
        . " | has_diploma=" . ($elig['has_diploma'] ? 'yes' : 'no')
        . "\n";
}

echo "\n=== Test 2: with onlyeligible=1 ===\n";
$grads2 = \local_grupomakro_core\local\diplomas\manager::list_graduands_with_eligibility(null, '', true, 0, 10);
echo "  Returned: " . count($grads2) . " students\n";

// Test 3: detail
if (!empty($grads)) {
    $u = $grads[0]['user']['id'];
    $p = $grads[0]['plan']['id'];
    echo "\n=== Test 3: detail for user $u, plan $p ===\n";
    $detail = \local_grupomakro_core\local\diplomas\manager::get_graduand_eligibility_detail($u, $p);
    if ($detail) {
        $elig = $detail['eligibility'];
        echo "  User: {$detail['user']['fullname']}\n";
        echo "  Plan: {$detail['plan']['name']}\n";
        echo "  Required: {$elig['required_count']}\n";
        echo "  Passed: {$elig['passed_count']}\n";
        echo "  Is eligible: " . ($elig['is_eligible'] ? 'yes' : 'no') . "\n";
        echo "  Reason: {$elig['reason']}\n";
        echo "  Passed reqs (" . count($elig['passed_requirements']) . "): " . implode(', ', array_slice($elig['passed_requirements'], 0, 3)) . "\n";
        echo "  Missing reqs (" . count($elig['missing_requirements']) . "): " . implode(', ', array_slice($elig['missing_requirements'], 0, 3)) . "\n";
    } else {
        echo "  detail is null!\n";
    }
}
