<?php

function response_success($data = null, $msg = null)
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
    DeviceAuditLogger::logErrorFile((string) $msg, intval($httpCode), [
        'uri' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
        'method' => isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : ''
    ]);

    http_response_code($httpCode);
    echo json_encode([
        'code' => 0,
        'msg' => $msg,
        'data' => null
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function parse_json_body()
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function normalize_int_or_null($value)
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    return intval($value);
}

function normalize_string($value)
{
    if ($value === null) {
        return '';
    }
    return trim((string) $value);
}

function ping_host($ipAddress, $timeoutSeconds = 1)
{
    $ip = trim((string) $ipAddress);
    if ($ip === '') {
        return false;
    }

    $timeout = intval($timeoutSeconds);
    if ($timeout <= 0) {
        $timeout = 1;
    }

    $escapedIp = escapeshellarg($ip);
    if (stripos(PHP_OS, 'WIN') === 0) {
        $waitMs = max(1000, $timeout * 1000);
        $command = 'ping -n 1 -w ' . $waitMs . ' ' . $escapedIp;
    } else {
        $command = 'ping -c 1 -W ' . $timeout . ' ' . $escapedIp;
    }

    $output = [];
    $code = 1;
    @exec($command, $output, $code);
    return $code === 0;
}

function tcp_port_reachable($ipAddress, $port, $timeoutSeconds = 2)
{
    $ip = trim((string) $ipAddress);
    $safePort = intval($port);
    if ($ip === '' || $safePort <= 0 || $safePort > 65535) {
        return false;
    }

    $errno = 0;
    $errstr = '';
    $timeout = floatval($timeoutSeconds);
    if ($timeout <= 0) {
        $timeout = 2.0;
    }

    $connection = @fsockopen($ip, $safePort, $errno, $errstr, $timeout);
    if (is_resource($connection)) {
        fclose($connection);
        return true;
    }

    return false;
}

function check_device_online_ready($ipAddress, $port)
{
    if (!ping_host($ipAddress, 1)) {
        return false;
    }

    if (!tcp_port_reachable($ipAddress, $port, 2)) {
        return false;
    }

    return true;
}

function get_request_client_ip()
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim((string) $parts[0]);
    }

    if (!empty($_SERVER['REMOTE_ADDR'])) {
        return (string) $_SERVER['REMOTE_ADDR'];
    }

    return '';
}

function write_device_audit(PDO $pdo, array $payload)
{
    $payload['client_ip'] = get_request_client_ip();
    DeviceAuditLogger::logOperation($pdo, $payload);
}

function build_default_play_url($pathName)
{
    $path = trim((string) $pathName);
    if ($path === '') {
        return null;
    }

    $path = ltrim($path, '/');
    return 'http://' . STREAM_SERVER_IP . ':' . STREAM_SERVER_WEBRTC_PORT . '/' . $path . '/';
}

function normalize_datetime_to_timestamp_ms($value)
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_numeric($value)) {
        $raw = intval($value);
        if ($raw <= 0) return null;
        return $raw >= 1000000000000 ? $raw : ($raw * 1000);
    }

    $parsed = strtotime((string) $value);
    if ($parsed === false) {
        return null;
    }
    return intval($parsed) * 1000;
}


function bootstrap_device_tables(PDO $pdo)
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_device_group (
        group_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        group_uuid VARCHAR(40) DEFAULT NULL,
        group_name VARCHAR(100) DEFAULT NULL,
        sort INT DEFAULT 0,
        status_flag TINYINT DEFAULT 1,
        create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
        modify_time DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_group_status (status_flag)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_device (
        device_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        device_uuid VARCHAR(40) NOT NULL,
        group_id BIGINT UNSIGNED DEFAULT NULL,
        device_name VARCHAR(100) DEFAULT NULL,
        brand VARCHAR(64) DEFAULT NULL,
        model VARCHAR(64) DEFAULT NULL,
        protocol_type TINYINT DEFAULT NULL,
        ip_address VARCHAR(128) DEFAULT NULL,
        port INT DEFAULT 554,
        username VARCHAR(64) DEFAULT NULL,
        password VARCHAR(64) DEFAULT NULL,
        location VARCHAR(255) DEFAULT NULL,
        online_status TINYINT DEFAULT 0,
        status_flag TINYINT DEFAULT 1,
        create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
        modify_time DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_device_group (group_id),
        INDEX idx_device_status (status_flag),
        INDEX idx_device_online (online_status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sys_camera_path (
        path_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        path_uuid VARCHAR(40) NOT NULL,
        device_id BIGINT UNSIGNED NOT NULL,
        path_name VARCHAR(128) NOT NULL,
        source_url VARCHAR(255) NOT NULL,
        stream_type TINYINT DEFAULT 1,
        record_enabled TINYINT DEFAULT 0,
        record_path VARCHAR(128) DEFAULT NULL,
        record_format VARCHAR(20) DEFAULT 'fmp4',
        record_part_duration INT DEFAULT NULL,
        status_flag TINYINT DEFAULT 1,
        modify_time DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_path_uuid (path_uuid),
        UNIQUE KEY uk_path_name (path_name),
        INDEX idx_camera_path_device (device_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensure_default_groups(PDO $pdo)
{
    $count = intval($pdo->query('SELECT COUNT(*) FROM sys_device_group')->fetchColumn());
    if ($count > 0) {
        return;
    }

    $sql = 'INSERT INTO sys_device_group (group_uuid, group_name, sort, status_flag, create_time, modify_time)
            VALUES (?, ?, ?, 1, NOW(), NOW())';
    $stmt = $pdo->prepare($sql);

    $defaults = [
        ['default-group-1', 'Default Group', 1],
        ['default-group-2', 'Focus Area', 2],
        ['default-group-3', 'Entrance Exit', 3]
    ];

    foreach ($defaults as $row) {
        $stmt->execute([$row[0], $row[1], $row[2]]);
    }
}

function get_action()
{
    if (isset($_GET['action']) && $_GET['action'] !== '') {
        return trim((string) $_GET['action']);
    }

    $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    if ($uri === '') {
        return '';
    }

    if (preg_match('/\/monitor\/syncCameraConfig$/', $uri)) {
        return 'syncCameraConfig';
    }
    if (preg_match('/\/monitor\/mediamtx\/health$/', $uri)) {
        return 'mediaMtxHealth';
    }
    if (preg_match('/\/monitor\/page\/devices$/', $uri)) {
        return 'pageDevices';
    }
    if (preg_match('/\/monitor\/groups$/', $uri)) {
        return 'groups';
    }
    if (preg_match('/\/monitor\/groups\/devices$/', $uri)) {
        return 'groupDeviceList';
    }
    if (preg_match('/\/monitor\/group$/', $uri)) {
        return 'group';
    }
    if (preg_match('/\/monitor\/workbench\/audit$/', $uri)) {
        return 'workbenchAudit';
    }
    if (preg_match('/\/monitor\/device\/form\/([0-9]+)$/', $uri, $m)) {
        $_GET['id'] = $m[1];
        return 'deviceForm';
    }
    if (preg_match('/\/monitor\/device\/streams\/([0-9]+)$/', $uri, $m)) {
        $_GET['id'] = $m[1];
        return 'deviceStreams';
    }
    if (preg_match('/\/monitor\/device\/delete-check\/([0-9]+)$/', $uri, $m)) {
        $_GET['id'] = $m[1];
        return 'deleteDeviceCheck';
    }
    if (preg_match('/\/monitor\/device$/', $uri)) {
        return 'device';
    }

    return '';
}

function format_unix_time_iso8601($timestamp)
{
    $ts = intval($timestamp);
    if ($ts <= 0) {
        return null;
    }
    return date('Y-m-d H:i:s', $ts);
}

