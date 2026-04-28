<?php
require_once __DIR__ . '/../../../config/cors.php';
require_once __DIR__ . '/../../../../config.php';
require_once __DIR__ . '/../../../auth/AuthService.php';
require_once __DIR__ . '/../../user_bootstrap.php';
require_once __DIR__ . '/../../../lib/storage_paths.php';
require_once __DIR__ . '/../../../lib/storage_migrator.php';

header('Content-Type: application/json; charset=utf-8');

function storage_settings_success($data = null, $msg = null)
{
    echo json_encode(array(
        'code' => 1,
        'msg' => $msg,
        'data' => $data
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function storage_settings_error($msg, $httpCode = 500)
{
    http_response_code(intval($httpCode));
    echo json_encode(array(
        'code' => 0,
        'msg' => strval($msg),
        'data' => null
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function storage_settings_parse_json_body()
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return array();
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

function storage_settings_can_manage($jwtPayload)
{
    $role = intval(isset($jwtPayload['role']) ? $jwtPayload['role'] : 0);
    return in_array($role, array(1, 2), true);
}

function storage_settings_handle_list(PDO $pdo)
{
    $records = storage_examples($pdo);
    storage_settings_success(array(
        'records' => $records
    ));
}

function storage_settings_handle_update(PDO $pdo, $jwtPayload)
{
    if (!storage_settings_can_manage($jwtPayload)) {
        storage_settings_error('Permission denied', 403);
    }

    $payload = storage_settings_parse_json_body();
    $settings = isset($payload['settings']) && is_array($payload['settings']) ? $payload['settings'] : array();
    $runMigration = isset($payload['run_migration']) ? intval($payload['run_migration']) : 1;

    if (empty($settings)) {
        storage_settings_error('settings is required', 400);
    }

    $pdo->beginTransaction();
    try {
        storage_update_settings($pdo, $settings);
        $migrationSummary = null;
        if ($runMigration === 1) {
            $migrationSummary = storage_migrate_all($pdo);
        }
        $pdo->commit();
        storage_settings_success(array(
            'records' => storage_examples($pdo),
            'migration' => $migrationSummary
        ), 'Updated');
    } catch (InvalidArgumentException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        storage_settings_error($e->getMessage(), 400);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        storage_settings_error('failed to update settings: ' . $e->getMessage(), 500);
    }
}

function storage_settings_service_bootstrap(PDO $pdo)
{
    try {
        bootstrap_user_table($pdo);
        ensure_default_admin($pdo);
        return auth_require_jwt(array(), function ($msg, $httpCode) {
            storage_settings_error($msg, $httpCode);
        });
    } catch (RuntimeException $e) {
        storage_settings_error($e->getMessage(), 401);
    }
}

if (!defined('STORAGE_SETTINGS_SERVICE_SKIP_ROUTER')) {
    $jwtPayload = storage_settings_service_bootstrap($pdo);
    $method = strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET');

    if ($method === 'GET') {
        storage_settings_handle_list($pdo);
    }

    if ($method === 'PUT') {
        storage_settings_handle_update($pdo, $jwtPayload);
    }

    storage_settings_error('Method not allowed', 405);
}
