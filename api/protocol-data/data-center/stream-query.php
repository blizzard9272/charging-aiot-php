<?php

$action = isset($_GET['action']) ? trim((string) $_GET['action']) : '';
$map = array(
    '101' => __DIR__ . '/query-101.php',
    '102' => __DIR__ . '/query-102.php',
    '103' => __DIR__ . '/query-103.php',
    'page' => __DIR__ . '/page.php',
    'stats' => __DIR__ . '/stats.php'
);

if (isset($map[$action])) {
    require_once $map[$action];
    exit;
}

http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(array('code' => 0, 'msg' => 'Endpoint not found', 'data' => null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
