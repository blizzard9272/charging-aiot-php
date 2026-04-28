<?php
require_once __DIR__ . '/../../../config/cors.php';
require_once __DIR__ . '/../../../../config.php';
require_once __DIR__ . '/../../../auth/AuthService.php';
require_once __DIR__ . '/../../user_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function personnel_success($data = null, $msg = null)
{
    echo json_encode([
        'code' => 1,
        'msg' => $msg,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function personnel_error($msg, $httpCode = 500)
{
    http_response_code(intval($httpCode));
    echo json_encode([
        'code' => 0,
        'msg' => (string) $msg,
        'data' => null
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function personnel_parse_json_body()
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function personnel_get_action()
{
    if (isset($_GET['action']) && $_GET['action'] !== '') {
        return trim((string) $_GET['action']);
    }
    return '';
}

function personnel_normalize_string($value)
{
    return trim((string) ($value ?? ''));
}

function personnel_normalize_role($value)
{
    if ($value === null) {
        return 0;
    }
    if (is_int($value) || is_numeric($value)) {
        $role = intval($value);
        return ($role >= 1 && $role <= 4) ? $role : 0;
    }
    $trimmed = trim((string) $value);
    if ($trimmed === '') {
        return 0;
    }
    if (preg_match('/^[0-9]+$/', $trimmed) === 1) {
        $role = intval($trimmed);
        return ($role >= 1 && $role <= 4) ? $role : 0;
    }
    $lower = strtolower($trimmed);
    $map = [
        'super_admin' => 1,
        'admin' => 2,
        'manager' => 3,
        'maintainer' => 4
    ];
    return isset($map[$lower]) ? intval($map[$lower]) : 0;
}

function personnel_can_manage(array $jwtPayload)
{
    $role = intval($jwtPayload['role'] ?? 0);
    return in_array($role, [1, 2], true);
}

function personnel_handle_page(PDO $pdo)
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        personnel_error('Method not allowed', 405);
    }

    $page = intval($_GET['page'] ?? 1);
    $pageSize = intval($_GET['pageSize'] ?? 10);
    if ($page <= 0) $page = 1;
    if ($pageSize <= 0) $pageSize = 10;
    if ($pageSize > 100) $pageSize = 100;

    $roleFilter = personnel_normalize_role($_GET['role'] ?? '');
    $keyword = personnel_normalize_string($_GET['keyword'] ?? '');

    // 默认展示 user 表全部数据（包括默认的 super_admin/admin 账号）。
    // 前端 Personnel 页面期望至少能看到初始化的一条用户数据。
    $whereSql = ' WHERE 1=1';
    $params = [];
    if ($roleFilter > 0) {
        $whereSql .= ' AND role = ?';
        $params[] = $roleFilter;
    }
    if ($keyword !== '') {
        $whereSql .= ' AND (username LIKE ? OR nickname LIKE ? OR tel LIKE ?)';
        $like = '%' . $keyword . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $countSql = 'SELECT COUNT(*) FROM `user`' . $whereSql;
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = intval($countStmt->fetchColumn());

    $offset = ($page - 1) * $pageSize;
    $listSql = 'SELECT user_id, user__uuid, username, role, nickname, tel, email, createtime
                FROM `user`'
                . $whereSql
                . ' ORDER BY user_id DESC LIMIT ? OFFSET ?';
    $listParams = $params;
    $listParams[] = $pageSize;
    $listParams[] = $offset;
    $listStmt = $pdo->prepare($listSql);
    $listStmt->execute($listParams);
    $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

    $records = [];
    foreach ($rows as $row) {
        $records[] = [
            'user_id' => intval($row['user_id']),
            'user__uuid' => (string) ($row['user__uuid'] ?? ''),
            'username' => (string) ($row['username'] ?? ''),
            'nickname' => (string) ($row['nickname'] ?? ''),
            'role' => intval($row['role'] ?? 0),
            'tel' => (string) ($row['tel'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'createtime' => (string) ($row['createtime'] ?? '')
        ];
    }

    personnel_success([
        'total' => $total,
        'records' => $records
    ]);
}

function personnel_handle_create(PDO $pdo, array $jwtPayload)
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        personnel_error('Method not allowed', 405);
    }
    if (!personnel_can_manage($jwtPayload)) {
        personnel_error('Permission denied', 403);
    }

    $payload = personnel_parse_json_body();
    $username = personnel_normalize_string($payload['username'] ?? '');
    $password = personnel_normalize_string($payload['password'] ?? '');
    $role = personnel_normalize_role($payload['role'] ?? 0);
    $nickname = personnel_normalize_string($payload['nickname'] ?? '');
    $tel = personnel_normalize_string($payload['tel'] ?? '');
    $email = personnel_normalize_string($payload['email'] ?? '');

    if ($username === '' || $password === '' || $role <= 0) {
        personnel_error('username, password, role are required', 400);
    }

    $existsStmt = $pdo->prepare('SELECT COUNT(*) FROM `user` WHERE username = ?');
    $existsStmt->execute([$username]);
    if (intval($existsStmt->fetchColumn()) > 0) {
        personnel_error('Username already exists', 400);
    }

    $passwordHash = auth_hash_password_for_storage($password);
    if ($passwordHash === '') {
        personnel_error('Invalid password', 400);
    }

    $insertSql = 'INSERT INTO `user` (user__uuid, username, password, nickname, role, tel, email, createtime)
                  VALUES (?, ?, ?, ?, ?, ?, ?, NOW())';
    $insertStmt = $pdo->prepare($insertSql);
    $insertStmt->execute([user_generate_uuid_v4(), $username, $passwordHash, $nickname, $role, $tel, $email]);

    personnel_success(['user_id' => intval($pdo->lastInsertId())], 'Created');
}

function personnel_handle_update(PDO $pdo, array $jwtPayload)
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'PUT') {
        personnel_error('Method not allowed', 405);
    }
    if (!personnel_can_manage($jwtPayload)) {
        personnel_error('Permission denied', 403);
    }

    $payload = personnel_parse_json_body();
    $userId = intval($payload['user_id'] ?? 0);
    $username = personnel_normalize_string($payload['username'] ?? '');
    $role = personnel_normalize_role($payload['role'] ?? 0);
    $nickname = personnel_normalize_string($payload['nickname'] ?? '');
    $tel = personnel_normalize_string($payload['tel'] ?? '');
    $email = personnel_normalize_string($payload['email'] ?? '');
    $password = personnel_normalize_string($payload['password'] ?? '');

    if ($userId <= 0 || $username === '' || $role <= 0) {
        personnel_error('user_id, username, role are required', 400);
    }

    $rowStmt = $pdo->prepare('SELECT user_id, role FROM `user` WHERE user_id = ? LIMIT 1');
    $rowStmt->execute([$userId]);
    $exists = $rowStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($exists)) {
        personnel_error('User not found', 404);
    }

    $duplicateStmt = $pdo->prepare('SELECT COUNT(*) FROM `user` WHERE username = ? AND user_id <> ?');
    $duplicateStmt->execute([$username, $userId]);
    if (intval($duplicateStmt->fetchColumn()) > 0) {
        personnel_error('Username already exists', 400);
    }

    if ($password !== '') {
        $passwordHash = auth_hash_password_for_storage($password);
        if ($passwordHash === '') {
            personnel_error('Invalid password', 400);
        }
        $updateSql = 'UPDATE `user`
                      SET username = ?, password = ?, nickname = ?, role = ?, tel = ?, email = ?
                      WHERE user_id = ?';
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute([$username, $passwordHash, $nickname, $role, $tel, $email, $userId]);
    } else {
        $updateSql = 'UPDATE `user`
                      SET username = ?, nickname = ?, role = ?, tel = ?, email = ?
                      WHERE user_id = ?';
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute([$username, $nickname, $role, $tel, $email, $userId]);
    }

    personnel_success(null, 'Updated');
}

function personnel_handle_delete(PDO $pdo, array $jwtPayload)
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'DELETE') {
        personnel_error('Method not allowed', 405);
    }
    if (!personnel_can_manage($jwtPayload)) {
        personnel_error('Permission denied', 403);
    }

    $payload = personnel_parse_json_body();
    $userId = intval($payload['user_id'] ?? ($payload['id'] ?? 0));
    if ($userId <= 0) {
        personnel_error('user_id is required', 400);
    }

    $stmt = $pdo->prepare('SELECT user_id, username, role FROM `user` WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($user)) {
        personnel_error('User not found', 404);
    }

    $role = intval($user['role'] ?? 0);
    if ($role === 1) {
        personnel_error('Cannot delete super admin user', 400);
    }

    $deleteStmt = $pdo->prepare('DELETE FROM `user` WHERE user_id = ?');
    $deleteStmt->execute([$userId]);
    personnel_success(null, 'Deleted');
}

function personnel_service_bootstrap(PDO $pdo)
{
    $jwtPayload = auth_require_jwt([], function ($msg, $httpCode) {
        personnel_error($msg, $httpCode);
    });
    bootstrap_user_table($pdo);
    ensure_default_admin($pdo);
    return $jwtPayload;
}

if (!defined('PERSONNEL_SERVICE_SKIP_ROUTER')) {
    try {
        $jwtPayload = personnel_service_bootstrap($pdo);

        $action = personnel_get_action();
        if ($action === 'page') {
            personnel_handle_page($pdo);
        }
        if ($action === 'create') {
            personnel_handle_create($pdo, $jwtPayload);
        }
        if ($action === 'update') {
            personnel_handle_update($pdo, $jwtPayload);
        }
        if ($action === 'delete') {
            personnel_handle_delete($pdo, $jwtPayload);
        }

        personnel_error('Endpoint not found', 404);
    } catch (PDOException $e) {
        personnel_error('Database error: ' . $e->getMessage(), 500);
    } catch (Exception $e) {
        personnel_error('Server error: ' . $e->getMessage(), 500);
    }
}
