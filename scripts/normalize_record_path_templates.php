<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "PDO bootstrap failed\n");
    exit(1);
}

$defaultTemplate = '/home/zjl/Desktop/videos/%path/%path_%Y%m%d_%H%M%S';

$sql = <<<SQL
UPDATE sys_camera_path
SET record_path = :defaultTemplate,
    modify_time = NOW()
WHERE record_enabled = 1
  AND status_flag = 1
  AND (
      record_path IS NULL
      OR TRIM(record_path) = ''
      OR record_path REGEXP '/home/zjl/Desktop/videos/%path/Cam[0-9]+_%Y%m%d_%H%M%S$'
  )
SQL;

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':defaultTemplate' => $defaultTemplate
]);

fwrite(STDOUT, json_encode([
    'ok' => true,
    'updatedRows' => $stmt->rowCount(),
    'recordPath' => $defaultTemplate
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);

exit(0);
