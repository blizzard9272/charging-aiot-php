<?php
require_once __DIR__ . '/../../../config/cors.php';
require_once __DIR__ . '/../../../../config.php';
require_once __DIR__ . '/../../../lib/storage_paths.php';

header('Content-Type: application/json; charset=utf-8');

class UploadStreamRequestDTO
{
    public $protocol;
    public $camId;
    public $binaryData;
    public $filename;
    public $fileSize;

    public function __construct($protocol, $camId, $binaryData, $filename, $fileSize)
    {
        $this->protocol = $protocol;
        $this->camId = $camId;
        $this->binaryData = $binaryData;
        $this->filename = $filename;
        $this->fileSize = $fileSize;
    }

    public static function fromGlobals()
    {
        if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
            throw new InvalidArgumentException('Only POST method is supported');
        }

        $protocol = request_int_param(array('protocol', 'proto'));
        if (!in_array($protocol, array(101, 102, 103), true)) {
            throw new InvalidArgumentException('protocol must be 101, 102 or 103');
        }

        $camId = request_nullable_int_param('cam_id');
        $binaryData = '';
        $filename = 'stream_upload.bin';
        $fileSize = 0;

        if (isset($_FILES['file'])) {
            $fileInfo = $_FILES['file'];
            if (!isset($fileInfo['error']) || intval($fileInfo['error']) !== UPLOAD_ERR_OK) {
                $code = isset($fileInfo['error']) ? intval($fileInfo['error']) : -1;
                throw new InvalidArgumentException('upload failed, error code: ' . $code);
            }

            if (!isset($fileInfo['tmp_name']) || !is_uploaded_file($fileInfo['tmp_name'])) {
                throw new InvalidArgumentException('invalid uploaded file');
            }

            $binaryData = file_get_contents($fileInfo['tmp_name']);
            if ($binaryData === false || $binaryData === '') {
                throw new InvalidArgumentException('uploaded binary stream is empty');
            }

            $filename = isset($fileInfo['name']) ? basename((string) $fileInfo['name']) : $filename;
            $fileSize = isset($fileInfo['size']) ? intval($fileInfo['size']) : strlen($binaryData);
        } else {
            $binaryData = file_get_contents('php://input');
            if (!is_string($binaryData) || $binaryData === '') {
                throw new InvalidArgumentException('request binary stream is empty');
            }
            $fileSize = strlen($binaryData);
        }

        return new self($protocol, $camId, $binaryData, $filename, $fileSize);
    }
}

class TargetVO
{
    public $type;
    public $tid;
    public $x1;
    public $y1;
    public $x2;
    public $y2;
    public $conf;

    public function __construct($type, $tid, $x1, $y1, $x2, $y2, $conf = null)
    {
        $this->type = $type;
        $this->tid = $tid;
        $this->x1 = $x1;
        $this->y1 = $y1;
        $this->x2 = $x2;
        $this->y2 = $y2;
        $this->conf = $conf;
    }

    public function toArray()
    {
        return array(
            'type' => $this->type,
            'tid' => $this->tid,
            'x1' => $this->x1,
            'y1' => $this->y1,
            'x2' => $this->x2,
            'y2' => $this->y2,
            'conf' => $this->conf
        );
    }
}

class StreamFrameVO
{
    public $protocol;
    public $camId;
    public $timestamp;
    public $frameSeq;
    public $count;
    public $targets;
    public $payloadItems;
    public $base64Image;
    public $payloadBinary;
    public $rawFrameBinary;
    public $protocolVersion;
    public $frameLength;
    public $crc;
    public $frameHeader;
    public $frameTail;
    public $payloadType;
    public $trackId;
    public $startTimestamp;
    public $endTimestamp;

    public function __construct($protocol, $camId, $timestamp, $count, $targets, $base64Image, $payloadBinary = null, $meta = array())
    {
        $this->protocol = $protocol;
        $this->camId = $camId;
        $this->timestamp = $timestamp;
        $this->frameSeq = isset($meta['frame_seq']) ? intval($meta['frame_seq']) : 0;
        $this->count = $count;
        $this->targets = $targets;
        $this->payloadItems = isset($meta['payload_items']) && is_array($meta['payload_items']) ? $meta['payload_items'] : array();
        $this->base64Image = $base64Image;
        $this->payloadBinary = $payloadBinary;
        $this->rawFrameBinary = isset($meta['raw_frame_binary']) ? $meta['raw_frame_binary'] : '';
        $this->protocolVersion = isset($meta['protocol_version']) ? intval($meta['protocol_version']) : 1;
        $this->frameLength = isset($meta['frame_length']) ? intval($meta['frame_length']) : 0;
        $this->crc = isset($meta['crc']) ? intval($meta['crc']) : 0;
        $this->frameHeader = isset($meta['frame_header']) ? intval($meta['frame_header']) : 0;
        $this->frameTail = isset($meta['frame_tail']) ? intval($meta['frame_tail']) : 0xFFFFFFFF;
        $this->payloadType = isset($meta['payload_type']) ? intval($meta['payload_type']) : 0;
        $this->trackId = isset($meta['track_id']) ? intval($meta['track_id']) : 0;
        $this->startTimestamp = isset($meta['start_timestamp']) ? intval($meta['start_timestamp']) : 0;
        $this->endTimestamp = isset($meta['end_timestamp']) ? intval($meta['end_timestamp']) : 0;
    }

    public function toArray()
    {
        $data = array(
            'protocol' => $this->protocol,
            'cam_id' => $this->camId,
            'timestamp' => $this->timestamp,
            'frame_seq' => $this->frameSeq
        );

        if ($this->protocol === 101) {
            $data['count'] = $this->count;
            $data['targets'] = $this->targets;
        } elseif ($this->protocol === 102) {
            $data['count'] = $this->count;
            $data['items'] = $this->payloadItems;
        } elseif ($this->protocol === 103) {
            $data['base64_image'] = $this->base64Image;
            $data['count'] = $this->count;
            $data['payload_type'] = $this->payloadType;
            $data['track_id'] = $this->trackId;
            $data['start_timestamp'] = $this->startTimestamp;
            $data['end_timestamp'] = $this->endTimestamp;
        } else {
            $data['count'] = $this->count;
        }

        return $data;
    }
}

class ParseResultVO
{
    public $protocol;
    public $camId;
    public $frameCount;
    public $frames;

    public function __construct($protocol, $camId, $frameCount, $frames)
    {
        $this->protocol = $protocol;
        $this->camId = $camId;
        $this->frameCount = $frameCount;
        $this->frames = $frames;
    }

    public function toArray()
    {
        $resultFrames = array();
        foreach ($this->frames as $frameVO) {
            $resultFrames[] = $frameVO->toArray();
        }

        return array(
            'protocol' => $this->protocol,
            'cam_id' => $this->camId,
            'frame_count' => $this->frameCount,
            'frames' => $resultFrames
        );
    }
}

function respond_success($data, $msg)
{
    echo json_encode(array(
        'code' => 1,
        'msg' => $msg,
        'data' => $data
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function respond_error($msg, $httpCode)
{
    http_response_code($httpCode);
    echo json_encode(array(
        'code' => 0,
        'msg' => $msg,
        'data' => null
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function byte_at($data, $offset)
{
    if ($offset < 0 || $offset >= strlen($data)) {
        throw new RuntimeException('binary offset out of range: ' . $offset);
    }
    return ord($data[$offset]);
}

function request_uri()
{
    return isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
}

function request_query_value($name)
{
    if (isset($_GET[$name]) && $_GET[$name] !== '') {
        return (string) $_GET[$name];
    }

    $uri = request_uri();
    if ($uri !== '' && preg_match('/(?:\?|&)' . preg_quote($name, '/') . '=([^&]+)/', $uri, $matches) === 1) {
        return rawurldecode((string) $matches[1]);
    }

    return null;
}

function request_int_param($names, $default = 0)
{
    foreach ((array) $names as $name) {
        $value = request_query_value($name);
        if ($value !== null && preg_match('/^-?\d+$/', $value) === 1) {
            return intval($value);
        }
    }
    return intval($default);
}

function request_nullable_int_param($name)
{
    $value = request_query_value($name);
    if ($value === null || $value === '') {
        return null;
    }
    if (preg_match('/^-?\d+$/', $value) !== 1) {
        throw new InvalidArgumentException($name . ' must be an integer');
    }
    return intval($value);
}

function read_u16_le($data, $offset)
{
    return byte_at($data, $offset) | (byte_at($data, $offset + 1) << 8);
}

function read_u32_le($data, $offset)
{
    return byte_at($data, $offset)
        | (byte_at($data, $offset + 1) << 8)
        | (byte_at($data, $offset + 2) << 16)
        | (byte_at($data, $offset + 3) << 24);
}

function read_u64_le($data, $offset)
{
    $value = 0;
    for ($i = 0; $i < 8; $i++) {
        $value |= (byte_at($data, $offset + $i) << ($i * 8));
    }
    return $value;
}

function read_u24_le($data, $offset)
{
    return byte_at($data, $offset)
        | (byte_at($data, $offset + 1) << 8)
        | (byte_at($data, $offset + 2) << 16);
}

function read_u56_le($data, $offset)
{
    $value = 0;
    for ($i = 0; $i < 7; $i++) {
        $value |= (byte_at($data, $offset + $i) << ($i * 8));
    }
    return $value;
}

function read_f32_le($data, $offset)
{
    $chunk = substr($data, $offset, 4);
    if ($chunk === false || strlen($chunk) !== 4) {
        throw new RuntimeException('binary float offset out of range: ' . $offset);
    }
    $arr = unpack('gvalue', $chunk);
    if (!is_array($arr) || !array_key_exists('value', $arr)) {
        throw new RuntimeException('failed to unpack float at offset ' . $offset);
    }
    return floatval($arr['value']);
}

function crc16_ccitt_false($binary)
{
    $data = is_string($binary) ? $binary : '';
    $crc = 0xFFFF;
    $len = strlen($data);
    for ($i = 0; $i < $len; $i++) {
        $crc ^= (ord($data[$i]) << 8);
        for ($bit = 0; $bit < 8; $bit++) {
            if (($crc & 0x8000) !== 0) {
                $crc = (($crc << 1) ^ 0x1021) & 0xFFFF;
            } else {
                $crc = ($crc << 1) & 0xFFFF;
            }
        }
    }
    return $crc;
}

function crc32_unsigned($binary)
{
    return intval(hexdec(hash('crc32b', is_string($binary) ? $binary : '')));
}

function u16le_bin($value)
{
    $v = intval($value);
    return chr($v & 0xFF) . chr(($v >> 8) & 0xFF);
}

function build_101_payload_hex($targets)
{
    if (!is_array($targets) || empty($targets)) {
        return '';
    }

    $bin = '';
    foreach ($targets as $target) {
        $bin .= u16le_bin(isset($target['type']) ? $target['type'] : 0);
        $bin .= u16le_bin(isset($target['tid']) ? $target['tid'] : 0);
        $bin .= u16le_bin(isset($target['x1']) ? $target['x1'] : 0);
        $bin .= u16le_bin(isset($target['y1']) ? $target['y1'] : 0);
        $bin .= u16le_bin(isset($target['x2']) ? $target['x2'] : 0);
        $bin .= u16le_bin(isset($target['y2']) ? $target['y2'] : 0);
    }

    return bin2hex($bin);
}

function sanitize_upload_basename($filename)
{
    $base = pathinfo((string) $filename, PATHINFO_FILENAME);
    $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $base);
    if (!is_string($clean) || $clean === '') {
        return 'stream_upload';
    }
    return substr($clean, 0, 80);
}

function sanitize_upload_extension($filename)
{
    $ext = strtolower((string) pathinfo((string) $filename, PATHINFO_EXTENSION));
    if ($ext === '' || preg_match('/^[a-z0-9]{1,8}$/', $ext) !== 1) {
        return 'bin';
    }
    return $ext;
}

function make_upload_archive_filename($requestDTO, $parseResult)
{
    $baseName = sanitize_upload_basename($requestDTO->filename);
    $ext = sanitize_upload_extension($requestDTO->filename);
    $camTag = $requestDTO->camId === null ? 'cam_auto' : ('cam' . intval($requestDTO->camId));
    $frameTag = 'f' . intval($parseResult->frameCount);

    $suffix = '';
    try {
        $suffix = bin2hex(random_bytes(4));
    } catch (Exception $e) {
        $suffix = substr(md5(uniqid('', true)), 0, 8);
    }

    return sprintf(
        '%s_p%d_%s_%s_%s.%s',
        date('Ymd_His'),
        intval($requestDTO->protocol),
        $camTag,
        $frameTag,
        $baseName . '_' . $suffix,
        $ext
    );
}

function parse_result_first_timestamp($parseResult)
{
    if (!isset($parseResult->frames) || !is_array($parseResult->frames) || empty($parseResult->frames)) {
        return intval(round(microtime(true) * 1000));
    }
    $firstFrame = $parseResult->frames[0];
    return isset($firstFrame->timestamp) ? intval($firstFrame->timestamp) : intval(round(microtime(true) * 1000));
}

function persist_uploaded_binary_file($requestDTO, $parseResult)
{
    $dateYmd = date('Ymd', intval(parse_result_first_timestamp($parseResult) / 1000));
    $cameraCode = build_camera_code($parseResult->camId);
    $storageDir = ensure_project_file_dir($dateYmd, intval($requestDTO->protocol), $cameraCode, 'raw_upload');
    if (!is_dir($storageDir) && !@mkdir($storageDir, 0777, true)) {
        throw new RuntimeException('failed to create upload storage directory: ' . $storageDir);
    }

    if (!is_dir($storageDir) || !is_writable($storageDir)) {
        throw new RuntimeException('upload storage directory is not writable: ' . $storageDir);
    }

    $filename = make_upload_archive_filename($requestDTO, $parseResult);
    $targetPath = $storageDir . '/' . $filename;
    $bytes = @file_put_contents($targetPath, $requestDTO->binaryData, LOCK_EX);
    if ($bytes === false || $bytes <= 0) {
        throw new RuntimeException('failed to persist uploaded binary stream file');
    }

    return array(
        'relative_path' => build_project_file_relative_path($dateYmd, intval($requestDTO->protocol), $cameraCode, $filename, 'raw_upload'),
        'size' => intval($bytes)
    );
}

function emit_stream_ws_events($events)
{
    if (!is_array($events) || empty($events)) {
        return;
    }

    $runtimeDir = dirname(__DIR__, 3) . '/runtime';
    if (!is_dir($runtimeDir)) {
        @mkdir($runtimeDir, 0777, true);
    }

    if (!is_dir($runtimeDir) || !is_writable($runtimeDir)) {
        return;
    }

    $eventFile = $runtimeDir . '/ws_events.jsonl';
    if (file_exists($eventFile)) {
        $size = @filesize($eventFile);
        if ($size !== false && $size > (8 * 1024 * 1024)) {
            @unlink($eventFile . '.1');
            @rename($eventFile, $eventFile . '.1');
        }
    }

    $lines = '';
    foreach ($events as $event) {
        $json = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            continue;
        }
        $lines .= $json . "\n";
    }

    if ($lines !== '') {
        @file_put_contents($eventFile, $lines, FILE_APPEND | LOCK_EX);
    }
}

function face_match_service_url()
{
    $url = getenv('FACE_MATCH_SERVICE_URL');
    if (is_string($url) && $url !== '') {
        return rtrim($url, '/');
    }
    return 'http://127.0.0.1:8090';
}

function face_match_trigger_limit()
{
    $value = getenv('FACE_MATCH_TRIGGER_LIMIT');
    if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
        $num = intval($value);
        if ($num > 0) {
            return $num;
        }
    }
    return 200;
}

function face_match_trigger_topk()
{
    $value = getenv('FACE_MATCH_TRIGGER_TOPK');
    if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
        $num = intval($value);
        if ($num > 0) {
            return $num;
        }
    }
    return 3;
}

function trigger_face_match_service_async($limit, $topK)
{
    $endpoint = face_match_service_url() . '/process_pending_102';
    $payload = json_encode(array(
        'limit' => intval($limit),
        'top_k' => intval($topK)
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
        return false;
    }

    if (function_exists('curl_init')) {
        try {
            $ch = curl_init($endpoint);
            if ($ch === false) {
                return false;
            }

            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 250);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 120);
            curl_setopt($ch, CURLOPT_NOSIGNAL, 1);

            @curl_exec($ch);
            @curl_close($ch);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    $parts = @parse_url($endpoint);
    if (!is_array($parts) || !isset($parts['host'])) {
        return false;
    }

    $host = $parts['host'];
    $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : 'http';
    $port = isset($parts['port']) ? intval($parts['port']) : ($scheme === 'https' ? 443 : 80);
    $path = isset($parts['path']) ? $parts['path'] : '/process_pending_102';
    if (isset($parts['query']) && $parts['query'] !== '') {
        $path .= '?' . $parts['query'];
    }

    $transport = $scheme === 'https' ? 'ssl://' : '';
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($transport . $host, $port, $errno, $errstr, 0.12);
    if (!$fp) {
        return false;
    }

    stream_set_blocking($fp, false);
    $req = "POST " . $path . " HTTP/1.1\r\n";
    $req .= "Host: " . $host . "\r\n";
    $req .= "Content-Type: application/json\r\n";
    $req .= "Connection: Close\r\n";
    $req .= "Content-Length: " . strlen($payload) . "\r\n\r\n";
    $req .= $payload;
    @fwrite($fp, $req);
    @fclose($fp);
    return true;
}

function table_exists($pdo, $tableName)
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute(array($tableName));
    return intval($stmt->fetchColumn()) > 0;
}

function message_column_exists($pdo, $tableName, $columnName)
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute(array($tableName, $columnName));
    return intval($stmt->fetchColumn()) > 0;
}

function message_column_type($pdo, $tableName, $columnName)
{
    $stmt = $pdo->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $stmt->execute(array($tableName, $columnName));
    $columnType = $stmt->fetchColumn();
    return is_string($columnType) ? strtolower($columnType) : '';
}

function ensure_protocol_meta_columns($pdo, $tableName)
{
    $unsignedMetaColumns = array('frame_header', 'frame_tail', 'crc_value', 'frame_length');
    foreach ($unsignedMetaColumns as $column) {
        if (message_column_exists($pdo, $tableName, $column)
            && message_column_type($pdo, $tableName, $column) !== 'bigint unsigned') {
            $pdo->exec('ALTER TABLE ' . $tableName . ' MODIFY COLUMN ' . $column . ' BIGINT UNSIGNED DEFAULT NULL');
        }
    }
}

function ensure_message_table_exists($pdo, $protocol)
{
    $tableName = 'message_' . intval($protocol) . '_records';
    if (!table_exists($pdo, $tableName)) {
        throw new RuntimeException('table not found: ' . $tableName);
    }
    ensure_protocol_meta_columns($pdo, $tableName);
    return $tableName;
}

function build_camera_code($camId)
{
    return 'cam' . intval($camId);
}

function build_raw_protocol_hex($binary)
{
    return strtoupper(bin2hex(is_string($binary) ? $binary : ''));
}

function next_batch_id($pdo, $tableName)
{
    $stmt = $pdo->query('SELECT COALESCE(MAX(batch_id), 0) + 1 FROM ' . $tableName);
    return intval($stmt->fetchColumn());
}

function ensure_storage_dir($relativeDir)
{
    $dir = dirname(__DIR__, 3) . '/storage/' . trim($relativeDir, '/');
    if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
        throw new RuntimeException('failed to create storage directory: ' . $dir);
    }
    return $dir;
}

function ensure_project_file_dir($dateYmd, $protocol, $cameraCode, $subDir = '')
{
    global $pdo;
    $category = 'payload';
    $batch = 0;
    $cleanSub = trim(strval($subDir), '/');
    if ($cleanSub === 'raw_upload') {
        $category = 'raw_upload';
    } elseif ($cleanSub === 'frame') {
        $category = 'frame';
    } elseif ($cleanSub === 'payload') {
        $category = 'payload';
    } elseif ($cleanSub === 'image') {
        $category = 'image';
    } elseif (preg_match('/^embedding\/batch_(\d+)$/', $cleanSub, $matches) === 1) {
        $category = 'embedding';
        $batch = intval($matches[1]);
    } elseif ($cleanSub === '') {
        $category = 'payload';
    }
    $relativeDir = storage_resolve_relative_dir($pdo, $category, array(
        'date' => strval($dateYmd),
        'protocol' => intval($protocol),
        'camera' => strval($cameraCode),
        'batch' => intval($batch)
    ));
    $dir = dirname(__DIR__, 3) . '/' . trim($relativeDir, '/');
    if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
        throw new RuntimeException('failed to create storage directory: ' . $dir);
    }
    return $dir;
}

function build_project_file_relative_path($dateYmd, $protocol, $cameraCode, $filename, $subDir = '')
{
    global $pdo;
    $category = 'payload';
    $batch = 0;
    $cleanSub = trim(strval($subDir), '/');
    if ($cleanSub === 'raw_upload') {
        $category = 'raw_upload';
    } elseif ($cleanSub === 'frame') {
        $category = 'frame';
    } elseif ($cleanSub === 'payload') {
        $category = 'payload';
    } elseif ($cleanSub === 'image') {
        $category = 'image';
    } elseif (preg_match('/^embedding\/batch_(\d+)$/', $cleanSub, $matches) === 1) {
        $category = 'embedding';
        $batch = intval($matches[1]);
    } elseif ($cleanSub === '') {
        $category = 'payload';
    }
    $relativeDir = storage_resolve_relative_dir($pdo, $category, array(
        'date' => strval($dateYmd),
        'protocol' => intval($protocol),
        'camera' => strval($cameraCode),
        'batch' => intval($batch)
    ));
    return storage_relative_file_path($relativeDir, $filename);
}

function store_protocol_frame_binary($protocol, $eventTimestamp, $cameraCode, $frameIndex, $rawFrameBinary)
{
    $dateYmd = date('Ymd', intval($eventTimestamp / 1000));
    $dir = ensure_project_file_dir($dateYmd, $protocol, $cameraCode, 'frame');
    $filename = sprintf('%s_%s_f%d_frame.bin', $cameraCode, $eventTimestamp, intval($frameIndex));
    $path = $dir . '/' . $filename;
    $bytes = @file_put_contents($path, is_string($rawFrameBinary) ? $rawFrameBinary : '', LOCK_EX);
    if ($bytes === false) {
        throw new RuntimeException('failed to write frame file: ' . $path);
    }
    return build_project_file_relative_path($dateYmd, $protocol, $cameraCode, $filename, 'frame');
}

function store_protocol_payload_in_project_file($protocol, $eventTimestamp, $cameraCode, $frameIndex, $payloadBinary, $extension)
{
    $dateYmd = date('Ymd', intval($eventTimestamp / 1000));
    $subDir = strtolower(ltrim(strval($extension), '.')) === 'jpg' ? 'image' : 'payload';
    $dir = ensure_project_file_dir($dateYmd, $protocol, $cameraCode, $subDir);
    $filename = sprintf('%s_%s_f%d_payload.%s', $cameraCode, $eventTimestamp, intval($frameIndex), ltrim($extension, '.'));
    $path = $dir . '/' . $filename;
    $bytes = @file_put_contents($path, is_string($payloadBinary) ? $payloadBinary : '', LOCK_EX);
    if ($bytes === false) {
        throw new RuntimeException('failed to write payload file: ' . $path);
    }
    return build_project_file_relative_path($dateYmd, $protocol, $cameraCode, $filename, $subDir);
}

function store_protocol_vector_in_project_file($protocol, $eventTimestamp, $cameraCode, $batchId, $frameIndex, $itemIndex, $vectorBinary)
{
    $dateYmd = date('Ymd', intval($eventTimestamp / 1000));
    $subDir = 'embedding/batch_' . intval($batchId);
    $dir = ensure_project_file_dir($dateYmd, $protocol, $cameraCode, $subDir);
    $filename = sprintf('%s_%s_f%d_v%d.bin', $cameraCode, $eventTimestamp, intval($frameIndex), intval($itemIndex));
    $path = $dir . '/' . $filename;
    $bytes = @file_put_contents($path, is_string($vectorBinary) ? $vectorBinary : '', LOCK_EX);
    if ($bytes === false) {
        throw new RuntimeException('failed to write vector file: ' . $path);
    }
    return build_project_file_relative_path($dateYmd, $protocol, $cameraCode, $filename, $subDir);
}

function store_protocol_payload_file($relativeDir, $filename, $binary)
{
    $dir = ensure_storage_dir($relativeDir);
    $path = $dir . '/' . $filename;
    $bytes = @file_put_contents($path, $binary, LOCK_EX);
    if ($bytes === false) {
        throw new RuntimeException('failed to write payload file: ' . $path);
    }
    return 'charging-aiot-php/storage/' . trim($relativeDir, '/') . '/' . $filename;
}

function encode_json_text($payload)
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json === false ? '{}' : $json;
}

function save_parsed_frames($pdo, $parseResult)
{
    $protocol = intval($parseResult->protocol);
    $tableName = ensure_message_table_exists($pdo, $protocol);
    $batchId = next_batch_id($pdo, $tableName);
    $cameraCode = build_camera_code($parseResult->camId);

    $insert101Stmt = $pdo->prepare(
        'INSERT INTO message_101_records (
            batch_id, camera_id, event_timestamp_ms, track_id, obj_type, x1, y1, x2, y2, conf, object_index,
            protocol_version, frame_header, frame_tail, crc_value, frame_length, raw_protocol_hex, normalized_json
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $insert102Stmt = $pdo->prepare(
        'INSERT INTO message_102_records (
            batch_id, camera_id, event_timestamp_ms, track_id, obj_type, information, person_name, status_text,
            feature_data, vector_index, embedding_dim, embedding_byte_length, embedding_file_path, embedding_preview,
            protocol_version, frame_header, frame_tail, crc_value, frame_length, raw_protocol_hex, normalized_json
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $insert103Stmt = $pdo->prepare(
        'INSERT INTO message_103_records (
            batch_id, camera_id, event_timestamp_ms, track_id, obj_type, person_count, car_count, frame_image_url,
            image_fetch_status, local_image_path, image_index, image_byte_length, protocol_version, frame_header,
            frame_tail, crc_value, frame_length, raw_protocol_hex, image_downloaded_at, error_message, normalized_json
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $wsEvents = array();
    $pdo->beginTransaction();
    try {
        foreach ($parseResult->frames as $frameIndex => $frameVO) {
            $eventTimestamp = intval($frameVO->timestamp);
            $createdAt = date('Y-m-d H:i:s');
            $rawProtocolHex = build_raw_protocol_hex($frameVO->rawFrameBinary);
            $frameFilePath = store_protocol_frame_binary($protocol, $eventTimestamp, $cameraCode, $frameIndex, $frameVO->rawFrameBinary);

            if ($protocol === 101) {
                $targets = is_array($frameVO->targets) ? $frameVO->targets : array();
                if (empty($targets)) {
                    $targets[] = array('type' => 0, 'tid' => 0, 'x1' => 0, 'y1' => 0, 'x2' => 0, 'y2' => 0);
                }

                foreach ($targets as $targetIndex => $target) {
                    $normalizedJson = encode_json_text(array(
                        'protocol' => 101,
                        'camera_id' => $cameraCode,
                        'cam_id' => intval($frameVO->camId),
                        'timestamp' => $eventTimestamp,
                        'frame_seq' => intval($frameVO->frameSeq),
                        'frame_index' => intval($frameIndex),
                        'frame_target_count' => intval($frameVO->count),
                        'frame_file_path' => $frameFilePath,
                        'objects' => $targets
                    ));

                    $insert101Stmt->execute(array(
                        $batchId,
                        $cameraCode,
                        $eventTimestamp,
                        intval(isset($target['tid']) ? $target['tid'] : 0),
                        intval(isset($target['type']) ? $target['type'] : 0),
                        intval(isset($target['x1']) ? $target['x1'] : 0),
                        intval(isset($target['y1']) ? $target['y1'] : 0),
                        intval(isset($target['x2']) ? $target['x2'] : 0),
                        intval(isset($target['y2']) ? $target['y2'] : 0),
                        isset($target['conf']) ? strval($target['conf']) : null,
                        intval($targetIndex),
                        intval($frameVO->protocolVersion),
                        intval($frameVO->frameHeader),
                        intval($frameVO->frameTail),
                        intval($frameVO->crc),
                        intval($frameVO->frameLength),
                        $rawProtocolHex,
                        $normalizedJson
                    ));

                    $recordId = intval($pdo->lastInsertId());
                    $wsEvents[] = array(
                        'record_id' => $recordId,
                        'cam_id' => intval($frameVO->camId),
                        'camera_id' => $cameraCode,
                        'protocol_id' => 101,
                        'timestamp' => $eventTimestamp,
                        'create_time' => $createdAt,
                        'batch_id' => $batchId,
                        'details' => array(
                            'count' => 1,
                            'frame_seq' => intval($frameVO->frameSeq),
                            'targets' => array(array(
                                'type' => intval(isset($target['type']) ? $target['type'] : 0),
                                'tid' => intval(isset($target['tid']) ? $target['tid'] : 0),
                                'x1' => intval(isset($target['x1']) ? $target['x1'] : 0),
                                'y1' => intval(isset($target['y1']) ? $target['y1'] : 0),
                                'x2' => intval(isset($target['x2']) ? $target['x2'] : 0),
                                'y2' => intval(isset($target['y2']) ? $target['y2'] : 0),
                                'object_index' => intval($targetIndex)
                            )),
                            'payload_hex_rebuilt' => build_101_payload_hex(array($target)),
                            'conf' => isset($target['conf']) ? $target['conf'] : null,
                            'frame_target_count' => intval($frameVO->count),
                            'frame_file_path' => $frameFilePath
                        )
                    );
                }

                continue;
            }

            if ($protocol === 102) {
                $payloadBinary = is_string($frameVO->payloadBinary) ? $frameVO->payloadBinary : '';
                $payloadFilePath = store_protocol_payload_in_project_file(102, $eventTimestamp, $cameraCode, $frameIndex, $payloadBinary, 'bin');
                $items = is_array($frameVO->payloadItems) ? $frameVO->payloadItems : array();
                if (empty($items)) {
                    $items[] = array('type' => 0, 'tid' => 0, 'vector_binary' => '');
                }

                foreach ($items as $itemIndex => $item) {
                    $vectorBinary = isset($item['vector_binary']) && is_string($item['vector_binary']) ? $item['vector_binary'] : '';
                    $vectorBase64 = base64_encode($vectorBinary);
                    $vectorPath = store_protocol_vector_in_project_file(102, $eventTimestamp, $cameraCode, $batchId, $frameIndex, $itemIndex, $vectorBinary);
                    $normalizedJson = encode_json_text(array(
                        'protocol' => 102,
                        'camera_id' => $cameraCode,
                        'cam_id' => intval($frameVO->camId),
                        'timestamp' => $eventTimestamp,
                        'frame_seq' => intval($frameVO->frameSeq),
                        'frame_index' => intval($frameIndex),
                        'frame_vector_count' => intval($frameVO->count),
                        'frame_file_path' => $frameFilePath,
                        'payload_file_path' => $payloadFilePath,
                        'embedding_byte_length_each' => strlen($vectorBinary),
                        'obj_type' => intval(isset($item['type']) ? $item['type'] : 0),
                        'track_id' => intval(isset($item['tid']) ? $item['tid'] : 0),
                        'vector_index' => intval($itemIndex),
                        'embedding_file_path' => $vectorPath
                    ));

                    $insert102Stmt->execute(array(
                        $batchId,
                        $cameraCode,
                        $eventTimestamp,
                        intval(isset($item['tid']) ? $item['tid'] : 0),
                        intval(isset($item['type']) ? $item['type'] : 0),
                        'received',
                        null,
                        'received',
                        $vectorBase64,
                        intval($itemIndex),
                        intval(isset($item['embedding_dim']) ? $item['embedding_dim'] : 0),
                        strlen($vectorBinary),
                        $vectorPath,
                        strtoupper(bin2hex(substr($vectorBinary, 0, 32))),
                        intval($frameVO->protocolVersion),
                        intval($frameVO->frameHeader),
                        intval($frameVO->frameTail),
                        intval($frameVO->crc),
                        intval($frameVO->frameLength),
                        $rawProtocolHex,
                        $normalizedJson
                    ));

                    $recordId = intval($pdo->lastInsertId());
                    $wsEvents[] = array(
                        'record_id' => $recordId,
                        'cam_id' => intval($frameVO->camId),
                        'camera_id' => $cameraCode,
                        'protocol_id' => 102,
                        'timestamp' => $eventTimestamp,
                        'create_time' => $createdAt,
                        'batch_id' => $batchId,
                        'details' => array(
                            'frame_seq' => intval($frameVO->frameSeq),
                            'count' => intval($frameVO->count),
                            'obj_type' => intval(isset($item['type']) ? $item['type'] : 0),
                            'tid' => intval(isset($item['tid']) ? $item['tid'] : 0),
                            'payload_size' => strlen($vectorBinary),
                            'vector_base64_preview' => substr($vectorBase64, 0, 96),
                            'vector_hex_preview' => bin2hex(substr($vectorBinary, 0, 32)),
                            'vector_payload_hex_preview' => bin2hex(substr($vectorBinary, 0, 64)),
                            'vector_base64' => $vectorBase64,
                            'embedding_dim' => intval(isset($item['embedding_dim']) ? $item['embedding_dim'] : 0),
                            'frame_file_path' => $frameFilePath,
                            'payload_file_path' => $payloadFilePath,
                            'embedding_file_path' => $vectorPath,
                            'embedding_preview' => strtoupper(bin2hex(substr($vectorBinary, 0, 32))),
                            'status' => 'received'
                        )
                    );
                }

                continue;
            }

            if ($protocol === 103) {
                $payloadBinary = is_string($frameVO->payloadBinary) ? $frameVO->payloadBinary : '';
                $payloadFilePath = store_protocol_payload_in_project_file(103, $eventTimestamp, $cameraCode, $frameIndex, $payloadBinary, 'bin');
                $isJpegPayload = intval($frameVO->payloadType) === 1;
                $imagePath = $isJpegPayload
                    ? store_protocol_payload_in_project_file(103, $eventTimestamp, $cameraCode, $frameIndex, $payloadBinary, 'jpg')
                    : null;
                $normalizedJson = encode_json_text(array(
                    'protocol' => 103,
                    'camera_id' => $cameraCode,
                    'cam_id' => intval($frameVO->camId),
                    'timestamp' => $eventTimestamp,
                    'frame_seq' => intval($frameVO->frameSeq),
                    'frame_target_count' => intval($frameVO->count),
                    'frame_index' => intval($frameIndex),
                    'payload_type' => intval($frameVO->payloadType),
                    'track_id' => intval($frameVO->trackId),
                    'total_packets' => intval($frameVO->startTimestamp),
                    'packet_index' => intval($frameVO->endTimestamp),
                    'frame_file_path' => $frameFilePath,
                    'payload_file_path' => $payloadFilePath,
                    'local_image_path' => $imagePath,
                    'image_byte_length' => strlen($payloadBinary)
                ));

                $insert103Stmt->execute(array(
                    $batchId,
                    $cameraCode,
                    $eventTimestamp,
                    intval($frameVO->trackId),
                    null,
                    0,
                    0,
                    null,
                    $isJpegPayload ? 'success' : 'binary_only',
                    $imagePath,
                    intval($frameIndex),
                    strlen($payloadBinary),
                    intval($frameVO->protocolVersion),
                    intval($frameVO->frameHeader),
                    intval($frameVO->frameTail),
                    intval($frameVO->crc),
                    intval($frameVO->frameLength),
                    $rawProtocolHex,
                    $createdAt,
                    null,
                    $normalizedJson
                ));

                $recordId = intval($pdo->lastInsertId());
                $base64Image = base64_encode($payloadBinary);
                $wsEvents[] = array(
                    'record_id' => $recordId,
                    'cam_id' => intval($frameVO->camId),
                    'camera_id' => $cameraCode,
                    'protocol_id' => 103,
                    'timestamp' => $eventTimestamp,
                    'create_time' => $createdAt,
                    'batch_id' => $batchId,
                    'details' => array(
                        'frame_seq' => intval($frameVO->frameSeq),
                        'count' => intval($frameVO->count),
                        'payload_type' => intval($frameVO->payloadType),
                        'tid' => intval($frameVO->trackId),
                        'total_packets' => intval($frameVO->startTimestamp),
                        'packet_index' => intval($frameVO->endTimestamp),
                        'payload_size' => strlen($payloadBinary),
                        'image_hex_preview' => bin2hex(substr($payloadBinary, 0, 64)),
                        'base64_image' => $isJpegPayload ? $base64Image : null,
                        'image_data_url' => $isJpegPayload ? ('data:image/jpeg;base64,' . $base64Image) : null,
                        'image_fetch_status' => $isJpegPayload ? 'success' : 'binary_only',
                        'frame_file_path' => $frameFilePath,
                        'payload_file_path' => $payloadFilePath,
                        'local_image_path' => $imagePath,
                        'person_count' => 0,
                        'car_count' => 0
                    )
                );
            }
        }

        $pdo->commit();
        return $wsEvents;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function parseBinaryStream($binaryData, $expectedProtocol, $expectedCamId)
{
    $totalLen = strlen($binaryData);
    if ($totalLen < 20) {
        throw new RuntimeException('binary stream is too short, minimum length is 20 bytes');
    }

    $cursor = 0;
    $frames = array();
    $frameSeq = 1;

    while (($cursor + 20) <= $totalLen) {
        if (byte_at($binaryData, $cursor) !== 0x55
            || byte_at($binaryData, $cursor + 1) !== 0xAA) {
            throw new RuntimeException('invalid frame header at offset ' . $cursor);
        }

        $frameHeader = 0x55AA;
        $protocolVersion = 1;
        $frameLength = (byte_at($binaryData, $cursor + 2) << 8) | byte_at($binaryData, $cursor + 3);
        $protocolId = (byte_at($binaryData, $cursor + 4) << 8) | byte_at($binaryData, $cursor + 5);

        if ($frameLength < 20) {
            throw new RuntimeException('invalid frame length ' . $frameLength . ' at offset ' . $cursor);
        }

        if (($cursor + $frameLength) > $totalLen) {
            throw new RuntimeException('incomplete frame data at offset ' . $cursor);
        }

        $camId = read_u32_le(strrev(substr($binaryData, $cursor + 6, 4)), 0);
        $camId = (byte_at($binaryData, $cursor + 6) << 24)
            | (byte_at($binaryData, $cursor + 7) << 16)
            | (byte_at($binaryData, $cursor + 8) << 8)
            | byte_at($binaryData, $cursor + 9);
        $timestamp = (
            (byte_at($binaryData, $cursor + 10) << 24)
            | (byte_at($binaryData, $cursor + 11) << 16)
            | (byte_at($binaryData, $cursor + 12) << 8)
            | byte_at($binaryData, $cursor + 13)
        ) * 1000;
        $payloadLength = (byte_at($binaryData, $cursor + 14) << 8) | byte_at($binaryData, $cursor + 15);
        $payloadOffset = $cursor + 16;
        $tailOffset = $payloadOffset + $payloadLength;
        if (($tailOffset + 4) > ($cursor + $frameLength)) {
            throw new RuntimeException('payload length exceeds frame boundary at offset ' . $cursor);
        }

        $crc = (byte_at($binaryData, $tailOffset) << 8) | byte_at($binaryData, $tailOffset + 1);
        $tail = (byte_at($binaryData, $tailOffset + 2) << 8) | byte_at($binaryData, $tailOffset + 3);
        if ($tail !== 0xAA55) {
            throw new RuntimeException('invalid frame tail at offset ' . $tailOffset);
        }

        $crcBinary = substr($binaryData, $cursor + 4, $frameLength - 8);
        $crcCalculated = crc16_ccitt_false($crcBinary);
        if ($crcCalculated !== $crc) {
            throw new RuntimeException('crc mismatch at offset ' . $cursor . ': recv=' . sprintf('%u', $crc) . ', calc=' . sprintf('%u', $crcCalculated));
        }

        $rawFrameBinary = substr($binaryData, $cursor, $frameLength);
        $frameMeta = array(
            'raw_frame_binary' => $rawFrameBinary === false ? '' : $rawFrameBinary,
            'protocol_version' => $protocolVersion,
            'frame_seq' => $frameSeq,
            'frame_length' => $frameLength,
            'crc' => $crc,
            'frame_header' => $frameHeader,
            'frame_tail' => $tail
        );

        if ($protocolId !== $expectedProtocol) {
            throw new RuntimeException('protocol mismatch: url=' . $expectedProtocol . ', frame=' . $protocolId);
        }

        if ($expectedCamId !== null && $camId !== $expectedCamId) {
            throw new RuntimeException('cam_id mismatch: url=' . $expectedCamId . ', frame=' . $camId);
        }

        if ($protocolId === 101) {
            if ($payloadLength < 8) {
                throw new RuntimeException('protocol 101 payload size must be at least 8 bytes');
            }

            $count = read_u16_le($binaryData, $payloadOffset);
            $frameMeta['frame_width'] = read_u16_le($binaryData, $payloadOffset + 2);
            $frameMeta['frame_height'] = read_u16_le($binaryData, $payloadOffset + 4);
            $frameMeta['reserved'] = read_u16_le($binaryData, $payloadOffset + 6);

            $targetsByteLength = $payloadLength - 8;
            if (($targetsByteLength % 12) !== 0) {
                throw new RuntimeException('protocol 101 target payload size must be a multiple of 12 bytes');
            }

            $maxCount = intval($targetsByteLength / 12);
            if ($count !== $maxCount) {
                throw new RuntimeException('protocol 101 count does not match payload capacity');
            }

            $targets = array();
            for ($i = 0; $i < $count; $i++) {
                $offset = $payloadOffset + 8 + ($i * 12);
                $x1 = read_u16_le($binaryData, $offset);
                $y1 = read_u16_le($binaryData, $offset + 2);
                $x2 = read_u16_le($binaryData, $offset + 4);
                $y2 = read_u16_le($binaryData, $offset + 6);
                $confRaw = read_u16_le($binaryData, $offset + 8);
                $tid = read_u16_le($binaryData, $offset + 10);
                $targets[] = (new TargetVO(0, $tid, $x1, $y1, $x2, $y2, $confRaw))->toArray();
            }

            $frames[] = new StreamFrameVO(101, $camId, $timestamp, $count, $targets, null, null, $frameMeta);
        } elseif ($protocolId === 102) {
            $payload = substr($binaryData, $payloadOffset, $payloadLength);
            if ($payload === false) {
                throw new RuntimeException('failed to read protocol 102 payload');
            }

            if ($payloadLength < 4) {
                throw new RuntimeException('protocol 102 payload size must be at least 4 bytes');
            }

            $tid = read_u16_le($payload, 0);
            $embeddingDim = read_u16_le($payload, 2);
            $vectorBinary = substr($payload, 4);
            if ($vectorBinary === false) {
                throw new RuntimeException('failed to read protocol 102 vector payload');
            }
            if (strlen($vectorBinary) !== ($embeddingDim * 4)) {
                throw new RuntimeException('protocol 102 vector byte length does not match dimension');
            }

            $preview = array();
            $previewCount = min(4, $embeddingDim);
            for ($i = 0; $i < $previewCount; $i++) {
                $preview[] = read_f32_le($vectorBinary, $i * 4);
            }
            $items = array();
            $items[] = array(
                'type' => 0,
                'tid' => $tid,
                'vector_binary' => $vectorBinary,
                'embedding_dim' => $embeddingDim,
                'vector_preview' => $preview
            );

            $frameMeta['payload_items'] = $items;
            $frames[] = new StreamFrameVO(102, $camId, $timestamp, 1, array(), null, $payload, $frameMeta);
        } elseif ($protocolId === 103) {
            if ($payloadLength < 16) {
                throw new RuntimeException('protocol 103 payload size must be at least 16 bytes');
            }

            $payloadType = read_u16_le($binaryData, $payloadOffset);
            $trackId = read_u16_le($binaryData, $payloadOffset + 2);
            $totalPackets = read_u16_le($binaryData, $payloadOffset + 4);
            $packetIndex = read_u16_le($binaryData, $payloadOffset + 6);
            $mediaTotalSize = read_u32_le($binaryData, $payloadOffset + 8);
            $chunkLength = read_u32_le($binaryData, $payloadOffset + 12);
            $payload = substr($binaryData, $payloadOffset + 16, $payloadLength - 16);
            if ($payload === false) {
                throw new RuntimeException('failed to read protocol 103 media payload');
            }
            if (strlen($payload) !== $chunkLength) {
                throw new RuntimeException('protocol 103 chunk length does not match payload size');
            }

            $frameMeta['payload_type'] = $payloadType;
            $frameMeta['track_id'] = $trackId;
            $frameMeta['start_timestamp'] = $totalPackets;
            $frameMeta['end_timestamp'] = $packetIndex;
            $frameMeta['media_total_size'] = $mediaTotalSize;
            $frameMeta['chunk_length'] = $chunkLength;
            $frames[] = new StreamFrameVO(103, $camId, $timestamp, $totalPackets, array(), base64_encode($payload), $payload, $frameMeta);
        } else {
            throw new RuntimeException('unsupported protocol in frame: ' . $protocolId);
        }

        $cursor += $frameLength;
        $frameSeq += 1;
    }

    if ($cursor !== $totalLen) {
        throw new RuntimeException('trailing bytes found after parsed frames');
    }

    if (empty($frames)) {
        throw new RuntimeException('no frame parsed from binary stream');
    }

    return new ParseResultVO($expectedProtocol, $expectedCamId, count($frames), $frames);
}

function handle_upload_stream_request($pdo)
{
    try {
        $requestDTO = UploadStreamRequestDTO::fromGlobals();
        $parseResult = parseBinaryStream($requestDTO->binaryData, $requestDTO->protocol, $requestDTO->camId);
        $storedFile = persist_uploaded_binary_file($requestDTO, $parseResult);
        $events = save_parsed_frames($pdo, $parseResult);
        emit_stream_ws_events($events);
        if (intval($requestDTO->protocol) === 102) {
            try {
                trigger_face_match_service_async(face_match_trigger_limit(), face_match_trigger_topk());
            } catch (Exception $e) {
                // 非阻塞触发失败不影响主流程
            }
        }

        $responseData = $parseResult->toArray();
        $responseData['stored_file'] = $storedFile['relative_path'];
        $responseData['stored_file_size'] = $storedFile['size'];

        respond_success($responseData, 'stream parsed successfully');
    } catch (InvalidArgumentException $e) {
        respond_error($e->getMessage(), 400);
    } catch (RuntimeException $e) {
        respond_error($e->getMessage(), 422);
    } catch (Exception $e) {
        respond_error('internal server error: ' . $e->getMessage(), 500);
    }
}

$scriptName = isset($_SERVER['SCRIPT_FILENAME']) ? (string) $_SERVER['SCRIPT_FILENAME'] : '';
if ($scriptName !== '' && realpath($scriptName) === realpath(__FILE__)) {
    handle_upload_stream_request($pdo);
}
