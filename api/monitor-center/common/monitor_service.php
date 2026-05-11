<?php
require_once __DIR__ . '/../../../config/cors.php';
require_once __DIR__ . '/../../../../config.php';
require_once __DIR__ . '/../../../auth/AuthService.php';
require_once __DIR__ . '/../../../services/monitor-center/infrastructure/DeviceAuditLogger.php';
require_once __DIR__ . '/../../../services/monitor-center/integrations/MediaMtxPathConfigClient.php';
require_once __DIR__ . '/../../../services/monitor-center/integrations/MediaMtxClient.php';
require_once __DIR__ . '/../../../services/monitor-center/services/DeviceService.php';

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('resolve_mediamtx_auto_sync_runtime_dir')) {
    function resolve_mediamtx_auto_sync_runtime_dir(): string
    {
        $envDir = getenv('MEDIAMTX_AUTO_SYNC_RUNTIME_DIR');
        $candidates = [];

        if (is_string($envDir) && trim($envDir) !== '') {
            $candidates[] = rtrim(trim($envDir), '/\\');
        }

        $candidates[] = realpath(__DIR__ . '/../../../runtime') ?: (__DIR__ . '/../../../runtime');
        $candidates[] = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'charging-aiot-runtime';

        foreach ($candidates as $dir) {
            if (!is_string($dir) || trim($dir) === '') {
                continue;
            }

            if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
                continue;
            }

            if (is_writable($dir)) {
                return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dir);
            }
        }

        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidates[count($candidates) - 1]);
    }
}

define('STREAM_SERVER_IP', '172.18.7.124');
define('STREAM_SERVER_WEBRTC_PORT', 8889);
define('MEDIAMTX_AUTO_SYNC_INTERVAL_SECONDS', 30);
define('MEDIAMTX_AUTO_SYNC_RUNTIME_DIR', resolve_mediamtx_auto_sync_runtime_dir());
define('MEDIAMTX_AUTO_SYNC_STATE_FILE', MEDIAMTX_AUTO_SYNC_RUNTIME_DIR . DIRECTORY_SEPARATOR . 'mediamtx_auto_sync_state.json');
define('MEDIAMTX_AUTO_SYNC_LOCK_FILE', MEDIAMTX_AUTO_SYNC_RUNTIME_DIR . DIRECTORY_SEPARATOR . 'mediamtx_auto_sync.lock');

require_once __DIR__ . '/monitor_support.php';
require_once __DIR__ . '/monitor_playback.php';
require_once __DIR__ . '/monitor_groups.php';
require_once __DIR__ . '/monitor_device_queries.php';
require_once __DIR__ . '/monitor_device_mutations.php';
require_once __DIR__ . '/monitor_workbench.php';
try {
    bootstrap_device_tables($pdo);
    ensure_default_groups($pdo);
    DeviceAuditLogger::ensureAuditTables($pdo);
    $action = get_action();
    auth_require_jwt([], function ($msg, $httpCode) {
        response_error($msg, $httpCode);
    });
    maybe_auto_sync_camera_config($pdo, $action);
    if ($action === 'syncCameraConfig') {
        handle_sync_camera_config($pdo);
    }
    if ($action === 'mediaMtxHealth') {
        handle_media_mtx_health($pdo);
    }
    if ($action === 'pageDevices') {
        handle_page_devices($pdo);
    }
    if ($action === 'groups') {
        handle_groups($pdo);
    }
    if ($action === 'groupDeviceList') {
        handle_group_device_list($pdo);
    }
    if ($action === 'createGroup') {
        handle_create_group($pdo);
    }
    if ($action === 'updateGroup') {
        handle_update_group($pdo);
    }
    if ($action === 'group') {
        $method = strtoupper($_SERVER['REQUEST_METHOD']);
        if ($method === 'POST') {
            handle_create_group($pdo);
        }
        if ($method === 'PUT') {
            handle_update_group($pdo);
        }
        response_error('Method not allowed', 405);
    }
    if ($action === 'workbenchAudit') {
        handle_workbench_audit($pdo);
    }
    if ($action === 'playbackList') {
        handle_playback_list($pdo);
    }
    if ($action === 'cleanupRecordings') {
        handle_cleanup_recordings($pdo);
    }
    if ($action === 'deviceForm') {
        $id = normalize_int_or_null(isset($_GET['id']) ? $_GET['id'] : null);
        if ($id === null || $id <= 0) {
            response_error('Missing device id', 400);
        }
        handle_device_form($pdo, $id);
    }
    if ($action === 'deviceStreams') {
        $id = normalize_int_or_null(isset($_GET['id']) ? $_GET['id'] : null);
        if ($id === null || $id <= 0) {
            response_error('Missing device id', 400);
        }
        handle_device_streams($pdo, $id);
    }
    if ($action === 'deleteDeviceCheck') {
        $id = normalize_int_or_null(isset($_GET['id']) ? $_GET['id'] : null);
        if ($id === null || $id <= 0) {
            response_error('Missing device id', 400);
        }
        handle_delete_device_check($pdo, $id);
    }
    if ($action === 'updateDevice') {
        handle_update_device($pdo);
    }
    if ($action === 'createDevice') {
        handle_create_device($pdo);
    }
    if ($action === 'device') {
        $method = strtoupper($_SERVER['REQUEST_METHOD']);
        if ($method === 'PUT') {
            handle_update_device($pdo);
        }
        if ($method === 'POST') {
            handle_create_device($pdo);
        }
        if ($method === 'DELETE') {
            handle_delete_device($pdo);
        }
        response_error('Method not allowed', 405);
    }

    response_error('Endpoint not found', 404);
} catch (PDOException $e) {
    response_error('Database error: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    response_error('Server error: ' . $e->getMessage(), 500);
}
