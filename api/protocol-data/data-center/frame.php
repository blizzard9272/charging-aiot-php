<?php
$_GET['action'] = 'frame';
require_once __DIR__ . '/../common/upload_stream_records_service.php';
handle_upload_stream_records_request($pdo);
