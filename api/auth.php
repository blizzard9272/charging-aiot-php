<?php
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../auth/AuthService.php';
require_once __DIR__ . '/user_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

const AUTH_TOKEN_EXPIRE_SECONDS = 7200;

function auth_response_success($data = null, $msg = null)
{
    echo json_encode([
        'code' => 1,
        'msg' => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function auth_response_error($msg, $httpCode = 500)
{
    http_response_code(intval($httpCode));
    echo json_encode([
        'code' => 0,
        'msg' => (string) $msg,
        'data' => null
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function auth_get_action()
{
    if (isset($_GET['action']) && $_GET['action'] !== '') {
        return trim((string) $_GET['action']);
    }

    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if (preg_match('/\/auth\/login(\?|$)/', $uri)) {
        return 'login';
    }
    if (preg_match('/\/auth\/me(\?|$)/', $uri)) {
        return 'me';
    }
    return '';
}

function auth_parse_json_body()
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function fetch_user_by_username(PDO $pdo, $username)
{
    $sql = 'SELECT user_id, user__uuid, username, password, role FROM `user` WHERE username = ? LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([trim((string) $username)]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function handle_login(PDO $pdo)
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        auth_response_error('Method not allowed', 405);
    }

    $body = auth_parse_json_body();
    $username = trim((string) ($body['username'] ?? ''));
    $password = trim((string) ($body['password'] ?? ''));

    if ($username === '' || $password === '') {
        auth_response_error('Username and password are required', 400);
    }

    $user = fetch_user_by_username($pdo, $username);
    if (!is_array($user)) {
        auth_response_error('Username or password is incorrect', 401);
    }

    $storedPasswordHash = (string) ($user['password'] ?? '');
    if ($storedPasswordHash === '' || !auth_verify_password($password, $storedPasswordHash)) {
        auth_response_error('Username or password is incorrect', 401);
    }

    $role = intval($user['role'] ?? 3);

    $jwt = auth_issue_jwt([
        'uid' => intval($user['user_id']),
        'username' => (string) $user['username'],
        'role' => $role
    ], AUTH_TOKEN_EXPIRE_SECONDS);

    auth_response_success([
        'token' => $jwt['token'],
        'tokenType' => 'Bearer',
        'expiresIn' => AUTH_TOKEN_EXPIRE_SECONDS,
        'expiresAt' => intval($jwt['expiresAt']),
        'user' => [
            'user_id' => intval($user['user_id']),
            'user__uuid' => (string) ($user['user__uuid'] ?? ''),
            'username' => (string) $user['username'],
            'role' => $role
        ]
    ], 'Login success');
}

function handle_me(PDO $pdo)
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        auth_response_error('Method not allowed', 405);
    }

    $payload = auth_require_jwt([], function ($msg, $httpCode) {
        auth_response_error($msg, $httpCode);
    });

    $uid = intval($payload['uid'] ?? 0);
    if ($uid <= 0) {
        auth_response_error('Invalid token payload', 401);
    }

    $stmt = $pdo->prepare('SELECT user_id, user__uuid, username, role FROM `user` WHERE user_id = ? LIMIT 1');
    $stmt->execute([$uid]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($user)) {
        auth_response_error('User does not exist', 401);
    }

    auth_response_success([
        'user_id' => intval($user['user_id']),
        'user__uuid' => (string) ($user['user__uuid'] ?? ''),
        'username' => (string) $user['username'],
        'role' => intval($user['role'] ?? 0)
    ], 'Success');
}

try {
    bootstrap_user_table($pdo);
    ensure_default_admin($pdo);

    $action = auth_get_action();
    if ($action === 'login') {
        handle_login($pdo);
    }
    if ($action === 'me') {
        handle_me($pdo);
    }

    auth_response_error('Endpoint not found', 404);
} catch (PDOException $e) {
    auth_response_error('Database error: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    auth_response_error('Server error: ' . $e->getMessage(), 500);
}
