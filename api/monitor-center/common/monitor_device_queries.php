<?php

function resolve_effective_record_enabled($rawEnabled, $recordPath, $recordFormat, $recordPartDuration)
{
    $enabled = normalize_int_or_null($rawEnabled);
    return $enabled === 1 ? 1 : 0;
}

function handle_page_devices(PDO $pdo)
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

    $deviceName = normalize_string(isset($_GET['deviceName']) ? $_GET['deviceName'] : '');
    $brand = normalize_string(isset($_GET['brand']) ? $_GET['brand'] : '');
    $model = normalize_string(isset($_GET['model']) ? $_GET['model'] : '');
    $ipAddress = normalize_string(isset($_GET['ipAddress']) ? $_GET['ipAddress'] : '');
    $groupId = normalize_int_or_null(isset($_GET['groupId']) ? $_GET['groupId'] : null);
    $protocolType = normalize_int_or_null(isset($_GET['protocolType']) ? $_GET['protocolType'] : null);
    $onlineStatus = normalize_int_or_null(isset($_GET['onlineStatus']) ? $_GET['onlineStatus'] : null);
    $statusFlag = normalize_int_or_null(isset($_GET['statusFlag']) ? $_GET['statusFlag'] : null);

    $whereSql = ' WHERE 1=1';
    $params = [];

    if ($deviceName !== '') {
        $whereSql .= ' AND d.device_name LIKE ?';
        $params[] = '%' . $deviceName . '%';
    }
    if ($brand !== '') {
        $whereSql .= ' AND d.brand LIKE ?';
        $params[] = '%' . $brand . '%';
    }
    if ($model !== '') {
        $whereSql .= ' AND d.model LIKE ?';
        $params[] = '%' . $model . '%';
    }
    if ($ipAddress !== '') {
        $whereSql .= ' AND d.ip_address LIKE ?';
        $params[] = '%' . $ipAddress . '%';
    }
    if ($groupId !== null) {
        $whereSql .= ' AND d.group_id = ?';
        $params[] = $groupId;
    }
    if ($protocolType !== null) {
        $whereSql .= ' AND d.protocol_type = ?';
        $params[] = $protocolType;
    }
    if ($onlineStatus !== null) {
        $whereSql .= ' AND d.online_status = ?';
        $params[] = $onlineStatus;
    }
    if ($statusFlag !== null) {
        $whereSql .= ' AND d.status_flag = ?';
        $params[] = $statusFlag;
    } else {
        $whereSql .= ' AND d.status_flag <> 4';
    }

    $countSql = 'SELECT COUNT(*) FROM sys_device d' . $whereSql;
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = intval($countStmt->fetchColumn());

    $offset = ($page - 1) * $pageSize;
    $dataSql = 'SELECT d.device_id, d.device_uuid, d.group_id, d.device_name, d.brand, d.model,
                       d.protocol_type, d.ip_address, d.port, d.location, d.online_status, d.status_flag
                FROM sys_device d' . $whereSql . '
                ORDER BY d.device_id DESC
                LIMIT ? OFFSET ?';

    $dataParams = $params;
    $dataParams[] = $pageSize;
    $dataParams[] = $offset;

    $dataStmt = $pdo->prepare($dataSql);
    $dataStmt->execute($dataParams);
    $devices = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($devices)) {
        write_device_audit($pdo, [
            'event_type' => 'QUERY',
            'action_name' => 'pageDevices',
            'result_status' => 1,
            'request_payload' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'deviceName' => $deviceName,
                'brand' => $brand,
                'model' => $model,
                'ipAddress' => $ipAddress,
                'groupId' => $groupId,
                'protocolType' => $protocolType,
                'onlineStatus' => $onlineStatus,
                'statusFlag' => $statusFlag
            ],
            'response_payload' => [
                'total' => $total,
                'records' => 0
            ]
        ]);

        response_success([
            'total' => $total,
            'records' => []
        ]);
    }

    $deviceIds = [];
    $groupIds = [];
    foreach ($devices as $row) {
        $deviceIds[] = intval($row['device_id']);
        if ($row['group_id'] !== null) {
            $groupIds[] = intval($row['group_id']);
        }
    }
    $deviceIds = array_values(array_unique($deviceIds));
    $groupIds = array_values(array_unique($groupIds));

    $groupMap = [];
    if (!empty($groupIds)) {
        $groupPlaceholders = implode(',', array_fill(0, count($groupIds), '?'));
        $groupSql = 'SELECT group_id, group_name FROM sys_device_group WHERE group_id IN (' . $groupPlaceholders . ')';
        $groupStmt = $pdo->prepare($groupSql);
        $groupStmt->execute($groupIds);
        $groupRows = $groupStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($groupRows as $groupRow) {
            $groupMap[intval($groupRow['group_id'])] = isset($groupRow['group_name']) ? (string) $groupRow['group_name'] : null;
        }
    }

        $pathMap = [];

    if (!empty($deviceIds)) {
        $devicePlaceholders = implode(',', array_fill(0, count($deviceIds), '?'));

                $pathSql = 'SELECT p.path_id, p.path_uuid, p.device_id, p.path_name, p.source_url,
                                                     p.stream_type, p.record_enabled, p.record_path, p.record_format,
                                                     p.record_part_duration, p.status_flag
                                        FROM sys_camera_path p
                                        INNER JOIN (
                                                SELECT device_id, MAX(path_id) AS path_id
                                                FROM sys_camera_path
                                                WHERE device_id IN (' . $devicePlaceholders . ')
                                                GROUP BY device_id
                                        ) t ON p.path_id = t.path_id';
                $pathStmt = $pdo->prepare($pathSql);
                $pathStmt->execute($deviceIds);
                $pathRows = $pathStmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($pathRows as $pathRow) {
                        $pathMap[intval($pathRow['device_id'])] = $pathRow;
        }
    }

    $records = [];
    foreach ($devices as $row) {
        $deviceId = intval($row['device_id']);
        $groupIdValue = $row['group_id'] === null ? null : intval($row['group_id']);
        $pathInfo = isset($pathMap[$deviceId]) ? $pathMap[$deviceId] : null;
        $sourceUrl = $pathInfo ? (string) $pathInfo['source_url'] : null;
        $pathName = $pathInfo ? (string) $pathInfo['path_name'] : '';
        $defaultPlayUrl = build_default_play_url($pathName);
        $recordEnabled = $pathInfo
            ? resolve_effective_record_enabled(
                $pathInfo['record_enabled'] ?? null,
                $pathInfo['record_path'] ?? '',
                $pathInfo['record_format'] ?? 'fmp4',
                $pathInfo['record_part_duration'] ?? null
            )
            : 0;

        $records[] = [
            'deviceId' => $deviceId,
            'deviceUuid' => isset($row['device_uuid']) ? (string) $row['device_uuid'] : '',
            'deviceName' => isset($row['device_name']) ? (string) $row['device_name'] : '',
            'brand' => isset($row['brand']) ? (string) $row['brand'] : '',
            'model' => isset($row['model']) ? (string) $row['model'] : null,
            'ipAddress' => isset($row['ip_address']) ? (string) $row['ip_address'] : '',
            'port' => normalize_int_or_null(isset($row['port']) ? $row['port'] : null),
            'location' => isset($row['location']) ? (string) $row['location'] : '',
            'protocolType' => intval($row['protocol_type']),
            'onlineStatus' => intval($row['online_status']),
            'statusFlag' => intval($row['status_flag']),
            'groupName' => ($groupIdValue !== null && isset($groupMap[$groupIdValue])) ? $groupMap[$groupIdValue] : null,
            'pathId' => $pathInfo ? intval($pathInfo['path_id']) : null,
            'pathUuid' => $pathInfo ? (string) $pathInfo['path_uuid'] : '',
            'pathName' => $pathName,
            'sourceUrl' => $sourceUrl,
            'streamType' => $pathInfo ? normalize_int_or_null($pathInfo['stream_type']) : null,
            'recordEnabled' => $recordEnabled,
            'recordPath' => $pathInfo ? (string) $pathInfo['record_path'] : '',
            'recordFormat' => $pathInfo ? (string) $pathInfo['record_format'] : 'fmp4',
            'recordPartDuration' => $pathInfo ? normalize_int_or_null($pathInfo['record_part_duration']) : null,
            'pathStatusFlag' => $pathInfo ? normalize_int_or_null($pathInfo['status_flag']) : null,
            // Legacy fields retained for compatibility with existing play flow.
            'defaultStreamUrl' => $defaultPlayUrl,
            'defaultStreamProtocol' => 4,
            'isRecording' => $recordEnabled
        ];
    }

    write_device_audit($pdo, [
        'event_type' => 'QUERY',
        'action_name' => 'pageDevices',
        'result_status' => 1,
        'request_payload' => [
            'page' => $page,
            'pageSize' => $pageSize,
            'deviceName' => $deviceName,
            'brand' => $brand,
            'model' => $model,
            'ipAddress' => $ipAddress,
            'groupId' => $groupId,
            'protocolType' => $protocolType,
            'onlineStatus' => $onlineStatus,
            'statusFlag' => $statusFlag
        ],
        'response_payload' => [
            'total' => $total,
            'records' => count($records)
        ]
    ]);

    response_success([
        'total' => $total,
        'records' => $records
    ]);
}

function handle_device_form(PDO $pdo, $deviceId)
{
    if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'GET') {
        response_error('Method not allowed', 405);
    }

    $sql = 'SELECT g.group_id AS groupId,
                   g.group_name AS groupName,
                   d.device_id AS deviceId,
                   d.device_name AS deviceName,
                   d.device_uuid AS deviceUuid,
                   d.brand AS brand,
                   d.model AS model,
                   d.protocol_type AS protocolType,
                   d.ip_address AS ipAddress,
                   d.port AS port,
                   d.username AS username,
                   d.password AS password,
                   d.location AS location,
                   d.online_status AS onlineStatus,
                   d.status_flag AS statusFlag,
                   p.path_id AS pathId,
                   p.path_uuid AS pathUuid,
                   p.path_name AS pathName,
                   p.source_url AS sourceUrl,
                   p.stream_type AS streamType,
                   p.record_enabled AS recordEnabled,
                   p.record_path AS recordPath,
                   p.record_format AS recordFormat,
                   p.record_part_duration AS recordPartDuration,
                   p.status_flag AS pathStatusFlag
            FROM sys_device d
            LEFT JOIN sys_device_group g ON d.group_id = g.group_id
            LEFT JOIN sys_camera_path p ON p.path_id = (
                SELECT MAX(cp.path_id)
                FROM sys_camera_path cp
                WHERE cp.device_id = d.device_id
            )
            WHERE d.device_id = ?
            LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$deviceId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        response_error('Device not found', 404);
    }

    $result = [
        'groupId' => normalize_int_or_null(isset($row['groupId']) ? $row['groupId'] : null),
        'groupName' => isset($row['groupName']) ? (string) $row['groupName'] : '',
        'deviceId' => intval($row['deviceId']),
        'deviceName' => isset($row['deviceName']) ? (string) $row['deviceName'] : '',
        'deviceUuid' => isset($row['deviceUuid']) ? (string) $row['deviceUuid'] : '',
        'brand' => isset($row['brand']) ? (string) $row['brand'] : '',
        'model' => isset($row['model']) ? (string) $row['model'] : '',
        'protocolType' => normalize_int_or_null(isset($row['protocolType']) ? $row['protocolType'] : null),
        'ipAddress' => isset($row['ipAddress']) ? (string) $row['ipAddress'] : '',
        'port' => normalize_int_or_null(isset($row['port']) ? $row['port'] : null),
        'username' => isset($row['username']) ? (string) $row['username'] : '',
        'password' => isset($row['password']) ? (string) $row['password'] : '',
        'location' => isset($row['location']) ? (string) $row['location'] : '',
        'onlineStatus' => normalize_int_or_null(isset($row['onlineStatus']) ? $row['onlineStatus'] : null),
        'statusFlag' => normalize_int_or_null(isset($row['statusFlag']) ? $row['statusFlag'] : null),
        'pathId' => normalize_int_or_null(isset($row['pathId']) ? $row['pathId'] : null),
        'pathUuid' => isset($row['pathUuid']) ? (string) $row['pathUuid'] : '',
        'pathName' => isset($row['pathName']) ? (string) $row['pathName'] : '',
        'sourceUrl' => isset($row['sourceUrl']) ? (string) $row['sourceUrl'] : '',
        'streamType' => normalize_int_or_null(isset($row['streamType']) ? $row['streamType'] : null),
        'recordEnabled' => resolve_effective_record_enabled(
            isset($row['recordEnabled']) ? $row['recordEnabled'] : null,
            isset($row['recordPath']) ? $row['recordPath'] : '',
            isset($row['recordFormat']) ? $row['recordFormat'] : 'fmp4',
            isset($row['recordPartDuration']) ? $row['recordPartDuration'] : null
        ),
        'recordPath' => isset($row['recordPath']) ? (string) $row['recordPath'] : '',
        'recordFormat' => isset($row['recordFormat']) ? (string) $row['recordFormat'] : 'fmp4',
        'recordPartDuration' => normalize_int_or_null(isset($row['recordPartDuration']) ? $row['recordPartDuration'] : null),
        'pathStatusFlag' => normalize_int_or_null(isset($row['pathStatusFlag']) ? $row['pathStatusFlag'] : null)
    ];

    response_success($result);
}

function handle_device_streams(PDO $pdo, $deviceId)
{
    if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'GET') {
        response_error('Method not allowed', 405);
    }

    $sql = 'SELECT path_id, path_uuid, device_id, path_name, source_url, stream_type,
                   status_flag, modify_time
            FROM sys_camera_path
            WHERE device_id = ?
            ORDER BY path_id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$deviceId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($rows as $index => $row) {
        $pathName = isset($row['path_name']) ? normalize_string($row['path_name']) : '';
        $playUrl = build_default_play_url($pathName);
        $result[] = [
            'streamId' => intval($row['path_id']),
            'streamUuid' => isset($row['path_uuid']) ? (string) $row['path_uuid'] : '',
            'deviceId' => intval($row['device_id']),
            'streamType' => normalize_int_or_null(isset($row['stream_type']) ? $row['stream_type'] : null),
            'transportProtocol' => 4,
            'streamUrl' => $playUrl ? $playUrl : (isset($row['source_url']) ? (string) $row['source_url'] : ''),
            'defaultFlag' => $index === 0 ? 1 : 0,
            'statusFlag' => normalize_int_or_null(isset($row['status_flag']) ? $row['status_flag'] : null),
            'createTime' => null,
            'modifyTime' => isset($row['modify_time']) ? (string) $row['modify_time'] : null
        ];
    }

    response_success($result);
}

