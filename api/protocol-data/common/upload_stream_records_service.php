<?php
require_once __DIR__ . '/../../../config/cors.php';
require_once __DIR__ . '/../../../../config.php';

header('Content-Type: application/json; charset=utf-8');

function respond_success($data, $msg = null)
{
    echo json_encode(array('code' => 1, 'msg' => $msg, 'data' => $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function respond_error($msg, $httpCode = 500)
{
    http_response_code($httpCode);
    echo json_encode(array('code' => 0, 'msg' => $msg, 'data' => null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function normalize_int_or_null($value)
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return null;
    }
    return intval($value);
}

function normalize_string_or_null($value)
{
    if ($value === null) {
        return null;
    }
    $text = trim(strval($value));
    return $text === '' ? null : $text;
}

function parse_timestamp_list($value)
{
    if ($value === null) {
        return array();
    }
    $raw = strval($value);
    if (trim($raw) === '') {
        return array();
    }
    $parts = preg_split('/[,\s]+/', $raw);
    $timestamps = array();
    foreach ($parts as $part) {
        if ($part === '' || !is_numeric($part)) {
            continue;
        }
        $ts = intval($part);
        if ($ts > 0) {
            $timestamps[$ts] = true;
        }
    }
    return array_map('intval', array_keys($timestamps));
}

function normalize_timestamp_or_null($value)
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_numeric($value)) {
        $ts = intval($value);
        return $ts > 0 ? $ts : null;
    }

    $text = trim(strval($value));
    if ($text === '') {
        return null;
    }
    $parsed = strtotime($text);
    if ($parsed === false) {
        return null;
    }
    return intval($parsed) * 1000;
}

function table_exists(PDO $pdo, $tableName)
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute(array($tableName));
    return intval($stmt->fetchColumn()) > 0;
}

function message_column_exists(PDO $pdo, $tableName, $columnName)
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute(array($tableName, $columnName));
    return intval($stmt->fetchColumn()) > 0;
}

function ensure_query_message_columns(PDO $pdo, $tableName, $columns)
{
    foreach ($columns as $columnName => $definition) {
        if (!message_column_exists($pdo, $tableName, $columnName)) {
            $pdo->exec('ALTER TABLE ' . $tableName . ' ADD COLUMN ' . $columnName . ' ' . $definition);
        }
    }
}

function ensure_query_message_table_exists(PDO $pdo, $protocol)
{
    $tableName = 'message_' . intval($protocol) . '_records';
    if (!table_exists($pdo, $tableName)) {
        return false;
    }

    if (intval($protocol) === 101) {
        ensure_query_message_columns($pdo, $tableName, array(
            'face_id' => 'BIGINT DEFAULT NULL AFTER track_id',
            'frame_face_count' => 'INT DEFAULT NULL AFTER obj_type',
            'frame_width' => 'INT DEFAULT NULL AFTER frame_face_count',
            'frame_height' => 'INT DEFAULT NULL AFTER frame_width',
            'reserved_value' => 'INT DEFAULT NULL AFTER frame_height',
            'protocol_version' => 'INT DEFAULT NULL AFTER conf',
            'frame_header' => 'BIGINT UNSIGNED DEFAULT NULL AFTER protocol_version',
            'frame_tail' => 'BIGINT UNSIGNED DEFAULT NULL AFTER frame_header',
            'crc_value' => 'BIGINT UNSIGNED DEFAULT NULL AFTER frame_tail',
            'frame_length' => 'BIGINT UNSIGNED DEFAULT NULL AFTER crc_value',
            'raw_protocol_hex' => 'LONGTEXT AFTER frame_length',
            'normalized_json' => 'LONGTEXT AFTER raw_protocol_hex',
            'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP AFTER normalized_json',
            'updated_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
            'error_message' => 'VARCHAR(500) DEFAULT NULL AFTER updated_at'
        ));
        return true;
    }

    if (intval($protocol) === 102) {
        ensure_query_message_columns($pdo, $tableName, array(
            'face_id' => 'BIGINT DEFAULT NULL AFTER track_id',
            'information' => 'VARCHAR(255) DEFAULT NULL AFTER obj_type',
            'person_name' => 'VARCHAR(128) DEFAULT NULL AFTER information',
            'status_text' => 'VARCHAR(255) DEFAULT NULL AFTER person_name',
            'feature_data' => 'LONGTEXT AFTER status_text',
            'vector_index' => 'INT DEFAULT NULL AFTER feature_data',
            'embedding_dim' => 'INT DEFAULT NULL AFTER vector_index',
            'embedding_byte_length' => 'INT DEFAULT NULL AFTER embedding_dim',
            'embedding_file_path' => 'VARCHAR(255) DEFAULT NULL AFTER embedding_byte_length',
            'embedding_preview' => 'VARCHAR(255) DEFAULT NULL AFTER embedding_file_path',
            'protocol_version' => 'INT DEFAULT NULL AFTER embedding_preview',
            'frame_header' => 'BIGINT UNSIGNED DEFAULT NULL AFTER protocol_version',
            'frame_tail' => 'BIGINT UNSIGNED DEFAULT NULL AFTER frame_header',
            'crc_value' => 'BIGINT UNSIGNED DEFAULT NULL AFTER frame_tail',
            'frame_length' => 'BIGINT UNSIGNED DEFAULT NULL AFTER crc_value',
            'raw_protocol_hex' => 'LONGTEXT AFTER frame_length',
            'normalized_json' => 'LONGTEXT AFTER raw_protocol_hex',
            'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP AFTER normalized_json',
            'updated_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
            'error_message' => 'VARCHAR(500) DEFAULT NULL AFTER updated_at'
        ));
        return true;
    }

    ensure_query_message_columns($pdo, $tableName, array(
        'face_id' => 'BIGINT DEFAULT NULL AFTER track_id',
        'media_type' => 'INT DEFAULT NULL AFTER obj_type',
        'total_packets' => 'INT DEFAULT 0 AFTER media_type',
        'packet_index' => 'INT DEFAULT 0 AFTER total_packets',
        'media_total_size' => 'BIGINT DEFAULT 0 AFTER packet_index',
        'chunk_length' => 'BIGINT DEFAULT 0 AFTER media_total_size',
        'received_packets' => 'INT DEFAULT 0 AFTER chunk_length',
        'received_media_size' => 'BIGINT DEFAULT 0 AFTER received_packets',
        'is_complete_media' => 'TINYINT(1) DEFAULT 0 AFTER received_media_size',
        'media_kind' => 'VARCHAR(32) DEFAULT NULL AFTER is_complete_media',
        'start_timestamp_ms' => 'BIGINT DEFAULT NULL AFTER media_kind',
        'end_timestamp_ms' => 'BIGINT DEFAULT NULL AFTER start_timestamp_ms',
        'person_count' => 'INT DEFAULT 0 AFTER end_timestamp_ms',
        'car_count' => 'INT DEFAULT 0 AFTER person_count',
        'frame_image_url' => 'VARCHAR(255) DEFAULT NULL AFTER car_count',
        'image_fetch_status' => 'VARCHAR(64) DEFAULT NULL AFTER frame_image_url',
        'local_image_path' => 'VARCHAR(255) DEFAULT NULL AFTER image_fetch_status',
        'image_index' => 'INT DEFAULT NULL AFTER local_image_path',
        'image_byte_length' => 'BIGINT DEFAULT 0 AFTER image_index',
        'protocol_version' => 'INT DEFAULT NULL AFTER image_byte_length',
        'frame_header' => 'BIGINT UNSIGNED DEFAULT NULL AFTER protocol_version',
        'frame_tail' => 'BIGINT UNSIGNED DEFAULT NULL AFTER frame_header',
        'crc_value' => 'BIGINT UNSIGNED DEFAULT NULL AFTER frame_tail',
        'frame_length' => 'BIGINT UNSIGNED DEFAULT NULL AFTER crc_value',
        'raw_protocol_hex' => 'LONGTEXT AFTER frame_length',
        'image_downloaded_at' => 'DATETIME DEFAULT NULL AFTER raw_protocol_hex',
        'error_message' => 'VARCHAR(500) DEFAULT NULL AFTER image_downloaded_at',
        'normalized_json' => 'LONGTEXT AFTER error_message',
        'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP AFTER normalized_json',
        'updated_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at'
    ));
    return true;
}

function camera_code_to_int($cameraId)
{
    if (is_numeric($cameraId)) {
        return intval($cameraId);
    }
    if (preg_match('/(\d+)/', strval($cameraId), $matches)) {
        return intval($matches[1]);
    }
    return 0;
}

function json_decode_array($value)
{
    if ($value === null || $value === '') {
        return array();
    }
    $decoded = json_decode(strval($value), true);
    return is_array($decoded) ? $decoded : array();
}

function compact_hex($value, $maxLength = 160)
{
    $text = preg_replace('/\s+/', '', strval($value));
    if (strlen($text) <= $maxLength) {
        return $text;
    }
    return substr($text, 0, $maxLength);
}

function build_public_media_url($relativePath)
{
    $path = trim(strval($relativePath));
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path) === 1) {
        return $path;
    }
    return '/' . ltrim($path, '/');
}

function build_record_media_url($protocolId, $recordId)
{
    return '/charging-aiot-php/api/protocol-data/data-center/media.php?protocol=' . intval($protocolId) . '&record_id=' . intval($recordId);
}

function is_visual_target_row($target)
{
    if (!is_array($target)) {
        return false;
    }
    $x1 = intval(isset($target['x1']) ? $target['x1'] : 0);
    $y1 = intval(isset($target['y1']) ? $target['y1'] : 0);
    $x2 = intval(isset($target['x2']) ? $target['x2'] : 0);
    $y2 = intval(isset($target['y2']) ? $target['y2'] : 0);
    return !($x1 === 0 && $y1 === 0 && $x2 === 0 && $y2 === 0);
}

function base64_image_from_path($path)
{
    $text = trim(strval($path));
    if ($text === '') {
        return '';
    }

    $normalized = ltrim(str_replace('\\', '/', $text), '/');
    $projectRoot = dirname(__DIR__, 4);
    $phpRoot = dirname(__DIR__, 3);

    $candidates = array(
        $text,
        $projectRoot . '/' . $normalized,
        $phpRoot . '/' . $normalized
    );
    if (strpos($normalized, 'charging-aiot-php/') === 0) {
        $candidates[] = $projectRoot . '/' . substr($normalized, strlen('charging-aiot-php/'));
    }
    $candidates = array_values(array_unique($candidates));

    foreach ($candidates as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            $binary = file_get_contents($candidate);
            if ($binary !== false) {
                return 'data:image/jpeg;base64,' . base64_encode($binary);
            }
        }
    }

    return '';
}

function unique_int_list($values)
{
    $set = array();
    foreach ((array) $values as $value) {
        $id = intval($value);
        if ($id > 0) {
            $set[$id] = true;
        }
    }
    return array_map('intval', array_keys($set));
}

function build_where($cameraId, $timestamps = array(), $startEventTime = null, $endEventTime = null)
{
    $where = ' WHERE 1=1';
    $params = array();
    if ($cameraId !== null) {
        $where .= ' AND camera_id = ?';
        $params[] = $cameraId;
    }
    if (!empty($timestamps)) {
        $placeholders = implode(',', array_fill(0, count($timestamps), '?'));
        $where .= ' AND event_timestamp_ms IN (' . $placeholders . ')';
        foreach ($timestamps as $timestamp) {
            $params[] = intval($timestamp);
        }
    }
    if ($startEventTime !== null) {
        $where .= ' AND event_timestamp_ms >= ?';
        $params[] = intval($startEventTime);
    }
    if ($endEventTime !== null) {
        $where .= ' AND event_timestamp_ms <= ?';
        $params[] = intval($endEventTime);
    }
    return array($where, $params);
}

function count_table(PDO $pdo, $tableName, $cameraId, $timestamps = array(), $startEventTime = null, $endEventTime = null)
{
    if (!table_exists($pdo, $tableName)) {
        return 0;
    }
    list($where, $params) = build_where($cameraId, $timestamps, $startEventTime, $endEventTime);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $tableName . $where);
    $stmt->execute($params);
    return intval($stmt->fetchColumn());
}

function ensure_protocol_quality_summary_table_exists(PDO $pdo)
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS protocol_quality_summary (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        protocol_id INT NOT NULL,
        camera_id VARCHAR(128) NOT NULL,
        batch_id BIGINT UNSIGNED NOT NULL,
        record_total INT NOT NULL DEFAULT 0,
        error_count INT NOT NULL DEFAULT 0,
        missing_count INT NOT NULL DEFAULT 0,
        missing_frames INT NOT NULL DEFAULT 0,
        first_event_timestamp_ms BIGINT DEFAULT NULL,
        last_event_timestamp_ms BIGINT DEFAULT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_protocol_camera_batch (protocol_id, camera_id, batch_id),
        KEY idx_protocol_updated (protocol_id, updated_at),
        KEY idx_protocol_camera_time (protocol_id, camera_id, last_event_timestamp_ms)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function query_quality_summary_from_table(PDO $pdo, $protocol, $cameraId, $startEventTime = null, $endEventTime = null)
{
    ensure_protocol_quality_summary_table_exists($pdo);
    $sql = 'SELECT
                COALESCE(SUM(error_count), 0) error_count,
                COALESCE(SUM(missing_count), 0) missing_count,
                COALESCE(SUM(missing_frames), 0) missing_frames
            FROM protocol_quality_summary
            WHERE protocol_id = ?';
    $params = array(intval($protocol));

    if ($cameraId !== null) {
        $sql .= ' AND camera_id = ?';
        $params[] = $cameraId;
    }
    if ($startEventTime !== null) {
        $sql .= ' AND last_event_timestamp_ms >= ?';
        $params[] = intval($startEventTime);
    }
    if ($endEventTime !== null) {
        $sql .= ' AND first_event_timestamp_ms <= ?';
        $params[] = intval($endEventTime);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return array(
        'error_count' => intval(isset($row['error_count']) ? $row['error_count'] : 0),
        'missing_count' => intval(isset($row['missing_count']) ? $row['missing_count'] : 0),
        'missing_frames' => intval(isset($row['missing_frames']) ? $row['missing_frames'] : 0)
    );
}

function query_data_center_overview(PDO $pdo)
{
    $protocolTotals = array(101 => 0, 102 => 0, 103 => 0);
    foreach (array(101, 102, 103) as $pid) {
        $protocolTotals[$pid] = count_table($pdo, 'message_' . $pid . '_records', null, array(), null, null);
    }

    $cameraSelects = array();
    foreach (array(101, 102, 103) as $pid) {
        $table = 'message_' . $pid . '_records';
        if (table_exists($pdo, $table)) {
            $cameraSelects[] = "SELECT camera_id FROM $table WHERE camera_id IS NOT NULL AND camera_id <> ''";
        }
    }

    $cameraCount = 0;
    if (!empty($cameraSelects)) {
        $cameraStmt = $pdo->query('SELECT COUNT(DISTINCT camera_id) AS total FROM (' . implode(' UNION ALL ', $cameraSelects) . ') t');
        $cameraCount = intval($cameraStmt ? $cameraStmt->fetchColumn() : 0);
    }

    $targetCount = 0;
    if (table_exists($pdo, 'message_101_records')) {
        $stmt101 = $pdo->query("SELECT COALESCE(SUM(CASE
            WHEN NOT (IFNULL(x1, 0) = 0 AND IFNULL(y1, 0) = 0 AND IFNULL(x2, 0) = 0 AND IFNULL(y2, 0) = 0) THEN 1
            ELSE GREATEST(COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(normalized_json, '$.frame_target_count')) AS SIGNED), 0), 0)
        END), 0) AS total FROM message_101_records");
        $targetCount = intval($stmt101 ? $stmt101->fetchColumn() : 0);
    }

    $vectorBytes = 0;
    if (table_exists($pdo, 'message_102_records')) {
        $stmt102 = $pdo->query('SELECT COALESCE(SUM(embedding_byte_length), 0) AS total FROM message_102_records');
        $vectorBytes = intval($stmt102 ? $stmt102->fetchColumn() : 0);
    }

    $imageSuccess = 0;
    if (table_exists($pdo, 'message_103_records')) {
        $stmt103 = $pdo->query("SELECT COUNT(*) AS total FROM message_103_records WHERE COALESCE(image_fetch_status, '') = 'success'");
        $imageSuccess = intval($stmt103 ? $stmt103->fetchColumn() : 0);
    }

    $latestEventTime = 0;
    $latestReceivedTime = '';
    $latestEventSelects = array();
    $latestReceivedSelects = array();
    foreach (array(101, 102, 103) as $pid) {
        $table = 'message_' . $pid . '_records';
        if (table_exists($pdo, $table)) {
            $latestEventSelects[] = "SELECT MAX(event_timestamp_ms) AS latest_event_time FROM $table";
            $latestReceivedSelects[] = "SELECT MAX(created_at) AS latest_received_time FROM $table";
        }
    }
    if (!empty($latestEventSelects)) {
        $eventStmt = $pdo->query('SELECT MAX(latest_event_time) AS latest_event_time FROM (' . implode(' UNION ALL ', $latestEventSelects) . ') t');
        $latestEventTime = intval($eventStmt ? $eventStmt->fetchColumn() : 0);
    }
    if (!empty($latestReceivedSelects)) {
        $receivedStmt = $pdo->query('SELECT MAX(latest_received_time) AS latest_received_time FROM (' . implode(' UNION ALL ', $latestReceivedSelects) . ') t');
        $latestReceivedTime = strval($receivedStmt ? $receivedStmt->fetchColumn() : '');
    }

    return array(
        'protocol_totals' => $protocolTotals,
        'summary' => array(
            'total_records' => intval($protocolTotals[101] + $protocolTotals[102] + $protocolTotals[103]),
            'camera_count' => $cameraCount,
            'target_count' => $targetCount,
            'vector_bytes' => $vectorBytes,
            'image_success' => $imageSuccess,
            'latest_event_time' => $latestEventTime,
            'latest_received_time' => $latestReceivedTime
        )
    );
}

function query_camera_options(PDO $pdo, $protocol = null)
{
    $protocolValue = normalize_int_or_null($protocol);
    if ($protocolValue !== null && !in_array($protocolValue, array(101, 102, 103), true)) {
        throw new InvalidArgumentException('protocol must be 101, 102 or 103');
    }

    $protocols = $protocolValue === null ? array(101, 102, 103) : array($protocolValue);
    $selects = array();
    foreach ($protocols as $pid) {
        $table = 'message_' . intval($pid) . '_records';
        if (table_exists($pdo, $table)) {
            $selects[] = 'SELECT DISTINCT camera_id FROM ' . $table . ' WHERE camera_id IS NOT NULL AND camera_id <> \'\'';
        }
    }

    if (empty($selects)) {
        return array();
    }

    $stmt = $pdo->query('SELECT DISTINCT camera_id FROM (' . implode(' UNION ALL ', $selects) . ') t ORDER BY camera_id ASC');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
    $options = array();
    foreach ($rows as $row) {
        $cameraId = strval(isset($row['camera_id']) ? $row['camera_id'] : '');
        if ($cameraId === '') {
            continue;
        }
        $options[] = array(
            'camera_id' => $cameraId,
            'cam_id' => camera_code_to_int($cameraId),
            'label' => $cameraId,
            'value' => $cameraId
        );
    }
    return $options;
}

function build_select_sql($protocol, $cameraId, $timestamps, $startEventTime, $endEventTime, $includePayload, &$params)
{
    $table = 'message_' . $protocol . '_records';
    list($where, $whereParams) = build_where($cameraId, $timestamps, $startEventTime, $endEventTime);
    $params = array_merge($params, $whereParams);
    $featureDataSql = $includePayload ? 'feature_data' : 'NULL feature_data';
    $rawHexPreviewSql = 'LEFT(COALESCE(raw_protocol_hex, \'\'), 512) raw_protocol_hex_preview';
    $rawHexSizeSql = 'CHAR_LENGTH(COALESCE(raw_protocol_hex, \'\')) raw_protocol_hex_size';

    if ($protocol === 101) {
        return "SELECT id, 101 protocol_id, batch_id, camera_id, event_timestamp_ms, track_id, face_id, obj_type,
                frame_face_count, frame_width, frame_height, reserved_value,
                NULL information, NULL person_name, NULL status_text, NULL feature_data, NULL embedding_dim,
                NULL embedding_byte_length, NULL embedding_file_path, NULL embedding_preview,
                NULL media_type, NULL total_packets, NULL packet_index, NULL media_total_size, NULL chunk_length,
                NULL received_packets, NULL received_media_size, NULL is_complete_media, NULL media_kind,
                NULL start_timestamp_ms, NULL end_timestamp_ms, NULL person_count, NULL car_count, NULL frame_image_url, NULL image_fetch_status,
                NULL local_image_path, NULL image_byte_length, x1, y1, x2, y2, conf, object_index,
                NULL error_message, quality_status,
                protocol_version, frame_header, frame_tail, crc_value, frame_length, " . $rawHexPreviewSql . ", " . $rawHexSizeSql . ",
                normalized_json, created_at, updated_at
                FROM message_101_records" . $where;
    }

    if ($protocol === 102) {
        return "SELECT id, 102 protocol_id, batch_id, camera_id, event_timestamp_ms, track_id, face_id, obj_type,
                NULL frame_face_count, NULL frame_width, NULL frame_height, NULL reserved_value,
                information, person_name, status_text, " . $featureDataSql . ", embedding_dim, embedding_byte_length,
                embedding_file_path, embedding_preview, NULL person_count, NULL car_count, NULL frame_image_url,
                NULL media_type, NULL total_packets, NULL packet_index, NULL media_total_size, NULL chunk_length,
                NULL received_packets, NULL received_media_size, NULL is_complete_media, NULL media_kind,
                NULL start_timestamp_ms, NULL end_timestamp_ms, NULL image_fetch_status, NULL local_image_path, NULL image_byte_length,
                NULL x1, NULL y1, NULL x2, NULL y2, NULL conf, vector_index object_index,
                NULL error_message, quality_status,
                protocol_version, frame_header, frame_tail, crc_value, frame_length, " . $rawHexPreviewSql . ", " . $rawHexSizeSql . ",
                normalized_json, created_at, updated_at
                FROM message_102_records" . $where;
    }

    return "SELECT id, 103 protocol_id, batch_id, camera_id, event_timestamp_ms, track_id, face_id, obj_type,
            NULL frame_face_count, NULL frame_width, NULL frame_height, NULL reserved_value,
            NULL information, NULL person_name, NULL status_text, NULL feature_data, NULL embedding_dim,
            NULL embedding_byte_length, NULL embedding_file_path, NULL embedding_preview,
            media_type, total_packets, packet_index, media_total_size, chunk_length,
            received_packets, received_media_size, is_complete_media, media_kind, start_timestamp_ms, end_timestamp_ms,
            person_count, car_count, frame_image_url, image_fetch_status, local_image_path, image_byte_length,
            NULL x1, NULL y1, NULL x2, NULL y2, NULL conf, image_index object_index,
            error_message, quality_status,
            protocol_version, frame_header, frame_tail, crc_value, frame_length, " . $rawHexPreviewSql . ", " . $rawHexSizeSql . ",
            normalized_json, created_at, updated_at
            FROM message_103_records" . $where;
}

function normalize_record($row, $includePayload)
{
    $protocolId = intval($row['protocol_id']);
    $recordId = intval($row['id']);
    $cameraId = strval($row['camera_id']);
    $normalized = json_decode_array(isset($row['normalized_json']) ? $row['normalized_json'] : '');

    $mediaEndpoint = build_record_media_url($protocolId, $recordId);
    $common = array(
        'record_id' => $recordId,
        'cam_id' => camera_code_to_int($cameraId),
        'camera_id' => $cameraId,
        'protocol_id' => $protocolId,
        'track_id' => intval(isset($row['track_id']) ? $row['track_id'] : 0),
        'face_id' => intval(isset($row['face_id']) ? $row['face_id'] : (isset($row['track_id']) ? $row['track_id'] : 0)),
        'timestamp' => intval($row['event_timestamp_ms']),
        'batch_id' => intval($row['batch_id']),
        'source_file_name' => isset($normalized['raw_file_path']) ? strval($normalized['raw_file_path']) : '',
        'source_file_size' => intval(isset($row['frame_length']) ? $row['frame_length'] : 0),
        'create_time' => isset($row['created_at']) ? strval($row['created_at']) : '',
        'frame_header' => intval(isset($row['frame_header']) ? $row['frame_header'] : 0),
        'frame_tail' => intval(isset($row['frame_tail']) ? $row['frame_tail'] : 0),
        'crc_value' => intval(isset($row['crc_value']) ? $row['crc_value'] : 0),
        'frame_length' => intval(isset($row['frame_length']) ? $row['frame_length'] : 0),
        'frame_seq' => intval(isset($normalized['frame_seq']) ? $normalized['frame_seq'] : (isset($normalized['frame_index']) ? $normalized['frame_index'] : 0)),
        'error_message' => strval(isset($row['error_message']) ? $row['error_message'] : ''),
        'quality_status' => strval(isset($row['quality_status']) ? $row['quality_status'] : ''),
        'raw_protocol_hex_preview' => compact_hex(isset($row['raw_protocol_hex_preview']) ? $row['raw_protocol_hex_preview'] : (isset($row['raw_protocol_hex']) ? $row['raw_protocol_hex'] : '')),
        'raw_protocol_hex_size' => intval(isset($row['raw_protocol_hex_size']) ? $row['raw_protocol_hex_size'] : strlen(strval(isset($row['raw_protocol_hex']) ? $row['raw_protocol_hex'] : ''))),
        'normalized_json' => $normalized
    );

    if ($protocolId === 101) {
        $target = array(
            'type' => intval(isset($row['obj_type']) ? $row['obj_type'] : 0),
            'tid' => intval(isset($row['track_id']) ? $row['track_id'] : 0),
            'face_id' => intval(isset($row['face_id']) ? $row['face_id'] : (isset($row['track_id']) ? $row['track_id'] : 0)),
            'x1' => intval(isset($row['x1']) ? $row['x1'] : 0),
            'y1' => intval(isset($row['y1']) ? $row['y1'] : 0),
            'x2' => intval(isset($row['x2']) ? $row['x2'] : 0),
            'y2' => intval(isset($row['y2']) ? $row['y2'] : 0),
            'conf' => isset($row['conf']) ? intval($row['conf']) : null,
            'object_index' => intval(isset($row['object_index']) ? $row['object_index'] : 0)
        );

        $visualCount = is_visual_target_row($target) ? 1 : 0;
        $frameTargetCount = intval(isset($normalized['frame_target_count']) ? $normalized['frame_target_count'] : 0);
        if ($frameTargetCount < 0) {
            $frameTargetCount = 0;
        }
        $targetCount = $visualCount > 0 ? $visualCount : $frameTargetCount;

        $common['details'] = array(
            'count' => $targetCount,
            'target_count' => $targetCount,
            'obj_type' => intval(isset($row['obj_type']) ? $row['obj_type'] : 0),
            'frame_face_count' => intval(isset($row['frame_face_count']) ? $row['frame_face_count'] : (isset($normalized['frame_face_count']) ? $normalized['frame_face_count'] : $frameTargetCount)),
            'frame_width' => intval(isset($row['frame_width']) ? $row['frame_width'] : (isset($normalized['frame_width']) ? $normalized['frame_width'] : 0)),
            'frame_height' => intval(isset($row['frame_height']) ? $row['frame_height'] : (isset($normalized['frame_height']) ? $normalized['frame_height'] : 0)),
            'reserved_value' => intval(isset($row['reserved_value']) ? $row['reserved_value'] : (isset($normalized['reserved_value']) ? $normalized['reserved_value'] : 0)),
            'targets' => array($target),
            'payload_hex_rebuilt' => compact_hex(isset($row['raw_protocol_hex_preview']) ? $row['raw_protocol_hex_preview'] : (isset($row['raw_protocol_hex']) ? $row['raw_protocol_hex'] : ''), 512),
            'frame_target_count' => $frameTargetCount
        );
        return $common;
    }

    if ($protocolId === 102) {
        $feature = strval(isset($row['feature_data']) ? $row['feature_data'] : '');
        $payloadSize = intval(isset($row['embedding_byte_length']) ? $row['embedding_byte_length'] : 0);
        $featureBase64 = $feature;
        $previewBase64 = $featureBase64 === '' ? '' : substr($featureBase64, 0, 96);

        $frameVectorCount = intval(isset($normalized['frame_vector_count']) ? $normalized['frame_vector_count'] : 0);
        if ($frameVectorCount < 0) {
            $frameVectorCount = 0;
        }
        $hasVector = ($payloadSize >= 2048) || ($previewBase64 !== '');
        $targetCount = $hasVector ? ($frameVectorCount > 0 ? $frameVectorCount : 1) : 0;

        $common['details'] = array(
            'payload_size' => $payloadSize,
            'target_count' => $targetCount,
            'obj_type' => intval(isset($row['obj_type']) ? $row['obj_type'] : 0),
            'face_id' => intval(isset($row['face_id']) ? $row['face_id'] : (isset($row['track_id']) ? $row['track_id'] : 0)),
            'vector_base64_preview' => $previewBase64,
            'vector_hex_preview' => compact_hex(isset($row['raw_protocol_hex_preview']) ? $row['raw_protocol_hex_preview'] : (isset($row['raw_protocol_hex']) ? $row['raw_protocol_hex'] : ''), 96),
            'vector_payload_hex_preview' => compact_hex(isset($row['raw_protocol_hex_preview']) ? $row['raw_protocol_hex_preview'] : (isset($row['raw_protocol_hex']) ? $row['raw_protocol_hex'] : ''), 180),
            'embedding_dim' => intval(isset($row['embedding_dim']) ? $row['embedding_dim'] : 0),
            'embedding_file_path' => strval(isset($row['embedding_file_path']) ? $row['embedding_file_path'] : ''),
            'embedding_preview' => strval(isset($row['embedding_preview']) ? $row['embedding_preview'] : ''),
            'information' => strval(isset($row['information']) ? $row['information'] : ''),
            'person_name' => strval(isset($row['person_name']) ? $row['person_name'] : ''),
            'status' => strval(isset($row['status_text']) ? $row['status_text'] : '')
        );
        if ($includePayload && $featureBase64 !== '') {
            $common['details']['vector_base64'] = $featureBase64;
        }
        return $common;
    }

    $mediaKind = strval(isset($normalized['media_kind']) ? $normalized['media_kind'] : 'image');
    $imageDataUrl = '';
    if ($includePayload && $mediaKind === 'image') {
        $imageDataUrl = base64_image_from_path(isset($row['local_image_path']) ? $row['local_image_path'] : '');
    }

    $animalCount = 0;
    if (isset($normalized['animal_count'])) {
        $animalCount = intval($normalized['animal_count']);
    } elseif (isset($normalized['frame_animal_count'])) {
        $animalCount = intval($normalized['frame_animal_count']);
    } elseif (intval(isset($row['obj_type']) ? $row['obj_type'] : -1) === 1
        && intval(isset($row['person_count']) ? $row['person_count'] : 0) === 0
        && intval(isset($row['car_count']) ? $row['car_count'] : 0) === 0) {
        $animalCount = 1;
    }

    $localImagePath = strval(isset($row['local_image_path']) ? $row['local_image_path'] : '');
    $mediaUrl = $localImagePath !== '' ? $mediaEndpoint : '';

    $common['details'] = array(
        'payload_size' => intval(isset($row['image_byte_length']) ? $row['image_byte_length'] : 0),
        'tid' => intval(isset($row['track_id']) ? $row['track_id'] : (isset($normalized['track_id']) ? $normalized['track_id'] : 0)),
        'face_id' => intval(isset($row['face_id']) ? $row['face_id'] : (isset($normalized['face_id']) ? $normalized['face_id'] : (isset($row['track_id']) ? $row['track_id'] : 0))),
        'image_hex_preview' => compact_hex(isset($row['raw_protocol_hex_preview']) ? $row['raw_protocol_hex_preview'] : (isset($row['raw_protocol_hex']) ? $row['raw_protocol_hex'] : ''), 180),
        'base64_image' => $imageDataUrl,
        'image_data_url' => $imageDataUrl,
        'frame_image_url' => $mediaUrl,
        'image_fetch_status' => strval(isset($row['image_fetch_status']) ? $row['image_fetch_status'] : ''),
        'local_image_path' => $localImagePath,
        'media_kind' => strval(isset($row['media_kind']) ? $row['media_kind'] : $mediaKind),
        'media_url' => $mediaUrl,
        'payload_type' => intval(isset($normalized['payload_type']) ? $normalized['payload_type'] : (isset($row['media_type']) ? $row['media_type'] : 0)),
        'media_type' => intval(isset($row['media_type']) ? $row['media_type'] : (isset($normalized['payload_type']) ? $normalized['payload_type'] : 0)),
        'start_timestamp' => intval(isset($row['start_timestamp_ms']) ? $row['start_timestamp_ms'] : (isset($normalized['start_timestamp']) ? $normalized['start_timestamp'] : 0)),
        'end_timestamp' => intval(isset($row['end_timestamp_ms']) ? $row['end_timestamp_ms'] : (isset($normalized['end_timestamp']) ? $normalized['end_timestamp'] : 0)),
        'total_packets' => intval(isset($row['total_packets']) ? $row['total_packets'] : (isset($normalized['total_packets']) ? $normalized['total_packets'] : 0)),
        'packet_index' => intval(isset($row['packet_index']) ? $row['packet_index'] : (isset($normalized['packet_index']) ? $normalized['packet_index'] : 0)),
        'received_packets' => intval(isset($row['received_packets']) ? $row['received_packets'] : (isset($normalized['received_packets']) ? $normalized['received_packets'] : 0)),
        'media_total_size' => intval(isset($row['media_total_size']) ? $row['media_total_size'] : (isset($normalized['media_total_size']) ? $normalized['media_total_size'] : 0)),
        'chunk_length' => intval(isset($row['chunk_length']) ? $row['chunk_length'] : (isset($normalized['chunk_length']) ? $normalized['chunk_length'] : 0)),
        'received_media_size' => intval(isset($row['received_media_size']) ? $row['received_media_size'] : (isset($normalized['received_media_size']) ? $normalized['received_media_size'] : 0)),
        'is_complete_media' => intval(isset($row['is_complete_media']) ? $row['is_complete_media'] : (!empty($normalized['is_complete_media']) ? 1 : 0)) === 1,
        'source_file_name' => strval(isset($normalized['source_file_name']) ? $normalized['source_file_name'] : ''),
        'error_message' => strval(isset($row['error_message']) ? $row['error_message'] : ''),
        'person_count' => intval(isset($row['person_count']) ? $row['person_count'] : 0),
        'animal_count' => max(0, $animalCount),
        'car_count' => intval(isset($row['car_count']) ? $row['car_count'] : 0)
    );
    return $common;
}

function media_content_type_from_path($path)
{
    $ext = strtolower(pathinfo(strval($path), PATHINFO_EXTENSION));
    if ($ext === 'mp4') return 'video/mp4';
    if ($ext === 'jpg' || $ext === 'jpeg') return 'image/jpeg';
    if ($ext === 'png') return 'image/png';
    if ($ext === 'bin') return 'application/octet-stream';
    return 'application/octet-stream';
}

function resolve_media_file_path($path)
{
    $text = trim(strval($path));
    if ($text === '') {
        return null;
    }

    $normalized = ltrim(str_replace('\\', '/', $text), '/');
    $projectRoot = dirname(__DIR__, 4);
    $phpRoot = dirname(__DIR__, 3);

    $candidates = array(
        $text,
        $projectRoot . '/' . $normalized,
        $phpRoot . '/' . $normalized
    );
    if (strpos($normalized, 'charging-aiot-php/') === 0) {
        $candidates[] = $projectRoot . '/' . substr($normalized, strlen('charging-aiot-php/'));
    }

    foreach (array_values(array_unique($candidates)) as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function stream_file_with_range($filePath, $contentType)
{
    $size = filesize($filePath);
    if ($size === false) {
        throw new RuntimeException('failed to read media file size');
    }

    $start = 0;
    $end = $size - 1;
    $statusCode = 200;

    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/i', strval($_SERVER['HTTP_RANGE']), $matches) === 1) {
        $rangeStart = $matches[1];
        $rangeEnd = $matches[2];

        if ($rangeStart === '' && $rangeEnd === '') {
            respond_error('invalid range', 416);
        }

        if ($rangeStart === '') {
            $suffixLength = intval($rangeEnd);
            if ($suffixLength > 0) {
                $start = max(0, $size - $suffixLength);
            }
        } else {
            $start = intval($rangeStart);
        }

        if ($rangeEnd !== '') {
            $end = intval($rangeEnd);
        }

        if ($start > $end || $start >= $size) {
            header('Content-Range: bytes */' . $size);
            respond_error('requested range not satisfiable', 416);
        }

        $end = min($end, $size - 1);
        $statusCode = 206;
    }

    $length = $end - $start + 1;
    if (ob_get_level() > 0) {
        @ob_end_clean();
    }

    http_response_code($statusCode);
    header('Content-Type: ' . $contentType);
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . $length);
    header('Cache-Control: public, max-age=86400');
    header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
    if ($statusCode === 206) {
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }

    if (strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET') === 'HEAD') {
        exit;
    }

    $fp = fopen($filePath, 'rb');
    if ($fp === false) {
        throw new RuntimeException('failed to open media file');
    }

    try {
        fseek($fp, $start);
        $remaining = $length;
        while ($remaining > 0 && !feof($fp)) {
            $chunkSize = $remaining > 8192 ? 8192 : $remaining;
            $buffer = fread($fp, $chunkSize);
            if ($buffer === false) {
                break;
            }
            echo $buffer;
            flush();
            $remaining -= strlen($buffer);
            if (connection_aborted()) {
                break;
            }
        }
    } finally {
        fclose($fp);
    }
    exit;
}

function build_linked_packets($records)
{
    $map = array();
    foreach ($records as $record) {
        $cameraKey = isset($record['camera_id']) ? strval($record['camera_id']) : strval($record['cam_id']);
        $timestamp = intval($record['timestamp']);
        $linkKey = $cameraKey . '_' . $timestamp;

        if (!isset($map[$linkKey])) {
            $map[$linkKey] = array(
                'link_key' => $linkKey,
                'cam_id' => intval($record['cam_id']),
                'camera_id' => $cameraKey,
                'timestamp' => $timestamp,
                'create_time' => strval($record['create_time']),
                'protocol_101_record_ids' => array(),
                'protocol_102_record_ids' => array(),
                'protocol_103_record_ids' => array(),
                'target_total' => 0
            );
        }

        $packet = &$map[$linkKey];
        if (strval($record['create_time']) > $packet['create_time']) {
            $packet['create_time'] = strval($record['create_time']);
        }

        $protocolId = intval($record['protocol_id']);
        if ($protocolId === 101) {
            $packet['protocol_101_record_ids'][] = intval($record['record_id']);
            $details = isset($record['details']) && is_array($record['details']) ? $record['details'] : array();
            $packet['target_total'] += intval(isset($details['count']) ? $details['count'] : 0);
        } elseif ($protocolId === 102) {
            $packet['protocol_102_record_ids'][] = intval($record['record_id']);
        } elseif ($protocolId === 103) {
            $packet['protocol_103_record_ids'][] = intval($record['record_id']);
        }
    }

    $packets = array();
    foreach ($map as $packet) {
        $ids101 = unique_int_list($packet['protocol_101_record_ids']);
        $ids102 = unique_int_list($packet['protocol_102_record_ids']);
        $ids103 = unique_int_list($packet['protocol_103_record_ids']);
        rsort($ids101);
        rsort($ids102);
        rsort($ids103);

        $protocols = array();
        if (!empty($ids101)) $protocols[] = 101;
        if (!empty($ids102)) $protocols[] = 102;
        if (!empty($ids103)) $protocols[] = 103;

        $packet['protocol_101_record_ids'] = $ids101;
        $packet['protocol_102_record_ids'] = $ids102;
        $packet['protocol_103_record_ids'] = $ids103;
        $packet['protocols'] = $protocols;
        $packet['vector_total'] = count($ids102);
        $packet['image_total'] = count($ids103);
        $packets[] = $packet;
    }

    usort($packets, function ($a, $b) {
        return intval($b['timestamp']) - intval($a['timestamp']);
    });
    return $packets;
}

function row_quality_has_error($record)
{
    $protocolId = intval(isset($record['protocol_id']) ? $record['protocol_id'] : 0);
    $details = isset($record['details']) && is_array($record['details']) ? $record['details'] : array();

    $errorMessage = strval(isset($record['error_message']) ? $record['error_message'] : '');
    if ($errorMessage !== '') {
        return true;
    }
    if ($protocolId === 103) {
        $imageStatus = strval(isset($details['image_fetch_status']) ? $details['image_fetch_status'] : '');
        if ($imageStatus !== '' && strtolower($imageStatus) !== 'success') {
            return true;
        }
        $detailError = strval(isset($details['error_message']) ? $details['error_message'] : '');
        if ($detailError !== '') {
            return true;
        }
    }

    return false;
}

function build_quality_state_map($records, &$errorCount = 0, &$missingCount = 0, &$missingFrames = 0)
{
    $errorIds = array();
    $missingIds = array();
    $frameGroups = array();

    foreach ($records as $record) {
        $recordId = intval(isset($record['record_id']) ? $record['record_id'] : 0);
        if ($recordId <= 0) continue;
        if (row_quality_has_error($record)) {
            $errorIds[$recordId] = true;
        }

        $normalized = isset($record['normalized_json']) && is_array($record['normalized_json']) ? $record['normalized_json'] : array();
        $seq = null;
        if (isset($normalized['frame_seq']) && is_numeric($normalized['frame_seq'])) {
            $seq = intval($normalized['frame_seq']);
        } elseif (isset($normalized['frame_index']) && is_numeric($normalized['frame_index'])) {
            $seq = intval($normalized['frame_index']);
        } elseif (isset($record['frame_seq']) && is_numeric($record['frame_seq'])) {
            $seq = intval($record['frame_seq']);
        }
        if ($seq === null) continue;

        $camera = strval(isset($record['camera_id']) ? $record['camera_id'] : '');
        $batchId = intval(isset($record['batch_id']) ? $record['batch_id'] : 0);
        $protocolId = intval(isset($record['protocol_id']) ? $record['protocol_id'] : 0);
        $timestamp = intval(isset($record['timestamp']) ? $record['timestamp'] : 0);
        $groupKey = $protocolId . '::' . $camera . '::' . $batchId;
        $frameKey = $seq . '::' . $timestamp;

        if (!isset($frameGroups[$groupKey])) {
            $frameGroups[$groupKey] = array();
        }
        if (!isset($frameGroups[$groupKey][$frameKey])) {
            $frameGroups[$groupKey][$frameKey] = array(
                'seq' => $seq,
                'timestamp' => $timestamp,
                'record_ids' => array()
            );
        }
        $frameGroups[$groupKey][$frameKey]['record_ids'][$recordId] = true;
    }

    foreach ($frameGroups as $framesByKey) {
        $frames = array_values($framesByKey);
        usort($frames, function ($a, $b) {
            if (intval($a['timestamp']) === intval($b['timestamp'])) {
                return intval($a['seq']) - intval($b['seq']);
            }
            return intval($a['timestamp']) - intval($b['timestamp']);
        });

        $prevSeq = null;
        $prevTs = null;
        foreach ($frames as $frame) {
            $seq = intval($frame['seq']);
            $ts = intval($frame['timestamp']);
            if ($prevSeq !== null) {
                if ($seq > $prevSeq + 1) {
                    $missingFrames += ($seq - $prevSeq - 1);
                    foreach ($frame['record_ids'] as $rid => $_) {
                        $missingIds[intval($rid)] = true;
                    }
                } elseif ($seq < $prevSeq || ($seq === $prevSeq && $ts !== $prevTs)) {
                    foreach ($frame['record_ids'] as $rid => $_) {
                        $errorIds[intval($rid)] = true;
                    }
                }
            }
            $prevSeq = $seq;
            $prevTs = $ts;
        }
    }

    $stateMap = array();
    foreach ($records as $record) {
        $recordId = intval(isset($record['record_id']) ? $record['record_id'] : 0);
        if ($recordId <= 0) continue;
        $hasError = isset($errorIds[$recordId]);
        $hasMissing = isset($missingIds[$recordId]);
        if ($hasError && $hasMissing) $stateMap[$recordId] = 'error_missing';
        elseif ($hasError) $stateMap[$recordId] = 'error';
        elseif ($hasMissing) $stateMap[$recordId] = 'missing';
        else $stateMap[$recordId] = 'normal';
    }

    $errorCount = count($errorIds);
    $missingCount = count($missingIds);
    return $stateMap;
}

function quality_match($qualityStatus, $state)
{
    if ($qualityStatus === 'all') return true;
    if ($qualityStatus === 'error') return $state === 'error' || $state === 'error_missing';
    if ($qualityStatus === 'missing') return $state === 'missing' || $state === 'error_missing';
    if ($qualityStatus === 'normal') return $state === 'normal';
    return true;
}

function normalize_aggregate_granularity($value)
{
    $text = strtolower(trim(strval($value)));
    if (!in_array($text, array('year', 'quarter', 'month', 'week', 'day', 'hourly'), true)) {
        return 'day';
    }
    return $text;
}

function normalize_date_text_or_null($value)
{
    if ($value === null) {
        return null;
    }
    $text = trim(strval($value));
    if ($text === '') {
        return null;
    }
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1 ? $text : null;
}

function query_single_row_assoc(PDO $pdo, $sql, $params = array())
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function build_protocol_quality_summary(PDO $pdo, $protocol, $cameraId, $timestamps = array(), $startEventTime = null, $endEventTime = null)
{
    $params = array();
    $sql = build_select_sql(intval($protocol), $cameraId, $timestamps, $startEventTime, $endEventTime, false, $params)
        . ' ORDER BY created_at DESC, id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $records = array();
    while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
        $records[] = normalize_record($row, false);
    }

    $errorCount = 0;
    $missingCount = 0;
    $missingFrames = 0;
    build_quality_state_map($records, $errorCount, $missingCount, $missingFrames);
    return array(
        'error_count' => $errorCount,
        'missing_count' => $missingCount,
        'missing_frames' => $missingFrames
    );
}

function build_timeline_bucket_config($datetimeExpr, $granularity)
{
    if ($granularity === 'year') {
        return array(
            'label_expr' => "CONCAT(YEAR($datetimeExpr), '年')",
            'sort_expr' => "YEAR($datetimeExpr)"
        );
    }
    if ($granularity === 'quarter') {
        return array(
            'label_expr' => "CONCAT(YEAR($datetimeExpr), ' Q', QUARTER($datetimeExpr))",
            'sort_expr' => "(YEAR($datetimeExpr) * 10 + QUARTER($datetimeExpr))"
        );
    }
    if ($granularity === 'month') {
        return array(
            'label_expr' => "DATE_FORMAT($datetimeExpr, '%Y-%m')",
            'sort_expr' => "DATE_FORMAT($datetimeExpr, '%Y-%m-01 00:00:00')"
        );
    }
    if ($granularity === 'week') {
        $weekStartExpr = "DATE_SUB(DATE($datetimeExpr), INTERVAL WEEKDAY($datetimeExpr) DAY)";
        return array(
            'label_expr' => "CONCAT(DATE_FORMAT($weekStartExpr, '%Y-%m-%d'), '周')",
            'sort_expr' => $weekStartExpr
        );
    }
    if ($granularity === 'hourly') {
        return array(
            'label_expr' => "CONCAT(LPAD(HOUR($datetimeExpr), 2, '0'), ':00-', LPAD(HOUR($datetimeExpr), 2, '0'), ':59')",
            'sort_expr' => "HOUR($datetimeExpr)"
        );
    }
    return array(
        'label_expr' => "DATE_FORMAT($datetimeExpr, '%Y-%m-%d')",
        'sort_expr' => "DATE_FORMAT($datetimeExpr, '%Y-%m-%d 00:00:00')"
    );
}

function query_protocol_latest_day(PDO $pdo, $tableName, $whereSql, $params, $timeMode)
{
    if ($timeMode === 'receive') {
        $sql = "SELECT DATE_FORMAT(MAX(created_at), '%Y-%m-%d') latest_day FROM $tableName" . $whereSql;
    } else {
        $sql = "SELECT DATE_FORMAT(MAX(FROM_UNIXTIME(event_timestamp_ms / 1000)), '%Y-%m-%d') latest_day FROM $tableName" . $whereSql;
    }
    $row = query_single_row_assoc($pdo, $sql, $params);
    return $row && !empty($row['latest_day']) ? strval($row['latest_day']) : '';
}

function query_protocol_timeline(PDO $pdo, $tableName, $cameraId, $timestamps, $startEventTime, $endEventTime, $timeMode, $granularity, $hourlyDate = null)
{
    list($whereSql, $params) = build_where($cameraId, $timestamps, $startEventTime, $endEventTime);

    if ($timeMode === 'receive') {
        $datetimeExpr = 'created_at';
    } else {
        $datetimeExpr = 'FROM_UNIXTIME(event_timestamp_ms / 1000)';
    }

    $effectiveDate = '';
    if ($granularity === 'hourly') {
        $effectiveDate = $hourlyDate !== null && $hourlyDate !== '' ? $hourlyDate : query_protocol_latest_day($pdo, $tableName, $whereSql, $params, $timeMode);
        if ($effectiveDate !== '') {
            if ($timeMode === 'receive') {
                $whereSql .= ' AND created_at >= ? AND created_at < ?';
                $params[] = $effectiveDate . ' 00:00:00';
                $params[] = date('Y-m-d H:i:s', strtotime($effectiveDate . ' +1 day'));
            } else {
                $dayStartMs = intval(strtotime($effectiveDate . ' 00:00:00')) * 1000;
                $nextDayMs = intval(strtotime($effectiveDate . ' +1 day 00:00:00')) * 1000;
                $whereSql .= ' AND event_timestamp_ms >= ? AND event_timestamp_ms < ?';
                $params[] = $dayStartMs;
                $params[] = $nextDayMs;
            }
        }
    }

    $bucket = build_timeline_bucket_config($datetimeExpr, $granularity);
    $sql = "SELECT {$bucket['label_expr']} AS label, {$bucket['sort_expr']} AS sort_key, COUNT(*) AS value
            FROM $tableName" . $whereSql . "
            GROUP BY label, sort_key
            ORDER BY sort_key ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = array();
    foreach ($rows as $row) {
        $result[] = array(
            'label' => strval(isset($row['label']) ? $row['label'] : ''),
            'value' => intval(isset($row['value']) ? $row['value'] : 0)
        );
    }

    return array(
        'effective_date' => $effectiveDate,
        'rows' => $result
    );
}

function query_protocol_camera_distribution(PDO $pdo, $tableName, $cameraId, $timestamps, $startEventTime, $endEventTime, $limit = 12)
{
    list($whereSql, $params) = build_where($cameraId, $timestamps, $startEventTime, $endEventTime);
    $whereSql .= " AND camera_id IS NOT NULL AND camera_id <> ''";

    $sql = "SELECT camera_id, COUNT(*) AS value
            FROM $tableName" . $whereSql . "
            GROUP BY camera_id
            ORDER BY value DESC, camera_id ASC
            LIMIT " . intval($limit);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = array();
    foreach ($rows as $row) {
        $result[] = array(
            'name' => strval(isset($row['camera_id']) ? $row['camera_id'] : ''),
            'value' => intval(isset($row['value']) ? $row['value'] : 0)
        );
    }
    return $result;
}

function query_protocol_edge_row(PDO $pdo, $tableName, $whereSql, $params, $field, $direction)
{
    $sql = "SELECT camera_id, $field AS field_value
            FROM $tableName" . $whereSql . "
            ORDER BY $field $direction, id $direction
            LIMIT 1";
    return query_single_row_assoc($pdo, $sql, $params);
}

function build_protocol_aggregate_summary(PDO $pdo, $protocol, $tableName, $cameraId, $timestamps, $startEventTime, $endEventTime)
{
    list($whereSql, $params) = build_where($cameraId, $timestamps, $startEventTime, $endEventTime);

    $commonSql = "SELECT
        COUNT(*) AS total_records,
        COUNT(DISTINCT batch_id) AS batch_count,
        COUNT(DISTINCT camera_id) AS camera_count,
        COUNT(DISTINCT DATE(FROM_UNIXTIME(event_timestamp_ms / 1000))) AS active_days,
        COALESCE(DATE_FORMAT(MAX(FROM_UNIXTIME(event_timestamp_ms / 1000)), '%Y-%m-%d'), '') AS latest_active_date,
        COALESCE(DATE_FORMAT(MAX(FROM_UNIXTIME(event_timestamp_ms / 1000)), '%Y-%m-%d'), '') AS latest_event_day,
        COALESCE(DATE_FORMAT(MAX(created_at), '%Y-%m-%d'), '') AS latest_receive_day
        FROM $tableName" . $whereSql;

    $common = query_single_row_assoc($pdo, $commonSql, $params);
    if (!$common || intval($common['total_records']) <= 0) {
        return array(
            'protocol_id' => intval($protocol),
            'total_records' => 0,
            'batch_count' => 0,
            'camera_count' => 0,
            'active_days' => 0,
            'latest_active_date' => '',
            'latest_event_time' => 0,
            'latest_event_camera' => '',
            'earliest_event_time' => 0,
            'earliest_event_camera' => '',
            'latest_received_time' => '',
            'latest_received_camera' => '',
            'latest_event_day' => '',
            'latest_receive_day' => '',
            'event_hourly_date' => '',
            'receive_hourly_date' => ''
        );
    }

    $summary = array(
        'protocol_id' => intval($protocol),
        'total_records' => intval($common['total_records']),
        'batch_count' => intval($common['batch_count']),
        'camera_count' => intval($common['camera_count']),
        'active_days' => intval($common['active_days']),
        'latest_active_date' => strval($common['latest_active_date']),
        'latest_event_day' => strval($common['latest_event_day']),
        'latest_receive_day' => strval($common['latest_receive_day']),
        'event_hourly_date' => '',
        'receive_hourly_date' => ''
    );

    $latestEvent = query_protocol_edge_row($pdo, $tableName, $whereSql, $params, 'event_timestamp_ms', 'DESC');
    $earliestEvent = query_protocol_edge_row($pdo, $tableName, $whereSql, $params, 'event_timestamp_ms', 'ASC');
    $latestReceive = query_protocol_edge_row($pdo, $tableName, $whereSql, $params, 'created_at', 'DESC');

    $summary['latest_event_time'] = $latestEvent ? intval($latestEvent['field_value']) : 0;
    $summary['latest_event_camera'] = $latestEvent ? strval($latestEvent['camera_id']) : '';
    $summary['earliest_event_time'] = $earliestEvent ? intval($earliestEvent['field_value']) : 0;
    $summary['earliest_event_camera'] = $earliestEvent ? strval($earliestEvent['camera_id']) : '';
    $summary['latest_received_time'] = $latestReceive ? strval($latestReceive['field_value']) : '';
    $summary['latest_received_camera'] = $latestReceive ? strval($latestReceive['camera_id']) : '';

    if (intval($protocol) === 101) {
        $sql101 = "SELECT
            COUNT(DISTINCT CONCAT(COALESCE(camera_id, ''), '::', CAST(event_timestamp_ms AS CHAR), '::',
                COALESCE(JSON_UNQUOTE(JSON_EXTRACT(normalized_json, '$.frame_seq')),
                         JSON_UNQUOTE(JSON_EXTRACT(normalized_json, '$.frame_index')),
                         '')
            )) AS keyframe_count,
            SUM(CASE
                WHEN NOT (IFNULL(x1, 0) = 0 AND IFNULL(y1, 0) = 0 AND IFNULL(x2, 0) = 0 AND IFNULL(y2, 0) = 0) THEN 1
                ELSE GREATEST(COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(normalized_json, '$.frame_target_count')) AS SIGNED), 0), 0)
            END) AS target_total,
            COUNT(DISTINCT CASE WHEN IFNULL(track_id, 0) > 0 THEN track_id ELSE NULL END) AS track_count,
            SUM(CASE WHEN IFNULL(obj_type, 0) = 0 THEN 1 ELSE 0 END) AS person_total
            FROM $tableName" . $whereSql;
        $row101 = query_single_row_assoc($pdo, $sql101, $params);
        $summary['keyframe_count'] = intval(isset($row101['keyframe_count']) ? $row101['keyframe_count'] : 0);
        $summary['target_total'] = intval(isset($row101['target_total']) ? $row101['target_total'] : 0);
        $summary['track_count'] = intval(isset($row101['track_count']) ? $row101['track_count'] : 0);
        $summary['person_total'] = intval(isset($row101['person_total']) ? $row101['person_total'] : 0);
        $summary['non_person_total'] = max(0, $summary['total_records'] - $summary['person_total']);
        return $summary;
    }

    if (intval($protocol) === 102) {
        $sql102 = "SELECT
            SUM(COALESCE(embedding_byte_length, 0)) AS vector_bytes,
            SUM(CASE WHEN COALESCE(person_name, '') <> '' THEN 1 ELSE 0 END) AS recognized_total
            FROM $tableName" . $whereSql;
        $row102 = query_single_row_assoc($pdo, $sql102, $params);
        $summary['vector_bytes'] = intval(isset($row102['vector_bytes']) ? $row102['vector_bytes'] : 0);
        $summary['recognized_total'] = intval(isset($row102['recognized_total']) ? $row102['recognized_total'] : 0);
        $summary['unrecognized_total'] = max(0, $summary['total_records'] - $summary['recognized_total']);
        return $summary;
    }

    $sql103 = "SELECT
        SUM(COALESCE(image_byte_length, 0)) AS image_bytes,
        SUM(CASE WHEN COALESCE(image_fetch_status, '') = 'success' THEN 1 ELSE 0 END) AS success_total,
        SUM(CASE WHEN COALESCE(image_fetch_status, '') = 'incomplete' THEN 1 ELSE 0 END) AS incomplete_total
        FROM $tableName" . $whereSql;
    $row103 = query_single_row_assoc($pdo, $sql103, $params);
    $summary['image_bytes'] = intval(isset($row103['image_bytes']) ? $row103['image_bytes'] : 0);
    $summary['success_total'] = intval(isset($row103['success_total']) ? $row103['success_total'] : 0);
    $summary['incomplete_total'] = intval(isset($row103['incomplete_total']) ? $row103['incomplete_total'] : 0);
    $summary['other_abnormal_total'] = max(0, $summary['total_records'] - $summary['success_total'] - $summary['incomplete_total']);
    return $summary;
}

function handle_query_aggregate(PDO $pdo)
{
    if (strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') !== 'GET') {
        respond_error('Only GET method is supported', 405);
    }

    $protocol = normalize_int_or_null(isset($_GET['protocol']) ? $_GET['protocol'] : null);
    if ($protocol === null || !in_array($protocol, array(101, 102, 103), true)) {
        respond_error('protocol must be 101, 102 or 103', 400);
    }

    if (!ensure_query_message_table_exists($pdo, $protocol)) {
        respond_success(array(
            'summary' => array(
                'protocol_id' => intval($protocol),
                'total_records' => 0,
                'batch_count' => 0,
                'camera_count' => 0,
                'active_days' => 0,
                'latest_active_date' => '',
                'latest_event_time' => 0,
                'latest_event_camera' => '',
                'earliest_event_time' => 0,
                'earliest_event_camera' => '',
                'latest_received_time' => '',
                'latest_received_camera' => '',
                'latest_event_day' => '',
                'latest_receive_day' => '',
                'event_hourly_date' => '',
                'receive_hourly_date' => ''
            ),
            'quality_summary' => array('error_count' => 0, 'missing_count' => 0, 'missing_frames' => 0),
            'event_timeline' => array(),
            'receive_timeline' => array(),
            'camera_distribution' => array()
        ), 'ok');
    }

    $cameraId = normalize_string_or_null(isset($_GET['camera_id']) ? $_GET['camera_id'] : (isset($_GET['cam_id']) ? $_GET['cam_id'] : null));
    $timestamps = parse_timestamp_list(isset($_GET['timestamps']) ? $_GET['timestamps'] : null);
    $startEventTime = normalize_timestamp_or_null(isset($_GET['start_event_time']) ? $_GET['start_event_time'] : null);
    $endEventTime = normalize_timestamp_or_null(isset($_GET['end_event_time']) ? $_GET['end_event_time'] : null);
    $eventGranularity = normalize_aggregate_granularity(isset($_GET['event_granularity']) ? $_GET['event_granularity'] : 'day');
    $receiveGranularity = normalize_aggregate_granularity(isset($_GET['receive_granularity']) ? $_GET['receive_granularity'] : 'day');
    $eventHourlyDate = normalize_date_text_or_null(isset($_GET['event_hourly_date']) ? $_GET['event_hourly_date'] : null);
    $receiveHourlyDate = normalize_date_text_or_null(isset($_GET['receive_hourly_date']) ? $_GET['receive_hourly_date'] : null);

    $tableName = 'message_' . intval($protocol) . '_records';
    $summary = build_protocol_aggregate_summary($pdo, $protocol, $tableName, $cameraId, $timestamps, $startEventTime, $endEventTime);
    $eventTimeline = query_protocol_timeline($pdo, $tableName, $cameraId, $timestamps, $startEventTime, $endEventTime, 'event', $eventGranularity, $eventHourlyDate);
    $receiveTimeline = query_protocol_timeline($pdo, $tableName, $cameraId, $timestamps, $startEventTime, $endEventTime, 'receive', $receiveGranularity, $receiveHourlyDate);
    $summary['event_hourly_date'] = strval($eventTimeline['effective_date']);
    $summary['receive_hourly_date'] = strval($receiveTimeline['effective_date']);

    $qualitySummary = $summary['total_records'] > 0
        ? query_quality_summary_from_table($pdo, $protocol, $cameraId, $startEventTime, $endEventTime)
        : array('error_count' => 0, 'missing_count' => 0, 'missing_frames' => 0);

    respond_success(array(
        'summary' => $summary,
        'quality_summary' => $qualitySummary,
        'event_timeline' => $eventTimeline['rows'],
        'receive_timeline' => $receiveTimeline['rows'],
        'camera_distribution' => query_protocol_camera_distribution($pdo, $tableName, $cameraId, $timestamps, $startEventTime, $endEventTime, 12)
    ), 'ok');
}

function handle_query_overview(PDO $pdo)
{
    if (strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') !== 'GET') {
        respond_error('Only GET method is supported', 405);
    }

    respond_success(query_data_center_overview($pdo), 'ok');
}

function handle_query_records(PDO $pdo)
{
    if (strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') !== 'GET') {
        respond_error('Only GET method is supported', 405);
    }

    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
    if ($limit <= 0) $limit = 20;
    if ($limit > 500) $limit = 500;

    $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
    if ($offset < 0) $offset = 0;

    $protocol = normalize_int_or_null(isset($_GET['protocol']) ? $_GET['protocol'] : null);
    if ($protocol !== null && !in_array($protocol, array(101, 102, 103), true)) {
        respond_error('protocol must be 101, 102 or 103', 400);
    }

    $cameraId = normalize_string_or_null(isset($_GET['camera_id']) ? $_GET['camera_id'] : (isset($_GET['cam_id']) ? $_GET['cam_id'] : null));
    $timestamps = parse_timestamp_list(isset($_GET['timestamps']) ? $_GET['timestamps'] : null);
    $startEventTime = normalize_timestamp_or_null(isset($_GET['start_event_time']) ? $_GET['start_event_time'] : null);
    $endEventTime = normalize_timestamp_or_null(isset($_GET['end_event_time']) ? $_GET['end_event_time'] : null);
    $includePayload = isset($_GET['include_payload']) && strval($_GET['include_payload']) === '1';
    $qualityStatus = strtolower(strval(isset($_GET['quality_status']) ? $_GET['quality_status'] : 'all'));
    if (!in_array($qualityStatus, array('all', 'error', 'missing', 'normal'), true)) {
        respond_error('quality_status must be all, error, missing or normal', 400);
    }

    $protocols = $protocol === null ? array(101, 102, 103) : array($protocol);
    $availableProtocols = array();
    foreach ($protocols as $pid) {
        if (ensure_query_message_table_exists($pdo, $pid)) {
            $availableProtocols[] = $pid;
        }
    }

    $total = 0;
    $protocolTotals = array(101 => 0, 102 => 0, 103 => 0);
    foreach ($protocols as $pid) {
        $count = count_table($pdo, 'message_' . $pid . '_records', $cameraId, $timestamps, $startEventTime, $endEventTime);
        $protocolTotals[$pid] = $count;
        $total += $count;
    }

    if (empty($availableProtocols)) {
        respond_success(array('total' => 0, 'records' => array(), 'linked_packets' => array(), 'protocol_totals' => $protocolTotals), 'ok');
    }

    $params = array();
    $selects = array();
    foreach ($availableProtocols as $pid) {
        $selects[] = build_select_sql($pid, $cameraId, $timestamps, $startEventTime, $endEventTime, $includePayload, $params);
    }

    $records = array();
    $qualityErrorCount = 0;
    $qualityMissingCount = 0;
    $qualityMissingFrames = 0;

    $qualityWhere = '';
    if ($qualityStatus === 'error') {
        $qualityWhere = " WHERE quality_status IN ('error', 'error_missing')";
    } elseif ($qualityStatus === 'missing') {
        $qualityWhere = " WHERE quality_status IN ('missing', 'error_missing')";
    } elseif ($qualityStatus === 'normal') {
        $qualityWhere = " WHERE quality_status = 'normal'";
    }

    $baseSql = 'SELECT * FROM (' . implode(' UNION ALL ', $selects) . ') t';
    if ($qualityWhere !== '') {
        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM (' . implode(' UNION ALL ', $selects) . ') t' . $qualityWhere);
        $countStmt->execute($params);
        $total = intval($countStmt->fetchColumn());
    }

    $sql = $baseSql . $qualityWhere . ' ORDER BY created_at DESC, id DESC LIMIT ' . intval($limit) . ' OFFSET ' . intval($offset);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
        $records[] = normalize_record($row, $includePayload);
    }

    if ($protocol !== null && empty($timestamps)) {
        $qualitySummary = query_quality_summary_from_table($pdo, $protocol, $cameraId, $startEventTime, $endEventTime);
        $qualityErrorCount = intval($qualitySummary['error_count']);
        $qualityMissingCount = intval($qualitySummary['missing_count']);
        $qualityMissingFrames = intval($qualitySummary['missing_frames']);
    }

    respond_success(array(
        'total' => $total,
        'records' => $records,
        'linked_packets' => build_linked_packets($records),
        'protocol_totals' => $protocolTotals,
        'quality_summary' => array(
            'error_count' => $qualityErrorCount,
            'missing_count' => $qualityMissingCount,
            'missing_frames' => $qualityMissingFrames
        )
    ), 'ok');
}

function query_frame_hex(PDO $pdo, $protocol, $recordId)
{
    $pid = intval($protocol);
    if (!in_array($pid, array(101, 102, 103), true)) {
        throw new InvalidArgumentException('protocol must be 101, 102 or 103');
    }
    $id = intval($recordId);
    if ($id <= 0) {
        throw new InvalidArgumentException('record_id must be positive');
    }

    $table = 'message_' . $pid . '_records';
    if (!table_exists($pdo, $table)) {
        throw new RuntimeException('table not found: ' . $table);
    }

    $stmt = $pdo->prepare('SELECT id, camera_id, event_timestamp_ms, raw_protocol_hex, created_at FROM ' . $table . ' WHERE id = ? LIMIT 1');
    $stmt->execute(array($id));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('record not found');
    }

    return array(
        'protocol_id' => $pid,
        'record_id' => intval($row['id']),
        'camera_id' => strval($row['camera_id']),
        'cam_id' => camera_code_to_int($row['camera_id']),
        'timestamp' => intval($row['event_timestamp_ms']),
        'create_time' => strval($row['created_at']),
        'raw_protocol_hex' => strval($row['raw_protocol_hex']),
        'raw_protocol_hex_size' => strlen(strval($row['raw_protocol_hex']))
    );
}

function query_media_row(PDO $pdo, $protocol, $recordId)
{
    $pid = intval($protocol);
    if ($pid !== 103) {
        throw new InvalidArgumentException('media stream currently supports protocol 103 only');
    }

    $id = intval($recordId);
    if ($id <= 0) {
        throw new InvalidArgumentException('record_id must be positive');
    }

    $table = 'message_103_records';
    if (!table_exists($pdo, $table)) {
        throw new RuntimeException('table not found: ' . $table);
    }

    $stmt = $pdo->prepare('SELECT id, local_image_path, normalized_json FROM message_103_records WHERE id = ? LIMIT 1');
    $stmt->execute(array($id));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('record not found');
    }

    return $row;
}

function handle_stream_media(PDO $pdo, $protocol, $recordId)
{
    $method = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET');
    if ($method !== 'GET' && $method !== 'HEAD') {
        respond_error('Only GET/HEAD method is supported', 405);
    }

    $row = query_media_row($pdo, $protocol, $recordId);
    $localPath = strval(isset($row['local_image_path']) ? $row['local_image_path'] : '');
    $normalized = json_decode_array(isset($row['normalized_json']) ? $row['normalized_json'] : '');
    if ($localPath === '' && isset($normalized['local_image_path'])) {
        $localPath = strval($normalized['local_image_path']);
    }

    $realPath = resolve_media_file_path($localPath);
    if ($realPath === null) {
        throw new RuntimeException('media file not found');
    }

    $download = isset($_GET['download']) ? intval($_GET['download']) : 0;
    if ($download === 1) {
        header('Content-Disposition: attachment; filename="' . basename($realPath) . '"');
    }

    stream_file_with_range($realPath, media_content_type_from_path($realPath));
}

function handle_delete_media(PDO $pdo, $protocol, $recordId)
{
    $method = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET');
    if ($method !== 'DELETE' && $method !== 'POST') {
        respond_error('Only DELETE/POST method is supported', 405);
    }

    $row = query_media_row($pdo, $protocol, $recordId);
    $localPath = strval(isset($row['local_image_path']) ? $row['local_image_path'] : '');
    $normalized = json_decode_array(isset($row['normalized_json']) ? $row['normalized_json'] : '');
    if ($localPath === '' && isset($normalized['local_image_path'])) {
        $localPath = strval($normalized['local_image_path']);
    }

    $realPath = resolve_media_file_path($localPath);
    $deleted = false;
    if ($realPath !== null && is_file($realPath)) {
        $deleted = @unlink($realPath);
        if (!$deleted) {
            respond_error('failed to delete media file', 500);
        }
    }

    $normalized['local_image_path'] = '';
    $normalized['media_deleted_at'] = date('Y-m-d H:i:s');
    $normalized['media_deleted_flag'] = 1;

    $stmt = $pdo->prepare('UPDATE message_103_records SET local_image_path = NULL, image_fetch_status = ?, error_message = ?, normalized_json = ? WHERE id = ? LIMIT 1');
    $stmt->execute(array(
        'deleted',
        'media file deleted manually',
        json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        intval($row['id'])
    ));

    respond_success(array(
        'record_id' => intval($row['id']),
        'deleted' => $deleted || $realPath === null,
        'path' => $localPath
    ), 'deleted');
}

function handle_upload_stream_records_request($pdo)
{
    try {
        if (isset($_GET['action']) && strval($_GET['action']) === 'media') {
            handle_stream_media($pdo, isset($_GET['protocol']) ? $_GET['protocol'] : null, isset($_GET['record_id']) ? $_GET['record_id'] : null);
        }
        if (isset($_GET['action']) && strval($_GET['action']) === 'delete_media') {
            handle_delete_media($pdo, isset($_GET['protocol']) ? $_GET['protocol'] : null, isset($_GET['record_id']) ? $_GET['record_id'] : null);
        }
        if (isset($_GET['action']) && strval($_GET['action']) === 'frame') {
            respond_success(query_frame_hex($pdo, isset($_GET['protocol']) ? $_GET['protocol'] : null, isset($_GET['record_id']) ? $_GET['record_id'] : null), 'ok');
        }
        if (isset($_GET['action']) && strval($_GET['action']) === 'cameras') {
            respond_success(array(
                'records' => query_camera_options($pdo, isset($_GET['protocol']) ? $_GET['protocol'] : null)
            ), 'ok');
        }
        handle_query_records($pdo);
    } catch (InvalidArgumentException $e) {
        respond_error($e->getMessage(), 400);
    } catch (Exception $e) {
        respond_error('query failed: ' . $e->getMessage(), 500);
    }
}

$scriptName = isset($_SERVER['SCRIPT_FILENAME']) ? (string) $_SERVER['SCRIPT_FILENAME'] : '';
if ($scriptName !== '' && realpath($scriptName) === realpath(__FILE__)) {
    handle_upload_stream_records_request($pdo);
}
