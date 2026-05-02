<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once __DIR__ . '/../services/monitor-center/infrastructure/DeviceAuditLogger.php';
require_once __DIR__ . '/../services/monitor-center/integrations/MediaMtxPathConfigClient.php';
require_once __DIR__ . '/../services/monitor-center/integrations/MediaMtxClient.php';

if (!function_exists('resolve_mediamtx_auto_sync_runtime_dir')) {
    function resolve_mediamtx_auto_sync_runtime_dir(): string
    {
        $envDir = getenv('MEDIAMTX_AUTO_SYNC_RUNTIME_DIR');
        $candidates = [];

        if (is_string($envDir) && trim($envDir) !== '') {
            $candidates[] = rtrim(trim($envDir), '/\\');
        }

        $candidates[] = realpath(dirname(__DIR__) . '/runtime') ?: (dirname(__DIR__) . '/runtime');
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

if (!defined('STREAM_SERVER_IP')) {
    define('STREAM_SERVER_IP', '172.18.7.124');
}
if (!defined('STREAM_SERVER_WEBRTC_PORT')) {
    define('STREAM_SERVER_WEBRTC_PORT', 8889);
}
if (!defined('MEDIAMTX_AUTO_SYNC_INTERVAL_SECONDS')) {
    define('MEDIAMTX_AUTO_SYNC_INTERVAL_SECONDS', 30);
}
if (!defined('MEDIAMTX_AUTO_SYNC_RUNTIME_DIR')) {
    define('MEDIAMTX_AUTO_SYNC_RUNTIME_DIR', resolve_mediamtx_auto_sync_runtime_dir());
}
if (!defined('MEDIAMTX_AUTO_SYNC_STATE_FILE')) {
    define('MEDIAMTX_AUTO_SYNC_STATE_FILE', MEDIAMTX_AUTO_SYNC_RUNTIME_DIR . DIRECTORY_SEPARATOR . 'mediamtx_auto_sync_state.json');
}
if (!defined('MEDIAMTX_AUTO_SYNC_LOCK_FILE')) {
    define('MEDIAMTX_AUTO_SYNC_LOCK_FILE', MEDIAMTX_AUTO_SYNC_RUNTIME_DIR . DIRECTORY_SEPARATOR . 'mediamtx_auto_sync.lock');
}

require_once __DIR__ . '/../api/monitor-center/common/monitor_support.php';
require_once __DIR__ . '/../api/monitor-center/common/monitor_workbench.php';

function cli_print_line(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function cli_print_error(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
}

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('PDO bootstrap failed');
    }

    bootstrap_device_tables($pdo);
    ensure_default_groups($pdo);
    DeviceAuditLogger::ensureAuditTables($pdo);

    $runtimeDir = dirname(MEDIAMTX_AUTO_SYNC_LOCK_FILE);
    if (!is_dir($runtimeDir)) {
        @mkdir($runtimeDir, 0777, true);
    }

    $lockFp = @fopen(MEDIAMTX_AUTO_SYNC_LOCK_FILE, 'c+');
    if (!$lockFp) {
        throw new RuntimeException('failed to open MediaMTX sync lock file');
    }

    if (!flock($lockFp, LOCK_EX)) {
        @fclose($lockFp);
        throw new RuntimeException('failed to acquire MediaMTX sync lock');
    }

    $summary = sync_camera_config_core($pdo);
    $failedCount = intval($summary['failedCount'] ?? 0);
    $now = time();
    $previousState = read_media_mtx_auto_sync_state();

    $state = [
        'last_attempt_ts' => $now,
        'last_success_ts' => $failedCount === 0 ? $now : intval($previousState['last_success_ts'] ?? 0),
        'last_failed_count' => $failedCount,
        'last_summary' => $summary
    ];
    if ($failedCount > 0) {
        $state['last_error'] = 'sync finished with failures';
    } elseif (!empty($previousState['last_error'])) {
        $state['last_error'] = '';
    }
    write_media_mtx_auto_sync_state($state);

    write_device_audit($pdo, [
        'event_type' => 'SYNC',
        'action_name' => 'syncCameraConfigCli',
        'result_status' => $failedCount === 0 ? 1 : 0,
        'error_message' => $failedCount > 0 ? 'sync finished with failures' : '',
        'response_payload' => $summary
    ]);

    cli_print_line(json_encode([
        'ok' => $failedCount === 0,
        'summary' => $summary
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

    @flock($lockFp, LOCK_UN);
    @fclose($lockFp);

    exit($failedCount === 0 ? 0 : 1);
} catch (Throwable $e) {
    $message = 'MediaMTX sync bootstrap failed: ' . $e->getMessage();
    cli_print_error($message);

    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            write_device_audit($pdo, [
                'event_type' => 'SYNC',
                'action_name' => 'syncCameraConfigCli',
                'result_status' => 0,
                'error_message' => $message
            ]);
        } catch (Throwable $auditError) {
            cli_print_error('Audit write failed: ' . $auditError->getMessage());
        }
    }

    $previousState = read_media_mtx_auto_sync_state();
    $previousState['last_attempt_ts'] = time();
    $previousState['last_error'] = $message;
    write_media_mtx_auto_sync_state($previousState);

    exit(1);
}
