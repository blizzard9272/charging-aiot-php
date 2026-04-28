<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../lib/storage_paths.php';
require_once __DIR__ . '/../lib/storage_migrator.php';

try {
    storage_bootstrap_settings($pdo);
    $summary = storage_migrate_all($pdo);
    echo json_encode(array(
        'code' => 1,
        'msg' => 'migration finished',
        'data' => $summary
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, json_encode(array(
        'code' => 0,
        'msg' => 'migration failed: ' . $e->getMessage(),
        'data' => null
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

