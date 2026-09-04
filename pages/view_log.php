<?php
require_once(__DIR__ . '/../../../config.php');
require_login();
require_capability('local/grupomakro_core:view_log', context_system::instance());

$logFile = make_temp_directory('grupomakro') . '/sync_progress.log';
if (file_exists($logFile)) {
    echo "<h1>Log de Sincronización</h1>";
    echo "<pre>" . htmlspecialchars(file_get_contents($logFile)) . "</pre>";
} else {
    echo "El archivo de log no existe en: " . $logFile;
}
