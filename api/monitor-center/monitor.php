<?php

$action = isset($_GET['action']) ? trim((string) $_GET['action']) : '';
$map = array(
    'syncCameraConfig' => __DIR__ . '/device-center/sync-camera-config.php',
    'mediaMtxHealth' => __DIR__ . '/device-center/media-mtx-health.php',
    'pageDevices' => __DIR__ . '/device-center/list.php',
    'groups' => __DIR__ . '/group-management/list.php',
    'groupDeviceList' => __DIR__ . '/group-management/device-list.php',
    'createGroup' => __DIR__ . '/group-management/create.php',
    'updateGroup' => __DIR__ . '/group-management/update.php',
    'workbenchAudit' => __DIR__ . '/workbench/audit.php',
    'playbackList' => __DIR__ . '/video-list/list.php',
    'deviceForm' => __DIR__ . '/device-center/form.php',
    'deviceStreams' => __DIR__ . '/device-center/streams.php',
    'deleteDeviceCheck' => __DIR__ . '/device-center/delete-check.php',
    'updateDevice' => __DIR__ . '/device-center/update.php',
    'createDevice' => __DIR__ . '/device-center/create.php'
);

if (isset($map[$action])) {
    require_once $map[$action];
    exit;
}

if ($action === 'group') {
    $method = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET');
    if ($method === 'POST') {
        require_once __DIR__ . '/group-management/create.php';
        exit;
    }
    if ($method === 'PUT') {
        require_once __DIR__ . '/group-management/update.php';
        exit;
    }
}

if ($action === 'device') {
    $method = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? (string) $_SERVER['REQUEST_METHOD'] : 'GET');
    if ($method === 'POST') {
        require_once __DIR__ . '/device-center/create.php';
        exit;
    }
    if ($method === 'PUT') {
        require_once __DIR__ . '/device-center/update.php';
        exit;
    }
    if ($method === 'DELETE') {
        require_once __DIR__ . '/device-center/delete.php';
        exit;
    }
}

http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(array('code' => 0, 'msg' => 'Endpoint not found', 'data' => null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
