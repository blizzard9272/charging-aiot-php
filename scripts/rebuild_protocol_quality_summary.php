<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../api/protocol-data/common/upload_stream_service.php';

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

$protocolArg = isset($argv[1]) ? intval($argv[1]) : 0;
$allowedProtocols = array(101, 102, 103);
$protocols = in_array($protocolArg, $allowedProtocols, true) ? array($protocolArg) : $allowedProtocols;

try {
    ensure_protocol_quality_summary_table_exists($pdo);

    foreach ($protocols as $protocol) {
        $table = 'message_' . intval($protocol) . '_records';
        $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $checkStmt->execute(array($table));
        if (intval($checkStmt->fetchColumn()) <= 0) {
            fwrite(STDOUT, "Skip missing table: $table\n");
            continue;
        }

        $sql = "SELECT DISTINCT batch_id, camera_id
                FROM $table
                WHERE batch_id IS NOT NULL AND camera_id IS NOT NULL AND camera_id <> ''
                ORDER BY batch_id ASC";
        $stmt = $pdo->query($sql);
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
        fwrite(STDOUT, "Protocol $protocol batches: " . count($rows) . "\n");

        foreach ($rows as $index => $row) {
            $batchId = intval(isset($row['batch_id']) ? $row['batch_id'] : 0);
            $cameraId = strval(isset($row['camera_id']) ? $row['camera_id'] : '');
            if ($batchId <= 0 || $cameraId === '') {
                continue;
            }

            apply_quality_results_for_batch($pdo, $protocol, $batchId, $cameraId);

            if ((($index + 1) % 100) === 0) {
                fwrite(STDOUT, "Processed $protocol: " . ($index + 1) . '/' . count($rows) . "\n");
            }
        }
    }

    fwrite(STDOUT, "Quality summary rebuild completed.\n");
    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, "Rebuild failed: " . $e->getMessage() . "\n");
    exit(1);
}
