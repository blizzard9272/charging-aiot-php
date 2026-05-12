<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../api/protocol-data/common/upload_stream_service.php';

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from CLI.\n");
    exit(1);
}

$retryFailed = in_array('--retry-failed', $argv, true);
$showFailed = in_array('--show-failed', $argv, true) || !$retryFailed;
$showRecentDone = in_array('--show-done', $argv, true);
$limit = 10;

foreach ($argv as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $limit = max(1, intval(substr($arg, 8)));
    }
}

function fetch_queue_counts(PDO $pdo)
{
    $stmt = $pdo->query("SELECT status, COUNT(*) AS total FROM protocol_quality_jobs GROUP BY status");
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
    $counts = array(
        'pending' => 0,
        'processing' => 0,
        'failed' => 0,
        'done' => 0
    );
    foreach ($rows as $row) {
        $status = strval(isset($row['status']) ? $row['status'] : '');
        $counts[$status] = intval(isset($row['total']) ? $row['total'] : 0);
    }
    return $counts;
}

function fetch_jobs_by_status(PDO $pdo, $status, $limit)
{
    $stmt = $pdo->prepare(
        "SELECT id, protocol_id, camera_id, batch_id, attempts, available_at, started_at, finished_at, last_error
         FROM protocol_quality_jobs
         WHERE status = ?
         ORDER BY updated_at DESC, id DESC
         LIMIT " . intval($limit)
    );
    $stmt->execute(array($status));
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

try {
    ensure_protocol_quality_job_table_exists($pdo);

    if ($retryFailed) {
        $retryStmt = $pdo->prepare(
            "UPDATE protocol_quality_jobs
             SET status = 'pending',
                 available_at = NOW(),
                 locked_at = NULL,
                 started_at = NULL,
                 finished_at = NULL,
                 last_error = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE status = 'failed'"
        );
        $retryStmt->execute();
        fwrite(STDOUT, "Reset failed jobs: " . intval($retryStmt->rowCount()) . PHP_EOL);
    }

    $counts = fetch_queue_counts($pdo);
    fwrite(STDOUT, "Protocol Quality Queue Summary\n");
    fwrite(STDOUT, "pending    : {$counts['pending']}\n");
    fwrite(STDOUT, "processing : {$counts['processing']}\n");
    fwrite(STDOUT, "failed     : {$counts['failed']}\n");
    fwrite(STDOUT, "done       : {$counts['done']}\n");

    if ($showFailed) {
        $failedRows = fetch_jobs_by_status($pdo, 'failed', $limit);
        fwrite(STDOUT, PHP_EOL . "Recent failed jobs (limit {$limit})\n");
        if (empty($failedRows)) {
            fwrite(STDOUT, "none\n");
        } else {
            foreach ($failedRows as $row) {
                fwrite(STDOUT, sprintf(
                    "#%d protocol=%d camera=%s batch=%d attempts=%d available_at=%s error=%s\n",
                    intval($row['id']),
                    intval($row['protocol_id']),
                    strval($row['camera_id']),
                    intval($row['batch_id']),
                    intval($row['attempts']),
                    strval($row['available_at']),
                    strval($row['last_error'])
                ));
            }
        }
    }

    if ($showRecentDone) {
        $doneRows = fetch_jobs_by_status($pdo, 'done', $limit);
        fwrite(STDOUT, PHP_EOL . "Recent done jobs (limit {$limit})\n");
        if (empty($doneRows)) {
            fwrite(STDOUT, "none\n");
        } else {
            foreach ($doneRows as $row) {
                fwrite(STDOUT, sprintf(
                    "#%d protocol=%d camera=%s batch=%d finished_at=%s\n",
                    intval($row['id']),
                    intval($row['protocol_id']),
                    strval($row['camera_id']),
                    intval($row['batch_id']),
                    strval($row['finished_at'])
                ));
            }
        }
    }

    exit(0);
} catch (Exception $e) {
    fwrite(STDERR, "Queue status failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
