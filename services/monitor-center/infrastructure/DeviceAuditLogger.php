<?php

class DeviceAuditLogger
{
    const RUNTIME_DIR = __DIR__ . '/../runtime';
    const ROLLBACK_LOG_FILE = 'device_tx_rollback_audit.jsonl';
    const ERROR_LOG_FILE = 'device_error_audit.jsonl';

    public static function ensureAuditTables(PDO $pdo)
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sys_device_audit_log (
            log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(32) NOT NULL,
            action_name VARCHAR(64) DEFAULT NULL,
            device_id BIGINT UNSIGNED DEFAULT NULL,
            group_id BIGINT UNSIGNED DEFAULT NULL,
            path_name VARCHAR(128) DEFAULT NULL,
            result_status TINYINT DEFAULT 1,
            error_message VARCHAR(500) DEFAULT NULL,
            request_payload LONGTEXT DEFAULT NULL,
            response_payload LONGTEXT DEFAULT NULL,
            client_ip VARCHAR(64) DEFAULT NULL,
            create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_audit_event_type (event_type),
            INDEX idx_audit_action_name (action_name),
            INDEX idx_audit_path_name (path_name),
            INDEX idx_audit_create_time (create_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public static function logOperation($pdo, array $payload)
    {
        if (!($pdo instanceof PDO)) {
            return;
        }

        try {
            $stmt = $pdo->prepare('INSERT INTO sys_device_audit_log
                (event_type, action_name, device_id, group_id, path_name, result_status, error_message, request_payload, response_payload, client_ip, create_time)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');

            $requestPayload = self::encodeJson(isset($payload['request_payload']) ? $payload['request_payload'] : null);
            $responsePayload = self::encodeJson(isset($payload['response_payload']) ? $payload['response_payload'] : null);

            $stmt->execute([
                isset($payload['event_type']) ? (string) $payload['event_type'] : 'UNKNOWN',
                isset($payload['action_name']) ? (string) $payload['action_name'] : '',
                isset($payload['device_id']) ? intval($payload['device_id']) : null,
                isset($payload['group_id']) ? intval($payload['group_id']) : null,
                isset($payload['path_name']) ? (string) $payload['path_name'] : null,
                isset($payload['result_status']) ? intval($payload['result_status']) : 1,
                isset($payload['error_message']) ? (string) $payload['error_message'] : null,
                $requestPayload,
                $responsePayload,
                isset($payload['client_ip']) ? (string) $payload['client_ip'] : null
            ]);
        } catch (Exception $e) {
            self::logErrorFile('audit table insert failed: ' . $e->getMessage(), 500, [
                'event_type' => isset($payload['event_type']) ? $payload['event_type'] : null,
                'action_name' => isset($payload['action_name']) ? $payload['action_name'] : null
            ]);
        }
    }

    public static function logRollbackFile($pathName, $reason, array $context = [])
    {
        self::appendJsonLine(self::ROLLBACK_LOG_FILE, [
            'time' => date('Y-m-d H:i:s'),
            'path_name' => (string) $pathName,
            'reason' => (string) $reason,
            'context' => $context
        ]);
    }

    public static function logErrorFile($message, $httpCode = 500, array $context = [])
    {
        self::appendJsonLine(self::ERROR_LOG_FILE, [
            'time' => date('Y-m-d H:i:s'),
            'http_code' => intval($httpCode),
            'message' => (string) $message,
            'context' => $context
        ]);
    }

    public static function readRecentLogFile($fileName, $limit = 50)
    {
        $limit = intval($limit);
        if ($limit <= 0) {
            $limit = 50;
        }

        $filePath = self::buildLogFilePath($fileName);
        if (!is_file($filePath)) {
            return [];
        }

        $lines = @file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || empty($lines)) {
            return [];
        }

        $slice = array_slice($lines, -$limit);
        $rows = [];

        foreach (array_reverse($slice) as $line) {
            $decoded = json_decode((string) $line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    private static function appendJsonLine($fileName, array $data)
    {
        self::ensureRuntimeDir();
        $filePath = self::buildLogFilePath($fileName);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        @file_put_contents($filePath, $json . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private static function ensureRuntimeDir()
    {
        if (!is_dir(self::RUNTIME_DIR)) {
            @mkdir(self::RUNTIME_DIR, 0777, true);
        }
    }

    private static function buildLogFilePath($fileName)
    {
        return rtrim(self::RUNTIME_DIR, '/\\') . DIRECTORY_SEPARATOR . $fileName;
    }

    private static function encodeJson($payload)
    {
        if ($payload === null) {
            return null;
        }

        if (is_string($payload)) {
            return $payload;
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? null : $json;
    }
}
