<?php

function handle_workbench_audit(PDO $pdo)
{
    if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'GET') {
        response_error('Method not allowed', 405);
    }

    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $pageSize = isset($_GET['pageSize']) ? intval($_GET['pageSize']) : 20;
    if ($page <= 0) {
        $page = 1;
    }
    if ($pageSize <= 0) {
        $pageSize = 20;
    }
    if ($pageSize > 200) {
        $pageSize = 200;
    }

    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
    if ($limit <= 0) {
        $limit = 50;
    }
    if ($limit > 200) {
        $limit = 200;
    }

    $startTime = normalize_string(isset($_GET['startTime']) ? $_GET['startTime'] : '');
    $endTime = normalize_string(isset($_GET['endTime']) ? $_GET['endTime'] : '');
    $eventType = strtoupper(normalize_string(isset($_GET['eventType']) ? $_GET['eventType'] : ''));
    $keyword = normalize_string(isset($_GET['keyword']) ? $_GET['keyword'] : '');
    $resultStatus = normalize_int_or_null(isset($_GET['resultStatus']) ? $_GET['resultStatus'] : null);

    $allowedEventTypes = ['CREATE', 'QUERY', 'UPDATE', 'MODIFY', 'DELETE', 'ROLLBACK', 'SYNC'];
    if ($eventType !== '' && !in_array($eventType, $allowedEventTypes, true)) {
        $eventType = '';
    }
    if ($resultStatus !== null && !in_array($resultStatus, [0, 1], true)) {
        $resultStatus = null;
    }

    $logWhereParts = ['1=1'];
    $logParams = [];

    if ($startTime !== '') {
        $logWhereParts[] = 'create_time >= ?';
        $logParams[] = $startTime;
    }
    if ($endTime !== '') {
        $logWhereParts[] = 'create_time <= ?';
        $logParams[] = $endTime;
    }
    if ($eventType !== '') {
        $logWhereParts[] = 'event_type = ?';
        $logParams[] = $eventType;
    }
    if ($resultStatus !== null) {
        $logWhereParts[] = 'result_status = ?';
        $logParams[] = $resultStatus;
    }
    if ($keyword !== '') {
        $logWhereParts[] = '(action_name LIKE ? OR path_name LIKE ? OR error_message LIKE ?)';
        $likeKeyword = '%' . $keyword . '%';
        $logParams[] = $likeKeyword;
        $logParams[] = $likeKeyword;
        $logParams[] = $likeKeyword;
    }

    $logWhereSql = ' WHERE ' . implode(' AND ', $logWhereParts);
    $offset = ($page - 1) * $pageSize;
    if ($offset < 0) {
        $offset = 0;
    }

    $stats = [
        'groupCount' => intval($pdo->query('SELECT COUNT(*) FROM sys_device_group WHERE status_flag = 1')->fetchColumn()),
        'deviceCount' => intval($pdo->query('SELECT COUNT(*) FROM sys_device WHERE status_flag <> 4')->fetchColumn()),
        'onlineDeviceCount' => intval($pdo->query('SELECT COUNT(*) FROM sys_device WHERE status_flag <> 4 AND online_status = 1')->fetchColumn()),
        'pathCount' => intval($pdo->query('SELECT COUNT(*) FROM sys_camera_path WHERE status_flag = 1')->fetchColumn()),
        'createCount' => intval($pdo->query("SELECT COUNT(*) FROM sys_device_audit_log WHERE event_type = 'CREATE'")->fetchColumn()),
        'queryCount' => intval($pdo->query("SELECT COUNT(*) FROM sys_device_audit_log WHERE event_type = 'QUERY'")->fetchColumn()),
        'deleteCount' => intval($pdo->query("SELECT COUNT(*) FROM sys_device_audit_log WHERE event_type = 'DELETE'")->fetchColumn()),
        'rollbackCount' => intval($pdo->query("SELECT COUNT(*) FROM sys_device_audit_log WHERE event_type = 'ROLLBACK'")->fetchColumn()),
        'errorCount' => 0
    ];

    $countSql = 'SELECT COUNT(*) FROM sys_device_audit_log' . $logWhereSql;
    $countStmt = $pdo->prepare($countSql);
    foreach ($logParams as $index => $value) {
        $countStmt->bindValue($index + 1, $value);
    }
    $countStmt->execute();
    $operationTotal = intval($countStmt->fetchColumn());

    $logSql = 'SELECT log_id, event_type, action_name, device_id, group_id, path_name,
                      result_status, error_message, create_time
               FROM sys_device_audit_log' . $logWhereSql . '
               ORDER BY log_id DESC
               LIMIT ?, ?';
    $logStmt = $pdo->prepare($logSql);
    foreach ($logParams as $index => $value) {
        $logStmt->bindValue($index + 1, $value);
    }
    $logStmt->bindValue(count($logParams) + 1, $offset, PDO::PARAM_INT);
    $logStmt->bindValue(count($logParams) + 2, $pageSize, PDO::PARAM_INT);
    $logStmt->execute();
    $operationLogs = $logStmt->fetchAll(PDO::FETCH_ASSOC);

    $rollbackLogs = DeviceAuditLogger::readRecentLogFile(DeviceAuditLogger::ROLLBACK_LOG_FILE, $limit);
    $errorLogs = DeviceAuditLogger::readRecentLogFile(DeviceAuditLogger::ERROR_LOG_FILE, $limit);
    $stats['errorCount'] = count($errorLogs);

    response_success([
        'stats' => $stats,
        'operationLogs' => $operationLogs,
        'operationPage' => [
            'currentPage' => $page,
            'pageSize' => $pageSize,
            'total' => $operationTotal
        ],
        'rollbackLogs' => $rollbackLogs,
        'errorLogs' => $errorLogs
    ], 'Workbench audit loaded');
}

function sync_camera_config_core(PDO $pdo)
{
    $sql = 'SELECT p.path_id, p.device_id, p.path_name, p.source_url,
                   p.record_enabled, p.record_path, p.record_format,
                   p.record_part_duration, p.status_flag
            FROM sys_camera_path p
            WHERE p.path_name IS NOT NULL
              AND p.path_name <> ""
              AND p.source_url IS NOT NULL
              AND p.source_url <> ""
            ORDER BY p.path_id ASC';
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        return [
            'total' => 0,
            'successCount' => 0,
            'failedCount' => 0,
            'skippedCount' => 0,
            'failedItems' => [],
            'skippedItems' => []
        ];
    }

    $mediaMtxClient = MediaMtxClient::getInstance();
    $successCount = 0;
    $failedItems = [];
    $skippedItems = [];

    foreach ($rows as $row) {
        $pathName = normalize_string(isset($row['path_name']) ? $row['path_name'] : '');
        $sourceUrl = normalize_string(isset($row['source_url']) ? $row['source_url'] : '');
        $statusFlag = normalize_int_or_null(isset($row['status_flag']) ? $row['status_flag'] : null);

        if ($pathName === '' || $sourceUrl === '') {
            $skippedItems[] = [
                'pathId' => intval($row['path_id']),
                'deviceId' => intval($row['device_id']),
                'pathName' => $pathName,
                'reason' => 'path_name or source_url is empty'
            ];
            continue;
        }

        if ($statusFlag !== null && $statusFlag !== 1) {
            $skippedItems[] = [
                'pathId' => intval($row['path_id']),
                'deviceId' => intval($row['device_id']),
                'pathName' => $pathName,
                'reason' => 'path status is not enabled'
            ];
            continue;
        }

        $result = $mediaMtxClient->ensurePathConfig($pathName, [
            'source' => $sourceUrl,
            'record' => intval($row['record_enabled'] ?? 0) === 1,
            'recordPath' => normalize_string(isset($row['record_path']) ? $row['record_path'] : ''),
            'recordFormat' => normalize_string(isset($row['record_format']) ? $row['record_format'] : 'fmp4'),
            'recordPartDuration' => intval($row['record_part_duration'] ?? 60)
        ]);

        if (!is_array($result) || empty($result['success'])) {
            $failedItems[] = [
                'pathId' => intval($row['path_id']),
                'deviceId' => intval($row['device_id']),
                'pathName' => $pathName,
                'code' => isset($result['code']) ? intval($result['code']) : 500,
                'error' => isset($result['data']) ? $result['data'] : 'unknown error'
            ];
            continue;
        }

        $successCount += 1;
    }

    $summary = [
        'total' => count($rows),
        'successCount' => $successCount,
        'failedCount' => count($failedItems),
        'skippedCount' => count($skippedItems),
        'failedItems' => $failedItems,
        'skippedItems' => $skippedItems
    ];

    return $summary;
}

function read_media_mtx_auto_sync_state()
{
    if (!is_file(MEDIAMTX_AUTO_SYNC_STATE_FILE)) {
        return [];
    }
    $raw = @file_get_contents(MEDIAMTX_AUTO_SYNC_STATE_FILE);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function write_media_mtx_auto_sync_state(array $state)
{
    $dir = dirname(MEDIAMTX_AUTO_SYNC_STATE_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    @file_put_contents(
        MEDIAMTX_AUTO_SYNC_STATE_FILE,
        json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    );
}

function maybe_auto_sync_camera_config(PDO $pdo, $action)
{
    if ($action === 'syncCameraConfig' || $action === 'mediaMtxHealth') {
        return;
    }

    $interval = intval(getenv('MEDIAMTX_AUTO_SYNC_INTERVAL_SECONDS'));
    if ($interval <= 0) {
        $interval = MEDIAMTX_AUTO_SYNC_INTERVAL_SECONDS;
    }

    $dir = dirname(MEDIAMTX_AUTO_SYNC_LOCK_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }

    $lockFp = @fopen(MEDIAMTX_AUTO_SYNC_LOCK_FILE, 'c+');
    if (!$lockFp) {
        return;
    }

    try {
        if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
            return;
        }

        $state = read_media_mtx_auto_sync_state();
        $now = time();
        $lastSuccess = intval($state['last_success_ts'] ?? 0);
        if ($lastSuccess > 0 && ($now - $lastSuccess) < $interval) {
            return;
        }

        $summary = sync_camera_config_core($pdo);
        $failedCount = intval($summary['failedCount'] ?? 0);
        $state = [
            'last_attempt_ts' => $now,
            'last_success_ts' => $failedCount === 0 ? $now : $lastSuccess,
            'last_failed_count' => $failedCount,
            'last_summary' => $summary
        ];
        write_media_mtx_auto_sync_state($state);
    } catch (Exception $e) {
        $state = read_media_mtx_auto_sync_state();
        $state['last_attempt_ts'] = time();
        $state['last_error'] = $e->getMessage();
        write_media_mtx_auto_sync_state($state);
    } finally {
        @flock($lockFp, LOCK_UN);
        @fclose($lockFp);
    }
}

function handle_sync_camera_config(PDO $pdo)
{
    if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'GET') {
        response_error('Method not allowed', 405);
    }

    $summary = sync_camera_config_core($pdo);
    $failedCount = intval($summary['failedCount'] ?? 0);

    if ($failedCount > 0) {
        write_device_audit($pdo, [
            'event_type' => 'SYNC',
            'action_name' => 'syncCameraConfig',
            'result_status' => 0,
            'error_message' => 'sync finished with failures',
            'response_payload' => $summary
        ]);
        response_success($summary, 'Sync finished with failures: ' . $failedCount);
    }

    write_device_audit($pdo, [
        'event_type' => 'SYNC',
        'action_name' => 'syncCameraConfig',
        'result_status' => 1,
        'response_payload' => $summary
    ]);

    if (intval($summary['total'] ?? 0) === 0) {
        response_success($summary, 'Sync finished, no valid path config in database');
    }
    response_success($summary, 'Sync finished: ' . intval($summary['successCount'] ?? 0) . '/' . intval($summary['total'] ?? 0));
}

function handle_media_mtx_health(PDO $pdo)
{
    if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'GET') {
        response_error('Method not allowed', 405);
    }

    $state = read_media_mtx_auto_sync_state();
    $interval = intval(getenv('MEDIAMTX_AUTO_SYNC_INTERVAL_SECONDS'));
    if ($interval <= 0) {
        $interval = MEDIAMTX_AUTO_SYNC_INTERVAL_SECONDS;
    }

    $now = time();
    $lastAttemptTs = intval($state['last_attempt_ts'] ?? 0);
    $lastSuccessTs = intval($state['last_success_ts'] ?? 0);
    $lastFailedCount = intval($state['last_failed_count'] ?? 0);
    $nextDueInSeconds = 0;
    if ($lastSuccessTs > 0) {
        $nextDueInSeconds = max(0, ($lastSuccessTs + $interval) - $now);
    }

    $probeResult = MediaMtxClient::getInstance()->checkPathExists('__monitor_health_probe__');
    $apiReachable = is_array($probeResult) && !empty($probeResult['success']);
    $apiCode = is_array($probeResult) ? intval($probeResult['code'] ?? 500) : 500;
    $apiMessage = '';
    if (!$apiReachable) {
        $apiMessage = is_array($probeResult) && isset($probeResult['data'])
            ? (is_string($probeResult['data']) ? $probeResult['data'] : json_encode($probeResult['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            : 'failed to connect MediaMTX API';
    }

    $result = [
        'nowTs' => $now,
        'nowAt' => format_unix_time_iso8601($now),
        'intervalSeconds' => $interval,
        'lastAttemptTs' => $lastAttemptTs > 0 ? $lastAttemptTs : null,
        'lastAttemptAt' => format_unix_time_iso8601($lastAttemptTs),
        'lastSuccessTs' => $lastSuccessTs > 0 ? $lastSuccessTs : null,
        'lastSuccessAt' => format_unix_time_iso8601($lastSuccessTs),
        'lastFailedCount' => $lastFailedCount,
        'nextDueInSeconds' => $nextDueInSeconds,
        'lastError' => isset($state['last_error']) ? strval($state['last_error']) : '',
        'lastSummary' => isset($state['last_summary']) && is_array($state['last_summary']) ? $state['last_summary'] : null,
        'mediaMtxApiReachable' => $apiReachable,
        'mediaMtxApiCode' => $apiCode,
        'mediaMtxApiMessage' => $apiMessage
    ];

    response_success($result, 'MediaMTX health loaded');
}

