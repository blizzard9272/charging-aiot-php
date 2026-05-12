<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../api/protocol-data/common/upload_stream_service.php';

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

$daemon = in_array('--daemon', $argv, true);
$sleepSeconds = 3;
$limit = 20;

foreach ($argv as $arg) {
    if (strpos($arg, '--sleep=') === 0) {
        $sleepSeconds = max(1, intval(substr($arg, 8)));
    } elseif (strpos($arg, '--limit=') === 0) {
        $limit = max(1, intval(substr($arg, 8)));
    }
}

function run_quality_worker_once(PDO $pdo, $limit)
{
    $jobs = fetch_quality_jobs($pdo, $limit);
    $processed = 0;
    foreach ($jobs as $job) {
        if (process_quality_job($pdo, $job)) {
            $processed++;
            fwrite(STDOUT, sprintf(
                "[%s] processed job #%d protocol=%d camera=%s batch=%d\n",
                date('Y-m-d H:i:s'),
                intval($job['id']),
                intval($job['protocol_id']),
                strval($job['camera_id']),
                intval($job['batch_id'])
            ));
        } else {
            fwrite(STDOUT, sprintf(
                "[%s] job #%d skipped or failed protocol=%d camera=%s batch=%d\n",
                date('Y-m-d H:i:s'),
                intval($job['id']),
                intval($job['protocol_id']),
                strval($job['camera_id']),
                intval($job['batch_id'])
            ));
        }
    }
    return $processed;
}

try {
    ensure_protocol_quality_summary_table_exists($pdo);
    ensure_protocol_quality_job_table_exists($pdo);

    do {
        $processed = run_quality_worker_once($pdo, $limit);
        if (!$daemon) {
            break;
        }
        if ($processed === 0) {
            sleep($sleepSeconds);
        }
    } while (true);

    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, "Worker failed: " . $e->getMessage() . "\n");
    exit(1);
}
