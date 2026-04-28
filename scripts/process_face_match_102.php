<?php

declare(strict_types=1);

/**
 * 批量调用 face_match_service，对 message_102_records 做识别并回写。
 *
 * 用法：
 * php charging-aiot-php/scripts/process_face_match_102.php
 * php charging-aiot-php/scripts/process_face_match_102.php --limit=500 --topk=3
 */

$limit = 200;
$topK = 3;
$serviceBase = getenv('FACE_MATCH_SERVICE_URL') ?: 'http://127.0.0.1:8090';

foreach ($argv as $arg) {
    if (strpos($arg, '--limit=') === 0) {
        $value = intval(substr($arg, 8));
        if ($value > 0) $limit = $value;
    } elseif (strpos($arg, '--topk=') === 0) {
        $value = intval(substr($arg, 7));
        if ($value > 0) $topK = $value;
    }
}

$endpoint = rtrim($serviceBase, '/') . '/process_pending_102';
$payload = json_encode(
    array(
        'limit' => $limit,
        'top_k' => $topK
    ),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);

if ($payload === false) {
    fwrite(STDERR, "failed to encode payload\n");
    exit(1);
}

$ch = curl_init($endpoint);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);
curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

$result = curl_exec($ch);
if ($result === false) {
    $error = curl_error($ch);
    curl_close($ch);
    fwrite(STDERR, "request failed: {$error}\n");
    exit(2);
}

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode < 200 || $httpCode >= 300) {
    fwrite(STDERR, "service returned http {$httpCode}: {$result}\n");
    exit(3);
}

echo $result . PHP_EOL;
exit(0);

