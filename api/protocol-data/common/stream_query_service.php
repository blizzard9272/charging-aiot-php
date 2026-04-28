<?php
require_once __DIR__ . '/../../../config/cors.php';
require_once __DIR__ . '/../../../../config.php';
require_once __DIR__ . '/../../../auth/AuthService.php';

header('Content-Type: application/json; charset=utf-8');

function response_success($data, $msg = null)
{
    echo json_encode([
        'code' => 1,
        'msg' => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function response_error($msg, $httpCode = 500)
{
    http_response_code($httpCode);
    echo json_encode([
        'code' => 0,
        'msg' => $msg,
        'data' => null
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bootstrap_stream_table(PDO $pdo)
{
    $sql = "CREATE TABLE IF NOT EXISTS camera_stream_data (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                msg_type INT NOT NULL,
                camera_id VARCHAR(128) DEFAULT NULL,
                device_timestamp BIGINT DEFAULT NULL,
                payload_data LONGTEXT,
                raw_json LONGTEXT,
                image_urls LONGTEXT,
                source_file_name VARCHAR(255) DEFAULT NULL,
                source_file_size BIGINT DEFAULT 0,
                status_flag TINYINT DEFAULT 1,
                create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
                modify_time DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_msg_type_time (msg_type, device_timestamp),
                INDEX idx_camera_time (camera_id, device_timestamp)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $pdo->exec($sql);

    $requiredColumns = [
        'msg_type' => 'INT NOT NULL DEFAULT 0',
        'camera_id' => 'VARCHAR(128) DEFAULT NULL',
        'device_timestamp' => 'BIGINT DEFAULT NULL',
        'payload_data' => 'LONGTEXT',
        'raw_json' => 'LONGTEXT',
        'image_urls' => 'LONGTEXT',
        'source_file_name' => 'VARCHAR(255) DEFAULT NULL',
        'source_file_size' => 'BIGINT DEFAULT 0',
        'status_flag' => 'TINYINT DEFAULT 1',
        'create_time' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
        'modify_time' => 'DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
    ];

    $columnSql = 'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?';
    $columnStmt = $pdo->prepare($columnSql);

    foreach ($requiredColumns as $column => $definition) {
        $columnStmt->execute(['camera_stream_data', $column]);
        $exists = intval($columnStmt->fetchColumn()) > 0;
        if (!$exists) {
            $pdo->exec('ALTER TABLE camera_stream_data ADD COLUMN ' . $column . ' ' . $definition);
        }
    }

    $requiredIndexes = [
        'idx_msg_type_time' => 'ALTER TABLE camera_stream_data ADD INDEX idx_msg_type_time (msg_type, device_timestamp)',
        'idx_camera_time' => 'ALTER TABLE camera_stream_data ADD INDEX idx_camera_time (camera_id, device_timestamp)'
    ];

    $indexSql = 'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?';
    $indexStmt = $pdo->prepare($indexSql);
    foreach ($requiredIndexes as $indexName => $indexAlterSql) {
        $indexStmt->execute(['camera_stream_data', $indexName]);
        $exists = intval($indexStmt->fetchColumn()) > 0;
        if (!$exists) {
            $pdo->exec($indexAlterSql);
        }
    }
}

function parse_nullable_int($value)
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    return intval($value);
}

function normalize_timestamp($value)
{
    $timestamp = parse_nullable_int($value);
    if ($timestamp === null) {
        return null;
    }
    if ($timestamp > 0 && $timestamp < 100000000000) {
        $timestamp = $timestamp * 1000;
    }
    return $timestamp;
}

function read_json_body()
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function get_action()
{
    if (isset($_GET['action']) && $_GET['action'] !== '') {
        return trim($_GET['action']);
    }

    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    if ($uri !== '' && preg_match('/\/stream\/query\/(101|102|103|page|stats)/', $uri, $matches)) {
        return $matches[1];
    }
    return '';
}

function parse_image_urls($rawImageUrls)
{
    if ($rawImageUrls === null || $rawImageUrls === '') {
        return [];
    }
    $decoded = json_decode($rawImageUrls, true);
    return is_array($decoded) ? $decoded : [];
}

function extract_payload($rawPayload)
{
    if ($rawPayload === null || $rawPayload === '') {
        return [];
    }

    if (is_array($rawPayload)) {
        $payload = $rawPayload;
    } else {
        $decoded = json_decode($rawPayload, true);
        $payload = is_array($decoded) ? $decoded : [];
    }

    if (isset($payload['payloadData']) && is_array($payload['payloadData'])) {
        $payload = $payload['payloadData'];
    }
    if (isset($payload['payload_data']) && is_array($payload['payload_data'])) {
        $payload = $payload['payload_data'];
    }

    return is_array($payload) ? $payload : [];
}

function normalize_payload_by_type($msgType, $payload, $imageUrls)
{
    if (!is_array($payload)) {
        $payload = [];
    }

    if ($msgType === 101) {
        if (!isset($payload['objects']) || !is_array($payload['objects'])) {
            $payload['objects'] = [];
        }
        if (!isset($payload['object_count'])) {
            $payload['object_count'] = count($payload['objects']);
        }
    }

    if ($msgType === 102) {
        if (!isset($payload['person_name']) && isset($payload['personName'])) {
            $payload['person_name'] = $payload['personName'];
        }
        if (!isset($payload['track_id']) && isset($payload['trackId'])) {
            $payload['track_id'] = $payload['trackId'];
        }
        if (!isset($payload['person_name'])) {
            $payload['person_name'] = '未知';
        }
        if (!isset($payload['status'])) {
            $payload['status'] = '未知人员';
        }
        if (!isset($payload['track_id'])) {
            $payload['track_id'] = '-';
        }
    }

    if ($msgType === 103) {
        if (!isset($payload['track_id']) && isset($payload['trackId'])) {
            $payload['track_id'] = $payload['trackId'];
        }
        if (!isset($payload['frame_image']) && isset($payload['frameImage'])) {
            $payload['frame_image'] = $payload['frameImage'];
        }
        if ((!isset($payload['frame_image']) || $payload['frame_image'] === '') && !empty($imageUrls)) {
            $payload['frame_image'] = $imageUrls[0];
        }
        if (!isset($payload['frame_image'])) {
            $payload['frame_image'] = '';
        }
        if (!isset($payload['track_id'])) {
            $payload['track_id'] = '-';
        }
    }

    return $payload;
}

function format_stream_row($row)
{
    $msgType = intval($row['msg_type']);
    $payload = extract_payload(isset($row['payload_data']) ? $row['payload_data'] : '');
    $imageUrls = parse_image_urls(isset($row['image_urls']) ? $row['image_urls'] : '');
    $payloadData = normalize_payload_by_type($msgType, $payload, $imageUrls);

    return [
        'id' => intval($row['id']),
        'msgType' => $msgType,
        'cameraId' => isset($row['camera_id']) ? (string) $row['camera_id'] : '',
        'deviceTimestamp' => intval(isset($row['device_timestamp']) ? $row['device_timestamp'] : 0),
        'payloadData' => $payloadData
    ];
}

function build_filters($msgType, $cameraId, $startTime, $endTime)
{
    $sql = ' WHERE msg_type = ?';
    $params = [intval($msgType)];

    if ($cameraId !== '') {
        $sql .= ' AND camera_id = ?';
        $params[] = $cameraId;
    }
    if ($startTime !== null) {
        $sql .= ' AND device_timestamp >= ?';
        $params[] = $startTime;
    }
    if ($endTime !== null) {
        $sql .= ' AND device_timestamp <= ?';
        $params[] = $endTime;
    }

    return [$sql, $params];
}

function query_by_type(PDO $pdo, $msgType)
{
    if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
        response_error('请求方法不支持', 405);
    }

    $requestData = read_json_body();
    $limit = isset($requestData['limit']) ? intval($requestData['limit']) : 20;
    if ($limit <= 0) {
        $limit = 20;
    }
    if ($limit > 500) {
        $limit = 500;
    }

    $cameraId = '';
    if (isset($requestData['cameraId'])) {
        $cameraId = trim((string) $requestData['cameraId']);
    }
    if ($cameraId === '' && isset($requestData['camera_id'])) {
        $cameraId = trim((string) $requestData['camera_id']);
    }

    $startTime = normalize_timestamp(isset($requestData['startTime']) ? $requestData['startTime'] : null);
    $endTime = normalize_timestamp(isset($requestData['endTime']) ? $requestData['endTime'] : null);

    list($whereSql, $params) = build_filters($msgType, $cameraId, $startTime, $endTime);

    $sql = 'SELECT id, msg_type, camera_id, device_timestamp, payload_data, image_urls
            FROM camera_stream_data' . $whereSql . '
            ORDER BY device_timestamp DESC, id DESC
            LIMIT ?';
    $params[] = $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = [];
    foreach ($rows as $row) {
        $data[] = format_stream_row($row);
    }

    response_success($data);
}

function query_page(PDO $pdo)
{
    if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'GET') {
        response_error('请求方法不支持', 405);
    }

    $msgType = isset($_GET['msgType']) ? intval($_GET['msgType']) : 0;
    if (!in_array($msgType, [101, 102, 103], true)) {
        response_error('msgType 参数错误', 400);
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

    $cameraId = isset($_GET['cameraId']) ? trim((string) $_GET['cameraId']) : '';
    if ($cameraId === '' && isset($_GET['camera_id'])) {
        $cameraId = trim((string) $_GET['camera_id']);
    }

    $startTime = normalize_timestamp(isset($_GET['startTime']) ? $_GET['startTime'] : null);
    $endTime = normalize_timestamp(isset($_GET['endTime']) ? $_GET['endTime'] : null);

    list($whereSql, $params) = build_filters($msgType, $cameraId, $startTime, $endTime);

    $countSql = 'SELECT COUNT(*) AS total FROM camera_stream_data' . $whereSql;
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = intval($countStmt->fetchColumn());

    $offset = ($page - 1) * $pageSize;
    $dataSql = 'SELECT id, msg_type, camera_id, device_timestamp, payload_data, image_urls
                FROM camera_stream_data' . $whereSql . '
                ORDER BY device_timestamp DESC, id DESC
                LIMIT ? OFFSET ?';

    $dataParams = $params;
    $dataParams[] = $pageSize;
    $dataParams[] = $offset;

    $dataStmt = $pdo->prepare($dataSql);
    $dataStmt->execute($dataParams);
    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    $records = [];
    foreach ($rows as $row) {
        $records[] = format_stream_row($row);
    }

    response_success([
        'total' => $total,
        'records' => $records
    ]);
}

function query_stats(PDO $pdo)
{
    if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'GET') {
        response_error('请求方法不支持', 405);
    }

    $startTime = normalize_timestamp(isset($_GET['startTime']) ? $_GET['startTime'] : null);
    $endTime = normalize_timestamp(isset($_GET['endTime']) ? $_GET['endTime'] : null);

    $whereSql = ' WHERE 1=1';
    $params = [];
    if ($startTime !== null) {
        $whereSql .= ' AND device_timestamp >= ?';
        $params[] = $startTime;
    }
    if ($endTime !== null) {
        $whereSql .= ' AND device_timestamp <= ?';
        $params[] = $endTime;
    }

    $onlineSql = 'SELECT COUNT(DISTINCT camera_id) FROM camera_stream_data'
        . $whereSql . " AND camera_id IS NOT NULL AND camera_id <> ''";
    $onlineStmt = $pdo->prepare($onlineSql);
    $onlineStmt->execute($params);
    $onlineDeviceCount = intval($onlineStmt->fetchColumn());

    $peopleSql = 'SELECT camera_id, payload_data FROM camera_stream_data'
        . $whereSql . ' AND msg_type = 101 ORDER BY device_timestamp DESC, id DESC';
    $peopleStmt = $pdo->prepare($peopleSql);
    $peopleStmt->execute($params);
    $peopleRows = $peopleStmt->fetchAll(PDO::FETCH_ASSOC);

    $cameraSeen = [];
    $currentTotalPeople = 0;
    foreach ($peopleRows as $row) {
        $cameraId = trim((string) (isset($row['camera_id']) ? $row['camera_id'] : ''));
        if ($cameraId !== '') {
            if (isset($cameraSeen[$cameraId])) {
                continue;
            }
            $cameraSeen[$cameraId] = true;
        }

        $payload = extract_payload(isset($row['payload_data']) ? $row['payload_data'] : '');
        $objectCount = 0;
        if (isset($payload['object_count']) && is_numeric($payload['object_count'])) {
            $objectCount = intval($payload['object_count']);
        } elseif (isset($payload['objects']) && is_array($payload['objects'])) {
            $objectCount = count($payload['objects']);
        }
        if ($objectCount > 0) {
            $currentTotalPeople += $objectCount;
        }
    }

    if ($startTime === null && $endTime === null) {
        $todayStart = strtotime(date('Y-m-d 00:00:00')) * 1000;
        $todayEnd = strtotime(date('Y-m-d 23:59:59')) * 1000;
    } else {
        $todayStart = $startTime !== null ? $startTime : 0;
        $todayEnd = $endTime !== null ? $endTime : intval(round(microtime(true) * 1000));
    }

    $captureSql = 'SELECT COUNT(*) FROM camera_stream_data
                   WHERE msg_type = 103 AND device_timestamp >= ? AND device_timestamp <= ?';
    $captureStmt = $pdo->prepare($captureSql);
    $captureStmt->execute([$todayStart, $todayEnd]);
    $todayCaptureCount = intval($captureStmt->fetchColumn());

    $recognizedSql = 'SELECT payload_data FROM camera_stream_data
                      WHERE msg_type = 102 AND device_timestamp >= ? AND device_timestamp <= ?';
    $recognizedStmt = $pdo->prepare($recognizedSql);
    $recognizedStmt->execute([$todayStart, $todayEnd]);
    $recognizedRows = $recognizedStmt->fetchAll(PDO::FETCH_ASSOC);

    $todayRecognizedCount = 0;
    foreach ($recognizedRows as $row) {
        $payload = extract_payload(isset($row['payload_data']) ? $row['payload_data'] : '');
        $status = isset($payload['status']) ? trim((string) $payload['status']) : '';
        if ($status !== '' && $status !== '未知人员') {
            $todayRecognizedCount++;
        }
    }

    response_success([
        'onlineDeviceCount' => $onlineDeviceCount,
        'currentTotalPeople' => $currentTotalPeople,
        'todayCaptureCount' => $todayCaptureCount,
        'todayRecognizedCount' => $todayRecognizedCount
    ]);
}

try {
    bootstrap_stream_table($pdo);
    auth_require_jwt([], function ($msg, $httpCode) {
        response_error($msg, $httpCode);
    });

    $action = get_action();
    if (!in_array($action, ['101', '102', '103', 'page', 'stats'], true)) {
        response_error('接口不存在', 404);
    }

    if ($action === '101' || $action === '102' || $action === '103') {
        query_by_type($pdo, intval($action));
    }

    if ($action === 'page') {
        query_page($pdo);
    }

    if ($action === 'stats') {
        query_stats($pdo);
    }

    response_error('接口不存在', 404);
} catch (PDOException $e) {
    response_error('数据库查询失败: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    response_error('服务异常: ' . $e->getMessage(), 500);
}
