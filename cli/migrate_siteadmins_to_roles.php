<?php
// This file is part of Moodle - http://moodle.org/
//
// Migración de siteadmins a los roles custom del workflow matrix (PR7).
//
// HISTORIAL: en producción hay 11 usuarios con el rol `manager` a nivel
// sistema (sitio admins). Eso es lo que dio pie al problema inicial:
// cualquiera con `manager` podía tocar todo sin necesidad de un rol
// específico. La matriz de roles (PR1+PR2) define 6 roles custom:
//   - gmk_director_academico   (45 caps)
//   - gmk_secretaria_academica  (33 caps)
//   - gmk_registros_academicos  (18 caps)
//   - gmk_soporte_ti            (6 caps)
//   - gmk_bienestar             (4 caps, coordinador del módulo wellness)
//   - gmk_psicologo             (1 cap, agenda psicológica)
//
// Este script reasigna los siteadmins actuales a su rol correspondiente
// según el criterio definido por el equipo de Registros. Tres usuarios
// conservan `manager` (super-admins). Una queda sin ningún rol custom
// (Fernanda Alonso — sin uso registrado). El resto pasan a un rol
// custom y se les quita `manager` a nivel sistema para que el bundle
// de caps del rol sea su único techo de permisos.
//
// Uso:
//   php migrate_siteadmins_to_roles.php                    # ensayo (no toca nada)
//   APPLY=1 php migrate_siteadmins_to_roles.php            # aplica los cambios
//   APPLY=1 php migrate_siteadmins_to_roles.php --reverse   # revierte (manager a todos)
//
// Antes de aplicar:
//   - El plugin debe estar en version 20261001007 o superior (gmk_psicologo creado)
//   - Hacer un backup de mdl_role_assignments
//
// Salida: lista cada movimiento (quitar manager, asignar custom) por
// usuario y deja el sistema en un estado trazable.

define("CLI_SCRIPT", true);
require("/var/www/html/moodle/config.php");
global $DB, $CFG;

$DRY = getenv("APPLY") !== "1";
$REVERSE = in_array("--reverse", $argv ?? [], true);

echo ($DRY ? "*** ENSAYO (DRY RUN) ***" : "*** APLICANDO ***") . "\n";
echo ($REVERSE ? "*** MODO REVERSA: restaurar manager a todos ***" : "*** MODO NORMAL ***") . "\n\n";

$syscontext = context_system::instance();

// Mapa de migración: userid => shortname del rol destino
//   - 'manager'   = se queda como super-admin (manager a nivel sistema)
//   - 'none'      = se le quita el manager pero no se le asigna rol custom
$migration = [
    2    => 'manager',                  // Administrador Usuario (tic@isi.edu.pa) - super-admin
    2886 => 'manager',                  // Joyce Muñoz (direccionacademica) - super-admin
    2773 => 'manager',                  // Walber Castillo (gerenciageneral) - super-admin (inactivo)
    2953 => 'gmk_director_academico',   // José Joel Rodriguez - Director Académico
    2912 => 'gmk_registros_academicos', // Lizbeth Aizprua - Registros
    2732 => 'gmk_secretaria_academica',  // Jean Remice - Secretaría
    2771 => 'gmk_secretaria_academica',  // Veronica Rangel - Secretaría
    2737 => 'gmk_bienestar',            // Jorge Oviedo - Coordinador de Bienestar
    2765 => 'gmk_psicologo',            // Dulce Jurado - Psicóloga
    2756 => 'gmk_soporte_ti',           // Esteban Montoya - Soporte TI
    3031 => 'none',                     // Fernanda Alonso - remover manager (inactiva)
];

// --- MODO REVERSA: restaurar manager a todos los del mapa ---
if ($REVERSE) {
    $manager = $DB->get_record('role', ['shortname' => 'manager']);
    if (!$manager) { fwrite(STDERR, "ERROR: rol 'manager' no existe\n"); exit(1); }
    foreach ($migration as $userid => $target) {
        $user = $DB->get_record('user', ['id' => $userid]);
        if (!$user) { echo "  SKIP user $userid (no existe)\n"; continue; }
        $has = $DB->record_exists('role_assignments', ['userid' => $userid, 'roleid' => $manager->id, 'contextid' => $syscontext->id]);
        if ($has && !$DRY) { echo "  ALREADY user $userid ({$user->username}) ya tiene manager — skip\n"; continue; }
        echo "  + manager a user $userid ({$user->username}) — $user->firstname $user->lastname\n";
        if (!$DRY) {
            role_assign($manager->id, $userid, $syscontext);
        }
    }
    echo "\n" . ($DRY ? "(dry-run) " : "") . "Reversión lista.\n";
    exit(0);
}

// --- MODO NORMAL ---

// Snapshot antes.
$managerrole = $DB->get_record('role', ['shortname' => 'manager']);
if (!$managerrole) { fwrite(STDERR, "ERROR: rol 'manager' no existe\n"); exit(1); }

// Moodle 4+ gestiona siteadmins via mdl_config('siteadmins') (lista CSV de
// userids). Tambien mantiene role_assignment con manager a nivel sistema como
// mecanismo secundario. Hay que tocar ambos para que un usuario deje de ser
// admin completamente.
$siteadminsConfig = $DB->get_record('config', ['name' => 'siteadmins']);
$siteadminIds = [];
if ($siteadminsConfig && !empty($siteadminsConfig->value)) {
    $siteadminIds = array_filter(array_map('intval', explode(',', $siteadminsConfig->value)));
}

$before = [];
$before['siteadmins_via_config'] = count($siteadminIds);
$before['siteadmins_via_role'] = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT userid) FROM {role_assignments} ra
     JOIN {role} r ON r.id = ra.roleid
     WHERE r.shortname = 'manager' AND ra.contextid = ?", [$syscontext->id]
);

echo "=== ANTES ===\n";
printf("  Siteadmins (config):    %d\n", $before['siteadmins_via_config']);
printf("  Siteadmins (manager):   %d\n", $before['siteadmins_via_role']);
echo "\n=== MOVIMIENTOS ===\n";

$moves = ['unassign_manager' => 0, 'assign_custom' => 0, 'keep_manager' => 0, 'noop' => 0];

foreach ($migration as $userid => $target) {
    $user = $DB->get_record('user', ['id' => $userid]);
    if (!$user) { echo "  SKIP user $userid (no existe)\n"; continue; }

    $label = "user $userid ({$user->username}) — $user->firstname $user->lastname <$user->email>";

    // 1) Quitar acceso de siteadmin a usuarios no-super (excepto para los 3
    // super-admins definidos en el mapa). Hay que tocar DOS mecanismos:
    //    a) mdl_config('siteadmins') — mecanismo principal en Moodle 4+.
    //    b) mdl_role_assignments con manager en context_system — secundario.
    if ($target !== 'manager') {
        // (a) Quitar de mdl_config.siteadmins
        if (in_array($userid, $siteadminIds, true)) {
            echo "  - siteadmins (config) a $label\n";
            if (!$DRY) {
                $newIds = array_values(array_filter($siteadminIds, function ($id) use ($userid) {
                    return $id !== $userid;
                }));
                $newValue = empty($newIds) ? '' : implode(',', $newIds);
                $DB->set_field('config', 'value', $newValue, ['name' => 'siteadmins']);
                $siteadminIds = $newIds;  // actualizar el snapshot en memoria
            }
            $moves['unassign_manager']++;
        }
        // (b) Quitar role_assignment manager
        $has_manager = $DB->record_exists('role_assignments', [
            'userid' => $userid, 'roleid' => $managerrole->id, 'contextid' => $syscontext->id
        ]);
        if ($has_manager) {
            echo "  - manager (role_assignment) a $label\n";
            if (!$DRY) {
                role_unassign($managerrole->id, $userid, $syscontext);
            }
            $moves['unassign_manager']++;
        }
    } else {
        echo "  = mantiene manager a $label (super-admin)\n";
        $moves['keep_manager']++;
        continue;
    }

    // 2) Asignar rol custom (excepto 'none')
    if ($target === 'none') {
        echo "  (sin rol custom — solo se le removió manager)\n";
        $moves['noop']++;
        continue;
    }

    $customrole = $DB->get_record('role', ['shortname' => $target]);
    if (!$customrole) {
        echo "  ERROR: rol '$target' no existe — skipping $label\n";
        continue;
    }
    $has_custom = $DB->record_exists('role_assignments', [
        'userid' => $userid, 'roleid' => $customrole->id, 'contextid' => $syscontext->id
    ]);
    if ($has_custom) {
        echo "  (ya tiene $target) skip\n";
        $moves['noop']++;
        continue;
    }
    echo "  + $target (sistema) a $label\n";
    if (!$DRY) {
        role_assign($customrole->id, $userid, $syscontext);
    }
    $moves['assign_custom']++;
}

echo "\n=== RESUMEN ===\n";
foreach ($moves as $k => $v) printf("  %-20s %d\n", $k, $v);

$afterConfig = $DB->count_records_sql("SELECT LENGTH(value) - LENGTH(REPLACE(value, ',', '')) + 1 FROM {config} WHERE name = 'siteadmins' AND value <> ''");
$afterRole = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT userid) FROM {role_assignments} ra
     JOIN {role} r ON r.id = ra.roleid
     WHERE r.shortname = 'manager' AND ra.contextid = ?", [$syscontext->id]
);
echo "\n=== DESPUÉS ===\n";
printf("  Siteadmins (config):  %d  (antes %d)\n", $afterConfig, $before['siteadmins_via_config']);
printf("  Siteadmins (manager): %d  (antes %d)\n", $afterRole, $before['siteadmins_via_role']);

if ($DRY) {
    echo "\n*** DRY RUN: nada se modificó. Ejecutar con APPLY=1 para aplicar. ***\n";
} else {
    echo "\n*** MIGRACIÓN APLICADA. Verificar que los usuarios pueden entrar al admin tree. ***\n";
}
