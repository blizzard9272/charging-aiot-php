<?php

require_once __DIR__ . '/../integrations/MediaMtxClient.php';
require_once __DIR__ . '/../infrastructure/DeviceAuditLogger.php';

class DeviceService
{
    private $pdo;
    private $mediaMtxClient;

    public function __construct(PDO $pdo, $mediaMtxClient = null)
    {
        $this->pdo = $pdo;
        $this->mediaMtxClient = $mediaMtxClient instanceof MediaMtxClient
            ? $mediaMtxClient
            : MediaMtxClient::getInstance();
    }

    public function addCameraDevice(array $data)
    {
        $ipAddress = trim((string) ($data['ip_address'] ?? ''));
        $username = trim((string) ($data['username'] ?? ''));
        $password = trim((string) ($data['password'] ?? ''));
        $pathName = trim((string) ($data['path_name'] ?? ''));
        $streamType = intval($data['stream_type'] ?? 0);
        $port = intval($data['port'] ?? 554);
        $recordEnabled = intval($data['record_enabled'] ?? 0) === 1;
        $recordPath = trim((string) ($data['record_path'] ?? ''));
        $recordFormat = strtolower(trim((string) ($data['record_format'] ?? 'fmp4')));
        $recordPartDuration = intval($data['record_part_duration'] ?? 60);

        if ($ipAddress === '' || $username === '' || $password === '' || $pathName === '') {
            throw new InvalidArgumentException('ip_address, username, password, path_name are required');
        }
        if (!in_array($streamType, [1, 2], true)) {
            throw new InvalidArgumentException('stream_type must be 1 or 2');
        }
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', $pathName)) {
            throw new InvalidArgumentException('path_name can only contain letters, numbers, underscore and dash');
        }
        if ($port <= 0 || $port > 65535) {
            $port = 554;
        }
        if (!in_array($recordFormat, ['fmp4', 'mp4', 'mpegts'], true)) {
            throw new InvalidArgumentException('record_format must be one of: fmp4, mp4, mpegts');
        }
        if ($recordPartDuration <= 0) {
            $recordPartDuration = 60;
        }

        $pathExistsStmt = $this->pdo->prepare('SELECT path_id FROM sys_camera_path WHERE path_name = ? LIMIT 1');
        $pathExistsStmt->execute([$pathName]);
        if ($pathExistsStmt->fetch(PDO::FETCH_ASSOC)) {
            throw new InvalidArgumentException('path_name already exists in sys_camera_path');
        }

        $sourceUrl = $this->buildHikvisionSourceUrl($ipAddress, $username, $password, $streamType, $port);
        $deviceId = null;

        $this->pdo->beginTransaction();
        try {
            $deviceUuid = trim((string) ($data['device_uuid'] ?? ''));
            if ($deviceUuid === '') {
                $deviceUuid = str_replace('.', '', uniqid('', true));
            }

            $deviceSql = 'INSERT INTO sys_device
                          (device_uuid, group_id, device_name, brand, model, protocol_type, ip_address, port, username, password, location, online_status, status_flag, create_time, modify_time)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())';
            $deviceStmt = $this->pdo->prepare($deviceSql);
            $deviceStmt->execute([
                $deviceUuid,
                isset($data['group_id']) ? intval($data['group_id']) : null,
                isset($data['device_name']) && trim((string) $data['device_name']) !== '' ? trim((string) $data['device_name']) : $pathName,
                isset($data['brand']) ? trim((string) $data['brand']) : '',
                isset($data['model']) ? trim((string) $data['model']) : '',
                isset($data['protocol_type']) ? intval($data['protocol_type']) : 1,
                $ipAddress,
                $port,
                $username,
                $password,
                isset($data['location']) ? trim((string) $data['location']) : '',
                isset($data['online_status']) ? intval($data['online_status']) : 0,
                isset($data['status_flag']) ? intval($data['status_flag']) : 1
            ]);

            $deviceId = intval($this->pdo->lastInsertId());
            if ($deviceId <= 0) {
                throw new RuntimeException('failed to create sys_device row');
            }

            $pathSql = 'INSERT INTO sys_camera_path
                        (path_uuid, device_id, path_name, source_url, stream_type, record_enabled, record_path, record_format, record_part_duration, status_flag, modify_time)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())';
            $pathStmt = $this->pdo->prepare($pathSql);
            $pathUuid = str_replace('.', '', uniqid('', true));
            $pathStmt->execute([
                $pathUuid,
                $deviceId,
                $pathName,
                $sourceUrl,
                $streamType,
                $recordEnabled ? 1 : 0,
                $recordPath,
                $recordFormat,
                $recordPartDuration,
                isset($data['path_status_flag']) ? intval($data['path_status_flag']) : 1
            ]);

            $mtxResult = $this->mediaMtxClient->ensurePathConfig($pathName, [
                'source' => $sourceUrl,
                'record' => $recordEnabled,
                'recordPath' => $recordPath,
                'recordFormat' => $recordFormat,
                'recordPartDuration' => $recordPartDuration
            ]);
            if (!is_array($mtxResult) || empty($mtxResult['success'])) {
                $code = isset($mtxResult['code']) ? intval($mtxResult['code']) : 500;
                $msg = isset($mtxResult['data'])
                    ? (is_string($mtxResult['data']) ? $mtxResult['data'] : json_encode($mtxResult['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                    : 'MediaMTX path config validation failed';
                throw new RuntimeException('MediaMTX path config validation failed(' . $code . '): ' . $msg, $code);
            }

            $this->pdo->commit();

            return [
                'device_id' => $deviceId,
                'path_uuid' => $pathUuid,
                'path_name' => $pathName,
                'source_url' => $sourceUrl,
                'media_mtx' => isset($mtxResult['data']) ? $mtxResult['data'] : ''
            ];
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            DeviceAuditLogger::logRollbackFile($pathName, $e->getMessage(), [
                'stage' => 'addCameraDevice',
                'device_id' => $deviceId,
                'source_url' => $sourceUrl
            ]);

            DeviceAuditLogger::logOperation($this->pdo, [
                'event_type' => 'ROLLBACK',
                'action_name' => 'createDevice',
                'device_id' => $deviceId,
                'group_id' => isset($data['group_id']) ? intval($data['group_id']) : null,
                'path_name' => $pathName,
                'result_status' => 0,
                'error_message' => $e->getMessage(),
                'request_payload' => $data
            ]);

            throw $e;
        }
    }

    private function buildHikvisionSourceUrl($ipAddress, $username, $password, $streamType, $port)
    {
        $channel = intval($streamType) === 1 ? 101 : 102;

        return sprintf(
            'rtsp://%s:%s@%s:%d/Streaming/Channels/%d',
            rawurlencode($username),
            rawurlencode($password),
            $ipAddress,
            intval($port),
            $channel
        );
    }
}
