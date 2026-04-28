<?php

function handle_groups(PDO $pdo)
{
    if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'GET') {
        response_error('Method not allowed', 405);
    }

    $rows = $pdo->query('SELECT group_id, group_uuid, group_name, sort, status_flag, create_time, modify_time FROM sys_device_group ORDER BY sort ASC, group_id ASC')
                ->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($rows as $row) {
        $result[] = [
            'groupId' => intval($row['group_id']),
            'groupUuid' => isset($row['group_uuid']) ? (string) $row['group_uuid'] : null,
            'groupName' => isset($row['group_name']) ? (string) $row['group_name'] : '',
            'sort' => intval($row['sort']),
            'statusFlag' => intval($row['status_flag']),
            'createTime' => isset($row['create_time']) ? (string) $row['create_time'] : null,
            'modifyTime' => isset($row['modify_time']) ? (string) $row['modify_time'] : null
        ];
    }

    response_success($result);
}

function handle_group_device_list(PDO $pdo)
{
    if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'GET') {
        response_error('Method not allowed', 405);
    }

    $groupName = normalize_string(isset($_GET['groupName']) ? $_GET['groupName'] : '');
    $statusFlag = normalize_int_or_null(isset($_GET['statusFlag']) ? $_GET['statusFlag'] : null);

    $groupWhere = ' WHERE 1=1';
    $groupParams = [];
    if ($groupName !== '') {
        $groupWhere .= ' AND g.group_name LIKE ?';
        $groupParams[] = '%' . $groupName . '%';
    }
    if ($statusFlag !== null) {
        $groupWhere .= ' AND g.status_flag = ?';
        $groupParams[] = $statusFlag;
    }

    $groupSql = 'SELECT g.group_id, g.group_uuid, g.group_name, g.sort, g.status_flag, g.create_time, g.modify_time
                 FROM sys_device_group g' . $groupWhere . '
                 ORDER BY g.sort ASC, g.group_id ASC';
    $groupStmt = $pdo->prepare($groupSql);
    $groupStmt->execute($groupParams);
    $groups = $groupStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($groups)) {
        response_success([]);
    }

    $groupIds = [];
    foreach ($groups as $group) {
        $groupIds[] = intval($group['group_id']);
    }
    $groupIds = array_values(array_unique($groupIds));

    $deviceMap = [];
    if (!empty($groupIds)) {
        $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
        $deviceSql = 'SELECT d.device_id, d.group_id, d.device_name, d.brand, d.model,
                             d.protocol_type, d.ip_address, d.port, d.location,
                             d.online_status, d.status_flag
                      FROM sys_device d
                      WHERE d.group_id IN (' . $placeholders . ')
                        AND d.status_flag <> 4
                      ORDER BY d.group_id ASC, d.device_id DESC';
        $deviceStmt = $pdo->prepare($deviceSql);
        $deviceStmt->execute($groupIds);
        $devices = $deviceStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($devices as $device) {
            $currentGroupId = intval($device['group_id']);
            if (!isset($deviceMap[$currentGroupId])) {
                $deviceMap[$currentGroupId] = [];
            }
            $deviceMap[$currentGroupId][] = [
                'deviceId' => intval($device['device_id']),
                'deviceName' => isset($device['device_name']) ? (string) $device['device_name'] : '',
                'brand' => isset($device['brand']) ? (string) $device['brand'] : '',
                'model' => isset($device['model']) ? (string) $device['model'] : '',
                'protocolType' => normalize_int_or_null(isset($device['protocol_type']) ? $device['protocol_type'] : null),
                'ipAddress' => isset($device['ip_address']) ? (string) $device['ip_address'] : '',
                'port' => normalize_int_or_null(isset($device['port']) ? $device['port'] : null),
                'location' => isset($device['location']) ? (string) $device['location'] : '',
                'onlineStatus' => normalize_int_or_null(isset($device['online_status']) ? $device['online_status'] : null),
                'statusFlag' => normalize_int_or_null(isset($device['status_flag']) ? $device['status_flag'] : null)
            ];
        }
    }

    $result = [];
    foreach ($groups as $group) {
        $groupId = intval($group['group_id']);
        $groupDevices = isset($deviceMap[$groupId]) ? $deviceMap[$groupId] : [];
        $result[] = [
            'groupId' => $groupId,
            'groupUuid' => isset($group['group_uuid']) ? (string) $group['group_uuid'] : null,
            'groupName' => isset($group['group_name']) ? (string) $group['group_name'] : '',
            'sort' => intval($group['sort']),
            'statusFlag' => intval($group['status_flag']),
            'createTime' => isset($group['create_time']) ? (string) $group['create_time'] : null,
            'modifyTime' => isset($group['modify_time']) ? (string) $group['modify_time'] : null,
            'deviceCount' => count($groupDevices),
            'devices' => $groupDevices
        ];
    }

    write_device_audit($pdo, [
        'event_type' => 'QUERY',
        'action_name' => 'groupDeviceList',
        'result_status' => 1,
        'request_payload' => [
            'groupName' => $groupName,
            'statusFlag' => $statusFlag
        ],
        'response_payload' => [
            'groupCount' => count($result)
        ]
    ]);

    response_success($result);
}

function handle_create_group(PDO $pdo)
{
    if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
        response_error('Method not allowed', 405);
    }

    $payload = parse_json_body();
    $groupName = normalize_string(isset($payload['groupName']) ? $payload['groupName'] : '');
    $sort = normalize_int_or_null(isset($payload['sort']) ? $payload['sort'] : 0);
    $statusFlag = normalize_int_or_null(isset($payload['statusFlag']) ? $payload['statusFlag'] : 1);

    if ($groupName === '') {
        response_error('Group name is required', 400);
    }

    if ($sort === null) {
        $sort = 0;
    }

    if ($sort < 0) {
        response_error('Invalid sort, it must be greater than or equal to 0', 400);
    }

    if ($statusFlag === null) {
        $statusFlag = 1;
    }

    if ($statusFlag !== 0 && $statusFlag !== 1) {
        response_error('Invalid statusFlag, only 0 or 1 is supported', 400);
    }

    $duplicateStmt = $pdo->prepare('SELECT COUNT(*) FROM sys_device_group WHERE group_name = ?');
    $duplicateStmt->execute([$groupName]);
    if (intval($duplicateStmt->fetchColumn()) > 0) {
        response_error('Group name already exists', 400);
    }

    $groupUuid = str_replace('.', '', uniqid('', true));
    $stmt = $pdo->prepare('INSERT INTO sys_device_group (group_uuid, group_name, sort, status_flag, create_time, modify_time)
                           VALUES (?, ?, ?, ?, NOW(), NOW())');
    $stmt->execute([$groupUuid, $groupName, $sort, $statusFlag]);

    $groupId = intval($pdo->lastInsertId());

    write_device_audit($pdo, [
        'event_type' => 'CREATE',
        'action_name' => 'createGroup',
        'group_id' => $groupId,
        'result_status' => 1,
        'request_payload' => [
            'groupName' => $groupName,
            'sort' => $sort,
            'statusFlag' => $statusFlag
        ],
        'response_payload' => [
            'groupId' => $groupId
        ]
    ]);

    response_success([
        'groupId' => $groupId
    ], 'Group created');
}

function handle_update_group(PDO $pdo)
{
    if (strtoupper($_SERVER['REQUEST_METHOD']) !== 'PUT') {
        response_error('Method not allowed', 405);
    }

    $payload = parse_json_body();
    $groupId = normalize_int_or_null(isset($payload['groupId']) ? $payload['groupId'] : null);
    if ($groupId === null || $groupId <= 0) {
        response_error('Missing valid groupId', 400);
    }

    $existingStmt = $pdo->prepare('SELECT group_id, group_name, sort, status_flag FROM sys_device_group WHERE group_id = ?');
    $existingStmt->execute([$groupId]);
    $existing = $existingStmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        response_error('Group not found', 404);
    }

    $groupName = array_key_exists('groupName', $payload) ? normalize_string($payload['groupName']) : normalize_string($existing['group_name']);
    $sort = array_key_exists('sort', $payload) ? normalize_int_or_null($payload['sort']) : intval($existing['sort']);
    $statusFlag = array_key_exists('statusFlag', $payload) ? normalize_int_or_null($payload['statusFlag']) : intval($existing['status_flag']);

    if ($groupName === '') {
        response_error('Group name is required', 400);
    }

    if ($sort === null) {
        $sort = 0;
    }

    if ($sort < 0) {
        response_error('Invalid sort, it must be greater than or equal to 0', 400);
    }

    if ($statusFlag === null) {
        $statusFlag = intval($existing['status_flag']);
    }

    if ($statusFlag !== 0 && $statusFlag !== 1) {
        response_error('Invalid statusFlag, only 0 or 1 is supported', 400);
    }

    $duplicateStmt = $pdo->prepare('SELECT COUNT(*) FROM sys_device_group WHERE group_name = ? AND group_id <> ?');
    $duplicateStmt->execute([$groupName, $groupId]);
    if (intval($duplicateStmt->fetchColumn()) > 0) {
        response_error('Group name already exists', 400);
    }

    $stmt = $pdo->prepare('UPDATE sys_device_group
                           SET group_name = ?, sort = ?, status_flag = ?, modify_time = NOW()
                           WHERE group_id = ?');
    $stmt->execute([$groupName, $sort, $statusFlag, $groupId]);

    write_device_audit($pdo, [
        'event_type' => 'UPDATE',
        'action_name' => 'updateGroup',
        'group_id' => $groupId,
        'result_status' => 1,
        'request_payload' => [
            'groupName' => $groupName,
            'sort' => $sort,
            'statusFlag' => $statusFlag
        ],
        'response_payload' => [
            'groupId' => $groupId
        ]
    ]);

    response_success(null, 'Group updated');
}

