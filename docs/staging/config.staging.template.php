<?php
// This file is part of Moodle - http://moodle.org/
//
// Staging config.php template for local_grupomakro_core.
//
// PURPOSE:
//   Reference config.php used when bootstrapping a Moodle instance inside the
//   new staging VPC (10.1.0.0/16). The real config.php is generated from this
//   template at boot time (e.g. by user-data script that substitutes the
//   %%PLACEHOLDER%% tokens using IMDS / SSM Parameter Store / environment
//   variables). DO NOT copy this file verbatim to /var/www/html/config.php
//   without substitution.
//
// USAGE (informative, NOT executed here):
//   aws ssm get-parameters --names "/isi/staging/moodle/db_host" ...
//   envsubst < config.staging.template.php > /var/www/html/config.php
//
// DESIGN RULES:
//   - DB credentials MUST come from SSM Parameter Store at boot, never baked
//     into the AMI.
//   - $CFG->wwwroot MUST match the staging URL the LXP/Express will hit
//     (e.g. https://staging-moodle.isi.example.test).
//   - $CFG->dbname and $CFG->prefix stay aligned with what the DBA runbook
//     restores from prod (sanitised blacklist in STAGING-DB-RUNBOOK.md).
//   - Odoo/LXP/BBB endpoints are environment-specific and consumed by
//     local_grupomakro_core through the Express proxy + Odoo's
//     moodle_user_sync environment override (DES-ODOO-002).
//
// SCOPE OF THIS FILE:
//   This template lives at docs/staging/config.staging.template.php inside
//   the fork. It is NOT auto-loaded by Moodle. The real config.php will be
//   generated on the staging EC2 only.

defined('MOODLE_INTERNAL') || die();

$CFG = new stdClass();

// === Identity ===
$CFG->dbtype    = 'mariadb';
$CFG->dblibrary = 'native';
$CFG->dbhost    = getenv('MOODLE_DB_HOST')     ?: '%%MOODLE_DB_HOST%%';
$CFG->dbname    = getenv('MOODLE_DB_NAME')     ?: '%%MOODLE_DB_NAME%%';
$CFG->dbuser    = getenv('MOODLE_DB_USER')     ?: '%%MOODLE_DB_USER%%';
$CFG->dbpass    = getenv('MOODLE_DB_PASS')     ?: '%%MOODLE_DB_PASS%%';
$CFG->prefix    = 'isi_';
$CFG->dboptions = [
    'dbpersist'  => false,
    'dbport'     => 3306,
    'dbsocket'   => false,
    'dbcollation' => 'utf8mb4_unicode_ci',
];

// === Site ===
$CFG->wwwroot   = getenv('MOODLE_WWWROOT') ?: 'https://staging-moodle.isi.example.test';
$CFG->dataroot  = getenv('MOODLE_DATAROOT') ?: '/var/moodledata/staging';
$CFG->admin     = 'admin';
$CFG->directorypermissions = 02777;
$CFG->umaskpermissions = 0007;

// === Performance / proxy headers ===
$CFG->reverseproxy = true;
$CFG->sslproxy     = true;

// === Environment marker (consumed by local_grupomakro_core features) ===
// Picked up by the plugin via:
//   get_config('local_grupomakro_core', 'environment')
// set in /etc/moodle-environment or via CFN UserData.
$CFG->customstring1 = 'environment=staging';

// === External services consumed by local_grupomakro_core ===
// These keys are the same names the plugin already reads via get_config()
// (see db/install.php and config hooks in lib.php). They are intentionally
// empty here so the values are sourced from SSM at boot.
$CFG->local_grupomakro_core_settings = [
    // Express proxy (LXP → Odoo → Moodle)
    'express_proxy_url'      => getenv('EXPRESS_PROXY_URL') ?: 'http://isi-express-staging.internal:3000',
    'express_proxy_apikey'   => getenv('EXPRESS_PROXY_APIKEY') ?: '',

    // BBB (consumed by mod_bigbluebuttonbn / plugin glue code)
    'bbb_server_url'         => getenv('BBB_SERVER_URL') ?: 'https://staging-bbb.isi.example.test/bigbluebutton/',
    'bbb_shared_secret'      => getenv('BBB_SHARED_SECRET') ?: '',

    // Odoo JSON-RPC endpoint used by moodle_user_sync bridge (DES-ODOO-002)
    'odoo_url'               => getenv('ODOO_URL') ?: 'https://odoo-staging.isi.example.test',
    'odoo_db'                => getenv('ODOO_DB')  ?: 'isi_staging',
];

// === Caches / debug ===
$CFG->cachejs = true;
$CFG->cachetemplates = true;
// Staging runs with debug ON so the smoke test (DES-MOODLE-002) surfaces errors.
$CFG->debug = (getenv('MOODLE_DEBUG') === '1') ? E_ALL | E_STRICT : 0;
$CFG->debugdisplay = (getenv('MOODLE_DEBUG') === '1') ? 1 : 0;

// Force this file to never be served.
$notallowed = true;