<?php
require_once __DIR__ . '/../../../config/cors.php';
require_once __DIR__ . '/../../../../config.php';

header('Content-Type: application/json; charset=utf-8');

function response_success($data, $msg = '上传成功')
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

function normalize_timestamp($value)
{
    if (!is_numeric($value)) {
        return intval(round(microtime(true) * 1000));
    }
    $timestamp = intval($value);
    if ($timestamp > 0 && $timestamp < 100000000000) {
        $timestamp = $timestamp * 1000;
    }
    return $timestamp;
}

function get_upload_type()
{
    if (isset($_GET['type']) && $_GET['type'] !== '') {
        return intval($_GET['type']);
    }

    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    if ($uri !== '' && preg_match('/\/upload\/(\d+)/', $uri, $matches)) {
        return intval($matches[1]);
    }

    return 0;
}

function resolve_camera_id($payload)
{
    if (!is_array($payload)) {
        return '';
    }
    if (isset($payload['camera_id'])) {
        return trim((string) $payload['camera_id']);
    }
    if (isset($payload['cameraId'])) {
        return trim((string) $payload['cameraId']);
    }
    return '';
}

function resolve_device_timestamp($payload)
{
    if (!is_array($payload)) {
        return intval(round(microtime(true) * 1000));
    }
    if (isset($payload['device_timestamp'])) {
        return normalize_timestamp($payload['device_timestamp']);
    }
    if (isset($payload['deviceTimestamp'])) {
        return normalize_timestamp($payload['deviceTimestamp']);
    }
    if (isset($payload['timestamp'])) {
        return normalize_timestamp($payload['timestamp']);
    }
    return intval(round(microtime(true) * 1000));
}

function save_one_image($tmpName, $originName, $saveDir, $publicPrefix)
{
    if (!is_uploaded_file($tmpName)) {
        return '';
    }

    $ext = strtolower(pathinfo($originName, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]/', '', $ext);
    $suffix = $ext !== '' ? '.' . $ext : '';

    try {
        $randomPart = bin2hex(random_bytes(4));
    } catch (Exception $e) {
        $randomPart = (string) mt_rand(1000, 9999);
    }

    $filename = date('YmdHis') . '_' . $randomPart . $suffix;
    $targetPath = $saveDir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($tmpName, $targetPath)) {
        return '';
    }

    return $publicPrefix . $filename;
}

function save_images()
{
    $field = null;
    if (isset($_FILES['images'])) {
        $field = 'images';
    } elseif (isset($_FILES['images[]'])) {
        $field = 'images[]';
    }

    if ($field === null) {
        return [];
    }

    $dateFolder = date('Ymd');
    $saveDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data-center' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . $dateFolder;
    if (!is_dir($saveDir) && !mkdir($saveDir, 0777, true) && !is_dir($saveDir)) {
        response_error('图片目录创建失败', 500);
    }
    $publicPrefix = '/charging-aiot-php/api/protocol-data/data-center/uploads/' . $dateFolder . '/';

    $savedUrls = [];
    $imageInfo = $_FILES[$field];

    if (is_array($imageInfo['name'])) {
        $count = count($imageInfo['name']);
        for ($i = 0; $i < $count; $i++) {
            if (!isset($imageInfo['error'][$i]) || intval($imageInfo['error'][$i]) !== UPLOAD_ERR_OK) {
                continue;
            }
            $url = save_one_image(
                $imageInfo['tmp_name'][$i],
                $imageInfo['name'][$i],
                $saveDir,
                $publicPrefix
            );
            if ($url !== '') {
                $savedUrls[] = $url;
            }
        }
    } else {
        if (intval($imageInfo['error']) === UPLOAD_ERR_OK) {
            $url = save_one_image(
                $imageInfo['tmp_name'],
                $imageInfo['name'],
                $saveDir,
                $publicPrefix
            );
            if ($url !== '') {
                $savedUrls[] = $url;
            }
        }
    }

    return $savedUrls;
}

try {
    if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
        response_error('请求方法不支持', 405);
    }

    bootstrap_stream_table($pdo);

    $type = get_upload_type();
    if (!in_array($type, [101, 102, 103], true)) {
        response_error('type 参数错误', 400);
    }

    if (!isset($_FILES['file'])) {
        response_error('缺少 file 文件字段', 400);
    }

    $fileInfo = $_FILES['file'];
    if (intval($fileInfo['error']) !== UPLOAD_ERR_OK) {
        response_error('文件上传失败，错误码: ' . $fileInfo['error'], 400);
    }

    $binary = file_get_contents($fileInfo['tmp_name']);
    if ($binary === false) {
        response_error('读取上传文件失败', 500);
    }

    $decodedPayload = [];
    $rawJson = '';
    $textPayload = trim((string) $binary);
    if ($textPayload !== '') {
        $decoded = json_decode($textPayload, true);
        if (is_array($decoded)) {
            $decodedPayload = $decoded;
            $rawJson = $textPayload;
        }
    }

    $cameraId = resolve_camera_id($decodedPayload);
    if ($cameraId === '' && isset($_POST['cameraId'])) {
        $cameraId = trim((string) $_POST['cameraId']);
    }
    if ($cameraId === '' && isset($_POST['camera_id'])) {
        $cameraId = trim((string) $_POST['camera_id']);
    }

    $deviceTimestamp = resolve_device_timestamp($decodedPayload);
    if (isset($_POST['timestamp']) && is_numeric($_POST['timestamp'])) {
        $deviceTimestamp = normalize_timestamp($_POST['timestamp']);
    }

    $imageUrls = save_images();

    $payloadData = !empty($decodedPayload)
        ? $decodedPayload
        : ['raw_file_base64' => base64_encode($binary)];

    if (!empty($imageUrls)) {
        $payloadData['image_urls'] = $imageUrls;
    }

    if ($type === 103) {
        if (!isset($payloadData['track_id']) && isset($payloadData['trackId'])) {
            $payloadData['track_id'] = $payloadData['trackId'];
        }
        if (!isset($payloadData['frame_image']) && !empty($imageUrls)) {
            $payloadData['frame_image'] = $imageUrls[0];
        }
    }

    if ($type === 101) {
        if (!isset($payloadData['objects']) || !is_array($payloadData['objects'])) {
            $payloadData['objects'] = [];
        }
        if (!isset($payloadData['object_count'])) {
            $payloadData['object_count'] = count($payloadData['objects']);
        }
    }

    if ($type === 102) {
        if (!isset($payloadData['person_name']) && isset($payloadData['personName'])) {
            $payloadData['person_name'] = $payloadData['personName'];
        }
        if (!isset($payloadData['track_id']) && isset($payloadData['trackId'])) {
            $payloadData['track_id'] = $payloadData['trackId'];
        }
        if (!isset($payloadData['status'])) {
            $payloadData['status'] = '未知人员';
        }
    }

    $payloadJson = json_encode($payloadData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payloadJson === false) {
        response_error('payload 转换失败', 500);
    }

    $imageUrlsJson = json_encode($imageUrls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($imageUrlsJson === false) {
        $imageUrlsJson = '[]';
    }

    if ($rawJson === '') {
        $rawJson = base64_encode($binary);
    }

    $insertSql = 'INSERT INTO camera_stream_data
                  (msg_type, camera_id, device_timestamp, payload_data, raw_json, image_urls, source_file_name, source_file_size, create_time, modify_time)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';

    $stmt = $pdo->prepare($insertSql);
    $stmt->execute([
        $type,
        $cameraId,
        $deviceTimestamp,
        $payloadJson,
        $rawJson,
        $imageUrlsJson,
        isset($fileInfo['name']) ? $fileInfo['name'] : '',
        isset($fileInfo['size']) ? intval($fileInfo['size']) : 0
    ]);

    response_success([
        'id' => intval($pdo->lastInsertId()),
        'msgType' => $type,
        'cameraId' => $cameraId,
        'deviceTimestamp' => $deviceTimestamp,
        'imageCount' => count($imageUrls)
    ]);
} catch (PDOException $e) {
    response_error('数据库写入失败: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    response_error('处理失败: ' . $e->getMessage(), 500);
}
