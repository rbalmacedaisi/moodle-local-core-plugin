<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');

$userid = 2467;
$service = $DB->get_record('external_services', ['shortname' => 'moodle_mobile_app'], '*', MUST_EXIST);

$now = time();
$token = md5(uniqid(rand(), true));

$rec = new stdClass();
$rec->token      = $token;
$rec->userid     = $userid;
$rec->externalserviceid = $service->id;
$rec->contextid  = 1;
$rec->creatorid   = $userid;
$rec->iprestriction = '';
$rec->validuntil  = $now + (86400 * 7);
$rec->timecreated = $now;
$rec->lastaccess  = null;
$rec->tokentype  = 0;
$rec->sid        = '';

$DB->insert_record('external_tokens', $rec);
echo $token;