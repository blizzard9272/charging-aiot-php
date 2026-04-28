<?php

function user_column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    $sql = 'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tableName, $columnName]);
    return intval($stmt->fetchColumn()) > 0;
}

function user_get_column_data_type(PDO $pdo, string $tableName, string $columnName): ?string
{
    $sql = 'SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
            LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tableName, $columnName]);
    $type = $stmt->fetchColumn();
    if (!is_string($type) || trim($type) === '') {
        return null;
    }
    return strtolower(trim($type));
}

function user_index_exists(PDO $pdo, string $tableName, string $indexName): bool
{
    $sql = 'SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tableName, $indexName]);
    return intval($stmt->fetchColumn()) > 0;
}

function user_generate_uuid_v4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

function bootstrap_user_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS `user` (
        user_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user__uuid CHAR(36) NOT NULL,
        username VARCHAR(64) NOT NULL,
        password VARCHAR(255) NOT NULL,
        nickname VARCHAR(64) DEFAULT NULL,
        role TINYINT NOT NULL DEFAULT 3,
        tel VARCHAR(32) DEFAULT NULL,
        email VARCHAR(128) DEFAULT NULL,
        createtime DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_username (username),
        UNIQUE KEY uk_user_uuid (user__uuid),
        INDEX idx_role (role)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Legacy schema migration (id/password_hash/full_name/phone/create_time/role varchar -> v2 schema).
    if (!user_column_exists($pdo, 'user', 'user_id') && user_column_exists($pdo, 'user', 'id')) {
        $pdo->exec('ALTER TABLE `user` CHANGE COLUMN id user_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
    }

    if (!user_column_exists($pdo, 'user', 'password') && user_column_exists($pdo, 'user', 'password_hash')) {
        $pdo->exec('ALTER TABLE `user` CHANGE COLUMN password_hash password VARCHAR(255) NOT NULL');
    }

    if (!user_column_exists($pdo, 'user', 'nickname') && user_column_exists($pdo, 'user', 'full_name')) {
        $pdo->exec('ALTER TABLE `user` CHANGE COLUMN full_name nickname VARCHAR(64) DEFAULT NULL');
    }

    if (!user_column_exists($pdo, 'user', 'tel') && user_column_exists($pdo, 'user', 'phone')) {
        $pdo->exec('ALTER TABLE `user` CHANGE COLUMN phone tel VARCHAR(32) DEFAULT NULL');
    }

    if (!user_column_exists($pdo, 'user', 'createtime') && user_column_exists($pdo, 'user', 'create_time')) {
        $pdo->exec('ALTER TABLE `user` CHANGE COLUMN create_time createtime DATETIME DEFAULT CURRENT_TIMESTAMP');
    }

    if (!user_column_exists($pdo, 'user', 'email')) {
        $pdo->exec('ALTER TABLE `user` ADD COLUMN email VARCHAR(128) DEFAULT NULL');
    }

    if (!user_column_exists($pdo, 'user', 'tel')) {
        $pdo->exec('ALTER TABLE `user` ADD COLUMN tel VARCHAR(32) DEFAULT NULL');
    }

    if (!user_column_exists($pdo, 'user', 'nickname')) {
        $pdo->exec('ALTER TABLE `user` ADD COLUMN nickname VARCHAR(64) DEFAULT NULL');
    }

    if (!user_column_exists($pdo, 'user', 'createtime')) {
        $pdo->exec('ALTER TABLE `user` ADD COLUMN createtime DATETIME DEFAULT CURRENT_TIMESTAMP');
    }

    if (!user_column_exists($pdo, 'user', 'user__uuid')) {
        $pdo->exec('ALTER TABLE `user` ADD COLUMN user__uuid CHAR(36) DEFAULT NULL');
    }

    if (!user_column_exists($pdo, 'user', 'role')) {
        $pdo->exec('ALTER TABLE `user` ADD COLUMN role TINYINT NOT NULL DEFAULT 3');
    } else {
        $roleType = user_get_column_data_type($pdo, 'user', 'role');
        if ($roleType !== 'tinyint') {
            $pdo->exec("UPDATE `user`
                        SET role = CASE
                            WHEN role IS NULL OR TRIM(role) = '' THEN '3'
                            WHEN LOWER(TRIM(role)) = 'super_admin' THEN '1'
                            WHEN LOWER(TRIM(role)) = 'admin' THEN '2'
                            WHEN LOWER(TRIM(role)) = 'manager' THEN '3'
                            WHEN LOWER(TRIM(role)) = 'maintainer' THEN '4'
                            WHEN role REGEXP '^[0-9]+$' THEN role
                            ELSE '3'
                        END");
            $pdo->exec('ALTER TABLE `user` MODIFY COLUMN role TINYINT NOT NULL DEFAULT 3');
        }
    }

    if (!user_index_exists($pdo, 'user', 'uk_username')) {
        $pdo->exec('ALTER TABLE `user` ADD UNIQUE KEY uk_username (username)');
    }

    // Backfill uuids then enforce constraints.
    $uuidSelect = $pdo->query("SELECT user_id FROM `user` WHERE user__uuid IS NULL OR user__uuid = ''");
    $rows = $uuidSelect ? $uuidSelect->fetchAll(PDO::FETCH_ASSOC) : [];
    if (!empty($rows)) {
        $updateUuidStmt = $pdo->prepare('UPDATE `user` SET user__uuid = ? WHERE user_id = ?');
        foreach ($rows as $row) {
            $userId = intval($row['user_id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            $updateUuidStmt->execute([user_generate_uuid_v4(), $userId]);
        }
    }

    $uuidType = user_get_column_data_type($pdo, 'user', 'user__uuid');
    if ($uuidType !== null) {
        $pdo->exec('ALTER TABLE `user` MODIFY COLUMN user__uuid CHAR(36) NOT NULL');
    }

    if (!user_index_exists($pdo, 'user', 'uk_user_uuid')) {
        $pdo->exec('ALTER TABLE `user` ADD UNIQUE KEY uk_user_uuid (user__uuid)');
    }
}

function ensure_default_admin(PDO $pdo): void
{
    $username = 'admin';
    $defaultPassword = '123456';
    $passwordHash = auth_hash_password_for_storage($defaultPassword);
    if ($passwordHash === '') {
        throw new RuntimeException('Default admin password hash failed');
    }

    $queryStmt = $pdo->prepare('SELECT user_id, user__uuid, password, role FROM `user` WHERE username = ? LIMIT 1');
    $queryStmt->execute([$username]);
    $admin = $queryStmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        $insertSql = 'INSERT INTO `user` (user__uuid, username, password, nickname, role, tel, email, createtime)
                      VALUES (?, ?, ?, ?, ?, ?, ?, NOW())';
        $insertStmt = $pdo->prepare($insertSql);
        $insertStmt->execute([user_generate_uuid_v4(), $username, $passwordHash, '系统管理员', 1, '', '']);
        return;
    }

    $adminId = intval($admin['user_id'] ?? 0);
    $storedHash = isset($admin['password']) ? trim((string) $admin['password']) : '';
    $storedUuid = isset($admin['user__uuid']) ? trim((string) $admin['user__uuid']) : '';
    $storedRole = intval($admin['role'] ?? 0);

    if ($adminId <= 0) {
        return;
    }

    $needUpdate = $storedHash === '' || $storedRole !== 1 || $storedUuid === '';
    if (!$needUpdate) {
        return;
    }

    $newUuid = $storedUuid !== '' ? $storedUuid : user_generate_uuid_v4();
    $newHash = $storedHash !== '' ? $storedHash : $passwordHash;
    $updateStmt = $pdo->prepare('UPDATE `user` SET user__uuid = ?, password = ?, role = 1 WHERE user_id = ?');
    $updateStmt->execute([$newUuid, $newHash, $adminId]);
}
