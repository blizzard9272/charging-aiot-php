<?php

$type = isset($_GET['type']) ? trim((string) $_GET['type']) : '';
$map = array(
    '101' => __DIR__ . '/upload-101.php',
    '102' => __DIR__ . '/upload-102.php',
    '103' => __DIR__ . '/upload-103.php'
);

if (isset($map[$type])) {
    require_once $map[$type];
    exit;
}

http_response_code(400);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(array('code' => 0, 'msg' => 'Invalid upload type', 'data' => null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
