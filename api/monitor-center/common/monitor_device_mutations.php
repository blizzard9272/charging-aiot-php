<?php

function has_stream_payload($payload)
{
    if (!is_array($payload)) {
        return false;
    }

    $keys = ['pathName', 'sourceUrl', 'streamType', 'recordEnabled', 'recordPath', 'recordFormat', 'recordPartDuration', 'pathStatusFlag'];
    foreach ($keys as $key) {
        if (array_key_exists($key, $payload) && $payload[$key] !== null && $payload[$key] !== '') {
            return true;
        }
    }
    return false;
}

function build_hikvision_source_url($ipAddress, $username, $password, $streamType, $port)
{
    $channel = intval($streamType) === 2 ? 102 : 101;
    $safePort = intval($port);
    if ($safePort <= 0 || $safePort > 65535) {
        $safePort = 554;
    }

    return sprintf(
        'rtsp://%s:%s@%s:%d/Streaming/Channels/%d',
        rawurlencode((string) $username),
        rawurlencode((string) $password),
        (string) $ipAddress,
        $safePort,
        $channel
    );
}

function handle_update_device(PDO $pdo)
{
    if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'PUT') {
        response_error('Method not allowed', 405);
    }

    $payload = parse_json_body();
    $deviceId = normalize_int_or_null(isset($payload['deviceId']) ? $payload['deviceId'] : null);
    if ($deviceId === null || $deviceId <= 0) {
        response_error('Missing valid deviceId', 400);
    }

    $existingStmt = $pdo->prepare('SELECT * FROM sys_device WHERE device_id = ? LIMIT 1');
    $existingStmt->execute([$deviceId]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        response_error('Device not found', 404);
    }

    $groupId = array_key_exists('groupId', $payload) ? normalize_int_or_null($payload['groupId']) : normalize_int_or_null($existing['group_id']);
    $deviceName = array_key_exists('deviceName', $payload) ? normalize_string($payload['deviceName']) : (string) $existing['device_name'];
    $deviceUuid = array_key_exists('deviceUuid', $payload) ? normalize_string($payload['deviceUuid']) : (string) $existing['device_uuid'];
    $brand = array_key_exists('brand', $payload) ? normalize_string($payload['brand']) : (string) $existing['brand'];
    $model = array_key_exists('model', $payload) ? normalize_string($payload['model']) : (string) $existing['model'];
    $protocolType = array_key_exists('protocolType', $payload) ? normalize_int_or_null($payload['protocolType']) : normalize_int_or_null($existing['protocol_type']);
    $ipAddress = array_key_exists('ipAddress', $payload) ? normalize_string($payload['ipAddress']) : (string) $existing['ip_address'];
    $port = array_key_exists('port', $payload) ? normalize_int_or_null($payload['port']) : normalize_int_or_null($existing['port']);
    $username = array_key_exists('username', $payload) ? normalize_string($payload['username']) : (string) $existing['username'];
    $password = array_key_exists('password', $payload) ? normalize_string($payload['password']) : (string) $existing['password'];
    $location = array_key_exists('location', $payload) ? normalize_string($payload['location']) : (string) $existing['location'];
    $onlineStatus = array_key_exists('onlineStatus', $payload) ? normalize_int_or_null($payload['onlineStatus']) : normalize_int_or_null($existing['online_status']);
    $statusFlag = array_key_exists('statusFlag', $payload) ? normalize_int_or_null($payload['statusFlag']) : normalize_int_or_null($existing['status_flag']);

    if ($onlineStatus !== null && !in_array($onlineStatus, [0, 1], true)) {
        response_error('Invalid onlineStatus, only 0 or 1 is supported', 400);
    }

    $requestContainsOnlineStatus = array_key_exists('onlineStatus', $payload) || array_key_exists('online_status', $payload);
    $existingOnlineStatus = normalize_int_or_null($existing['online_status']);
    $isTurningOnline = ($onlineStatus === 1) && ($requestContainsOnlineStatus || $existingOnlineStatus !== 1);
    $safePortForCheck = ($port === null || $port <= 0) ? 554 : intval($port);

    if ($isTurningOnline && !check_device_online_ready($ipAddress, $safePortForCheck)) {
        write_device_audit($pdo, [
            'event_type' => 'UPDATE',
            'action_name' => 'updateDevice',
            'device_id' => $deviceId,
            'group_id' => $groupId,
            'result_status' => 0,
            'error_message' => 'device offline during update pre-check',
            'request_payload' => $payload
        ]);

        response_error('设备暂时处于离线状态不可使用', 400);
    }

    $mediaMtxClient = MediaMtxClient::getInstance();
    $mediaMtxSyncData = null;
    $mediaMtxWarnings = [];
    $pathNameToCleanup = '';

    $pdo->beginTransaction();
    try {
        $updateSql = 'UPDATE sys_device
                      SET group_id = ?, device_name = ?, device_uuid = ?, brand = ?, model = ?,
                          protocol_type = ?, ip_address = ?, port = ?, username = ?, password = ?,
                          location = ?, online_status = ?, status_flag = ?, modify_time = NOW()
                      WHERE device_id = ?';
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute([
            $groupId,
            $deviceName,
            $deviceUuid,
            $brand,
            $model,
            $protocolType,
            $ipAddress,
            $port,
            $username,
            $password,
            $location,
            $onlineStatus,
            $statusFlag,
            $deviceId
        ]);

        $latestStmt = $pdo->prepare('SELECT * FROM sys_camera_path WHERE device_id = ? ORDER BY path_id DESC LIMIT 1');
        $latestStmt->execute([$deviceId]);
        $latestPath = $latestStmt->fetch(PDO::FETCH_ASSOC);
        $credentialChanged = (
            $ipAddress !== (string) $existing['ip_address'] ||
            intval($port) !== intval($existing['port']) ||
            $username !== (string) $existing['username'] ||
            $password !== (string) $existing['password']
        );
        $shouldSyncStream = has_stream_payload($payload) || ($latestPath && $credentialChanged);

        if ($shouldSyncStream) {
            $oldPathName = $latestPath ? normalize_string($latestPath['path_name']) : '';

            $pathName = array_key_exists('pathName', $payload)
                ? normalize_string($payload['pathName'])
                : ($latestPath ? normalize_string($latestPath['path_name']) : '');
            $streamType = array_key_exists('streamType', $payload)
                ? normalize_int_or_null($payload['streamType'])
                : ($latestPath ? normalize_int_or_null($latestPath['stream_type']) : 1);
            $sourceUrl = array_key_exists('sourceUrl', $payload)
                ? normalize_string($payload['sourceUrl'])
                : ($latestPath ? normalize_string($latestPath['source_url']) : '');
            $recordEnabled = array_key_exists('recordEnabled', $payload)
                ? normalize_int_or_null($payload['recordEnabled'])
                : ($latestPath ? normalize_int_or_null($latestPath['record_enabled']) : 0);
            $recordPath = array_key_exists('recordPath', $payload)
                ? normalize_string($payload['recordPath'])
                : ($latestPath ? normalize_string($latestPath['record_path']) : '');
            $recordFormat = array_key_exists('recordFormat', $payload)
                ? normalize_string($payload['recordFormat'])
                : ($latestPath ? normalize_string($latestPath['record_format']) : 'fmp4');
            $recordPartDuration = array_key_exists('recordPartDuration', $payload)
                ? normalize_int_or_null($payload['recordPartDuration'])
                : ($latestPath ? normalize_int_or_null($latestPath['record_part_duration']) : 60);
            $pathStatusFlag = array_key_exists('pathStatusFlag', $payload)
                ? normalize_int_or_null($payload['pathStatusFlag'])
                : ($latestPath ? normalize_int_or_null($latestPath['status_flag']) : 1);

            if ($streamType === null || !in_array($streamType, [1, 2], true)) {
                $streamType = 1;
            }
            if ($recordEnabled === null) {
                $recordEnabled = 0;
            }
            if ($recordFormat === '') {
                $recordFormat = 'fmp4';
            }
            if ($recordPartDuration === null || $recordPartDuration <= 0) {
                $recordPartDuration = 60;
            }
            if ($pathStatusFlag === null) {
                $pathStatusFlag = 1;
            }
            if ($pathName === '' && $latestPath) {
                $pathName = $oldPathName;
            }

            if ($sourceUrl === '' && $ipAddress !== '' && $username !== '' && $password !== '') {
                $sourceUrl = build_hikvision_source_url($ipAddress, $username, $password, $streamType, $port);
            }
            if ($pathName !== '') {
                $pathExistsStmt = $pdo->prepare('SELECT path_id FROM sys_camera_path WHERE path_name = ? AND device_id <> ? AND status_flag = 1 LIMIT 1');
                $pathExistsStmt->execute([$pathName, $deviceId]);
                if ($pathExistsStmt->fetch(PDO::FETCH_ASSOC)) {
                    throw new InvalidArgumentException('pathName already exists in another device');
                }
            }

            if ($latestPath) {
                $pathUuid = normalize_string($latestPath['path_uuid']);
                if ($pathUuid === '') {
                    $pathUuid = str_replace('.', '', uniqid('', true));
                }

                $updatePathSql = 'UPDATE sys_camera_path
                                  SET path_uuid = ?, device_id = ?, path_name = ?, source_url = ?,
                                      stream_type = ?, record_enabled = ?, record_path = ?,
                                      record_format = ?, record_part_duration = ?, status_flag = ?,
                                      modify_time = NOW()
                                  WHERE path_id = ?';
                $updatePathStmt = $pdo->prepare($updatePathSql);
                $updatePathStmt->execute([
                    $pathUuid,
                    $deviceId,
                    $pathName,
                    $sourceUrl,
                    $streamType,
                    $recordEnabled,
                    $recordPath,
                    $recordFormat,
                    $recordPartDuration,
                    $pathStatusFlag,
                    intval($latestPath['path_id'])
                ]);
            } else {
                if ($pathName === '') {
                    $pathName = 'auto_path_' . $deviceId;
                }

                if ($sourceUrl === '' && $ipAddress !== '' && $username !== '' && $password !== '') {
                    $sourceUrl = build_hikvision_source_url($ipAddress, $username, $password, $streamType, $port);
                }

                if ($sourceUrl !== '') {
                    $insertPathSql = 'INSERT INTO sys_camera_path
                                      (path_uuid, device_id, path_name, source_url, stream_type, record_enabled, record_path, record_format, record_part_duration, status_flag, modify_time)
                                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())';
                    $insertPathStmt = $pdo->prepare($insertPathSql);
                    $insertPathStmt->execute([
                        str_replace('.', '', uniqid('', true)),
                        $deviceId,
                        $pathName,
                        $sourceUrl,
                        $streamType,
                        $recordEnabled,
                        $recordPath,
                        $recordFormat,
                        $recordPartDuration,
                        $pathStatusFlag
                    ]);
                }
            }

            if ($pathName !== '' && $sourceUrl !== '') {
                $mtxResult = $mediaMtxClient->ensurePathConfig($pathName, [
                    'source' => $sourceUrl,
                    'record' => intval($recordEnabled) === 1,
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
                $mediaMtxSyncData = isset($mtxResult['data']) ? $mtxResult['data'] : null;
            }

            if ($oldPathName !== '' && $pathName !== '' && $oldPathName !== $pathName) {
                $pathNameToCleanup = $oldPathName;
            }
        }

        $pdo->commit();
        if ($pathNameToCleanup !== '') {
            $reuseStmt = $pdo->prepare('SELECT COUNT(*) FROM sys_camera_path WHERE path_name = ? AND device_id <> ? AND status_flag = 1');
            $reuseStmt->execute([$pathNameToCleanup, $deviceId]);
            $reuseCount = intval($reuseStmt->fetchColumn());
            if ($reuseCount > 0) {
                $mediaMtxWarnings[] = [
                    'pathName' => $pathNameToCleanup,
                    'code' => 409,
                    'message' => 'old path is still used by another active device, skip removal'
                ];
            } else {
                $removeResult = $mediaMtxClient->removePath($pathNameToCleanup);
                if (!is_array($removeResult) || empty($removeResult['success'])) {
                    $mediaMtxWarnings[] = [
                        'pathName' => $pathNameToCleanup,
                        'code' => isset($removeResult['code']) ? intval($removeResult['code']) : 500,
                        'message' => isset($removeResult['data']) ? $removeResult['data'] : 'failed to remove old MediaMTX path config'
                    ];
                }
            }
        }

        $responsePayload = null;
        if ($mediaMtxSyncData !== null || !empty($mediaMtxWarnings)) {
            $responsePayload = [
                'mediaMtx' => $mediaMtxSyncData,
                'mediaMtxWarnings' => $mediaMtxWarnings
            ];
        }
        response_success($responsePayload, 'Device updated');
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        response_error($e->getMessage(), 400);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        response_error('Device update failed: ' . $e->getMessage(), 500);
    }
}

function handle_create_device(PDO $pdo)
{
    if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
        response_error('Method not allowed', 405);
    }

    $payload = parse_json_body();

    $groupId = normalize_int_or_null(isset($payload['groupId']) ? $payload['groupId'] : null);
    $deviceUuid = normalize_string(isset($payload['deviceUuid']) ? $payload['deviceUuid'] : '');
    $deviceName = normalize_string(isset($payload['deviceName']) ? $payload['deviceName'] : '');
    $brand = normalize_string(isset($payload['brand']) ? $payload['brand'] : '');
    $model = normalize_string(isset($payload['model']) ? $payload['model'] : '');
    $protocolType = normalize_int_or_null(isset($payload['protocolType']) ? $payload['protocolType'] : null);
    $ipAddress = normalize_string(isset($payload['ipAddress']) ? $payload['ipAddress'] : (isset($payload['ip_address']) ? $payload['ip_address'] : ''));
    $port = normalize_int_or_null(isset($payload['port']) ? $payload['port'] : null);
    $username = normalize_string(isset($payload['username']) ? $payload['username'] : '');
    $password = normalize_string(isset($payload['password']) ? $payload['password'] : '');
    $confirmPassword = normalize_string(isset($payload['confirmPassword']) ? $payload['confirmPassword'] : '');
    $location = normalize_string(isset($payload['location']) ? $payload['location'] : '');
    $onlineStatus = normalize_int_or_null(isset($payload['onlineStatus']) ? $payload['onlineStatus'] : (isset($payload['online_status']) ? $payload['online_status'] : 0));
    $streamType = normalize_int_or_null(isset($payload['streamType']) ? $payload['streamType'] : (isset($payload['stream_type']) ? $payload['stream_type'] : null));
    $pathName = normalize_string(isset($payload['pathName']) ? $payload['pathName'] : (isset($payload['path_name']) ? $payload['path_name'] : ''));
    $recordEnabled = normalize_int_or_null(isset($payload['recordEnabled']) ? $payload['recordEnabled'] : (isset($payload['record_enabled']) ? $payload['record_enabled'] : 0));
    $recordPath = normalize_string(isset($payload['recordPath']) ? $payload['recordPath'] : (isset($payload['record_path']) ? $payload['record_path'] : ''));
    $recordFormat = normalize_string(isset($payload['recordFormat']) ? $payload['recordFormat'] : (isset($payload['record_format']) ? $payload['record_format'] : 'fmp4'));
    $recordPartDuration = normalize_int_or_null(isset($payload['recordPartDuration']) ? $payload['recordPartDuration'] : (isset($payload['record_part_duration']) ? $payload['record_part_duration'] : 60));
    $pathStatusFlag = normalize_int_or_null(isset($payload['pathStatusFlag']) ? $payload['pathStatusFlag'] : (isset($payload['path_status_flag']) ? $payload['path_status_flag'] : 1));

    if ($groupId === null || $groupId <= 0 || $deviceName === '' || $brand === '' || $model === '' ||
        $protocolType === null || $port === null || $username === '' || $password === '' ||
        $confirmPassword === '' || $location === '' || $ipAddress === '' || $streamType === null || $pathName === '') {
        response_error('Invalid create payload', 400);
    }

    if ($password !== $confirmPassword) {
        response_error('Password mismatch', 400);
    }
    if ($protocolType < 1 || $protocolType > 3) {
        response_error('Invalid protocolType', 400);
    }
    if ($port <= 0 || $port > 65535) {
        response_error('Invalid port', 400);
    }
    if (!in_array($streamType, [1, 2], true)) {
        response_error('Invalid streamType, only 1 or 2 is supported', 400);
    }
    if ($onlineStatus === null || !in_array($onlineStatus, [0, 1], true)) {
        response_error('Invalid onlineStatus, only 0 or 1 is supported', 400);
    }

    if ($onlineStatus === 1 && !check_device_online_ready($ipAddress, $port)) {
        write_device_audit($pdo, [
            'event_type' => 'CREATE',
            'action_name' => 'createDevice',
            'group_id' => $groupId,
            'path_name' => $pathName,
            'result_status' => 0,
            'error_message' => 'device offline during pre-check',
            'request_payload' => $payload
        ]);

        response_error('设备暂时处于离线状态不可使用', 400);
    }

    $groupCheckStmt = $pdo->prepare('SELECT COUNT(*) FROM sys_device_group WHERE group_id = ?');
    $groupCheckStmt->execute([$groupId]);
    if (intval($groupCheckStmt->fetchColumn()) <= 0) {
        response_error('Group not found', 400);
    }

    $deviceService = new DeviceService($pdo, MediaMtxClient::getInstance());

    try {
        $result = $deviceService->addCameraDevice([
            'device_uuid' => $deviceUuid,
            'group_id' => $groupId,
            'device_name' => $deviceName,
            'brand' => $brand,
            'model' => $model,
            'protocol_type' => $protocolType,
            'ip_address' => $ipAddress,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'location' => $location,
            'online_status' => $onlineStatus,
            'status_flag' => 1,
            'stream_type' => $streamType,
            'path_name' => $pathName,
            'record_enabled' => ($recordEnabled === null ? 0 : intval($recordEnabled)),
            'record_path' => $recordPath,
            'record_format' => $recordFormat,
            'record_part_duration' => ($recordPartDuration === null ? 60 : intval($recordPartDuration)),
            'path_status_flag' => ($pathStatusFlag === null ? 1 : intval($pathStatusFlag))
        ]);

        write_device_audit($pdo, [
            'event_type' => 'CREATE',
            'action_name' => 'createDevice',
            'device_id' => intval(isset($result['device_id']) ? $result['device_id'] : 0),
            'group_id' => $groupId,
            'path_name' => isset($result['path_name']) ? (string) $result['path_name'] : $pathName,
            'result_status' => 1,
            'request_payload' => $payload,
            'response_payload' => $result
        ]);

        response_success([
            'deviceId' => intval(isset($result['device_id']) ? $result['device_id'] : 0),
            'pathUuid' => isset($result['path_uuid']) ? (string) $result['path_uuid'] : '',
            'pathName' => isset($result['path_name']) ? (string) $result['path_name'] : $pathName,
            'sourceUrl' => isset($result['source_url']) ? (string) $result['source_url'] : ''
        ], 'Device created');
    } catch (InvalidArgumentException $e) {
        write_device_audit($pdo, [
            'event_type' => 'CREATE',
            'action_name' => 'createDevice',
            'group_id' => $groupId,
            'path_name' => $pathName,
            'result_status' => 0,
            'error_message' => $e->getMessage(),
            'request_payload' => $payload
        ]);
        response_error($e->getMessage(), 400);
    } catch (RuntimeException $e) {
        write_device_audit($pdo, [
            'event_type' => 'CREATE',
            'action_name' => 'createDevice',
            'group_id' => $groupId,
            'path_name' => $pathName,
            'result_status' => 0,
            'error_message' => $e->getMessage(),
            'request_payload' => $payload
        ]);
        $httpCode = intval($e->getCode());
        if ($httpCode < 400 || $httpCode > 599) {
            $httpCode = 500;
        }
        response_error('Create device failed: ' . $e->getMessage(), $httpCode);
    } catch (Exception $e) {
        write_device_audit($pdo, [
            'event_type' => 'CREATE',
            'action_name' => 'createDevice',
            'group_id' => $groupId,
            'path_name' => $pathName,
            'result_status' => 0,
            'error_message' => $e->getMessage(),
            'request_payload' => $payload
        ]);
        response_error('Create device failed: ' . $e->getMessage(), 500);
    }
}

function handle_delete_device(PDO $pdo)
{
    if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'DELETE') {
        response_error('Method not allowed', 405);
    }

    $payload = parse_json_body();
    $deviceId = normalize_int_or_null(isset($payload['deviceId']) ? $payload['deviceId'] : (isset($_GET['id']) ? $_GET['id'] : null));

    if ($deviceId === null || $deviceId <= 0) {
        write_device_audit($pdo, [
            'event_type' => 'DELETE',
            'action_name' => 'deleteDevice',
            'result_status' => 0,
            'error_message' => 'Missing valid deviceId',
            'request_payload' => $payload
        ]);
        response_error('Missing valid deviceId', 400);
    }

    $mediaMtxClient = MediaMtxClient::getInstance();
    $pathNames = [];
    $removedPaths = [];
    $mediaMtxWarnings = [];

    $pdo->beginTransaction();
    try {
        $deviceStmt = $pdo->prepare('SELECT d.device_id, d.group_id,
                                            p.path_name AS path_name
                                     FROM sys_device d
                                     LEFT JOIN sys_camera_path p ON p.path_id = (
                                        SELECT MAX(cp.path_id)
                                        FROM sys_camera_path cp
                                        WHERE cp.device_id = d.device_id
                                     )
                                     WHERE d.device_id = ?
                                     LIMIT 1');
        $deviceStmt->execute([$deviceId]);
        $deviceRow = $deviceStmt->fetch(PDO::FETCH_ASSOC);

        if (!$deviceRow) {
            throw new InvalidArgumentException('Device not found');
        }

        $pathStmt = $pdo->prepare('SELECT DISTINCT path_name FROM sys_camera_path WHERE device_id = ? AND path_name IS NOT NULL AND path_name <> ""');
        $pathStmt->execute([$deviceId]);
        $pathRows = $pathStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($pathRows as $pathRow) {
            $name = normalize_string(isset($pathRow['path_name']) ? $pathRow['path_name'] : '');
            if ($name !== '') {
                $pathNames[] = $name;
            }
        }

        $updateDeviceStmt = $pdo->prepare('UPDATE sys_device
                                           SET status_flag = 4,
                                               online_status = 0,
                                               modify_time = NOW()
                                           WHERE device_id = ?');
        $updateDeviceStmt->execute([$deviceId]);

        $updatePathStmt = $pdo->prepare('UPDATE sys_camera_path
                                         SET status_flag = 0,
                                             modify_time = NOW()
                                         WHERE device_id = ?');
        $updatePathStmt->execute([$deviceId]);

        $pdo->commit();

        foreach (array_values(array_unique($pathNames)) as $pathName) {
            $reuseStmt = $pdo->prepare('SELECT COUNT(*) FROM sys_camera_path WHERE path_name = ? AND device_id <> ? AND status_flag = 1');
            $reuseStmt->execute([$pathName, $deviceId]);
            $reuseCount = intval($reuseStmt->fetchColumn());
            if ($reuseCount > 0) {
                $mediaMtxWarnings[] = [
                    'pathName' => $pathName,
                    'code' => 409,
                    'message' => 'path is still used by another active device, skip removal'
                ];
                continue;
            }
            $removeResult = $mediaMtxClient->removePath($pathName);
            if (!is_array($removeResult) || empty($removeResult['success'])) {
                $mediaMtxWarnings[] = [
                    'pathName' => $pathName,
                    'code' => isset($removeResult['code']) ? intval($removeResult['code']) : 500,
                    'message' => isset($removeResult['data']) ? $removeResult['data'] : 'failed to remove MediaMTX path config'
                ];
                continue;
            }
            $removedPaths[] = $pathName;
        }

        $responsePayload = [
            'deviceId' => $deviceId,
            'removedPathNames' => $removedPaths,
            'mediaMtxWarnings' => $mediaMtxWarnings
        ];

        write_device_audit($pdo, [
            'event_type' => 'DELETE',
            'action_name' => 'deleteDevice',
            'device_id' => $deviceId,
            'group_id' => normalize_int_or_null(isset($deviceRow['group_id']) ? $deviceRow['group_id'] : null),
            'path_name' => isset($deviceRow['path_name']) ? (string) $deviceRow['path_name'] : '',
            'result_status' => 1,
            'request_payload' => $payload,
            'response_payload' => $responsePayload
        ]);

        response_success($responsePayload, 'Device deleted');
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        write_device_audit($pdo, [
            'event_type' => 'DELETE',
            'action_name' => 'deleteDevice',
            'device_id' => $deviceId,
            'result_status' => 0,
            'error_message' => $e->getMessage(),
            'request_payload' => $payload
        ]);

        response_error($e->getMessage(), 404);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        write_device_audit($pdo, [
            'event_type' => 'DELETE',
            'action_name' => 'deleteDevice',
            'device_id' => $deviceId,
            'result_status' => 0,
            'error_message' => $e->getMessage(),
            'request_payload' => $payload
        ]);

        response_error('Delete device failed: ' . $e->getMessage(), 500);
    }
}

function handle_delete_device_check(PDO $pdo, $deviceId)
{
    if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'GET') {
        response_error('Method not allowed', 405);
    }

    $safeDeviceId = normalize_int_or_null($deviceId);
    if ($safeDeviceId === null || $safeDeviceId <= 0) {
        write_device_audit($pdo, [
            'event_type' => 'QUERY',
            'action_name' => 'deleteDeviceCheck',
            'result_status' => 0,
            'error_message' => 'Missing valid deviceId',
            'request_payload' => ['deviceId' => $deviceId]
        ]);
        response_error('Missing valid deviceId', 400);
    }

    $deviceRow = null;
    try {
        $deviceStmt = $pdo->prepare('SELECT d.device_id, d.device_name, d.group_id,
                                            p.path_name AS path_name
                                     FROM sys_device d
                                     LEFT JOIN sys_camera_path p ON p.path_id = (
                                        SELECT MAX(cp.path_id)
                                        FROM sys_camera_path cp
                                        WHERE cp.device_id = d.device_id
                                     )
                                     WHERE d.device_id = ?
                                     LIMIT 1');
        $deviceStmt->execute([$safeDeviceId]);
        $deviceRow = $deviceStmt->fetch(PDO::FETCH_ASSOC);

        if (!$deviceRow) {
            throw new InvalidArgumentException('Device not found');
        }

        $pathName = normalize_string(isset($deviceRow['path_name']) ? $deviceRow['path_name'] : '');
        $mediaMtxPathExists = false;
        $mediaMtxCheckSuccess = true;
        $mediaMtxCheckCode = 200;
        $mediaMtxCheckMessage = '';

        if ($pathName !== '') {
            $checkResult = MediaMtxClient::getInstance()->checkPathExists($pathName);
            if (!is_array($checkResult) || empty($checkResult['success'])) {
                $mediaMtxCheckSuccess = false;
                $mediaMtxCheckCode = isset($checkResult['code']) ? intval($checkResult['code']) : 500;
                $mediaMtxCheckMessage = isset($checkResult['data'])
                    ? (is_string($checkResult['data']) ? $checkResult['data'] : json_encode($checkResult['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                    : 'failed to query MediaMTX path config';
            } else {
                $mediaMtxPathExists = !empty($checkResult['exists']);
                $mediaMtxCheckCode = isset($checkResult['code']) ? intval($checkResult['code']) : 200;
            }
        }

        $result = [
            'deviceId' => intval($deviceRow['device_id']),
            'deviceName' => isset($deviceRow['device_name']) ? (string) $deviceRow['device_name'] : '',
            'groupId' => normalize_int_or_null(isset($deviceRow['group_id']) ? $deviceRow['group_id'] : null),
            'pathName' => $pathName,
            'mediaMtxPathExists' => $mediaMtxPathExists,
            'mediaMtxCheckSuccess' => $mediaMtxCheckSuccess,
            'mediaMtxCheckCode' => $mediaMtxCheckCode,
            'mediaMtxCheckMessage' => $mediaMtxCheckMessage
        ];

        write_device_audit($pdo, [
            'event_type' => 'QUERY',
            'action_name' => 'deleteDeviceCheck',
            'device_id' => $safeDeviceId,
            'group_id' => normalize_int_or_null(isset($deviceRow['group_id']) ? $deviceRow['group_id'] : null),
            'path_name' => $pathName,
            'result_status' => 1,
            'request_payload' => ['deviceId' => $safeDeviceId],
            'response_payload' => $result
        ]);

        response_success($result, 'Delete check loaded');
    } catch (InvalidArgumentException $e) {
        write_device_audit($pdo, [
            'event_type' => 'QUERY',
            'action_name' => 'deleteDeviceCheck',
            'device_id' => $safeDeviceId,
            'group_id' => normalize_int_or_null(isset($deviceRow['group_id']) ? $deviceRow['group_id'] : null),
            'path_name' => isset($deviceRow['path_name']) ? normalize_string($deviceRow['path_name']) : '',
            'result_status' => 0,
            'error_message' => $e->getMessage(),
            'request_payload' => ['deviceId' => $safeDeviceId]
        ]);

        response_error($e->getMessage(), 404);
    } catch (Exception $e) {
        write_device_audit($pdo, [
            'event_type' => 'QUERY',
            'action_name' => 'deleteDeviceCheck',
            'device_id' => $safeDeviceId,
            'group_id' => normalize_int_or_null(isset($deviceRow['group_id']) ? $deviceRow['group_id'] : null),
            'path_name' => isset($deviceRow['path_name']) ? normalize_string($deviceRow['path_name']) : '',
            'result_status' => 0,
            'error_message' => $e->getMessage(),
            'request_payload' => ['deviceId' => $safeDeviceId]
        ]);

        response_error('Delete device check failed: ' . $e->getMessage(), 500);
    }
}

